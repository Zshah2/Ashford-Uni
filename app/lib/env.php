<?php

declare(strict_types=1);

/**
 * Read one setting from the process environment.
 * AWS and some Apache/PHP-FPM setups expose variables on $_SERVER or $_ENV
 * rather than getenv().
 */
function app_env(string $key): ?string
{
    $candidates = [];
    $fromGetenv = getenv($key);
    if ($fromGetenv !== false) {
        $candidates[] = (string)$fromGetenv;
    }
    if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
        $candidates[] = $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        $candidates[] = $_SERVER[$key];
    }
    foreach ($candidates as $value) {
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function app_env_flag(string $key): bool
{
    $value = app_env($key);
    if ($value === null) {
        return false;
    }
    $value = strtolower($value);

    return $value === '1' || $value === 'true' || $value === 'on' || $value === 'yes';
}

/** True for a direct HTTPS request, or for AWS/ALB when TRUST_PROXY=1. */
function app_request_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    if (!app_env_flag('TRUST_PROXY')) {
        return false;
    }
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $proto === 'https';
}
