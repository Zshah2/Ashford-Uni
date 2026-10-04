<?php

declare(strict_types=1);

/**
 * Portal roles (`auth_users.role`):
 * - admin: full access — registration add/drop, holds, future grade writes.
 * - limited: registration + holds; cannot perform blocked write actions (e.g. grade import).
 * - viewer: read-only — no mutations via admin.php or legacy /admin/holds/* POST routes.
 * - stat: full-time department statistics. Aggregate views only, plus that employee's own record.
 */

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/env.php';

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_role(): ?string
{
    auth_start_session();
    $r = $_SESSION['auth']['role'] ?? null;

    return is_string($r) && $r !== '' ? $r : null;
}

function auth_is_admin(): bool
{
    return auth_role() === 'admin';
}

/** Transcript grades stay with the main admin account, not every admin. */
function auth_can_post_grades(): bool
{
    auth_start_session();
    $username = $_SESSION['auth']['username'] ?? '';

    return $username === 'mainadmin';
}

/** Teammate accounts land on the access sheet so a test login shows what they can do. */
function auth_home_view(): string
{
    auth_start_session();
    $username = $_SESSION['auth']['username'] ?? '';
    if (auth_role() === 'stat') {
        return 'stats';
    }
    if (in_array($username, ['sraza9', 'wbhatti1', 'asewell3'], true)) {
        return 'team';
    }

    return 'dashboard';
}

function auth_is_stat(): bool
{
    return auth_role() === 'stat';
}

function auth_is_limited(): bool
{
    return auth_role() === 'limited';
}

function auth_is_viewer(): bool
{
    return auth_role() === 'viewer';
}

/** Fully authenticated portal user (password + 2FA complete). Pending 2FA does not count. */
function auth_is_portal_user(): bool
{
    auth_start_session();
    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        return false;
    }
    $r = auth_role();

    return $r === 'admin' || $r === 'limited' || $r === 'viewer' || $r === 'stat';
}

function auth_has_pending_2fa(): bool
{
    auth_start_session();

    return isset($_SESSION['pending_2fa_email'])
        && is_string($_SESSION['pending_2fa_email'])
        && $_SESSION['pending_2fa_email'] !== ''
        && isset($_SESSION['pending_2fa_auth'])
        && is_array($_SESSION['pending_2fa_auth']);
}

function auth_clear_pending_2fa(): void
{
    auth_start_session();
    unset(
        $_SESSION['pending_2fa_email'],
        $_SESSION['pending_2fa_auth'],
        $_SESSION['twofa_resend_after'],
        $_SESSION['dev_otp_preview'],
        $_SESSION['dev_otp_preview_for'],
    );
}

function auth_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function auth_resolve_display_name(array $row): string
{
    $display = isset($row['display_name']) ? trim((string)$row['display_name']) : '';
    if ($display !== '') {
        return $display;
    }

    return trim((string)($row['username'] ?? '')) ?: 'Admin';
}

/** @param array<string, mixed> $row */
function auth_map_user_row(array $row): array
{
    $email = isset($row['email']) ? trim((string)$row['email']) : '';
    $display = isset($row['display_name']) ? trim((string)$row['display_name']) : '';

    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'display_name' => $display !== '' ? $display : null,
        'role' => (string)$row['role'],
        'email' => $email !== '' ? auth_normalize_email($email) : null,
        'password_hash' => (string)$row['password_hash'],
        'is_active' => (int)($row['is_active'] ?? 1),
        'person_id' => isset($row['person_id']) ? (int)$row['person_id'] : 0,
    ];
}

function auth_portal_display_name(): string
{
    auth_start_session();
    $n = $_SESSION['auth']['display_name'] ?? null;
    if (is_string($n) && trim($n) !== '') {
        return trim($n);
    }
    $u = $_SESSION['auth']['username'] ?? '';

    return is_string($u) && $u !== '' ? $u : 'Admin';
}

/** Derive a unique-friendly username when only email is provided at signup/seed. */
function auth_username_from_email(string $email): string
{
    $local = explode('@', auth_normalize_email($email), 2)[0];
    $local = preg_replace('/[^a-zA-Z0-9._-]/', '', $local) ?? '';

    return $local !== '' ? substr($local, 0, 100) : 'user';
}

/**
 * @return array{id: int, username: string, role: string, email: ?string, password_hash: string, is_active: int}|null
 */
function auth_fetch_user_by_email(string $email): ?array
{
    $email = auth_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare('
          SELECT id, username, display_name, email, password_hash, role, IFNULL(is_active, 1) AS is_active, person_id
          FROM auth_users WHERE LOWER(TRIM(email)) = ? LIMIT 1
        ');
        $stmt->execute([$email]);
    } catch (Throwable) {
        try {
            $stmt = $pdo->prepare('
              SELECT id, username, email, password_hash, role, IFNULL(is_active, 1) AS is_active
              FROM auth_users WHERE LOWER(TRIM(email)) = ? LIMIT 1
            ');
            $stmt->execute([$email]);
        } catch (Throwable) {
            return null;
        }
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return auth_map_user_row($row);
}

/**
 * @return array{id: int, username: string, role: string, email: ?string, password_hash: string, is_active: int}|null
 */
function auth_fetch_user_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare('
          SELECT id, username, display_name, email, password_hash, role, IFNULL(is_active, 1) AS is_active
          FROM auth_users WHERE id = ? LIMIT 1
        ');
        $stmt->execute([$id]);
    } catch (Throwable) {
        try {
            $stmt = $pdo->prepare('
              SELECT id, username, email, password_hash, role, IFNULL(is_active, 1) AS is_active
              FROM auth_users WHERE id = ? LIMIT 1
            ');
            $stmt->execute([$id]);
        } catch (Throwable) {
            return null;
        }
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return auth_map_user_row($row);
}

/**
 * @return array{0:bool,1:?string}
 */
function auth_update_user_display_name(int $userId, string $displayName): array
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return [false, 'Name is required.'];
    }
    if (strlen($displayName) > 100) {
        return [false, 'Name is too long.'];
    }

    try {
        db()->prepare('UPDATE auth_users SET display_name = ? WHERE id = ?')->execute([$displayName, $userId]);
    } catch (Throwable) {
        return [false, 'Could not save name — run php scripts/migrate.php'];
    }

    return [true, null];
}

function auth_verify_user_password(int $userId, string $password): bool
{
    $row = auth_fetch_user_by_id($userId);

    return $row !== null && password_verify($password, $row['password_hash']);
}

/**
 * @return array{0:bool,1:?string}
 */
function auth_update_user_email(int $userId, string $email): array
{
    $email = auth_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'A valid email is required.'];
    }

    $pdo = db();
    $dup = $pdo->prepare('SELECT id FROM auth_users WHERE LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1');
    $dup->execute([$email, $userId]);
    if ($dup->fetchColumn()) {
        return [false, 'That email is already used by another account.'];
    }

    try {
        $pdo->prepare('UPDATE auth_users SET email = ? WHERE id = ?')->execute([$email, $userId]);
    } catch (Throwable) {
        return [false, 'Could not save email — run migrations (auth_users.email).'];
    }

    return [true, null];
}

/**
 * @return array{0:bool,1:?string}
 */
function auth_update_user_password(int $userId, string $newPassword): array
{
    if (strlen($newPassword) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        return [false, 'Failed to hash password.'];
    }

    $pdo = db();
    $pdo->prepare('UPDATE auth_users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);

    return [true, null];
}

/**
 * Update sign-in email and/or password for a portal user.
 *
 * @return array{0:bool,1:?string}
 */
function auth_update_portal_login(
    int $userId,
    string $email,
    ?string $newPassword,
    ?string $currentPasswordForSelf = null,
    ?string $displayName = null,
): array {
    $row = auth_fetch_user_by_id($userId);
    if ($row === null) {
        return [false, 'Account not found.'];
    }

    if ($currentPasswordForSelf !== null && !auth_verify_user_password($userId, $currentPasswordForSelf)) {
        return [false, 'Current password is incorrect.'];
    }

    if ($displayName !== null) {
        [$okName, $errName] = auth_update_user_display_name($userId, $displayName);
        if (!$okName) {
            return [false, $errName];
        }
    }

    [$ok, $err] = auth_update_user_email($userId, $email);
    if (!$ok) {
        return [false, $err];
    }

    if ($newPassword !== null && $newPassword !== '') {
        return auth_update_user_password($userId, $newPassword);
    }

    return [true, null];
}

/**
 * Verify portal credentials without establishing a logged-in session (for 2FA step 1).
 *
 * @return array{id: int, username: string, role: string, email: ?string}|null
 */
function auth_verify_portal_credentials(string $email, string $password): ?array
{
    $email = auth_normalize_email($email);
    if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $row = auth_fetch_user_by_email($email);
    if ($row === null) {
        return null;
    }
    if (!password_verify($password, $row['password_hash'])) {
        return null;
    }
    if ($row['is_active'] !== 1) {
        return null;
    }
    $role = $row['role'];
    if ($role !== 'admin' && $role !== 'limited' && $role !== 'viewer' && $role !== 'stat') {
        return null;
    }

    return [
        'id' => $row['id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'] ?? auth_resolve_display_name($row),
        'role' => $role,
        'email' => $row['email'],
        'person_id' => (int)($row['person_id'] ?? 0),
    ];
}

/**
 * @param array{id: int, username: string, role: string, email?: ?string, display_name?: ?string} $row
 */
function auth_begin_pending_2fa(array $row): void
{
    auth_start_session();
    auth_clear_pending_2fa();
    session_regenerate_id(true);

    $email = isset($row['email']) ? strtolower(trim((string)$row['email'])) : '';
    $_SESSION['pending_2fa_email'] = $email;
    $_SESSION['pending_2fa_auth'] = [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'display_name' => auth_resolve_display_name($row),
        'role' => (string)$row['role'],
    ];
}

/**
 * @param array{id: int, username: string, role: string, email?: ?string, display_name?: ?string} $row
 */
function auth_establish_portal_session(array $row): void
{
    auth_start_session();
    auth_clear_pending_2fa();
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'display_name' => auth_resolve_display_name($row),
        'role' => (string)$row['role'],
        'person_id' => (int)($row['person_id'] ?? 0),
    ];
}

function auth_complete_pending_2fa(): bool
{
    auth_start_session();
    if (!auth_has_pending_2fa()) {
        return false;
    }

    $pending = $_SESSION['pending_2fa_auth'];
    auth_establish_portal_session($pending);

    return auth_is_portal_user();
}

/** Add/clear student holds (admin.php + legacy holds routes) */
function auth_can_manage_holds(): bool
{
    return auth_is_admin() || auth_is_limited();
}

/** Add/drop enrollments in admin registration UI */
function auth_can_manage_registration(): bool
{
    return auth_is_admin() || auth_is_limited();
}

function auth_require_admin(): void
{
    if (!auth_is_admin()) {
        header('Location: ' . url('/login.php'));
        exit;
    }
}

function auth_require_portal_user(): void
{
    if (!auth_is_portal_user()) {
        header('Location: ' . url('/login.php'));
        exit;
    }
}

function auth_require_hold_manager(): void
{
    auth_require_portal_user();
    if (!auth_can_manage_holds()) {
        header('Location: ' . url('/admin.php?view=dashboard'));
        exit;
    }
}

function auth_login(string $email, string $password): bool
{
    $row = auth_verify_portal_credentials($email, $password);
    if ($row === null) {
        return false;
    }

    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = [
        'id' => $row['id'],
        'username' => $row['username'],
        'display_name' => auth_resolve_display_name($row),
        'role' => $row['role'],
        'person_id' => (int)($row['person_id'] ?? 0),
    ];

    return true;
}

function auth_login_portal_user(string $email, string $password): bool
{
    if (!auth_login($email, $password)) {
        return false;
    }

    return auth_is_portal_user();
}

function auth_login_admin(string $email, string $password): bool
{
    if (!auth_login($email, $password)) {
        return false;
    }

    return auth_is_admin();
}

/**
 * Creates an admin auth user.
 *
 * @return array{0:bool,1:?string}
 */
function auth_create_admin(string $email, string $password, ?string $username = null): array
{
    return auth_create_user_with_role($email, $password, 'admin', $username);
}

/**
 * @return array{0:bool,1:?string}
 */
function auth_create_user_with_role(string $email, string $password, string $role, ?string $username = null): array
{
    $email = auth_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'A valid email is required.'];
    }
    $username = $username !== null ? trim($username) : auth_username_from_email($email);
    if ($username === '') {
        return [false, 'Username is required.'];
    }
    if (strlen($username) > 100) {
        return [false, 'Username is too long.'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password must be at least 8 characters.'];
    }
    if (!in_array($role, ['admin', 'limited', 'viewer', 'stat'], true)) {
        return [false, 'Invalid role.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        return [false, 'Failed to hash password.'];
    }

    $pdo = db();
    try {
        $stmt = $pdo->prepare('INSERT INTO auth_users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $email, $hash, $role]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062)) {
            if (auth_fetch_user_by_email($email) !== null) {
                return [false, 'That email is already registered.'];
            }

            return [false, 'That username is already taken.'];
        }

        return [false, 'Failed to create user.'];
    } catch (Throwable) {
        try {
            $stmt = $pdo->prepare('INSERT INTO auth_users (username, password_hash, role) VALUES (?, ?, ?)');
            $stmt->execute([$username, $hash, $role]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062)) {
                return [false, 'That username is already taken.'];
            }

            return [false, 'Failed to create user. Run php scripts/migrate.php.'];
        }
    }

    return [true, null];
}

function auth_logout(): void
{
    auth_start_session();
    auth_clear_pending_2fa();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
