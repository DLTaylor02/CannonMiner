<?php
declare(strict_types=1);

use CannonMiner\Database;
use CannonMiner\LoginRateLimiter;
use CannonMiner\PasswordPolicy;
use CannonMiner\Router;
use CannonMiner\Settings;
use GuzzleHttp\Client;
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
session_start(['cookie_samesite'=>'Lax','cookie_secure'=>$secureRequest]);

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

$app->get('/',function(Request $request,Response $response)use($pdo,$settings,$render):Response{
    $summary=$pdo->query(<<<'SQL'
        SELECT count(*) FILTER (WHERE status='complete')::int AS completed,
          count(*) FILTER (WHERE created_at>now()-interval '24 hours')::int AS runs_24h,
          min((result->0->>'risk')::float) FILTER (WHERE status='complete' AND jsonb_array_length(result)>0) AS best_risk,
          avg((result->0->>'expected_seconds')::float) FILTER (WHERE status='complete' AND jsonb_array_length(result)>0) AS avg_seconds
        FROM analysis_jobs
    SQL)->fetch();
    $automated=$pdo->query(<<<'SQL'
        SELECT result->0->>'route' AS route,avg((result->0->>'risk')::float) AS avg_risk,
          avg((result->0->>'expected_seconds')::float) AS avg_seconds,count(*)::int AS runs,
          avg((result->0->>'risk')::float) FILTER (WHERE finished_at>now()-interval '12 hours') AS recent_risk,
          avg((result->0->>'risk')::float) FILTER (WHERE finished_at<=now()-interval '12 hours') AS previous_risk,
          (array_agg(id ORDER BY finished_at DESC))[1] AS latest_id
        FROM analysis_jobs WHERE job_type='automated' AND status='complete' AND finished_at>now()-interval '24 hours'
          AND jsonb_array_length(result)>0 GROUP BY result->0->>'route'
        ORDER BY avg_seconds,avg_risk
    SQL)->fetchAll();
    foreach($automated as &$route){
        $recent=$route['recent_risk'];$previous=$route['previous_risk'];$route['risk_change_percent']=null;
        if($recent!==null&&$previous!==null){
            $recent=(float)$recent;$previous=(float)$previous;
            if($previous>0)$route['risk_change_percent']=100*($recent-$previous)/$previous;
            elseif($recent===0.0)$route['risk_change_percent']=0.0;
        }
    }
    unset($route);
    $automationProfile=$settings->get('automation_profile','balanced');
    usort($automated,static fn(array $a,array $b):int=>$automationProfile==='reliability'
        ? [(float)$a['avg_risk'],(float)$a['avg_seconds']]<=>[(float)$b['avg_risk'],(float)$b['avg_seconds']]
        : [(float)$a['avg_seconds'],(float)$a['avg_risk']]<=>[(float)$b['avg_seconds'],(float)$b['avg_risk']]);
    $automated=array_slice($automated,0,5);
    $metrics=array_reverse($pdo->query("SELECT recorded_at,host_cpu_percent,app_cpu_percent,disk_total_bytes,disk_free_bytes,app_bytes FROM system_metrics WHERE cpu_metric_version=2 ORDER BY recorded_at DESC LIMIT 672")->fetchAll());
    $apiActual=$pdo->query(<<<'SQL'
        WITH tracking AS (
          SELECT min(requested_at) FILTER (WHERE service='directions') AS directions_started
          FROM google_api_requests
        ), usage AS (
          SELECT requested_at,0::int AS reconstructed
          FROM google_api_requests WHERE requested_at>=date_trunc('month',now())
          UNION ALL
          SELECT collected_at,1
          FROM measurements CROSS JOIN tracking
          WHERE collected_at>=date_trunc('month',now())
            AND collected_at<coalesce(directions_started,now())
        ), hourly AS (
          SELECT bucket,count(usage.requested_at)::bigint AS requests,
            coalesce(sum(usage.reconstructed),0)::bigint AS reconstructed_requests
          FROM generate_series(date_trunc('month',now()),date_trunc('hour',now()),interval '1 hour') AS bucket
          LEFT JOIN usage ON usage.requested_at>=bucket AND usage.requested_at<bucket+interval '1 hour'
          GROUP BY bucket
        )
        SELECT bucket,sum(requests) OVER (ORDER BY bucket)::bigint AS requests,
          sum(reconstructed_requests) OVER (ORDER BY bucket)::bigint AS reconstructed_requests
        FROM hourly ORDER BY bucket
    SQL)->fetchAll();
    foreach($apiActual as &$point)$point['bucket']=(new DateTimeImmutable((string)$point['bucket']))->format(DATE_ATOM);
    unset($point);
    $enabledSegments=(int)$pdo->query('SELECT count(*) FROM segments WHERE enabled')->fetchColumn();
    $collectionInterval=max(5,min(10080,(int)$settings->get('collection_interval_minutes','60')));
    $googleReady=$settings->get('google_data_storage_authorized','no')==='yes'&&trim($settings->get('google_maps_api_key',''))!=='';
    $directionsPerHour=$googleReady?$enabledSegments*60/$collectionInterval:0.0;
    $staticMapsPerHour=(float)$pdo->query("SELECT count(*)/24.0 FROM google_api_requests WHERE service='static_map' AND requested_at>=now()-interval '24 hours'")->fetchColumn();
    $forecastEnd=$pdo->query("SELECT date_trunc('month',now())+interval '1 month'")->fetchColumn();
    $apiUsage=['actual'=>$apiActual,'forecast_per_hour'=>round($directionsPerHour+$staticMapsPerHour,2),
        'forecast_end'=>(new DateTimeImmutable((string)$forecastEnd))->format(DATE_ATOM),
        'reconstructed_requests'=>(int)($apiActual[array_key_last($apiActual)]['reconstructed_requests']??0)];
    return $render($request,$response,'home.twig',['summary'=>$summary,'automated'=>$automated,'metrics'=>$metrics,'api_usage'=>$apiUsage,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->map(['GET','POST'], '/plan', function (Request $request, Response $response) use ($pdo,$router,$settings,$render,&$identity): Response {
    $nodes = $router->nodes(); $input = ['start'=>'redball','end'=>'portofino','speed'=>(float)$settings->get('default_speed_mph','110'),
        'profile'=>'balanced','risk'=>100*(float)$settings->get('default_max_delay_risk','.20')];
    $routes=$router->routeOptions();$results = []; $error = null;
    if ($request->getMethod() === 'POST') {
        $input = array_merge($input, (array)$request->getParsedBody());
        if($identity['role']==='user')$input['risk']=100*(float)$settings->get('default_max_delay_risk','.20');
        $token=(string)($input['_token']??'');if(!hash_equals($_SESSION['csrf']??'',$token))throw new RuntimeException('Your session expired.');
        $jobType=($input['_mode']??'best')==='custom'?'custom':'best';$segments=null;
        if($jobType==='custom'){$selected=$routes[(int)($input['route_index']??-1)]??null;if(!$selected){$error='Select an available custom route.';}else{$input['start']=$selected['start'];$input['end']=$selected['end'];$segments=$selected['segments'];}}
        if($error)return $render($request,$response,'dashboard.twig',['nodes'=>$nodes,'routes'=>$routes,'input'=>$input,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
        $id=bin2hex(random_bytes(16));
        $statement=$pdo->prepare("INSERT INTO analysis_jobs(id,user_id,status,input,job_type) VALUES (?,?,'queued',?::jsonb,?)");
        $payload=['start'=>$input['start'],'end'=>$input['end'],'speed'=>(float)$input['speed'],'profile'=>$input['profile'],'risk'=>max(0,min(1,(float)$input['risk']/100))];if($segments!==null)$payload['segments']=$segments;
        $statement->execute([$id,$_SESSION['user_id'],json_encode($payload,JSON_THROW_ON_ERROR),$jobType]);
        return $response->withHeader('Location','/analysis/'.$id)->withStatus(302);
    }
    return $render($request, $response, 'dashboard.twig', ['nodes'=>$nodes,'routes'=>$routes,'input'=>$input,'results'=>$results,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->get('/analysis/{id}',function(Request $request,Response $response,array $args)use($pdo,$render,&$identity):Response{
    $statement=$pdo->prepare('SELECT * FROM analysis_jobs WHERE id=?');$statement->execute([$args['id']]);$job=$statement->fetch();
    if(!$job)return $response->withStatus(404);
    return $render($request,$response,'analysis.twig',['job'=>$job,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/analysis/{id}/map/{rank}',function(Request $request,Response $response,array $args)use($pdo):Response{
    $statement=$pdo->prepare("SELECT result FROM analysis_jobs WHERE id=? AND status='complete'");$statement->execute([$args['id']]);
    $stored=$statement->fetchColumn();if($stored===false)return $response->withStatus(404);
    $results=json_decode((string)$stored,true);$rank=filter_var($args['rank'],FILTER_VALIDATE_INT);
    $url=$rank!==false&&isset($results[$rank]['map_url'])?(string)$results[$rank]['map_url']:'';$parts=parse_url($url);
    if(!$parts||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='maps.googleapis.com'||($parts['path']??'')!=='/maps/api/staticmap')return $response->withStatus(404);
    try{
        $pdo->exec("INSERT INTO google_api_requests(service) VALUES ('static_map')");
        $upstream=(new Client(['timeout'=>20,'connect_timeout'=>5,'http_errors'=>false]))->get($url);
        $response->getBody()->write((string)$upstream->getBody());
        return $response->withStatus($upstream->getStatusCode())->withHeader('Content-Type',$upstream->getHeaderLine('Content-Type')?:'image/png')->withHeader('Cache-Control','private, no-store');
    }catch(Throwable){return $response->withStatus(502)->withHeader('Cache-Control','private, no-store');}
})->add($guard);
$app->get('/analysis/{id}/status',function(Request $request,Response $response,array $args)use($pdo):Response{
    $statement=$pdo->prepare('SELECT status,progress_current,progress_total,stage,eta_seconds,updated_at,error,result FROM analysis_jobs WHERE id=?');
    $statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return $response->withStatus(404);
    $job['updated_at']=(new DateTimeImmutable((string)$job['updated_at']))->format(DATE_ATOM);
    if($job['result']!==null){
        $results=is_array($job['result'])?$job['result']:json_decode((string)$job['result'],true);
        if(is_array($results))foreach($results as &$result){$result['map_available']=!empty($result['map_url']);unset($result['map_url']);}unset($result);
        $job['result']=$results;
    }
    $response->getBody()->write(json_encode($job,JSON_THROW_ON_ERROR));return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/history',function(Request $request,Response $response)use($pdo,$render):Response{
    $query=$request->getQueryParams();$filter=(string)($query['type']??'all');if(!in_array($filter,['all','best','custom','automated'],true))$filter='all';
    $sort=(string)($query['sort']??'risk');if(!in_array($sort,['risk','expected','run','matches'],true))$sort='risk';
    $descendingDefault=in_array($sort,['run','matches'],true);
    $direction=(string)($query['dir']??($descendingDefault?'desc':'asc'));if(!in_array($direction,['asc','desc'],true))$direction=$descendingDefault?'desc':'asc';
    $users=$pdo->query('SELECT DISTINCT u.id,u.username FROM users u JOIN analysis_jobs j ON j.user_id=u.id ORDER BY u.username')->fetchAll();
    $userId=max(0,(int)($query['user']??0));$validUserIds=array_map(static fn(array $user):int=>(int)$user['id'],$users);if($userId&&!in_array($userId,$validUserIds,true))$userId=0;
    $conditions=[];$parameters=[];
    if($filter!=='all'){$conditions[]='j.job_type=?';$parameters[]=$filter;}
    if($userId){$conditions[]='j.user_id=?';$parameters[]=$userId;}
    $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
    $historyCte=<<<SQL
        WITH history_source AS (
          SELECT j.id,u.username,j.status,j.stage,j.created_at,j.job_type,j.input->>'profile' AS profile,
            j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0 AS comparable,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN round(((j.result->0->>'target_speed_mph')::numeric)*10)::int END AS target_speed_tenths,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN j.result->0->>'route' END AS route,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN date_trunc('minute',(j.result->0->>'departure')::timestamptz) END AS departure_minute,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN round(((j.result->0->>'risk')::numeric)*1000)::int END AS risk_tenths,
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN round(((j.result->0->>'expected_seconds')::numeric)/60)::int END AS expected_minutes
          FROM analysis_jobs j JOIN users u ON u.id=j.user_id{$where}
        ), grouped_history AS (
          SELECT (array_agg(id ORDER BY created_at DESC,id DESC))[1] AS id,
            (array_agg(username ORDER BY created_at DESC,id DESC))[1] AS username,
            (array_agg(status ORDER BY created_at DESC,id DESC))[1] AS status,
            (array_agg(stage ORDER BY created_at DESC,id DESC))[1] AS stage,
            (array_agg(job_type ORDER BY created_at DESC,id DESC))[1] AS job_type,
            max(created_at) AS created_at,profile,target_speed_tenths/10.0 AS target_speed_mph,route,
            departure_minute AS departure,
            risk_tenths/1000.0 AS risk,expected_minutes*60 AS expected_seconds,
            count(*)::int AS matches
          FROM history_source
          GROUP BY profile,target_speed_tenths,route,departure_minute,risk_tenths,expected_minutes,
            CASE WHEN comparable THEN NULL ELSE id END
        )
    SQL;
    $pageSize=50;
    $countStatement=$pdo->prepare($historyCte.' SELECT count(*) FROM grouped_history');
    $countStatement->execute($parameters);$totalRuns=(int)$countStatement->fetchColumn();
    $totalPages=max(1,(int)ceil($totalRuns/$pageSize));$page=max(1,min($totalPages,(int)($query['page']??1)));$offset=($page-1)*$pageSize;
    $sqlDirection=strtoupper($direction);
    $order=match($sort){'expected'=>"(status='complete') DESC,expected_seconds {$sqlDirection} NULLS LAST,risk ASC NULLS LAST,created_at DESC,id DESC",'run'=>"created_at {$sqlDirection},id {$sqlDirection}",'matches'=>"matches {$sqlDirection},created_at DESC,id DESC",'risk'=>"(status='complete') DESC,risk {$sqlDirection} NULLS LAST,expected_seconds ASC NULLS LAST,created_at DESC,id DESC"};
    $historySql=$historyCte;
    $historySql.=<<<SQL
        SELECT history.* FROM grouped_history history
        ORDER BY {$order}
        LIMIT {$pageSize} OFFSET {$offset}
    SQL;
    $statement=$pdo->prepare($historySql);$statement->execute($parameters);$runs=$statement->fetchAll();
    return $render($request,$response,'history.twig',['runs'=>$runs,'filter'=>$filter,'users'=>$users,'selected_user'=>$userId,'sort'=>$sort,'direction'=>$direction,'page'=>$page,'total_pages'=>$totalPages,'total_runs'=>$totalRuns,'csrf'=>$_SESSION['csrf']]);
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
        if($identity['role']==='superadmin')$allowed=array_merge($allowed,['google_maps_api_key','google_data_storage_authorized','collection_interval_minutes','timezone','default_speed_mph','candidate_routes','departure_interval_minutes','login_rate_limit','login_lockout_minutes','password_min_strength','password_min_length','automation_enabled','automation_interval_hours','automation_start_minute','automation_speed_mph','automation_profile','automation_max_risk','telemetry_interval_minutes']);
        $body=array_intersect_key($body,array_flip($allowed));
        $body['default_max_delay_risk']=(string)(max(0,min(100,(float)($body['default_max_delay_risk']??20)))/100);
        if($identity['role']==='superadmin'){
            $body['collection_interval_minutes']=(string)max(5,min(10080,(int)($body['collection_interval_minutes']??60)));
            $body['departure_interval_minutes']=(string)max(5,min(60,(int)($body['departure_interval_minutes']??15)));
            $body['login_rate_limit']=(string)max(1,min(100,(int)($body['login_rate_limit']??5)));
            $body['login_lockout_minutes']=(string)max(1,min(1440,(int)($body['login_lockout_minutes']??15)));
            $body['password_min_length']=(string)max(8,min(64,(int)($body['password_min_length']??12)));
            if(!in_array($body['password_min_strength']??'',['strong','very_strong'],true))$body['password_min_strength']='strong';
            $body['automation_enabled']=isset($body['automation_enabled'])?'yes':'no';
            $body['automation_interval_hours']=(string)max(1,min(168,(int)($body['automation_interval_hours']??1)));
            $body['automation_start_minute']=(string)max(0,min(59,(int)($body['automation_start_minute']??0)));
            $body['automation_speed_mph']=(string)max(1,min(250,(float)($body['automation_speed_mph']??110)));
            if(!in_array($body['automation_profile']??'',['balanced','fastest','reliability'],true))$body['automation_profile']='balanced';
            $body['automation_max_risk']=(string)(max(0,min(100,(float)($body['automation_max_risk']??20)))/100);
            $body['telemetry_interval_minutes']=(string)max(1,min(1440,(int)($body['telemetry_interval_minutes']??15)));
            $body['google_data_storage_authorized']=isset($body['google_data_storage_authorized'])?'yes':'no';
            $submittedKey=trim((string)($body['google_maps_api_key']??''));if($submittedKey===''||$submittedKey==='************')unset($body['google_maps_api_key']);
        }
        $settings->save($body);$message='Settings saved.';
    }
    $lastRun=$pdo->query(<<<'SQL'
        SELECT *,to_char(coalesce(finished_at,started_at) AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') AS event_at_iso
        FROM collection_runs ORDER BY started_at DESC LIMIT 1
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
