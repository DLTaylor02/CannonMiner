<?php
declare(strict_types=1);

use CannonMiner\Database;
use CannonMiner\LoginRateLimiter;
use CannonMiner\PasswordPolicy;
use CannonMiner\Planner;
use CannonMiner\Router;
use CannonMiner\RouteGraph;
use CannonMiner\Settings;
use CannonMiner\SimulationTrafficProvider;
use CannonMiner\Simulator;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__); $pdo = Database::connect($root); $settings = new Settings($pdo); $router = new Router($pdo, $settings);
$simulator=new Simulator($pdo,new RouteGraph($pdo),new SimulationTrafficProvider($pdo),$settings);
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
$app->get('/analysis-queue/status',function(Request $request,Response $response)use($pdo):Response{
    $status=$pdo->query(<<<'SQL'
        SELECT count(*) FILTER (WHERE job_type='automated' AND status='queued')::int AS automated_queued,
          count(*) FILTER (WHERE job_type='automated' AND status='running')::int AS automated_running
        FROM analysis_jobs
        WHERE status IN ('queued','running')
    SQL)->fetch();
    $response->getBody()->write(json_encode($status,JSON_THROW_ON_ERROR));
    return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/',function(Request $request,Response $response)use($pdo,$settings,$render):Response{
    $summary=$pdo->query(<<<'SQL'
        SELECT count(*) FILTER (WHERE status='complete')::int AS completed,
          count(*) FILTER (WHERE created_at>now()-interval '24 hours')::int AS runs_24h,
          min((result->0->>'risk')::float) FILTER (WHERE status='complete' AND jsonb_array_length(result)>0) AS best_risk
        FROM analysis_jobs WHERE calculation_method_version=3
    SQL)->fetch();
    $automated=$pdo->query(<<<'SQL'
        SELECT result->0->>'route' AS route,avg((result->0->>'risk')::float) AS avg_risk,
          avg((result->0->>'expected_seconds')::float) AS avg_seconds,count(*)::int AS runs,
          avg((result->0->>'risk')::float) FILTER (WHERE finished_at>now()-interval '12 hours') AS recent_risk,
          avg((result->0->>'risk')::float) FILTER (WHERE finished_at<=now()-interval '12 hours') AS previous_risk,
          (array_agg(id ORDER BY finished_at DESC))[1] AS latest_id
        FROM analysis_jobs WHERE calculation_method_version=3 AND job_type='automated' AND status='complete' AND finished_at>now()-interval '24 hours'
          AND jsonb_array_length(result)>0 GROUP BY result->0->>'route'
        ORDER BY avg_seconds,avg_risk
    SQL)->fetchAll();
    foreach($automated as &$route){
        $recent=$route['recent_risk'];$previous=$route['previous_risk'];$route['risk_change_points']=null;
        if($recent!==null&&$previous!==null){
            $recent=(float)$recent;$previous=(float)$previous;
            $route['risk_change_points']=100*($recent-$previous);
        }
    }
    unset($route);
    $automationProfile=$settings->get('automation_profile','balanced');
    usort($automated,static fn(array $a,array $b):int=>$automationProfile==='reliability'
        ? [(float)$a['avg_risk'],(float)$a['avg_seconds']]<=>[(float)$b['avg_risk'],(float)$b['avg_seconds']]
        : [(float)$a['avg_seconds'],(float)$a['avg_risk']]<=>[(float)$b['avg_seconds'],(float)$b['avg_risk']]);
    $automated=array_slice($automated,0,5);
    $metrics=array_reverse($pdo->query("SELECT recorded_at,host_cpu_percent,app_cpu_percent FROM system_metrics WHERE cpu_metric_version=2 ORDER BY recorded_at DESC LIMIT 672")->fetchAll());
    $storageMetrics=array_reverse($pdo->query("SELECT recorded_at,disk_total_bytes,disk_free_bytes,app_bytes FROM storage_metrics ORDER BY recorded_at DESC LIMIT 540")->fetchAll());
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
    $dashboardBanner=trim((string)$settings->get('dashboard_banner',''));
    $lastCollection=$pdo->query(<<<'SQL'
        SELECT status,segments_collected,message,to_char(coalesce(finished_at,started_at) AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS"Z"') AS event_at_iso
        FROM collection_runs ORDER BY started_at DESC LIMIT 1
    SQL)->fetch();
    return $render($request,$response,'home.twig',['summary'=>$summary,'automated'=>$automated,'metrics'=>$metrics,'storage_metrics'=>$storageMetrics,'api_usage'=>$apiUsage,'dashboard_banner'=>$dashboardBanner,'last_collection'=>$lastCollection,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$loadHistory=null;
$app->map(['GET','POST'], '/analyze-traffic', function (Request $request, Response $response) use ($pdo,$router,$settings,$render,&$identity,&$loadHistory): Response {
    $nodes = $router->nodes(); $input = ['start'=>'redball','end'=>'portofino','speed'=>(float)$settings->get('default_speed_mph','110'),
        'profile'=>'balanced','risk'=>100*(float)$settings->get('default_max_delay_risk','.20')];
    $routes=$router->routeOptions();$results = []; $error = null;
    if ($request->getMethod() === 'POST') {
        $input = array_merge($input, (array)$request->getParsedBody());
        if($identity['role']==='user')$input['risk']=100*(float)$settings->get('default_max_delay_risk','.20');
        $token=(string)($input['_token']??'');if(!hash_equals($_SESSION['csrf']??'',$token))throw new RuntimeException('Your session expired.');
        $jobType=($input['_mode']??'best')==='custom'?'custom':'best';$segments=null;
        if($jobType==='custom'){$selected=$routes[(int)($input['route_index']??-1)]??null;if(!$selected){$error='Select an available custom route.';}else{$input['start']=$selected['start'];$input['end']=$selected['end'];$segments=$selected['segments'];}}
        if($error)return $render($request,$response,'dashboard.twig',array_merge(['nodes'=>$nodes,'routes'=>$routes,'input'=>$input,'error'=>$error,'csrf'=>$_SESSION['csrf']],$loadHistory($request)));
        $id=bin2hex(random_bytes(16));
        $statement=$pdo->prepare("INSERT INTO analysis_jobs(id,user_id,status,input,job_type,calculation_method_version) VALUES (?,?,'queued',?::jsonb,?,3)");
        $payload=['start'=>$input['start'],'end'=>$input['end'],'speed'=>(float)$input['speed'],'profile'=>$input['profile'],'risk'=>max(0,min(1,(float)$input['risk']/100))];if($segments!==null)$payload['segments']=$segments;
        $statement->execute([$id,$_SESSION['user_id'],json_encode($payload,JSON_THROW_ON_ERROR),$jobType]);
        return $response->withHeader('Location','/analysis/'.$id)->withStatus(302);
    }
    return $render($request, $response, 'dashboard.twig', array_merge(['nodes'=>$nodes,'routes'=>$routes,'input'=>$input,'results'=>$results,'error'=>$error,'csrf'=>$_SESSION['csrf']],$loadHistory($request)));
})->add($guard);

$loadWindows=null;
$app->map(['GET','POST'],'/plan',function(Request $request,Response $response)use($pdo,$settings,$render,$csrf,&$identity,&$loadWindows):Response{
    $speedStatement=$pdo->query(<<<'SQL'
        SELECT DISTINCT round(((item.value->>'target_speed_mph')::numeric)*10)::int/10.0 AS speed
        FROM analysis_jobs j
        CROSS JOIN LATERAL jsonb_array_elements(
          CASE WHEN jsonb_typeof(j.result)='array' THEN j.result ELSE '[]'::jsonb END
        ) AS item(value)
        WHERE j.status='complete' AND j.calculation_method_version=3
          AND item.value->>'target_speed_mph' IS NOT NULL
        ORDER BY speed
    SQL);
    $speeds=array_map('floatval',array_column($speedStatement->fetchAll(),'speed'));
    $today=new DateTimeImmutable('today',new DateTimeZone('America/New_York'));$nextYear=(int)$today->format('Y')+1;
    $defaultSpeed=(float)$settings->get('default_speed_mph','110');
    if($speeds&&!in_array($defaultSpeed,$speeds,true))$defaultSpeed=$speeds[0];
    $input=['start_date'=>$nextYear.'-01-01','end_date'=>$nextYear.'-12-31',
        'speed'=>$defaultSpeed,'profile'=>'balanced','risk'=>100*(float)$settings->get('default_max_delay_risk','.20')];$error=null;
    if($request->getMethod()==='POST'){
        $csrf($request);$input=array_merge($input,(array)$request->getParsedBody());if($identity['role']==='user')$input['risk']=100*(float)$settings->get('default_max_delay_risk','.20');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$input['start_date'],new DateTimeZone('America/New_York'));
        $end=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$input['end_date'],new DateTimeZone('America/New_York'));
        if(!$start||!$end||$start->format('Y-m-d')!==(string)$input['start_date']||$end->format('Y-m-d')!==(string)$input['end_date']||$start<$today||$end<$start||$end->diff($start)->days>366)$error='Choose a future planning range of no more than one year.';
        elseif(!in_array($input['profile'],['balanced','fastest','reliability'],true))$error='Select a valid strategy.';
        elseif(!in_array((float)$input['speed'],$speeds,true))$error='Select a target speed supported by current historical calculations.';
        else{
            $payload=['start_date'=>$start->format('Y-m-d'),'end_date'=>$end->format('Y-m-d'),
                'speed'=>max(1,min(250,(float)$input['speed'])),'profile'=>$input['profile'],
                'risk'=>max(0,min(1,(float)$input['risk']/100))];
            $id=bin2hex(random_bytes(16));$pdo->prepare("INSERT INTO planning_jobs(id,user_id,status,input,planning_method_version) VALUES (?,?,'queued',?::jsonb,?)")->execute([$id,$identity['id'],json_encode($payload,JSON_THROW_ON_ERROR),Planner::METHOD_VERSION]);return$response->withHeader('Location','/plan/'.$id)->withStatus(302);
        }
    }
    return$render($request,$response,'plan.twig',array_merge(['input'=>$input,'planning_speeds'=>$speeds,'error'=>$error,'csrf'=>$_SESSION['csrf']],$loadWindows($request)));
})->add($guard);
$app->get('/plan/{id}',function(Request $request,Response $response,array $args)use($pdo,$render):Response{
    $statement=$pdo->prepare('SELECT * FROM planning_jobs WHERE id=?');$statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return$response->withStatus(404);
    return$render($request,$response,'planning.twig',['job'=>$job,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/plan/{id}/status',function(Request $request,Response $response,array $args)use($pdo):Response{
    $statement=$pdo->prepare(<<<SQL
        SELECT j.status,j.progress_current,j.progress_total,j.stage,j.updated_at,j.error,j.result,
          CASE WHEN j.status='queued' THEN (SELECT count(*) FROM planning_jobs q WHERE q.status='queued' AND (q.created_at,q.id)<=(j.created_at,j.id))::int END AS queue_position,
          CASE WHEN j.status='queued' THEN (SELECT count(*) FROM planning_jobs q WHERE q.status='queued')::int END AS queue_total
        FROM planning_jobs j WHERE j.id=?
    SQL);
    $statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return$response->withStatus(404);$job['updated_at']=(new DateTimeImmutable($job['updated_at']))->format(DATE_ATOM);
    if($job['result']!==null){
        $results=is_array($job['result'])?$job['result']:json_decode((string)$job['result'],true);
        $sourceStatement=$pdo->prepare("SELECT result FROM analysis_jobs WHERE id=? AND status='complete'");
        if(is_array($results))foreach($results as &$item){
            if(!empty($item['segment_risks'])||empty($item['source_id'])||!isset($item['source_index'],$item['departure']))continue;
            $sourceStatement->execute([$item['source_id']]);$source=json_decode((string)$sourceStatement->fetchColumn(),true);$sourceResult=$source[(int)$item['source_index']]??null;
            if(!is_array($sourceResult))continue;$departure=new DateTimeImmutable((string)$item['departure']);$item['segment_risks']=(array)($sourceResult['segment_risks']??[]);
            foreach($item['segment_risks'] as &$segment){$offset=max(0,(int)($segment['start_offset_seconds']??0));$segment['start_time']=$departure->modify('+'.$offset.' seconds')->format(DATE_ATOM);}unset($segment);
        }unset($item);$job['result']=$results;
    }
    $response->getBody()->write(json_encode($job,JSON_THROW_ON_ERROR));return$response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$loadWindows=function(Request $request)use($pdo):array{
    $query=$request->getQueryParams();$users=$pdo->prepare('SELECT DISTINCT u.id,u.username FROM users u JOIN planning_jobs j ON j.user_id=u.id WHERE j.planning_method_version=? ORDER BY u.username');$users->execute([Planner::METHOD_VERSION]);$users=$users->fetchAll();
    $userId=max(0,(int)($query['user']??0));if($userId&&!in_array($userId,array_map(static fn(array $user):int=>(int)$user['id'],$users),true))$userId=0;
    $speedStatement=$pdo->prepare("SELECT DISTINCT round((input->>'speed')::numeric,1)::float AS speed FROM planning_jobs WHERE planning_method_version=? ORDER BY speed");$speedStatement->execute([Planner::METHOD_VERSION]);$speeds=array_map('floatval',array_column($speedStatement->fetchAll(),'speed'));
    $selectedSpeed=(string)($query['speed']??'all');if($selectedSpeed!=='all'){$requested=round((float)$selectedSpeed,1);$match=null;foreach($speeds as $speed)if(abs($speed-$requested)<.05){$match=$speed;break;}$selectedSpeed=$match===null?'all':number_format($match,1,'.','');}
    $from=(string)($query['from']??'');$to=(string)($query['to']??'');$validDate=static fn(string $date):bool=>$date===''||(($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date))&&$parsed->format('Y-m-d')===$date);if(!$validDate($from))$from='';if(!$validDate($to))$to='';
    $conditions=['j.planning_method_version=?'];$parameters=[Planner::METHOD_VERSION];
    if($userId){$conditions[]='j.user_id=?';$parameters[]=$userId;}if($selectedSpeed!=='all'){$conditions[]="round((j.input->>'speed')::numeric,1)=?";$parameters[]=(float)$selectedSpeed;}
    if($from!==''){$conditions[]="((j.result->0->>'departure')::timestamptz AT TIME ZONE 'America/New_York')::date>=?::date";$parameters[]=$from;}
    if($to!==''){$conditions[]="((j.result->0->>'departure')::timestamptz AT TIME ZONE 'America/New_York')::date<=?::date";$parameters[]=$to;}
    $where=' WHERE '.implode(' AND ',$conditions);$perPage=20;$totalStatement=$pdo->prepare('SELECT count(*) FROM planning_jobs j'.$where);
    $totalStatement->execute($parameters);$total=(int)$totalStatement->fetchColumn();
    $totalPages=max(1,(int)ceil($total/$perPage));$page=max(1,min($totalPages,(int)($request->getQueryParams()['page']??1)));$offset=($page-1)*$perPage;
    $statement=$pdo->prepare(<<<SQL
        SELECT j.id,j.user_id,u.username,j.status,j.stage,j.created_at,
          j.input->>'start_date' AS start_date,j.input->>'end_date' AS end_date,
          j.input->>'profile' AS profile,(j.input->>'speed')::numeric AS target_speed_mph,
          CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0 THEN j.result->0->>'route' END AS route,
          CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0 THEN (j.result->0->>'departure')::timestamptz END AS departure,
          CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0 THEN (j.result->0->>'risk')::numeric END AS risk,
          CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0 THEN (j.result->0->>'confidence')::numeric END AS confidence
        FROM planning_jobs j JOIN users u ON u.id=j.user_id
        {$where} ORDER BY j.created_at DESC,j.id DESC LIMIT ? OFFSET ?
    SQL);
    $statement->execute([...$parameters,$perPage,$offset]);
    return['windows'=>$statement->fetchAll(),'users'=>$users,'selected_user'=>$userId,'speeds'=>$speeds,'selected_speed'=>$selectedSpeed,'from'=>$from,'to'=>$to,'page'=>$page,'total_pages'=>$totalPages,'total'=>$total];
};
$app->post('/run-windows/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$id=(string)$args['id'];
    if(preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',$id)){
        if(in_array($identity['role'],['admin','superadmin'],true)){$statement=$pdo->prepare('DELETE FROM planning_jobs WHERE id=?');$statement->execute([$id]);}
        else{$statement=$pdo->prepare('DELETE FROM planning_jobs WHERE id=? AND user_id=?');$statement->execute([$id,$identity['id']]);if($statement->rowCount()===0)return$response->withStatus(403);}
    }
    return$response->withHeader('Location','/plan')->withStatus(302);
})->add($guard);

$app->get('/simulator',function(Request $request,Response $response)use($pdo,$render,&$identity):Response{
    $admin=in_array($identity['role'],['admin','superadmin'],true);$sql="SELECT s.id,s.user_id,u.username,s.status,s.mode,s.departure_at,s.simulated_at,s.state,s.created_at FROM simulations s JOIN users u ON u.id=s.user_id".($admin?'':' WHERE s.user_id=?')." ORDER BY CASE WHEN s.status IN ('completed','failed') THEN 1 ELSE 0 END,s.updated_at DESC LIMIT 100";
    $statement=$pdo->prepare($sql);$statement->execute($admin?[]:[$identity['id']]);$simulations=$statement->fetchAll();foreach($simulations as &$simulation){$simulation['state']=json_decode((string)$simulation['state'],true);$simulation['route_label']=Simulator::routeLabel($simulation['state']['route']??[]);$simulation['successful']=$simulation['status']==='completed'&&($simulation['state']['node']??null)==='portofino';if($simulation['successful']&&isset($simulation['state']['finished_elapsed_seconds'])){$elapsed=max(0,(int)$simulation['state']['finished_elapsed_seconds']);$simulation['elapsed_label']=sprintf('%dh %02dm %02ds',intdiv($elapsed,3600),intdiv($elapsed%3600,60),$elapsed%60);}}unset($simulation);
    return$render($request,$response,'simulator.twig',['simulations'=>$simulations,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->post('/simulator/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);$id=(string)$args['id'];if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',$id))return$response->withStatus(404);
    if(in_array($identity['role'],['admin','superadmin'],true)){$statement=$pdo->prepare('DELETE FROM simulations WHERE id=?');$statement->execute([$id]);}
    else{$statement=$pdo->prepare('DELETE FROM simulations WHERE id=? AND user_id=?');$statement->execute([$id,$identity['id']]);if($statement->rowCount()===0)return$response->withStatus(403);}
    return$response->withHeader('Location','/simulator')->withStatus(302);
})->add($guard);

$app->map(['GET','POST'],'/simulator/new',function(Request $request,Response $response)use($simulator,$render,$csrf,&$identity):Response{
    if($request->getMethod()==='GET'&&(!isset($_SESSION['simulator_roster'])||isset($request->getQueryParams()['regenerate'])))$_SESSION['simulator_roster']=Simulator::roster($identity['username']);
    $roster=(array)($_SESSION['simulator_roster']??Simulator::roster($identity['username']));$error=null;
    if($request->getMethod()==='POST')try{$csrf($request);$id=$simulator->create((int)$identity['id'],(array)$request->getParsedBody(),$roster);unset($_SESSION['simulator_roster']);return$response->withHeader('Location','/simulator/'.$id)->withStatus(302);}catch(Throwable $exception){$error=$exception->getMessage();}
    $defaultDeparture=(new DateTimeImmutable('tomorrow 06:00',new DateTimeZone('America/New_York')))->format('Y-m-d\TH:i');
    return$render($request,$response,'simulator-new.twig',['roster'=>$roster,'default_departure'=>$defaultDeparture,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->get('/simulator/{id}',function(Request $request,Response $response,array $args)use($pdo,$render,&$identity):Response{
    if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',(string)$args['id']))return$response->withStatus(404);
    $statement=$pdo->prepare('SELECT id,status,mode,created_at FROM simulations WHERE id=? AND user_id=?');$statement->execute([$args['id'],$identity['id']]);$simulation=$statement->fetch();if(!$simulation)return$response->withStatus(404);
    return$render($request,$response,'simulation.twig',['simulation'=>$simulation,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->get('/simulator/{id}/status',function(Request $request,Response $response,array $args)use($pdo,&$identity):Response{
    if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',(string)$args['id']))return$response->withStatus(404);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$after=max(0,(int)($request->getQueryParams()['after']??0));
    $statement=$pdo->prepare('SELECT id,status,mode,departure_at,simulated_at,state,version,finished_at FROM simulations WHERE id=? AND user_id=?');$statement->execute([$args['id'],$identity['id']]);$simulation=$statement->fetch();if(!$simulation)return$response->withStatus(404);
    $simulation['state']=json_decode((string)$simulation['state'],true);$events=$pdo->prepare('SELECT id,simulated_at,type,payload FROM simulation_events WHERE simulation_id=? AND id>? ORDER BY id LIMIT 250');$events->execute([$args['id'],$after]);$simulation['events']=$events->fetchAll();foreach($simulation['events'] as &$event)$event['payload']=json_decode((string)$event['payload'],true);unset($event);
    $response->getBody()->write(json_encode($simulation,JSON_THROW_ON_ERROR));return$response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->post('/simulator/{id}/action',function(Request $request,Response $response,array $args)use($simulator,$csrf,&$identity):Response{
    if(!preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',(string)$args['id']))return$response->withStatus(404);
    try{$csrf($request);$body=(array)$request->getParsedBody();$simulator->act((string)$args['id'],(int)$identity['id'],(string)($body['action']??''),$body);$payload=['ok'=>true];}
    catch(Throwable $exception){$payload=['ok'=>false,'error'=>$exception->getMessage()];$response=$response->withStatus(422);}
    $response->getBody()->write(json_encode($payload,JSON_THROW_ON_ERROR));return$response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/tools',function(Request $request,Response $response)use($router,$settings,$render):Response{
    $routes=$router->calculatorRoutes();$query=$request->getQueryParams();$selected=(string)($query['route']??'');
    if(!in_array($selected,array_column($routes,'label'),true))$selected=(string)($routes[0]['label']??'');
    $speed=max(1,min(500,(float)($query['speed']??$settings->get('default_speed_mph','110'))));
    $fuelRate=max(.1,min(100,(float)$settings->get('cruising_fuel_rate_gpm','10')));
    return $render($request,$response,'tools.twig',['routes'=>$routes,'selected_route'=>$selected,'target_speed'=>$speed,'fuel_rate_gpm'=>$fuelRate,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/tools/heatmap',function(Request $request,Response $response)use($router,$settings):Response{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $routes=$router->calculatorRoutes();$query=$request->getQueryParams();$label=(string)($query['route']??'');
    $selected=null;foreach($routes as $route)if(hash_equals($route['label'],$label)){$selected=$route;break;}
    if(!$selected){$response->getBody()->write(json_encode(['error'=>'Select an available route.'],JSON_THROW_ON_ERROR));return$response->withStatus(400)->withHeader('Content-Type','application/json');}
    $speed=max(1,min(500,(float)($query['speed']??$settings->get('default_speed_mph','110'))));
    try{$payload=['cells'=>$router->routeHeatmap($selected['segments'],$speed)];}
    catch(Throwable $error){$payload=['error'=>$error->getMessage()];$response=$response->withStatus(422);}
    $response->getBody()->write(json_encode($payload,JSON_THROW_ON_ERROR));
    return$response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/analysis/{id}',function(Request $request,Response $response,array $args)use($pdo,$render,&$identity):Response{
    $statement=$pdo->prepare('SELECT * FROM analysis_jobs WHERE id=?');$statement->execute([$args['id']]);$job=$statement->fetch();
    if(!$job)return $response->withStatus(404);
    return $render($request,$response,'analysis.twig',['job'=>$job,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->get('/analysis/{id}/map/{rank}',function(Request $request,Response $response,array $args)use($pdo):Response{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
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
    $statement=$pdo->prepare(<<<'SQL'
        SELECT j.status,j.progress_current,j.progress_total,j.stage,j.eta_seconds,j.updated_at,j.error,j.result,
          CASE WHEN j.status='queued' THEN (
            SELECT count(*) FROM analysis_jobs q
            WHERE q.status='queued' AND (q.created_at,q.id)<=(j.created_at,j.id)
          )::int END AS queue_position,
          CASE WHEN j.status='queued' THEN (
            SELECT count(*) FROM analysis_jobs q WHERE q.status='queued'
          )::int END AS queue_total
        FROM analysis_jobs j WHERE j.id=?
    SQL);
    $statement->execute([$args['id']]);$job=$statement->fetch();if(!$job)return $response->withStatus(404);
    $job['updated_at']=(new DateTimeImmutable((string)$job['updated_at']))->format(DATE_ATOM);
    if($job['result']!==null){
        $results=is_array($job['result'])?$job['result']:json_decode((string)$job['result'],true);
        if(is_array($results))foreach($results as &$result){$result['map_available']=!empty($result['map_url']);unset($result['map_url']);}unset($result);
        $job['result']=$results;
    }
    $response->getBody()->write(json_encode($job,JSON_THROW_ON_ERROR));return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','private, no-store');
})->add($guard);

$app->get('/calendar',function(Request $request,Response $response)use($pdo,$settings,$render):Response{
    $speeds=array_map('floatval',array_column($pdo->query(<<<'SQL'
        SELECT DISTINCT round((result->0->>'target_speed_mph')::numeric,1)::float AS speed
        FROM analysis_jobs
        WHERE calculation_method_version=3 AND status='complete' AND jsonb_typeof(result)='array' AND jsonb_array_length(result)>0
          AND result->0->>'target_speed_mph' IS NOT NULL
        ORDER BY speed
    SQL)->fetchAll(),'speed'));
    if($speeds===[])$speeds=[round((float)$settings->get('automation_speed_mph','110'),1)];
    $requestedSpeed=round((float)($request->getQueryParams()['speed']??$settings->get('automation_speed_mph','110')),1);
    $selectedSpeed=$speeds[0];foreach($speeds as $speed)if(abs($speed-$requestedSpeed)<.05){$selectedSpeed=$speed;break;}
    $timezone=new DateTimeZone('America/New_York');$currentYear=(int)(new DateTimeImmutable('now',$timezone))->format('Y');
    $yearStatement=$pdo->prepare(<<<'SQL'
        SELECT DISTINCT year FROM (
          SELECT extract(year FROM ((result->0->>'departure')::timestamptz AT TIME ZONE 'America/New_York'))::int AS year
          FROM analysis_jobs WHERE calculation_method_version=3 AND status='complete' AND jsonb_typeof(result)='array' AND jsonb_array_length(result)>0 AND result->0->>'departure' IS NOT NULL
          UNION ALL
          SELECT extract(year FROM ((item->>'departure')::timestamptz AT TIME ZONE 'America/New_York'))::int AS year
          FROM planning_jobs CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(result)='array' THEN result ELSE '[]'::jsonb END) AS item
          WHERE planning_method_version=? AND status='complete' AND item->>'departure' IS NOT NULL
        ) available_years ORDER BY year DESC
    SQL);$yearStatement->execute([Planner::METHOD_VERSION]);$years=array_map('intval',array_column($yearStatement->fetchAll(),'year'));
    if(!in_array($currentYear,$years,true))$years[]=$currentYear;rsort($years);
    $requestedYear=(int)($request->getQueryParams()['year']??$currentYear);$selectedYear=in_array($requestedYear,$years,true)?$requestedYear:$currentYear;
    $countsStatement=$pdo->prepare(<<<'SQL'
        WITH latest_equivalent AS (
          SELECT DISTINCT ON (job_type,input) id,finished_at,result
          FROM analysis_jobs
          WHERE calculation_method_version=3 AND status='complete'
            AND jsonb_typeof(result)='array' AND jsonb_array_length(result)>0
          ORDER BY job_type,input,finished_at DESC,id DESC
        ), recommendations AS (
          SELECT id,finished_at,item
          FROM latest_equivalent CROSS JOIN LATERAL jsonb_array_elements(result) AS item
        )
        SELECT to_char(((item->>'departure')::timestamptz AT TIME ZONE 'America/New_York')::date,'YYYY-MM-DD') AS day,
          to_char((item->>'departure')::timestamptz AT TIME ZONE 'America/New_York','HH24:MI') AS departure_time,
          count(*)::int AS recommendations,
          (array_agg(id ORDER BY finished_at DESC,id DESC))[1] AS latest_id,
          sum(coalesce((item->>'confidence')::numeric,0)*(1-(item->>'risk')::numeric))::float AS points
        FROM recommendations
        WHERE item->>'departure' IS NOT NULL AND item->>'target_speed_mph' IS NOT NULL
          AND extract(year FROM ((item->>'departure')::timestamptz AT TIME ZONE 'America/New_York'))::int=?
          AND round((item->>'target_speed_mph')::numeric,1)=CAST(? AS numeric)
        GROUP BY day,departure_time ORDER BY day,departure_time
    SQL);
    $countsStatement->execute([$selectedYear,$selectedSpeed]);$counts=[];$recommendationCounts=[];$departures=[];
    foreach($countsStatement->fetchAll() as $row){
        $points=(float)$row['points'];$recommendations=(int)$row['recommendations'];$counts[$row['day']]=($counts[$row['day']]??0)+$points;
        $recommendationCounts[$row['day']]=($recommendationCounts[$row['day']]??0)+$recommendations;
        $time=DateTimeImmutable::createFromFormat('!H:i',(string)$row['departure_time'],$timezone);
        $departures[$row['day']][]=['time'=>$row['departure_time'],'label'=>$time?$time->format('g:i A'):$row['departure_time'],'count'=>$recommendations,'points'=>$points,'run_id'=>$row['latest_id']];
    }
    $projectionStatement=$pdo->prepare(<<<'SQL'
        SELECT j.id,item.value->>'route' AS route,item.value->>'departure' AS departure,
          (item.value->>'risk')::float AS risk,(item.value->>'confidence')::float AS confidence,
          (item.value->>'expected_seconds')::float AS expected_seconds,item.value->>'designation' AS designation
        FROM planning_jobs j
        CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(j.result)='array' THEN j.result ELSE '[]'::jsonb END) WITH ORDINALITY AS item(value,position)
        WHERE j.planning_method_version=? AND j.status='complete'
          AND extract(year FROM ((item.value->>'departure')::timestamptz AT TIME ZONE 'America/New_York'))::int=?
          AND round((item.value->>'target_speed_mph')::numeric,1)=CAST(? AS numeric)
        ORDER BY (item.value->>'departure')::timestamptz,j.created_at DESC,item.position
    SQL);
    $projectionStatement->execute([Planner::METHOD_VERSION,$selectedYear,$selectedSpeed]);$projections=[];
    foreach($projectionStatement->fetchAll() as $row){$departure=(new DateTimeImmutable((string)$row['departure']))->setTimezone($timezone);$day=$departure->format('Y-m-d');
        $projections[$day][]=['id'=>$row['id'],'route'=>$row['route'],'departure'=>$row['departure'],'departure_label'=>$departure->format('g:i A T'),
            'risk'=>(float)$row['risk'],'confidence'=>(float)$row['confidence'],'expected_seconds'=>(float)$row['expected_seconds'],'designation'=>$row['designation']];
    }
    $trendRows=$pdo->query(<<<'SQL'
        WITH observed AS (
          SELECT extract(month FROM m.collected_at AT TIME ZONE s.timezone)::int AS month,
            extract(isodow FROM m.collected_at AT TIME ZONE s.timezone)::int AS weekday,
            extract(hour FROM m.collected_at AT TIME ZONE s.timezone)::int AS hour,
            s.name,s.timezone,count(*)::int AS observations,
            avg(greatest(0,m.duration_in_traffic_seconds-m.duration_seconds))::float AS avg_delay,
            (percentile_cont(.9) WITHIN GROUP (ORDER BY greatest(0,m.duration_in_traffic_seconds-m.duration_seconds)))::float AS p90_delay,
            (avg(greatest(0,m.duration_in_traffic_seconds-m.duration_seconds))/nullif(avg(m.duration_seconds),0))::float AS severity
          FROM measurements m JOIN segments s ON s.id=m.segment_id
          WHERE s.enabled AND m.duration_in_traffic_seconds IS NOT NULL AND m.duration_seconds>0
          GROUP BY month,weekday,hour,s.name,s.timezone
          HAVING count(*)>=2
            AND avg(greatest(0,m.duration_in_traffic_seconds-m.duration_seconds))>=greatest(120.0,avg(m.duration_seconds)*.05)
        ), ranked AS (
          SELECT observed.*,row_number() OVER (PARTITION BY month,weekday ORDER BY severity DESC,avg_delay DESC) AS rank
          FROM observed
        )
        SELECT * FROM ranked WHERE rank<=3 ORDER BY severity DESC
    SQL)->fetchAll();
    $trends=[];$maximumTrend=0.0;
    foreach($trendRows as $row){
        $key=$row['month'].'-'.$row['weekday'];$severity=(float)$row['severity'];$maximumTrend=max($maximumTrend,$severity);
        $zone=new DateTimeZone((string)$row['timezone']);$hour=(int)$row['hour'];
        $local=(new DateTimeImmutable(sprintf('%04d-%02d-01 %02d:00',$selectedYear,(int)$row['month'],$hour),$zone));
        $trends[$key][]=['name'=>$row['name'],'time'=>$local->format('g A T'),'avg_delay'=>(float)$row['avg_delay'],
            'p90_delay'=>(float)$row['p90_delay'],'observations'=>(int)$row['observations'],'severity'=>$severity];
    }
    $maximum=$counts?max($counts):0;$months=[];$holidays=\CannonMiner\UsBankHolidays::forYear($selectedYear,$timezone);
    for($month=1;$month<=12;$month++){
        $start=new DateTimeImmutable(sprintf('%04d-%02d-01',$selectedYear,$month),$timezone);$days=[];
        for($day=1,$limit=(int)$start->format('t');$day<=$limit;$day++){
            $date=sprintf('%04d-%02d-%02d',$selectedYear,$month,$day);$count=$counts[$date]??0;
            $calendarDate=$start->setDate($selectedYear,$month,$day);$pattern=$month.'-'.$calendarDate->format('N');
            $dayTrends=$trends[$pattern]??[];$green=null;$trendColor=null;$dark=false;
            if($count>0){$intensity=$maximum>0?$count/$maximum:0;$from=[222,241,230];$to=[23,107,77];$rgb=[];foreach($from as $index=>$value)$rgb[]=(int)round($value+($to[$index]-$value)*$intensity);$green='rgb('.implode(',',$rgb).')';$dark=$intensity>=.55;}
            if($dayTrends){$intensity=$maximumTrend>0?max(array_column($dayTrends,'severity'))/$maximumTrend:0;$from=[255,194,188];$to=[181,59,50];$rgb=[];foreach($from as $index=>$value)$rgb[]=(int)round($value+($to[$index]-$value)*$intensity);$trendColor='rgb('.implode(',',$rgb).')';}
            $days[]=['number'=>$day,'date'=>$date,'count'=>$count,'recommendations'=>$recommendationCounts[$date]??0,'color'=>$green,'trend_color'=>$trendColor,'dark'=>$dark,'departures'=>$departures[$date]??[],'projections'=>$projections[$date]??[],'trends'=>$dayTrends,'holidays'=>$holidays[$date]??[]];
        }
        $months[]=['name'=>$start->format('F'),'offset'=>(int)$start->format('N')-1,'days'=>$days];
    }
    return $render($request,$response,'calendar.twig',['months'=>$months,'speeds'=>$speeds,'years'=>$years,'selected_speed'=>$selectedSpeed,'selected_year'=>$selectedYear,'maximum'=>$maximum,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$loadHistory=function(Request $request)use($pdo):array{
    $query=$request->getQueryParams();$filter=(string)($query['type']??'all');if(!in_array($filter,['all','best','custom','automated'],true))$filter='all';
    $sort=(string)($query['sort']??'risk');if(!in_array($sort,['risk','expected','run','matches'],true))$sort='risk';
    $descendingDefault=in_array($sort,['run','matches'],true);
    $direction=(string)($query['dir']??($descendingDefault?'desc':'asc'));if(!in_array($direction,['asc','desc'],true))$direction=$descendingDefault?'desc':'asc';
    $grouped=(string)($query['grouped']??'1')!=='0';
    $pageSizes=['20','40','60','80','all'];
    if(array_key_exists('per_page',$query)){
        $perPage=(string)$query['per_page'];if(!in_array($perPage,$pageSizes,true))$perPage='20';
        $_SESSION['history_per_page']=$perPage;
    }else{
        $perPage=(string)($_SESSION['history_per_page']??'20');if(!in_array($perPage,$pageSizes,true))$perPage='20';
    }
    $users=$pdo->query('SELECT DISTINCT u.id,u.username FROM users u JOIN analysis_jobs j ON j.user_id=u.id WHERE j.calculation_method_version=3 ORDER BY u.username')->fetchAll();
    $userId=max(0,(int)($query['user']??0));$validUserIds=array_map(static fn(array $user):int=>(int)$user['id'],$users);if($userId&&!in_array($userId,$validUserIds,true))$userId=0;
    $speeds=array_map('floatval',array_column($pdo->query(<<<'SQL'
        SELECT DISTINCT round(((result->0->>'target_speed_mph')::numeric)*10)::int/10.0 AS speed
        FROM analysis_jobs
        WHERE calculation_method_version=3 AND status='complete' AND jsonb_typeof(result)='array' AND jsonb_array_length(result)>0
          AND result->0->>'target_speed_mph' IS NOT NULL
        ORDER BY speed
    SQL)->fetchAll(),'speed'));
    $selectedSpeed=(string)($query['speed']??'all');
    if($selectedSpeed!=='all'){
        $requestedSpeed=round((float)$selectedSpeed,1);
        $matchingSpeed=null;foreach($speeds as $speed)if(abs($speed-$requestedSpeed)<.05){$matchingSpeed=$speed;break;}
        $selectedSpeed=$matchingSpeed===null?'all':number_format($matchingSpeed,1,'.','');
    }
    $conditions=['j.calculation_method_version=3'];$parameters=[];
    if($filter!=='all'){$conditions[]='j.job_type=?';$parameters[]=$filter;}
    if($userId){$conditions[]='j.user_id=?';$parameters[]=$userId;}
    if($selectedSpeed!=='all'){$conditions[]="round(((j.result->0->>'target_speed_mph')::numeric)*10)::int=?";$parameters[]=(int)round((float)$selectedSpeed*10);}
    $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
    $groupDiscriminator=$grouped?'CASE WHEN comparable THEN NULL ELSE id END':'id';
    $historyCte=<<<SQL
        WITH history_source AS (
          SELECT j.id,j.user_id,u.username,j.status,j.stage,j.created_at,j.job_type,j.input->>'profile' AS profile,
            result_item.item <> 'null'::jsonb AS comparable,
            (result_item.position-1)::int AS result_index,
            CASE WHEN result_item.item <> 'null'::jsonb THEN CASE result_item.position
              WHEN 1 THEN coalesce(result_item.item->>'designation','recommended')
              WHEN 2 THEN coalesce(result_item.item->>'designation','day_alternative')
              WHEN 3 THEN coalesce(result_item.item->>'designation','route_time_alternative')
              ELSE coalesce(result_item.item->>'designation','alternative')
            END END AS designation,
            CASE WHEN result_item.item <> 'null'::jsonb THEN round(((result_item.item->>'target_speed_mph')::numeric)*10)::int END AS target_speed_tenths,
            CASE WHEN result_item.item <> 'null'::jsonb THEN result_item.item->>'route' END AS route,
            CASE WHEN result_item.item <> 'null'::jsonb THEN date_trunc('minute',(result_item.item->>'departure')::timestamptz) END AS departure_minute,
            CASE WHEN result_item.item <> 'null'::jsonb THEN round(((result_item.item->>'risk')::numeric)*1000)::int END AS risk_tenths,
            CASE WHEN result_item.item <> 'null'::jsonb THEN round(((result_item.item->>'expected_seconds')::numeric)/60)::int END AS expected_minutes
          FROM analysis_jobs j
          JOIN users u ON u.id=j.user_id
          LEFT JOIN LATERAL jsonb_array_elements(
            CASE WHEN j.status='complete' AND jsonb_typeof(j.result)='array' AND jsonb_array_length(j.result)>0
              THEN j.result ELSE '[null]'::jsonb END
          ) WITH ORDINALITY AS result_item(item,position) ON true{$where}
        ), grouped_history AS (
          SELECT (array_agg(id ORDER BY created_at DESC,id DESC))[1] AS id,
            (array_agg(user_id ORDER BY created_at DESC,id DESC))[1] AS user_id,
            (array_agg(username ORDER BY created_at DESC,id DESC))[1] AS username,
            (array_agg(status ORDER BY created_at DESC,id DESC))[1] AS status,
            (array_agg(stage ORDER BY created_at DESC,id DESC))[1] AS stage,
            (array_agg(job_type ORDER BY created_at DESC,id DESC))[1] AS job_type,
            max(created_at) AS created_at,profile,result_index,designation,target_speed_tenths/10.0 AS target_speed_mph,route,
            departure_minute AS departure,
            risk_tenths/1000.0 AS risk,expected_minutes*60 AS expected_seconds,
            count(*)::int AS matches
          FROM history_source
          GROUP BY profile,result_index,designation,target_speed_tenths,route,departure_minute,risk_tenths,expected_minutes,
            {$groupDiscriminator}
        )
    SQL;
    $pageSize=$perPage==='all'?null:(int)$perPage;
    $countStatement=$pdo->prepare($historyCte.' SELECT count(*) FROM grouped_history');
    $countStatement->execute($parameters);$totalRuns=(int)$countStatement->fetchColumn();
    $totalPages=$pageSize===null?1:max(1,(int)ceil($totalRuns/$pageSize));$page=max(1,min($totalPages,(int)($query['page']??1)));$offset=$pageSize===null?0:($page-1)*$pageSize;
    $sqlDirection=strtoupper($direction);
    $order=match($sort){'expected'=>"(status='complete') DESC,expected_seconds {$sqlDirection} NULLS LAST,risk ASC NULLS LAST,created_at DESC,id DESC",'run'=>"created_at {$sqlDirection},id {$sqlDirection}",'matches'=>"matches {$sqlDirection},created_at DESC,id DESC",'risk'=>"(status='complete') DESC,risk {$sqlDirection} NULLS LAST,expected_seconds ASC NULLS LAST,created_at DESC,id DESC"};
    $historySql=$historyCte;
    $historySql.=<<<SQL
        SELECT history.* FROM grouped_history history
        ORDER BY {$order}
    SQL;
    if($pageSize!==null)$historySql.=" LIMIT {$pageSize} OFFSET {$offset}";
    $statement=$pdo->prepare($historySql);$statement->execute($parameters);$runs=$statement->fetchAll();
    return['runs'=>$runs,'filter'=>$filter,'users'=>$users,'selected_user'=>$userId,'speeds'=>$speeds,'selected_speed'=>$selectedSpeed,'sort'=>$sort,'direction'=>$direction,'grouped'=>$grouped,'per_page'=>$perPage,'page'=>$page,'total_pages'=>$totalPages,'total_runs'=>$totalRuns];
};
$app->post('/history/{id}/delete',function(Request $request,Response $response,array $args)use($pdo,$csrf,&$identity):Response{
    $csrf($request);
    if(preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D',(string)$args['id'])){
        if(in_array($identity['role'],['admin','superadmin'],true)){
            $statement=$pdo->prepare('DELETE FROM analysis_jobs WHERE id=?');$statement->execute([$args['id']]);
        }else{
            $statement=$pdo->prepare('DELETE FROM analysis_jobs WHERE id=? AND user_id=?');$statement->execute([$args['id'],$identity['id']]);
            if($statement->rowCount()===0)return$response->withStatus(403);
        }
    }
    return $response->withHeader('Location','/analyze-traffic')->withStatus(302);
})->add($guard);
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
        if(!in_array($identity['role'],['admin','superadmin'],true))return$response->withStatus(403);
        $csrf($request); $body=(array)$request->getParsedBody(); unset($body['_token']);
        $allowed=['default_max_delay_risk'];
        if($identity['role']==='superadmin')$allowed=array_merge($allowed,['dashboard_banner','google_maps_api_key','google_data_storage_authorized','collection_interval_minutes','timezone','default_speed_mph','candidate_routes','departure_interval_minutes','cruising_fuel_rate_gpm','login_rate_limit','login_lockout_minutes','password_min_strength','password_min_length','automation_enabled','automation_interval_minutes','automation_speed_mph','automation_profile','automation_max_risk','telemetry_interval_minutes','simulator_crash_120_percent','simulator_crash_145_percent','simulator_weather_percent','simulator_flat_tire_percent','simulator_headwind_percent','simulator_tailwind_percent','simulator_police_percent','simulator_road_event_percent']);
        $body=array_intersect_key($body,array_flip($allowed));
        $body['default_max_delay_risk']=(string)(max(0,min(100,(float)($body['default_max_delay_risk']??20)))/100);
        if($identity['role']==='superadmin'){
            $body['collection_interval_minutes']=(string)max(5,min(10080,(int)($body['collection_interval_minutes']??60)));
            $body['departure_interval_minutes']=(string)max(5,min(60,(int)($body['departure_interval_minutes']??15)));
            $body['cruising_fuel_rate_gpm']=(string)max(.1,min(100,(float)($body['cruising_fuel_rate_gpm']??10)));
            $body['login_rate_limit']=(string)max(1,min(100,(int)($body['login_rate_limit']??5)));
            $body['login_lockout_minutes']=(string)max(1,min(1440,(int)($body['login_lockout_minutes']??15)));
            $body['password_min_length']=(string)max(8,min(64,(int)($body['password_min_length']??12)));
            if(!in_array($body['password_min_strength']??'',['strong','very_strong'],true))$body['password_min_strength']='strong';
            $body['automation_enabled']=isset($body['automation_enabled'])?'yes':'no';
            $body['automation_interval_minutes']=(string)max(5,min(10080,(int)($body['automation_interval_minutes']??60)));
            $body['automation_speed_mph']=(string)max(1,min(250,(float)($body['automation_speed_mph']??110)));
            if(!in_array($body['automation_profile']??'',['balanced','fastest','reliability'],true))$body['automation_profile']='balanced';
            $body['automation_max_risk']=(string)(max(0,min(100,(float)($body['automation_max_risk']??20)))/100);
            $body['telemetry_interval_minutes']=(string)max(1,min(1440,(int)($body['telemetry_interval_minutes']??15)));
            foreach(['simulator_crash_120_percent'=>14,'simulator_crash_145_percent'=>14,'simulator_weather_percent'=>5,'simulator_flat_tire_percent'=>5,'simulator_headwind_percent'=>1,'simulator_tailwind_percent'=>1,'simulator_police_percent'=>5,'simulator_road_event_percent'=>5] as $key=>$default)$body[$key]=(string)max(0,min(100,(float)($body[$key]??$default)));
            $body['google_data_storage_authorized']=isset($body['google_data_storage_authorized'])?'yes':'no';
            $submittedKey=trim((string)($body['google_maps_api_key']??''));if($submittedKey===''||$submittedKey==='************')unset($body['google_maps_api_key']);
        }
        $settings->save($body);$message='Settings saved.';
    }
    $values=$settings->all();
    if(!isset($values['automation_interval_minutes']))$values['automation_interval_minutes']=(string)(max(1,min(168,(int)($values['automation_interval_hours']??1)))*60);
    $values['cruising_fuel_rate_gpm']??='10';
    foreach(['simulator_crash_120_percent'=>14,'simulator_crash_145_percent'=>14,'simulator_weather_percent'=>5,'simulator_flat_tire_percent'=>5,'simulator_headwind_percent'=>1,'simulator_tailwind_percent'=>1,'simulator_police_percent'=>5,'simulator_road_event_percent'=>5] as $key=>$default)$values[$key]??=(string)$default;
    $keyConfigured=($values['google_maps_api_key'] ?? '') !== ''; unset($values['google_maps_api_key']);
    return $render($request,$response,'settings.twig',['settings'=>$values,'google_key_configured'=>$keyConfigured,'segments'=>$pdo->query('SELECT * FROM segments ORDER BY name')->fetchAll(),'message'=>$message,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

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
