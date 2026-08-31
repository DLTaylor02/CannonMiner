<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Collector;
use CannonMiner\Database;
use CannonMiner\Settings;

$pdo = Database::connect(dirname(__DIR__));
$settings = new Settings($pdo);
$scheduled = in_array('--scheduled', $argv, true);

if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('cannonminer.collector'))")->fetchColumn()) {
    echo "Another collection is already running.\n";
    exit(0);
}

if ($scheduled) {
    $interval = max(5, min(10080, (int) $settings->get('collection_interval_minutes', '60')));
    $statement = $pdo->prepare("SELECT started_at > now() - (? * interval '1 minute') FROM collection_runs WHERE status='success' ORDER BY started_at DESC LIMIT 1");
    $statement->execute([$interval]);
    if ((bool) $statement->fetchColumn()) {
        echo "Collection is not due.\n";
        exit(0);
    }
}

$run = $pdo->query("INSERT INTO collection_runs(status) VALUES ('running') RETURNING id")->fetchColumn();
try {
    $collected = (new Collector($pdo, $settings))->collectAll();
    $statement = $pdo->prepare("UPDATE collection_runs SET status='success',finished_at=now(),segments_collected=? WHERE id=?");
    $statement->execute([count($collected), $run]);
    echo 'Collected ' . count($collected) . " segments: " . implode(', ', $collected) . "\n";
} catch (Throwable $error) {
    $statement = $pdo->prepare("UPDATE collection_runs SET status='failed',finished_at=now(),message=? WHERE id=?");
    $statement->execute([substr($error->getMessage(), 0, 2000), $run]);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
