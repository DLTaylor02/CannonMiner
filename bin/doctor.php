<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;
use CannonMiner\Router;
use CannonMiner\Settings;

$root = dirname(__DIR__);
$failures = 0;
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$passed) $failures++;
};

echo "CannonMiner diagnostics\n\n";
$check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP 8.2+ (' . PHP_VERSION . ')');
foreach (['pdo', 'pdo_pgsql', 'json', 'mbstring'] as $extension) {
    $check(extension_loaded($extension), "PHP extension {$extension}");
}

try {
    $pdo = Database::connect($root);
    $check(true, 'PostgreSQL connection');
} catch (Throwable $error) {
    $check(false, 'PostgreSQL connection: ' . $error->getMessage());
    exit(1);
}

$tables = ['settings','users','segments','measurements','collection_runs','legacy_measurement_imports','analysis_jobs'];
foreach ($tables as $table) {
    $statement = $pdo->prepare("SELECT to_regclass(?) IS NOT NULL");
    $statement->execute(['public.' . $table]);
    $check((bool)$statement->fetchColumn(), "Database table {$table}");
}

$settings = new Settings($pdo);
$values = $settings->all();
$check(($values['collection_interval_minutes'] ?? null) === '60' || (int)($values['collection_interval_minutes'] ?? 0) >= 5,
    'Collection interval is configured (' . ($values['collection_interval_minutes'] ?? 'missing') . ' minutes)');
$check(($values['timezone'] ?? '') !== '', 'Traffic timezone is configured');
$check(($values['google_maps_api_key'] ?? '') !== '', 'Google Maps API key is configured');
$check(($values['google_data_storage_authorized'] ?? 'no') === 'yes', 'Google data-storage authorization is confirmed');

$users = (int)$pdo->query('SELECT count(*) FROM users')->fetchColumn();
$superadmins = (int)$pdo->query("SELECT count(*) FROM users WHERE role='superadmin'")->fetchColumn();
$segments = (int)$pdo->query('SELECT count(*) FROM segments WHERE enabled')->fetchColumn();
$measurements = (int)$pdo->query('SELECT count(*) FROM measurements')->fetchColumn();
$check($users > 0, "Administrator account exists ({$users})");
$check($superadmins > 0, "Superadmin account exists ({$superadmins})");
$check($segments > 0, "Enabled route segments exist ({$segments})");
$check($measurements > 0, "Traffic measurements exist ({$measurements})");

if ($segments > 0 && $measurements > 0) {
    echo "\nRunning route-analysis smoke test...\n";
    try {
        $started = microtime(true);
        $results = (new Router($pdo, $settings))->explore('redball', 'portofino',
            (float)$settings->get('default_speed_mph', '110'), 'balanced',
            (float)$settings->get('default_max_delay_risk', '.20'));
        $elapsed = microtime(true)-$started;
        $check(count($results) > 0 && isset($results[0]['expected_seconds'], $results[0]['map_url']),
            sprintf('Route analysis returned %d results in %.2f seconds', count($results), $elapsed));
        $check(memory_get_peak_usage(true) < 128*1024*1024,
            sprintf('Route analysis peak PHP memory %.1f MB', memory_get_peak_usage(true)/1024/1024));
    } catch (Throwable $error) {
        $check(false, 'Route analysis: ' . $error->getMessage());
    }
}

echo $failures === 0 ? "\nAll diagnostics passed.\n" : "\n{$failures} diagnostic check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
