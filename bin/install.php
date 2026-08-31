<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;

$root = dirname(__DIR__);
$pdo = Database::connect($root);
$pdo->exec((string) file_get_contents($root . '/database/schema.sql'));
$pdo->exec((string) file_get_contents($root . '/database/seed.sql'));
$pdo->exec((string) file_get_contents($root . '/database/import_legacy.sql'));

$count = (int) $pdo->query('SELECT count(*) FROM users')->fetchColumn();
if ($count === 0) {
    $username = trim((string) readline('Admin username [admin]: ')) ?: 'admin';
    do {
        $password = (string) readline('Admin password (12+ characters): ');
    } while (strlen($password) < 12 && fwrite(STDERR, "Password must be at least 12 characters.\n"));
    $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Administrator created.\n";
}

echo "Database schema and default routes are installed.\n";
