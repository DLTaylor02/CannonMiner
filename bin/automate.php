<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;
use CannonMiner\Router;
use CannonMiner\Settings;

$root=dirname(__DIR__);$pdo=Database::connect($root);$settings=new Settings($pdo);
if(!(bool)$pdo->query("SELECT pg_try_advisory_lock(hashtext('cannonminer.automation'))")->fetchColumn())exit(0);
$scheduled=in_array('--scheduled',$argv,true);
$interval=max(1,min(168,(int)$settings->get('automation_interval_hours','1')));
$minute=max(0,min(59,(int)$settings->get('automation_start_minute','0')));
$telemetryInterval=max(1,min(1440,(int)$settings->get('telemetry_interval_minutes','15')));
$metricsDue=true;$automationDue=true;
if($scheduled){
    $latestMetric=$pdo->query('SELECT max(recorded_at) FROM system_metrics')->fetchColumn();
    $metricsDue=!$latestMetric||strtotime((string)$latestMetric)<=time()-$telemetryInterval*60+60;
    $latestAutomation=$pdo->query("SELECT max(created_at) FROM analysis_jobs WHERE job_type='automated'")->fetchColumn();
    $automationDue=$settings->get('automation_enabled','yes')==='yes'&&(int)date('i')===$minute
        &&(!$latestAutomation||strtotime((string)$latestAutomation)<=time()-$interval*3600+60);
    if(!$metricsDue&&!$automationDue)exit(0);
}

function cpuSnapshot():array{
    $fields=preg_split('/\s+/',trim((string)file('/proc/stat')[0]));array_shift($fields);$values=array_map('intval',$fields);
    return['idle'=>($values[3]??0)+($values[4]??0),'total'=>array_sum($values)];
}
function currentUid():int{
    $status=(string)file_get_contents('/proc/self/status');
    if(!preg_match('/^Uid:\s+(\d+)/m',$status,$match))throw new RuntimeException('Unable to determine the telemetry process UID.');
    return(int)$match[1];
}
function userCpuSnapshot(int $uid):array{
    $snapshot=[];
    foreach(glob('/proc/[0-9]*/stat')?:[] as $statPath){
        $pid=basename(dirname($statPath));$status=@file_get_contents(dirname($statPath).'/status');
        if($status===false||!preg_match('/^Uid:\s+(\d+)/m',$status,$match)||(int)$match[1]!==$uid)continue;
        $stat=@file_get_contents($statPath);$close=$stat===false?false:strrpos($stat,')');
        if($close===false)continue;$fields=preg_split('/\s+/',trim(substr($stat,$close+1)));
        if(count($fields)<13)continue;$snapshot[$pid]=(int)$fields[11]+(int)$fields[12];
    }
    return$snapshot;
}
function userCpuDelta(array $before,array $after):int{
    $ticks=0;
    foreach($after as $pid=>$value)if(isset($before[$pid]))$ticks+=max(0,$value-$before[$pid]);
    return$ticks;
}
function directoryBytes(string $path):int{
    if(!is_dir($path)||!is_readable($path))return 0;
    $bytes=0;$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file)if($file->isFile()&&!$file->isLink())$bytes+=$file->getSize();return$bytes;
}
function recordMetrics(PDO $pdo,string $root):void{
    $uid=currentUid();$before=cpuSnapshot();$appBefore=userCpuSnapshot($uid);usleep(250000);$appAfter=userCpuSnapshot($uid);$after=cpuSnapshot();
    $total=max(1,$after['total']-$before['total']);$idle=$after['idle']-$before['idle'];
    $host=max(0,min(100,100*(1-$idle/$total)));$app=max(0,min($host,100*userCpuDelta($appBefore,$appAfter)/$total));
    $totalDisk=(int)disk_total_space($root);$freeDisk=(int)disk_free_space($root);
    $databaseBytes=(int)$pdo->query('SELECT pg_database_size(current_database())')->fetchColumn();
    $appBytes=$databaseBytes+directoryBytes($root)+directoryBytes('/var/log/cannonminer')+directoryBytes('/var/lib/cannonminer/sessions');
    $save=$pdo->prepare('INSERT INTO system_metrics(host_cpu_percent,app_cpu_percent,disk_total_bytes,disk_free_bytes,app_bytes) VALUES (?,?,?,?,?)');
    $save->execute([round($host,2),round($app,2),$totalDisk,$freeDisk,$appBytes]);
    $pdo->exec("DELETE FROM system_metrics WHERE recorded_at < now() - interval '90 days'");
}

if($metricsDue)recordMetrics($pdo,$root);
if(!$automationDue)exit(0);
if((bool)$pdo->query("SELECT EXISTS(SELECT 1 FROM analysis_jobs WHERE job_type='automated' AND status IN ('queued','running'))")->fetchColumn()){
    if(!$scheduled)fwrite(STDOUT,"An automated calculation batch is still active; telemetry recorded without adding duplicate jobs.\n");exit(0);
}
$router=new Router($pdo,$settings);$routes=$router->routeOptions();
$userId=$pdo->query("SELECT id FROM users WHERE role='superadmin' LIMIT 1")->fetchColumn();
if(!$userId)throw new RuntimeException('The automation job requires a superadmin account.');
$insert=$pdo->prepare("INSERT INTO analysis_jobs(id,user_id,status,input,job_type) VALUES (?,?, 'queued',?::jsonb,'automated')");
$speed=max(1,min(250,(float)$settings->get('automation_speed_mph','110')));$profile=(string)$settings->get('automation_profile','balanced');
if(!in_array($profile,['balanced','fastest','reliability'],true))$profile='balanced';$risk=max(0,min(1,(float)$settings->get('automation_max_risk','.20')));
foreach($routes as $route){
    $input=['start'=>$route['start'],'end'=>$route['end'],'speed'=>$speed,'profile'=>$profile,'risk'=>$risk,'segments'=>$route['segments']];
    $insert->execute([bin2hex(random_bytes(16)),$userId,json_encode($input,JSON_THROW_ON_ERROR)]);
}
printf("[%s] Queued %d automated route calculations.\n",date(DATE_ATOM),count($routes));
