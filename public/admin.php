<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/bootstrap.php';
bootstrap_app();
require __DIR__ . '/../app/lib/url.php';
require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/../app/lib/admin_term_policy.php';
require __DIR__ . '/../app/lib/auth.php';
require __DIR__ . '/../app/lib/ui.php';
require __DIR__ . '/../app/lib/csrf.php';
require_once __DIR__ . '/../app/lib/northbridge_email.php';
require_once __DIR__ . '/../app/lib/us_student_phone.php';
require_once __DIR__ . '/../app/lib/people_validate.php';

header('Content-Type: text/html; charset=utf-8');

auth_start_session();
auth_require_portal_user();

$user = auth_portal_display_name();
$currentAuthId = (int)($_SESSION['auth']['id'] ?? 0);
$isAdmin = auth_is_admin();
$isLimited = auth_is_limited();
$isViewer = auth_is_viewer();
$roleLabel = $isViewer ? 'Viewer' : ($isLimited ? 'Limited Admin' : 'Admin');
$roleNote = 'Admin: full access, including grades.';
if ($isViewer) {
    $roleNote = 'Viewer: browse only. No add/drop or hold changes.';
} elseif ($isLimited) {
    $roleNote = 'Limited: holds and registration add/drop. No grade entry.';
} elseif (!auth_can_post_grades()) {
    $roleNote = 'Admin: same tools as full admin, except entering grades.';
}
$canRegister = auth_can_manage_registration();
$canManageHolds = auth_can_manage_holds();
$canPostGrades = auth_can_post_grades();

$csrf = csrf_token();
$pageTitle = 'Administration — Ashford College';
$pdo = db();
$appCfg = (array)config('app');
$defaultMaxCredits = (int)(($appCfg['registration']['default_max_credits'] ?? 18));
if ($defaultMaxCredits < 1) {
    $defaultMaxCredits = 18;
}

$view = (string)($_GET['view'] ?? 'dashboard');
$validViews = ['dashboard', 'people', 'schedule', 'courses', 'course', 'enrollment', 'departments', 'department', 'registration', 'reports', 'messages', 'settings', 'catalog', 'terms', 'holds', 'accounts', 'team'];
if (!in_array($view, $validViews, true)) {
    $view = 'dashboard';
}

$peopleIdRaw = trim((string)($_GET['id'] ?? ''));
$peopleId = ctype_digit($peopleIdRaw) ? (int)$peopleIdRaw : null;

$currentTerm = null;
$currentTermCode = null;
$currentTermId = null;
try {
    $currentTerm = $pdo->query('SELECT term_id, code, name FROM terms ORDER BY start_date DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($currentTerm) {
        $currentTermCode = (string)$currentTerm['code'];
        $currentTermId = (int)$currentTerm['term_id'];
    }
} catch (Throwable) {
}

function admin_audit(PDO $pdo, string $action, string $details): void
{
    auth_start_session();
    $aid = (int)($_SESSION['auth']['id'] ?? 0);
    if ($aid < 1) {
        return;
    }
    try {
        $pdo->prepare('INSERT INTO admin_audit_log (admin_auth_id, action, details) VALUES (?, ?, ?)')->execute([$aid, $action, $details]);
    } catch (Throwable) {
    }
}

function schedule_conflicts(?string $daysA, ?string $timeA, ?string $daysB, ?string $timeB): bool
{
    $daysA = strtoupper(trim((string)$daysA));
    $daysB = strtoupper(trim((string)$daysB));
    $timeA = trim((string)$timeA);
    $timeB = trim((string)$timeB);
    if ($daysA === '' || $daysB === '' || $timeA === '' || $timeB === '') {
        return false;
    }
    $setA = array_unique(str_split(preg_replace('/[^MTWRFSU]/', '', $daysA) ?? ''));
    $setB = array_unique(str_split(preg_replace('/[^MTWRFSU]/', '', $daysB) ?? ''));
    if (!$setA || !$setB || !array_intersect($setA, $setB)) {
        return false;
    }
    $parse = static function (string $t): ?array {
        if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $t, $m)) {
            return null;
        }
        $s = ((int)$m[1]) * 60 + (int)$m[2];
        $e = ((int)$m[3]) * 60 + (int)$m[4];

        return $e <= $s ? null : [$s, $e];
    };
    $a = $parse($timeA);
    $b = $parse($timeB);

    return $a !== null && $b !== null && $a[0] < $b[1] && $b[0] < $a[1];
}

function schedule_time_valid(?string $time): bool
{
    $time = trim((string)$time);
    if ($time === '') {
        return false;
    }
    if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $time, $m)) {
        return false;
    }
    $s = ((int)$m[1]) * 60 + (int)$m[2];
    $e = ((int)$m[3]) * 60 + (int)$m[4];

    return $e > $s;
}

function schedule_days_valid(?string $days): bool
{
    $days = strtoupper(trim((string)$days));
    if ($days === '') {
        return false;
    }

    return (bool)preg_match('/^[MTWRFSU]+$/', preg_replace('/\s+/', '', $days) ?? '');
}

/**
 * @return array{section_id: int, course_id: string, reason: string}|null
 */
function admin_find_section_schedule_conflict(
    PDO $pdo,
    int $termId,
    ?string $meetingDays,
    ?string $meetingTime,
    ?string $room,
    ?int $facultyId,
    ?int $excludeSectionId = null
): ?array {
    $meetingDays = trim((string)$meetingDays);
    $meetingTime = trim((string)$meetingTime);
    $room = trim((string)$room);
    if ($meetingDays === '' || $meetingTime === '') {
        return null;
    }

    $checks = [];
    if ($facultyId !== null && $facultyId > 0) {
        $checks[] = ['faculty', 'SELECT s.section_id, s.course_id, s.meeting_days, s.meeting_time FROM sections s WHERE s.term_id = ? AND s.faculty_id = ?', [$termId, $facultyId]];
    }
    if ($room !== '') {
        $checks[] = ['room', 'SELECT s.section_id, s.course_id, s.meeting_days, s.meeting_time FROM sections s WHERE s.term_id = ? AND TRIM(COALESCE(s.room, "")) = ?', [$termId, $room]];
    }

    foreach ($checks as [$reason, $sql, $params]) {
        if ($excludeSectionId !== null && $excludeSectionId > 0) {
            $sql .= ' AND s.section_id <> ?';
            $params[] = $excludeSectionId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (schedule_conflicts($meetingDays, $meetingTime, $row['meeting_days'] ?? null, $row['meeting_time'] ?? null)) {
                return [
                    'section_id' => (int)$row['section_id'],
                    'course_id' => (string)($row['course_id'] ?? ''),
                    'reason' => $reason,
                ];
            }
        }
    }

    return null;
}

// POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($isViewer) {
        header('Location: ' . url('/admin.php?view=' . rawurlencode($view) . '&msg=readonly'));
        exit;
    }
    if (!$canPostGrades && in_array((string)($_POST['action'] ?? ''), ['grade_upsert', 'people_scr_upsert'], true)) {
        header('Location: ' . url('/admin.php?view=' . rawurlencode($view) . '&msg=forbidden'));
        exit;
    }
    if ($isLimited) {
        $blocked = ['grade_upsert', 'people_scr_upsert', 'people_create', 'catalog_course_save', 'catalog_prereqs_save', 'section_save', 'section_update', 'department_save', 'department_chair_save', 'term_registration_save', 'auth_password_reset', 'auth_login_save', 'auth_email_save', 'auth_user_active', 'reg_promote'];
        $act = (string)($_POST['action'] ?? '');
        if (in_array($act, $blocked, true)) {
            header('Location: ' . url('/admin.php?view=' . rawurlencode($view) . '&msg=forbidden'));
            exit;
        }
    }

    csrf_require_valid();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'hold_clear' && $canManageHolds) {
        $holdId = isset($_POST['hold_id']) && ctype_digit((string)$_POST['hold_id']) ? (int)$_POST['hold_id'] : null;
        $sid = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        if ($holdId !== null && $sid !== null) {
            $pdo->prepare('UPDATE student_holds SET is_active = 0, cleared_at = CURRENT_TIMESTAMP WHERE hold_id = ? AND student_id = ? AND is_active = 1')->execute([$holdId, $sid]);
        }
        header('Location: ' . url('/admin.php?view=people&id=' . ($sid ?? '') . '&people_panel=hold'));
        exit;
    }
    if ($action === 'hold_clear_people' && $canManageHolds) {
        $holdId = isset($_POST['hold_id']) && ctype_digit((string)$_POST['hold_id']) ? (int)$_POST['hold_id'] : null;
        $sid = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        if ($holdId !== null && $sid !== null) {
            $pdo->prepare('UPDATE student_holds SET is_active = 0, cleared_at = CURRENT_TIMESTAMP WHERE hold_id = ? AND student_id = ? AND is_active = 1')->execute([$holdId, $sid]);
        }
        header('Location: ' . url('/admin.php?view=people&id=' . ($sid ?? '') . '&people_panel=hold'));
        exit;
    }
    if ($action === 'hold_add_people' && $canManageHolds) {
        $sid = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $presets = admin_people_hold_presets();
        $sel = trim((string)($_POST['hold_type_select'] ?? ''));
        $custom = trim((string)($_POST['hold_type_custom'] ?? ''));
        $type = '';
        if ($sel === '__custom__') {
            $type = $custom;
        } elseif ($sel !== '' && in_array($sel, $presets, true)) {
            $type = $sel;
        }
        $note = trim((string)($_POST['note'] ?? ''));
        if ($sid !== null && $type !== '') {
            $pdo->prepare('INSERT INTO student_holds (student_id, hold_type, note, is_active) VALUES (?, ?, ?, 1)')->execute([$sid, $type, $note !== '' ? $note : null]);
            admin_audit($pdo, 'hold_add', 'student_id=' . $sid);
            header('Location: ' . url('/admin.php?view=people&id=' . $sid . '&people_panel=hold&msg=hold_added'));
            exit;
        }
        header('Location: ' . url('/admin.php?view=people&id=' . ($sid ?? '') . '&people_panel=hold'));
        exit;
    }

    if ($action === 'people_update_student' && $canManageHolds) {
        $sid = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $redirectId = $sid ?? 0;
        if ($sid === null) {
            header('Location: ' . url('/admin.php?view=people&people_panel=info'));
            exit;
        }
        $stuOk = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ? LIMIT 1');
        $stuOk->execute([$sid]);
        if (!$stuOk->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=forbidden'));
            exit;
        }
        $genderIn = trim((string)($_POST['gender'] ?? ''));
        $stateRaw = trim((string)($_POST['state'] ?? ''));
        $stateIn = strtoupper($stateRaw);
        $emailIn = trim((string)($_POST['email'] ?? ''));
        $phoneIn = trim((string)($_POST['phone'] ?? ''));
        $aptIn = trim((string)($_POST['apt_no'] ?? ''));
        $streetIn = trim((string)($_POST['street'] ?? ''));
        $cityIn = trim((string)($_POST['city'] ?? ''));
        $zipIn = trim((string)($_POST['zip_code'] ?? ''));
        $sets = [];
        $params = [];
        $inputErr = false;
        $allowedG = admin_people_genders();
        if ($genderIn !== '' && $genderIn !== '__keep__' && in_array($genderIn, $allowedG, true)) {
            $sets[] = 'gender = ?';
            $params[] = $genderIn;
        }
        $stateCodes = admin_people_us_state_codes();
        if ($stateRaw !== '' && strcasecmp($stateRaw, '__keep__') !== 0 && in_array($stateIn, $stateCodes, true)) {
            $sets[] = 'state = ?';
            $params[] = $stateIn;
        }
        if ($emailIn !== '') {
            if (northbridge_email_is_school($emailIn)) {
                $sets[] = 'email = ?';
                $params[] = strtolower($emailIn);
            } else {
                $inputErr = true;
            }
        }
        if ($phoneIn !== '') {
            $phoneNorm = northbridge_normalize_us_phone($phoneIn);
            if ($phoneNorm !== null) {
                $sets[] = 'phone_number = ?';
                $params[] = $phoneNorm;
            } else {
                $inputErr = true;
            }
        }
        foreach (['apt_no' => $aptIn, 'street' => $streetIn, 'city' => $cityIn, 'zip_code' => $zipIn] as $col => $val) {
            if ($val !== '') {
                $sets[] = $col . ' = ?';
                $params[] = $val;
            }
        }
        if ($inputErr) {
            header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_invalid'));
            exit;
        }
        if ($sets !== []) {
            $params[] = $sid;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($params);
        }

        $years = admin_people_academic_year_levels();
        $ints = admin_people_enrollment_intensity_options();
        $ayIn = trim((string)($_POST['academic_year_level'] ?? ''));
        $eiIn = trim((string)($_POST['enrollment_intensity'] ?? ''));
        $newAy = null;
        $newEi = null;
        if ($ayIn !== '' && $ayIn !== '__keep__' && in_array($ayIn, $years, true)) {
            $newAy = $ayIn;
        }
        if ($eiIn !== '' && $eiIn !== '__keep__' && in_array($eiIn, $ints, true)) {
            $newEi = $eiIn;
        }
        $ugRow = $pdo->prepare('SELECT student_id, student_type FROM undergrad_students WHERE student_id = ? LIMIT 1');
        $ugRow->execute([$sid]);
        $ugEx = $ugRow->fetch(PDO::FETCH_ASSOC);
        if ($ugEx) {
            $uq = [];
            $up = [];
            if ($newAy !== null) {
                $uq[] = 'academic_year_level = ?';
                $up[] = $newAy;
            }
            if ($newEi !== null) {
                $uq[] = 'enrollment_intensity = ?';
                $up[] = $newEi;
            }
            if ($uq !== []) {
                $up[] = $sid;
                $pdo->prepare('UPDATE undergrad_students SET ' . implode(', ', $uq) . ' WHERE student_id = ?')->execute($up);
            }
        } elseif ($newAy !== null || $newEi !== null) {
            $pdo->prepare('INSERT INTO undergrad_students (student_id, student_type, academic_year_level, enrollment_intensity) VALUES (?, ?, ?, ?)')->execute([
                $sid,
                'Unknown',
                $newAy,
                $newEi,
            ]);
        }

        $roles = $_POST['declaration_role'] ?? [];
        $addDept = trim((string)($_POST['add_declaration_dept'] ?? ''));
        $addRole = trim((string)($_POST['add_declaration_role'] ?? ''));
        $addRole = ($addRole === 'major' || $addRole === 'minor') ? $addRole : '';
        $removeMajor = !empty($_POST['remove_major']);
        $removeMinor = !empty($_POST['remove_minor']);
        $overrideDeclApproval = $isAdmin && !empty($_POST['override_decl_approval']);
        $overrideMajorLimit = $isAdmin && !empty($_POST['override_major_limit']);
        $overrideFinalSemester = $isAdmin && !empty($_POST['override_final_semester']);
        $overrideGpa = $isAdmin && !empty($_POST['override_gpa']);

        $pdo->beginTransaction();
        try {
            $existing = [];
            $st = $pdo->prepare('SELECT dept_id, declaration_role FROM student_departments WHERE student_id = ?');
            $st->execute([$sid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $did = trim((string)($r['dept_id'] ?? ''));
                if ($did === '') {
                    continue;
                }
                $role = trim((string)($r['declaration_role'] ?? 'major'));
                $existing[$did] = ($role === 'minor') ? 'minor' : 'major';
            }

            $final = $existing;

            $oldMajorDept = null;
            $oldMinorDept = null;
            foreach ($existing as $did => $r) {
                if ($r === 'major') {
                    $oldMajorDept = $did;
                } elseif ($r === 'minor') {
                    $oldMinorDept = $did;
                }
            }

            if (is_array($roles)) {
                foreach ($roles as $deptId => $role) {
                    $deptId = trim((string)$deptId);
                    $role = trim((string)$role);
                    if ($deptId === '' || ($role !== 'major' && $role !== 'minor')) {
                        continue;
                    }
                    if (!array_key_exists($deptId, $final)) {
                        continue;
                    }
                    $final[$deptId] = $role;
                }
            }

            if ($removeMajor && $oldMajorDept !== null) {
                unset($final[$oldMajorDept]);
            }
            if ($removeMinor && $oldMinorDept !== null) {
                unset($final[$oldMinorDept]);
            }

            $pendingInsert = null;
            if ($addDept !== '' && $addRole !== '') {
                $dchk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ? LIMIT 1');
                $dchk->execute([$addDept]);
                if ($dchk->fetchColumn() && !array_key_exists($addDept, $final)) {
                    $final[$addDept] = $addRole;
                    $pendingInsert = [$addDept, $addRole];
                }
            }

            $newMajorDept = null;
            $newMinorDept = null;
            foreach ($final as $did => $r) {
                if ($r === 'major') {
                    $newMajorDept = $did;
                } elseif ($r === 'minor') {
                    $newMinorDept = $did;
                }
            }

            $majorChanged = $oldMajorDept !== $newMajorDept;
            $minorChanged = $oldMinorDept !== $newMinorDept;

            if (($majorChanged || $minorChanged) && !$isAdmin && !$overrideDeclApproval) {
                $pdo->rollBack();
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=forbidden'));
                exit;
            }

            if ($newMajorDept !== null && $newMinorDept !== null && $newMajorDept === $newMinorDept) {
                $pdo->rollBack();
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_conflict'));
                exit;
            }

            if ($majorChanged && $newMajorDept !== null && $oldMinorDept !== null && $newMajorDept === $oldMinorDept) {
                $pdo->rollBack();
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_same_as_other'));
                exit;
            }
            if ($minorChanged && $newMinorDept !== null && $oldMajorDept !== null && $newMinorDept === $oldMajorDept) {
                $pdo->rollBack();
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_same_as_other'));
                exit;
            }

            if (($majorChanged || $minorChanged) && !$overrideFinalSemester) {
                try {
                    $ay = $pdo->prepare('SELECT academic_year_level FROM undergrad_students WHERE student_id = ? LIMIT 1');
                    $ay->execute([$sid]);
                    $lvl = trim((string)($ay->fetchColumn() ?: ''));
                    if (strcasecmp($lvl, 'Senior') === 0) {
                        $pdo->rollBack();
                        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_final_sem'));
                        exit;
                    }
                } catch (Throwable) {
                }
            }

            if ($majorChanged && $newMajorDept !== null && !$overrideGpa) {
                try {
                    $gpaStmt = $pdo->prepare('
                      SELECT
                        COALESCE(SUM(grade_points * credits_earned), 0) AS quality,
                        COALESCE(SUM(CASE WHEN credits_earned > 0 THEN credits_earned ELSE 0 END), 0) AS cr
                      FROM student_course_results
                      WHERE student_id = ?
                    ');
                    $gpaStmt->execute([$sid]);
                    $gpaRow = $gpaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $qc = isset($gpaRow['quality']) ? (float)$gpaRow['quality'] : 0.0;
                    $credSum = isset($gpaRow['cr']) ? (float)$gpaRow['cr'] : 0.0;
                    $gpa = ($credSum > 0.0) ? round($qc / $credSum, 2) : null;
                    if ($gpa !== null && $gpa < 2.0) {
                        $pdo->rollBack();
                        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_gpa'));
                        exit;
                    }
                } catch (Throwable) {
                }
            }

            if ($majorChanged && !$overrideMajorLimit) {
                try {
                    $cs = $pdo->prepare('SELECT major_change_count FROM student_major_change_stats WHERE student_id = ? LIMIT 1');
                    $cs->execute([$sid]);
                    $cnt = $cs->fetchColumn();
                    $curCnt = ($cnt !== false && $cnt !== null && is_numeric($cnt)) ? (int)$cnt : 0;
                    if ($curCnt >= 3) {
                        $pdo->rollBack();
                        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_limit3'));
                        exit;
                    }
                } catch (Throwable) {
                }
            }

            $maj = 0;
            $min = 0;
            foreach ($final as $r) {
                if ($r === 'major') {
                    $maj++;
                } elseif ($r === 'minor') {
                    $min++;
                }
            }
            if ($maj > 1 || $min > 1) {
                $pdo->rollBack();
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_limit'));
                exit;
            }

            if ($final !== $existing) {
                $upd = $pdo->prepare('UPDATE student_departments SET declaration_role = ? WHERE student_id = ? AND dept_id = ?');
                foreach ($final as $deptId => $role) {
                    if (!array_key_exists($deptId, $existing)) {
                        continue;
                    }
                    if ($existing[$deptId] !== $role) {
                        $upd->execute([$role, $sid, $deptId]);
                    }
                }
            }
            if ($pendingInsert) {
                [$did, $role] = $pendingInsert;
                $pdo->prepare('INSERT INTO student_departments (student_id, dept_id, declaration_role, date_of_declaration) VALUES (?, ?, ?, CURDATE())')->execute([$sid, $did, $role]);
            }

            if ($removeMajor && $oldMajorDept !== null) {
                $pdo->prepare('DELETE FROM student_departments WHERE student_id = ? AND dept_id = ?')->execute([$sid, $oldMajorDept]);
            }
            if ($removeMinor && $oldMinorDept !== null) {
                $pdo->prepare('DELETE FROM student_departments WHERE student_id = ? AND dept_id = ?')->execute([$sid, $oldMinorDept]);
            }

            if ($majorChanged) {
                try {
                    $pdo->prepare('
                      INSERT INTO student_declaration_change_log (student_id, change_kind, old_dept_id, new_dept_id, actor_kind, actor_auth_id, note)
                      VALUES (?, "major", ?, ?, "admin", ?, ?)
                    ')->execute([$sid, $oldMajorDept, $newMajorDept, (int)($_SESSION['auth']['id'] ?? 0), $overrideMajorLimit ? 'override_limit' : null]);
                } catch (Throwable) {
                }
                try {
                    $pdo->prepare('
                      INSERT INTO student_major_change_stats (student_id, major_change_count, last_changed_at)
                      VALUES (?, 1, CURRENT_TIMESTAMP)
                      ON DUPLICATE KEY UPDATE major_change_count = major_change_count + 1, last_changed_at = CURRENT_TIMESTAMP
                    ')->execute([$sid]);
                } catch (Throwable) {
                }
            }
            if ($minorChanged) {
                try {
                    $pdo->prepare('
                      INSERT INTO student_declaration_change_log (student_id, change_kind, old_dept_id, new_dept_id, actor_kind, actor_auth_id)
                      VALUES (?, "minor", ?, ?, "admin", ?)
                    ')->execute([$sid, $oldMinorDept, $newMinorDept, (int)($_SESSION['auth']['id'] ?? 0)]);
                } catch (Throwable) {
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=decl_limit'));
                exit;
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($isAdmin) {
            $tceRaw = trim((string)($_POST['total_credit_earned'] ?? ''));
            $mxRaw = trim((string)($_POST['max_credit'] ?? ''));
            $mnRaw = trim((string)($_POST['min_credit'] ?? ''));
            $tceVal = ($tceRaw !== '' && ctype_digit($tceRaw)) ? max(0, (int)$tceRaw) : null;
            $mxVal = ($mxRaw !== '' && ctype_digit($mxRaw)) ? (int)$mxRaw : null;
            $mnVal = ($mnRaw !== '' && ctype_digit($mnRaw)) ? (int)$mnRaw : null;
            if ($mxVal !== null && ($mxVal < 1 || $mxVal > 40)) {
                $mxVal = null;
            }
            if ($mnVal !== null && ($mnVal < 1 || $mnVal > 40)) {
                $mnVal = null;
            }
            $hasUgPosted = $tceVal !== null || $mxVal !== null || $mnVal !== null;
            if ($hasUgPosted && $mxVal !== null && $mnVal !== null && $mnVal > $mxVal) {
                header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_invalid'));
                exit;
            }

            $hasLimStmt = $pdo->prepare('SELECT max_credit, min_credit FROM ug_credit_limits WHERE student_id = ? LIMIT 1');
            $hasLimStmt->execute([$sid]);
            $existingLim = $hasLimStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingLim) {
                $nextMx = $mxVal ?? (int)($existingLim['max_credit'] ?? 18);
                $nextMn = $mnVal ?? (int)($existingLim['min_credit'] ?? 12);
                if ($mxVal !== null || $mnVal !== null) {
                    if ($nextMn > $nextMx) {
                        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_invalid'));
                        exit;
                    }
                }
                $sets = [];
                $params = [];
                if ($tceVal !== null) {
                    $sets[] = 'total_credit_earned = ?';
                    $params[] = $tceVal;
                }
                if ($mxVal !== null) {
                    $sets[] = 'max_credit = ?';
                    $params[] = $mxVal;
                }
                if ($mnVal !== null) {
                    $sets[] = 'min_credit = ?';
                    $params[] = $mnVal;
                }
                if ($sets !== []) {
                    $params[] = $sid;
                    $pdo->prepare('UPDATE ug_credit_limits SET ' . implode(', ', $sets) . ' WHERE student_id = ?')->execute($params);
                }
            } elseif ($hasUgPosted) {
                $stLabel = ($ugEx && !empty($ugEx['student_type'])) ? (string)$ugEx['student_type'] : 'Unknown';
                $yr = (int)date('Y');
                $band = admin_ug_credit_band_from_student_type($stLabel);
                $finalMx = $mxVal ?? $band['max_credit'];
                $finalMn = $mnVal ?? $band['min_credit'];
                if ($finalMn > $finalMx) {
                    header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_invalid'));
                    exit;
                }
                $finalTce = $tceVal ?? 0;
                $pdo->prepare('INSERT INTO ug_credit_limits (student_id, student_type, year, max_credit, min_credit, total_credit_earned) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                    $sid, $stLabel, $yr, $finalMx, $finalMn, $finalTce,
                ]);
            }
        }

        admin_audit($pdo, 'people_update_student', 'student_id=' . $sid);
        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_saved'));
        exit;
    }

    if ($action === 'people_update_faculty' && $canManageHolds) {
        $fid = isset($_POST['faculty_id']) && ctype_digit((string)$_POST['faculty_id']) ? (int)$_POST['faculty_id'] : null;
        $redirectId = $fid ?? 0;
        if ($fid === null) {
            header('Location: ' . url('/admin.php?view=people&people_panel=info'));
            exit;
        }
        $facOk = $pdo->prepare('SELECT 1 FROM faculty WHERE faculty_id = ? LIMIT 1');
        $facOk->execute([$fid]);
        if (!$facOk->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=forbidden'));
            exit;
        }
        $genderIn = trim((string)($_POST['gender'] ?? ''));
        $stateRaw = trim((string)($_POST['state'] ?? ''));
        $stateIn = strtoupper($stateRaw);
        $emailIn = trim((string)($_POST['email'] ?? ''));
        $phoneIn = trim((string)($_POST['phone'] ?? ''));
        $aptIn = trim((string)($_POST['apt_no'] ?? ''));
        $streetIn = trim((string)($_POST['street'] ?? ''));
        $cityIn = trim((string)($_POST['city'] ?? ''));
        $zipIn = trim((string)($_POST['zip_code'] ?? ''));
        $officeIn = trim((string)($_POST['office_number'] ?? ''));
        $inputErr = false;
        $setsU = [];
        $paramsU = [];
        $allowedG = admin_people_genders();
        if ($genderIn !== '' && $genderIn !== '__keep__' && in_array($genderIn, $allowedG, true)) {
            $setsU[] = 'gender = ?';
            $paramsU[] = $genderIn;
        }
        $stateCodes = admin_people_us_state_codes();
        if ($stateRaw !== '' && strcasecmp($stateRaw, '__keep__') !== 0 && in_array($stateIn, $stateCodes, true)) {
            $setsU[] = 'state = ?';
            $paramsU[] = $stateIn;
        }
        if ($emailIn !== '') {
            if (northbridge_email_is_school($emailIn)) {
                $setsU[] = 'email = ?';
                $paramsU[] = strtolower($emailIn);
            } else {
                $inputErr = true;
            }
        }
        $phoneNormFac = null;
        if ($phoneIn !== '') {
            $phoneNormFac = northbridge_normalize_us_phone($phoneIn);
            if ($phoneNormFac !== null) {
                $setsU[] = 'phone_number = ?';
                $paramsU[] = $phoneNormFac;
            } else {
                $inputErr = true;
            }
        }
        foreach (['apt_no' => $aptIn, 'street' => $streetIn, 'city' => $cityIn, 'zip_code' => $zipIn] as $col => $val) {
            if ($val !== '') {
                $setsU[] = $col . ' = ?';
                $paramsU[] = $val;
            }
        }
        if ($inputErr) {
            header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=profile_invalid'));
            exit;
        }
        if ($setsU !== []) {
            $paramsU[] = $fid;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $setsU) . ' WHERE user_id = ?')->execute($paramsU);
        }
        $setsF = [];
        $paramsF = [];
        if ($emailIn !== '' && northbridge_email_is_school($emailIn)) {
            $setsF[] = 'email = ?';
            $paramsF[] = strtolower($emailIn);
        }
        if ($phoneNormFac !== null) {
            $setsF[] = 'phone_number = ?';
            $paramsF[] = $phoneNormFac;
        }
        if ($officeIn !== '') {
            $setsF[] = 'office_number = ?';
            $paramsF[] = $officeIn;
        }
        if ($setsF !== []) {
            $paramsF[] = $fid;
            $pdo->prepare('UPDATE faculty SET ' . implode(', ', $setsF) . ' WHERE faculty_id = ?')->execute($paramsF);
        }
        admin_audit($pdo, 'people_update_faculty', 'faculty_id=' . $fid);
        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=faculty_saved'));
        exit;
    }

    if ($action === 'people_create' && $isAdmin) {
        $personType = trim((string)($_POST['person_type'] ?? ''));
        $uidRaw = trim((string)($_POST['user_id'] ?? ''));
        $first = trim((string)($_POST['first_name'] ?? ''));
        $middle = trim((string)($_POST['middle_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $emailIn = trim((string)($_POST['email'] ?? ''));
        $phoneIn = trim((string)($_POST['phone'] ?? ''));
        $genderIn = trim((string)($_POST['gender'] ?? ''));
        $dobIn = trim((string)($_POST['dob'] ?? ''));
        $deptId = strtoupper(trim((string)($_POST['dept_id'] ?? '')));
        $officeIn = trim((string)($_POST['office_number'] ?? ''));
        $rankIn = trim((string)($_POST['rank'] ?? ''));
        $facTypeIn = trim((string)($_POST['faculty_type'] ?? ''));
        $stuTypeIn = trim((string)($_POST['student_type'] ?? ''));
        $streetIn = trim((string)($_POST['street'] ?? ''));
        $cityIn = trim((string)($_POST['city'] ?? ''));
        $stateIn = strtoupper(trim((string)($_POST['state'] ?? '')));
        $zipIn = trim((string)($_POST['zip_code'] ?? ''));

        $failRedirect = url('/admin.php?view=people&msg=person_invalid#add-person');

        if (!in_array($personType, ['Student', 'Faculty'], true)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        if ($uidRaw === '' || !ctype_digit($uidRaw) || strlen($uidRaw) !== 7) {
            header('Location: ' . $failRedirect);
            exit;
        }
        $uid = (int)$uidRaw;
        if (!people_id_is_valid_for_type($uid, $personType)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        if (!people_name_is_valid($first) || !people_name_is_valid($last) || !people_name_is_valid($middle, true)) {
            header('Location: ' . $failRedirect);
            exit;
        }

        $exists = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ? LIMIT 1');
        $exists->execute([$uid]);
        if ($exists->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=people&msg=person_exists#add-person'));
            exit;
        }

        if ($emailIn === '' || !northbridge_email_is_school($emailIn)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        $email = strtolower($emailIn);
        if (northbridge_school_email_in_use($pdo, $email, $uid)) {
            header('Location: ' . url('/admin.php?view=people&msg=person_email_taken#add-person'));
            exit;
        }

        $phoneNorm = northbridge_normalize_us_phone($phoneIn);
        if ($phoneNorm === null || !people_phone_area_code_allowed($phoneNorm)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        $phone = $phoneNorm;

        $allowedG = admin_people_genders();
        if ($genderIn === '' || !in_array($genderIn, $allowedG, true)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        $gender = $genderIn;

        $dob = null;
        if (!people_dob_is_valid($dobIn, $dob)) {
            header('Location: ' . $failRedirect);
            exit;
        }

        if ($deptId === '') {
            header('Location: ' . $failRedirect);
            exit;
        }
        $dchk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ? LIMIT 1');
        $dchk->execute([$deptId]);
        if (!$dchk->fetchColumn()) {
            header('Location: ' . $failRedirect);
            exit;
        }
        $deptOk = $deptId;

        if ($personType === 'Student') {
            if (!in_array($stuTypeIn, ['Fulltime', 'Parttime'], true)) {
                header('Location: ' . $failRedirect);
                exit;
            }
        } else {
            if ($rankIn === '' || !in_array($rankIn, ['Adjunct', 'Non-Tenured', 'Tenured'], true)) {
                header('Location: ' . $failRedirect);
                exit;
            }
            if ($facTypeIn === '' || !in_array($facTypeIn, ['Fulltime', 'Parttime'], true)) {
                header('Location: ' . $failRedirect);
                exit;
            }
            if (!people_office_is_valid($officeIn, true)) {
                header('Location: ' . $failRedirect);
                exit;
            }
        }

        if ($stateIn !== '' && !in_array($stateIn, admin_people_us_state_codes(), true)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        if (!people_us_zip_is_valid($zipIn, true)) {
            header('Location: ' . $failRedirect);
            exit;
        }
        if (strlen($streetIn) > 200 || strlen($cityIn) > 120) {
            header('Location: ' . $failRedirect);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('
              INSERT INTO users (
                user_id, first_name, middle_name, last_name,
                street, city, state, zip_code,
                email, phone_number, gender, dob, user_type
              ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $uid,
                $first,
                $middle !== '' ? $middle : null,
                $last,
                $streetIn !== '' ? $streetIn : null,
                $cityIn !== '' ? $cityIn : null,
                $stateIn !== '' ? $stateIn : null,
                $zipIn !== '' ? $zipIn : null,
                $email,
                $phone,
                $gender,
                $dob,
                $personType,
            ]);

            if ($personType === 'Student') {
                $pdo->prepare('INSERT INTO students (student_id) VALUES (?)')->execute([$uid]);
                try {
                    $pdo->prepare('
                      INSERT INTO undergrad_students (student_id, student_type)
                      VALUES (?, ?)
                    ')->execute([$uid, $stuTypeIn]);
                } catch (Throwable) {
                }
                try {
                    $pdo->prepare('
                      INSERT INTO student_departments (student_id, dept_id, declaration_role, date_of_declaration)
                      VALUES (?, ?, \'major\', CURDATE())
                    ')->execute([$uid, $deptOk]);
                } catch (Throwable) {
                    $pdo->prepare('
                      INSERT INTO student_departments (student_id, dept_id, date_of_declaration)
                      VALUES (?, ?, CURDATE())
                    ')->execute([$uid, $deptOk]);
                }
            } else {
                $pdo->prepare('
                  INSERT INTO faculty (faculty_id, office_number, `rank`, faculty_type, email, phone_number)
                  VALUES (?, ?, ?, ?, ?, ?)
                ')->execute([
                    $uid,
                    $officeIn !== '' ? $officeIn : null,
                    $rankIn,
                    $facTypeIn,
                    $email,
                    $phone,
                ]);
                $pdo->prepare('
                  INSERT INTO faculty_departments (faculty_id, dept_id, percent_time, date_of_appointment)
                  VALUES (?, ?, 100, CURDATE())
                ')->execute([$uid, $deptOk]);
            }

            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: ' . $failRedirect);
            exit;
        }

        admin_audit($pdo, 'people_create', $personType . ' user_id=' . $uid);
        header('Location: ' . url('/admin.php?view=people&id=' . $uid . '&people_panel=info&msg=person_created'));
        exit;
    }

    if ($action === 'people_scr_upsert' && $canPostGrades) {
        $sid = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $redirectId = $sid ?? 0;
        if ($sid === null) {
            header('Location: ' . url('/admin.php?view=people&people_panel=info'));
            exit;
        }
        $courseId = strtoupper(trim((string)($_POST['course_id'] ?? '')));
        $termId = isset($_POST['term_id']) && ctype_digit((string)$_POST['term_id']) ? (int)$_POST['term_id'] : null;
        $letter = strtoupper(trim((string)($_POST['letter_grade'] ?? '')));
        $gpRaw = trim((string)($_POST['grade_points'] ?? ''));
        if ($sid && $courseId !== '' && $termId !== null && $letter !== '') {
            $cchk = $pdo->prepare('SELECT credits FROM courses WHERE course_id = ? LIMIT 1');
            $cchk->execute([$courseId]);
            $crRow = $cchk->fetch(PDO::FETCH_ASSOC);
            $tchk = $pdo->prepare('SELECT 1 FROM terms WHERE term_id = ? LIMIT 1');
            $tchk->execute([$termId]);
            $stuOk = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ? LIMIT 1');
            $stuOk->execute([$sid]);
            if ($crRow && $tchk->fetchColumn() && $stuOk->fetchColumn()) {
                $courseCredits = (int)($crRow['credits'] ?? 0);
                $gp = admin_grade_points_from_letter($letter);
                if ($gp === null && $gpRaw !== '' && is_numeric($gpRaw)) {
                    $gp = round((float)$gpRaw, 2);
                }
                if ($gp !== null) {
                    $creditsEarned = ($gp > 0.0) ? $courseCredits : 0;
                    $pdo->prepare('
                      INSERT INTO student_course_results (student_id, course_id, term_id, letter_grade, grade_points, credits_earned)
                      VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE letter_grade = VALUES(letter_grade), grade_points = VALUES(grade_points), credits_earned = VALUES(credits_earned)
                    ')->execute([$sid, $courseId, $termId, $letter, $gp, $creditsEarned]);
                    admin_audit($pdo, 'people_scr_upsert', 'student_id=' . $sid . ' course=' . $courseId);
                    header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=grade_saved'));
                    exit;
                }
            }
        }
        header('Location: ' . url('/admin.php?view=people&id=' . $redirectId . '&people_panel=info&msg=grade_invalid'));
        exit;
    }

    if ($action === 'department_chair_save' && $isAdmin) {
        $deptId = strtoupper(trim((string)($_POST['dept_id'] ?? '')));
        $chairRaw = trim((string)($_POST['chair_id'] ?? ''));
        $redirect = url('/admin.php?' . http_build_query(['view' => 'department', 'dept_id' => $deptId]));

        if ($deptId === '') {
            header('Location: ' . url('/admin.php?view=departments&msg=chair_invalid'));
            exit;
        }

        $dchk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ? LIMIT 1');
        $dchk->execute([$deptId]);
        if (!$dchk->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=departments&msg=chair_invalid'));
            exit;
        }

        $chairId = null;
        if ($chairRaw !== '') {
            if (!ctype_digit($chairRaw)) {
                header('Location: ' . $redirect . '&msg=chair_invalid');
                exit;
            }
            $chairId = (int)$chairRaw;
            $fchk = $pdo->prepare('SELECT 1 FROM faculty WHERE faculty_id = ? LIMIT 1');
            $fchk->execute([$chairId]);
            if (!$fchk->fetchColumn()) {
                header('Location: ' . $redirect . '&msg=chair_invalid');
                exit;
            }
        }

        try {
            $pdo->prepare('UPDATE departments SET chair_id = ? WHERE dept_id = ?')->execute([$chairId, $deptId]);
        } catch (Throwable) {
            header('Location: ' . $redirect . '&msg=chair_invalid');
            exit;
        }

        admin_audit($pdo, 'department_chair_save', $deptId . ' chair_id=' . ($chairId ?? 'null'));
        header('Location: ' . $redirect . '&msg=chair_saved');
        exit;
    }

    if ($action === 'department_save' && $isAdmin) {
        $deptId = strtoupper(trim((string)($_POST['dept_id'] ?? '')));
        $deptName = trim((string)($_POST['dept_name'] ?? ''));
        $building = trim((string)($_POST['building_number'] ?? ''));
        $room = trim((string)($_POST['room_number'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone_number'] ?? ''));
        $assistant = trim((string)($_POST['dept_assistant'] ?? ''));

        if ($deptId === '' || $deptName === '' || strlen($deptId) > 10 || !preg_match('/^[A-Z0-9]+$/', $deptId)) {
            header('Location: ' . url('/admin.php?view=departments&msg=dept_invalid'));
            exit;
        }

        $dchk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ? LIMIT 1');
        $dchk->execute([$deptId]);
        if ($dchk->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=departments&msg=dept_exists'));
            exit;
        }

        try {
            $pdo->prepare('
              INSERT INTO departments (dept_id, dept_name, room_number, building_number, email, phone_number, dept_assistant)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $deptId,
                $deptName,
                $room !== '' ? $room : null,
                $building !== '' ? $building : null,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $assistant !== '' ? $assistant : null,
            ]);
        } catch (Throwable) {
            header('Location: ' . url('/admin.php?view=departments&msg=dept_invalid'));
            exit;
        }

        admin_audit($pdo, 'department_save', $deptId);
        header('Location: ' . url('/admin.php?' . http_build_query([
            'view' => 'departments',
            'msg' => 'dept_saved',
            'created' => $deptId,
        ])));
        exit;
    }

    if ($action === 'catalog_course_save' && $isAdmin) {
        $cid = strtoupper(trim((string)($_POST['course_id'] ?? '')));
        $name = trim((string)($_POST['course_name'] ?? ''));
        $credits = isset($_POST['credits']) && is_numeric((string)$_POST['credits']) ? (int)$_POST['credits'] : null;
        $deptRaw = trim((string)($_POST['dept_id'] ?? ''));
        $deptId = $deptRaw === '' ? null : $deptRaw;
        $desc = trim((string)($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($cid === '' || $name === '' || $credits === null || $credits < 0) {
            header('Location: ' . url('/admin.php?view=catalog&msg=catalog_invalid'));
            exit;
        }
        if ($deptId !== null) {
            $dchk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ? LIMIT 1');
            $dchk->execute([$deptId]);
            if (!$dchk->fetchColumn()) {
                $deptId = null;
            }
        }
        try {
            $pdo->prepare('
              INSERT INTO courses (course_id, course_name, credits, dept_id, description, is_active)
              VALUES (?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                course_name = VALUES(course_name),
                credits = VALUES(credits),
                dept_id = VALUES(dept_id),
                description = VALUES(description),
                is_active = VALUES(is_active)
            ')->execute([$cid, $name, $credits, $deptId, $desc !== '' ? $desc : null, $isActive]);
        } catch (Throwable) {
            $pdo->prepare('
              INSERT INTO courses (course_id, course_name, credits, dept_id)
              VALUES (?,?,?,?)
              ON DUPLICATE KEY UPDATE course_name = VALUES(course_name), credits = VALUES(credits), dept_id = VALUES(dept_id)
            ')->execute([$cid, $name, $credits, $deptId]);
        }
        admin_audit($pdo, 'catalog_course_save', $cid);
        header('Location: ' . url('/admin.php?view=catalog&edit=' . rawurlencode($cid) . '&msg=course_saved'));
        exit;
    }

    if ($action === 'section_save' && $isAdmin) {
        $termId = isset($_POST['term_id']) && ctype_digit((string)$_POST['term_id']) ? (int)$_POST['term_id'] : null;
        $courseId = strtoupper(trim((string)($_POST['course_id'] ?? '')));
        $facultyRaw = trim((string)($_POST['faculty_id'] ?? ''));
        $facultyId = ($facultyRaw !== '' && ctype_digit($facultyRaw)) ? (int)$facultyRaw : null;
        $meetingDays = strtoupper(trim((string)($_POST['meeting_days'] ?? '')));
        $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
        $room = trim((string)($_POST['room'] ?? ''));
        $capacity = isset($_POST['capacity']) && is_numeric((string)$_POST['capacity']) ? (int)$_POST['capacity'] : null;

        $redirectBase = url('/admin.php?view=courses' . ($termId !== null ? '&term_id=' . $termId : ''));

        if ($termId === null || $courseId === '' || $capacity === null || $capacity < 1) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }

        $tchk = $pdo->prepare('SELECT 1 FROM terms WHERE term_id = ? LIMIT 1');
        $tchk->execute([$termId]);
        if (!$tchk->fetchColumn()) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }

        $cchk = $pdo->prepare('SELECT 1 FROM courses WHERE course_id = ? LIMIT 1');
        $cchk->execute([$courseId]);
        if (!$cchk->fetchColumn()) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }

        if ($facultyId !== null) {
            $fchk = $pdo->prepare('SELECT 1 FROM faculty WHERE faculty_id = ? LIMIT 1');
            $fchk->execute([$facultyId]);
            if (!$fchk->fetchColumn()) {
                $facultyId = null;
            }
        }

        $hasDays = $meetingDays !== '';
        $hasTime = $meetingTime !== '';
        if ($hasDays xor $hasTime) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }
        if ($hasDays && !schedule_days_valid($meetingDays)) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }
        if ($hasTime && !schedule_time_valid($meetingTime)) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }

        if ($hasDays && $hasTime) {
            $conflict = admin_find_section_schedule_conflict($pdo, $termId, $meetingDays, $meetingTime, $room !== '' ? $room : null, $facultyId);
            if ($conflict !== null) {
                $reason = ($conflict['reason'] ?? '') === 'room' ? 'room' : 'instructor';
                header('Location: ' . $redirectBase . '&msg=section_conflict&conflict_reason=' . rawurlencode($reason) . '&conflict_course=' . rawurlencode((string)($conflict['course_id'] ?? '')));
                exit;
            }
        }

        try {
            $pdo->prepare('
              INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
              VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $courseId,
                $termId,
                $facultyId,
                $hasDays ? $meetingDays : null,
                $hasTime ? $meetingTime : null,
                $room !== '' ? $room : null,
                $capacity,
            ]);
        } catch (Throwable) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }
        $newSectionId = (int)$pdo->lastInsertId();
        admin_audit($pdo, 'section_save', $courseId . ' section #' . $newSectionId . ' term ' . $termId);
        header('Location: ' . $redirectBase . '&msg=section_saved&highlight_section=' . $newSectionId);
        exit;
    }

    if ($action === 'section_update' && $isAdmin) {
        $sectionId = isset($_POST['section_id']) && ctype_digit((string)$_POST['section_id']) ? (int)$_POST['section_id'] : null;
        $termId = isset($_POST['term_id']) && ctype_digit((string)$_POST['term_id']) ? (int)$_POST['term_id'] : null;
        $facultyRaw = trim((string)($_POST['faculty_id'] ?? ''));
        $facultyId = ($facultyRaw !== '' && ctype_digit($facultyRaw)) ? (int)$facultyRaw : null;
        $meetingDays = strtoupper(trim((string)($_POST['meeting_days'] ?? '')));
        $meetingTime = trim((string)($_POST['meeting_time'] ?? ''));
        $room = trim((string)($_POST['room'] ?? ''));
        $capacity = isset($_POST['capacity']) && is_numeric((string)$_POST['capacity']) ? (int)$_POST['capacity'] : null;

        $redirectBase = url('/admin.php?view=courses' . ($termId !== null ? '&term_id=' . $termId : ''));
        $redirectEdit = $redirectBase . ($sectionId !== null ? '&edit_section=' . $sectionId : '');

        if ($sectionId === null || $termId === null || $capacity === null || $capacity < 1) {
            header('Location: ' . $redirectEdit . '&msg=section_invalid');
            exit;
        }

        $schk = $pdo->prepare('SELECT section_id, course_id, term_id FROM sections WHERE section_id = ? LIMIT 1');
        $schk->execute([$sectionId]);
        $sectionRow = $schk->fetch(PDO::FETCH_ASSOC);
        if (!$sectionRow || (int)($sectionRow['term_id'] ?? 0) !== $termId) {
            header('Location: ' . $redirectBase . '&msg=section_invalid');
            exit;
        }
        $courseId = (string)($sectionRow['course_id'] ?? '');

        if ($facultyId !== null) {
            $fchk = $pdo->prepare('SELECT 1 FROM faculty WHERE faculty_id = ? LIMIT 1');
            $fchk->execute([$facultyId]);
            if (!$fchk->fetchColumn()) {
                $facultyId = null;
            }
        }

        $hasDays = $meetingDays !== '';
        $hasTime = $meetingTime !== '';
        if ($hasDays xor $hasTime) {
            header('Location: ' . $redirectEdit . '&msg=section_invalid');
            exit;
        }
        if ($hasDays && !schedule_days_valid($meetingDays)) {
            header('Location: ' . $redirectEdit . '&msg=section_invalid');
            exit;
        }
        if ($hasTime && !schedule_time_valid($meetingTime)) {
            header('Location: ' . $redirectEdit . '&msg=section_invalid');
            exit;
        }

        if ($hasDays && $hasTime) {
            $conflict = admin_find_section_schedule_conflict($pdo, $termId, $meetingDays, $meetingTime, $room !== '' ? $room : null, $facultyId, $sectionId);
            if ($conflict !== null) {
                $reason = ($conflict['reason'] ?? '') === 'room' ? 'room' : 'instructor';
                header('Location: ' . $redirectEdit . '&msg=section_conflict&conflict_reason=' . rawurlencode($reason) . '&conflict_course=' . rawurlencode((string)($conflict['course_id'] ?? '')));
                exit;
            }
        }

        try {
            $pdo->prepare('
              UPDATE sections
              SET faculty_id = ?, meeting_days = ?, meeting_time = ?, room = ?, capacity = ?
              WHERE section_id = ?
            ')->execute([
                $facultyId,
                $hasDays ? $meetingDays : null,
                $hasTime ? $meetingTime : null,
                $room !== '' ? $room : null,
                $capacity,
                $sectionId,
            ]);
        } catch (Throwable) {
            header('Location: ' . $redirectEdit . '&msg=section_invalid');
            exit;
        }
        admin_audit($pdo, 'section_update', $courseId . ' section #' . $sectionId . ' term ' . $termId);
        header('Location: ' . $redirectBase . '&msg=section_updated&highlight_section=' . $sectionId);
        exit;
    }

    if ($action === 'catalog_prereqs_save' && $isAdmin) {
        $cid = strtoupper(trim((string)($_POST['course_id'] ?? '')));
        $ids = $_POST['prereq_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        if ($cid === '') {
            header('Location: ' . url('/admin.php?view=catalog&msg=catalog_invalid'));
            exit;
        }
        $pdo->prepare('DELETE FROM course_prereqs WHERE course_id = ?')->execute([$cid]);
        $ins = $pdo->prepare('INSERT INTO course_prereqs (course_id, prereq_course_id) VALUES (?, ?)');
        foreach ($ids as $p) {
            $p = strtoupper(trim((string)$p));
            if ($p !== '' && $p !== $cid) {
                try {
                    $ins->execute([$cid, $p]);
                } catch (Throwable) {
                }
            }
        }
        admin_audit($pdo, 'catalog_prereqs_save', $cid);
        header('Location: ' . url('/admin.php?view=catalog&edit=' . rawurlencode($cid) . '&msg=prereqs_saved'));
        exit;
    }

    if ($action === 'term_registration_save' && $isAdmin) {
        $tid = isset($_POST['term_id']) && ctype_digit((string)$_POST['term_id']) ? (int)$_POST['term_id'] : null;
        if ($tid === null) {
            header('Location: ' . url('/admin.php?view=terms&msg=invalid'));
            exit;
        }
        $open = isset($_POST['registration_open']) ? 1 : 0;
        $rs = trim((string)($_POST['registration_start'] ?? ''));
        $re = trim((string)($_POST['registration_end'] ?? ''));
        $rs = $rs === '' ? null : $rs;
        $re = $re === '' ? null : $re;
        try {
            $pdo->prepare('UPDATE terms SET registration_open = ?, registration_start = ?, registration_end = ? WHERE term_id = ?')->execute([$open, $rs, $re, $tid]);
        } catch (Throwable) {
            header('Location: ' . url('/admin.php?view=terms&msg=term_save_failed'));
            exit;
        }
        admin_audit($pdo, 'term_registration_save', 'term_id=' . $tid);
        header('Location: ' . url('/admin.php?view=terms&msg=term_saved'));
        exit;
    }

    if ($action === 'auth_login_save' && $isAdmin) {
        $aid = isset($_POST['auth_id']) && ctype_digit((string)$_POST['auth_id']) ? (int)$_POST['auth_id'] : null;
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $pw = trim((string)($_POST['new_password'] ?? ''));
        if ($aid === null || $aid < 1) {
            header('Location: ' . url('/admin.php?view=accounts&msg=invalid'));
            exit;
        }
        [$ok, $err] = auth_update_portal_login(
            $aid,
            $email,
            $pw !== '' ? $pw : null,
            null,
            $displayName !== '' ? $displayName : null,
        );
        if (!$ok) {
            $code = str_contains((string)$err, 'email') ? 'email_invalid' : 'pwd_invalid';
            if (str_contains((string)$err, 'already')) {
                $code = 'email_taken';
            }
            if (str_contains((string)$err, '8 characters')) {
                $code = 'pwd_invalid';
            }
            header('Location: ' . url('/admin.php?view=accounts&msg=' . $code));
            exit;
        }
        admin_audit($pdo, 'auth_login_save', 'id=' . $aid);
        header('Location: ' . url('/admin.php?view=accounts&msg=login_saved'));
        exit;
    }

    if ($action === 'auth_self_creds_save') {
        $sessionAuthId = (int)($_SESSION['auth']['id'] ?? 0);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $current = (string)($_POST['current_password'] ?? '');
        $newPw = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($sessionAuthId < 1 || $current === '') {
            header('Location: ' . url('/admin.php?view=settings&msg=invalid'));
            exit;
        }
        if ($newPw !== '' && $newPw !== $confirm) {
            header('Location: ' . url('/admin.php?view=settings&msg=pwd_mismatch'));
            exit;
        }
        [$ok, $err] = auth_update_portal_login(
            $sessionAuthId,
            $email,
            $newPw !== '' ? $newPw : null,
            $current,
            $displayName !== '' ? $displayName : null,
        );
        if (!$ok) {
            $code = 'self_failed';
            if (str_contains((string)$err, 'Current password')) {
                $code = 'self_bad_password';
            } elseif (str_contains((string)$err, 'already')) {
                $code = 'email_taken';
            } elseif (str_contains((string)$err, 'email')) {
                $code = 'email_invalid';
            } elseif (str_contains((string)$err, '8 characters')) {
                $code = 'pwd_invalid';
            }
            header('Location: ' . url('/admin.php?view=settings&msg=' . $code));
            exit;
        }
        if ($displayName !== '') {
            $_SESSION['auth']['display_name'] = $displayName;
        }
        admin_audit($pdo, 'auth_self_creds_save', 'id=' . $sessionAuthId);
        header('Location: ' . url('/admin.php?view=settings&msg=login_saved'));
        exit;
    }

    if ($action === 'auth_password_reset' && $isAdmin) {
        $aid = isset($_POST['auth_id']) && ctype_digit((string)$_POST['auth_id']) ? (int)$_POST['auth_id'] : null;
        $pw = (string)($_POST['new_password'] ?? '');
        if ($aid === null || strlen($pw) < 8) {
            header('Location: ' . url('/admin.php?view=accounts&msg=pwd_invalid'));
            exit;
        }
        [$ok, $err] = auth_update_user_password($aid, $pw);
        if (!$ok) {
            header('Location: ' . url('/admin.php?view=accounts&msg=pwd_invalid'));
            exit;
        }
        admin_audit($pdo, 'auth_password_reset', 'id=' . $aid);
        header('Location: ' . url('/admin.php?view=accounts&msg=pwd_reset'));
        exit;
    }

    if ($action === 'auth_user_active' && $isAdmin) {
        $aid = isset($_POST['auth_id']) && ctype_digit((string)$_POST['auth_id']) ? (int)$_POST['auth_id'] : null;
        $wantActive = isset($_POST['is_active']) && (string)$_POST['is_active'] === '1' ? 1 : 0;
        $sessionAuthId = (int)($_SESSION['auth']['id'] ?? 0);
        if ($aid === null || $aid < 1) {
            header('Location: ' . url('/admin.php?view=accounts&msg=invalid'));
            exit;
        }
        if ($aid === $sessionAuthId) {
            header('Location: ' . url('/admin.php?view=accounts&msg=self_not_allowed'));
            exit;
        }
        try {
            $pdo->prepare('UPDATE auth_users SET is_active = ? WHERE id = ?')->execute([$wantActive, $aid]);
        } catch (Throwable) {
            header('Location: ' . url('/admin.php?view=accounts&msg=active_failed'));
            exit;
        }
        admin_audit($pdo, 'auth_user_active', 'id=' . $aid . ';active=' . $wantActive);
        header('Location: ' . url('/admin.php?view=accounts&msg=active_saved'));
        exit;
    }

    if ($action === 'auth_email_save' && $isAdmin) {
        $aid = isset($_POST['auth_id']) && ctype_digit((string)$_POST['auth_id']) ? (int)$_POST['auth_id'] : null;
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($aid === null || $aid < 1) {
            header('Location: ' . url('/admin.php?view=accounts&msg=invalid'));
            exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header('Location: ' . url('/admin.php?view=accounts&msg=email_invalid'));
            exit;
        }
        if ($email !== '') {
            $dup = $pdo->prepare('SELECT id FROM auth_users WHERE LOWER(TRIM(email)) = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $aid]);
            if ($dup->fetchColumn()) {
                header('Location: ' . url('/admin.php?view=accounts&msg=email_taken'));
                exit;
            }
        }
        try {
            $pdo->prepare('UPDATE auth_users SET email = ? WHERE id = ?')->execute([$email === '' ? null : $email, $aid]);
        } catch (Throwable) {
            header('Location: ' . url('/admin.php?view=accounts&msg=email_failed'));
            exit;
        }
        admin_audit($pdo, 'auth_email_save', 'id=' . $aid);
        header('Location: ' . url('/admin.php?view=accounts&msg=email_saved'));
        exit;
    }

    if ($action === 'reg_promote' && $canRegister && $isAdmin) {
        $studentId = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $sectionId = isset($_POST['section_id']) && ctype_digit((string)$_POST['section_id']) ? (int)$_POST['section_id'] : null;
        $termCode = trim((string)($_POST['term'] ?? ''));
        if ($studentId === null || $sectionId === null) {
            header('Location: ' . url('/admin.php?view=registration&msg=invalid'));
            exit;
        }
        $stChk = $pdo->prepare('SELECT status FROM enrollments WHERE student_id = ? AND section_id = ? LIMIT 1');
        $stChk->execute([$studentId, $sectionId]);
        $curSt = (string)($stChk->fetchColumn() ?: '');
        if ($curSt !== 'waitlisted') {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=promote_bad'));
            exit;
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = "enrolled"');
        $cnt->execute([$sectionId]);
        $enrolled = (int)$cnt->fetchColumn();
        $capStmt = $pdo->prepare('SELECT capacity FROM sections WHERE section_id = ?');
        $capStmt->execute([$sectionId]);
        $cap = (int)$capStmt->fetchColumn();
        if ($enrolled >= $cap) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=promote_full'));
            exit;
        }
        $pdo->prepare('UPDATE enrollments SET status = "enrolled" WHERE student_id = ? AND section_id = ? AND status = "waitlisted"')->execute([$studentId, $sectionId]);
        admin_audit($pdo, 'reg_promote', 'student=' . $studentId . ';section=' . $sectionId);
        header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=promote_ok'));
        exit;
    }

    if ($action === 'reg_add' && $canRegister) {
        $studentId = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $sectionId = isset($_POST['section_id']) && ctype_digit((string)$_POST['section_id']) ? (int)$_POST['section_id'] : null;
        $termCode = trim((string)($_POST['term'] ?? ''));
        $overrideRegClosed = $isAdmin && !empty($_POST['override_reg_closed']);
        $overridePrereq = $isAdmin && !empty($_POST['override_prereq']);
        $overrideCredit = $isAdmin && !empty($_POST['override_credit']);
        if ($studentId === null || $sectionId === null || $termCode === '') {
            header('Location: ' . url('/admin.php?view=registration&msg=invalid'));
            exit;
        }
        $hc = $pdo->prepare('SELECT 1 FROM student_holds WHERE student_id = ? AND is_active = 1 LIMIT 1');
        $hc->execute([$studentId]);
        if ($hc->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=hold'));
            exit;
        }
        $sec = $pdo->prepare('
          SELECT s.section_id, s.term_id, s.course_id, s.meeting_days, s.meeting_time, s.capacity, c.credits, t.code AS term_code
          FROM sections s
          JOIN courses c ON c.course_id = s.course_id
          JOIN terms t ON t.term_id = s.term_id
          WHERE s.section_id = ? LIMIT 1
        ');
        $sec->execute([$sectionId]);
        $section = $sec->fetch(PDO::FETCH_ASSOC);
        if (!$section || (string)$section['term_code'] !== $termCode) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=wrongterm'));
            exit;
        }
        $termId = (int)$section['term_id'];
        $courseId = (string)$section['course_id'];
        $credits = (int)$section['credits'];

        if (!admin_term_registration_allowed($pdo, $termId) && !$overrideRegClosed) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=regclosed'));
            exit;
        }

        $dup = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND section_id = ? AND status IN ("enrolled","waitlisted") LIMIT 1');
        $dup->execute([$studentId, $sectionId]);
        if ($dup->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=duplicate'));
            exit;
        }
        $dc = $pdo->prepare('
          SELECT 1 FROM enrollments e JOIN sections s ON s.section_id = e.section_id
          WHERE e.student_id = ? AND s.term_id = ? AND s.course_id = ? AND e.status IN ("enrolled","waitlisted") LIMIT 1
        ');
        $dc->execute([$studentId, $termId, $courseId]);
        if ($dc->fetchColumn()) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=dupecourse'));
            exit;
        }

        $ex = $pdo->prepare('
          SELECT s.meeting_days, s.meeting_time FROM enrollments e
          JOIN sections s ON s.section_id = e.section_id
          WHERE e.student_id = ? AND e.status = "enrolled" AND s.term_id = ?
        ');
        $ex->execute([$studentId, $termId]);
        foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (schedule_conflicts($section['meeting_days'] ?? null, $section['meeting_time'] ?? null, $row['meeting_days'] ?? null, $row['meeting_time'] ?? null)) {
                header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=conflict'));
                exit;
            }
        }

        $maxCredits = $defaultMaxCredits;
        try {
            $mx = $pdo->prepare('SELECT max_credit FROM ug_credit_limits WHERE student_id = ? LIMIT 1');
            $mx->execute([$studentId]);
            $v = $mx->fetchColumn();
            if ($v !== false && $v !== null && is_numeric($v) && (int)$v > 0) {
                $maxCredits = (int)$v;
            }
        } catch (Throwable) {
        }
        $cur = $pdo->prepare('
          SELECT COALESCE(SUM(c.credits),0) FROM enrollments e
          JOIN sections s ON s.section_id = e.section_id
          JOIN courses c ON c.course_id = s.course_id
          WHERE e.student_id = ? AND e.status = "enrolled" AND s.term_id = ?
        ');
        $cur->execute([$studentId, $termId]);
        $currentCredits = (int)$cur->fetchColumn();
        if (!$overrideCredit && $currentCredits + $credits > $maxCredits) {
            header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=credit'));
            exit;
        }

        try {
            $pre = $pdo->prepare('SELECT prereq_course_id FROM course_prereqs WHERE course_id = ?');
            $pre->execute([$courseId]);
            $prereqs = $pre->fetchAll(PDO::FETCH_COLUMN);
            if (!$overridePrereq && $prereqs) {
                $in = implode(',', array_fill(0, count($prereqs), '?'));
                $stmt = $pdo->prepare("SELECT DISTINCT course_id FROM student_course_results WHERE student_id = ? AND course_id IN ($in) AND grade_points > 0");
                $stmt->execute(array_merge([$studentId], $prereqs));
                $done = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $missing = array_values(array_diff($prereqs, $done));
                if ($missing) {
                    header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=prereq'));
                    exit;
                }
            }
        } catch (Throwable) {
        }

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = "enrolled"');
        $cnt->execute([$sectionId]);
        $enrolled = (int)$cnt->fetchColumn();
        $cap = (int)$section['capacity'];
        $status = ($enrolled >= $cap) ? 'waitlisted' : 'enrolled';
        $pdo->prepare('INSERT INTO enrollments (student_id, section_id, status) VALUES (?, ?, ?)')->execute([$studentId, $sectionId, $status]);
        admin_audit($pdo, 'reg_' . $status, 'student=' . $studentId . ';section=' . $sectionId);
        header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=' . ($status === 'enrolled' ? 'enrolled' : 'waitlisted')));
        exit;
    }

    if ($action === 'reg_drop' && $canRegister) {
        $studentId = isset($_POST['student_id']) && ctype_digit((string)$_POST['student_id']) ? (int)$_POST['student_id'] : null;
        $sectionId = isset($_POST['section_id']) && ctype_digit((string)$_POST['section_id']) ? (int)$_POST['section_id'] : null;
        $termCode = trim((string)($_POST['term'] ?? ''));
        if ($studentId === null || $sectionId === null) {
            header('Location: ' . url('/admin.php?view=registration&msg=invalid'));
            exit;
        }
        $pdo->prepare('DELETE FROM enrollments WHERE student_id = ? AND section_id = ? AND status IN ("enrolled","waitlisted")')->execute([$studentId, $sectionId]);

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = "enrolled"');
        $cnt->execute([$sectionId]);
        $enrolled = (int)$cnt->fetchColumn();
        $capStmt = $pdo->prepare('SELECT capacity FROM sections WHERE section_id = ?');
        $capStmt->execute([$sectionId]);
        $cap = (int)$capStmt->fetchColumn();
        if ($enrolled < $cap) {
            $w = $pdo->prepare('SELECT enrollment_id, student_id FROM enrollments WHERE section_id = ? AND status = "waitlisted" ORDER BY created_at ASC LIMIT 1');
            $w->execute([$sectionId]);
            $row = $w->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare('UPDATE enrollments SET status = "enrolled" WHERE enrollment_id = ?')->execute([(int)$row['enrollment_id']]);
            }
        }
        admin_audit($pdo, 'reg_drop', 'student=' . $studentId . ';section=' . $sectionId);
        header('Location: ' . url('/admin.php?view=registration&student_id=' . $studentId . '&term=' . rawurlencode($termCode) . '&msg=dropped'));
        exit;
    }

    header('Location: ' . url('/admin.php?view=' . rawurlencode($view)));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $view === 'reports' && ($_GET['export'] ?? '') === 'enrollment_summary' && $isAdmin) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="enrollment_summary.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['course_id', 'course_name', 'section_id', 'term_code', 'enrolled', 'capacity', 'waitlisted']);
    if ($currentTermId !== null) {
        try {
            $q = $pdo->prepare('
              SELECT
                c.course_id,
                c.course_name,
                s.section_id,
                t.code AS term_code,
                s.capacity,
                (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.status = "enrolled") AS enrolled_cnt,
                (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.status = "waitlisted") AS waitlisted_cnt
              FROM sections s
              INNER JOIN courses c ON c.course_id = s.course_id
              INNER JOIN terms t ON t.term_id = s.term_id
              WHERE s.term_id = ?
              ORDER BY c.course_id, s.section_id
            ');
            $q->execute([$currentTermId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                fputcsv($out, [
                    (string)$row['course_id'],
                    (string)$row['course_name'],
                    (string)$row['section_id'],
                    (string)$row['term_code'],
                    (string)$row['enrolled_cnt'],
                    (string)$row['capacity'],
                    (string)$row['waitlisted_cnt'],
                ]);
            }
        } catch (Throwable) {
        }
    }
    fclose($out);
    exit;
}

$counts = [
    'students' => 0,
    'faculty' => 0,
    'holds_active' => 0,
    'courses_catalog' => 0,
    'courses_active_term' => 0,
    'departments' => 0,
];
$dash = [
    'students_missing_email' => 0,
    'students_missing_phone' => 0,
    'faculty_missing_email' => 0,
    'faculty_missing_phone' => 0,
    'term_sections' => 0,
    'term_enrolled' => 0,
    'term_waitlisted' => 0,
    'term_open_seats' => 0,
    'top_enrolled' => [],
    'top_waitlisted' => [],
    'recent_enrollments' => [],
    'audit_recent' => [],
    'chart_month_labels' => [],
    'chart_month_values' => [],
    'chart_dept_labels' => [],
    'chart_dept_values' => [],
    'chart_status_labels' => [],
    'chart_status_values' => [],
    'chart_status_colors' => [],
];
$notifPreviews = [
    'student_email' => [
        'table' => 'users ⋈ students',
        'title' => 'Students missing email',
        'total' => 0,
        'columns' => ['user_id' => 'user_id', 'name' => 'name', 'email' => 'email'],
        'rows' => [],
        'href' => url('/admin.php?view=schedule&q=%40ashford.edu'),
        'empty' => 'SELECT returned 0 rows.',
    ],
    'student_phone' => [
        'table' => 'users ⋈ students',
        'title' => 'Students missing phone',
        'total' => 0,
        'columns' => ['user_id' => 'user_id', 'name' => 'name', 'phone_number' => 'phone'],
        'rows' => [],
        'href' => url('/admin.php?view=schedule&q=%28'),
        'empty' => 'SELECT returned 0 rows.',
    ],
    'faculty_email' => [
        'table' => 'faculty ⋈ users',
        'title' => 'Faculty missing email',
        'total' => 0,
        'columns' => ['faculty_id' => 'faculty_id', 'name' => 'name', 'email' => 'email'],
        'rows' => [],
        'href' => url('/admin.php?view=schedule&q=%40ashford.edu'),
        'empty' => 'SELECT returned 0 rows.',
    ],
    'faculty_phone' => [
        'table' => 'faculty ⋈ users',
        'title' => 'Faculty missing phone',
        'total' => 0,
        'columns' => ['faculty_id' => 'faculty_id', 'name' => 'name', 'phone_number' => 'phone'],
        'rows' => [],
        'href' => url('/admin.php?view=schedule&q=%28'),
        'empty' => 'SELECT returned 0 rows.',
    ],
    'holds' => [
        'table' => 'student_holds',
        'title' => 'Active registration holds',
        'total' => 0,
        'columns' => ['student_id' => 'student_id', 'hold_type' => 'hold_type', 'note' => 'note'],
        'rows' => [],
        'href' => url('/admin.php?view=holds#admin-active-holds-list'),
        'empty' => 'No active holds.',
    ],
];
try {
    $counts['students'] = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $counts['faculty'] = (int)$pdo->query('SELECT COUNT(*) FROM faculty')->fetchColumn();
    $counts['holds_active'] = (int)$pdo->query('SELECT COUNT(*) FROM student_holds WHERE is_active = 1')->fetchColumn();
    $counts['undergrad'] = (int)$pdo->query('SELECT COUNT(*) FROM undergrad_students')->fetchColumn();
    $counts['undergrad_ft'] = (int)$pdo->query('SELECT COUNT(*) FROM undergrad_students WHERE student_type LIKE "%Full%"')->fetchColumn();
    $counts['undergrad_pt'] = (int)$pdo->query('SELECT COUNT(*) FROM undergrad_students WHERE student_type LIKE "%Part%"')->fetchColumn();
    $counts['grad'] = (int)$pdo->query('SELECT COUNT(DISTINCT student_id) FROM grad_student_programs')->fetchColumn();
    $counts['faculty_ft'] = (int)$pdo->query('SELECT COUNT(*) FROM faculty WHERE faculty_type LIKE "%Full%"')->fetchColumn();
    $counts['faculty_pt'] = (int)$pdo->query('SELECT COUNT(*) FROM faculty WHERE faculty_type LIKE "%Part%"')->fetchColumn();
    foreach (['buildings' => 'buildings', 'rooms' => 'rooms', 'offices' => 'office_rooms', 'semesters' => 'semesters'] as $countKey => $tableName) {
        try {
            $counts[$countKey] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $tableName . '`')->fetchColumn();
        } catch (Throwable) {
            $counts[$countKey] = 0;
        }
    }

    // Data-quality signals
    $dash['students_missing_email'] = (int)$pdo->query('
      SELECT COUNT(*)
      FROM users u
      INNER JOIN students s ON s.student_id = u.user_id
      WHERE u.email IS NULL OR TRIM(u.email) = ""
    ')->fetchColumn();
    $dash['students_missing_phone'] = (int)$pdo->query('
      SELECT COUNT(*)
      FROM users u
      INNER JOIN students s ON s.student_id = u.user_id
      WHERE u.phone_number IS NULL OR TRIM(u.phone_number) = ""
    ')->fetchColumn();
    $dash['faculty_missing_email'] = (int)$pdo->query('
      SELECT COUNT(*)
      FROM faculty f
      WHERE f.email IS NULL OR TRIM(f.email) = ""
    ')->fetchColumn();
    $dash['faculty_missing_phone'] = (int)$pdo->query('
      SELECT COUNT(*)
      FROM faculty f
      WHERE f.phone_number IS NULL OR TRIM(f.phone_number) = ""
    ')->fetchColumn();

    $notifPreviews['student_email']['total'] = (int)$dash['students_missing_email'];
    $notifPreviews['student_email']['rows'] = $pdo->query('
      SELECT
        u.user_id,
        CONCAT(u.first_name, " ", u.last_name) AS name,
        u.email
      FROM users u
      INNER JOIN students s ON s.student_id = u.user_id
      WHERE u.email IS NULL OR TRIM(u.email) = ""
      ORDER BY u.user_id
      LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $notifPreviews['student_phone']['total'] = (int)$dash['students_missing_phone'];
    $notifPreviews['student_phone']['rows'] = $pdo->query('
      SELECT
        u.user_id,
        CONCAT(u.first_name, " ", u.last_name) AS name,
        u.phone_number
      FROM users u
      INNER JOIN students s ON s.student_id = u.user_id
      WHERE u.phone_number IS NULL OR TRIM(u.phone_number) = ""
      ORDER BY u.user_id
      LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $notifPreviews['faculty_email']['total'] = (int)$dash['faculty_missing_email'];
    $notifPreviews['faculty_email']['rows'] = $pdo->query('
      SELECT
        f.faculty_id,
        CONCAT(u.first_name, " ", u.last_name) AS name,
        f.email
      FROM faculty f
      INNER JOIN users u ON u.user_id = f.faculty_id
      WHERE f.email IS NULL OR TRIM(f.email) = ""
      ORDER BY f.faculty_id
      LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $notifPreviews['faculty_phone']['total'] = (int)$dash['faculty_missing_phone'];
    $notifPreviews['faculty_phone']['rows'] = $pdo->query('
      SELECT
        f.faculty_id,
        CONCAT(u.first_name, " ", u.last_name) AS name,
        f.phone_number
      FROM faculty f
      INNER JOIN users u ON u.user_id = f.faculty_id
      WHERE f.phone_number IS NULL OR TRIM(f.phone_number) = ""
      ORDER BY f.faculty_id
      LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    $notifPreviews['holds']['total'] = (int)$counts['holds_active'];
    $notifPreviews['holds']['rows'] = $pdo->query('
      SELECT
        h.student_id,
        h.hold_type,
        LEFT(COALESCE(h.note, ""), 48) AS note
      FROM student_holds h
      WHERE h.is_active = 1
      ORDER BY h.created_at DESC
      LIMIT 5
    ')->fetchAll(PDO::FETCH_ASSOC);

    // Current-term operational stats (if we have a term)
    if ($currentTermId !== null) {
        $secCnt = $pdo->prepare('SELECT COUNT(*) FROM sections WHERE term_id = ?');
        $secCnt->execute([$currentTermId]);
        $dash['term_sections'] = (int)$secCnt->fetchColumn();

        $st = $pdo->prepare('
          SELECT
            SUM(e.status = "enrolled") AS enrolled_cnt,
            SUM(e.status = "waitlisted") AS waitlisted_cnt
          FROM enrollments e
          INNER JOIN sections s ON s.section_id = e.section_id
          WHERE s.term_id = ?
        ');
        $st->execute([$currentTermId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $dash['term_enrolled'] = (int)($row['enrolled_cnt'] ?? 0);
        $dash['term_waitlisted'] = (int)($row['waitlisted_cnt'] ?? 0);

        $open = $pdo->prepare('
          SELECT
            COALESCE(SUM(GREATEST(s.capacity - COALESCE(en.enrolled_cnt, 0), 0)), 0) AS open_seats
          FROM sections s
          LEFT JOIN (
            SELECT e.section_id, COUNT(*) AS enrolled_cnt
            FROM enrollments e
            WHERE e.status = "enrolled"
            GROUP BY e.section_id
          ) en ON en.section_id = s.section_id
          WHERE s.term_id = ?
        ');
        $open->execute([$currentTermId]);
        $dash['term_open_seats'] = (int)$open->fetchColumn();

        $topEn = $pdo->prepare('
          SELECT
            s.section_id,
            c.course_id,
            c.course_name,
            s.capacity,
            COALESCE(en.enrolled_cnt, 0) AS enrolled_cnt,
            COALESCE(wl.waitlisted_cnt, 0) AS waitlisted_cnt,
            u.first_name AS fac_first,
            u.last_name AS fac_last
          FROM sections s
          INNER JOIN courses c ON c.course_id = s.course_id
          LEFT JOIN (
            SELECT section_id, COUNT(*) AS enrolled_cnt
            FROM enrollments
            WHERE status = "enrolled"
            GROUP BY section_id
          ) en ON en.section_id = s.section_id
          LEFT JOIN (
            SELECT section_id, COUNT(*) AS waitlisted_cnt
            FROM enrollments
            WHERE status = "waitlisted"
            GROUP BY section_id
          ) wl ON wl.section_id = s.section_id
          LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
          LEFT JOIN users u ON u.user_id = f.faculty_id
          WHERE s.term_id = ?
          ORDER BY enrolled_cnt DESC, waitlisted_cnt DESC, s.section_id DESC
          LIMIT 5
        ');
        $topEn->execute([$currentTermId]);
        $dash['top_enrolled'] = $topEn->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $topWl = $pdo->prepare('
          SELECT
            s.section_id,
            c.course_id,
            c.course_name,
            s.capacity,
            COALESCE(en.enrolled_cnt, 0) AS enrolled_cnt,
            COALESCE(wl.waitlisted_cnt, 0) AS waitlisted_cnt,
            u.first_name AS fac_first,
            u.last_name AS fac_last
          FROM sections s
          INNER JOIN courses c ON c.course_id = s.course_id
          LEFT JOIN (
            SELECT section_id, COUNT(*) AS enrolled_cnt
            FROM enrollments
            WHERE status = "enrolled"
            GROUP BY section_id
          ) en ON en.section_id = s.section_id
          LEFT JOIN (
            SELECT section_id, COUNT(*) AS waitlisted_cnt
            FROM enrollments
            WHERE status = "waitlisted"
            GROUP BY section_id
          ) wl ON wl.section_id = s.section_id
          LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
          LEFT JOIN users u ON u.user_id = f.faculty_id
          WHERE s.term_id = ?
          ORDER BY waitlisted_cnt DESC, enrolled_cnt DESC, s.section_id DESC
          LIMIT 5
        ');
        $topWl->execute([$currentTermId]);
        $dash['top_waitlisted'] = $topWl->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $counts['courses_catalog'] = (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $counts['departments'] = (int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
    if ($currentTermId !== null) {
        $distinctCourses = $pdo->prepare('SELECT COUNT(DISTINCT course_id) FROM sections WHERE term_id = ?');
        $distinctCourses->execute([$currentTermId]);
        $counts['courses_active_term'] = (int)$distinctCourses->fetchColumn();
    }

    $recentStmt = $pdo->query('
      SELECT e.created_at, e.status, e.student_id,
        u.first_name, u.last_name,
        c.course_id, c.course_name
      FROM enrollments e
      INNER JOIN sections s ON s.section_id = e.section_id
      INNER JOIN courses c ON c.course_id = s.course_id
      INNER JOIN users u ON u.user_id = e.student_id
      ORDER BY e.created_at DESC
      LIMIT 15
    ');
    $dash['recent_enrollments'] = $recentStmt ? ($recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $auditStmt = $pdo->query('
      SELECT a.action, a.details, a.created_at,
        COALESCE(u.username, CONCAT("#", a.admin_auth_id)) AS actor
      FROM admin_audit_log a
      LEFT JOIN auth_users u ON u.id = a.admin_auth_id
      ORDER BY a.created_at DESC
      LIMIT 18
    ');
    $dash['audit_recent'] = $auditStmt ? ($auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $monthMap = [];
    $monthRows = $pdo->query("
      SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
      FROM enrollments
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
      GROUP BY ym ORDER BY ym
    ");
    if ($monthRows) {
        foreach ($monthRows->fetchAll(PDO::FETCH_ASSOC) as $mr) {
            $monthMap[(string)$mr['ym']] = (int)$mr['cnt'];
        }
    }
    $dash['chart_month_labels'] = [];
    $dash['chart_month_values'] = [];
    for ($mi = 11; $mi >= 0; $mi--) {
        $d = (new DateTimeImmutable('first day of this month'))->modify('-' . $mi . ' months');
        $key = $d->format('Y-m');
        $dash['chart_month_labels'][] = $d->format('M Y');
        $dash['chart_month_values'][] = (int)($monthMap[$key] ?? 0);
    }

    $dash['chart_dept_labels'] = [];
    $dash['chart_dept_values'] = [];
    try {
        $deptStmt = $pdo->query('
          SELECT d.dept_name, COUNT(DISTINCT sd.student_id) AS cnt
          FROM student_departments sd
          INNER JOIN departments d ON d.dept_id = sd.dept_id
          GROUP BY d.dept_id, d.dept_name
          ORDER BY cnt DESC
          LIMIT 10
        ');
        if ($deptStmt) {
            foreach ($deptStmt->fetchAll(PDO::FETCH_ASSOC) as $dr) {
                $dash['chart_dept_labels'][] = (string)$dr['dept_name'];
                $dash['chart_dept_values'][] = (int)$dr['cnt'];
            }
        }
    } catch (Throwable) {
    }

    $dash['chart_status_labels'] = [];
    $dash['chart_status_values'] = [];
    $dash['chart_status_colors'] = [];
    if ($currentTermId !== null) {
        $stChart = $pdo->prepare('
          SELECT e.status, COUNT(*) AS cnt
          FROM enrollments e
          INNER JOIN sections s ON s.section_id = e.section_id
          WHERE s.term_id = ?
          GROUP BY e.status
        ');
        $stChart->execute([$currentTermId]);
        $palette = ['enrolled' => '#4f46e5', 'waitlisted' => '#f59e0b', 'dropped' => '#94a3b8'];
        foreach ($stChart->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $st = (string)$sr['status'];
            $dash['chart_status_labels'][] = ucfirst($st);
            $dash['chart_status_values'][] = (int)$sr['cnt'];
            $dash['chart_status_colors'][] = $palette[$st] ?? '#64748b';
        }
    }
} catch (Throwable) {
}

$adminNotificationCount = (int)($dash['students_missing_email'] ?? 0)
    + (int)($dash['faculty_missing_email'] ?? 0)
    + (int)($dash['students_missing_phone'] ?? 0)
    + (int)($dash['faculty_missing_phone'] ?? 0)
    + (int)($counts['holds_active'] ?? 0);
$adminNotifBellPreviews = array_values(array_filter(
    [
        ($notifPreviews['student_email']['total'] ?? 0) > 0 ? $notifPreviews['student_email'] : null,
        ($notifPreviews['faculty_email']['total'] ?? 0) > 0 ? $notifPreviews['faculty_email'] : null,
        ($notifPreviews['student_phone']['total'] ?? 0) > 0 ? $notifPreviews['student_phone'] : null,
        ($notifPreviews['faculty_phone']['total'] ?? 0) > 0 ? $notifPreviews['faculty_phone'] : null,
        ($notifPreviews['holds']['total'] ?? 0) > 0 ? $notifPreviews['holds'] : null,
    ],
    static fn ($p) => $p !== null
));
$adminNotifEmailGapPreviews = array_values(array_filter(
    [
        ($notifPreviews['student_email']['total'] ?? 0) > 0 ? $notifPreviews['student_email'] : null,
        ($notifPreviews['faculty_email']['total'] ?? 0) > 0 ? $notifPreviews['faculty_email'] : null,
    ],
    static fn ($p) => $p !== null
));
$adminNotifPhoneGapPreviews = array_values(array_filter(
    [
        ($notifPreviews['student_phone']['total'] ?? 0) > 0 ? $notifPreviews['student_phone'] : null,
        ($notifPreviews['faculty_phone']['total'] ?? 0) > 0 ? $notifPreviews['faculty_phone'] : null,
    ],
    static fn ($p) => $p !== null
));
$adminUserInitials = '';
{
    $u = trim($user);
    if ($u === '') {
        $adminUserInitials = 'NA';
    } elseif (str_contains($u, '@')) {
        $local = strstr($u, '@', true) ?: $u;
        $clean = preg_replace('/[^a-z]/i', '', $local) ?: $local;
        $adminUserInitials = strtoupper(substr($clean, 0, 2));
    } else {
        $parts = preg_split('/\s+/', $u);
        $adminUserInitials = count($parts) >= 2
            ? strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1))
            : strtoupper(substr($u, 0, 2));
    }
}

function admin_people_hold_presets(): array
{
    return ['Bursar', 'Academic', 'Registration', 'Immunization', 'Financial aid', 'Disciplinary', 'Other'];
}

function admin_people_genders(): array
{
    return ['Male', 'Female', 'Non-binary', 'Prefer not to say', 'Other'];
}

/** US postal abbreviations (including DC) for profile state dropdown */
function admin_people_us_state_codes(): array
{
    return ['AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC'];
}

function admin_people_academic_year_levels(): array
{
    return ['Freshman', 'Sophomore', 'Junior', 'Senior', 'Other'];
}

function admin_people_enrollment_intensity_options(): array
{
    return ['Full-time', 'Part-time', 'Other'];
}

/**
 * Enrollment label for display & dropdown hints when enrollment_intensity is unset:
 * maps legacy undergrad_students.student_type (e.g. Fulltime/Parttime from CSV import).
 */
function admin_ug_display_enrollment(?array $ug): string
{
    if (!$ug) {
        return '';
    }
    $ei = trim((string)($ug['enrollment_intensity'] ?? ''));
    if ($ei !== '') {
        return $ei;
    }
    $st = strtolower(trim((string)($ug['student_type'] ?? '')));
    if ($st === 'fulltime' || str_contains($st, 'full')) {
        return 'Full-time';
    }
    if ($st === 'parttime' || str_contains($st, 'part')) {
        return 'Part-time';
    }
    $legacy = trim((string)($ug['student_type'] ?? ''));

    return $legacy !== '' ? $legacy : '';
}

/**
 * Default load bands when admin creates the first ug_credit_limits row (no CSV row yet).
 * Normal installs should populate limits from storage/import UG_fulltime.csv & UG_parttime.csv.
 */
function admin_ug_credit_band_from_student_type(string $studentType): array
{
    $t = strtolower(trim($studentType));
    if ($t === 'parttime' || str_contains($t, 'part')) {
        return ['max_credit' => 12, 'min_credit' => 6];
    }

    return ['max_credit' => 18, 'min_credit' => 12];
}

/** Map common letter grades to quality points (4.0 scale); returns null if unknown */
function admin_grade_points_from_letter(string $letter): ?float
{
    $l = strtoupper(trim($letter));
    $map = [
        'A+' => 4.0, 'A' => 4.0, 'A-' => 3.7,
        'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7,
        'C+' => 2.3, 'C' => 2.0, 'C-' => 1.7,
        'D+' => 1.3, 'D' => 1.0, 'D-' => 0.7,
        'F' => 0.0, 'W' => 0.0, 'WP' => 0.0, 'WF' => 0.0, 'I' => 0.0,
    ];

    return array_key_exists($l, $map) ? $map[$l] : null;
}

function admin_enrollment_badge_classes(string $status): string
{
    return match (strtolower(trim($status))) {
        'enrolled' => 'bg-emerald-100 text-emerald-900 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-200 dark:ring-emerald-800',
        'waitlisted' => 'bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-800',
        'dropped' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600',
        default => 'bg-slate-100 text-slate-800 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600',
    };
}

function nav_item(string $href, string $label, bool $active): string
{
    $cls = $active
        ? 'block rounded-xl px-3 py-2 font-semibold text-indigo-950 bg-indigo-50 ring-1 ring-indigo-200 dark:bg-indigo-500/15 dark:text-indigo-100 dark:ring-indigo-500/30'
        : 'block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5';

    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
}

/** Non-clickable sidebar subsection title (separates directory vs courses, etc.). */
function nav_group_label(string $label): string
{
    return '<div class="pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">' . htmlspecialchars($label) . '</div>';
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <?php require __DIR__ . '/../app/views/partials/theme_init.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { fontFamily: { sans: ['DM Sans', 'system-ui', 'sans-serif'] } } },
    };
  </script>
  <link rel="stylesheet" href="<?= htmlspecialchars(url('/assets/css/theme.css')) ?>" />
  <style>
    @media (min-width: 1024px) {
      html.admin-nav-collapsed #adminSidebar {
        transform: translateX(-100%);
      }
      html.admin-nav-collapsed #adminMain {
        padding-left: 1.5rem;
      }
      html:not(.admin-nav-collapsed) #adminMain {
        padding-left: 19rem;
      }
    }
  </style>
</head>
<body class="nb-staff min-h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
  <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95">
    <div class="mx-auto max-w-[min(100vw-2rem,110rem)] px-3 py-3 sm:px-5">
      <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:gap-4">
        <div class="flex min-w-0 flex-1 items-center justify-between gap-3 lg:justify-start lg:gap-4">
          <a href="<?= htmlspecialchars(url('/admin.php?view=dashboard')) ?>" class="flex min-w-0 items-center gap-3">
            <?php
            $logoClass = 'h-9 w-auto max-w-[10rem] shrink-0 object-contain sm:h-10 sm:max-w-[12rem]';
            $logoAlt = 'Ashford Admin';
            require __DIR__ . '/../app/views/partials/brand_logo.php';
            ?>
            <div class="min-w-0">
              <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">Ashford Admin</div>
              <div class="text-[11px] text-slate-500 dark:text-slate-400">Admin dashboard</div>
            </div>
          </a>
          <div class="flex items-center gap-2 lg:hidden">
            <button
              type="button"
              id="adminMenuButton"
              class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-900 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
              aria-haspopup="dialog"
              aria-controls="adminMenuDrawer"
              aria-expanded="false"
              title="Menu"
            >
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5">
                <path fill="currentColor" d="M4 6.5h16a1 1 0 0 0 0-2H4a1 1 0 0 0 0 2Zm16 5.5H4a1 1 0 0 0 0 2h16a1 1 0 0 0 0-2Zm0 7H4a1 1 0 0 0 0 2h16a1 1 0 0 0 0-2Z"/>
              </svg>
              <span class="sr-only">Open menu</span>
            </button>
          </div>
        </div>

        <form method="get" action="<?= htmlspecialchars(url('/admin.php')) ?>" class="flex w-full flex-1 items-center gap-2 lg:max-w-xl">
          <input type="hidden" name="view" value="schedule" />
          <label class="sr-only" for="admin-global-search">Search schedule and directory</label>
          <input
            id="admin-global-search"
            type="search"
            name="q"
            placeholder="Search people, courses, email…"
            class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-500"
            autocomplete="off"
          />
          <button type="submit" class="shrink-0 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Search</button>
        </form>

        <div class="flex flex-wrap items-center justify-end gap-2 sm:gap-3">
          <?php require __DIR__ . '/../app/views/partials/theme_toggle.php'; ?>
          <div class="group relative admin-notif-wrap" tabindex="0" data-notif-wrap>
            <a
              href="<?= htmlspecialchars(url('/admin.php?view=dashboard#admin-alerts')) ?>"
              class="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
              title="Alerts and items needing attention — hover or tap for live database preview"
              aria-describedby="admin-notif-bell-preview"
              data-notif-trigger
            >
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 00-3 0v.68C7.64 5.36 6 7.92 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <?php if ($adminNotificationCount > 0): ?>
                <span class="absolute -right-1 -top-1 grid min-h-[1.25rem] min-w-[1.25rem] place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white"><?= $adminNotificationCount > 99 ? '99+' : (int)$adminNotificationCount ?></span>
              <?php endif; ?>
            </a>
            <?php if ($adminNotifBellPreviews !== []): ?>
              <?php
                $previews = $adminNotifBellPreviews;
                $panelClass = 'right-0 top-full w-[min(22rem,92vw)]';
              ?>
              <div id="admin-notif-bell-preview">
                <?php require __DIR__ . '/../app/views/partials/admin_notif_hover_panel.php'; ?>
              </div>
            <?php else: ?>
              <?php
                $previews = [[
                    'table' => 'information_schema',
                    'title' => 'All clear',
                    'total' => 0,
                    'columns' => ['status' => 'status'],
                    'rows' => [['status' => 'No open alerts']],
                    'href' => url('/admin.php?view=dashboard#admin-alerts'),
                    'empty' => 'No open alerts.',
                ]];
                $panelClass = 'right-0 top-full w-[min(18rem,92vw)]';
              ?>
              <div id="admin-notif-bell-preview">
                <?php require __DIR__ . '/../app/views/partials/admin_notif_hover_panel.php'; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex max-w-[14rem] items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/80 px-2 py-1.5 dark:border-slate-700 dark:bg-slate-900/80">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-indigo-600 to-sky-600 text-xs font-bold text-white"><?= htmlspecialchars($adminUserInitials) ?></span>
            <div class="min-w-0">
              <div class="truncate text-xs font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars($user !== '' ? $user : 'Admin') ?></div>
              <div class="truncate text-[10px] text-slate-500 dark:text-slate-400"><?= htmlspecialchars($roleLabel) ?></div>
            </div>
          </div>

          <button
            type="button"
            id="adminSidebarToggle"
            class="hidden rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:inline-flex"
            aria-controls="adminSidebar"
            aria-expanded="true"
            title="Hide sidebar"
          >
            Hide
          </button>

          <a href="<?= htmlspecialchars(url('/')) ?>" class="hidden text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white sm:inline">Site home</a>
          <form method="post" action="<?= htmlspecialchars(url('/logout.php')) ?>" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
            <button type="submit" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800">Log out</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <div id="adminMenuBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/40 backdrop-blur-[1px] lg:hidden"></div>
  <div id="adminMenuDrawer" class="fixed right-0 top-0 z-50 hidden h-full w-[min(92vw,22rem)] border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900 lg:hidden">
    <div class="flex h-full flex-col">
      <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <div>
          <div class="text-sm font-semibold text-slate-900 dark:text-white">Menu</div>
          <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Ashford Admin</div>
        </div>
        <button type="button" id="adminMenuClose" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
          Close
        </button>
      </div>
      <div class="flex-1 overflow-y-auto px-5 py-5">
        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Navigation</div>
        <nav class="mt-4">
          <?php require view_path('partials/admin_full_nav.php'); ?>
        </nav>
        <form method="post" action="<?= htmlspecialchars(url('/logout.php')) ?>" class="mt-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
          <button type="submit" class="block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-950/50">Log out</button>
        </form>

        <p class="mt-4 text-xs leading-relaxed text-slate-500">
          <strong class="text-slate-600"><?= htmlspecialchars($roleLabel) ?>:</strong> <?= htmlspecialchars(preg_replace('/^[^:]+:\s*/', '', $roleNote)) ?>
        </p>
      </div>
    </div>
  </div>

  <main id="adminMain" class="nb-admin-content relative w-full px-4 py-10 sm:px-6">
    <?php
    $flashMsg = trim((string)($_GET['msg'] ?? ''));
    $flashMap = [
        'readonly' => ['warn', 'Your role is read-only; that action was not applied.'],
        'forbidden' => ['error', 'Your role cannot perform that action.'],
        'profile_saved' => ['success', 'Student record saved.'],
        'faculty_saved' => ['success', 'Faculty profile saved.'],
        'profile_invalid' => ['error', 'Profile was not updated — use an @ashford.edu email and a US phone like (516) 867-4800.'],
        'decl_limit' => ['error', 'A student can only have 1 major and 1 minor. Update the declarations and try again.'],
        'decl_conflict' => ['error', 'Major and minor cannot be in the same department.'],
        'decl_same_as_other' => ['error', 'Major cannot match current minor (and minor cannot match current major).'],
        'decl_final_sem' => ['error', 'Major/minor changes are blocked in the final semester.'],
        'decl_gpa' => ['error', 'GPA is below the minimum required for that major.'],
        'decl_limit3' => ['error', 'Major change limit reached (max 3).'],
        'hold_added' => ['success', 'Hold added.'],
        'grade_saved' => ['success', 'Transcript grade saved.'],
        'grade_invalid' => ['error', 'Grade was not saved — check course, term, and letter grade.'],
        'section_saved' => ['success', 'New section created and added to the schedule.'],
        'section_updated' => ['success', 'Section updated — instructor and schedule saved.'],
        'section_invalid' => ['error', 'Could not save section — capacity ≥ 1, and use matching days (MWF/TR) + time (10:00-11:15) together or leave both blank.'],
        'section_conflict' => ['error', 'Schedule conflict — that instructor or room already has a class at overlapping days/times this term.'],
        'chair_saved' => ['success', 'Department chair saved.'],
        'chair_invalid' => ['error', 'Chair was not saved — pick a valid faculty member or clear the assignment.'],
        'dept_saved' => ['success', 'Department created. Click it in the directory below to open it.'],
        'dept_invalid' => ['error', 'Department was not saved — use a unique code (letters/numbers, max 10) and a name.'],
        'dept_exists' => ['error', 'That department code already exists.'],
        'person_created' => ['success', 'Person created. You can update details, declarations, or holds below.'],
        'person_invalid' => ['error', 'Person was not created — check ID range (students 1000000–1999999, faculty 9000000–9999999), @ashford.edu email, NY-area phone, required fields, and formats.'],
        'person_exists' => ['error', 'That ID already exists. Search for it above or pick a different ID.'],
        'person_email_taken' => ['error', 'That school email is already used by another person.'],
    ];
    if ($flashMsg !== '' && isset($flashMap[$flashMsg])) {
        [$ftone, $ftext] = $flashMap[$flashMsg];
        if ($flashMsg === 'section_conflict') {
            $conflictCourse = trim((string)($_GET['conflict_course'] ?? ''));
            $conflictReason = trim((string)($_GET['conflict_reason'] ?? ''));
            if ($conflictCourse !== '') {
                $ftext .= ' Conflicts with ' . $conflictCourse . '.';
            }
            if ($conflictReason === 'room') {
                $ftext = 'Room schedule conflict — that room already has a class at overlapping days/times this term.' . ($conflictCourse !== '' ? (' Conflicts with ' . $conflictCourse . '.') : '');
            }
        }
        if ($ftone === 'error') {
            $fcls = ui_flash('error');
        } elseif ($ftone === 'success') {
            $fcls = ui_flash('success');
        } else {
            $fcls = ui_flash('warn');
        }
        echo '<div class="mb-6 ' . $fcls . '">' . htmlspecialchars($ftext) . '</div>';
    }
    ?>
    <div class="min-w-0">
      <aside id="adminSidebar" class="fixed left-0 top-[6.25rem] z-20 hidden h-[calc(100vh-6.25rem)] w-[18rem] overflow-y-auto border-r border-slate-200 bg-white px-4 py-6 transition-transform duration-200 ease-out dark:border-slate-800 dark:bg-slate-900 lg:block lg:top-[5.5rem] lg:h-[calc(100vh-5.5rem)]">
        <div class="pr-1">
          <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Navigation</div>
          <nav class="mt-4">
            <?php require view_path('partials/admin_full_nav.php'); ?>
          </nav>
          <form method="post" action="<?= htmlspecialchars(url('/logout.php')) ?>" class="mt-4">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
            <button type="submit" class="block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-800 dark:hover:bg-rose-950/50">Log out</button>
          </form>
          <p class="mt-4 text-xs leading-relaxed text-slate-500">
            <strong class="text-slate-600"><?= htmlspecialchars($roleLabel) ?>:</strong> <?= htmlspecialchars(preg_replace('/^[^:]+:\s*/', '', $roleNote)) ?>
          </p>
        </div>
      </aside>
      <div class="mx-auto w-full max-w-[min(100vw-2rem,110rem)] min-w-0">
        <?php if ($view === 'dashboard'): ?>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Dashboard</h1>
              <p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>"><?= htmlspecialchars($roleNote) ?> Enrollment activity, data quality, and this term’s busiest sections are below.<?php if ($isAdmin): ?> <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=team')) ?>">Team access</a><?php endif; ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
              <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=registration')) ?>">Registration</a>
              <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=holds')) ?>" title="Look up holds for one student by ID">Student hold lookup</a>
            </div>
          </div>

          <div class="mt-6">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">People</div>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Undergraduates</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['undergrad'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500"><?= (int)($counts['undergrad_ft'] ?? 0) ?> full-time · <?= (int)($counts['undergrad_pt'] ?? 0) ?> part-time</div>
              </a>
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Graduates</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['grad'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500">Masters and PhD</div>
              </a>
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Faculty full-time</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['faculty_ft'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500"><?= (int)$counts['faculty'] ?> faculty total</div>
              </a>
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Faculty part-time</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['faculty_pt'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500">Adjunct and part-time load</div>
              </a>
            </div>
          </div>

          <div class="mt-6">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Campus and catalog</div>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Departments</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['departments'] ?? 0) ?></div>
              </a>
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=catalog')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Catalog courses</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['courses_catalog'] ?? 0) ?></div>
              </a>
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="text-xs font-semibold uppercase text-slate-500">Buildings</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['buildings'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500"><?= (int)($counts['rooms'] ?? 0) ?> rooms · <?= (int)($counts['offices'] ?? 0) ?> offices</div>
              </div>
              <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=terms')) ?>">
                <div class="text-xs font-semibold uppercase text-slate-500">Semesters</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['semesters'] ?? 0) ?></div>
                <div class="mt-2 text-xs text-slate-500"><?= (int)$counts['holds_active'] ?> active holds</div>
              </a>
            </div>
          </div>

          <div id="admin-alerts" class="mt-6 scroll-mt-28 rounded-2xl border border-amber-200 bg-amber-50/90 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/40">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div class="text-sm font-semibold text-amber-950 dark:text-amber-100">Alerts &amp; notices</div>
              <div class="flex flex-wrap gap-2 text-xs">
                <div class="group relative admin-notif-wrap" tabindex="0" data-notif-wrap>
                  <a class="<?= htmlspecialchars(ui_alert_pill()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=schedule&q=%40ashford.edu')) ?>">Email gaps (<?= (int)($dash['students_missing_email'] ?? 0) + (int)($dash['faculty_missing_email'] ?? 0) ?>)</a>
                  <?php if ($adminNotifEmailGapPreviews !== []): ?>
                    <?php
                      $previews = $adminNotifEmailGapPreviews;
                      $panelClass = 'left-0 top-full z-50 w-[min(22rem,92vw)] sm:left-auto sm:right-0';
                    ?>
                    <?php require __DIR__ . '/../app/views/partials/admin_notif_hover_panel.php'; ?>
                  <?php endif; ?>
                </div>
                <div class="group relative admin-notif-wrap" tabindex="0" data-notif-wrap>
                  <a class="<?= htmlspecialchars(ui_alert_pill()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=schedule&q=%28')) ?>">Phone checks (<?= (int)($dash['students_missing_phone'] ?? 0) + (int)($dash['faculty_missing_phone'] ?? 0) ?>)</a>
                  <?php if ($adminNotifPhoneGapPreviews !== []): ?>
                    <?php
                      $previews = $adminNotifPhoneGapPreviews;
                      $panelClass = 'left-0 top-full z-50 w-[min(22rem,92vw)] sm:left-auto sm:right-0';
                    ?>
                    <?php require __DIR__ . '/../app/views/partials/admin_notif_hover_panel.php'; ?>
                  <?php endif; ?>
                </div>
                <div class="group relative admin-notif-wrap" tabindex="0" data-notif-wrap>
                  <a class="<?= htmlspecialchars(ui_alert_pill()) ?>" title="Open the full list of active registration holds" href="<?= htmlspecialchars(url('/admin.php?view=holds#admin-active-holds-list')) ?>">Active holds (<?= (int)$counts['holds_active'] ?>)</a>
                  <?php if (($notifPreviews['holds']['total'] ?? 0) > 0): ?>
                    <?php
                      $previews = [$notifPreviews['holds']];
                      $panelClass = 'left-0 top-full z-50 w-[min(22rem,92vw)] sm:left-auto sm:right-0';
                    ?>
                    <?php require __DIR__ . '/../app/views/partials/admin_notif_hover_panel.php'; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <p class="mt-2 text-xs text-amber-900/90">Tip: use the header search to open <strong class="font-semibold">Master schedule</strong> with your query — fastest way to find people by ID, name, email, or phone.</p>
          </div>

          <div class="mt-6 <?= htmlspecialchars(ui_card('p-5')) ?>">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
              <div>
                <div class="<?= htmlspecialchars(ui_label()) ?>">Current term spotlight</div>
                <div class="mt-1 <?= htmlspecialchars(ui_muted()) ?>">
                  <?= $currentTermCode ? ('Top sections — ' . htmlspecialchars($currentTermCode)) : 'No term configured yet.' ?>
                </div>
              </div>
              <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=schedule')) ?>">Open master schedule →</a>
            </div>

            <div class="mt-5 grid gap-6 lg:grid-cols-2">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-800">Top enrolled</div>
                <div class="mt-3 max-h-[min(28rem,55vh)] overflow-auto rounded-xl border border-slate-200">
                  <table class="min-w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-xs font-semibold uppercase text-slate-500 shadow-sm">
                      <tr>
                        <th class="px-3 py-2">Course</th>
                        <th class="px-3 py-2">Enrolled</th>
                        <th class="px-3 py-2">Cap</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                      <?php foreach (($dash['top_enrolled'] ?? []) as $r): ?>
                        <tr class="hover:bg-slate-50/60">
                          <td class="px-3 py-2">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['course_id'] ?? '')) ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></div>
                          </td>
                          <td class="px-3 py-2 font-semibold text-slate-900"><?= (int)($r['enrolled_cnt'] ?? 0) ?></td>
                          <td class="px-3 py-2 text-slate-600"><?= (int)($r['capacity'] ?? 0) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!($dash['top_enrolled'] ?? [])): ?>
                        <tr><td class="px-3 py-4 text-center text-slate-500" colspan="3">No data.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-800">Top waitlisted</div>
                <div class="mt-3 max-h-[min(28rem,55vh)] overflow-auto rounded-xl border border-slate-200">
                  <table class="min-w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-xs font-semibold uppercase text-slate-500 shadow-sm">
                      <tr>
                        <th class="px-3 py-2">Course</th>
                        <th class="px-3 py-2">Waitlist</th>
                        <th class="px-3 py-2">Enrolled</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                      <?php foreach (($dash['top_waitlisted'] ?? []) as $r): ?>
                        <tr class="hover:bg-slate-50/60">
                          <td class="px-3 py-2">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['course_id'] ?? '')) ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></div>
                          </td>
                          <td class="px-3 py-2 font-semibold text-slate-900"><?= (int)($r['waitlisted_cnt'] ?? 0) ?></td>
                          <td class="px-3 py-2 text-slate-600"><?= (int)($r['enrolled_cnt'] ?? 0) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!($dash['top_waitlisted'] ?? [])): ?>
                        <tr><td class="px-3 py-4 text-center text-slate-500" colspan="3">No data.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase text-slate-500">Total students</div>
              <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)$counts['students'] ?></div>
              <div class="mt-2 text-xs text-slate-500"><?= (int)($dash['students_missing_email'] ?? 0) ?> missing email · <?= (int)($dash['students_missing_phone'] ?? 0) ?> missing phone</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase text-slate-500">Total faculty</div>
              <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)$counts['faculty'] ?></div>
              <div class="mt-2 text-xs text-slate-500"><?= (int)($dash['faculty_missing_email'] ?? 0) ?> missing email · <?= (int)($dash['faculty_missing_phone'] ?? 0) ?> missing phone</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase text-slate-500">Active courses (this term)</div>
              <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['courses_active_term'] ?? 0) ?></div>
              <div class="mt-2 text-xs text-slate-500"><?= (int)($dash['term_sections'] ?? 0) ?> sections · <?= (int)($counts['courses_catalog'] ?? 0) ?> courses in catalog</div>
            </div>
            <a class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-900" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">
              <div class="text-xs font-semibold uppercase text-slate-500">Departments</div>
              <div class="mt-2 text-3xl font-semibold tabular-nums"><?= (int)($counts['departments'] ?? 0) ?></div>
              <div class="mt-2 text-xs text-slate-500">
                Term <?= $currentTermCode ? htmlspecialchars($currentTermCode) : '—' ?> ·
                <?= (int)($dash['term_enrolled'] ?? 0) ?> enrolled ·
                <?= (int)($dash['term_waitlisted'] ?? 0) ?> waitlisted ·
                <?= (int)($dash['term_open_seats'] ?? 0) ?> open seats
              </div>
            </a>
          </div>

          <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Enrollment trend</div>
              <p class="mt-1 text-xs text-slate-500">New enrollment rows recorded per month (last 12 months)</p>
              <div class="mt-3 h-56 w-full"><canvas id="chartEnrollmentLine" aria-label="Enrollment trend chart"></canvas></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Students by department</div>
              <p class="mt-1 text-xs text-slate-500">Declared majors / student-department links</p>
              <div class="mt-3 h-56 w-full"><canvas id="chartDeptBar" aria-label="Students per department chart"></canvas></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Course seat status</div>
              <p class="mt-1 text-xs text-slate-500">Enrollment rows for <?= $currentTermCode ? htmlspecialchars($currentTermCode) : 'current term' ?></p>
              <div class="mt-3 h-56 w-full"><canvas id="chartStatusDonut" aria-label="Enrollment status breakdown chart"></canvas></div>
            </div>
          </div>

          <div class="mt-8 grid gap-6 lg:grid-cols-12">
            <div class="lg:col-span-8">
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                  <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent enrollments</div>
                    <div class="mt-1 text-sm text-slate-600">Latest registration activity across sections</div>
                  </div>
                  <a class="text-sm font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=registration')) ?>">Open registration →</a>
                </div>
                <div class="mt-4 max-h-[min(24rem,50vh)] overflow-auto rounded-xl border border-slate-200">
                  <table class="min-w-full text-left text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                      <tr>
                        <th class="px-3 py-2">Student</th>
                        <th class="px-3 py-2">Course</th>
                        <th class="px-3 py-2">When</th>
                        <th class="px-3 py-2">Status</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                      <?php foreach (($dash['recent_enrollments'] ?? []) as $re): ?>
                        <?php
                          $stuName = trim((string)($re['first_name'] ?? '') . ' ' . (string)($re['last_name'] ?? ''));
                          $created = $re['created_at'] ?? '';
                          $createdFmt = is_string($created) && $created !== '' ? date('M j, Y g:i a', strtotime($created)) : '—';
                        ?>
                        <tr class="hover:bg-slate-50/60">
                          <td class="px-3 py-2">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars($stuName !== '' ? $stuName : '—') ?></div>
                            <div class="font-mono text-xs text-slate-500"><?= (int)($re['student_id'] ?? 0) ?></div>
                          </td>
                          <td class="px-3 py-2">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($re['course_id'] ?? '')) ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($re['course_name'] ?? '')) ?></div>
                          </td>
                          <td class="whitespace-nowrap px-3 py-2 text-slate-600"><?= htmlspecialchars($createdFmt) ?></td>
                          <td class="px-3 py-2">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 <?= admin_enrollment_badge_classes((string)($re['status'] ?? '')) ?>">
                              <?= htmlspecialchars(ucfirst((string)($re['status'] ?? ''))) ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (!($dash['recent_enrollments'] ?? [])): ?>
                        <tr><td class="px-3 py-6 text-center text-slate-500" colspan="4">No enrollment rows yet.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <div class="lg:col-span-4">
              <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quick actions</div>
                <ul class="mt-4 space-y-2 text-sm">
                  <li><a class="inline-flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-semibold text-slate-900 hover:bg-slate-100" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>"><?= $isViewer ? 'Look up a student' : 'Students' ?> <span aria-hidden="true">→</span></a></li>
                  <li><a class="inline-flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-semibold text-slate-900 hover:bg-slate-100" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>"><?= $isViewer ? 'Look up faculty' : 'Faculty' ?> <span aria-hidden="true">→</span></a></li>
                  <li><a class="inline-flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-semibold text-slate-900 hover:bg-slate-100" href="<?= htmlspecialchars(url('/admin.php?view=schedule')) ?>"><?= $isViewer ? 'View the schedule' : 'Master schedule' ?> <span aria-hidden="true">→</span></a></li>
                  <li><a class="inline-flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-semibold text-slate-900 hover:bg-slate-100" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">Departments <span aria-hidden="true">→</span></a></li>
                  <?php if ($canPostGrades): ?>
                    <li><a class="inline-flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 font-semibold text-slate-900 hover:bg-slate-100" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">Enter a grade <span aria-hidden="true">→</span></a></li>
                  <?php endif; ?>
                </ul>
                <p class="mt-4 text-xs leading-relaxed text-slate-500"><?= $isViewer ? 'This login can open records and cannot save changes.' : 'Signed in as ' . htmlspecialchars($user) . '. Use lookup when you already have an ID.' ?></p>
              </div>
            </div>
          </div>

          <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent activity</div>
              <p class="mt-1 text-sm text-slate-600">Latest actions logged from this admin portal</p>
              <ul class="mt-4 max-h-80 space-y-3 overflow-y-auto text-sm">
                <?php foreach (($dash['audit_recent'] ?? []) as $ar): ?>
                  <?php
                    $ts = $ar['created_at'] ?? '';
                    $tsFmt = is_string($ts) && $ts !== '' ? date('M j, g:i a', strtotime($ts)) : '';
                  ?>
                  <li class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                    <div class="flex items-start justify-between gap-2">
                      <span class="font-semibold text-slate-900"><?= htmlspecialchars((string)($ar['action'] ?? '')) ?></span>
                      <span class="shrink-0 text-xs text-slate-400"><?= htmlspecialchars($tsFmt) ?></span>
                    </div>
                    <div class="mt-0.5 text-xs text-slate-500"><?= htmlspecialchars((string)($ar['actor'] ?? '')) ?></div>
                    <?php if (!empty($ar['details'])): ?>
                      <div class="mt-1 text-xs text-slate-600"><?= htmlspecialchars((string)$ar['details']) ?></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
                <?php if (!($dash['audit_recent'] ?? [])): ?>
                  <li class="text-sm text-slate-500">No audit entries yet — actions like registration changes will appear here.</li>
                <?php endif; ?>
              </ul>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Needs attention (detail)</div>
              <ul class="mt-4 space-y-2 text-sm">
                <li class="flex items-center justify-between gap-3">
                  <a class="font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=schedule&q=%40ashford.edu')) ?>">Verify school emails</a>
                  <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?= (int)($dash['students_missing_email'] ?? 0) + (int)($dash['faculty_missing_email'] ?? 0) ?></span>
                </li>
                <li class="flex items-center justify-between gap-3">
                  <a class="font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=schedule&q=%28')) ?>">Check phone formatting</a>
                  <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?= (int)($dash['students_missing_phone'] ?? 0) + (int)($dash['faculty_missing_phone'] ?? 0) ?></span>
                </li>
                <li>
                  <a class="flex items-center justify-between gap-3 rounded-xl px-2 py-2 -mx-2 text-sm font-semibold text-indigo-700 hover:bg-slate-50 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=holds#admin-active-holds-list')) ?>">
                    <span>Active holds</span>
                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900"><?= (int)$counts['holds_active'] ?></span>
                  </a>
                </li>
              </ul>
              <p class="mt-4 text-xs text-slate-500"><strong class="font-semibold text-slate-700">Exam season / approvals:</strong> wire calendar milestones here when you add workflow tables.</p>
            </div>
          </div>

          <?php
            $chartMonthLabels = json_encode($dash['chart_month_labels'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartMonthValues = json_encode($dash['chart_month_values'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartDeptLabels = json_encode($dash['chart_dept_labels'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartDeptValues = json_encode($dash['chart_dept_values'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartStatusLabels = json_encode($dash['chart_status_labels'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartStatusValues = json_encode($dash['chart_status_values'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $chartStatusColors = json_encode($dash['chart_status_colors'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
          ?>
          <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
          <script>
          (function () {
            var ChartRef = window.Chart;
            if (!ChartRef) return;

            var monthLabels = <?= $chartMonthLabels ?>;
            var monthVals = <?= $chartMonthValues ?>;
            var deptLabels = <?= $chartDeptLabels ?>;
            var deptVals = <?= $chartDeptValues ?>;
            var stLabels = <?= $chartStatusLabels ?>;
            var stVals = <?= $chartStatusValues ?>;
            var stColors = <?= $chartStatusColors ?>;

            var lineEl = document.getElementById('chartEnrollmentLine');
            if (lineEl && monthLabels.length) {
              new ChartRef(lineEl, {
                type: 'line',
                data: {
                  labels: monthLabels,
                  datasets: [{
                    label: 'Enrollment rows',
                    data: monthVals,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    fill: true,
                    tension: 0.25,
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: {
                    x: { ticks: { maxRotation: 45, minRotation: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                  }
                }
              });
            }

            var barEl = document.getElementById('chartDeptBar');
            if (barEl && deptLabels.length) {
              new ChartRef(barEl, {
                type: 'bar',
                data: {
                  labels: deptLabels,
                  datasets: [{
                    label: 'Students',
                    data: deptVals,
                    backgroundColor: 'rgba(14, 165, 233, 0.65)',
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: { legend: { display: false } },
                  scales: {
                    x: { ticks: { maxRotation: 35, minRotation: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                  }
                }
              });
            }

            var donutEl = document.getElementById('chartStatusDonut');
            if (donutEl && stLabels.length) {
              new ChartRef(donutEl, {
                type: 'doughnut',
                data: {
                  labels: stLabels,
                  datasets: [{
                    data: stVals,
                    backgroundColor: stColors.length ? stColors : ['#64748b'],
                  }]
                },
                options: {
                  responsive: true,
                  maintainAspectRatio: false,
                  plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12 } }
                  }
                }
              });
            }
          })();
          </script>

        <?php elseif ($view === 'reports'): ?>
          <?php
          $reportsEnrollmentRows = [];
          if ($currentTermId !== null && $isAdmin) {
              try {
                  $rq = $pdo->prepare('
                    SELECT
                      c.course_id,
                      c.course_name,
                      s.section_id,
                      t.code AS term_code,
                      s.capacity,
                      (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.status = "enrolled") AS enrolled_cnt,
                      (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.status = "waitlisted") AS waitlisted_cnt
                    FROM sections s
                    INNER JOIN courses c ON c.course_id = s.course_id
                    INNER JOIN terms t ON t.term_id = s.term_id
                    WHERE s.term_id = ?
                    ORDER BY c.course_id, s.section_id
                    LIMIT 200
                  ');
                  $rq->execute([$currentTermId]);
                  $reportsEnrollmentRows = $rq->fetchAll(PDO::FETCH_ASSOC) ?: [];
              } catch (Throwable) {
              }
          }
          ?>
          <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Reports &amp; Analytics</h1>
          <p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">Enrollment by section for the current term, CSV export, and links to operational views.</p>
          <div class="mt-6 flex flex-wrap gap-3">
            <?php if ($isAdmin && $currentTermCode !== null): ?>
              <a class="<?= htmlspecialchars(ui_btn_primary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=reports&export=enrollment_summary')) ?>">Download enrollment CSV (<?= htmlspecialchars($currentTermCode) ?>)</a>
            <?php endif; ?>
            <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=dashboard')) ?>">Dashboard charts</a>
            <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=enrollment')) ?>">Enrollment</a>
            <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">Departments</a>
            <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=schedule')) ?>">Master schedule</a>
          </div>
          <?php if ($isAdmin && $currentTermCode !== null): ?>
            <div class="mt-8 <?= htmlspecialchars(ui_table_wrap()) ?>">
              <table class="min-w-full text-left text-sm">
                <thead class="<?= htmlspecialchars(ui_thead()) ?>">
                  <tr>
                    <th class="px-4 py-3">Course</th>
                    <th class="px-4 py-3">Section</th>
                    <th class="px-4 py-3">Term</th>
                    <th class="px-4 py-3">Enrolled</th>
                    <th class="px-4 py-3">Cap</th>
                    <th class="px-4 py-3">Waitlist</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                  <?php foreach ($reportsEnrollmentRows as $rr): ?>
                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50">
                      <td class="px-4 py-3">
                        <div class="font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars((string)($rr['course_id'] ?? '')) ?></div>
                        <div class="text-xs text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)($rr['course_name'] ?? '')) ?></div>
                      </td>
                      <td class="px-4 py-3 font-mono text-xs">#<?= (int)($rr['section_id'] ?? 0) ?></td>
                      <td class="px-4 py-3"><?= htmlspecialchars((string)($rr['term_code'] ?? '')) ?></td>
                      <td class="px-4 py-3 tabular-nums"><?= (int)($rr['enrolled_cnt'] ?? 0) ?></td>
                      <td class="px-4 py-3 tabular-nums"><?= (int)($rr['capacity'] ?? 0) ?></td>
                      <td class="px-4 py-3 tabular-nums"><?= (int)($rr['waitlisted_cnt'] ?? 0) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$reportsEnrollmentRows): ?>
                    <tr><td class="px-4 py-8 text-center <?= htmlspecialchars(ui_muted()) ?>" colspan="6">No sections for the current term, or you need admin access to load this table.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">CSV includes all sections for the current term (not truncated).</p>
          <?php elseif (!$isAdmin): ?>
            <p class="mt-6 text-sm text-slate-600">CSV export and the detailed table require an administrator role. Use the dashboard for charts.</p>
          <?php endif; ?>

        <?php elseif ($view === 'catalog'): ?>
          <?php if (!$isAdmin): ?>
            <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Course catalog</h1>
            <div class="mt-4 <?= htmlspecialchars(ui_flash('warn')) ?>">Catalog editing requires an administrator role.</div>
          <?php else: ?>
            <?php
            $catalogFlash = trim((string)($_GET['msg'] ?? ''));
            $catalogFlashMap = [
                'course_saved' => ['success', 'Course saved.'],
                'prereqs_saved' => ['success', 'Prerequisites updated.'],
                'catalog_invalid' => ['error', 'Could not save — check course ID, name, and credits.'],
            ];
            if ($catalogFlash !== '' && isset($catalogFlashMap[$catalogFlash])) {
                [$ct, $ctxt] = $catalogFlashMap[$catalogFlash];
                echo '<div class="mb-6 ' . ui_flash($ct === 'success' ? 'success' : 'error') . '">' . htmlspecialchars($ctxt) . '</div>';
            }
            $catalogCourses = [];
            try {
                $catalogCourses = $pdo->query('
                  SELECT c.course_id, c.course_name, c.credits, c.dept_id, c.description,
                    IFNULL(c.is_active, 1) AS is_active, d.dept_name,
                    (SELECT COUNT(*) FROM course_prereqs cp WHERE cp.course_id = c.course_id) AS prereq_count
                  FROM courses c
                  LEFT JOIN departments d ON d.dept_id = c.dept_id
                  ORDER BY c.course_id
                ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $catalogCourses = $pdo->query('
                  SELECT c.course_id, c.course_name, c.credits, c.dept_id, d.dept_name,
                    (SELECT COUNT(*) FROM course_prereqs cp WHERE cp.course_id = c.course_id) AS prereq_count
                  FROM courses c
                  LEFT JOIN departments d ON d.dept_id = c.dept_id
                  ORDER BY c.course_id
                ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($catalogCourses as &$cc) {
                    $cc['description'] = null;
                    $cc['is_active'] = 1;
                    $cc['prereq_count'] = (int)($cc['prereq_count'] ?? 0);
                }
                unset($cc);
            }
            $departments = $pdo->query('SELECT dept_id, dept_name FROM departments ORDER BY dept_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $editCourse = null;
            $editPrereqs = [];
            $ek = strtoupper(trim((string)($_GET['edit'] ?? '')));
            if ($ek !== '') {
                $st = $pdo->prepare('SELECT * FROM courses WHERE course_id = ? LIMIT 1');
                $st->execute([$ek]);
                $editCourse = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($editCourse) {
                    $pre = $pdo->prepare('SELECT prereq_course_id FROM course_prereqs WHERE course_id = ?');
                    $pre->execute([$ek]);
                    $editPrereqs = array_map('strval', $pre->fetchAll(PDO::FETCH_COLUMN) ?: []);
                }
            }
            $acr = $pdo->query('SELECT course_id FROM courses ORDER BY course_id');
            $allCourseIds = $acr ? array_map('strval', $acr->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            require __DIR__ . '/../app/views/pages/admin/catalog_manage.php';
            ?>
          <?php endif; ?>

        <?php elseif ($view === 'terms'): ?>
          <?php if (!$isAdmin): ?>
            <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Terms</h1>
            <div class="mt-4 <?= htmlspecialchars(ui_flash('warn')) ?>">Term registration settings require an administrator role.</div>
          <?php else: ?>
            <?php
            $termFlash = trim((string)($_GET['msg'] ?? ''));
            $termFlashMap = [
                'term_saved' => ['success', 'Term registration settings saved.'],
                'invalid' => ['error', 'Invalid request.'],
                'term_save_failed' => ['error', 'Could not save — run database migrations for registration columns.'],
            ];
            if ($termFlash !== '' && isset($termFlashMap[$termFlash])) {
                [$tt, $ttxt] = $termFlashMap[$termFlash];
                echo '<div class="mb-6 ' . ui_flash($tt === 'success' ? 'success' : 'error') . '">' . htmlspecialchars($ttxt) . '</div>';
            }
            $termsRows = [];
            try {
                $termsRows = $pdo->query('
                  SELECT term_id, code, name, registration_open, registration_start, registration_end
                  FROM terms ORDER BY start_date DESC
                ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $termsRows = $pdo->query('SELECT term_id, code, name FROM terms ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($termsRows as &$tr) {
                    $tr['registration_open'] = 1;
                    $tr['registration_start'] = null;
                    $tr['registration_end'] = null;
                }
                unset($tr);
                echo '<div class="mb-6 ' . ui_flash('warn') . '">Registration window columns are missing — run migrations, then save again.</div>';
            }
            require __DIR__ . '/../app/views/pages/admin/terms_registration.php';
            ?>
          <?php endif; ?>

        <?php elseif ($view === 'holds'): ?>
          <?php
          $holdRows = [];
          try {
              $rawHoldRows = $pdo->query('
                SELECT h.student_id, h.hold_type, h.note, h.created_at, u.first_name, u.last_name
                FROM student_holds h
                LEFT JOIN users u ON u.user_id = h.student_id
                WHERE h.is_active = 1
                ORDER BY h.created_at DESC
                LIMIT 500
              ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
              foreach ($rawHoldRows as $holdRow) {
                $studentKey = (string)((int)($holdRow['student_id'] ?? 0));
                if (!isset($holdRows[$studentKey])) {
                  $holdRows[$studentKey] = [
                    'student_id' => (int)($holdRow['student_id'] ?? 0),
                    'first_name' => $holdRow['first_name'] ?? null,
                    'last_name' => $holdRow['last_name'] ?? null,
                    'holds' => [],
                  ];
                }
                $holdRows[$studentKey]['holds'][] = [
                  'hold_type' => $holdRow['hold_type'] ?? '',
                  'note' => $holdRow['note'] ?? '',
                  'created_at' => $holdRow['created_at'] ?? '',
                ];
              }
              $holdRows = array_values($holdRows);
          } catch (Throwable) {
          }
          require __DIR__ . '/../app/views/pages/admin/holds_directory.php';
          ?>

        <?php elseif ($view === 'team'): ?>
          <?php if (!$isAdmin): ?>
            <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Team access</h1>
            <div class="mt-4 <?= htmlspecialchars(ui_flash('warn')) ?>">Team access requires an administrator role.</div>
          <?php else: ?>
            <?php
            $teamEmails = [
                'zshah2@oldwestbury.edu',
                'sraza9@oldwestbury.edu',
                'wbhatti1@oldwestbury.edu',
                'asewell3@oldwestbury.edu',
                'viewonly@ashford.edu',
            ];
            $teamRows = [];
            try {
                $placeholders = implode(',', array_fill(0, count($teamEmails), '?'));
                $teamSt = $pdo->prepare("SELECT username, display_name, email, role FROM auth_users WHERE email IN ($placeholders)");
                $teamSt->execute($teamEmails);
                $byEmail = [];
                foreach ($teamSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $teamRow) {
                    $byEmail[strtolower((string)$teamRow['email'])] = $teamRow;
                }
                foreach ($teamEmails as $teamEmail) {
                    if (isset($byEmail[$teamEmail])) {
                        $teamRows[] = $byEmail[$teamEmail];
                    }
                }
            } catch (Throwable) {
                $teamRows = [];
            }
            require __DIR__ . '/../app/views/pages/admin/team_access.php';
            ?>
          <?php endif; ?>

        <?php elseif ($view === 'accounts'): ?>
          <?php if (!$isAdmin): ?>
            <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Accounts</h1>
            <div class="mt-4 <?= htmlspecialchars(ui_flash('warn')) ?>">Account management requires an administrator role.</div>
          <?php else: ?>
            <?php
            $acctFlash = trim((string)($_GET['msg'] ?? ''));
            $acctFlashMap = [
                'pwd_reset' => ['success', 'Password updated.'],
                'pwd_invalid' => ['error', 'Password must be at least 8 characters.'],
                'active_saved' => ['success', 'Account access updated.'],
                'self_not_allowed' => ['error', 'You cannot deactivate your own account.'],
                'invalid' => ['error', 'Invalid request.'],
                'active_failed' => ['error', 'Could not update — ensure auth_users.is_active exists (run migrations).'],
                'login_saved' => ['success', 'Login updated (email and/or password).'],
                'email_saved' => ['success', 'Email updated.'],
                'email_taken' => ['error', 'That email is already used by another account.'],
                'email_invalid' => ['error', 'Enter a valid email address.'],
                'email_failed' => ['error', 'Could not save email — run migrations (auth_users.email).'],
            ];
            if ($acctFlash !== '' && isset($acctFlashMap[$acctFlash])) {
                [$at, $atxt] = $acctFlashMap[$acctFlash];
                echo '<div class="mb-6 ' . ui_flash($at === 'success' ? 'success' : 'error') . '">' . htmlspecialchars($atxt) . '</div>';
            }
            $authRows = [];
            try {
                $authRows = $pdo->query('SELECT id, username, display_name, email, role, IFNULL(is_active, 1) AS is_active FROM auth_users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                try {
                    $authRows = $pdo->query('SELECT id, username, role, IFNULL(is_active, 1) AS is_active FROM auth_users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    $authRows = $pdo->query('SELECT id, username, role FROM auth_users ORDER BY username')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                foreach ($authRows as &$ar) {
                    $ar['is_active'] = 1;
                    if (!array_key_exists('email', $ar)) {
                        $ar['email'] = null;
                    }
                }
                unset($ar);
            }
            require __DIR__ . '/../app/views/pages/admin/accounts_manage.php';
            ?>
          <?php endif; ?>

        <?php elseif ($view === 'messages'): ?>
          <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Messages</h1>
          <p class="mt-2 max-w-2xl <?= htmlspecialchars(ui_muted()) ?>">In-app messaging is not enabled yet. Use official email for registrar communications.</p>
          <div class="mt-6 <?= htmlspecialchars(ui_empty()) ?>">
            Coming soon — optional inbox tied to holds and registration events.
          </div>

        <?php elseif ($view === 'settings'): ?>
          <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Settings</h1>
          <p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">Your login and shortcuts.</p>
          <?php
            $settingsFlash = trim((string)($_GET['msg'] ?? ''));
            $settingsFlashMap = [
                'login_saved' => ['success', 'Your login was updated.'],
                'pwd_invalid' => ['error', 'New password must be at least 8 characters.'],
                'pwd_mismatch' => ['error', 'New passwords do not match.'],
                'self_bad_password' => ['error', 'Current password is incorrect.'],
                'email_taken' => ['error', 'That email is already used by another account.'],
                'email_invalid' => ['error', 'Enter a valid email address.'],
                'self_failed' => ['error', 'Could not update login.'],
                'invalid' => ['error', 'Invalid request.'],
            ];
            if ($settingsFlash !== '' && isset($settingsFlashMap[$settingsFlash])) {
                [$st, $stxt] = $settingsFlashMap[$settingsFlash];
                echo '<div class="mb-6 ' . ui_flash($st === 'success' ? 'success' : 'error') . '">' . htmlspecialchars($stxt) . '</div>';
            }
            $selfAccount = auth_fetch_user_by_id((int)($_SESSION['auth']['id'] ?? 0)) ?? [];
            require __DIR__ . '/../app/views/pages/admin/account_settings.php';
          ?>
          <h2 class="mt-10 text-lg font-semibold text-slate-900 dark:text-white">Shortcuts</h2>
          <ul class="mt-3 space-y-3 text-sm">
            <li><a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=holds')) ?>">Active holds directory</a></li>
            <li><a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin/holds')) ?>">Legacy holds page</a></li>
            <li><a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/')) ?>">Public site home</a></li>
            <?php if ($isAdmin): ?>
              <li><a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=accounts')) ?>">Manage all accounts</a></li>
            <?php endif; ?>
            <li><a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/login.php')) ?>">Sign-in page</a> <span class="text-slate-500 dark:text-slate-400">(sign out first to create another admin)</span></li>
          </ul>
          <p class="mt-6 text-xs text-slate-500 dark:text-slate-400">Your role: <strong class="font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($roleLabel) ?></strong></p>

        <?php elseif ($view === 'people'): ?>
          <?php
          $peoplePanel = trim((string)($_GET['people_panel'] ?? ''));
          if ($peoplePanel !== 'hold' && $peoplePanel !== 'info') {
              $peoplePanel = '';
          }
          $isStu = false;
          $isFac = false;
          if ($peopleId !== null) {
              try {
                  $stChk = $pdo->prepare('SELECT COUNT(*) FROM students WHERE student_id = ?');
                  $stChk->execute([$peopleId]);
                  $isStu = (int)$stChk->fetchColumn() > 0;
                  $fcChk = $pdo->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id = ?');
                  $fcChk->execute([$peopleId]);
                  $isFac = (int)$fcChk->fetchColumn() > 0;
              } catch (Throwable) {
              }
          }
          ?>
          <?php $idLookupHasSearch = $peopleId !== null; ?>
          <div class="<?= $idLookupHasSearch ? 'space-y-6' : 'space-y-8' ?>">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">Students &amp; faculty</h1>
                <?php if (!$idLookupHasSearch): ?>
                  <p class="mt-2 text-sm text-slate-600">Enter a student or faculty numeric ID to load their record.</p>
                <?php else: ?>
                  <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
                    <span>Showing the record for</span>
                    <?php if ($isStu): ?>
                      <span class="inline-flex items-center gap-2 rounded-lg bg-sky-50 px-2 py-1 ring-1 ring-sky-200/80">
                        <span class="font-mono text-sm font-semibold tabular-nums text-sky-950"><?= (int)$peopleId ?></span>
                        <span class="text-[11px] font-bold uppercase tracking-wide text-sky-800">Student</span>
                      </span>
                    <?php elseif ($isFac): ?>
                      <span class="inline-flex items-center gap-2 rounded-lg bg-violet-50 px-2 py-1 ring-1 ring-violet-200/80">
                        <span class="font-mono text-sm font-semibold tabular-nums text-violet-950"><?= (int)$peopleId ?></span>
                        <span class="text-[11px] font-bold uppercase tracking-wide text-violet-800">Faculty</span>
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 ring-1 ring-slate-200">
                        <span class="font-mono text-sm font-semibold tabular-nums text-slate-900"><?= (int)$peopleId ?></span>
                        <span class="text-[11px] font-bold uppercase tracking-wide text-slate-600">User</span>
                      </span>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
              </div>
              <?php if ($idLookupHasSearch): ?>
                <a class="shrink-0 text-sm font-semibold text-indigo-700 hover:text-indigo-900 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">New search</a>
              <?php endif; ?>
            </div>

            <div class="<?= $idLookupHasSearch
                ? 'rounded-2xl border border-slate-200 bg-slate-50/80 p-4 shadow-sm'
                : 'rounded-2xl border border-slate-200 bg-white p-6 shadow-sm' ?>">
              <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end" method="get" action="<?= htmlspecialchars(url('/admin.php')) ?>">
                <input type="hidden" name="view" value="people" />
                <div class="min-w-0 flex-1 sm:max-w-xs">
                  <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-id-q"><?= $idLookupHasSearch ? 'Lookup another ID' : 'Numeric ID' ?></label>
                  <input
                    id="people-id-q"
                    name="id"
                    value="<?= htmlspecialchars($peopleIdRaw) ?>"
                    inputmode="numeric"
                    pattern="\d+"
                    autocomplete="off"
                    placeholder="e.g. 12345"
                    class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 font-mono text-sm text-slate-900 shadow-sm"
                  />
                </div>
                <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 sm:shrink-0" type="submit"><?= $idLookupHasSearch ? 'Search again' : 'Search' ?></button>
              </form>
              <div id="people-recent-searches" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5" aria-live="polite" hidden>
                <div class="flex flex-wrap items-center gap-2">
                  <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recent</span>
                  <div id="people-recent-search-links" class="flex flex-wrap gap-2"></div>
                  <button id="people-clear-recent-searches" type="button" class="text-xs font-semibold text-slate-500 hover:text-slate-900">Clear</button>
                </div>
              </div>
            </div>

            <?php if (!$idLookupHasSearch && $isAdmin): ?>
              <?php
                $addPersonOpen = in_array($flashMsg, ['person_invalid', 'person_exists', 'person_email_taken'], true);
                $addPersonDepts = [];
                try {
                    $addPersonDepts = $pdo->query('SELECT dept_id, dept_name FROM departments ORDER BY dept_name, dept_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    $addPersonDepts = [];
                }
                $suggestedStudentId = people_suggest_next_id($pdo, 'Student');
                $suggestedFacultyId = people_suggest_next_id($pdo, 'Faculty');
                $suggestedId = $suggestedStudentId;
                $stateCodes = admin_people_us_state_codes();
                $dobMax = date('Y-m-d', strtotime('-16 years'));
                $dobMin = date('Y-m-d', strtotime('-80 years'));
              ?>
              <details id="add-person" class="group scroll-mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/40 shadow-sm open:bg-white dark:border-emerald-900 dark:bg-emerald-950/30 dark:open:bg-slate-900"<?= $addPersonOpen ? ' open' : '' ?>>
                <summary class="cursor-pointer list-none px-5 py-4 text-sm font-semibold text-emerald-950 dark:text-emerald-100">
                  <span class="inline-flex items-center gap-2">
                    <span class="rounded-lg bg-emerald-600 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-white">Admin</span>
                    Add person
                  </span>
                  <span class="mt-1 block text-xs font-normal text-emerald-900/80 dark:text-emerald-200/80">Create a student or faculty record in MySQL (not an admin login)</span>
                </summary>
                <form class="border-t border-emerald-200/80 px-5 py-5 dark:border-emerald-900/80" method="post" action="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                  <input type="hidden" name="action" value="people_create" />
                  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-type">Type</label>
                      <select id="ap-type" name="person_type" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="Student">Student</option>
                        <option value="Faculty">Faculty</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-id">ID (7 digits)</label>
                      <input id="ap-id" name="user_id" type="text" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" required value="<?= (int)$suggestedId ?>" data-suggest-student="<?= (int)$suggestedStudentId ?>" data-suggest-faculty="<?= (int)$suggestedFacultyId ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 font-mono text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                      <p id="ap-id-hint" class="mt-1 text-xs text-slate-500">Students: 1000000–1999999 · Faculty: 9000000–9999999</p>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-dept">Department</label>
                      <select id="ap-dept" name="dept_id" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="">Select department…</option>
                        <?php foreach ($addPersonDepts as $d): ?>
                          <option value="<?= htmlspecialchars((string)$d['dept_id']) ?>">
                            <?= htmlspecialchars((string)$d['dept_id']) ?> — <?= htmlspecialchars((string)($d['dept_name'] ?? '')) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-first">First name</label>
                      <input id="ap-first" name="first_name" required maxlength="50" pattern="[\p{L}][\p{L}\s'\-]{0,49}" title="Letters, spaces, hyphen, apostrophe only" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-middle">Middle name</label>
                      <input id="ap-middle" name="middle_name" maxlength="50" pattern="[\p{L}][\p{L}\s'\-]{0,49}" title="Letters, spaces, hyphen, apostrophe only" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-last">Last name</label>
                      <input id="ap-last" name="last_name" required maxlength="50" pattern="[\p{L}][\p{L}\s'\-]{0,49}" title="Letters, spaces, hyphen, apostrophe only" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-email">School email</label>
                      <input id="ap-email" name="email" type="email" required placeholder="jsmith@ashford.edu" pattern="[^@\s]+@ashford\.edu" title="Must end with @ashford.edu" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                      <p class="mt-1 text-xs text-slate-500">Auto-fills from name · must be <span class="font-mono">@ashford.edu</span></p>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-phone">Phone (NY metro)</label>
                      <input id="ap-phone" name="phone" required placeholder="(516) 867-4800" inputmode="tel" title="US phone with NY/NJ/CT/PA/MA area code" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                      <p class="mt-1 text-xs text-slate-500">e.g. <span class="font-mono">(516) 867-4800</span> — area codes like 516, 718, 917, 201…</p>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-gender">Gender</label>
                      <select id="ap-gender" name="gender" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="">Select…</option>
                        <?php foreach (admin_people_genders() as $g): ?>
                          <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-dob">Date of birth</label>
                      <input id="ap-dob" name="dob" type="date" required min="<?= htmlspecialchars($dobMin) ?>" max="<?= htmlspecialchars($dobMax) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                      <p class="mt-1 text-xs text-slate-500">Age must be 16–80</p>
                    </div>
                    <div data-ap-student>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-stu-type">Student load</label>
                      <select id="ap-stu-type" name="student_type" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="Fulltime">Fulltime</option>
                        <option value="Parttime">Parttime</option>
                      </select>
                    </div>
                    <div data-ap-faculty class="hidden">
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-office">Office</label>
                      <input id="ap-office" name="office_number" placeholder="AB-2024" pattern="[A-Za-z][A-Za-z0-9]{0,9}-[A-Za-z0-9]{1,10}" title="Format like AB-2024 or Lib-1106" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                      <p class="mt-1 text-xs text-slate-500">Optional · e.g. <span class="font-mono">AB-2024</span></p>
                    </div>
                    <div data-ap-faculty class="hidden">
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-rank">Rank</label>
                      <select id="ap-rank" name="rank" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="">Select…</option>
                        <option value="Adjunct">Adjunct</option>
                        <option value="Non-Tenured">Non-Tenured</option>
                        <option value="Tenured">Tenured</option>
                      </select>
                    </div>
                    <div data-ap-faculty class="hidden">
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-fac-type">Faculty type</label>
                      <select id="ap-fac-type" name="faculty_type" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="">Select…</option>
                        <option value="Fulltime">Fulltime</option>
                        <option value="Parttime">Parttime</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-street">Street <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                      <input id="ap-street" name="street" maxlength="200" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-city">City <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                      <input id="ap-city" name="city" maxlength="120" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-state">State <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                      <select id="ap-state" name="state" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950">
                        <option value="">—</option>
                        <?php foreach ($stateCodes as $st): ?>
                          <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="ap-zip">ZIP <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                      <input id="ap-zip" name="zip_code" inputmode="numeric" pattern="\d{5}(-\d{4})?" placeholder="11568" maxlength="10" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-950" />
                    </div>
                  </div>
                  <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Create person</button>
                    <p class="text-xs text-slate-500">Opens the new record after save. Admin logins stay on Accounts / Create account.</p>
                  </div>
                </form>
                <script>
                (function () {
                  var root = document.getElementById('add-person');
                  if (!root) return;
                  if (location.hash === '#add-person') root.open = true;

                  var typeEl = document.getElementById('ap-type');
                  var idEl = document.getElementById('ap-id');
                  var firstEl = document.getElementById('ap-first');
                  var lastEl = document.getElementById('ap-last');
                  var emailEl = document.getElementById('ap-email');
                  var rankEl = document.getElementById('ap-rank');
                  var facTypeEl = document.getElementById('ap-fac-type');
                  var emailTouched = false;
                  var idTouched = false;

                  if (emailEl) {
                    emailEl.addEventListener('input', function () { emailTouched = true; });
                  }
                  if (idEl) {
                    idEl.addEventListener('input', function () { idTouched = true; });
                  }

                  function sanitizeLast(s) {
                    return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '') || 'user';
                  }
                  function suggestEmail() {
                    if (!emailEl || emailTouched) return;
                    var f = (firstEl && firstEl.value || '').trim();
                    var l = (lastEl && lastEl.value || '').trim();
                    if (!f || !l) return;
                    var letter = f.charAt(0).toLowerCase().replace(/[^a-z]/g, '') || 'u';
                    emailEl.value = letter + sanitizeLast(l) + '@ashford.edu';
                  }
                  function syncType() {
                    if (!typeEl) return;
                    var isFac = typeEl.value === 'Faculty';
                    root.querySelectorAll('[data-ap-student]').forEach(function (el) {
                      el.classList.toggle('hidden', isFac);
                    });
                    root.querySelectorAll('[data-ap-faculty]').forEach(function (el) {
                      el.classList.toggle('hidden', !isFac);
                    });
                    if (rankEl) rankEl.required = isFac;
                    if (facTypeEl) facTypeEl.required = isFac;
                    if (idEl && !idTouched) {
                      idEl.value = isFac
                        ? (idEl.getAttribute('data-suggest-faculty') || '9000000')
                        : (idEl.getAttribute('data-suggest-student') || '1000000');
                    }
                  }

                  if (typeEl) typeEl.addEventListener('change', syncType);
                  if (firstEl) firstEl.addEventListener('input', suggestEmail);
                  if (lastEl) lastEl.addEventListener('input', suggestEmail);
                  syncType();
                })();
                </script>
              </details>
            <?php elseif (!$idLookupHasSearch): ?>
              <div id="add-person" class="scroll-mt-6 rounded-2xl border border-dashed border-emerald-200 bg-emerald-50/60 p-5 shadow-sm">
                <h2 class="text-sm font-semibold text-emerald-950">Add a person</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-700">
                  Full admins can create student and faculty records here. Your role is read-only or limited — ask an admin, or load people from registrar CSV import.
                </p>
              </div>
            <?php endif; ?>

            <?php if ($peopleId !== null): ?>
            <?php
            $ur = null;
            try {
                $urow = $pdo->prepare('SELECT user_id, first_name, middle_name, last_name, user_type, dob, gender, apt_no, street, city, state, zip_code, email, phone_number FROM users WHERE user_id = ?');
                $urow->execute([$peopleId]);
                $ur = $urow->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $urow = $pdo->prepare('SELECT user_id, first_name, last_name, user_type FROM users WHERE user_id = ?');
                $urow->execute([$peopleId]);
                $ur = $urow->fetch(PDO::FETCH_ASSOC);
            }

            $displayName = '';
            if ($ur) {
                $mn = trim((string)($ur['middle_name'] ?? ''));
                $displayName = trim((string)($ur['first_name'] ?? '') . ($mn !== '' ? ' ' . $mn . ' ' : ' ') . (string)($ur['last_name'] ?? ''));
            }

            $rows = [];
            $holdRows = [];
            $gradeRows = [];
            $stuGpa = null;
            $stuGradedCredits = 0;
            $stuTermEnrolled = 0;
            $stuActiveHoldsCnt = 0;
            $stuDepts = [];
            $stuUgType = '';
            $stuEnrolledAny = 0;
            $srows = [];
            $facultyMeta = null;
            $facDepts = [];
            $facSectionsThisTerm = 0;
            $facTotalSections = 0;
            $enrollmentByTerm = [];
            $enrollmentTermOrder = [];
            $facSectionsByTerm = [];
            $facSectionTermOrder = [];
            $stuDegreeGaps = [];
            $stuPrereqGaps = [];
            $stuUgLimitRow = null;
            $stuUgDetail = null;
            $peopleTermsList = [];

            if ($ur && $isStu) {
                try {
                    $en = $pdo->prepare('
                      SELECT t.code AS term_code, t.name AS term_name, c.course_id, c.course_name, COALESCE(c.credits, 0) AS course_credits,
                        e.status, s.section_id, s.meeting_days, s.meeting_time, s.room
                      FROM enrollments e
                      JOIN sections s ON s.section_id = e.section_id
                      JOIN courses c ON c.course_id = s.course_id
                      JOIN terms t ON t.term_id = s.term_id
                      WHERE e.student_id = ?
                      ORDER BY t.start_date DESC, c.course_id
                      LIMIT 60
                    ');
                    $en->execute([$peopleId]);
                    $rows = $en->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                foreach ($rows as $er) {
                    if (($er['status'] ?? '') === 'enrolled') {
                        ++$stuEnrolledAny;
                    }
                }

                try {
                    $hh = $pdo->prepare('SELECT hold_id, hold_type, note, is_active FROM student_holds WHERE student_id = ? ORDER BY created_at DESC LIMIT 40');
                    $hh->execute([$peopleId]);
                    $holdRows = $hh->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }
                foreach ($holdRows as $h) {
                    if ((int)($h['is_active'] ?? 0) === 1) {
                        ++$stuActiveHoldsCnt;
                    }
                }

                try {
                    $gpaStmt = $pdo->prepare('
                      SELECT
                        COALESCE(SUM(grade_points * credits_earned), 0) AS quality,
                        COALESCE(SUM(CASE WHEN credits_earned > 0 THEN credits_earned ELSE 0 END), 0) AS cr
                      FROM student_course_results
                      WHERE student_id = ?
                    ');
                    $gpaStmt->execute([$peopleId]);
                    $gpaRow = $gpaStmt->fetch(PDO::FETCH_ASSOC);
                    $qc = isset($gpaRow['quality']) ? (float)$gpaRow['quality'] : 0.0;
                    $credSum = isset($gpaRow['cr']) ? (float)$gpaRow['cr'] : 0.0;
                    $stuGradedCredits = (int)round($credSum);
                    if ($credSum > 0.0) {
                        $stuGpa = round($qc / $credSum, 2);
                    }
                } catch (Throwable) {
                }

                if ($currentTermId) {
                    try {
                        $te = $pdo->prepare('
                          SELECT COUNT(*) FROM enrollments e
                          JOIN sections s ON s.section_id = e.section_id
                          WHERE e.student_id = ? AND s.term_id = ? AND e.status = "enrolled"
                        ');
                        $te->execute([$peopleId, $currentTermId]);
                        $stuTermEnrolled = (int)$te->fetchColumn();
                    } catch (Throwable) {
                    }
                }

                try {
                    $dd = $pdo->prepare('
                      SELECT d.dept_id, d.dept_name, sd.date_of_declaration, sd.declaration_role
                      FROM student_departments sd
                      JOIN departments d ON d.dept_id = sd.dept_id
                      WHERE sd.student_id = ?
                      ORDER BY d.dept_name
                    ');
                    $dd->execute([$peopleId]);
                    $stuDepts = $dd->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    $stuDepts = [];
                }

                $allDeptRows = [];
                $deptsAvailableToAdd = [];
                try {
                    $allDeptRows = $pdo->query('SELECT dept_id, dept_name FROM departments ORDER BY dept_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }
                $haveDeptIds = [];
                foreach ($stuDepts ?? [] as $sdRow) {
                    $did = trim((string)($sdRow['dept_id'] ?? ''));
                    if ($did !== '') {
                        $haveDeptIds[$did] = true;
                    }
                }
                foreach ($allDeptRows as $dr) {
                    $id = trim((string)($dr['dept_id'] ?? ''));
                    if ($id !== '' && empty($haveDeptIds[$id])) {
                        $deptsAvailableToAdd[] = $dr;
                    }
                }

                try {
                    $gr = $pdo->prepare('
                      SELECT scr.letter_grade, scr.grade_points, scr.credits_earned, c.course_id, c.course_name, t.code AS term_code, t.name AS term_name
                      FROM student_course_results scr
                      JOIN courses c ON c.course_id = scr.course_id
                      JOIN terms t ON t.term_id = scr.term_id
                      WHERE scr.student_id = ?
                      ORDER BY t.start_date DESC, c.course_id
                      LIMIT 40
                    ');
                    $gr->execute([$peopleId]);
                    $gradeRows = $gr->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                try {
                    $ug = $pdo->prepare('SELECT student_type, academic_year_level, enrollment_intensity FROM undergrad_students WHERE student_id = ?');
                    $ug->execute([$peopleId]);
                    $stuUgDetail = $ug->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($stuUgDetail) {
                        $stuUgType = (string)($stuUgDetail['student_type'] ?? '');
                    }
                } catch (Throwable) {
                }

                $stuEnrollmentDisplay = '';
                $stuClassYearDisplay = '';
                if ($stuUgDetail) {
                    $stuClassYearDisplay = trim((string)($stuUgDetail['academic_year_level'] ?? ''));
                    $stuEnrollmentDisplay = admin_ug_display_enrollment($stuUgDetail);
                }

                try {
                    $ucl = $pdo->prepare('SELECT total_credit_earned, year, max_credit, min_credit FROM ug_credit_limits WHERE student_id = ? LIMIT 1');
                    $ucl->execute([$peopleId]);
                    $stuUgLimitRow = $ucl->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable) {
                }

                try {
                    $dg = $pdo->prepare('
                      SELECT DISTINCT c.course_id, c.course_name, c.credits, d.dept_name, sd.declaration_role, drc.requirement_kind
                      FROM student_departments sd
                      JOIN departments d ON d.dept_id = sd.dept_id
                      JOIN degree_requirement_courses drc ON drc.dept_id = sd.dept_id
                        AND (
                          drc.requirement_kind = "both"
                          OR (drc.requirement_kind = "major" AND sd.declaration_role = "major")
                          OR (drc.requirement_kind = "minor" AND sd.declaration_role = "minor")
                        )
                      JOIN courses c ON c.course_id = drc.course_id
                      WHERE sd.student_id = ?
                      AND NOT EXISTS (
                        SELECT 1 FROM student_course_results scr
                        WHERE scr.student_id = sd.student_id AND scr.course_id = c.course_id AND scr.grade_points > 0
                      )
                      ORDER BY d.dept_name, c.course_id
                    ');
                    $dg->execute([$peopleId]);
                    $stuDegreeGaps = $dg->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                try {
                    $pg = $pdo->prepare('
                      SELECT DISTINCT cp.prereq_course_id, pc.course_name AS prereq_name,
                        fc.course_id AS for_course_id, fc.course_name AS for_course_name
                      FROM enrollments e
                      JOIN sections sec ON sec.section_id = e.section_id
                      JOIN courses fc ON fc.course_id = sec.course_id
                      JOIN course_prereqs cp ON cp.course_id = sec.course_id
                      JOIN courses pc ON pc.course_id = cp.prereq_course_id
                      WHERE e.student_id = ? AND e.status = "enrolled"
                      AND NOT EXISTS (
                        SELECT 1 FROM student_course_results scr
                        WHERE scr.student_id = e.student_id AND scr.course_id = cp.prereq_course_id AND scr.grade_points > 0
                      )
                      ORDER BY fc.course_id, cp.prereq_course_id
                    ');
                    $pg->execute([$peopleId]);
                    $stuPrereqGaps = $pg->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                try {
                    $peopleTermsList = $pdo->query('SELECT term_id, code, name FROM terms ORDER BY start_date DESC LIMIT 36')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                foreach ($rows as $r) {
                    $tc = trim((string)($r['term_code'] ?? ''));
                    if ($tc === '') {
                        $tc = 'Other';
                    }
                    if (!isset($enrollmentByTerm[$tc])) {
                        $enrollmentByTerm[$tc] = [];
                        $enrollmentTermOrder[] = $tc;
                    }
                    $enrollmentByTerm[$tc][] = $r;
                }
            }

            if ($ur && $isFac) {
                try {
                    $sec = $pdo->prepare('
                      SELECT t.code AS term_code, t.name AS term_name, t.start_date, s.section_id, c.course_id, c.course_name,
                        s.meeting_days, s.meeting_time, s.room, s.term_id
                      FROM sections s
                      JOIN courses c ON c.course_id = s.course_id
                      JOIN terms t ON t.term_id = s.term_id
                      WHERE s.faculty_id = ?
                      ORDER BY t.start_date DESC, c.course_id
                      LIMIT 80
                    ');
                    $sec->execute([$peopleId]);
                    $srows = $sec->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }
                $facTotalSections = count($srows);
                if ($currentTermId) {
                    foreach ($srows as $sr) {
                        if ((int)($sr['term_id'] ?? 0) === $currentTermId) {
                            ++$facSectionsThisTerm;
                        }
                    }
                }
                try {
                    $fm = $pdo->prepare('SELECT office_number, `rank`, faculty_type, email, phone_number, hire_date FROM faculty WHERE faculty_id = ?');
                    $fm->execute([$peopleId]);
                    $facultyMeta = $fm->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable) {
                }
                try {
                    $fd = $pdo->prepare('
                      SELECT d.dept_id, d.dept_name, fd.percent_time, fd.date_of_appointment
                      FROM faculty_departments fd
                      JOIN departments d ON d.dept_id = fd.dept_id
                      WHERE fd.faculty_id = ?
                      ORDER BY d.dept_name
                    ');
                    $fd->execute([$peopleId]);
                    $facDepts = $fd->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                }

                foreach ($srows as $r) {
                    $tc = trim((string)($r['term_code'] ?? ''));
                    if ($tc === '') {
                        $tc = 'Other';
                    }
                    if (!isset($facSectionsByTerm[$tc])) {
                        $facSectionsByTerm[$tc] = [];
                        $facSectionTermOrder[] = $tc;
                    }
                    $facSectionsByTerm[$tc][] = $r;
                }
            }
            ?>
            <div class="mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
              <?php if (!$ur): ?>
                <div class="border-b border-slate-200 bg-slate-100 px-5 py-4">
                  <div class="text-sm font-semibold text-slate-800">No match</div>
                  <p class="mt-1 text-sm text-slate-600">There is no user with ID <span class="font-mono font-semibold"><?= (int)$peopleId ?></span>.</p>
                </div>
                <div class="px-5 py-4">
                  <p class="text-sm text-slate-500">Try another ID, or check the number in Master schedule.</p>
                </div>
              <?php else: ?>
                <?php
                $peopleHeroBorder = 'border-indigo-100';
                $peopleHeroBg = 'bg-gradient-to-r from-indigo-600 to-sky-600';
                $peopleHeroSub = 'text-indigo-100';
                $peopleHeroDot = 'text-indigo-200';
                if ($isFac && !$isStu) {
                    $peopleHeroBorder = 'border-violet-200';
                    $peopleHeroBg = 'bg-gradient-to-r from-violet-600 to-fuchsia-600';
                    $peopleHeroSub = 'text-violet-100';
                    $peopleHeroDot = 'text-fuchsia-200';
                } elseif (!$isStu && !$isFac) {
                    $peopleHeroBorder = 'border-slate-200';
                    $peopleHeroBg = 'bg-gradient-to-r from-slate-600 to-slate-800';
                    $peopleHeroSub = 'text-slate-200';
                    $peopleHeroDot = 'text-slate-400';
                }
                ?>
                <div class="border-b <?= htmlspecialchars($peopleHeroBorder, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($peopleHeroBg, ENT_QUOTES, 'UTF-8') ?> px-5 py-4 text-white">
                  <div class="text-xs font-semibold uppercase tracking-wide opacity-90">Record</div>
                  <div class="mt-1 text-xl font-bold tracking-tight"><?= htmlspecialchars($displayName) ?></div>
                  <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm <?= htmlspecialchars($peopleHeroSub, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="font-mono font-semibold text-white">ID <?= (int)$peopleId ?></span>
                    <span class="<?= htmlspecialchars($peopleHeroDot, ENT_QUOTES, 'UTF-8') ?>">·</span>
                    <span><?= htmlspecialchars((string)$ur['user_type']) ?></span>
                    <?php if ($isStu): ?>
                      <span class="rounded-full bg-sky-400/25 px-2 py-0.5 text-xs font-semibold text-white ring-1 ring-white/20">Student</span>
                    <?php elseif ($isFac): ?>
                      <span class="rounded-full bg-fuchsia-400/25 px-2 py-0.5 text-xs font-semibold text-white ring-1 ring-white/20">Faculty</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="p-5">
                <?php
                $__empty = static function (?string $v): bool {
                    return $v === null || trim($v) === '';
                };
                $__fmtDate = static function ($d): string {
                    if ($d === null || $d === '') {
                        return '—';
                    }
                    return htmlspecialchars((string)$d);
                };
                ?>
                <?php if ($isStu): ?>
                  <?php
                  $peopleHoldPresets = admin_people_hold_presets();
                  $peopleGenderOpts = admin_people_genders();
                  $peopleStateCodes = admin_people_us_state_codes();
                  $peopleYearOpts = admin_people_academic_year_levels();
                  $peopleIntOpts = admin_people_enrollment_intensity_options();
                  $peopleCurGender = trim((string)($ur['gender'] ?? ''));
                  $peopleCurState = strtoupper(trim((string)($ur['state'] ?? '')));
                  ?>
                  <div class="mt-1 flex flex-wrap items-center gap-3" id="people-student-actions" data-initial-panel="<?= htmlspecialchars($peoplePanel, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" id="people-btn-open-info" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm font-semibold text-sky-950 shadow-sm hover:bg-sky-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500">Update info</button>
                    <button type="button" id="people-btn-open-hold" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-sm hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">Holds</button>
                    <span class="text-xs text-slate-500">Opens a full-screen panel over this page.</span>
                  </div>
                  <p class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Student snapshot</p>
                  <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Cumulative GPA</div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= $stuGpa !== null ? htmlspecialchars((string)$stuGpa) : '—' ?></div>
                      <div class="mt-1 text-xs text-slate-500"><?= $stuGradedCredits > 0 ? htmlspecialchars((string)$stuGradedCredits) . ' graded credits recorded' : 'No grades in transcript yet' ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500"><?= $currentTermCode !== null ? 'This term · ' . htmlspecialchars($currentTermCode) : 'This term' ?></div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= (int)$stuTermEnrolled ?></div>
                      <div class="mt-1 text-xs text-slate-500"><?= $currentTermCode !== null ? 'Sections enrolled (enrolled status)' : 'Set a current term on the timeline to populate' ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Enrolled registrations</div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= (int)$stuEnrolledAny ?></div>
                      <div class="mt-1 text-xs text-slate-500">All terms · status = enrolled</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Active holds</div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= (int)$stuActiveHoldsCnt ?></div>
                      <div class="mt-1 text-xs text-slate-500"><?= $stuActiveHoldsCnt > 0 ? 'Registration may be blocked' : 'None blocking' ?></div>
                    </div>
                  </div>

                  <div class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/40 px-4 py-3 text-sm text-slate-800">
                    <span class="font-semibold text-slate-900">Credits &amp; GPA — </span>
                    <span class="text-slate-700">Transcript graded credits: <strong class="tabular-nums"><?= (int)$stuGradedCredits ?></strong>.</span>
                    <?php if ($stuUgLimitRow): ?>
                      <span class="text-slate-700"> Registrar cumulative (<code class="rounded bg-white px-1 text-xs">ug_credit_limits</code>): <strong class="tabular-nums"><?= (int)($stuUgLimitRow['total_credit_earned'] ?? 0) ?></strong><?php if (isset($stuUgLimitRow['max_credit'], $stuUgLimitRow['min_credit'])): ?> <span class="text-slate-600">(load band <?= (int)$stuUgLimitRow['min_credit'] ?>–<?= (int)$stuUgLimitRow['max_credit'] ?> cr.)</span><?php endif; ?>.</span>
                    <?php else: ?>
                      <span class="text-slate-600"> No <code class="rounded bg-slate-100 px-1 text-xs">ug_credit_limits</code> row yet — usually loaded from <span class="font-medium">UG_fulltime.csv</span> / <span class="font-medium">UG_parttime.csv</span> import; admins can seed totals under Update info.</span>
                    <?php endif; ?>
                    <span class="block mt-1 text-xs text-slate-600">GPA above is computed from transcript grades only. Use <strong class="font-semibold text-slate-700">Update info</strong> to edit grades — GPA updates after save.</span>
                  </div>

                  <div class="mt-8 grid gap-6 lg:grid-cols-12">
                    <div class="space-y-4 lg:col-span-5">
                      <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="text-sm font-semibold text-slate-900">Personal &amp; contact</h3>
                        <dl class="mt-3 space-y-2 text-sm text-slate-700">
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">DOB</dt><dd class="font-medium"><?= $__fmtDate($ur['dob'] ?? null) ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Gender</dt><dd class="font-medium"><?= $__empty(($ur['gender'] ?? null)) ? '—' : htmlspecialchars((string)$ur['gender']) ?></dd></div>
                          <?php if ($stuUgDetail): ?>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Class year</dt><dd class="font-medium"><?= $stuClassYearDisplay !== '' ? htmlspecialchars($stuClassYearDisplay) : '—' ?><?php if ($stuClassYearDisplay === ''): ?><span class="block text-xs font-normal text-slate-500">Not on file — set under Update info.</span><?php endif; ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Enrollment</dt><dd class="font-medium"><?= $stuEnrollmentDisplay !== '' ? htmlspecialchars($stuEnrollmentDisplay) : '—' ?></dd></div>
                          <?php endif; ?>
                          <?php if ($stuUgType): ?>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Import category</dt><dd class="font-medium text-slate-600"><?= htmlspecialchars((string)$stuUgType) ?> <span class="text-xs">(legacy CSV)</span></dd></div>
                          <?php endif; ?>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Email</dt><dd class="break-all font-medium"><?= $__empty(($ur['email'] ?? null)) ? '—' : htmlspecialchars((string)$ur['email']) ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Phone</dt><dd class="font-medium"><?= $__empty(($ur['phone_number'] ?? null)) ? '—' : htmlspecialchars((string)$ur['phone_number']) ?></dd></div>
                          <div class="border-t border-slate-100 pt-2"><span class="text-slate-500">Address</span>
                            <?php if ($__empty($ur['street'] ?? null) && $__empty($ur['city'] ?? null)): ?>
                              <p class="mt-1 text-slate-600">—</p>
                            <?php else: ?>
                              <p class="mt-1 leading-relaxed text-slate-800"><?= htmlspecialchars(trim(((string)($ur['apt_no'] ?? '') . ' ' . (string)($ur['street'] ?? '')))) ?><br /><?= htmlspecialchars(trim(((string)($ur['city'] ?? '') . ', ' . (string)($ur['state'] ?? '') . ' ' . (string)($ur['zip_code'] ?? '')))) ?></p>
                            <?php endif; ?>
                          </div>
                        </dl>
                      </div>
                      <?php if ($stuDepts): ?>
                      <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="text-sm font-semibold text-slate-900">Departments</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                          <?php foreach ($stuDepts as $sd): ?>
                            <li class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                              <span class="font-medium text-slate-900"><?= htmlspecialchars((string)($sd['dept_name'] ?? '')) ?></span>
                              <span class="text-slate-500"> (<?= htmlspecialchars((string)($sd['dept_id'] ?? '')) ?>)</span>
                              <?php $sdRole = (string)($sd['declaration_role'] ?? 'major'); ?>
                              <span class="ml-2 inline-flex rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-slate-700"><?= $sdRole === 'minor' ? 'Minor' : 'Major' ?></span>
                              <?php if (!empty($sd['date_of_declaration'])): ?>
                                <div class="mt-0.5 text-xs text-slate-500">Declared <?= htmlspecialchars((string)$sd['date_of_declaration']) ?></div>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                      <?php endif; ?>
                    </div>

                    <div class="space-y-6 lg:col-span-7">
                      <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 px-4 py-3">
                          <h3 class="text-sm font-semibold text-slate-900">Course enrollments</h3>
                          <p class="mt-0.5 text-xs text-slate-500">Pick a term tab (e.g. FA27), then review courses and status.</p>
                        </div>
                        <?php if (!$enrollmentTermOrder): ?>
                          <div class="px-4 py-8 text-center text-sm text-slate-500">No enrollment rows.</div>
                        <?php else: ?>
                          <?php if (count($enrollmentTermOrder) > 1): ?>
                            <div class="flex flex-wrap gap-1 border-b border-slate-100 bg-slate-50/60 px-2 pt-2" role="tablist" aria-label="Enrollment by term">
                              <?php foreach ($enrollmentTermOrder as $tc): ?>
                                <button type="button" role="tab" class="people-enroll-tab -mb-px rounded-t-lg border border-slate-200 border-b-0 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-white/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" data-term="<?= htmlspecialchars($tc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tc) ?></button>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                          <?php foreach ($enrollmentTermOrder as $ti => $tc): ?>
                            <?php $termRows = $enrollmentByTerm[$tc] ?? []; ?>
                            <div class="people-enroll-panel overflow-x-auto" data-term="<?= htmlspecialchars($tc, ENT_QUOTES, 'UTF-8') ?>" <?= $ti > 0 ? 'hidden' : '' ?>>
                              <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                  <tr>
                                    <th class="px-3 py-2">Course</th>
                                    <th class="px-3 py-2">Cr</th>
                                    <th class="px-3 py-2">When / where</th>
                                    <th class="px-3 py-2">Status</th>
                                  </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                  <?php foreach ($termRows as $r): ?>
                                    <tr class="hover:bg-slate-50/60">
                                      <td class="px-3 py-2 align-top">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['course_id'] ?? '')) ?></div>
                                        <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)($r['term_name'] ?? '')) ?></div>
                                      </td>
                                      <td class="px-3 py-2 align-top tabular-nums text-slate-700"><?= (int)($r['course_credits'] ?? 0) ?></td>
                                      <td class="px-3 py-2 align-top text-xs text-slate-600">
                                        <?php if (!empty($r['meeting_days']) || !empty($r['meeting_time']) || !empty($r['room'])): ?>
                                          <?= htmlspecialchars(trim(((string)($r['meeting_days'] ?? '') . ' ' . (string)($r['meeting_time'] ?? '')))) ?>
                                          <?php if (!empty($r['room'])): ?><span class="text-slate-500"> · <?= htmlspecialchars((string)$r['room']) ?></span><?php endif; ?>
                                        <?php else: ?>
                                          <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                      </td>
                                      <td class="px-3 py-2 align-top">
                                        <span class="inline-flex rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-800"><?= htmlspecialchars((string)($r['status'] ?? '')) ?></span>
                                      </td>
                                    </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>

                      <?php if ($gradeRows): ?>
                      <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 px-4 py-3">
                          <h3 class="text-sm font-semibold text-slate-900">Transcript (graded courses)</h3>
                          <p class="mt-0.5 text-xs text-slate-500">From registrar grade records.</p>
                        </div>
                        <div class="overflow-x-auto">
                          <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                              <tr>
                                <th class="px-3 py-2">Term</th>
                                <th class="px-3 py-2">Course</th>
                                <th class="px-3 py-2">Grade</th>
                                <th class="px-3 py-2"><abbr title="GPA for this course on a 4.0 scale">GPA</abbr></th>
                                <th class="px-3 py-2"><abbr title="Credits earned toward GPA">Cr</abbr></th>
                              </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                              <?php foreach ($gradeRows as $g): ?>
                                <tr class="hover:bg-slate-50/60">
                                  <td class="px-3 py-2 align-top">
                                    <div class="font-medium text-slate-900"><?= htmlspecialchars((string)($g['term_code'] ?? '')) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($g['term_name'] ?? '')) ?></div>
                                  </td>
                                  <td class="px-3 py-2 align-top">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($g['course_id'] ?? '')) ?></div>
                                    <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($g['course_name'] ?? '')) ?></div>
                                  </td>
                                  <td class="px-3 py-2 align-top font-semibold"><?= htmlspecialchars((string)($g['letter_grade'] ?? '')) ?></td>
                                  <td class="px-3 py-2 align-top tabular-nums"><?= htmlspecialchars((string)$g['grade_points']) ?></td>
                                  <td class="px-3 py-2 align-top tabular-nums"><?= (int)($g['credits_earned'] ?? 0) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div id="people-overlay-info" class="people-stu-overlay fixed inset-0 z-[70] hidden items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="people-overlay-info-title">
                    <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-[2px]" data-close-stu-overlay="info"></div>
                    <div class="relative z-10 flex max-h-[min(90vh,880px)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                      <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-3">
                        <h2 id="people-overlay-info-title" class="text-lg font-semibold text-slate-900">Update information</h2>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-slate-200/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" data-close-stu-overlay="info">Close</button>
                      </div>
                      <div class="min-h-0 overflow-y-auto">
                    <div id="people-panel-info" class="space-y-6 p-4 sm:p-5">
                      <div class="rounded-2xl border border-sky-200/80 bg-sky-50/50 p-4 sm:p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-slate-900">Update information</h3>
                        <p class="mt-1 text-xs text-slate-600">Use this section for contact, address, class year, enrollment, <strong class="font-semibold text-slate-800">majors and minors</strong> (switch roles or add a declaration below), and registrar credits. Leave optional fields blank to keep current values.</p>
                        <?php if ($canManageHolds): ?>
                        <form class="mt-4 grid gap-4 sm:grid-cols-2" method="post" action="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$peopleId . '&people_panel=info')) ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                          <input type="hidden" name="action" value="people_update_student" />
                          <input type="hidden" name="student_id" value="<?= (int)$peopleId ?>" />
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-gender">Gender</label>
                            <select id="people-up-gender" name="gender" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                              <option value="__keep__">Keep current<?= $peopleCurGender !== '' ? ' (' . htmlspecialchars($peopleCurGender) . ')' : '' ?></option>
                              <?php foreach ($peopleGenderOpts as $g): ?>
                                <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-state">State (US)</label>
                            <select id="people-up-state" name="state" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-mono">
                              <option value="__keep__">Keep current<?= $peopleCurState !== '' ? ' (' . htmlspecialchars($peopleCurState) . ')' : '' ?></option>
                              <?php foreach ($peopleStateCodes as $sc): ?>
                                <option value="<?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($sc) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
                            <div>
                              <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-email">Email</label>
                              <input id="people-up-email" name="email" type="email" autocomplete="off" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                            </div>
                            <div>
                              <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-phone">Phone</label>
                              <input id="people-up-phone" name="phone" type="text" inputmode="tel" autocomplete="off" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                            </div>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-apt">Apt / unit</label>
                            <input id="people-up-apt" name="apt_no" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-street">Street</label>
                            <input id="people-up-street" name="street" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-city">City</label>
                            <input id="people-up-city" name="city" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-zip">ZIP</label>
                            <input id="people-up-zip" name="zip_code" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-year">Class year</label>
                            <select id="people-up-year" name="academic_year_level" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                              <option value="__keep__">Keep current<?php $cy = trim((string)(($stuUgDetail ?? [])['academic_year_level'] ?? '')); echo $cy !== '' ? ' (' . htmlspecialchars($cy) . ')' : ''; ?></option>
                              <?php foreach ($peopleYearOpts as $y): ?>
                                <option value="<?= htmlspecialchars($y) ?>"><?= htmlspecialchars($y) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-int">Full-time / part-time</label>
                            <select id="people-up-int" name="enrollment_intensity" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                              <option value="__keep__">Keep current<?php
                              $ciRaw = trim((string)(($stuUgDetail ?? [])['enrollment_intensity'] ?? ''));
                              $ciShow = $ciRaw !== '' ? $ciRaw : (($stuUgDetail ?? null) ? admin_ug_display_enrollment($stuUgDetail) : '');
                              echo $ciShow !== '' ? ' (' . htmlspecialchars($ciShow) . ')' : '';
                              ?></option>
                              <?php foreach ($peopleIntOpts as $io): ?>
                                <option value="<?= htmlspecialchars($io) ?>"><?= htmlspecialchars($io) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <?php if ($stuDepts): ?>
                          <div class="sm:col-span-2 rounded-xl border border-slate-100 bg-slate-50/80 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Major / minor per declaration</div>
                            <p class="mt-1 text-xs text-slate-600">Rules: max 1 major and max 1 minor. Major cannot match minor. Major/minor changes are blocked for Seniors unless an admin override is used.</p>
                            <div class="mt-3 space-y-3">
                              <?php foreach ($stuDepts as $sd): ?>
                                <?php $did = htmlspecialchars((string)($sd['dept_id'] ?? '')); ?>
                                <div class="flex flex-wrap items-center gap-3">
                                  <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars((string)($sd['dept_name'] ?? '')) ?> <span class="text-slate-500">(<?= $did ?>)</span></span>
                                  <select name="declaration_role[<?= $did ?>]" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm">
                                    <?php $dr = (string)($sd['declaration_role'] ?? 'major'); ?>
                                    <option value="major" <?= $dr === 'major' ? 'selected' : '' ?>>Major</option>
                                    <option value="minor" <?= $dr === 'minor' ? 'selected' : '' ?>>Minor</option>
                                  </select>
                                  <?php if ($isAdmin && $dr === 'major'): ?>
                                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-rose-700">
                                      <input type="checkbox" name="remove_major" value="1" />
                                      Remove major
                                    </label>
                                  <?php elseif ($isAdmin && $dr === 'minor'): ?>
                                    <label class="inline-flex items-center gap-2 text-xs font-semibold text-rose-700">
                                      <input type="checkbox" name="remove_minor" value="1" />
                                      Remove minor
                                    </label>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                          <?php endif; ?>
                          <?php if ($deptsAvailableToAdd): ?>
                          <div class="sm:col-span-2 rounded-xl border border-sky-100 bg-sky-50/50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-sky-900">Add major or minor</div>
                            <p class="mt-1 text-xs text-slate-600">Pick a department that is not already listed above and choose whether it is a major or minor, then click <span class="font-semibold">Save updates</span>.</p>
                            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                              <div class="min-w-0 flex-1 sm:max-w-xs">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-add-dept">Department</label>
                                <select id="people-add-dept" name="add_declaration_dept" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                  <option value="">Select department…</option>
                                  <?php foreach ($deptsAvailableToAdd as $dr): ?>
                                    <?php
                                    $optId = (string)($dr['dept_id'] ?? '');
                                    $optName = (string)($dr['dept_name'] ?? '');
                                    $optLabel = $optName !== '' ? $optName . ' (' . $optId . ')' : $optId;
                                    ?>
                                    <option value="<?= htmlspecialchars($optId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($optLabel) ?></option>
                                  <?php endforeach; ?>
                                </select>
                              </div>
                              <div class="sm:w-40">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-add-role">Role</label>
                                <select id="people-add-role" name="add_declaration_role" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                  <option value="major">Major</option>
                                  <option value="minor">Minor</option>
                                </select>
                              </div>
                            </div>
                          </div>
                          <?php elseif ($canManageHolds && $deptsAvailableToAdd === [] && $allDeptRows !== []): ?>
                          <div class="sm:col-span-2 rounded-xl border border-slate-100 bg-slate-50/60 px-4 py-3 text-xs text-slate-600">
                            No departments left to add — this student already has a declaration for every department in the catalog.
                          </div>
                          <?php elseif ($canManageHolds && $allDeptRows === []): ?>
                          <div class="sm:col-span-2 rounded-xl border border-amber-100 bg-amber-50/60 px-4 py-3 text-xs text-amber-950">
                            Cannot add a major or minor until there are rows in <code class="rounded bg-white px-1">departments</code> (run registrar import or insert departments first).
                          </div>
                          <?php endif; ?>
                          <?php if ($isAdmin): ?>
                          <div class="sm:col-span-2 rounded-xl border border-amber-100 bg-amber-50/60 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-amber-950">Admin overrides</div>
                            <p class="mt-1 text-xs text-slate-700">Overrides still log the change. Use sparingly.</p>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2 text-sm text-slate-800">
                              <label class="inline-flex items-center gap-2"><input type="checkbox" name="override_major_limit" value="1" /> Override 3-change major limit</label>
                              <label class="inline-flex items-center gap-2"><input type="checkbox" name="override_final_semester" value="1" /> Override final-semester block</label>
                              <label class="inline-flex items-center gap-2"><input type="checkbox" name="override_gpa" value="1" /> Override GPA minimum</label>
                            </div>
                          </div>
                          <?php endif; ?>
                          <?php if ($isAdmin): ?>
                          <div class="sm:col-span-2 rounded-xl border border-indigo-100 bg-indigo-50/40 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-indigo-950">Undergraduate credit limits (<code class="font-mono text-[11px] font-normal">ug_credit_limits</code>)</div>
                            <p class="mt-1 text-xs text-slate-600">Registration checks <strong class="font-semibold text-slate-800">max credits per term</strong> against enrolled load. Leave any field blank to keep its current value when updating an existing row.</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                              <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-maxcr">Max credits (term load cap)</label>
                                <input id="people-up-maxcr" name="max_credit" type="number" min="1" max="40" step="1" placeholder="<?= $stuUgLimitRow ? (string)(int)($stuUgLimitRow['max_credit'] ?? 18) : 'e.g. 18' ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-mono tabular-nums" />
                              </div>
                              <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-mincr">Min credits (term floor)</label>
                                <input id="people-up-mincr" name="min_credit" type="number" min="1" max="40" step="1" placeholder="<?= $stuUgLimitRow ? (string)(int)($stuUgLimitRow['min_credit'] ?? 12) : 'e.g. 12' ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-mono tabular-nums" />
                              </div>
                              <div>
                                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-up-tce">Registrar total earned</label>
                                <input id="people-up-tce" name="total_credit_earned" type="number" min="0" step="1" placeholder="<?= $stuUgLimitRow ? (string)(int)($stuUgLimitRow['total_credit_earned'] ?? 0) : 'e.g. 90' ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-mono tabular-nums" />
                              </div>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">Creating a new row without CSV import uses min/max defaults from legacy <code class="rounded bg-white px-1">student_type</code> for any blank limits. Import <span class="font-medium">UG_fulltime.csv</span> / <span class="font-medium">UG_parttime.csv</span> for registrar-backed bands.</p>
                          </div>
                          <?php endif; ?>
                          <div class="sm:col-span-2">
                            <button type="submit" class="rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-sky-600">Save updates</button>
                          </div>
                        </form>
                        <?php else: ?>
                        <p class="mt-3 text-sm text-slate-600">Your role cannot edit these fields.</p>
                        <?php endif; ?>
                      </div>

                      <div>
                        <h3 class="text-sm font-semibold text-slate-900">Degree &amp; prerequisite gaps</h3>
                        <p class="mt-1 text-xs text-slate-500">Missing courses use the <code class="rounded bg-slate-100 px-1">degree_requirement_courses</code> catalog (by declared major/minor). Populate it for your programs if this list is empty.</p>
                        <?php if ($stuDegreeGaps): ?>
                          <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200">
                            <table class="min-w-full text-left text-sm">
                              <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500"><tr><th class="px-3 py-2">Dept</th><th class="px-3 py-2">Course</th><th class="px-3 py-2">Cr</th><th class="px-3 py-2">Role</th></tr></thead>
                              <tbody class="divide-y divide-slate-100">
                                <?php foreach ($stuDegreeGaps as $dg): ?>
                                  <tr>
                                    <td class="px-3 py-2"><?= htmlspecialchars((string)($dg['dept_name'] ?? '')) ?></td>
                                    <td class="px-3 py-2"><span class="font-semibold"><?= htmlspecialchars((string)($dg['course_id'] ?? '')) ?></span> · <?= htmlspecialchars((string)($dg['course_name'] ?? '')) ?></td>
                                    <td class="px-3 py-2 tabular-nums"><?= (int)($dg['credits'] ?? 0) ?></td>
                                    <td class="px-3 py-2 text-xs"><?= htmlspecialchars((string)($dg['declaration_role'] ?? '')) ?> / <?= htmlspecialchars((string)($dg['requirement_kind'] ?? '')) ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php else: ?>
                          <p class="mt-2 text-sm text-slate-600">No catalog gaps found (or no requirements loaded for this student’s departments).</p>
                        <?php endif; ?>

                        <h4 class="mt-6 text-xs font-semibold uppercase tracking-wide text-slate-500">Prerequisite gaps (current enrollments)</h4>
                        <?php if ($stuPrereqGaps): ?>
                          <ul class="mt-2 space-y-1 text-sm text-slate-800">
                            <?php foreach ($stuPrereqGaps as $pg): ?>
                              <li class="rounded-lg border border-amber-100 bg-amber-50/60 px-3 py-2">
                                Needs <strong><?= htmlspecialchars((string)($pg['prereq_course_id'] ?? '')) ?></strong> <?= htmlspecialchars((string)($pg['prereq_name'] ?? '')) ?>
                                <span class="text-slate-600"> — before <?= htmlspecialchars((string)($pg['for_course_id'] ?? '')) ?> <?= htmlspecialchars((string)($pg['for_course_name'] ?? '')) ?></span>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          <p class="mt-2 text-sm text-slate-600">None detected for active enrollments (or no prereqs in <code class="rounded bg-slate-100 px-1">course_prereqs</code>).</p>
                        <?php endif; ?>
                      </div>

                      <div class="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm">
                        <span class="font-semibold text-slate-900">Roster &amp; registration — </span>
                        <a class="font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=registration&student_id=' . (int)$peopleId . ($currentTermCode !== null ? '&term=' . rawurlencode($currentTermCode) : ''))) ?>">Open registration / add-drop for this student</a>
                      </div>

                      <?php if ($canPostGrades): ?>
                      <div class="border-t border-slate-200 pt-6">
                        <h3 class="text-sm font-semibold text-slate-900">Registrar: add or replace transcript grade</h3>
                        <p class="mt-1 text-xs text-slate-500">Creates or updates one row in <code class="rounded bg-slate-100 px-1">student_course_results</code>. Letter grades map to standard quality points; override points only if needed.</p>
                        <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" method="post" action="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$peopleId . '&people_panel=info')) ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                          <input type="hidden" name="action" value="people_scr_upsert" />
                          <input type="hidden" name="student_id" value="<?= (int)$peopleId ?>" />
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="scr-course">Course ID</label>
                            <input id="scr-course" name="course_id" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 font-mono text-sm uppercase" placeholder="CS101" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="scr-term">Term</label>
                            <select id="scr-term" name="term_id" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                              <option value="">Select term…</option>
                              <?php foreach ($peopleTermsList as $tm): ?>
                                <option value="<?= (int)$tm['term_id'] ?>"><?= htmlspecialchars((string)($tm['code'] ?? '')) ?> — <?= htmlspecialchars((string)($tm['name'] ?? '')) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="scr-letter">Letter grade</label>
                            <input id="scr-letter" name="letter_grade" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm uppercase" placeholder="A-, B+, F" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="scr-gp">Quality points (override)</label>
                            <input id="scr-gp" name="grade_points" type="text" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 font-mono text-sm" placeholder="Optional if letter maps" />
                          </div>
                          <div class="lg:col-span-4">
                            <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Save grade row</button>
                          </div>
                        </form>
                      </div>
                      <?php endif; ?>
                    </div>
                      </div>
                    </div>
                  </div>

                  <div id="people-overlay-hold" class="people-stu-overlay fixed inset-0 z-[70] hidden items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="people-overlay-hold-title">
                    <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-[2px]" data-close-stu-overlay="hold"></div>
                    <div class="relative z-10 flex max-h-[min(90vh,760px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                      <div class="flex shrink-0 items-center justify-between gap-3 border-b border-amber-100 bg-amber-50/90 px-4 py-3">
                        <h2 id="people-overlay-hold-title" class="text-lg font-semibold text-slate-900">Holds</h2>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-amber-100/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500" data-close-stu-overlay="hold">Close</button>
                      </div>
                      <div class="min-h-0 overflow-y-auto p-4 sm:p-5">
                      <h3 class="text-sm font-semibold text-slate-900">Registration holds</h3>
                      <?php if ($canManageHolds): ?>
                        <form class="mt-4 grid gap-4 sm:max-w-xl" method="post" action="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$peopleId . '&people_panel=hold')) ?>" id="people-hold-form">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                          <input type="hidden" name="action" value="hold_add_people" />
                          <input type="hidden" name="student_id" value="<?= (int)$peopleId ?>" />
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-hold-type">Hold type</label>
                            <select id="people-hold-type" name="hold_type_select" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" required>
                              <option value="" disabled selected>Select a hold…</option>
                              <?php foreach ($peopleHoldPresets as $hp): ?>
                                <option value="<?= htmlspecialchars($hp) ?>"><?= htmlspecialchars($hp) ?></option>
                              <?php endforeach; ?>
                              <option value="__custom__">Custom (type below)</option>
                            </select>
                          </div>
                          <div id="people-hold-custom-wrap" class="hidden">
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-hold-custom">Custom hold label</label>
                            <input id="people-hold-custom" name="hold_type_custom" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. Immunization proof pending" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-hold-note-preset">Note</label>
                            <select id="people-hold-note-preset" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                              <option value="">No preset</option>
                              <option value="Contact bursar office.">Bursar — contact office</option>
                              <option value="Advisor clearance required.">Academic — advisor clearance</option>
                              <option value="Complete immunization documentation.">Immunization documentation</option>
                              <option value="Financial aid paperwork incomplete.">Financial aid paperwork</option>
                              <option value="Registrar review pending.">Registrar review</option>
                            </select>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="people-hold-note">Note detail <span class="font-normal normal-case text-slate-400">(optional)</span></label>
                            <textarea id="people-hold-note" name="note" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Additional context"></textarea>
                          </div>
                          <div>
                            <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500">Add hold</button>
                          </div>
                        </form>
                      <?php else: ?>
                        <p class="mt-3 text-sm text-slate-600">Your role cannot add or clear holds.</p>
                      <?php endif; ?>
                      <ul class="mt-6 space-y-2 text-sm">
                        <?php foreach ($holdRows as $h): ?>
                          <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
                            <span><?= htmlspecialchars((string)$h['hold_type']) ?><?= (int)$h['is_active'] === 1 ? '' : ' (cleared)' ?></span>
                            <?php if ((int)$h['is_active'] === 1 && $canManageHolds): ?>
                              <form method="post" action="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$peopleId . '&people_panel=hold')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                <input type="hidden" name="action" value="hold_clear_people" />
                                <input type="hidden" name="hold_id" value="<?= (int)$h['hold_id'] ?>" />
                                <input type="hidden" name="student_id" value="<?= (int)$peopleId ?>" />
                                <button type="submit" class="text-xs font-semibold text-amber-800 hover:underline">Clear</button>
                              </form>
                            <?php endif; ?>
                          </li>
                        <?php endforeach; ?>
                        <?php if (!$holdRows): ?><li class="text-slate-500">No holds.</li><?php endif; ?>
                      </ul>
                      </div>
                    </div>
                  </div>
                  <script>
                  (function () {
                    function wireTermTabs(tabSel, panelSel) {
                      var tabs = document.querySelectorAll(tabSel);
                      var panels = document.querySelectorAll(panelSel);
                      if (!tabs.length || !panels.length) return;
                      function show(term) {
                        panels.forEach(function (p) {
                          p.hidden = p.getAttribute('data-term') !== term;
                        });
                        tabs.forEach(function (b) {
                          var on = b.getAttribute('data-term') === term;
                          b.setAttribute('aria-selected', on ? 'true' : 'false');
                          b.classList.toggle('border-indigo-600', on);
                          b.classList.toggle('bg-white', on);
                          b.classList.toggle('text-indigo-900', on);
                          b.classList.toggle('border-transparent', !on);
                          b.classList.toggle('text-slate-600', !on);
                          b.classList.toggle('bg-transparent', !on);
                        });
                      }
                      tabs.forEach(function (b) {
                        b.addEventListener('click', function () { show(b.getAttribute('data-term')); });
                      });
                      show(tabs[0].getAttribute('data-term'));
                    }
                    wireTermTabs('.people-enroll-tab', '.people-enroll-panel');
                    wireTermTabs('.people-facsec-tab', '.people-facsec-panel');

                    var ovInfo = document.getElementById('people-overlay-info');
                    var ovHold = document.getElementById('people-overlay-hold');
                    var btnInfo = document.getElementById('people-btn-open-info');
                    var btnHold = document.getElementById('people-btn-open-hold');
                    var actWrap = document.getElementById('people-student-actions');
                    function openStuOverlay(which) {
                      if (which === 'info' && ovInfo) {
                        if (ovHold) { ovHold.classList.add('hidden'); ovHold.classList.remove('flex'); }
                        ovInfo.classList.remove('hidden');
                        ovInfo.classList.add('flex');
                        document.documentElement.classList.add('overflow-hidden');
                      } else if (which === 'hold' && ovHold) {
                        if (ovInfo) { ovInfo.classList.add('hidden'); ovInfo.classList.remove('flex'); }
                        ovHold.classList.remove('hidden');
                        ovHold.classList.add('flex');
                        document.documentElement.classList.add('overflow-hidden');
                      }
                    }
                    function closeStuOverlays() {
                      [ovInfo, ovHold].forEach(function (el) {
                        if (!el) return;
                        el.classList.add('hidden');
                        el.classList.remove('flex');
                      });
                      document.documentElement.classList.remove('overflow-hidden');
                    }
                    if (btnInfo) btnInfo.addEventListener('click', function () { openStuOverlay('info'); });
                    if (btnHold) btnHold.addEventListener('click', function () { openStuOverlay('hold'); });
                    document.querySelectorAll('[data-close-stu-overlay]').forEach(function (el) {
                      el.addEventListener('click', function (e) {
                        e.preventDefault();
                        closeStuOverlays();
                      });
                    });
                    document.addEventListener('keydown', function (e) {
                      if (e.key !== 'Escape') return;
                      var open = (ovInfo && ovInfo.classList.contains('flex')) || (ovHold && ovHold.classList.contains('flex'));
                      if (open) closeStuOverlays();
                    });
                    if (actWrap) {
                      var init = actWrap.getAttribute('data-initial-panel') || '';
                      if (init === 'info' || init === 'hold') openStuOverlay(init);
                    }

                    var ht = document.getElementById('people-hold-type');
                    var hw = document.getElementById('people-hold-custom-wrap');
                    var hci = document.getElementById('people-hold-custom');
                    if (ht && hw) {
                      function syncHoldCustom() {
                        var custom = ht.value === '__custom__';
                        hw.classList.toggle('hidden', !custom);
                        if (hci) hci.required = custom;
                      }
                      ht.addEventListener('change', syncHoldCustom);
                      syncHoldCustom();
                    }
                    var np = document.getElementById('people-hold-note-preset');
                    var nt = document.getElementById('people-hold-note');
                    if (np && nt) {
                      np.addEventListener('change', function () {
                        if (np.value) nt.value = np.value;
                      });
                    }
                  })();
                  </script>
                <?php elseif ($isFac): ?>
                  <div class="mt-1 flex flex-wrap items-center gap-3" id="people-faculty-actions" data-initial-panel="<?= htmlspecialchars($peoplePanel, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="button" id="people-btn-fac-open-info" class="rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-sm font-semibold text-violet-950 shadow-sm hover:bg-violet-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">Update info</button>
                    <button type="button" id="people-btn-fac-open-hold" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-950 shadow-sm hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500">Holds</button>
                    <span class="text-xs text-slate-500">Opens a full-screen panel over this page.</span>
                  </div>

                  <div id="people-overlay-fac-info" class="people-fac-overlay fixed inset-0 z-[70] hidden items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="people-overlay-fac-info-title">
                    <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-[2px]" data-close-fac-overlay="info"></div>
                    <div class="relative z-10 flex max-h-[min(90vh,720px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                      <div class="flex shrink-0 items-center justify-between gap-3 border-b border-violet-100 bg-violet-50/90 px-4 py-3">
                        <h2 id="people-overlay-fac-info-title" class="text-lg font-semibold text-slate-900">Update information</h2>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-violet-100/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500" data-close-fac-overlay="info">Close</button>
                      </div>
                      <div class="min-h-0 overflow-y-auto p-4 sm:p-5">
                        <?php
                        $peopleGenderOpts = admin_people_genders();
                        $peopleStateCodes = admin_people_us_state_codes();
                        $peopleCurGender = trim((string)($ur['gender'] ?? ''));
                        $peopleCurState = strtoupper(trim((string)($ur['state'] ?? '')));
                        ?>
                        <p class="text-sm text-slate-600">Same profile fields as students (minus student-only sections). Leave optional fields blank to keep current values.</p>
                        <?php if ($canManageHolds): ?>
                        <form class="mt-4 grid gap-4 sm:grid-cols-2" method="post" action="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$peopleId . '&people_panel=info')) ?>">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                          <input type="hidden" name="action" value="people_update_faculty" />
                          <input type="hidden" name="faculty_id" value="<?= (int)$peopleId ?>" />
                          <div class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
                            <div>
                              <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-gender">Gender</label>
                              <select id="fac-up-gender" name="gender" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                <option value="__keep__">Keep current<?= $peopleCurGender !== '' ? ' (' . htmlspecialchars($peopleCurGender) . ')' : '' ?></option>
                                <?php foreach ($peopleGenderOpts as $g): ?>
                                  <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div>
                              <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-state">State (US)</label>
                              <select id="fac-up-state" name="state" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-mono">
                                <option value="__keep__">Keep current<?= $peopleCurState !== '' ? ' (' . htmlspecialchars($peopleCurState) . ')' : '' ?></option>
                                <?php foreach ($peopleStateCodes as $sc): ?>
                                  <option value="<?= htmlspecialchars($sc) ?>"><?= htmlspecialchars($sc) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-email">Email</label>
                            <input id="fac-up-email" name="email" type="email" autocomplete="off" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-phone">Phone</label>
                            <input id="fac-up-phone" name="phone" type="text" inputmode="tel" autocomplete="off" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-apt">Apt / unit</label>
                            <input id="fac-up-apt" name="apt_no" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-street">Street</label>
                            <input id="fac-up-street" name="street" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-city">City</label>
                            <input id="fac-up-city" name="city" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-zip">ZIP</label>
                            <input id="fac-up-zip" name="zip_code" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="fac-up-office">Office</label>
                            <input id="fac-up-office" name="office_number" type="text" placeholder="Leave blank to keep" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                          </div>
                          <div class="sm:col-span-2">
                            <button type="submit" class="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-500">Save updates</button>
                          </div>
                        </form>
                        <?php else: ?>
                        <p class="mt-3 text-sm text-slate-600">Your role cannot edit these fields.</p>
                        <?php endif; ?>
                        <p class="mt-6 text-xs text-slate-500">
                          <a class="font-semibold text-violet-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=schedule')) ?>">Open Master schedule</a>
                          <span class="text-slate-400"> · </span>
                          Sections for this instructor appear under “Sections teaching” below.
                        </p>
                      </div>
                    </div>
                  </div>

                  <div id="people-overlay-fac-hold" class="people-fac-overlay fixed inset-0 z-[70] hidden items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="people-overlay-fac-hold-title">
                    <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-[2px]" data-close-fac-overlay="hold"></div>
                    <div class="relative z-10 flex max-h-[min(90vh,520px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                      <div class="flex shrink-0 items-center justify-between gap-3 border-b border-amber-100 bg-amber-50/90 px-4 py-3">
                        <h2 id="people-overlay-fac-hold-title" class="text-lg font-semibold text-slate-900">Holds</h2>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-amber-100/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500" data-close-fac-overlay="hold">Close</button>
                      </div>
                      <div class="min-h-0 overflow-y-auto p-4 sm:p-5 text-sm text-slate-700">
                        <p class="font-semibold text-slate-900">Registration holds apply to students</p>
                        <p class="mt-2 leading-relaxed">There are no faculty-specific holds in this system. To add or clear a registration hold, open a <span class="rounded-md bg-sky-100 px-1.5 py-0.5 font-mono font-semibold text-sky-950">student</span> record and use Holds there.</p>
                        <p class="mt-4">
                          <a class="font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">ID lookup — search by student ID</a>
                        </p>
                      </div>
                    </div>
                  </div>

                  <p class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500">Faculty snapshot</p>
                  <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500"><?= $currentTermCode !== null ? 'This term · ' . htmlspecialchars($currentTermCode) : 'This term' ?></div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= (int)$facSectionsThisTerm ?></div>
                      <div class="mt-1 text-xs text-slate-500">Sections on file</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Teaching load (list)</div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= (int)$facTotalSections ?></div>
                      <div class="mt-1 text-xs text-slate-500">Most recent terms first</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Departments</div>
                      <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900"><?= count($facDepts) ?></div>
                      <div class="mt-1 text-xs text-slate-500">Affiliations</div>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                      <div class="text-xs font-semibold uppercase text-slate-500">Contact on file</div>
                      <div class="mt-1 text-sm font-semibold leading-snug text-slate-900"><?php $___fe = (($facultyMeta ?? [])['email'] ?? null) ?: ($ur['email'] ?? null); echo (($___fe !== null && (string)$___fe !== '') ? htmlspecialchars(trim((string)$___fe)) : '—'); ?></div>
                      <div class="mt-1 text-xs text-slate-500">Faculty record, else user</div>
                    </div>
                  </div>

                  <div class="mt-8 grid gap-6 lg:grid-cols-12">
                    <div class="space-y-4 lg:col-span-5">
                      <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="text-sm font-semibold text-slate-900">Profile &amp; office</h3>
                        <dl class="mt-3 space-y-2 text-sm text-slate-700">
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">DOB</dt><dd class="font-medium"><?= $__fmtDate($ur['dob'] ?? null) ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Gender</dt><dd class="font-medium"><?= $__empty(($ur['gender'] ?? null)) ? '—' : htmlspecialchars((string)$ur['gender']) ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Rank / type</dt><dd class="text-right font-medium"><?php
                            $__fr = trim((string)(($facultyMeta ?? [])['rank'] ?? ''));
                            $__ft = trim((string)(($facultyMeta ?? [])['faculty_type'] ?? ''));
                            $__fcb = $__fr !== '' && $__ft !== '' ? $__fr . ' · ' . $__ft : ($__fr !== '' ? $__fr : $__ft);
                            echo $__fcb !== '' ? htmlspecialchars($__fcb) : '—';
                          ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Office</dt><dd class="font-medium"><?= $facultyMeta && ($facultyMeta['office_number'] ?? '') !== '' ? htmlspecialchars((string)$facultyMeta['office_number']) : '—' ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Hire date</dt><dd class="font-medium"><?= $facultyMeta && ($facultyMeta['hire_date'] ?? null) ? htmlspecialchars((string)$facultyMeta['hire_date']) : '—' ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Email</dt><dd class="break-all font-medium"><?php $___fe = (($facultyMeta ?? [])['email'] ?? null) ?: ($ur['email'] ?? null); echo ($___fe !== null && (string)$___fe !== '') ? htmlspecialchars(trim((string)$___fe)) : '—'; ?></dd></div>
                          <div class="flex justify-between gap-3"><dt class="text-slate-500">Phone</dt><dd class="font-medium"><?php $___fp = (($facultyMeta ?? [])['phone_number'] ?? null) ?: ($ur['phone_number'] ?? null); echo ($___fp !== null && (string)$___fp !== '') ? htmlspecialchars(trim((string)$___fp)) : '—'; ?></dd></div>
                          <div class="border-t border-slate-100 pt-2"><span class="text-slate-500">Address</span>
                            <?php if ($__empty($ur['street'] ?? null) && $__empty($ur['city'] ?? null)): ?>
                              <p class="mt-1 text-slate-600">—</p>
                            <?php else: ?>
                              <p class="mt-1 leading-relaxed text-slate-800"><?= htmlspecialchars(trim(((string)($ur['apt_no'] ?? '') . ' ' . (string)($ur['street'] ?? '')))) ?><br /><?= htmlspecialchars(trim(((string)($ur['city'] ?? '') . ', ' . (string)($ur['state'] ?? '') . ' ' . (string)($ur['zip_code'] ?? '')))) ?></p>
                            <?php endif; ?>
                          </div>
                        </dl>
                      </div>
                      <?php if ($facDepts): ?>
                      <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="text-sm font-semibold text-slate-900">Departments</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                          <?php foreach ($facDepts as $fd): ?>
                            <li class="rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                              <span class="font-medium text-slate-900"><?= htmlspecialchars((string)($fd['dept_name'] ?? '')) ?></span>
                              <span class="text-slate-500"> (<?= htmlspecialchars((string)($fd['dept_id'] ?? '')) ?>)</span>
                              <?php if (($fd['percent_time'] ?? null) !== null && (string)$fd['percent_time'] !== ''): ?>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$fd['percent_time']) ?>% time</div>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                      <?php endif; ?>
                    </div>
                    <div class="lg:col-span-7">
                      <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 px-4 py-3">
                          <h3 class="text-sm font-semibold text-slate-900">Sections teaching</h3>
                          <p class="mt-0.5 text-xs text-slate-500">Switch term tabs (e.g. FA27) to see each roster slice.</p>
                        </div>
                        <?php if (!$facSectionTermOrder): ?>
                          <div class="px-4 py-8 text-center text-sm text-slate-500">No sections assigned.</div>
                        <?php else: ?>
                          <?php if (count($facSectionTermOrder) > 1): ?>
                            <div class="flex flex-wrap gap-1 border-b border-slate-100 bg-slate-50/60 px-2 pt-2" role="tablist" aria-label="Sections by term">
                              <?php foreach ($facSectionTermOrder as $tc): ?>
                                <button type="button" role="tab" class="people-facsec-tab -mb-px rounded-t-lg border border-slate-200 border-b-0 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-white/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500" data-term="<?= htmlspecialchars($tc, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tc) ?></button>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                          <?php foreach ($facSectionTermOrder as $ti => $tc): ?>
                            <?php $facTermRows = $facSectionsByTerm[$tc] ?? []; ?>
                            <div class="people-facsec-panel overflow-x-auto" data-term="<?= htmlspecialchars($tc, ENT_QUOTES, 'UTF-8') ?>" <?= $ti > 0 ? 'hidden' : '' ?>>
                              <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                  <tr>
                                    <th class="px-3 py-2">Course</th>
                                    <th class="px-3 py-2">Section</th>
                                    <th class="px-3 py-2">Schedule</th>
                                  </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                  <?php foreach ($facTermRows as $r): ?>
                                    <tr class="hover:bg-slate-50/60">
                                      <td class="px-3 py-2 align-top">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['course_id'] ?? '')) ?></div>
                                        <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></div>
                                        <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string)($r['term_name'] ?? '')) ?></div>
                                      </td>
                                      <td class="px-3 py-2 align-top font-mono text-slate-800">#<?= (int)($r['section_id'] ?? 0) ?></td>
                                      <td class="px-3 py-2 align-top text-xs text-slate-600">
                                        <?php if (!empty($r['meeting_days']) || !empty($r['meeting_time']) || !empty($r['room'])): ?>
                                          <?= htmlspecialchars(trim(((string)($r['meeting_days'] ?? '') . ' ' . (string)($r['meeting_time'] ?? '')))) ?>
                                          <?php if (!empty($r['room'])): ?><span class="text-slate-500"> · <?= htmlspecialchars((string)$r['room']) ?></span><?php endif; ?>
                                        <?php else: ?>
                                          <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                      </td>
                                    </tr>
                                  <?php endforeach; ?>
                                </tbody>
                              </table>
                            </div>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <script>
                  (function () {
                    function wireTermTabs(tabSel, panelSel) {
                      var tabs = document.querySelectorAll(tabSel);
                      var panels = document.querySelectorAll(panelSel);
                      if (!tabs.length || !panels.length) return;
                      function show(term) {
                        panels.forEach(function (p) {
                          p.hidden = p.getAttribute('data-term') !== term;
                        });
                        tabs.forEach(function (b) {
                          var on = b.getAttribute('data-term') === term;
                          b.setAttribute('aria-selected', on ? 'true' : 'false');
                          b.classList.toggle('border-indigo-600', on);
                          b.classList.toggle('bg-white', on);
                          b.classList.toggle('text-indigo-900', on);
                          b.classList.toggle('border-transparent', !on);
                          b.classList.toggle('text-slate-600', !on);
                          b.classList.toggle('bg-transparent', !on);
                        });
                      }
                      tabs.forEach(function (b) {
                        b.addEventListener('click', function () { show(b.getAttribute('data-term')); });
                      });
                      show(tabs[0].getAttribute('data-term'));
                    }
                    wireTermTabs('.people-facsec-tab', '.people-facsec-panel');

                    var ovFacInfo = document.getElementById('people-overlay-fac-info');
                    var ovFacHold = document.getElementById('people-overlay-fac-hold');
                    var btnFacInfo = document.getElementById('people-btn-fac-open-info');
                    var btnFacHold = document.getElementById('people-btn-fac-open-hold');
                    var actFacWrap = document.getElementById('people-faculty-actions');
                    function openFacOverlay(which) {
                      if (which === 'info' && ovFacInfo) {
                        if (ovFacHold) { ovFacHold.classList.add('hidden'); ovFacHold.classList.remove('flex'); }
                        ovFacInfo.classList.remove('hidden');
                        ovFacInfo.classList.add('flex');
                        document.documentElement.classList.add('overflow-hidden');
                      } else if (which === 'hold' && ovFacHold) {
                        if (ovFacInfo) { ovFacInfo.classList.add('hidden'); ovFacInfo.classList.remove('flex'); }
                        ovFacHold.classList.remove('hidden');
                        ovFacHold.classList.add('flex');
                        document.documentElement.classList.add('overflow-hidden');
                      }
                    }
                    function closeFacOverlays() {
                      [ovFacInfo, ovFacHold].forEach(function (el) {
                        if (!el) return;
                        el.classList.add('hidden');
                        el.classList.remove('flex');
                      });
                      document.documentElement.classList.remove('overflow-hidden');
                    }
                    if (btnFacInfo) btnFacInfo.addEventListener('click', function () { openFacOverlay('info'); });
                    if (btnFacHold) btnFacHold.addEventListener('click', function () { openFacOverlay('hold'); });
                    document.querySelectorAll('[data-close-fac-overlay]').forEach(function (el) {
                      el.addEventListener('click', function (e) {
                        e.preventDefault();
                        closeFacOverlays();
                      });
                    });
                    document.addEventListener('keydown', function (e) {
                      if (e.key !== 'Escape') return;
                      var facOpen = (ovFacInfo && ovFacInfo.classList.contains('flex')) || (ovFacHold && ovFacHold.classList.contains('flex'));
                      if (facOpen) closeFacOverlays();
                    });
                    if (actFacWrap) {
                      var initFac = actFacWrap.getAttribute('data-initial-panel') || '';
                      if (initFac === 'info' || initFac === 'hold') openFacOverlay(initFac);
                    }
                  })();
                  </script>
                <?php else: ?>
                  <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Directory profile</p>
                  <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h3 class="text-sm font-semibold text-slate-900">Personal &amp; contact</h3>
                    <p class="mt-2 text-sm text-slate-600">This ID is not linked to a student or faculty row—only the user record below applies (e.g. staff account).</p>
                    <dl class="mt-4 space-y-2 text-sm text-slate-700">
                      <div class="flex justify-between gap-3"><dt class="text-slate-500">DOB</dt><dd class="font-medium"><?= $__fmtDate($ur['dob'] ?? null) ?></dd></div>
                      <div class="flex justify-between gap-3"><dt class="text-slate-500">Email</dt><dd class="break-all font-medium"><?= $__empty(($ur['email'] ?? null)) ? '—' : htmlspecialchars((string)$ur['email']) ?></dd></div>
                      <div class="flex justify-between gap-3"><dt class="text-slate-500">Phone</dt><dd class="font-medium"><?= $__empty(($ur['phone_number'] ?? null)) ? '—' : htmlspecialchars((string)$ur['phone_number']) ?></dd></div>
                    </dl>
                  </div>
                <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php endif; ?>

          </div>

        <?php elseif ($view === 'courses'): ?>
          <?php
          require_once __DIR__ . '/../app/lib/admin_course_offerings.php';
          $courseOfferingsState = admin_course_offerings_state($pdo, $_GET);
          extract($courseOfferingsState, EXTR_SKIP);
          require view_path('pages/admin/course_offerings.php');
          ?>

        <?php elseif ($view === 'course'): ?>
          <?php
          require_once __DIR__ . '/../app/lib/admin_course_detail.php';
          $courseDetailState = admin_course_detail_state($pdo, $_GET);
          extract($courseDetailState, EXTR_SKIP);
          $can_edit_catalog = $isAdmin;
          require view_path('pages/admin/course_detail.php');
          ?>

        <?php elseif ($view === 'schedule'): ?>
          <?php
          require_once __DIR__ . '/../app/lib/admin_schedule.php';
          $scheduleState = admin_schedule_state($pdo, $_GET);
          $schedule_form_action = url('/admin.php');
          extract($scheduleState, EXTR_SKIP);
          require view_path('pages/admin/schedule.php');
          ?>

        <?php elseif ($view === 'enrollment'): ?>
          <?php
          $termCode = trim((string)($_GET['term'] ?? ''));
          if ($termCode === '' && $currentTermCode !== null) {
              $termCode = $currentTermCode;
          }
          $status = strtolower(trim((string)($_GET['status'] ?? '')));
          if ($status !== '' && $status !== 'enrolled' && $status !== 'waitlisted' && $status !== 'dropped') {
              $status = '';
          }
          $q = trim((string)($_GET['q'] ?? ''));

          $terms = [];
          try {
              $terms = $pdo->query('SELECT code, name FROM terms ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable) {
          }

          $rows = [];
          try {
              $sql = '
                SELECT
                  e.enrollment_id,
                  e.status,
                  e.created_at,
                  s.section_id,
                  s.meeting_days,
                  s.meeting_time,
                  s.room,
                  s.capacity,
                  t.term_id AS term_id,
                  t.code AS term_code,
                  c.course_id,
                  c.course_name,
                  c.credits,
                  COALESCE(sf.first_name,"") AS fac_first,
                  COALESCE(sf.last_name,"") AS fac_last,
                  u.user_id AS student_id,
                  u.first_name,
                  u.last_name,
                  u.email,
                  u.phone_number,
                  u.city,
                  u.state,
                  u.zip_code,
                  u.gender,
                  u.dob
                FROM enrollments e
                INNER JOIN sections s ON s.section_id = e.section_id
                INNER JOIN terms t ON t.term_id = s.term_id
                INNER JOIN courses c ON c.course_id = s.course_id
                INNER JOIN users u ON u.user_id = e.student_id
                LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
                LEFT JOIN users sf ON sf.user_id = f.faculty_id
                WHERE 1=1
              ';
              $bind = [];
              if ($termCode !== '') {
                  $sql .= ' AND t.code = ?';
                  $bind[] = $termCode;
              }
              if ($status !== '') {
                  $sql .= ' AND e.status = ?';
                  $bind[] = $status;
              }
              if ($q !== '') {
                  $sql .= ' AND (
                    CAST(u.user_id AS CHAR) LIKE ?
                    OR LOWER(CONCAT(u.first_name," ",u.last_name)) LIKE ?
                    OR LOWER(COALESCE(u.email,"")) LIKE ?
                    OR LOWER(c.course_id) LIKE ?
                    OR LOWER(c.course_name) LIKE ?
                    OR CAST(s.section_id AS CHAR) LIKE ?
                  )';
                  $like = '%' . strtolower($q) . '%';
                  $likeId = '%' . $q . '%';
                  $bind = array_merge($bind, [$likeId, $like, $like, $like, $like, $likeId]);
              }
              $sql .= ' ORDER BY e.created_at DESC LIMIT 250';
              $st = $pdo->prepare($sql);
              $st->execute($bind);
              $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable) {
              $rows = [];
          }
          ?>
          <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">Enrollment</h1>
          <p class="mt-2 text-sm text-slate-600">Who is enrolled in what (with student + section + course attributes). Use filters to narrow down results.</p>

          <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end" method="get">
              <input type="hidden" name="view" value="enrollment" />
              <div class="sm:w-64">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="en-term">Term</label>
                <select id="en-term" name="term" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="">All terms</option>
                  <?php foreach ($terms as $t): $c = (string)($t['code'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $c !== '' && $c === $termCode ? 'selected' : '' ?>><?= htmlspecialchars($c) ?> — <?= htmlspecialchars((string)($t['name'] ?? '')) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sm:w-56">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="en-status">Status</label>
                <select id="en-status" name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <option value="">All</option>
                  <option value="enrolled" <?= $status === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
                  <option value="waitlisted" <?= $status === 'waitlisted' ? 'selected' : '' ?>>Waitlisted</option>
                  <option value="dropped" <?= $status === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                </select>
              </div>
              <div class="min-w-0 flex-1">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="en-q">Search</label>
                <input id="en-q" name="q" value="<?= htmlspecialchars($q) ?>" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Student ID, name, email, course, section…" />
              </div>
              <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500" type="submit">Filter</button>
              <a class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= htmlspecialchars(url('/admin.php?view=enrollment')) ?>">Reset</a>
            </form>
            <div class="mt-3 text-xs text-slate-500">Showing up to 250 newest rows.</div>
          </div>

          <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <table class="min-w-[72rem] text-left text-sm">
              <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                <tr>
                  <th class="px-4 py-3">Student</th>
                  <th class="px-4 py-3">Course / section</th>
                  <th class="px-4 py-3">Term</th>
                  <th class="px-4 py-3">Status</th>
                  <th class="px-4 py-3">When / where</th>
                  <th class="px-4 py-3">Faculty</th>
                  <th class="px-4 py-3">Student attributes</th>
                  <th class="px-4 py-3">Created</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-200">
                <?php foreach ($rows as $r): ?>
                  <?php
                    $sid = (int)($r['student_id'] ?? 0);
                    $stuName = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                    $facName = trim((string)($r['fac_first'] ?? '') . ' ' . (string)($r['fac_last'] ?? ''));
                    $st = (string)($r['status'] ?? '');
                    $badge = $st === 'enrolled' ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : ($st === 'waitlisted' ? 'bg-amber-50 text-amber-900 ring-amber-200' : 'bg-slate-100 text-slate-700 ring-slate-200');
                    $created = (string)($r['created_at'] ?? '');
                    $createdFmt = $created !== '' ? date('M j, g:i a', strtotime($created)) : '';
                  ?>
                  <tr class="hover:bg-slate-50/70 align-top">
                    <td class="px-4 py-3">
                      <a class="font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=people&id=' . $sid)) ?>"><?= $sid ?></a>
                      <div class="text-xs text-slate-600"><?= htmlspecialchars($stuName !== '' ? $stuName : 'Student') ?></div>
                      <?php if (!empty($r['email'])): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars((string)$r['email']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                      <?php
                        $enrCid = (string)($r['course_id'] ?? '');
                        $enrTid = isset($r['term_id']) ? (int)$r['term_id'] : 0;
                        $enrSec = (int)($r['section_id'] ?? 0);
                        $enrCourseLink = url('/admin.php?' . http_build_query(array_filter([
                            'view' => 'course',
                            'course_id' => $enrCid,
                            'term_id' => $enrTid > 0 ? (string)$enrTid : null,
                            'highlight_section' => $enrSec > 0 ? (string)$enrSec : null,
                        ])));
                      ?>
                      <a class="block font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars($enrCourseLink) ?>">
                        <?= htmlspecialchars($enrCid) ?> <span class="text-slate-400">·</span> <span class="font-mono text-xs">#<?= $enrSec ?></span>
                      </a>
                      <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?> · <?= (int)($r['credits'] ?? 0) ?> cr</div>
                    </td>
                    <td class="px-4 py-3"><?= htmlspecialchars((string)($r['term_code'] ?? '')) ?></td>
                    <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 <?= $badge ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
                    <td class="px-4 py-3 text-xs text-slate-700">
                      <?= htmlspecialchars(trim((string)($r['meeting_days'] ?? '') . ' ' . (string)($r['meeting_time'] ?? ''))) ?>
                      <?php if (!empty($r['room'])): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars((string)$r['room']) ?></div><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-700"><?= htmlspecialchars($facName !== '' ? $facName : '—') ?></td>
                    <td class="px-4 py-3 text-[11px] text-slate-600">
                      <?php
                        $attr = [];
                        if (!empty($r['phone_number'])) $attr[] = 'Phone ' . (string)$r['phone_number'];
                        $loc = trim((string)($r['city'] ?? '') . (empty($r['state']) ? '' : ', ' . (string)$r['state']) . (empty($r['zip_code']) ? '' : ' ' . (string)$r['zip_code']));
                        if ($loc !== '') $attr[] = $loc;
                        if (!empty($r['gender'])) $attr[] = (string)$r['gender'];
                        if (!empty($r['dob'])) $attr[] = 'DOB ' . (string)$r['dob'];
                      ?>
                      <?= htmlspecialchars($attr ? implode(' · ', $attr) : '—') ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap"><?= htmlspecialchars($createdFmt) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                  <tr><td class="px-4 py-10 text-center text-slate-500" colspan="8">No enrollment rows match your filters.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($view === 'department'): ?>
          <?php
          require_once __DIR__ . '/../app/lib/admin_department_detail.php';
          $departmentDetailState = admin_department_detail_state($pdo, $_GET);
          extract($departmentDetailState, EXTR_SKIP);
          require view_path('pages/admin/department_detail.php');
          ?>

        <?php elseif ($view === 'departments'): ?>
          <?php
          require_once __DIR__ . '/../app/lib/admin_departments_list.php';
          $departmentsListState = admin_departments_list_state($pdo);
          extract($departmentsListState, EXTR_SKIP);
          require view_path('pages/admin/departments_list.php');
          ?>

        <?php else: /* registration */ ?>
          <?php
          $msg = trim((string)($_GET['msg'] ?? ''));
          $banners = [
              'readonly' => ['warn', 'Read-only for this action.'],
              'forbidden' => ['error', 'Your role cannot perform this action.'],
              'enrolled' => ['success', 'Enrolled.'],
              'waitlisted' => ['warn', 'Added to waitlist.'],
              'dropped' => ['success', 'Dropped.'],
              'hold' => ['error', 'Student has an active hold.'],
              'conflict' => ['error', 'Schedule conflict.'],
              'credit' => ['error', 'Exceeds credit limit.'],
              'prereq' => ['error', 'Prerequisite not met.'],
              'duplicate' => ['error', 'Already in section.'],
              'dupecourse' => ['error', 'Already in course this term.'],
              'wrongterm' => ['error', 'Wrong term.'],
              'invalid' => ['error', 'Invalid request.'],
              'regclosed' => ['error', 'Registration window is closed for this term.'],
              'promote_ok' => ['success', 'Student promoted from waitlist to enrolled.'],
              'promote_full' => ['error', 'Section is full — cannot promote from waitlist.'],
              'promote_bad' => ['error', 'That enrollment is not waitlisted.'],
          ];
          if ($msg !== '' && isset($banners[$msg])) {
              [$tone, $text] = $banners[$msg];
              $cls = $tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : ($tone === 'error' ? 'border-rose-200 bg-rose-50 text-rose-950' : 'border-amber-200 bg-amber-50 text-amber-950');
              echo '<div class="mb-4 rounded-2xl border ' . $cls . ' px-4 py-3 text-sm font-medium">' . htmlspecialchars($text) . '</div>';
          }
          $regStudentRaw = trim((string)($_GET['student_id'] ?? ''));
          $regStudentId = ctype_digit($regStudentRaw) ? (int)$regStudentRaw : null;
          $termCode = trim((string)($_GET['term'] ?? ''));
          if ($termCode === '' && $currentTermCode !== null) {
              $termCode = $currentTermCode;
          }
          $terms = $pdo->query('SELECT code, name FROM terms ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <h1 class="text-2xl font-semibold text-slate-900 dark:text-white">Registration</h1>
          <?php
          $creditSummary = ['courses' => 0, 'credits' => 0, 'max' => $defaultMaxCredits];
          $enrolledNow = [];
          $waitlistedNow = [];
          $regStudentUser = null;
          $regHasHold = false;
          $termId = null;
          $browseQ = trim((string)($_GET['browse_q'] ?? ''));
          $browseRows = [];

          if ($regStudentId !== null && $termCode !== '') {
              try {
                  $tu = $pdo->prepare('SELECT u.user_id, u.first_name, u.last_name FROM users u JOIN students s ON s.student_id = u.user_id WHERE u.user_id = ? LIMIT 1');
                  $tu->execute([$regStudentId]);
                  $regStudentUser = $tu->fetch(PDO::FETCH_ASSOC) ?: null;
              } catch (Throwable) {
              }
              try {
                  $hc = $pdo->prepare('SELECT 1 FROM student_holds WHERE student_id = ? AND is_active = 1 LIMIT 1');
                  $hc->execute([$regStudentId]);
                  $regHasHold = (bool)$hc->fetchColumn();
              } catch (Throwable) {
              }
              try {
                  $mx = $pdo->prepare('SELECT max_credit FROM ug_credit_limits WHERE student_id = ? LIMIT 1');
                  $mx->execute([$regStudentId]);
                  $v = $mx->fetchColumn();
                  if ($v !== false && $v !== null && is_numeric($v) && (int)$v > 0) {
                      $creditSummary['max'] = (int)$v;
                  }
              } catch (Throwable) {
              }

              try {
                  $tId = $pdo->prepare('SELECT term_id FROM terms WHERE code = ? LIMIT 1');
                  $tId->execute([$termCode]);
                  $tv = $tId->fetchColumn();
                  if ($tv !== false && $tv !== null && is_numeric($tv)) {
                      $termId = (int)$tv;
                  }
              } catch (Throwable) {
              }

              $st = $pdo->prepare('
                SELECT e.status, s.section_id, c.course_id, c.course_name, c.credits,
                       s.meeting_days, s.meeting_time, s.room,
                       COALESCE(u.first_name,"") AS fac_first, COALESCE(u.last_name,"") AS fac_last
                FROM enrollments e
                JOIN sections s ON s.section_id = e.section_id
                JOIN courses c ON c.course_id = s.course_id
                JOIN terms t ON t.term_id = s.term_id
                LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
                LEFT JOIN users u ON u.user_id = f.faculty_id
                WHERE e.student_id = ? AND t.code = ? AND e.status IN ("enrolled","waitlisted")
                ORDER BY e.status DESC, c.course_id
              ');
              $st->execute([$regStudentId, $termCode]);
              $allNow = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
              foreach ($allNow as $r) {
                  if (($r['status'] ?? '') === 'enrolled') {
                      $enrolledNow[] = $r;
                      $creditSummary['courses']++;
                      $creditSummary['credits'] += (int)($r['credits'] ?? 0);
                  } elseif (($r['status'] ?? '') === 'waitlisted') {
                      $waitlistedNow[] = $r;
                  }
              }

              if ($termId !== null) {
                  try {
                      $sql = '
                        SELECT
                          s.section_id,
                          c.course_id,
                          c.course_name,
                          c.credits,
                          c.dept_id,
                          COALESCE(u.first_name,"") AS fac_first,
                          COALESCE(u.last_name,"") AS fac_last,
                          s.meeting_days,
                          s.meeting_time,
                          s.room,
                          s.capacity,
                          COALESCE(en.enrolled_cnt, 0) AS enrolled_cnt,
                          COALESCE(wl.waitlisted_cnt, 0) AS waitlisted_cnt
                        FROM sections s
                        JOIN courses c ON c.course_id = s.course_id
                        JOIN terms t ON t.term_id = s.term_id
                        LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
                        LEFT JOIN users u ON u.user_id = f.faculty_id
                        LEFT JOIN (
                          SELECT section_id, COUNT(*) AS enrolled_cnt
                          FROM enrollments
                          WHERE status = "enrolled"
                          GROUP BY section_id
                        ) en ON en.section_id = s.section_id
                        LEFT JOIN (
                          SELECT section_id, COUNT(*) AS waitlisted_cnt
                          FROM enrollments
                          WHERE status = "waitlisted"
                          GROUP BY section_id
                        ) wl ON wl.section_id = s.section_id
                        WHERE t.term_id = ?
                      ';
                      $bind = [$termId];
                      if ($browseQ !== '') {
                          $sql .= ' AND (
                            CAST(s.section_id AS CHAR) LIKE ?
                            OR c.course_id LIKE ?
                            OR LOWER(c.course_name) LIKE ?
                            OR LOWER(CONCAT(COALESCE(u.first_name,"")," ",COALESCE(u.last_name,""))) LIKE ?
                            OR LOWER(COALESCE(s.room,"")) LIKE ?
                            OR LOWER(COALESCE(s.meeting_days,"")) LIKE ?
                            OR LOWER(COALESCE(s.meeting_time,"")) LIKE ?
                          )';
                          $likeId = '%' . $browseQ . '%';
                          $like = '%' . strtolower($browseQ) . '%';
                          $bind = array_merge($bind, [$likeId, $likeId, $like, $like, $like, $like, $like]);
                      }
                      $sql .= ' ORDER BY c.course_id, s.section_id LIMIT 80';
                      $bst = $pdo->prepare($sql);
                      $bst->execute($bind);
                      $browseRows = $bst->fetchAll(PDO::FETCH_ASSOC) ?: [];
                  } catch (Throwable) {
                      $browseRows = [];
                  }
              }
          }
          $regWindowClosed = ($termId !== null && !admin_term_registration_allowed($pdo, $termId));
          ?>
          <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end" method="get">
              <input type="hidden" name="view" value="registration" />
              <div class="min-w-0 flex-1 sm:max-w-xs">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="reg-student-id">Student ID</label>
                <input id="reg-student-id" name="student_id" value="<?= htmlspecialchars($regStudentRaw) ?>" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-mono" placeholder="e.g. 900651" />
              </div>
              <div class="sm:w-60">
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="reg-term">Term</label>
                <select id="reg-term" name="term" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                  <?php foreach ($terms as $t): $c = (string)$t['code']; ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $c === $termCode ? 'selected' : '' ?>><?= htmlspecialchars($c) ?> — <?= htmlspecialchars((string)$t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500" type="submit">Load</button>
              <?php if ($regStudentId !== null && $termCode !== ''): ?>
                <input type="hidden" name="browse_q" value="<?= htmlspecialchars($browseQ, ENT_QUOTES, 'UTF-8') ?>" />
              <?php endif; ?>
            </form>

            <?php if (!empty($regWindowClosed) && $regStudentId !== null && $termCode !== ''): ?>
              <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                Registration for <strong class="font-semibold"><?= htmlspecialchars($termCode) ?></strong> is closed or outside the configured window. Administrators can use “Override closed registration window” when adding a section.
              </div>
            <?php endif; ?>

            <?php if ($regStudentId !== null): ?>
              <?php
              $stuName = $regStudentUser ? trim((string)($regStudentUser['first_name'] ?? '') . ' ' . (string)($regStudentUser['last_name'] ?? '')) : '';
              ?>
              <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="inline-flex items-center gap-2 rounded-lg bg-sky-50 px-2 py-1 ring-1 ring-sky-200/80">
                  <span class="font-mono font-semibold text-sky-950"><?= (int)$regStudentId ?></span>
                  <span class="text-sky-800"><?= $stuName !== '' ? htmlspecialchars($stuName) : 'Student' ?></span>
                </span>
                <span class="rounded-lg bg-slate-100 px-2 py-1 ring-1 ring-slate-200">
                  <span class="font-semibold text-slate-800"><?= htmlspecialchars($termCode) ?></span>
                </span>
                <span class="rounded-lg bg-white px-2 py-1 ring-1 ring-slate-200">
                  <span class="font-semibold text-slate-900"><?= (int)$creditSummary['credits'] ?></span>/<span class="text-slate-600"><?= (int)$creditSummary['max'] ?></span> cr
                </span>
                <?php if ($regHasHold): ?>
                  <span class="rounded-lg bg-rose-50 px-2 py-1 text-rose-950 ring-1 ring-rose-200">Blocking hold</span>
                <?php else: ?>
                  <span class="rounded-lg bg-emerald-50 px-2 py-1 text-emerald-950 ring-1 ring-emerald-200">No holds</span>
                <?php endif; ?>
                <a class="ml-auto text-sm font-semibold text-indigo-700 hover:underline" href="<?= htmlspecialchars(url('/admin.php?view=people&id=' . (int)$regStudentId)) ?>">Open student record →</a>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($regStudentId !== null && $termCode !== ''): ?>
            <div class="mt-6 grid gap-6 lg:grid-cols-12">
              <div class="lg:col-span-7 space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                  <div class="flex items-end justify-between gap-3">
                    <div>
                      <h2 class="text-sm font-semibold text-slate-900">Current schedule</h2>
                      <p class="mt-1 text-xs text-slate-500">Enrolled and waitlisted sections for <?= htmlspecialchars($termCode) ?>.</p>
                    </div>
                    <div class="text-xs text-slate-500"><?= (int)$creditSummary['courses'] ?> enrolled · <?= (int)$creditSummary['credits'] ?> credits</div>
                  </div>

                  <?php if (!$enrolledNow && !$waitlistedNow): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">No registrations yet for this term.</div>
                  <?php else: ?>
                    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                      <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                          <tr>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Course</th>
                            <th class="px-3 py-2">Section</th>
                            <th class="px-3 py-2">When / where</th>
                            <th class="px-3 py-2">Cr</th>
                            <th class="px-3 py-2"></th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                          <?php foreach (array_merge($enrolledNow, $waitlistedNow) as $r): ?>
                            <?php $st = (string)($r['status'] ?? ''); ?>
                            <tr class="hover:bg-slate-50/70">
                              <td class="px-3 py-2 text-xs font-semibold uppercase <?= $st === 'enrolled' ? 'text-emerald-700' : 'text-amber-700' ?>"><?= htmlspecialchars($st) ?></td>
                              <td class="px-3 py-2">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['course_id'] ?? '')) ?></div>
                                <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></div>
                                <?php $fn = trim((string)($r['fac_first'] ?? '') . ' ' . (string)($r['fac_last'] ?? '')); ?>
                                <?php if ($fn !== ''): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars($fn) ?></div><?php endif; ?>
                              </td>
                              <td class="px-3 py-2 font-mono text-xs text-slate-700">#<?= (int)($r['section_id'] ?? 0) ?></td>
                              <td class="px-3 py-2 text-xs text-slate-600">
                                <?= htmlspecialchars(trim((string)($r['meeting_days'] ?? '') . ' ' . (string)($r['meeting_time'] ?? ''))) ?>
                                <?php if (!empty($r['room'])): ?><span class="text-slate-400"> · </span><?= htmlspecialchars((string)$r['room']) ?><?php endif; ?>
                              </td>
                              <td class="px-3 py-2 tabular-nums text-slate-700"><?= (int)($r['credits'] ?? 0) ?></td>
                              <td class="px-3 py-2 text-right">
                                <?php if ($canRegister): ?>
                                  <div class="flex flex-wrap items-center justify-end gap-2">
                                    <form method="post" action="<?= htmlspecialchars(url('/admin.php?view=registration&student_id=' . $regStudentId . '&term=' . rawurlencode($termCode) . '&browse_q=' . rawurlencode($browseQ))) ?>">
                                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                      <input type="hidden" name="action" value="reg_drop" />
                                      <input type="hidden" name="student_id" value="<?= (int)$regStudentId ?>" />
                                      <input type="hidden" name="term" value="<?= htmlspecialchars($termCode) ?>" />
                                      <input type="hidden" name="section_id" value="<?= (int)($r['section_id'] ?? 0) ?>" />
                                      <button type="submit" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Drop</button>
                                    </form>
                                    <?php if ($st === 'waitlisted' && $isAdmin): ?>
                                      <form method="post" action="<?= htmlspecialchars(url('/admin.php?view=registration&student_id=' . $regStudentId . '&term=' . rawurlencode($termCode) . '&browse_q=' . rawurlencode($browseQ))) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                        <input type="hidden" name="action" value="reg_promote" />
                                        <input type="hidden" name="student_id" value="<?= (int)$regStudentId ?>" />
                                        <input type="hidden" name="term" value="<?= htmlspecialchars($termCode) ?>" />
                                        <input type="hidden" name="section_id" value="<?= (int)($r['section_id'] ?? 0) ?>" />
                                        <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">Promote</button>
                                      </form>
                                    <?php endif; ?>
                                  </div>
                                <?php else: ?>
                                  <span class="text-xs text-slate-400">—</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="lg:col-span-5 space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                  <h2 class="text-sm font-semibold text-slate-900">Add class</h2>
                  <p class="mt-1 text-xs text-slate-500">Add by Section ID (fast) or browse available sections for <?= htmlspecialchars($termCode) ?>.</p>

                  <?php if ($canRegister): ?>
                    <form class="mt-4 flex flex-col gap-3" method="post" action="<?= htmlspecialchars(url('/admin.php?view=registration&student_id=' . $regStudentId . '&term=' . rawurlencode($termCode) . '&browse_q=' . rawurlencode($browseQ))) ?>">
                      <div class="flex flex-wrap items-end gap-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                        <input type="hidden" name="action" value="reg_add" />
                        <input type="hidden" name="student_id" value="<?= (int)$regStudentId ?>" />
                        <input type="hidden" name="term" value="<?= htmlspecialchars($termCode) ?>" />
                        <div class="min-w-0 flex-1">
                          <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="reg-add-section">Section ID</label>
                          <input id="reg-add-section" name="section_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-mono" placeholder="e.g. 12031" />
                        </div>
                        <button class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500" type="submit">Add</button>
                      </div>
                      <?php if ($isAdmin): ?>
                        <details class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                          <summary class="cursor-pointer font-semibold text-slate-800">Administrator overrides</summary>
                          <div class="mt-2 space-y-2 text-slate-700">
                            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_reg_closed" value="1" /> Closed registration window</label>
                            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_prereq" value="1" /> Prerequisite rule</label>
                            <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_credit" value="1" /> Credit-hour limit</label>
                          </div>
                        </details>
                      <?php endif; ?>
                    </form>
                  <?php else: ?>
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">Viewer role: you can browse, but cannot add/drop.</div>
                  <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                  <div class="flex items-end justify-between gap-3">
                    <div>
                      <h3 class="text-sm font-semibold text-slate-900">Browse sections</h3>
                      <p class="mt-1 text-xs text-slate-500">Search course, instructor, room, days/time, or section ID.</p>
                    </div>
                  </div>
                  <form class="mt-3 flex flex-wrap items-end gap-2" method="get">
                    <input type="hidden" name="view" value="registration" />
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($regStudentRaw) ?>" />
                    <input type="hidden" name="term" value="<?= htmlspecialchars($termCode) ?>" />
                    <div class="min-w-0 flex-1">
                      <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500" for="reg-browse-q">Search</label>
                      <input id="reg-browse-q" name="browse_q" value="<?= htmlspecialchars($browseQ) ?>" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="CS101, Smith, MW 10, 12031…" />
                    </div>
                    <button class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" type="submit">Search</button>
                  </form>

                  <?php if ($termId === null): ?>
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">This term code wasn’t found in the database.</div>
                  <?php elseif (!$browseRows): ?>
                    <div class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">No sections found for this term.</div>
                  <?php else: ?>
                    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                      <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                          <tr>
                            <th class="px-3 py-2">Course</th>
                            <th class="px-3 py-2">Section</th>
                            <th class="px-3 py-2">When / where</th>
                            <th class="px-3 py-2">Seats</th>
                            <th class="px-3 py-2"></th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                          <?php foreach ($browseRows as $br): ?>
                            <?php
                              $sid = (int)($br['section_id'] ?? 0);
                              $cap = (int)($br['capacity'] ?? 0);
                              $ec = (int)($br['enrolled_cnt'] ?? 0);
                              $open = max(0, $cap - $ec);
                              $instructor = trim((string)($br['fac_first'] ?? '') . ' ' . (string)($br['fac_last'] ?? ''));
                            ?>
                            <tr class="hover:bg-slate-50/70">
                              <td class="px-3 py-2">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($br['course_id'] ?? '')) ?></div>
                                <div class="text-xs text-slate-600"><?= htmlspecialchars((string)($br['course_name'] ?? '')) ?> · <?= (int)($br['credits'] ?? 0) ?> cr</div>
                                <?php if ($instructor !== ''): ?><div class="text-[11px] text-slate-500"><?= htmlspecialchars($instructor) ?></div><?php endif; ?>
                              </td>
                              <td class="px-3 py-2 font-mono text-xs text-slate-700">#<?= $sid ?></td>
                              <td class="px-3 py-2 text-xs text-slate-600">
                                <?= htmlspecialchars(trim((string)($br['meeting_days'] ?? '') . ' ' . (string)($br['meeting_time'] ?? ''))) ?>
                                <?php if (!empty($br['room'])): ?><span class="text-slate-400"> · </span><?= htmlspecialchars((string)$br['room']) ?><?php endif; ?>
                              </td>
                              <td class="px-3 py-2 text-xs text-slate-600">
                                <span class="font-semibold text-slate-900"><?= (int)$open ?></span><span class="text-slate-400">/</span><?= (int)$cap ?>
                                <?php if ((int)($br['waitlisted_cnt'] ?? 0) > 0): ?>
                                  <span class="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-900 ring-1 ring-amber-200">WL <?= (int)$br['waitlisted_cnt'] ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="px-3 py-2 text-right align-top">
                                <?php if ($canRegister): ?>
                                  <form class="inline-flex flex-col items-end gap-2" method="post" action="<?= htmlspecialchars(url('/admin.php?view=registration&student_id=' . $regStudentId . '&term=' . rawurlencode($termCode) . '&browse_q=' . rawurlencode($browseQ))) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
                                    <input type="hidden" name="action" value="reg_add" />
                                    <input type="hidden" name="student_id" value="<?= (int)$regStudentId ?>" />
                                    <input type="hidden" name="term" value="<?= htmlspecialchars($termCode) ?>" />
                                    <input type="hidden" name="section_id" value="<?= $sid ?>" />
                                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">Add</button>
                                    <?php if ($isAdmin): ?>
                                      <details class="w-full min-w-[12rem] rounded-lg border border-slate-200 bg-slate-50 p-2 text-left text-[11px]">
                                        <summary class="cursor-pointer font-semibold text-slate-800">Overrides</summary>
                                        <div class="mt-2 space-y-1.5 text-slate-700">
                                          <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_reg_closed" value="1" /> Closed window</label>
                                          <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_prereq" value="1" /> Prereq</label>
                                          <label class="flex cursor-pointer items-center gap-2"><input type="checkbox" name="override_credit" value="1" /> Credits</label>
                                        </div>
                                      </details>
                                    <?php endif; ?>
                                  </form>
                                <?php else: ?>
                                  <span class="text-xs text-slate-400">—</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <script>
    (function () {
      const html = document.documentElement;
      const sidebarToggle = document.getElementById('adminSidebarToggle');
      const sidebar = document.getElementById('adminSidebar');
      const main = document.getElementById('adminMain');
      const storageKey = 'admin_nav_collapsed';

      function setCollapsed(collapsed) {
        if (!sidebarToggle || !sidebar || !main) return;
        if (collapsed) {
          html.classList.add('admin-nav-collapsed');
          sidebarToggle.textContent = 'Show';
          sidebarToggle.setAttribute('aria-expanded', 'false');
          sidebarToggle.setAttribute('title', 'Show sidebar');
        } else {
          html.classList.remove('admin-nav-collapsed');
          sidebarToggle.textContent = 'Hide';
          sidebarToggle.setAttribute('aria-expanded', 'true');
          sidebarToggle.setAttribute('title', 'Hide sidebar');
        }
        try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (e) {}
      }

      (function initSidebarState() {
        if (!sidebarToggle || !sidebar || !main) return;
        let collapsed = false;
        try { collapsed = localStorage.getItem(storageKey) === '1'; } catch (e) {}
        setCollapsed(collapsed);
        sidebarToggle.addEventListener('click', function () {
          setCollapsed(!html.classList.contains('admin-nav-collapsed'));
        });
      })();

      const btn = document.getElementById('adminMenuButton');
      const closeBtn = document.getElementById('adminMenuClose');
      const backdrop = document.getElementById('adminMenuBackdrop');
      const drawer = document.getElementById('adminMenuDrawer');
      if (!btn || !closeBtn || !backdrop || !drawer) return;

      function openDrawer() {
        drawer.classList.remove('hidden');
        backdrop.classList.remove('hidden');
        btn.setAttribute('aria-expanded', 'true');
        document.documentElement.classList.add('overflow-hidden');
      }

      function closeDrawer() {
        drawer.classList.add('hidden');
        backdrop.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('overflow-hidden');
      }

      btn.addEventListener('click', function () {
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        if (expanded) closeDrawer();
        else openDrawer();
      });

      closeBtn.addEventListener('click', closeDrawer);
      backdrop.addEventListener('click', closeDrawer);

      drawer.addEventListener('click', function (e) {
        const a = e.target && e.target.closest ? e.target.closest('a') : null;
        if (a) closeDrawer();
      });

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeDrawer();
      });
    })();

    (function initNotifTap() {
      function closeAll(except) {
        document.querySelectorAll('[data-notif-wrap].notif-open').forEach(function (wrap) {
          if (wrap !== except) wrap.classList.remove('notif-open');
        });
      }

      document.querySelectorAll('[data-notif-wrap]').forEach(function (wrap) {
        wrap.addEventListener('click', function (e) {
          if (window.matchMedia('(hover: hover)').matches) return;
          var trigger = e.target && e.target.closest ? e.target.closest('[data-notif-trigger]') : null;
          var inPanel = e.target && e.target.closest ? e.target.closest('[role="tooltip"]') : null;
          if (trigger) {
            e.preventDefault();
          }
          if (!trigger && !inPanel) return;
          var open = wrap.classList.toggle('notif-open');
          if (open) closeAll(wrap);
        });
      });

      document.addEventListener('click', function (e) {
        var inside = e.target && e.target.closest ? e.target.closest('[data-notif-wrap]') : null;
        if (!inside) closeAll(null);
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll(null);
      });
    })();

    (function initPeopleRecentSearches() {
      const input = document.getElementById('people-id-q');
      const form = input ? input.closest('form') : null;
      const recentWrap = document.getElementById('people-recent-searches');
      const recentLinks = document.getElementById('people-recent-search-links');
      const clearButton = document.getElementById('people-clear-recent-searches');
      const storageKey = 'admin_people_recent_ids';
      const peopleLookupBase = <?= json_encode(url('/admin.php?view=people&id='), JSON_UNESCAPED_SLASHES) ?>;
      if (!form || !input || !recentWrap || !recentLinks || !clearButton) return;

      const readRecent = function () {
        try {
          const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
          return Array.isArray(parsed) ? parsed.filter(function (id) { return /^\d+$/.test(String(id)); }).slice(0, 8) : [];
        } catch (e) {
          return [];
        }
      };

      const rememberId = function (rawId) {
        const id = String(rawId || '').trim();
        if (!/^\d+$/.test(id)) return;
        const ids = readRecent().filter(function (recentId) { return String(recentId) !== id; });
        ids.unshift(id);
        try { localStorage.setItem(storageKey, JSON.stringify(ids.slice(0, 8))); } catch (e) {}
      };

      const renderRecent = function () {
        const ids = readRecent();
        recentLinks.innerHTML = '';
        if (ids.length === 0) {
          recentWrap.hidden = true;
          return;
        }
        recentWrap.hidden = false;
        ids.forEach(function (id) {
          const link = document.createElement('a');
          link.className = 'rounded-lg border border-slate-200 bg-white px-2.5 py-1 font-mono text-xs font-semibold text-indigo-700 hover:bg-indigo-50';
          link.href = peopleLookupBase + encodeURIComponent(String(id));
          link.textContent = String(id);
          link.setAttribute('title', 'Open ID ' + id);
          recentLinks.appendChild(link);
        });
      };

      form.addEventListener('submit', function () {
        rememberId(input.value);
      });

      // Also remember a successful lookup already shown on this page.
      rememberId(input.value);

      clearButton.addEventListener('click', function (e) {
        e.preventDefault();
        try { localStorage.removeItem(storageKey); } catch (err) {}
        renderRecent();
      });

      renderRecent();
    })();
  </script>
  <?php require __DIR__ . '/../app/views/partials/theme_boot.php'; ?>
</body>
</html>
