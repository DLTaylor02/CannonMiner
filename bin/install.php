<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;

function readHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')
        && trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';
    if ($canHide) {
        shell_exec('stty -echo');
    }
    try {
        return trim((string) fgets(STDIN));
    } finally {
        if ($canHide) {
            shell_exec('stty echo');
        }
        fwrite(STDOUT, PHP_EOL);
    }
}

$root = dirname(__DIR__);
$pdo = Database::connect($root);
$pdo->exec((string) file_get_contents($root . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($root . '/database/seed.sql'));
$pdo->exec((string) file_get_contents($root . '/database/import_legacy.sql'));

$count = (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn();
if ($count === 0) {
    $username = trim((string) readline('Admin username [admin]: ')) ?: 'admin';
    do {
        $password = readHidden('Admin password (12+ characters): ');
    } while (strlen($password) < 12 && fwrite(STDERR, "Password must be at least 12 characters.\n"));
    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Administrator created.\n";
}

$keyStatement = $pdo->prepare("SELECT value FROM settings WHERE key='google_maps_api_key'");
$keyStatement->execute();
if ((string) $keyStatement->fetchColumn() === '') {
    $configureKey = strtolower(trim((string) readline('Configure a Google Maps API key now? [y/N]: ')));
    if (in_array($configureKey, ['y', 'yes'], true)) {
        $apiKey = readHidden('Google Maps API key (input hidden): ');
        if ($apiKey !== '') {
            $save = $pdo->prepare("UPDATE settings SET value=?,updated_at=now() WHERE key='google_maps_api_key'");
            $save->execute([$apiKey]);
            echo "Google Maps API key saved.\n";

            echo "Persistent Google traffic-data storage may require separate authorization; see THIRD_PARTY_NOTICES.md.\n";
            $authorized = strtolower(trim((string) readline('Does your Google agreement authorize this storage and analysis? [y/N]: ')));
            if (in_array($authorized, ['y', 'yes'], true)) {
                $pdo->exec("UPDATE settings SET value='yes',updated_at=now() WHERE key='google_data_storage_authorized'");
                echo "Collection authorization recorded.\n";
            } else {
                echo "The key was saved, but scheduled collection remains disabled until authorization is confirmed in WebUI Settings.\n";
            }
        } else {
            echo "No key entered; configure it later in WebUI Settings.\n";
        }
    } else {
        echo "Google Maps API key skipped; configure it later in WebUI Settings.\n";
    }
}

echo "Database schema and default routes are installed.\n";
