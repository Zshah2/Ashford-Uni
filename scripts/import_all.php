<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/../app/lib/northbridge_email.php';
require_once __DIR__ . '/../app/lib/us_student_phone.php';

function csv_rows(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot open $path");
    }
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        return [];
    }
    $header = array_map(static fn($h) => trim((string)$h), $header);

    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $assoc[$key] = isset($row[$i]) ? trim((string)$row[$i]) : null;
        }
        $rows[] = $assoc;
    }
    fclose($fh);
    return $rows;
}

function parse_date(?string $s): ?string
{
    $s = $s !== null ? trim($s) : '';
    if ($s === '' || $s === '–' || $s === '-') {
        return null;
    }
    // Accept YYYY-MM-DD (already correct). Otherwise try strtotime.
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

function parse_int(?string $s): ?int
{
    $s = $s !== null ? trim($s) : '';
    if ($s === '' || $s === '–' || $s === '-') {
        return null;
    }
    if (!preg_match('/^-?\d+$/', $s)) {
        return null;
    }
    return (int)$s;
}

function import_users(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO users (user_id, first_name, middle_name, last_name, apt_no, street, city, state, zip_code, email, phone_number, gender, dob, user_type)
      VALUES (:user_id, :first_name, :middle_name, :last_name, :apt_no, :street, :city, :state, :zip_code, :email, :phone_number, :gender, :dob, :user_type)
      ON DUPLICATE KEY UPDATE
        first_name=VALUES(first_name),
        middle_name=VALUES(middle_name),
        last_name=VALUES(last_name),
        apt_no=VALUES(apt_no),
        street=VALUES(street),
        city=VALUES(city),
        state=VALUES(state),
        zip_code=VALUES(zip_code),
        email=VALUES(email),
        phone_number=VALUES(phone_number),
        gender=VALUES(gender),
        dob=VALUES(dob),
        user_type=VALUES(user_type)
    ');

    foreach ($rows as $r) {
        $userId = parse_int($r['user_id'] ?? null);
        if ($userId === null) {
            continue;
        }
        $csvEmail = trim((string)($r['email'] ?? ''));
        if ($csvEmail !== '') {
            $email = $csvEmail;
        } else {
            $ex = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
            $ex->execute([$userId]);
            $prev = $ex->fetch(PDO::FETCH_ASSOC);
            $prevEmail = trim((string)($prev['email'] ?? ''));
            $email = $prevEmail !== ''
                ? $prevEmail
                : northbridge_allocate_school_email($pdo, (string)($r['first_name'] ?? ''), (string)($r['last_name'] ?? ''), $userId);
        }
        $csvPhone = trim((string)($r['phone_number'] ?? ''));
        if ($csvPhone !== '') {
            $phoneNumber = $csvPhone;
        } else {
            $phEx = $pdo->prepare('SELECT phone_number FROM users WHERE user_id = ? LIMIT 1');
            $phEx->execute([$userId]);
            $phPrev = $phEx->fetch(PDO::FETCH_ASSOC);
            $prevPhone = trim((string)($phPrev['phone_number'] ?? ''));
            $isStudentRow = strcasecmp((string)($r['user_type'] ?? ''), 'Student') === 0;
            if ($isStudentRow && $prevPhone === '') {
                $phoneNumber = northbridge_allocate_us_student_phone($pdo, $userId);
            } else {
                $phoneNumber = $prevPhone !== '' ? $prevPhone : null;
            }
        }
        $stmt->execute([
            ':user_id' => $userId,
            ':first_name' => $r['first_name'] ?? '',
            ':middle_name' => ($r['middle_name'] ?? '') !== '' ? $r['middle_name'] : null,
            ':last_name' => $r['last_name'] ?? '',
            ':apt_no' => ($r['apt_no'] ?? '') !== '' ? $r['apt_no'] : null,
            ':street' => ($r['street'] ?? '') !== '' ? $r['street'] : null,
            ':city' => ($r['city'] ?? '') !== '' ? $r['city'] : null,
            ':state' => ($r['state'] ?? '') !== '' ? $r['state'] : null,
            ':zip_code' => ($r['zip_code'] ?? '') !== '' ? $r['zip_code'] : null,
            ':email' => $email,
            ':phone_number' => $phoneNumber,
            ':gender' => ($r['gender'] ?? '') !== '' ? $r['gender'] : null,
            ':dob' => parse_date($r['dob'] ?? null),
            ':user_type' => $r['user_type'] ?? 'Unknown',
        ]);
    }

    fwrite(STDOUT, "Imported users\n");
}

function import_students_from_users(PDO $pdo): void
{
    // Derive student records from users.user_type = 'Student'
    $pdo->exec('
      INSERT IGNORE INTO students (student_id)
      SELECT user_id FROM users WHERE user_type = "Student"
    ');
    fwrite(STDOUT, "Derived students from users\n");
}

function import_faculty(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO faculty (faculty_id, office_number, `rank`, faculty_type, email, phone_number)
      VALUES (:faculty_id, :office_number, :rank, :faculty_type, :email, :phone_number)
      ON DUPLICATE KEY UPDATE
        office_number=VALUES(office_number),
        `rank`=VALUES(`rank`),
        faculty_type=VALUES(faculty_type),
        email=VALUES(email),
        phone_number=VALUES(phone_number)
    ');
    foreach ($rows as $r) {
        $id = parse_int($r['faculty_id'] ?? null);
        if ($id === null) {
            continue;
        }
        $csvEmail = trim((string)($r['email'] ?? ''));
        if ($csvEmail !== '') {
            $email = $csvEmail;
        } else {
            $ex = $pdo->prepare('SELECT email FROM faculty WHERE faculty_id = ? LIMIT 1');
            $ex->execute([$id]);
            $prev = $ex->fetch(PDO::FETCH_ASSOC);
            $prevEmail = trim((string)($prev['email'] ?? ''));
            if ($prevEmail !== '') {
                $email = $prevEmail;
            } else {
                $uSt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1');
                $uSt->execute([$id]);
                $urow = $uSt->fetch(PDO::FETCH_ASSOC) ?: [];
                $email = northbridge_allocate_school_email(
                    $pdo,
                    (string)($urow['first_name'] ?? ''),
                    (string)($urow['last_name'] ?? ''),
                    $id
                );
            }
        }
        $stmt->execute([
            ':faculty_id' => $id,
            ':office_number' => ($r['office_number'] ?? '') !== '' ? $r['office_number'] : null,
            ':rank' => ($r['rank'] ?? '') !== '' ? $r['rank'] : null,
            ':faculty_type' => ($r['faculty_type'] ?? '') !== '' ? $r['faculty_type'] : null,
            ':email' => $email,
            ':phone_number' => ($r['phone_number'] ?? '') !== '' ? $r['phone_number'] : null,
        ]);
        if (trim((string)($r['email'] ?? '')) === '' && $email !== '') {
            $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ? AND (email IS NULL OR TRIM(email) = "")')
                ->execute([$email, $id]);
        }
    }
    fwrite(STDOUT, "Imported faculty\n");
}

function import_departments(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO departments (dept_id, dept_name, room_number, building_number, chair_id, email, phone_number, dept_assistant)
      VALUES (:dept_id, :dept_name, :room_number, :building_number, :chair_id, :email, :phone_number, :dept_assistant)
      ON DUPLICATE KEY UPDATE
        dept_name=VALUES(dept_name),
        room_number=VALUES(room_number),
        building_number=VALUES(building_number),
        chair_id=VALUES(chair_id),
        email=VALUES(email),
        phone_number=VALUES(phone_number),
        dept_assistant=VALUES(dept_assistant)
    ');
    foreach ($rows as $r) {
        $deptId = trim((string)($r['dept_id'] ?? ''));
        if ($deptId === '') continue;
        $stmt->execute([
            ':dept_id' => $deptId,
            ':dept_name' => $r['dept_name'] ?? $deptId,
            ':room_number' => ($r['room_number'] ?? '') !== '' ? $r['room_number'] : null,
            ':building_number' => ($r['building_number'] ?? '') !== '' ? $r['building_number'] : null,
            ':chair_id' => parse_int($r['chair_id'] ?? null),
            ':email' => ($r['email'] ?? '') !== '' ? $r['email'] : null,
            ':phone_number' => ($r['phone_number'] ?? '') !== '' ? $r['phone_number'] : null,
            ':dept_assistant' => ($r['dept_assistant'] ?? '') !== '' ? $r['dept_assistant'] : null,
        ]);
    }
    fwrite(STDOUT, "Imported departments\n");
}

function import_faculty_departments(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO faculty_departments (faculty_id, dept_id, percent_time, date_of_appointment)
      VALUES (:faculty_id, :dept_id, :percent_time, :date_of_appointment)
      ON DUPLICATE KEY UPDATE
        percent_time=VALUES(percent_time),
        date_of_appointment=VALUES(date_of_appointment)
    ');
    foreach ($rows as $r) {
        $facultyId = parse_int($r['faculty_id'] ?? null);
        $deptId = trim((string)($r['dept_id'] ?? ''));
        if ($facultyId === null || $deptId === '') continue;
        $stmt->execute([
            ':faculty_id' => $facultyId,
            ':dept_id' => $deptId,
            ':percent_time' => parse_int($r['percent_time'] ?? null),
            ':date_of_appointment' => parse_date($r['date_of_appointment'] ?? null),
        ]);
    }
    fwrite(STDOUT, "Imported faculty_departments\n");
}

function import_student_departments(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO student_departments (student_id, dept_id, date_of_declaration)
      VALUES (:student_id, :dept_id, :date_of_declaration)
      ON DUPLICATE KEY UPDATE date_of_declaration=VALUES(date_of_declaration)
    ');
    foreach ($rows as $r) {
        $studentId = parse_int($r['student_id'] ?? null);
        $deptId = trim((string)($r['dept_id'] ?? ''));
        if ($studentId === null || $deptId === '') continue;
        $stmt->execute([
            ':student_id' => $studentId,
            ':dept_id' => $deptId,
            ':date_of_declaration' => parse_date($r['date_of_declaration'] ?? null),
        ]);
    }
    fwrite(STDOUT, "Imported student_departments\n");
}

function import_undergrad_students(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO undergrad_students (student_id, student_type)
      VALUES (:student_id, :student_type)
      ON DUPLICATE KEY UPDATE student_type=VALUES(student_type)
    ');
    foreach ($rows as $r) {
        $studentId = parse_int($r['student_id'] ?? null);
        if ($studentId === null) continue;
        $stmt->execute([
            ':student_id' => $studentId,
            ':student_type' => $r['student_type'] ?? 'Unknown',
        ]);
    }
    fwrite(STDOUT, "Imported undergrad_students\n");
}

function import_ug_credit_limits(PDO $pdo, string $path, string $studentType): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO ug_credit_limits (student_id, student_type, year, max_credit, min_credit, total_credit_earned)
      VALUES (:student_id, :student_type, :year, :max_credit, :min_credit, :total_credit_earned)
      ON DUPLICATE KEY UPDATE
        student_type=VALUES(student_type),
        year=VALUES(year),
        max_credit=VALUES(max_credit),
        min_credit=VALUES(min_credit),
        total_credit_earned=VALUES(total_credit_earned)
    ');
    foreach ($rows as $r) {
        $studentId = parse_int($r['student_id'] ?? null);
        if ($studentId === null) continue;
        $stmt->execute([
            ':student_id' => $studentId,
            ':student_type' => $studentType,
            ':year' => (int)($r['year'] ?? 0),
            ':max_credit' => (int)($r['max_credit'] ?? 0),
            ':min_credit' => (int)($r['min_credit'] ?? 0),
            ':total_credit_earned' => (int)($r['total_credit_earned'] ?? 0),
        ]);
    }
    fwrite(STDOUT, "Imported ug_credit_limits ($studentType)\n");
}

function import_grad_student_programs(PDO $pdo, string $path): void
{
    $rows = csv_rows($path);
    $stmt = $pdo->prepare('
      INSERT INTO grad_student_programs (student_id, program_id, year, thesis_year, total_credit_earned)
      VALUES (:student_id, :program_id, :year, :thesis_year, :total_credit_earned)
      ON DUPLICATE KEY UPDATE
        year=VALUES(year),
        thesis_year=VALUES(thesis_year),
        total_credit_earned=VALUES(total_credit_earned)
    ');
    foreach ($rows as $r) {
        $studentId = parse_int($r['student_id'] ?? null);
        $programId = parse_int($r['program_id'] ?? null);
        if ($studentId === null || $programId === null) continue;
        $stmt->execute([
            ':student_id' => $studentId,
            ':program_id' => $programId,
            ':year' => parse_int($r['year'] ?? null),
            ':thesis_year' => parse_int($r['thesis_year'] ?? null),
            ':total_credit_earned' => parse_int($r['total_credit_earned'] ?? null),
        ]);
    }
    fwrite(STDOUT, "Imported grad_student_programs\n");
}

function import_courses_from_major(PDO $pdo, string $path): void
{
    // This file is not a standard row-based CSV. We'll extract rows where course_id looks like ABC123.
    $rows = csv_rows($path);
    if (!$rows) {
        fwrite(STDOUT, "No rows found in majors file\n");
        return;
    }

    $stmt = $pdo->prepare('
      INSERT INTO courses (course_id, course_name, credits, dept_id)
      VALUES (:course_id, :course_name, :credits, :dept_id)
      ON DUPLICATE KEY UPDATE
        course_name=VALUES(course_name),
        credits=VALUES(credits),
        dept_id=VALUES(dept_id)
    ');

    $seen = [];
    foreach ($rows as $r) {
        $courseId = trim((string)($r['course_id'] ?? ''));
        $courseName = trim((string)($r['course_name'] ?? ''));
        $credits = parse_int($r['credits'] ?? null);

        if ($courseId === '' || $courseName === '' || $credits === null) {
            continue;
        }
        if (!preg_match('/^[A-Z]{2,10}[0-9]{2,4}$/', $courseId) && !preg_match('/^[A-Z]{2,10}\/[A-Z]{2,10}\s*[0-9]{2,4}$/', $courseId)) {
            // allow PSY/SOC 101-like codes? We'll normalize spaces.
            $courseId = str_replace(' ', '', $courseId);
            if (!preg_match('/^[A-Z]{2,10}\/[A-Z]{2,10}[0-9]{2,4}$/', $courseId) && !preg_match('/^[A-Z]{2,10}[0-9]{2,4}$/', $courseId)) {
                continue;
            }
        }

        if (isset($seen[$courseId])) continue;
        $seen[$courseId] = true;

        $deptId = null;
        if (preg_match('/^([A-Z]{2,10})/', $courseId, $m)) {
            $deptId = $m[1];
        }

        $stmt->execute([
            ':course_id' => $courseId,
            ':course_name' => $courseName,
            ':credits' => $credits,
            ':dept_id' => $deptId,
        ]);
    }

    fwrite(STDOUT, "Imported courses from majors file\n");
}

$base = __DIR__ . '/../storage/import';
$files = [
    'users' => $base . '/users - users.csv',
    'faculty' => $base . '/faculty.csv',
    'departments' => $base . '/department.csv',
    'faculty_departments' => $base . '/faculty_department.csv',
    'student_departments' => $base . '/student_department_df - student_department_df.csv',
    'undergrad_students' => $base . '/undergrad_student.csv',
    'ug_fulltime' => $base . '/UG_fulltime.csv',
    'ug_parttime' => $base . '/UG_parttime.csv',
    'grad_student_programs' => $base . '/grad_student_program.csv',
    'majors_visual_arts' => $base . '/Majors - B.A. in Visual Arts.csv',
];

$pdo = db();
$pdo->beginTransaction();
try {
    import_users($pdo, $files['users']);
    import_students_from_users($pdo);
    import_faculty($pdo, $files['faculty']);
    import_departments($pdo, $files['departments']);
    import_faculty_departments($pdo, $files['faculty_departments']);
    import_student_departments($pdo, $files['student_departments']);
    import_undergrad_students($pdo, $files['undergrad_students']);
    import_ug_credit_limits($pdo, $files['ug_fulltime'], 'Fulltime');
    import_ug_credit_limits($pdo, $files['ug_parttime'], 'Parttime');
    import_grad_student_programs($pdo, $files['grad_student_programs']);
    import_courses_from_major($pdo, $files['majors_visual_arts']);

    $pdo->commit();
    fwrite(STDOUT, "All imports committed.\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (getenv('SKIP_CATALOG_ENRICH') === '1') {
    fwrite(STDOUT, "SKIP_CATALOG_ENRICH=1 — skipping catalog description/prerequisite auto-fill.\n");
} else {
    require_once __DIR__ . '/../app/lib/enrich_catalog.php';
    try {
        $pdo = db();
        $stats = enrich_catalog_run($pdo, false);
        fwrite(
            STDOUT,
            "Catalog auto-enrich: {$stats['descriptions']} course description(s) filled, {$stats['prereqs']} prerequisite link(s) added (infer from numbering).\n"
        );
    } catch (Throwable $e) {
        fwrite(STDERR, 'Catalog auto-enrich skipped (non-fatal): ' . $e->getMessage() . "\n");
    }
}
