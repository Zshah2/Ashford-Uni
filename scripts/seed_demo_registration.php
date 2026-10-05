<?php

declare(strict_types=1);

/**
 * Demo terms, rich course catalog (descriptions + prerequisites), sections across
 * Fall 2026 + Spring 2027, enrollments, and a sample hold — so Admin → Courses and
 * course detail pages show complete, realistic data.
 *
 * Run after import:
 *   php scripts/migrate.php
 *   php scripts/import_all.php
 *   php scripts/seed_demo_registration.php
 *
 * Pure-SQL alternative (expects faculty + students present):
 *   mysql ... < database/seeds/prefill_course_demo.sql
 *
 * After this, for every imported course (fill empty descriptions + infer prereqs):
 *   php scripts/enrich_all_courses.php
 */

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';

const DEMO_STUDENT_ID = 123123;
const DEMO_FACULTY_ID = 123152;

/** @return list<int> */
function seed_demo_faculty_ids(PDO $pdo): array
{
    $rows = $pdo->query('SELECT faculty_id FROM faculty ORDER BY faculty_id')->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_map(static fn ($id) => (int)$id, $rows);
}

function seed_demo_pick_faculty(array $facultyIds, int $index): int
{
    $n = count($facultyIds);
    if ($n < 1) {
        return 0;
    }

    return $facultyIds[$index % $n];
}

function seed_demo_resolve_student(PDO $pdo): int
{
    $st = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ?');
    $st->execute([DEMO_STUDENT_ID]);
    if ($st->fetchColumn()) {
        return DEMO_STUDENT_ID;
    }
    $sid = $pdo->query('SELECT student_id FROM students ORDER BY student_id LIMIT 1')->fetchColumn();

    return $sid !== false ? (int)$sid : 0;
}

/** Resolve department code if it exists; otherwise NULL (valid FK). */
function seed_demo_dept(PDO $pdo, string $code): ?string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT dept_id FROM departments WHERE dept_id = ? LIMIT 1');
    $st->execute([$code]);

    return $st->fetchColumn() ? $code : null;
}

/**
 * @param array{course_id: string, course_name: string, description: string, credits: int, dept_pref: ?string} $row
 */
function seed_demo_upsert_course(PDO $pdo, array $row): void
{
    $dept = $row['dept_pref'] !== null ? seed_demo_dept($pdo, $row['dept_pref']) : null;
    $ins = $pdo->prepare('
      INSERT INTO courses (course_id, course_name, description, credits, dept_id, is_active)
      VALUES (?, ?, ?, ?, ?, 1)
      ON DUPLICATE KEY UPDATE
        course_name = VALUES(course_name),
        credits = VALUES(credits),
        dept_id = COALESCE(VALUES(dept_id), dept_id),
        description = VALUES(description),
        is_active = VALUES(is_active)
    ');
    $ins->execute([
        $row['course_id'],
        $row['course_name'],
        $row['description'],
        $row['credits'],
        $dept,
    ]);
}

function seed_demo_term_id(PDO $pdo, string $code): int
{
    $st = $pdo->prepare('SELECT term_id FROM terms WHERE code = ? LIMIT 1');
    $st->execute([$code]);
    $tid = $st->fetchColumn();

    return $tid !== false ? (int)$tid : 0;
}

function seed_demo_ensure_section(
    PDO $pdo,
    int $termId,
    string $courseId,
    int $facultyId,
    string $days,
    string $time,
    string $room,
    int $cap
): void {
    if ($termId < 1 || $facultyId < 1) {
        return;
    }
    $ex = $pdo->prepare('SELECT 1 FROM sections WHERE term_id = ? AND course_id = ? LIMIT 1');
    $ex->execute([$termId, $courseId]);
    if ($ex->fetchColumn()) {
        return;
    }
    $pdo->prepare('
      INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$courseId, $termId, $facultyId, $days, $time, $room, $cap]);
}

/** Additional sections for the same course in one term (e.g. multiple BIO labs). */
function seed_demo_ensure_section_slot(
    PDO $pdo,
    int $termId,
    string $courseId,
    int $facultyId,
    string $days,
    string $time,
    string $room,
    int $cap
): void {
    if ($termId < 1 || $facultyId < 1) {
        return;
    }
    $ex = $pdo->prepare('
      SELECT 1 FROM sections
      WHERE term_id = ? AND course_id = ? AND meeting_days = ? AND meeting_time = ? AND COALESCE(room,"") = ?
      LIMIT 1
    ');
    $ex->execute([$termId, $courseId, $days, $time, $room]);
    if ($ex->fetchColumn()) {
        return;
    }
    $pdo->prepare('
      INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([$courseId, $termId, $facultyId, $days, $time, $room, $cap]);
}

$pdo = db();

$studentId = seed_demo_resolve_student($pdo);
if ($studentId < 1) {
    fwrite(STDERR, "No students in database. Run: php scripts/import_all.php\n");
    exit(1);
}

$facultyIds = seed_demo_faculty_ids($pdo);
if ($facultyIds === []) {
    fwrite(STDERR, "No faculty in database. Run: php scripts/import_all.php\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('
      INSERT INTO terms (code, name, start_date, end_date)
      VALUES ("fall2026", "Fall 2026", "2026-08-20", "2026-12-15")
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        start_date = VALUES(start_date),
        end_date = VALUES(end_date)
    ')->execute();

    $pdo->prepare('
      INSERT INTO terms (code, name, start_date, end_date)
      VALUES ("spring2027", "Spring 2027", "2027-01-11", "2027-05-08")
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        start_date = VALUES(start_date),
        end_date = VALUES(end_date)
    ')->execute();

    $pdo->prepare('
      INSERT INTO terms (code, name, start_date, end_date)
      VALUES ("fall2027", "Fall 2027", "2027-08-23", "2027-12-16")
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        start_date = VALUES(start_date),
        end_date = VALUES(end_date)
    ')->execute();

    $termFa = seed_demo_term_id($pdo, 'fall2026');
    $termSp = seed_demo_term_id($pdo, 'spring2027');
    $termFa27 = seed_demo_term_id($pdo, 'fall2027');
    if ($termFa < 1) {
        throw new RuntimeException('Failed to resolve term fall2026');
    }

    $catalog = [
        [
            'course_id' => 'ENG101',
            'course_name' => 'English Composition I',
            'credits' => 4,
            'dept_pref' => 'ENGL',
            'description' => 'College reading, drafting, and revision. Argumentative and analytical essays, research basics, and MLA documentation. Required for most majors.',
        ],
        [
            'course_id' => 'ENG102',
            'course_name' => 'English Composition II',
            'credits' => 4,
            'dept_pref' => 'ENGL',
            'description' => 'Advanced composition emphasizing synthesis across sources, rhetoric, and revision. Builds directly on ENG101.',
        ],
        [
            'course_id' => 'HIS103',
            'course_name' => 'History of Ideas',
            'credits' => 4,
            'dept_pref' => 'HIS',
            'description' => 'Major themes in intellectual history from antiquity through the early modern period. Primary texts and discussion.',
        ],
        [
            'course_id' => 'CS101',
            'course_name' => 'Introduction to Computer Science',
            'credits' => 4,
            'dept_pref' => null,
            'description' => 'Problem solving, algorithms, and programming fundamentals using a high-level language. Lab projects and pair exercises.',
        ],
        [
            'course_id' => 'CS201',
            'course_name' => 'Data Structures',
            'credits' => 4,
            'dept_pref' => null,
            'description' => 'Abstract data types, lists, trees, hashing, graphs, and algorithmic complexity. Programming-intensive.',
        ],
        [
            'course_id' => 'MATH150',
            'course_name' => 'Calculus I',
            'credits' => 4,
            'dept_pref' => null,
            'description' => 'Limits, derivatives, and integrals of algebraic and transcendental functions with applications.',
        ],
        [
            'course_id' => 'BIO101',
            'course_name' => 'General Biology I',
            'credits' => 4,
            'dept_pref' => 'BIO',
            'description' => 'Cell structure, genetics, evolution, and ecology. Weekly lab experiments and scientific writing. Satisfies lab science requirements for many programs.',
        ],
        [
            'course_id' => 'BIO0098',
            'course_name' => 'Introduction to Biological Inquiry',
            'credits' => 3,
            'dept_pref' => 'BIO',
            'description' => 'Laboratory and lecture introduction to scientific reasoning in biology: hypothesis design, data literacy, microscopy, statistics basics, and evolution as a unifying theme. Prepares majors and pre-health students for upper-level work.',
        ],
        [
            'course_id' => 'CHE0105',
            'course_name' => 'General Chemistry I',
            'credits' => 4,
            'dept_pref' => 'CHE',
            'description' => 'Atomic structure, stoichiometry, gases, thermochemistry, and chemical bonding. Lecture and problem-solving studio; foundation for organic chemistry and laboratory sciences.',
        ],
        [
            'course_id' => 'BI0101',
            'course_name' => 'Biology Foundations',
            'credits' => 3,
            'dept_pref' => 'BIO',
            'description' => 'Molecular and cellular foundations of life: macromolecules, metabolism, cell division, genetics, and gene expression. Laboratories cover microscopy, biochemical assays, and experimental design. For continuing students in biology and allied health after completing gateway prerequisites.',
        ],
    ];

    foreach ($catalog as $row) {
        seed_demo_upsert_course($pdo, $row);
    }

    $pdo->exec('
      INSERT IGNORE INTO course_prereqs (course_id, prereq_course_id) VALUES
      ("ENG102","ENG101"),
      ("CS201","CS101"),
      ("BI0101","BIO0098"),
      ("BI0101","CHE0105")
    ');

    $f = 0;
    seed_demo_ensure_section($pdo, $termFa, 'ENG101', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '09:00-09:50', 'ENG-201', 32);
    seed_demo_ensure_section($pdo, $termFa, 'ENG102', seed_demo_pick_faculty($facultyIds, $f++), 'TR', '09:30-10:45', 'ENG-204', 28);
    seed_demo_ensure_section($pdo, $termFa, 'HIS103', seed_demo_pick_faculty($facultyIds, $f++), 'TR', '13:00-14:15', 'LIB-1107', 36);
    seed_demo_ensure_section($pdo, $termFa, 'CS101', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '11:00-11:50', 'SCI-105', 40);
    seed_demo_ensure_section($pdo, $termFa, 'CS201', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '13:00-13:50', 'SCI-210', 30);
    seed_demo_ensure_section($pdo, $termFa, 'MATH150', seed_demo_pick_faculty($facultyIds, $f++), 'TR', '10:00-11:15', 'MATH-140', 45);
    seed_demo_ensure_section($pdo, $termFa, 'BIO101', seed_demo_pick_faculty($facultyIds, $f++), 'MW', '14:00-15:40', 'LAB-3B', 24);

    if ($termSp > 0) {
        seed_demo_ensure_section($pdo, $termSp, 'ENG101', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '10:00-10:50', 'ENG-201', 32);
        seed_demo_ensure_section($pdo, $termSp, 'HIS103', seed_demo_pick_faculty($facultyIds, $f++), 'TR', '11:00-12:15', 'LIB-1107', 36);
    }

    if ($termFa27 > 0) {
        seed_demo_ensure_section_slot($pdo, $termFa27, 'BI0101', seed_demo_pick_faculty($facultyIds, $f++), 'T', '14:30-15:45', 'BIO-100', 25);
        seed_demo_ensure_section_slot($pdo, $termFa27, 'BI0101', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '09:00-09:50', 'BIO-101', 25);
        seed_demo_ensure_section_slot($pdo, $termFa27, 'BI0101', seed_demo_pick_faculty($facultyIds, $f++), 'TR', '10:00-10:50', 'BIO-102', 25);
        seed_demo_ensure_section($pdo, $termFa27, 'BIO0098', seed_demo_pick_faculty($facultyIds, $f++), 'MW', '11:00-12:15', 'BIO-090', 28);
        seed_demo_ensure_section($pdo, $termFa27, 'CHE0105', seed_demo_pick_faculty($facultyIds, $f++), 'MWF', '13:00-13:50', 'CHE-110', 40);
    }

    $secStmt = $pdo->prepare('SELECT section_id FROM sections WHERE term_id = ? AND course_id = ? LIMIT 1');

    $secStmt->execute([$termFa, 'ENG101']);
    $engSection = (int)$secStmt->fetchColumn();
    $secStmt->execute([$termFa, 'HIS103']);
    $hisSection = (int)$secStmt->fetchColumn();
    $secStmt->execute([$termFa, 'CS101']);
    $csSection = (int)$secStmt->fetchColumn();

    if ($engSection < 1 || $hisSection < 1) {
        throw new RuntimeException('Could not resolve demo section IDs for ENG101 / HIS103');
    }

    $enroll = $pdo->prepare('
      INSERT IGNORE INTO enrollments (student_id, section_id, status)
      VALUES (?, ?, ?)
    ');
    $enroll->execute([$studentId, $engSection, 'enrolled']);
    $enroll->execute([$studentId, $hisSection, 'enrolled']);

    $stuRows = $pdo->query('SELECT student_id FROM students ORDER BY student_id LIMIT 20')->fetchAll(PDO::FETCH_COLUMN);
    $stuList = is_array($stuRows) ? array_map(static fn ($x) => (int)$x, $stuRows) : [];

    $assign = static function (array $ids, int $sectionId, string $status) use ($enroll): void {
        if ($sectionId < 1) {
            return;
        }
        foreach ($ids as $sid) {
            if ($sid < 1) {
                continue;
            }
            $enroll->execute([$sid, $sectionId, $status]);
        }
    };

    $slice = static function (array $all, int $offset, int $len): array {
        return array_slice($all, $offset, $len);
    };

    $assign($slice($stuList, 0, 6), $engSection, 'enrolled');
    $assign($slice($stuList, 2, 4), $hisSection, 'enrolled');
    $assign($slice($stuList, 1, 5), $csSection, 'enrolled');
    $assign($slice($stuList, 8, 2), $csSection, 'waitlisted');

    if ($termFa27 > 0) {
        $biSections = $pdo->prepare('SELECT section_id FROM sections WHERE term_id = ? AND course_id = ? ORDER BY section_id');
        $biSections->execute([$termFa27, 'BI0101']);
        $biSecIds = array_map(static fn ($x) => (int)$x, $biSections->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $off = 0;
        foreach ($biSecIds as $bid) {
            if ($bid < 1) {
                continue;
            }
            $assign($slice($stuList, $off, 6), $bid, 'enrolled');
            $off += 4;
        }
        if ($biSecIds !== []) {
            $assign($slice($stuList, 14, 2), $biSecIds[0], 'waitlisted');
        }
    }

    $hc = $pdo->prepare('
      SELECT COUNT(*) FROM student_holds
      WHERE student_id = ? AND is_active = 1 AND hold_type = "Bursar"
    ');
    $hc->execute([$studentId]);
    if ((int)$hc->fetchColumn() === 0) {
        $pdo->prepare('
          INSERT INTO student_holds (student_id, hold_type, note, is_active)
          VALUES (?, "Bursar", "Demo financial hold — use Admin → Holds to clear.", 1)
        ')->execute([$studentId]);
    }

    $pdo->commit();
    fwrite(STDOUT, 'Demo registration seed OK (student ' . $studentId . ", terms fall2026 + spring2027 + fall2027, catalog with BI0101 prereqs + enrollments).\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
