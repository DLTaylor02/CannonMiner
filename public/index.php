<?php
declare(strict_types=1);

use CannonMiner\Database;
use CannonMiner\Router;
use CannonMiner\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);

$root = dirname(__DIR__); $pdo = Database::connect($root); $settings = new Settings($pdo); $router = new Router($pdo, $settings);
$app = AppFactory::create(); $twig = Twig::create($root . '/templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig)); $app->addRoutingMiddleware(); $app->addErrorMiddleware(false, true, true);
$identity = null;
if (isset($_SESSION['user_id'])) {
    $statement=$pdo->prepare('SELECT id,username,role,theme FROM users WHERE id=?'); $statement->execute([$_SESSION['user_id']]);
    $identity=$statement->fetch() ?: null;
}

$render = static function (Request $request, Response $response, string $template, array $data = []) use ($twig,&$identity): Response {
    return $twig->render($response, $template, $data + ['user'=>$identity['username']??null,'user_id'=>$identity['id']??null,
        'role'=>$identity['role']??null,'theme'=>$identity['theme']??'adaptive']);
};
$guard = static function (Request $request, RequestHandlerInterface $handler) use (&$identity): Response {
    if ($identity) return $handler->handle($request);
    $response = new \Slim\Psr7\Response();
    return $response->withHeader('Location', '/login')->withStatus(302);
};
$requireAdmin = static function (Request $request, RequestHandlerInterface $handler) use (&$identity): Response {
    if ($identity && in_array($identity['role'],['admin','superadmin'],true)) return $handler->handle($request);
    return (new \Slim\Psr7\Response())->withHeader('Location','/')->withStatus(302);
};
$csrf = static function (Request $request): void {
    $token = (string)(($request->getParsedBody() ?? [])['_token'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) throw new RuntimeException('Your session expired. Please try again.');
};

$app->map(['GET','POST'], '/login', function (Request $request, Response $response) use ($pdo, $render): Response {
    $error = null;
    if ($request->getMethod() === 'POST') {
        $body = (array)$request->getParsedBody(); $statement = $pdo->prepare('SELECT * FROM users WHERE username=?');
        $statement->execute([trim((string)($body['username'] ?? ''))]); $user = $statement->fetch();
        if ($user && password_verify((string)($body['password'] ?? ''), $user['password_hash'])) {
            session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $response->withHeader('Location', '/')->withStatus(302);
        }
        $error = 'Incorrect username or password.';
    }
    return $render($request, $response, 'login.twig', ['error' => $error]);
});
$app->post('/logout', function (Request $request, Response $response) use ($csrf): Response {
    $csrf($request); $_SESSION = []; session_destroy(); return $response->withHeader('Location', '/login')->withStatus(302);
})->add($guard);
$app->post('/theme', function(Request $request,Response $response)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$theme=(string)(($request->getParsedBody()??[])['theme']??'adaptive');
    if(!in_array($theme,['light','dark','adaptive'],true))$theme='adaptive';
    $statement=$pdo->prepare('UPDATE users SET theme=? WHERE id=?');$statement->execute([$theme,$identity['id']]);
    return $response->withHeader('Location',$request->getHeaderLine('Referer')?:'/')->withStatus(302);
})->add($guard);
$app->get('/favicon.ico', static fn(Request $request,Response $response):Response => $response->withStatus(204));

$app->map(['GET','POST'], '/', function (Request $request, Response $response) use ($pdo,$router,$settings,$render,&$identity): Response {
    $nodes = $router->nodes(); $input = ['start'=>'redball','end'=>'portofino','speed'=>(float)$settings->get('default_speed_mph','110'),
        'profile'=>'balanced','risk'=>(float)$settings->get('default_max_delay_risk','.20')];
    $results = []; $error = null;
    if ($request->getMethod() === 'POST') {
        $input = array_merge($input, (array)$request->getParsedBody());
        if($identity['role']==='user')$input['risk']=(float)$settings->get('default_max_delay_risk','.20');
        $token=(string)($input['_token']??'');if(!hash_equals($_SESSION['csrf']??'',$token))throw new RuntimeException('Your session expired.');
        $id=bin2hex(random_bytes(16));$statement=$pdo->prepare("INSERT INTO analysis_jobs(id,user_id,status,input) VALUES (?,?,'queued',?::jsonb)");
        $statement->execute([$id,$_SESSION['user_id'],json_encode(['start'=>$input['start'],'end'=>$input['end'],'speed'=>(float)$input['speed'],'profile'=>$input['profile'],'risk'=>(float)$input['risk']],JSON_THROW_ON_ERROR)]);
        return $response->withHeader('Location','/analysis/'.$id)->withStatus(302);
    }
    return $render($request, $response, 'dashboard.twig', ['nodes'=>$nodes,'input'=>$input,'results'=>$results,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->get('/analysis/{id}',function(Request $request,Response $response,array $args)use($pdo,$render,&$identity):Response{
    $statement=$pdo->prepare('SELECT * FROM analysis_jobs WHERE id=?');$statement->execute([$args['id']]);$job=$statement->fetch();
    if(!$job)return $response->withStatus(404);
    return $render($request,$response,'analysis.twig',['job'=>$job,'can_run'=>(int)$job['user_id']===(int)$identity['id'],'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/analysis/{id}/status',function(Request $request,Response $response,array $args)use($pdo):Response{
    $statement=$pdo->prepare("SELECT status,progress_current,progress_total,stage,eta_seconds,updated_at,error,result,input->>'speed' AS target_speed_mph FROM analysis_jobs WHERE id=?");
    $statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return $response->withStatus(404);
    $response->getBody()->write(json_encode($job,JSON_THROW_ON_ERROR));return $response->withHeader('Content-Type','application/json');
})->add($guard);

$app->get('/history',function(Request $request,Response $response)use($pdo,$render):Response{
    $runs=$pdo->query(<<<'SQL'
        SELECT * FROM (
          SELECT j.id,u.username,j.status,j.stage,j.created_at,
            j.input->>'start' AS start_node,j.input->>'end' AS end_node,j.input->>'profile' AS profile,
            (j.input->>'speed')::float AS target_speed_mph,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN (j.result->0->>'departure') END AS departure,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN (j.result->0->>'risk')::float END AS risk,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN (j.result->0->>'expected_seconds')::float END AS expected_seconds
          FROM analysis_jobs j JOIN users u ON u.id=j.user_id
        ) history
        ORDER BY (status='complete') DESC,risk ASC NULLS LAST,expected_seconds ASC NULLS LAST,created_at DESC
    SQL)->fetchAll();
    return $render($request,$response,'history.twig',['runs'=>$runs,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->post('/history/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf):Response{
    $csrf($request);
    if(preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',(string)$args['id'])){
        $statement=$pdo->prepare('DELETE FROM analysis_jobs WHERE id=?');$statement->execute([$args['id']]);
    }
    return $response->withHeader('Location','/history')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->post('/analysis/{id}/run',function(Request $request,Response $response,array $args)use($pdo,$csrf,$router,&$identity):Response{
    $csrf($request);$claim=$pdo->prepare("UPDATE analysis_jobs SET status='running',started_at=now(),updated_at=now(),stage='Loading traffic observations' WHERE id=? AND user_id=? AND status='queued' RETURNING input");
    $claim->execute([$args['id'],$identity['id']]);$input=$claim->fetchColumn();if($input===false)return $response->withStatus(409);
    session_write_close();ignore_user_abort(true);set_time_limit(0);$values=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    $update=$pdo->prepare('UPDATE analysis_jobs SET progress_current=?,progress_total=?,stage=?,eta_seconds=?,updated_at=now() WHERE id=?');
    $jobFinished=false;
    register_shutdown_function(static function()use(&$jobFinished,$pdo,$args):void{
        if($jobFinished)return;$fatal=error_get_last();
        if(!$fatal||!in_array($fatal['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
        $statement=$pdo->prepare("UPDATE analysis_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=? AND status='running'");
        $statement->execute([substr('PHP worker stopped: '.$fatal['message'],0,2000),$args['id']]);
    });
    try{
        $results=$router->explore((string)$values['start'],(string)$values['end'],(float)$values['speed'],(string)$values['profile'],(float)$values['risk'],
            static function(int $current,int $total,string $stage,?float $eta=null)use($update,$args):void{$update->execute([$current,max(1,$total),$stage,$eta===null?null:(int)ceil($eta),$args['id']]);});
        foreach($results as &$result)if($result['departure'] instanceof DateTimeInterface)$result['departure']=$result['departure']->format(DATE_ATOM);unset($result);
        $finish=$pdo->prepare("UPDATE analysis_jobs SET status='complete',progress_current=progress_total,stage='Complete',eta_seconds=0,result=?::jsonb,updated_at=now(),finished_at=now() WHERE id=?");
        $finish->execute([json_encode($results,JSON_THROW_ON_ERROR),$args['id']]);
        $jobFinished=true;
    }catch(Throwable $error){$fail=$pdo->prepare("UPDATE analysis_jobs SET status='failed',stage='Failed',error=?,updated_at=now(),finished_at=now() WHERE id=?");$fail->execute([substr($error->getMessage(),0,2000),$args['id']]);$jobFinished=true;}
    $response->getBody()->write('{"ok":true}');return $response->withHeader('Content-Type','application/json');
})->add($guard);

$app->get('/trends', fn(Request $q, Response $r): Response => $render($q,$r,'trends.twig',['trends'=>$router->trends(),'csrf'=>$_SESSION['csrf']]))->add($guard);
$app->post('/segments/{id}/toggle', function (Request $request, Response $response, array $args) use ($pdo,$csrf): Response {
    $csrf($request); $statement=$pdo->prepare('UPDATE segments SET enabled=NOT enabled WHERE id=?');
    $statement->execute([(int)$args['id']]); return $response->withHeader('Location','/settings')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->post('/segments',function(Request $request,Response $response)use($pdo,$csrf):Response{
    $csrf($request);$body=(array)$request->getParsedBody();$name=strtolower(trim((string)($body['name']??'')));
    $timezone=trim((string)($body['timezone']??'America/New_York'));
    if(preg_match('/^[a-z0-9]+_to_[a-z0-9]+$/',$name)&&in_array($timezone,DateTimeZone::listIdentifiers(),true)){
        [$start,$end]=explode('_to_',$name,2);$statement=$pdo->prepare('INSERT INTO segments(name,start_node,end_node,origin,destination,timezone) VALUES (?,?,?,?,?,?) ON CONFLICT(name) DO UPDATE SET origin=EXCLUDED.origin,destination=EXCLUDED.destination,timezone=EXCLUDED.timezone');
        $statement->execute([$name,$start,$end,trim((string)$body['origin']),trim((string)$body['destination']),$timezone]);
    }
    return $response->withHeader('Location','/settings')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->map(['GET','POST'], '/settings', function (Request $request, Response $response) use ($pdo,$settings,$render,$csrf,&$identity): Response {
    $message = null;
    if ($request->getMethod() === 'POST') {
        $csrf($request); $body=(array)$request->getParsedBody(); unset($body['_token']);
        $allowed=['default_max_delay_risk'];
        if($identity['role']==='superadmin')$allowed=array_merge($allowed,['google_maps_api_key','google_data_storage_authorized','collection_interval_minutes','timezone','default_speed_mph','candidate_routes','departure_interval_minutes']);
        $body=array_intersect_key($body,array_flip($allowed));
        if($identity['role']==='superadmin'){
            $body['collection_interval_minutes']=(string)max(5,min(10080,(int)($body['collection_interval_minutes']??60)));
            $body['departure_interval_minutes']=(string)max(5,min(60,(int)($body['departure_interval_minutes']??15)));
            $body['google_data_storage_authorized']=isset($body['google_data_storage_authorized'])?'yes':'no';
            $submittedKey=trim((string)($body['google_maps_api_key']??''));if($submittedKey===''||$submittedKey==='************')unset($body['google_maps_api_key']);
        }
        $settings->save($body);$message='Settings saved.';
    }
    $lastRun=$pdo->query('SELECT * FROM collection_runs ORDER BY started_at DESC LIMIT 1')->fetch();
    $values=$settings->all(); $keyConfigured=($values['google_maps_api_key'] ?? '') !== ''; unset($values['google_maps_api_key']);
    return $render($request,$response,'settings.twig',['settings'=>$values,'google_key_configured'=>$keyConfigured,'segments'=>$pdo->query('SELECT * FROM segments ORDER BY name')->fetchAll(),'last_run'=>$lastRun,'message'=>$message,'csrf'=>$_SESSION['csrf']]);
})->add($requireAdmin)->add($guard);

$app->map(['GET','POST'],'/users',function(Request $request,Response $response)use($pdo,$render,$csrf,&$identity):Response{
    $message=$error=null;
    if($request->getMethod()==='POST'){
        $csrf($request);$body=(array)$request->getParsedBody();$role=(string)($body['role']??'user');
        $allowed=['user','admin'];
        if(!in_array($role,$allowed,true))$error='That role cannot be assigned.';
        elseif(strlen((string)($body['password']??''))<12)$error='Password must be at least 12 characters.';
        else try{$statement=$pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES (?,?,?)');
            $statement->execute([trim((string)$body['username']),password_hash((string)$body['password'],PASSWORD_DEFAULT),$role]);$message='User created.';
        }catch(Throwable){$error='That username is unavailable.';}
    }
    return $render($request,$response,'users.twig',['users'=>$pdo->query('SELECT id,username,role,theme,created_at FROM users ORDER BY username')->fetchAll(),'message'=>$message,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
})->add($requireAdmin)->add($guard);
$app->post('/users/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$statement=$pdo->prepare('SELECT role FROM users WHERE id=?');$statement->execute([(int)$args['id']]);$target=$statement->fetchColumn();
    if((int)$args['id']!== (int)$identity['id']&&$target!==false&&$target!=='superadmin'){
        $delete=$pdo->prepare('DELETE FROM users WHERE id=?');$delete->execute([(int)$args['id']]);
    }
    return $response->withHeader('Location','/users')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->post('/users/{id}',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$id=(int)$args['id'];$lookup=$pdo->prepare('SELECT role FROM users WHERE id=?');$lookup->execute([$id]);$current=$lookup->fetchColumn();
    if($current===false||($current==='superadmin'&&(int)$identity['id']!==$id))return $response->withHeader('Location','/users')->withStatus(302);
    $body=(array)$request->getParsedBody();$role=$current==='superadmin'?'superadmin':(string)($body['role']??$current);$allowed=['user','admin'];
    if(!in_array($role,$allowed,true))$role=(string)$current;
    $password=(string)($body['password']??'');
    if($password!==''&&strlen($password)>=12){$update=$pdo->prepare('UPDATE users SET role=?,password_hash=? WHERE id=?');$update->execute([$role,password_hash($password,PASSWORD_DEFAULT),$id]);}
    else{$update=$pdo->prepare('UPDATE users SET role=? WHERE id=?');$update->execute([$role,$id]);}
    return $response->withHeader('Location','/users')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->run();
