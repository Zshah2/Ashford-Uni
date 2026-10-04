<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/env.php';

/**
 * Database settings from environment (DB_* or MYSQL_* on cloud hosts).
 * For local dev, copy database.local.php.example → database.local.php (gitignored).
 * Environment variables win over database.local.php so an AWS host ignores this Mac's password file.
 * No credentials are hardcoded in this file.
 */

$config = [
    'host' => '',
    'port' => 3306,
    'database' => '',
    'username' => '',
    'password' => '',
    'charset' => 'utf8mb4',
];

/** @var array<string, true> */
$setFromEnv = [];

$applyEnv = static function (string $envKey, string $configKey, bool $asInt = false) use (&$config, &$setFromEnv): void {
    $raw = app_env($envKey);
    if ($raw === null) {
        return;
    }
    $config[$configKey] = $asInt ? (int)$raw : $raw;
    $setFromEnv[$configKey] = true;
};

// MYSQL_* (common on some managed platforms)
$applyEnv('MYSQL_HOST', 'host');
$applyEnv('MYSQL_PORT', 'port', true);
$applyEnv('MYSQL_DATABASE', 'database');
$applyEnv('MYSQL_USER', 'username');
$mysqlPassword = app_env('MYSQL_PASSWORD');
if ($mysqlPassword !== null) {
    $config['password'] = $mysqlPassword;
    $setFromEnv['password'] = true;
}

// DB_* (DigitalOcean, AWS RDS, local scripts) — only if MYSQL_* did not set the field
if (!isset($setFromEnv['host'])) {
    $applyEnv('DB_HOST', 'host');
}
if (!isset($setFromEnv['port'])) {
    $applyEnv('DB_PORT', 'port', true);
}
if (!isset($setFromEnv['database'])) {
    $applyEnv('DB_NAME', 'database');
}
if (!isset($setFromEnv['username'])) {
    $applyEnv('DB_USER', 'username');
    if (!isset($setFromEnv['username'])) {
        $applyEnv('DB_USERNAME', 'username');
    }
}
if (!isset($setFromEnv['password'])) {
    $dbPassword = app_env('DB_PASSWORD') ?? app_env('DB_PASS');
    if ($dbPassword !== null) {
        $config['password'] = $dbPassword;
        $setFromEnv['password'] = true;
    }
}

$localPath = __DIR__ . '/database.local.php';
if (is_file($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        foreach ($local as $key => $value) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            if (isset($setFromEnv[$key])) {
                continue;
            }
            if ($key === 'port') {
                $config['port'] = (int)$value;
            } else {
                $config[$key] = $value;
            }
        }
    }
}

return $config;
