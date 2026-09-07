<?php
declare(strict_types=1);

namespace CannonMiner;

use GuzzleHttp\Client;
use Throwable;

final class PasswordPolicy
{
    public const LEVELS = ['weak', 'fair', 'strong', 'very_strong'];

    public function __construct(private Settings $settings) {}

    /** @return array{valid:bool,errors:list<string>,warning:?string,strength:string} */
    public function validate(string $password, ?string $username = null): array
    {
        $minimumLength = max(8, min(64, (int) $this->settings->get('password_min_length', '12')));
        $errors = [];
        if (strlen($password) > 72) $errors[] = 'Use no more than 72 characters.';
        if (strlen($password) < $minimumLength) $errors[] = "Use at least {$minimumLength} characters.";
        if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Include an uppercase letter.';
        if (!preg_match('/[a-z]/', $password)) $errors[] = 'Include a lowercase letter.';
        if (!preg_match('/[0-9]/', $password)) $errors[] = 'Include a number.';
        if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Include a symbol.';
        if ($username !== null && $username !== '' && str_contains(strtolower($password), strtolower($username))) {
            $errors[] = 'Do not include the username in the password.';
        }

        $strength = $this->strength($password, $minimumLength);
        $minimumStrength = (string) $this->settings->get('password_min_strength', 'strong');
        if (!in_array($minimumStrength, self::LEVELS, true)) $minimumStrength = 'strong';
        if (array_search($strength, self::LEVELS, true) < array_search($minimumStrength, self::LEVELS, true)) {
            $errors[] = 'Password strength must be at least ' . str_replace('_', ' ', $minimumStrength) . '.';
        }

        $warning = null;
        if ($errors === []) {
            try {
                if ($this->isBreached($password)) {
                    $errors[] = 'This password appears in known breach data. Choose a different password.';
                }
            } catch (Throwable) {
                $warning = 'The breach-password service could not be reached. Your password was saved because all local requirements passed.';
            }
        }
        return ['valid' => $errors === [], 'errors' => $errors, 'warning' => $warning, 'strength' => $strength];
    }

    public function strength(string $password, int $minimumLength): string
    {
        $classes = (int) (bool) preg_match('/[A-Z]/', $password)
            + (int) (bool) preg_match('/[a-z]/', $password)
            + (int) (bool) preg_match('/[0-9]/', $password)
            + (int) (bool) preg_match('/[^A-Za-z0-9]/', $password);
        if (strlen($password) >= max(16, $minimumLength + 4) && $classes === 4) return 'very_strong';
        if (strlen($password) >= $minimumLength && $classes === 4) return 'strong';
        if (strlen($password) >= max(8, $minimumLength - 2) && $classes >= 3) return 'fair';
        return 'weak';
    }

    private function isBreached(string $password): bool
    {
        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);
        $response = (new Client(['timeout' => 4.0, 'connect_timeout' => 2.0]))->get(
            'https://api.pwnedpasswords.com/range/' . $prefix,
            ['headers' => ['Add-Padding' => 'true', 'User-Agent' => 'CannonMiner password policy']]
        );
        foreach (preg_split('/\r?\n/', (string) $response->getBody()) ?: [] as $line) {
            [$candidate] = array_pad(explode(':', trim($line), 2), 2, '');
            if (hash_equals($suffix, $candidate)) return true;
        }
        return false;
    }
}
