<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CannonMiner\Database;
use CannonMiner\Planner;

$root=dirname(__DIR__);$pdo=Database::connect($root);
if(!(bool)$pdo->query("SELECT pg_try_advisory_lock(hashtext('cannonminer.plan_worker'))")->fetchColumn())exit(1);
$pdo->exec("UPDATE planning_jobs SET status='failed',stage='Failed',error='Planning worker was interrupted.',updated_at=now(),finished_at=now() WHERE status='running'");
$currentId=null;$finished=true;
register_shutdown_function(static function()use($pdo,&$currentId,&$finished):void{
    if($finished||$currentId===null)return;$fatal=error_get_last();$message=$fatal?'PHP worker stopped: '.$fatal['message']:'Planning worker stopped unexpectedly.';
    $statement=$pdo->prepare("UPDATE planning_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=? AND status='running'");
    $statement->execute([substr($message,0,2000),$currentId]);
});
while(true){
    $pdo->beginTransaction();$job=$pdo->query("SELECT id,input FROM planning_jobs WHERE status='queued' ORDER BY created_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch();
    if(!$job){$pdo->commit();sleep(1);continue;}
    $currentId=(string)$job['id'];$pdo->prepare("UPDATE planning_jobs SET status='running',planning_method_version=?,started_at=now(),updated_at=now(),stage='Loading historical calculations' WHERE id=?")->execute([Planner::METHOD_VERSION,$currentId]);$pdo->commit();$finished=false;
    try{
        $input=json_decode((string)$job['input'],true,512,JSON_THROW_ON_ERROR);$update=$pdo->prepare('UPDATE planning_jobs SET progress_current=?,progress_total=?,stage=?,updated_at=now() WHERE id=?');
        $results=(new Planner($pdo))->project($input,static function(int $current,int $total,string $stage)use($update,&$currentId):void{$update->execute([$current,$total,$stage,$currentId]);});
        $pdo->prepare("UPDATE planning_jobs SET status='complete',progress_current=progress_total,stage='Complete',result=?::jsonb,updated_at=now(),finished_at=now() WHERE id=?")->execute([json_encode($results,JSON_THROW_ON_ERROR),$currentId]);
    }catch(Throwable $error){
        $pdo->prepare("UPDATE planning_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=?")->execute([substr($error->getMessage(),0,2000),$currentId]);
        fwrite(STDERR,sprintf("[%s] Planning job %s failed: %s\n",date(DATE_ATOM),$currentId,$error->getMessage()));
    }
    $finished=true;$currentId=null;
}
