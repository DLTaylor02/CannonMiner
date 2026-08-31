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

$render = static function (Request $request, Response $response, string $template, array $data = []) use ($twig): Response {
    return $twig->render($response, $template, $data + ['user' => $_SESSION['username'] ?? null]);
};
$guard = static function (Request $request, RequestHandlerInterface $handler): Response {
    if (isset($_SESSION['user_id'])) return $handler->handle($request);
    $response = new \Slim\Psr7\Response();
    return $response->withHeader('Location', '/login')->withStatus(302);
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

$app->map(['GET','POST'], '/', function (Request $request, Response $response) use ($router, $settings, $render): Response {
    $nodes = $router->nodes(); $input = ['start'=>'redball','end'=>'portofino','speed'=>(float)$settings->get('default_speed_mph','110'),
        'profile'=>'balanced','risk'=>(float)$settings->get('default_max_delay_risk','.20')];
    $results = []; $error = null;
    if ($request->getMethod() === 'POST') {
        $input = array_merge($input, (array)$request->getParsedBody());
        try { $results = $router->explore((string)$input['start'], (string)$input['end'], (float)$input['speed'], (string)$input['profile'], (float)$input['risk']); }
        catch (Throwable $exception) { $error = $exception->getMessage(); }
    }
    return $render($request, $response, 'dashboard.twig', ['nodes'=>$nodes,'input'=>$input,'results'=>$results,'error'=>$error,'csrf'=>$_SESSION['csrf']]);
})->add($guard);

$app->get('/trends', fn(Request $q, Response $r): Response => $render($q,$r,'trends.twig',['trends'=>$router->trends(),'csrf'=>$_SESSION['csrf']]))->add($guard);
$app->post('/segments/{id}/toggle', function (Request $request, Response $response, array $args) use ($pdo,$csrf): Response {
    $csrf($request); $statement=$pdo->prepare('UPDATE segments SET enabled=NOT enabled WHERE id=?');
    $statement->execute([(int)$args['id']]); return $response->withHeader('Location','/settings')->withStatus(302);
})->add($guard);
$app->map(['GET','POST'], '/settings', function (Request $request, Response $response) use ($pdo,$settings,$render,$csrf): Response {
    $message = null;
    if ($request->getMethod() === 'POST') {
        $csrf($request); $body=(array)$request->getParsedBody(); unset($body['_token']);
        $body['collection_interval_minutes'] = (string)max(5, min(10080, (int)($body['collection_interval_minutes'] ?? 60)));
        $body['google_data_storage_authorized'] = isset($body['google_data_storage_authorized']) ? 'yes' : 'no';
        $settings->save($body); $message='Settings saved.';
    }
    $lastRun=$pdo->query('SELECT * FROM collection_runs ORDER BY started_at DESC LIMIT 1')->fetch();
    return $render($request,$response,'settings.twig',['settings'=>$settings->all(),'segments'=>$pdo->query('SELECT * FROM segments ORDER BY name')->fetchAll(),'last_run'=>$lastRun,'message'=>$message,'csrf'=>$_SESSION['csrf']]);
})->add($guard);
$app->run();
