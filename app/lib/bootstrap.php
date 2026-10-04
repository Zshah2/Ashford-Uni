<?php

declare(strict_types=1);

function app_debug(): bool
{
    if (!function_exists('app_env_flag')) {
        require_once __DIR__ . '/env.php';
    }

    return app_env_flag('APP_DEBUG');
}

function bootstrap_app(): void
{
    if (app_debug()) {
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        // E_STRICT removed in PHP 8.4+; mask deprecations in production demo mode.
        error_reporting(E_ALL & ~E_DEPRECATED);
    }

    set_exception_handler(static function (Throwable $e): void {
        error_log('[CollegeWeb] ' . $e->getMessage() . "\n" . $e->getTraceAsString());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        if (app_debug()) {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:system-ui;padding:2rem;background:#0f172a;color:#e2e8f0">';
            echo '<h1>Error</h1><pre style="white-space:pre-wrap;word-break:break-word">';
            echo htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString());
            echo '</pre></body></html>';
            exit;
        }

        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Something went wrong</title></head><body style="font-family:system-ui;padding:2rem;background:#0f172a;color:#e2e8f0">';
        echo '<h1>Something went wrong</h1>';
        echo '<p>Please try again later. If you are the site operator, set <code>APP_DEBUG=1</code> for details.</p>';
        echo '</body></html>';
        exit;
    });
}
