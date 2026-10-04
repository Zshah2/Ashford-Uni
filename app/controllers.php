<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/admin_portal.php';

function redirect(string $to): void
{
    if ($to !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $to) && str_starts_with($to, '/')) {
        $to = url($to);
    }
    header('Location: ' . $to);
    exit;
}

function audit_admin(PDO $pdo, string $action, string $details): void
{
    auth_start_session();
    $aid = (int)($_SESSION['auth']['id'] ?? 0);
    if ($aid < 1) {
        return;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_audit_log (admin_auth_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([$aid, $action, $details]);
    } catch (Throwable) {
        // avoid breaking UX if audit table missing in older DBs
    }
}

function handler_home(array $params): void
{
    global $app;
    render('pages/home.php', ['app' => $app]);
}

function public_term_schedule(string $code, string $title): void
{
    global $app;
    $pdo = db();
    $stmt = $pdo->prepare('
      SELECT c.course_id, c.course_name, s.meeting_days, s.meeting_time, s.room, s.capacity,
        TRIM(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) AS instructor
      FROM sections s
      INNER JOIN courses c ON c.course_id = s.course_id
      INNER JOIN terms t ON t.term_id = s.term_id
      LEFT JOIN users u ON u.user_id = s.faculty_id
      WHERE t.code = ?
      ORDER BY c.course_id, s.meeting_days, s.meeting_time, s.section_id
    ');
    $stmt->execute([$code]);
    render('pages/public_schedule.php', [
        'app' => $app,
        'pageTitle' => $title,
        'termName' => $title,
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function handler_public_calendar(array $params): void
{
    global $app;
    $events = db()->query('
      SELECT c.event_name, c.start_date, c.end_date, c.notes, t.name AS term_name
      FROM academic_calendar c
      LEFT JOIN terms t ON t.term_id = c.term_id
      ORDER BY c.start_date, c.calendar_id
    ')->fetchAll(PDO::FETCH_ASSOC);
    render('pages/public_calendar.php', [
        'app' => $app,
        'pageTitle' => 'Academic calendar',
        'events' => $events,
    ]);
}

function handler_public_fall_schedule(array $params): void
{
    public_term_schedule('fall2026', 'Fall 2026');
}

function handler_public_spring_schedule(array $params): void
{
    public_term_schedule('spring2027', 'Spring 2027');
}

function handler_public_catalog(array $params): void
{
    global $app;
    $courses = db()->query('
      SELECT c.course_id, c.course_name, c.credits, c.description, d.dept_name,
        (
          SELECT GROUP_CONCAT(p.prereq_course_id ORDER BY p.prereq_course_id SEPARATOR ", ")
          FROM course_prereqs p
          WHERE p.course_id = c.course_id
        ) AS prereqs
      FROM courses c
      LEFT JOIN departments d ON d.dept_id = c.dept_id
      ORDER BY c.course_id
    ')->fetchAll(PDO::FETCH_ASSOC);
    render('pages/public_catalog.php', [
        'app' => $app,
        'pageTitle' => 'University catalog',
        'courses' => $courses,
    ]);
}

function handler_health(array $params): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = db();
        $pdo->query('SELECT 1')->fetchColumn();
        echo json_encode(['ok' => true, 'database' => true], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'database' => false], JSON_THROW_ON_ERROR);
    }
}

function handler_admin_login_form(array $params): void
{
    if (auth_is_portal_user()) {
        redirect('/admin.php?view=dashboard');
    }
    // Admin sign-in lives on the real file public/login.php (clean URL under PhpStorm, etc.).
    header('Location: ' . url('/login.php'), true, 302);
    exit;
}

function handler_admin_login_submit(array $params): void
{
    global $app;
    csrf_require_valid();

    $email = trim((string)($_POST['email'] ?? $_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $ok = $email !== '' && $password !== '' && auth_login_portal_user($email, $password);
    if ($ok) {
        redirect('/admin.php?view=dashboard');
    }

    render('pages/admin/login.php', [
        'app' => $app,
        'error' => 'Invalid email or password.',
        'csrf' => csrf_token(),
        'pageTitle' => 'Sign in',
    ], 'layouts/main.php');
}

function handler_admin_signup_form(array $params): void
{
    global $app;
    if (auth_is_portal_user()) {
        redirect('/admin.php?view=dashboard');
    }
    render('pages/admin/signup.php', [
        'app' => $app,
        'error' => null,
        'csrf' => csrf_token(),
        'pageTitle' => 'Create account',
    ], 'layouts/main.php');
}

function handler_admin_signup_submit(array $params): void
{
    global $app;
    csrf_require_valid();

    if (auth_is_portal_user()) {
        redirect('/admin.php?view=dashboard');
    }

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($password !== $confirm) {
        render('pages/admin/signup.php', [
            'app' => $app,
            'error' => 'Passwords do not match.',
            'csrf' => csrf_token(),
            'pageTitle' => 'Create account',
        ], 'layouts/main.php');

        return;
    }

    [$ok, $err] = auth_create_admin($email, $password);
    if (!$ok) {
        render('pages/admin/signup.php', [
            'app' => $app,
            'error' => $err ?: 'Sign up failed.',
            'csrf' => csrf_token(),
            'pageTitle' => 'Create account',
        ], 'layouts/main.php');

        return;
    }

    redirect('/login.php');
}

function handler_admin_logout(array $params): void
{
    csrf_require_valid();
    auth_logout();
    redirect('/login.php');
}

function handler_admin_dashboard(array $params): void
{
    auth_require_portal_user();
    redirect('/admin.php?view=dashboard');
}

function handler_admin_student_search(array $params): void
{
    auth_require_portal_user();
    redirect('/admin.php?view=people');
}

function handler_admin_student_show(array $params): void
{
    auth_require_portal_user();
    $studentIdRaw = trim((string)($_GET['student_id'] ?? ''));
    if ($studentIdRaw !== '' && ctype_digit($studentIdRaw)) {
        redirect('/admin.php?view=people&id=' . rawurlencode($studentIdRaw));
    }
    redirect('/admin.php?view=people');
}

function handler_admin_schedule(array $params): void
{
    auth_require_portal_user();
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    redirect('/admin.php?view=schedule' . ($qs !== '' ? '&' . $qs : ''));
}

function handler_admin_holds_index(array $params): void
{
    auth_require_portal_user();
    redirect('/admin.php?view=holds');
}

function handler_admin_holds_show(array $params): void
{
    auth_require_portal_user();
    $studentIdRaw = trim((string)($_GET['student_id'] ?? ''));
    if ($studentIdRaw !== '' && ctype_digit($studentIdRaw)) {
        redirect('/admin.php?view=people&id=' . rawurlencode($studentIdRaw) . '&people_panel=hold');
    }
    redirect('/admin.php?view=holds');
}

function handler_admin_holds_add(array $params): void
{
    auth_require_hold_manager();
    csrf_require_valid();

    $studentId = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
    $holdType = trim((string)($_POST['hold_type'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $allowed = ['Bursar', 'Academic', 'Registration', 'Other'];

    if ($studentId === null || !in_array($holdType, $allowed, true)) {
        redirect('/admin.php?view=holds&error=invalid');
    }

    $pdo = db();
    $chk = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ?');
    $chk->execute([$studentId]);
    if (!$chk->fetchColumn()) {
        redirect('/admin.php?view=holds&error=nostudent');
    }

    $stmt = $pdo->prepare('
      INSERT INTO student_holds (student_id, hold_type, note, is_active)
      VALUES (?, ?, ?, 1)
    ');
    $stmt->execute([
        $studentId,
        $holdType,
        $note !== '' ? substr($note, 0, 500) : null,
    ]);

    audit_admin($pdo, 'hold_add', 'student_id=' . $studentId . ';type=' . $holdType);

    redirect('/admin.php?view=people&id=' . $studentId . '&people_panel=hold');
}

function handler_admin_holds_clear(array $params): void
{
    auth_require_hold_manager();
    csrf_require_valid();

    $holdId = isset($_POST['hold_id']) && ctype_digit((string)$_POST['hold_id']) ? (int)$_POST['hold_id'] : null;
    $studentId = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;

    if ($holdId === null || $studentId === null) {
        redirect('/admin.php?view=holds');
    }

    $pdo = db();
    $stmt = $pdo->prepare('
      UPDATE student_holds
      SET is_active = 0, cleared_at = CURRENT_TIMESTAMP
      WHERE hold_id = ? AND student_id = ? AND is_active = 1
    ');
    $stmt->execute([$holdId, $studentId]);

    audit_admin($pdo, 'hold_clear', 'student_id=' . $studentId . ';hold_id=' . $holdId);

    redirect('/admin.php?view=people&id=' . $studentId . '&people_panel=hold');
}
