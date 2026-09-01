<?php
declare(strict_types=1);

use CannonMiner\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only be run from the command line.\n");
    exit(1);
}

function readHiddenPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $canHide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')
        && trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';
    if ($canHide) shell_exec('stty -echo');
    try {
        return trim((string) fgets(STDIN));
    } finally {
        if ($canHide) shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    $pdo = Database::connect(dirname(__DIR__));
    $superadmins = $pdo->query("SELECT id,username FROM users WHERE role='superadmin' ORDER BY id")->fetchAll();
    if (count($superadmins) !== 1) {
        throw new RuntimeException('Expected exactly one superadmin; found ' . count($superadmins) . '. Run setup.sh to repair the role constraint.');
    }

    $account = $superadmins[0];
    echo "Resetting password for superadmin '{$account['username']}'.\n";
    do {
        $password = readHiddenPassword('New password (12+ characters): ');
        if (strlen($password) < 12) fwrite(STDERR, "Password must be at least 12 characters.\n");
    } while (strlen($password) < 12);

    $confirmation = readHiddenPassword('Confirm new password: ');
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('Passwords did not match; no changes were made.');
    }

    $statement = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
    $statement->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
    echo "Superadmin password updated.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
