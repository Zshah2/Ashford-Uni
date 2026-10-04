<?php

declare(strict_types=1);

/**
 * Raw SCRIPT_NAME (may omit project prefix on some built-in servers / IDEs).
 */
function app_script_name(): string
{
    return str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
}

/**
 * Web path to the executing script (/project/public/index.php). When REQUEST_URI
 * contains /index.php, derive from it so SCRIPT_NAME quirks (e.g. only /index.php)
 * do not break links and routing under a subdirectory.
 */
function app_effective_script_name(): string
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uriPath = str_replace('\\', '/', (string)(($uriPath === false || $uriPath === null || $uriPath === '') ? '/' : $uriPath));

    $needle = '/index.php';
    $pos = strpos($uriPath, $needle);
    if ($pos !== false) {
        return substr($uriPath, 0, $pos + strlen($needle));
    }

    $sn = app_script_name();

    return $sn !== '' ? $sn : '/index.php';
}

/**
 * Path to the front controller (index.php) for the app, even when the current
 * request is login.php, admin.php, etc. (same directory as index.php).
 */
function app_front_controller(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $script = app_effective_script_name();
    if ($script === '') {
        $cached = '';

        return $cached;
    }

    if (str_ends_with($script, '/index.php')) {
        $cached = $script;

        return $cached;
    }

    $dir = dirname($script);
    if ($dir === '/' || $dir === '.') {
        $cached = '/index.php';
    } else {
        $cached = $dir . '/index.php';
    }

    return $cached;
}

/**
 * JetBrains / PhpStorm built-in server (port 63342) does not rewrite /public/login → index.php.
 * In that case links use /public/index.php/... . Opt in/out with APP_USE_INDEX_PHP_LINKS (1/0/true/false).
 */
function app_use_index_php_in_links(): bool
{
    if (!function_exists('app_env')) {
        require_once __DIR__ . '/env.php';
    }
    $env = app_env('APP_USE_INDEX_PHP_LINKS');
    if ($env === '0' || strtolower((string)$env) === 'false' || strtolower((string)$env) === 'off') {
        return false;
    }
    if ($env === '1' || strtolower((string)$env) === 'true' || strtolower((string)$env) === 'on') {
        return true;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');

    return str_contains($host, ':63342');
}

/**
 * URL prefix when the app is not served at the web root (same folder as index.php).
 * Override with env APP_BASE_PATH (no trailing slash), e.g. APP_BASE_PATH=/CollegWeb/public
 */
function app_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!function_exists('app_env')) {
        require_once __DIR__ . '/env.php';
    }
    $env = app_env('APP_BASE_PATH');
    if (is_string($env) && $env !== '') {
        $cached = '/' . trim($env, '/');

        return $cached;
    }

    $script = app_effective_script_name();
    if ($script === '' || $script === '/index.php') {
        $cached = '';

        return $cached;
    }

    if (str_ends_with($script, '/index.php')) {
        $base = substr($script, 0, -strlen('/index.php'));
    } else {
        $base = dirname($script);
    }

    $base = rtrim($base, '/');
    if ($base === '' || $base === '/') {
        $cached = '';
    } else {
        $cached = $base;
    }

    return $cached;
}

/**
 * Absolute app URL path (leading /). Optional ?query only. Pass-through for http(s):// URLs.
 */
function url(string $path): string
{
    if ($path !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        return $path;
    }

    $query = '';
    if (str_contains($path, '?')) {
        [$path, $query] = explode('?', $path, 2);
        $query = '?' . $query;
    }

    $p = '/' . ltrim($path, '/');

    if (app_use_index_php_in_links()) {
        $fc = app_front_controller();
        if ($fc !== '') {
            // Home: avoid /index.php/ or /index.php/index.php
            if ($p === '/' || $p === '/index.php') {
                return $fc . $query;
            }

            // Other real PHP entry points in public/ (admin.php, login.php, logout.php, …) must not
            // be expressed as index.php/admin.php — that is not a file and breaks the built-in server.
            if (preg_match('#\.php$#', $p) && $p !== '/index.php') {
                $base = app_base_path();

                return ($base === '' ? '' : $base) . $p . $query;
            }

            // Static files live under document root (public/) and must not be routed as
            // …/index.php/assets/… or the IDE/built-in server returns HTML/404 instead of CSS/JS.
            $base = app_base_path();
            $isStaticUnderDocroot = (
                str_starts_with($p, '/assets/')
                || str_starts_with($p, '/favicon.ico')
                || (
                    preg_match('#\.(?:css|js|mjs|map|ico|png|gif|jpg|jpeg|webp|svg|woff2|woff|ttf|otf|txt|xml|json|webmanifest)$#i', $p) === 1
                    && !preg_match('#\.php$#i', $p)
                )
            );
            if ($isStaticUnderDocroot) {
                return ($base === '' ? '' : $base) . $p . $query;
            }

            return $fc . $p . $query;
        }
    }

    $base = app_base_path();

    if ($p === '/index.php') {
        return ($base === '' ? '' : $base) . '/index.php' . $query;
    }

    return ($base === '' ? '' : $base) . $p . $query;
}

/** For nav/config links: leave #anchors unchanged; prefix absolute app paths. */
function nav_url(string $href): string
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#') {
        return $href;
    }
    if ($href[0] === '/') {
        return url($href);
    }

    return $href;
}
