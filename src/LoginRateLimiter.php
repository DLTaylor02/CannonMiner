<?php
declare(strict_types=1);

namespace CannonMiner;

use PDO;

final class LoginRateLimiter
{
    public function __construct(private PDO $pdo, private Settings $settings) {}

    public function clientIp(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $trusted = array_filter(array_map('trim', explode(',', (string) getenv('TRUSTED_PROXIES'))));
        if (in_array($remote, $trusted, true)) {
            $forwarded = trim(explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
    }

    public function retryAfter(string $username, string $ip): int
    {
        $limit = max(1, min(100, (int) $this->settings->get('login_rate_limit', '5')));
        $lockout = max(1, min(1440, (int) $this->settings->get('login_lockout_minutes', '15')));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT EXTRACT(EPOCH FROM (max(attempted_at) + ({$lockout} * interval '1 minute') - now()))::int
            FROM login_attempts
            WHERE attempted_at > now() - ({$lockout} * interval '1 minute')
              AND (username_hash=? OR ip_hash=?)
            HAVING count(*) >= ?
        SQL);
        $statement->execute([$this->hash(strtolower(trim($username))), $this->hash($ip), $limit]);
        return max(0, (int) ($statement->fetchColumn() ?: 0));
    }

    public function fail(string $username, string $ip): void
    {
        $statement = $this->pdo->prepare('INSERT INTO login_attempts(username_hash,ip_hash) VALUES (?,?)');
        $statement->execute([$this->hash(strtolower(trim($username))), $this->hash($ip)]);
        if (random_int(1, 100) === 1) $this->pdo->exec("DELETE FROM login_attempts WHERE attempted_at < now() - interval '2 days'");
    }

    public function clear(string $username, string $ip): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE username_hash=? OR ip_hash=?');
        $statement->execute([$this->hash(strtolower(trim($username))), $this->hash($ip)]);
    }

    private function hash(string $value): string { return hash('sha256', $value); }
}
