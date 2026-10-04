<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$idColumns = [
    ['users', 'user_id'],
    ['students', 'student_id'],
    ['faculty', 'faculty_id'],
    ['faculty_departments', 'faculty_id'],
    ['departments', 'chair_id'],
    ['classes', 'faculty_id'],
    ['sections', 'faculty_id'],
    ['enrollments', 'student_id'],
    ['grad_student_programs', 'student_id'],
    ['student_course_results', 'student_id'],
    ['student_declaration_change_log', 'student_id'],
    ['student_departments', 'student_id'],
    ['student_holds', 'student_id'],
    ['student_major_change_stats', 'student_id'],
    ['ug_credit_limits', 'student_id'],
    ['undergrad_students', 'student_id'],
    ['advisors', 'faculty_id'],
    ['advisors', 'student_id'],
];

$studentCount = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$studentsInRange = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE student_id BETWEEN 1000000 AND 1999999')->fetchColumn();
$facultyCount = (int)$pdo->query('SELECT COUNT(*) FROM faculty')->fetchColumn();
$facultyInRange = (int)$pdo->query('SELECT COUNT(*) FROM faculty WHERE faculty_id BETWEEN 9000000 AND 9999999')->fetchColumn();

if ($studentCount > 0 && $studentsInRange === $studentCount && $facultyInRange === $facultyCount) {
    echo "IDs already 7-digit\n";
} else {
    echo "Remapping IDs\n";
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $offset = 50000000;
    foreach ($idColumns as [$table, $column]) {
        $pdo->exec('UPDATE `' . $table . '` SET `' . $column . '` = `' . $column . '` + ' . $offset . ' WHERE `' . $column . '` IS NOT NULL AND `' . $column . '` < 20000000');
    }

    $people = $pdo->query('SELECT user_id, user_type FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $used = [];
    $ranges = [
        'Student' => [1000000, 1999999],
        'Faculty' => [9000000, 9999999],
        'Admin' => [3000000, 3999999],
        'Staff' => [2000000, 2999999],
    ];
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS id_map');
    $pdo->exec('CREATE TEMPORARY TABLE id_map (old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, new_id BIGINT UNSIGNED NOT NULL, UNIQUE KEY uq_id_map_new (new_id))');
    $insMap = $pdo->prepare('INSERT INTO id_map (old_id, new_id) VALUES (?, ?)');
    foreach ($people as $person) {
        $type = (string)$person['user_type'];
        [$lo, $hi] = $ranges[$type] ?? [2000000, 2999999];
        do {
            $next = random_int($lo, $hi);
        } while (isset($used[$next]));
        $used[$next] = true;
        $insMap->execute([(int)$person['user_id'], $next]);
    }
    foreach ($idColumns as [$table, $column]) {
        $pdo->exec('UPDATE `' . $table . '` t INNER JOIN id_map m ON t.`' . $column . '` = m.old_id SET t.`' . $column . '` = m.new_id');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM students s LEFT JOIN users u ON u.user_id = s.student_id WHERE u.user_id IS NULL')->fetchColumn();
    if ($orphans !== 0) {
        fwrite(STDERR, "Remap left $orphans students without a user\n");
        exit(1);
    }
    echo "Remapped " . count($people) . " people\n";
}

$majorPlan = [
    'BIO' => ['Biology'],
    'CHE' => ['Chemistry'],
    'COM' => ['Computer Science', 'Management Information Systems'],
    'ECO' => ['Economics'],
    'ENG' => ['Engineering'],
    'ENGL' => ['English'],
    'HIS' => ['History'],
    'PHI' => ['Philosophy'],
    'PHY' => ['Physics'],
];
$insMajor = $pdo->prepare('INSERT INTO majors (major_name, dept_id) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM majors WHERE major_name = ?)');
foreach ($majorPlan as $deptId => $names) {
    foreach ($names as $name) {
        $insMajor->execute([$name, $deptId, $name]);
    }
}
$minorNames = [
    'BIO' => 'Biology',
    'CHE' => 'Chemistry',
    'COM' => 'Mathematics',
    'ECO' => 'Economics',
    'ENG' => 'Engineering',
    'ENGL' => 'Writing',
    'HIS' => 'History',
    'PHI' => 'Philosophy',
    'PHY' => 'Physics',
];
$insMinor = $pdo->prepare('INSERT INTO minors (minor_name, dept_id) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM minors WHERE minor_name = ?)');
foreach ($minorNames as $deptId => $name) {
    $insMinor->execute([$name, $deptId, $name]);
}

$majorsByDept = [];
foreach ($pdo->query('SELECT major_id, major_name, dept_id FROM majors') as $row) {
    $majorsByDept[(string)$row['dept_id']][] = $row;
}
$minorsByDept = [];
foreach ($pdo->query('SELECT minor_id, dept_id FROM minors') as $row) {
    $minorsByDept[(string)$row['dept_id']] = (int)$row['minor_id'];
}

$decl = $pdo->query('
  SELECT sd.student_id, sd.dept_id, sd.declaration_role, u.email
  FROM student_departments sd
  INNER JOIN users u ON u.user_id = sd.student_id
')->fetchAll(PDO::FETCH_ASSOC);
$setMajor = $pdo->prepare('UPDATE students SET major_id = ? WHERE student_id = ?');
$setMinor = $pdo->prepare('UPDATE students SET minor_id = ? WHERE student_id = ?');
foreach ($decl as $row) {
    if (strtolower((string)$row['email']) === 'jbezo@ashford.edu') {
        continue;
    }
    $deptId = (string)$row['dept_id'];
    $studentId = (int)$row['student_id'];
    if ((string)$row['declaration_role'] === 'minor') {
        if (isset($minorsByDept[$deptId])) {
            $setMinor->execute([$minorsByDept[$deptId], $studentId]);
        }
        continue;
    }
    $choices = $majorsByDept[$deptId] ?? [];
    if ($choices === []) {
        continue;
    }
    $pick = $choices[$studentId % count($choices)];
    $setMajor->execute([(int)$pick['major_id'], $studentId]);
}
$pdo->prepare('UPDATE students s INNER JOIN users u ON u.user_id = s.student_id SET s.major_id = NULL, s.minor_id = NULL WHERE LOWER(u.email) = ?')->execute(['jbezo@ashford.edu']);

$pdo->exec('
  UPDATE faculty f
  INNER JOIN (
    SELECT faculty_id, dept_id
    FROM (
      SELECT faculty_id, dept_id, ROW_NUMBER() OVER (PARTITION BY faculty_id ORDER BY percent_time DESC, dept_id) AS rn
      FROM faculty_departments
    ) ranked
    WHERE rn = 1
  ) pick ON pick.faculty_id = f.faculty_id
  SET f.dept_id = pick.dept_id
');

$pdo->exec('DELETE FROM advisors');
$pools = [];
foreach ($pdo->query('SELECT faculty_id, dept_id FROM faculty WHERE faculty_type = "Fulltime" AND dept_id IS NOT NULL ORDER BY faculty_id') as $row) {
    $pools[(string)$row['dept_id']][] = (int)$row['faculty_id'];
}
$anyFt = $pdo->query('SELECT faculty_id FROM faculty WHERE faculty_type = "Fulltime" ORDER BY faculty_id')->fetchAll(PDO::FETCH_COLUMN);
$advisees = $pdo->query('
  SELECT s.student_id, m.dept_id
  FROM students s
  INNER JOIN majors m ON m.major_id = s.major_id
  INNER JOIN users u ON u.user_id = s.student_id
  WHERE LOWER(u.email) <> "jbezo@ashford.edu"
')->fetchAll(PDO::FETCH_ASSOC);
$insAdvisor = $pdo->prepare('INSERT INTO advisors (faculty_id, student_id) VALUES (?, ?)');
$cursor = [];
foreach ($advisees as $advisee) {
    $deptId = (string)$advisee['dept_id'];
    $pool = $pools[$deptId] ?? $anyFt;
    if ($pool === []) {
        continue;
    }
    $n = $cursor[$deptId] ?? 0;
    $cursor[$deptId] = $n + 1;
    $insAdvisor->execute([(int)$pool[$n % count($pool)], (int)$advisee['student_id']]);
}

$reqCount = (int)$pdo->query('SELECT COUNT(*) FROM degree_requirements')->fetchColumn();
if ($reqCount === 0) {
    $insReq = $pdo->prepare('INSERT INTO degree_requirements (major_id, course_id) VALUES (?, ?)');
    $insDeptReq = $pdo->prepare('INSERT IGNORE INTO degree_requirement_courses (dept_id, course_id, requirement_kind) VALUES (?, ?, "major")');
    foreach ($pdo->query('SELECT major_id, dept_id FROM majors') as $major) {
        $courses = $pdo->prepare('SELECT course_id FROM courses WHERE dept_id = ? ORDER BY course_id LIMIT 4');
        $courses->execute([(string)$major['dept_id']]);
        foreach ($courses->fetchAll(PDO::FETCH_COLUMN) as $courseId) {
            $insReq->execute([(int)$major['major_id'], (string)$courseId]);
            $insDeptReq->execute([(string)$major['dept_id'], (string)$courseId]);
        }
    }
}

$calCount = (int)$pdo->query('SELECT COUNT(*) FROM academic_calendar')->fetchColumn();
if ($calCount === 0) {
    $termId = static function (PDO $pdo, string $code): ?int {
        $stmt = $pdo->prepare('SELECT term_id FROM terms WHERE code = ?');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    };
    $events = [
        ['fall2026', 'Fall 2026 classes begin', '2026-08-20', '2026-08-20', 'First day of the fall term.'],
        ['fall2026', 'Labor Day — no classes', '2026-09-07', '2026-09-07', 'Campus offices closed.'],
        ['fall2026', 'Fall midterm week', '2026-10-12', '2026-10-16', 'Midterm exams in undergraduate courses.'],
        ['fall2026', 'Thanksgiving recess', '2026-11-25', '2026-11-29', 'Classes resume December 1.'],
        ['fall2026', 'Fall 2026 last day of classes', '2026-12-08', '2026-12-08', null],
        ['fall2026', 'Fall 2026 final exams', '2026-12-10', '2026-12-15', 'Term ends December 15.'],
        ['winter2027', 'Winter 2027 session', '2027-01-04', '2027-01-11', 'Short session between fall and spring.'],
        ['spring2027', 'Spring 2027 classes begin', '2027-01-12', '2027-01-12', 'First day of the spring term.'],
        ['spring2027', 'Spring recess', '2027-03-15', '2027-03-19', 'Classes resume March 22.'],
        ['spring2027', 'Spring 2027 last day of classes', '2027-04-30', '2027-04-30', null],
        ['spring2027', 'Spring 2027 final exams', '2027-05-03', '2027-05-07', 'Term ends May 7.'],
    ];
    $insCal = $pdo->prepare('INSERT INTO academic_calendar (term_id, event_name, start_date, end_date, notes) VALUES (?, ?, ?, ?, ?)');
    foreach ($events as [$code, $name, $start, $end, $notes]) {
        $insCal->execute([$termId($pdo, $code), $name, $start, $end, $notes]);
    }
}

$statEmail = 'statdept@ashford.edu';
$faculty = $pdo->query('
  SELECT f.faculty_id, u.first_name, u.last_name
  FROM faculty f
  INNER JOIN users u ON u.user_id = f.faculty_id
  WHERE f.faculty_type = "Fulltime"
  ORDER BY f.faculty_id
  LIMIT 1
')->fetch(PDO::FETCH_ASSOC);
if ($faculty) {
    $hash = password_hash('Main@1234', PASSWORD_DEFAULT);
    $existing = $pdo->prepare('SELECT id FROM auth_users WHERE email = ? OR username = ? LIMIT 1');
    $existing->execute([$statEmail, 'statdept']);
    $authId = $existing->fetchColumn();
    if ($authId === false) {
        $pdo->prepare('INSERT INTO auth_users (username, display_name, email, password_hash, role, person_id, is_active) VALUES (?, ?, ?, ?, "stat", ?, 1)')
            ->execute(['statdept', $faculty['first_name'] . ' ' . $faculty['last_name'], $statEmail, $hash, (int)$faculty['faculty_id']]);
    } else {
        $pdo->prepare('UPDATE auth_users SET role = "stat", person_id = ?, display_name = ?, is_active = 1 WHERE id = ?')
            ->execute([(int)$faculty['faculty_id'], $faculty['first_name'] . ' ' . $faculty['last_name'], (int)$authId]);
    }
}

echo "students " . $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() . "\n";
echo "student id sample " . $pdo->query('SELECT MIN(student_id), MAX(student_id) FROM students')->fetch(PDO::FETCH_NUM)[0] . ' ' . $pdo->query('SELECT MAX(student_id) FROM students')->fetchColumn() . "\n";
echo "faculty id sample " . $pdo->query('SELECT MIN(faculty_id), MAX(faculty_id) FROM faculty')->fetch(PDO::FETCH_NUM)[0] . "\n";
echo "majors " . $pdo->query('SELECT COUNT(*) FROM majors')->fetchColumn() . "\n";
echo "with major " . $pdo->query('SELECT COUNT(*) FROM students WHERE major_id IS NOT NULL')->fetchColumn() . "\n";
echo "with minor " . $pdo->query('SELECT COUNT(*) FROM students WHERE minor_id IS NOT NULL')->fetchColumn() . "\n";
echo "advisors " . $pdo->query('SELECT COUNT(*) FROM advisors')->fetchColumn() . "\n";
echo "degree reqs " . $pdo->query('SELECT COUNT(*) FROM degree_requirements')->fetchColumn() . "\n";
echo "calendar " . $pdo->query('SELECT COUNT(*) FROM academic_calendar')->fetchColumn() . "\n";
echo "jeff major " . $pdo->query('SELECT s.major_id FROM students s JOIN users u ON u.user_id=s.student_id WHERE u.email="jbezo@ashford.edu"')->fetchColumn() . "\n";
echo "done\n";
