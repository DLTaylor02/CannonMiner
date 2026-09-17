<?php
declare(strict_types=1);

http_response_code(503);
header('Cache-Control: no-store');
header('Content-Length: 0');
ini_set('display_errors', '0');
ob_start();

$healthy = false;
register_shutdown_function(static function () use (&$healthy): void {
    $error = error_get_last();
    $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($healthy && !$fatal ? 200 : 503);
    header('Content-Length: 0');
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $root = dirname(__DIR__);
    if (version_compare(PHP_VERSION, '8.2.0', '<')) throw new RuntimeException('PHP version check failed');
    foreach (['pdo', 'pdo_pgsql', 'json', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) throw new RuntimeException("Missing PHP extension: {$extension}");
    }
    if (!is_readable($root . '/vendor/autoload.php') || !is_readable($root . '/.env')) {
        throw new RuntimeException('Application configuration or dependencies are unreadable');
    }
    if (PHP_OS_FAMILY === 'Linux') {
        $permissions = fileperms($root . '/.env');
        if ($permissions === false || ($permissions & 0007) !== 0) throw new RuntimeException('Environment file is accessible to other users');
        $sessionPath = (string) ini_get('session.save_path');
        if (str_contains($sessionPath, ';')) $sessionPath = (string) substr($sessionPath, strrpos($sessionPath, ';') + 1);
        if (!is_dir($sessionPath) || !is_writable($sessionPath) || ini_get('session.use_strict_mode') !== '1' || ini_get('session.cookie_httponly') !== '1') {
            throw new RuntimeException('Dedicated session configuration check failed');
        }
        if (!is_dir($root . '/public/uploads/vehicles') || !is_writable($root . '/public/uploads/vehicles')) {
            throw new RuntimeException('Vehicle image storage check failed');
        }
        $errorLog = (string) ini_get('error_log');
        if (!str_starts_with($errorLog, '/var/log/cannonminer/') || !is_writable(dirname($errorLog))) {
            throw new RuntimeException('Dedicated PHP logging configuration check failed');
        }
    }

    require $root . '/vendor/autoload.php';
    $pdo = CannonMiner\Database::connect($root);
    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) throw new RuntimeException('Database connectivity check failed');
    $tables = ['settings','users','login_attempts','segments','measurements','collection_runs','legacy_measurement_imports','analysis_jobs','planning_jobs','simulator_vehicles','simulations','simulation_events','system_metrics','storage_metrics','google_api_requests'];
    $statement = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
    foreach ($tables as $table) {
        $statement->execute(['public.' . $table]);
        if (!(bool) $statement->fetchColumn()) throw new RuntimeException("Missing database table: {$table}");
    }
    if ((int) $pdo->query("SELECT count(*) FROM users WHERE role='superadmin'")->fetchColumn() !== 1) {
        throw new RuntimeException('Superadmin invariant check failed');
    }
    $healthy = true;
} catch (Throwable $error) {
    error_log('Health check failed: ' . $error->getMessage());
}
