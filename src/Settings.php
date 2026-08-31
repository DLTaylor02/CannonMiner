<?php
declare(strict_types=1);

namespace CannonMiner;

use PDO;

final class Settings
{
    public function __construct(private PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo->query('SELECT key, value FROM settings ORDER BY key')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function save(array $values): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value, updated_at=now()'
        );
        foreach ($values as $key => $value) {
            if (preg_match('/^[a-z0-9_]+$/', (string) $key)) {
                $statement->execute([$key, trim((string) $value)]);
            }
        }
    }
}
