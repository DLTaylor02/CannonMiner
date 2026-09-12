<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;
use CannonMiner\Router;
use CannonMiner\Settings;

$root = dirname(__DIR__);
$pdo = Database::connect($root);
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('cannonminer.analysis_worker'))")->fetchColumn()) {
    fwrite(STDERR, "Another analysis worker is already running.\n");
    exit(1);
}

$pdo->exec(<<<'SQL'
    UPDATE analysis_jobs
    SET status='failed',stage='Failed',error='Analysis worker was interrupted.',updated_at=now(),finished_at=now()
    WHERE status='running'
SQL);

$currentId = null;
$finished = true;
register_shutdown_function(static function () use ($pdo, &$currentId, &$finished): void {
    if ($finished || $currentId === null) return;
    $fatal = error_get_last();
    $message = $fatal ? 'PHP worker stopped: ' . $fatal['message'] : 'Analysis worker stopped unexpectedly.';
    $statement = $pdo->prepare("UPDATE analysis_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=? AND status='running'");
    $statement->execute([substr($message, 0, 2000), $currentId]);
    fwrite(STDERR, sprintf("[%s] Job %s failed: %s\n", date(DATE_ATOM), $currentId, $message));
});

while (true) {
    $pdo->beginTransaction();
    $job = $pdo->query(<<<'SQL'
        SELECT id,input
        FROM analysis_jobs
        WHERE status='queued'
        ORDER BY created_at,id
        FOR UPDATE SKIP LOCKED
        LIMIT 1
    SQL)->fetch();
    if (!$job) {
        $pdo->commit();
        sleep(1);
        continue;
    }

    $currentId = (string) $job['id'];
    $claim = $pdo->prepare("UPDATE analysis_jobs SET status='running',calculation_method_version=?,started_at=now(),updated_at=now(),stage='Loading traffic observations' WHERE id=?");
    $claim->execute([Router::METHOD_VERSION,$currentId]);
    $pdo->commit();
    $finished = false;

    try {
        $values = json_decode((string) $job['input'], true, 512, JSON_THROW_ON_ERROR);
        $update = $pdo->prepare('UPDATE analysis_jobs SET progress_current=?,progress_total=?,stage=?,eta_seconds=?,updated_at=now() WHERE id=?');
        $router = new Router($pdo, new Settings($pdo));
        $results = $router->explore(
            (string) $values['start'], (string) $values['end'], (float) $values['speed'],
            (string) $values['profile'], (float) $values['risk'],
            static function (int $current, int $total, string $stage, ?float $eta = null) use ($update, &$currentId): void {
                $update->execute([$current, max(1, $total), $stage, $eta === null ? null : (int) ceil($eta), $currentId]);
            },isset($values['segments'])&&is_array($values['segments'])?$values['segments']:null
        );
        foreach ($results as &$result) {
            if ($result['departure'] instanceof DateTimeInterface) $result['departure'] = $result['departure']->format(DATE_ATOM);
        }
        unset($result);
        $complete = $pdo->prepare("UPDATE analysis_jobs SET status='complete',progress_current=progress_total,stage='Complete',eta_seconds=0,result=?::jsonb,updated_at=now(),finished_at=now() WHERE id=?");
        $complete->execute([json_encode($results, JSON_THROW_ON_ERROR), $currentId]);
    } catch (Throwable $error) {
        $failed = $pdo->prepare("UPDATE analysis_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=?");
        $failed->execute([substr($error->getMessage(), 0, 2000), $currentId]);
        fwrite(STDERR, sprintf("[%s] Job %s failed: %s\n", date(DATE_ATOM), $currentId, $error->getMessage()));
    }

    $finished = true;
    $currentId = null;
}
