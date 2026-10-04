<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Shared PDO connection (login, admin, scripts, etc.).
 * Cloud (DO/AWS/VPS): DB_* or MYSQL_* env vars (see docs/DEPLOY.md).
 * Local: database.local.php or the same env vars.
 */

function db_env(string $key): ?string
{
    return app_env($key);
}

/**
 * @return array{host: string, port: int, database: string, username: string, password: string}|null
 */
function db_credentials_from_env(): ?array
{
    $host = db_env('MYSQL_HOST') ?? db_env('DB_HOST');
    $database = db_env('MYSQL_DATABASE') ?? db_env('DB_NAME');
    if ($host === null || $database === null) {
        return null;
    }

    $portRaw = db_env('MYSQL_PORT') ?? db_env('DB_PORT');
    $port = $portRaw !== null ? (int)$portRaw : 3306;

    $username = db_env('MYSQL_USER') ?? db_env('DB_USER') ?? db_env('DB_USERNAME') ?? '';
    $password = db_env('MYSQL_PASSWORD');
    if ($password === null) {
        $password = db_env('DB_PASSWORD') ?? db_env('DB_PASS') ?? '';
    }

    return [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
    ];
}

function db_has_cloud_env(): bool
{
    return db_env('MYSQL_HOST') !== null
        || db_env('DB_HOST') !== null
        || db_env('MYSQL_DATABASE') !== null
        || db_env('DB_NAME') !== null;
}

/**
 * User-facing hint when db() fails (login / verify_otp banners).
 */
function db_connection_help_message(): string
{
    if (db_has_cloud_env()) {
        return 'Database connection failed. In your host dashboard, confirm MYSQL_* or DB_* variables (host, port, name, user, password) are set, then Save and Redeploy.';
    }

    return 'Start MySQL, copy app/config/database.local.php.example → database.local.php, then run php scripts/migrate.php.';
}

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $fromEnv = db_credentials_from_env();
    if ($fromEnv !== null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $fromEnv['host'],
            $fromEnv['port'],
            $fromEnv['database']
        );
        $pdo = new PDO($dsn, $fromEnv['username'], $fromEnv['password'], db_pdo_options());

        return $pdo;
    }

    $cfg = config('database');
    if (($cfg['host'] ?? '') === '' || ($cfg['database'] ?? '') === '') {
        throw new RuntimeException(
            'Database is not configured. Set MYSQL_* or DB_* environment variables, '
            . 'or copy app/config/database.local.php.example to database.local.php for local dev.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['database'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, (string)$cfg['username'], (string)$cfg['password'], db_pdo_options());

    return $pdo;
}

/**
 * Same driver options locally and on AWS.
 * Set DB_SSL_CA to the RDS CA bundle path when the database requires TLS.
 *
 * @return array<int|string, mixed>
 */
function db_pdo_options(): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $ca = db_env('DB_SSL_CA') ?? db_env('MYSQL_ATTR_SSL_CA');
    if ($ca !== null && is_file($ca)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        if (!app_env_flag('DB_SSL_VERIFY')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    return $options;
}
