<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use CannonMiner\Database;
use CannonMiner\RouteGraph;
use CannonMiner\Settings;
use CannonMiner\SimulationTrafficProvider;
use CannonMiner\Simulator;

$pdo=Database::connect(dirname(__DIR__));
if(!(bool)$pdo->query("SELECT pg_try_advisory_lock(hashtext('cannonminer.simulator_worker'))")->fetchColumn())exit(1);
$simulator=new Simulator($pdo,new RouteGraph($pdo),new SimulationTrafficProvider($pdo),new Settings($pdo));
while(true){
    $ids=array_column($pdo->query("SELECT id FROM simulations WHERE status='running' ORDER BY last_tick_at LIMIT 100")->fetchAll(),'id');
    foreach($ids as $id)try{$simulator->tick((string)$id);}catch(Throwable $error){fwrite(STDERR,sprintf("[%s] Simulation %s tick failed: %s\n",date(DATE_ATOM),$id,$error->getMessage()));}
    usleep(500000);
}
