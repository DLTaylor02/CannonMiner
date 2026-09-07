<?php
declare(strict_types=1);

use CannonMiner\Database;
use CannonMiner\LoginRateLimiter;
use CannonMiner\PasswordPolicy;
use CannonMiner\Router;
use CannonMiner\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__); $pdo = Database::connect($root); $settings = new Settings($pdo); $router = new Router($pdo, $settings);
$remote=(string)($_SERVER['REMOTE_ADDR']??'');$trustedProxies=array_filter(array_map('trim',explode(',',(string)getenv('TRUSTED_PROXIES'))));
$secureRequest=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(in_array($remote,$trustedProxies,true)&&strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]))==='https');
session_name('CannonMinerSession');
session_start(['use_strict_mode'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>$secureRequest]);

$passwordPolicy = new PasswordPolicy($settings); $loginLimiter = new LoginRateLimiter($pdo, $settings);
$app = AppFactory::create(); $twig = Twig::create($root . '/templates', ['cache' => false]);
$app->add(TwigMiddleware::create($app, $twig)); $app->addRoutingMiddleware(); $app->addErrorMiddleware(false, true, true);
$identity = null;
if (isset($_SESSION['user_id'])) {
    $statement=$pdo->prepare('SELECT id,username,role,theme,must_change_password FROM users WHERE id=?'); $statement->execute([$_SESSION['user_id']]);
    $identity=$statement->fetch() ?: null;
}

$render = static function (Request $request, Response $response, string $template, array $data = []) use ($twig,&$identity): Response {
    $notice=$_SESSION['notice']??null;$advisory=$_SESSION['advisory']??null;unset($_SESSION['notice'],$_SESSION['advisory']);
    return $twig->render($response, $template, $data + ['user'=>$identity['username']??null,'user_id'=>$identity['id']??null,
        'role'=>$identity['role']??null,'theme'=>$identity['theme']??'adaptive','notice'=>$notice,'advisory'=>$advisory])->withHeader('Cache-Control','private, no-store');
};
$guard = static function (Request $request, RequestHandlerInterface $handler) use (&$identity): Response {
    if ($identity) {
        if ($identity['role']!=='superadmin' && $identity['must_change_password'] && !in_array($request->getUri()->getPath(),['/password','/logout'],true)) {
            return (new \Slim\Psr7\Response())->withHeader('Location','/password')->withStatus(302);
        }
        return $handler->handle($request);
    }
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

$app->map(['GET','POST'], '/login', function (Request $request, Response $response) use ($pdo, $render, $loginLimiter): Response {
    $error = null;
    if ($request->getMethod() === 'POST') {
        $body = (array)$request->getParsedBody();$username=trim((string)($body['username']??''));$ip=$loginLimiter->clientIp($_SERVER);
        $retry=$loginLimiter->retryAfter($username,$ip);
        if($retry>0)return $render($request,$response->withStatus(429)->withHeader('Retry-After',(string)$retry),'login.twig',['error'=>'Too many sign-in attempts. Try again in '.max(1,(int)ceil($retry/60)).' minute(s).']);
        $statement = $pdo->prepare('SELECT * FROM users WHERE username=?');
        $statement->execute([$username]); $user = $statement->fetch();
        $candidatePassword=(string)($body['password']??'');$comparisonHash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $passwordMatches=password_verify($candidatePassword,$comparisonHash);
        if ($user && $passwordMatches) {
            $loginLimiter->clear($username,$ip);
            session_regenerate_id(true); $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username'];
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            $destination=$user['role']!=='superadmin'&&$user['must_change_password']?'/password':'/';
            return $response->withHeader('Location',$destination)->withStatus(302);
        }
        $loginLimiter->fail($username,$ip);
        $retry=$loginLimiter->retryAfter($username,$ip);
        if($retry>0)return $render($request,$response->withStatus(429)->withHeader('Retry-After',(string)$retry),'login.twig',['error'=>'Too many sign-in attempts. Try again in '.max(1,(int)ceil($retry/60)).' minute(s).']);
        $error = 'Incorrect username or password.';
    }
    return $render($request, $response, 'login.twig', ['error' => $error]);
});
$app->post('/logout', function (Request $request, Response $response) use ($csrf): Response {
    $csrf($request); $_SESSION = []; session_destroy(); return $response->withHeader('Location', '/login')->withStatus(302);
})->add($guard);
$app->map(['GET','POST'],'/password',function(Request $request,Response $response)use($pdo,$settings,$render,$csrf,$passwordPolicy,&$identity):Response{
    $error=$message=$warning=null;
    if($request->getMethod()==='POST'){
        $csrf($request);$body=(array)$request->getParsedBody();$current=(string)($body['current_password']??'');$password=(string)($body['password']??'');
        $statement=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$statement->execute([$identity['id']]);$hash=(string)$statement->fetchColumn();
        if(!password_verify($current,$hash))$error='Current password is incorrect.';
        elseif(!hash_equals($password,(string)($body['password_confirmation']??'')))$error='New passwords do not match.';
        else{
            $result=$identity['role']==='superadmin'?['valid'=>true,'errors'=>[],'warning'=>null]:$passwordPolicy->validate($password,$identity['username']);
            if(!$result['valid'])$error=implode(' ',$result['errors']);
            else{$update=$pdo->prepare('UPDATE users SET password_hash=?,must_change_password=FALSE WHERE id=?');$update->execute([password_hash($password,PASSWORD_DEFAULT),$identity['id']]);$identity['must_change_password']=false;$message='Password changed.';$warning=$result['warning'];}
        }
    }
    return $render($request,$response,'password.twig',['error'=>$error,'message'=>$message,'warning'=>$warning,'csrf'=>$_SESSION['csrf'],'password_policy'=>['minimum_length'=>max(8,min(64,(int)$settings->get('password_min_length','12'))),'minimum_strength'=>$settings->get('password_min_strength','strong')]]);
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
    return $render($request,$response,'analysis.twig',['job'=>$job,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/analysis/{id}/status',function(Request $request,Response $response,array $args)use($pdo):Response{
    $statement=$pdo->prepare('SELECT status,progress_current,progress_total,stage,eta_seconds,updated_at,error,result FROM analysis_jobs WHERE id=?');
    $statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return $response->withStatus(404);
    $response->getBody()->write(json_encode($job,JSON_THROW_ON_ERROR));return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/history',function(Request $request,Response $response)use($pdo,$render):Response{
    $runs=$pdo->query(<<<'SQL'
        SELECT * FROM (
          SELECT j.id,u.username,j.status,j.stage,j.created_at,
            j.input->>'start' AS start_node,j.input->>'end' AS end_node,j.input->>'profile' AS profile,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN (j.result->0->>'target_speed_mph')::float END AS target_speed_mph,
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
        if($identity['role']==='superadmin')$allowed=array_merge($allowed,['google_maps_api_key','google_data_storage_authorized','collection_interval_minutes','timezone','default_speed_mph','candidate_routes','departure_interval_minutes','login_rate_limit','login_lockout_minutes','password_min_strength','password_min_length']);
        $body=array_intersect_key($body,array_flip($allowed));
        if($identity['role']==='superadmin'){
            $body['collection_interval_minutes']=(string)max(5,min(10080,(int)($body['collection_interval_minutes']??60)));
            $body['departure_interval_minutes']=(string)max(5,min(60,(int)($body['departure_interval_minutes']??15)));
            $body['login_rate_limit']=(string)max(1,min(100,(int)($body['login_rate_limit']??5)));
            $body['login_lockout_minutes']=(string)max(1,min(1440,(int)($body['login_lockout_minutes']??15)));
            $body['password_min_length']=(string)max(8,min(64,(int)($body['password_min_length']??12)));
            if(!in_array($body['password_min_strength']??'',['strong','very_strong'],true))$body['password_min_strength']='strong';
            $body['google_data_storage_authorized']=isset($body['google_data_storage_authorized'])?'yes':'no';
            $submittedKey=trim((string)($body['google_maps_api_key']??''));if($submittedKey===''||$submittedKey==='************')unset($body['google_maps_api_key']);
        }
        $settings->save($body);$message='Settings saved.';
    }
    $lastRun=$pdo->query(<<<'SQL'
        SELECT *,to_char(finished_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') AS finished_at_iso
        FROM collection_runs
        WHERE status='success' AND finished_at IS NOT NULL
        ORDER BY finished_at DESC LIMIT 1
    SQL)->fetch();
    $values=$settings->all(); $keyConfigured=($values['google_maps_api_key'] ?? '') !== ''; unset($values['google_maps_api_key']);
    return $render($request,$response,'settings.twig',['settings'=>$values,'google_key_configured'=>$keyConfigured,'segments'=>$pdo->query('SELECT * FROM segments ORDER BY name')->fetchAll(),'last_run'=>$lastRun,'message'=>$message,'csrf'=>$_SESSION['csrf']]);
})->add($requireAdmin)->add($guard);

$app->map(['GET','POST'],'/users',function(Request $request,Response $response)use($pdo,$settings,$render,$csrf,$passwordPolicy,&$identity):Response{
    $message=$error=$warning=null;
    if($request->getMethod()==='POST'){
        $csrf($request);$body=(array)$request->getParsedBody();$role=(string)($body['role']??'user');
        $allowed=['user','admin'];
        $validation=$passwordPolicy->validate((string)($body['password']??''),trim((string)($body['username']??'')));
        if(!in_array($role,$allowed,true))$error='That role cannot be assigned.';
        elseif(!$validation['valid'])$error=implode(' ',$validation['errors']);
        else try{$statement=$pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES (?,?,?)');
            $statement->execute([trim((string)$body['username']),password_hash((string)$body['password'],PASSWORD_DEFAULT),$role]);$message='User created.';$warning=$validation['warning'];
        }catch(Throwable){$error='That username is unavailable.';}
    }
    return $render($request,$response,'users.twig',['users'=>$pdo->query('SELECT id,username,role,theme,must_change_password,created_at FROM users ORDER BY username')->fetchAll(),'message'=>$message,'error'=>$error,'warning'=>$warning,'csrf'=>$_SESSION['csrf'],'password_policy'=>['minimum_length'=>max(8,min(64,(int)$settings->get('password_min_length','12'))),'minimum_strength'=>$settings->get('password_min_strength','strong')]]);
})->add($requireAdmin)->add($guard);
$app->post('/users/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$statement=$pdo->prepare('SELECT role FROM users WHERE id=?');$statement->execute([(int)$args['id']]);$target=$statement->fetchColumn();
    if((int)$args['id']!== (int)$identity['id']&&$target!==false&&$target!=='superadmin'){
        $delete=$pdo->prepare('DELETE FROM users WHERE id=?');$delete->execute([(int)$args['id']]);
    }
    return $response->withHeader('Location','/users')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->post('/users/{id}',function(Request $request,Response $response,array $args)use($pdo,$csrf,$passwordPolicy,&$identity):Response{
    $csrf($request);$id=(int)$args['id'];$lookup=$pdo->prepare('SELECT username,role FROM users WHERE id=?');$lookup->execute([$id]);$target=$lookup->fetch();
    if(!$target||($target['role']==='superadmin'&&(int)$identity['id']!==$id))return $response->withHeader('Location','/users')->withStatus(302);
    $body=(array)$request->getParsedBody();$role=$target['role']==='superadmin'?'superadmin':(string)($body['role']??$target['role']);
    if(!in_array($role,['user','admin'],true)&&$target['role']!=='superadmin')$role=(string)$target['role'];
    $password=(string)($body['password']??'');
    if($password!==''){
        $validation=$target['role']==='superadmin'?['valid'=>true,'errors'=>[],'warning'=>null]:$passwordPolicy->validate($password,$target['username']);
        if(!$validation['valid']){$_SESSION['notice']=implode(' ',$validation['errors']);return $response->withHeader('Location','/users')->withStatus(302);}
        $update=$pdo->prepare('UPDATE users SET role=?,password_hash=?,must_change_password=FALSE WHERE id=?');$update->execute([$role,password_hash($password,PASSWORD_DEFAULT),$id]);
        if($validation['warning'])$_SESSION['advisory']=$validation['warning'];
    }else{$update=$pdo->prepare('UPDATE users SET role=? WHERE id=?');$update->execute([$role,$id]);}
    return $response->withHeader('Location','/users')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->post('/users/{id}/must-change',function(Request $request,Response $response,array $args)use($pdo,$csrf):Response{
    $csrf($request);$statement=$pdo->prepare("UPDATE users SET must_change_password=TRUE WHERE id=? AND role<>'superadmin'");$statement->execute([(int)$args['id']]);
    return $response->withHeader('Location','/users')->withStatus(302);
})->add($requireAdmin)->add($guard);
$app->run();
