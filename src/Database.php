<?php
declare(strict_types=1);

namespace CannonMiner;

use PDO;

final class Database
{
    public static function connect(string $root): PDO
    {
        self::loadEnvironment($root . '/.env');
        $pdo = new PDO(
            getenv('DATABASE_URL') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=cannonminer',
            getenv('DATABASE_USER') ?: 'postgres',
            getenv('DATABASE_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        return $pdo;
    }

    private static function loadEnvironment(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (getenv(trim($key)) === false) {
                putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
            }
        }
    }
}
