<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/url.php';
require __DIR__ . '/../app/lib/db.php';
require __DIR__ . '/../app/lib/auth.php';
require __DIR__ . '/../app/lib/csrf.php';
require __DIR__ . '/../app/lib/ui.php';
require __DIR__ . '/../app/lib/bootstrap.php';

bootstrap_app();

require __DIR__ . '/../app/controllers.php';

$app = config('app');

$routes = require __DIR__ . '/../app/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = str_replace('\\', '/', (string)$path);

$scriptName = app_effective_script_name();

/*
 * Valid app paths are defined in app/routes.php (GET/POST + path). After normalization below,
 * $path must match those literals (e.g. /login, /admin/students/show) for a route to run.
 */
if (is_string($scriptName) && $scriptName !== '' && str_starts_with($path, $scriptName)) {
    $path = substr($path, strlen($scriptName));
    $path = $path === '' ? '/' : $path;
}

// e.g. REQUEST_URI /project/public/login with SCRIPT_NAME /project/public/index.php → route /login
$scriptDir = is_string($scriptName) && $scriptName !== ''
    ? str_replace('\\', '/', dirname($scriptName))
    : '';
if ($scriptDir !== '' && $scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
    $suffix = substr($path, strlen($scriptDir));
    $path = $suffix === '' ? '/' : $suffix;
}

if ($path === '/index.php' || $path === '/public/index.php') {
    $path = '/';
} elseif (str_starts_with($path, '/index.php/')) {
    $path = substr($path, strlen('/index.php'));
} elseif (str_starts_with($path, '/public/index.php/')) {
    $path = substr($path, strlen('/public/index.php'));
}

// e.g. /project/public/index.php/login → /login if earlier steps left /index.php/...
if (str_contains($path, '/index.php/')) {
    $pos = strpos($path, '/index.php/');
    $path = substr($path, $pos + strlen('/index.php'));
    if ($path === '') {
        $path = '/';
    }
}

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

// Under …/index.php/…, a relative link "login.php" resolves to …/index.php/login.php (404).
// Map those mistaken paths to the real front-controller routes.
$fcPathAliases = [
    '/login.php' => '/login',
    '/signup.php' => '/signup',
];
if (isset($fcPathAliases[$path])) {
    $path = $fcPathAliases[$path];
}

function route_match(array $route, string $method, string $path): bool
{
    return $route[0] === $method && $route[1] === $path;
}

$handlerMap = [
    'home' => 'handler_home',
    'health' => 'handler_health',
    'public_calendar' => 'handler_public_calendar',
    'public_fall_schedule' => 'handler_public_fall_schedule',
    'public_spring_schedule' => 'handler_public_spring_schedule',
    'public_catalog' => 'handler_public_catalog',
    'admin_login_form' => 'handler_admin_login_form',
    'admin_login_submit' => 'handler_admin_login_submit',
    'admin_signup_form' => 'handler_admin_signup_form',
    'admin_signup_submit' => 'handler_admin_signup_submit',
    'admin_logout' => 'handler_admin_logout',
    'admin_dashboard' => 'handler_admin_dashboard',
    'admin_student_search' => 'handler_admin_student_search',
    'admin_student_show' => 'handler_admin_student_show',
    'admin_schedule' => 'handler_admin_schedule',
    'admin_holds_index' => 'handler_admin_holds_index',
    'admin_holds_show' => 'handler_admin_holds_show',
    'admin_holds_add' => 'handler_admin_holds_add',
    'admin_holds_clear' => 'handler_admin_holds_clear',
];

foreach ($routes as $route) {
    if (route_match($route, $method, $path)) {
        $name = $route[2];
        $fn = $handlerMap[$name] ?? null;
        if (!is_string($fn) || !function_exists($fn)) {
            http_response_code(500);
            echo 'Route handler not found.';

            exit;
        }
        $fn([]);

        exit;
    }
}

http_response_code(404);
render('pages/404.php', [
    'app' => $app,
    'path' => $path,
    'pageTitle' => 'Page not found',
], 'layouts/main.php');
