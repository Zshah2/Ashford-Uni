<?php

declare(strict_types=1);

/**
 * One-time midterm schema load. Rebuilds time slots, history, semester ids, and room ids
 * from the sections already in collegeweb. Refuses to run again once student_history exists.
 */

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$already = $pdo->query("
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_history'
")->fetchColumn();
if ((int)$already > 0) {
    fwrite(STDOUT, "student_history already exists. This load already ran.\n");
    exit(0);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $st->execute([$table, $column]);

    return (bool)$st->fetchColumn();
}

function exec_sql(PDO $pdo, string $sql): void
{
    $pdo->exec($sql);
}

$migration = file_get_contents(__DIR__ . '/../database/migrations/028_midterm_time_and_history.sql');
if ($migration === false) {
    fwrite(STDERR, "Missing migration 028\n");
    exit(1);
}
foreach (explode(';', $migration) as $statement) {
    $lines = [];
    foreach (preg_split("/\r\n|\n|\r/", $statement) as $line) {
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '--')) {
            continue;
        }
        $lines[] = $line;
    }
    $statement = trim(implode("\n", $lines));
    if ($statement === '') {
        continue;
    }
    exec_sql($pdo, $statement);
}

exec_sql($pdo, 'ALTER TABLE terms MODIFY code VARCHAR(16) NOT NULL');

$codeMap = [
    'FA22' => 'fall2022',
    'SP23' => 'spring2023',
    'FA23' => 'fall2023',
    'SP24' => 'spring2024',
    'FA24' => 'fall2024',
    'SP25' => 'spring2025',
    'FA25' => 'fall2025',
    'SP26' => 'spring2026',
    'FA26' => 'fall2026',
    'WI27' => 'winter2027',
    'SP27' => 'spring2027',
    'FA27' => 'fall2027',
];
$rename = $pdo->prepare('UPDATE terms SET code = ? WHERE code = ?');
foreach ($codeMap as $old => $new) {
    $rename->execute([$new, $old]);
}

exec_sql($pdo, 'ALTER TABLE classes DROP FOREIGN KEY fk_classes_semester');
exec_sql($pdo, 'ALTER TABLE classes MODIFY semester_id VARCHAR(16) NOT NULL');
exec_sql($pdo, 'ALTER TABLE classes MODIFY time_slot_id VARCHAR(40) NULL');
exec_sql($pdo, 'ALTER TABLE classes MODIFY faculty_id BIGINT UNSIGNED NULL');
exec_sql($pdo, 'DROP TABLE IF EXISTS semesters');
exec_sql($pdo, '
  CREATE TABLE semesters (
    semester_id VARCHAR(16) NOT NULL,
    semester_name VARCHAR(40) NOT NULL,
    semester_year SMALLINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    PRIMARY KEY (semester_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
');
exec_sql($pdo, '
  INSERT INTO semesters (semester_id, semester_name, semester_year, start_date, end_date)
  SELECT code, name, YEAR(start_date), start_date, end_date
  FROM terms
  WHERE start_date IS NOT NULL AND end_date IS NOT NULL
');
exec_sql($pdo, '
  ALTER TABLE classes
  ADD CONSTRAINT fk_classes_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
');

$days = [
    'M' => 'Monday',
    'T' => 'Tuesday',
    'W' => 'Wednesday',
    'R' => 'Thursday',
    'F' => 'Friday',
];
$dayIns = $pdo->prepare('INSERT IGNORE INTO `day` (day_id, weekday) VALUES (?, ?)');
foreach ($days as $id => $name) {
    $dayIns->execute([$id, $name]);
}

$splitDays = static function (string $pattern): array {
    $pattern = strtoupper(preg_replace('/[^A-Z]/', '', $pattern) ?? '');
    $out = [];
    $len = strlen($pattern);
    for ($i = 0; $i < $len; $i++) {
        $ch = $pattern[$i];
        if ($ch === 'T' && ($pattern[$i + 1] ?? '') === 'R') {
            $out[] = 'T';
            $out[] = 'R';
            $i++;
            continue;
        }
        if (isset(['M' => 1, 'T' => 1, 'W' => 1, 'R' => 1, 'F' => 1][$ch])) {
            $out[] = $ch;
        }
    }

    return array_values(array_unique($out));
};

$periodFor = static function (string $meetingTime): ?array {
    if (!preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $meetingTime, $m)) {
        return null;
    }
    $start = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
    $end = sprintf('%02d:%02d:00', (int)$m[3], (int)$m[4]);
    $id = sprintf('%02d%02d-%02d%02d', (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]);

    return [$id, $start, $end];
};

$patterns = $pdo->query('
  SELECT DISTINCT meeting_days, meeting_time
  FROM sections
  WHERE meeting_days IS NOT NULL AND meeting_days <> ""
    AND meeting_time IS NOT NULL AND meeting_time <> ""
')->fetchAll(PDO::FETCH_ASSOC);

$periodIns = $pdo->prepare('INSERT IGNORE INTO period (period_id, start_time, end_time) VALUES (?, ?, ?)');
$slotIns = $pdo->prepare('INSERT IGNORE INTO time_slot (time_slot_id) VALUES (?)');
$slotDayIns = $pdo->prepare('INSERT IGNORE INTO time_slot_day (time_slot_id, day_id) VALUES (?, ?)');
$slotPeriodIns = $pdo->prepare('INSERT IGNORE INTO time_slot_period (time_slot_id, period_id) VALUES (?, ?)');

foreach ($patterns as $pattern) {
    $period = $periodFor((string)$pattern['meeting_time']);
    $dayIds = $splitDays((string)$pattern['meeting_days']);
    if ($period === null || $dayIds === []) {
        continue;
    }
    [$periodId, $start, $end] = $period;
    $periodIns->execute([$periodId, $start, $end]);
    $slotId = strtoupper((string)$pattern['meeting_days']) . '-' . $periodId;
    $slotIns->execute([$slotId]);
    foreach ($dayIds as $dayId) {
        $slotDayIns->execute([$slotId, $dayId]);
    }
    $slotPeriodIns->execute([$slotId, $periodId]);
}

if (!column_exists($pdo, 'sections', 'time_slot_id')) {
    exec_sql($pdo, 'ALTER TABLE sections ADD COLUMN time_slot_id VARCHAR(40) NULL AFTER faculty_id');
    exec_sql($pdo, '
      ALTER TABLE sections
      ADD CONSTRAINT fk_sections_time_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(time_slot_id)
        ON UPDATE CASCADE ON DELETE SET NULL
    ');
}

$pdo->exec('
  UPDATE sections
  SET time_slot_id = CONCAT(UPPER(meeting_days), "-", 
    CONCAT(
      LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(meeting_time, "-", 1), ":", 1), 2, "0"),
      LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(meeting_time, "-", 1), ":", -1), 2, "0"),
      "-",
      LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(meeting_time, "-", -1), ":", 1), 2, "0"),
      LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX(meeting_time, "-", -1), ":", -1), 2, "0")
    ))
  WHERE meeting_days IS NOT NULL AND meeting_time IS NOT NULL
');

exec_sql($pdo, 'SET FOREIGN_KEY_CHECKS=0');

exec_sql($pdo, "
  INSERT IGNORE INTO buildings (building_id, building_name, building_use) VALUES
    ('NAB', 'North Academic Building', 'academic'),
    ('LIB', 'Library', 'library'),
    ('SSC', 'Student Services Center', 'student services')
");

$lectureRooms = $pdo->query('SELECT DISTINCT room FROM sections WHERE room IS NOT NULL AND room <> "" ORDER BY room')->fetchAll(PDO::FETCH_COLUMN);
$sectionRoomMap = [];
$n = 100;
foreach ($lectureRooms as $oldRoom) {
    $sectionRoomMap[(string)$oldRoom] = 'NAB' . $n;
    $n++;
}

$officeRows = $pdo->query('SELECT room_id, building_id FROM rooms ORDER BY room_id')->fetchAll(PDO::FETCH_ASSOC);
$officeMap = [];
$libN = 101;
$sscN = 201;
$nabOffice = 500;
foreach ($officeRows as $row) {
    $oldId = (string)$row['room_id'];
    $building = (string)$row['building_id'];
    if ($building === 'Lib') {
        $officeMap[$oldId] = 'LIB' . $libN;
        $libN++;
    } elseif ($building === 'SSC') {
        $officeMap[$oldId] = 'SSC' . $sscN;
        $sscN++;
    } else {
        $officeMap[$oldId] = 'NAB' . $nabOffice;
        $nabOffice++;
    }
}

$updateRoom = $pdo->prepare('UPDATE rooms SET room_id = ?, building_id = ?, room_number = ? WHERE room_id = ?');
foreach ($officeMap as $oldId => $newId) {
    $building = substr($newId, 0, 3);
    if (!in_array($building, ['NAB', 'LIB', 'SSC'], true)) {
        $building = substr($newId, 0, 2);
    }
    if (str_starts_with($newId, 'NAB')) {
        $building = 'NAB';
        $number = substr($newId, 3);
    } elseif (str_starts_with($newId, 'LIB')) {
        $building = 'LIB';
        $number = substr($newId, 3);
    } else {
        $building = 'SSC';
        $number = substr($newId, 3);
    }
    $updateRoom->execute([$newId, $building, $number, $oldId]);
}

$childUpdates = [
    'UPDATE office_rooms SET room_id = ? WHERE room_id = ?',
    'UPDATE lecture_rooms SET room_id = ? WHERE room_id = ?',
    'UPDATE lab_rooms SET room_id = ? WHERE room_id = ?',
    'UPDATE departments SET building_room_id = ? WHERE building_room_id = ?',
];
foreach ($childUpdates as $sql) {
    $st = $pdo->prepare($sql);
    foreach ($officeMap as $oldId => $newId) {
        $st->execute([$newId, $oldId]);
    }
}
$officeName = $pdo->prepare('UPDATE faculty SET office_number = ? WHERE office_number = ?');
foreach ($officeMap as $oldId => $newId) {
    $officeName->execute([$newId, $oldId]);
}
$sectionRoom = $pdo->prepare('UPDATE sections SET room = ? WHERE room = ?');
foreach ($sectionRoomMap as $oldRoom => $newId) {
    $sectionRoom->execute([$newId, $oldRoom]);
}

$insertRoom = $pdo->prepare('
  INSERT IGNORE INTO rooms (room_id, building_id, room_number, room_type)
  VALUES (?, "NAB", ?, "lecture")
');
$insertLecture = $pdo->prepare('INSERT IGNORE INTO lecture_rooms (room_id, seats_available) VALUES (?, 30)');
foreach ($sectionRoomMap as $newId) {
    $insertRoom->execute([$newId, substr($newId, 3)]);
    $insertLecture->execute([$newId]);
}

exec_sql($pdo, "DELETE FROM buildings WHERE CAST(building_id AS BINARY) IN ('AB','Admin','Gym','Lib')");
exec_sql($pdo, "INSERT IGNORE INTO buildings (building_id, building_name, building_use) VALUES ('LIB', 'Library', 'library')");
exec_sql($pdo, 'SET FOREIGN_KEY_CHECKS=1');

exec_sql($pdo, 'DELETE FROM classes');
exec_sql($pdo, '
  INSERT INTO classes (crn, course_id, section_number, faculty_id, time_slot_id, lecture_id, semester_id, available_seats)
  SELECT
    s.section_id,
    s.course_id,
    LPAD(ROW_NUMBER() OVER (PARTITION BY s.course_id, s.term_id ORDER BY s.section_id), 2, "0"),
    s.faculty_id,
    s.time_slot_id,
    s.room,
    t.code,
    s.capacity
  FROM sections s
  INNER JOIN terms t ON t.term_id = s.term_id
  INNER JOIN rooms r ON r.room_id = s.room
');

exec_sql($pdo, '
  INSERT IGNORE INTO grade_letters (grade)
  SELECT DISTINCT letter_grade FROM student_course_results WHERE letter_grade IS NOT NULL AND letter_grade <> ""
');

exec_sql($pdo, 'DROP TABLE IF EXISTS student_history');
exec_sql($pdo, 'DROP TABLE IF EXISTS faculty_history');
exec_sql($pdo, '
  CREATE TABLE student_history (
    student_id BIGINT UNSIGNED NOT NULL,
    crn BIGINT UNSIGNED NOT NULL,
    course_id VARCHAR(30) NOT NULL,
    semester_id VARCHAR(16) NOT NULL,
    grade VARCHAR(5) NULL,
    PRIMARY KEY (student_id, crn),
    CONSTRAINT fk_stu_hist_student FOREIGN KEY (student_id) REFERENCES students(student_id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stu_hist_class FOREIGN KEY (crn) REFERENCES classes(crn)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_stu_hist_course FOREIGN KEY (course_id) REFERENCES courses(course_id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_stu_hist_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_stu_hist_grade FOREIGN KEY (grade) REFERENCES grade_letters(grade)
      ON UPDATE CASCADE ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
');
exec_sql($pdo, '
  CREATE TABLE faculty_history (
    faculty_id BIGINT UNSIGNED NOT NULL,
    crn BIGINT UNSIGNED NOT NULL,
    course_id VARCHAR(30) NOT NULL,
    semester_id VARCHAR(16) NOT NULL,
    PRIMARY KEY (faculty_id, crn),
    CONSTRAINT fk_fac_hist_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_fac_hist_class FOREIGN KEY (crn) REFERENCES classes(crn)
      ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_fac_hist_course FOREIGN KEY (course_id) REFERENCES courses(course_id)
      ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_fac_hist_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id)
      ON UPDATE CASCADE ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
');

exec_sql($pdo, '
  INSERT INTO student_history (student_id, crn, course_id, semester_id, grade)
  SELECT e.student_id, s.section_id, s.course_id, t.code, g.letter_grade
  FROM enrollments e
  INNER JOIN sections s ON s.section_id = e.section_id
  INNER JOIN terms t ON t.term_id = s.term_id
  INNER JOIN classes c ON c.crn = s.section_id
  LEFT JOIN student_course_results g
    ON g.student_id = e.student_id AND g.course_id = s.course_id AND g.term_id = s.term_id
  WHERE e.status = "enrolled"
');
exec_sql($pdo, '
  INSERT INTO faculty_history (faculty_id, crn, course_id, semester_id)
  SELECT s.faculty_id, s.section_id, s.course_id, t.code
  FROM sections s
  INNER JOIN terms t ON t.term_id = s.term_id
  INNER JOIN classes c ON c.crn = s.section_id
  WHERE s.faculty_id IS NOT NULL
');

echo "terms\n";
foreach ($pdo->query('SELECT code, name FROM terms ORDER BY start_date') as $row) {
    echo '  ' . $row['code'] . ' ' . $row['name'] . "\n";
}
echo 'buildings ' . $pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn() . "\n";
foreach ($pdo->query('SELECT building_id, building_name FROM buildings ORDER BY building_id') as $row) {
    echo '  ' . $row['building_id'] . ' ' . $row['building_name'] . "\n";
}
echo 'time_slot ' . $pdo->query('SELECT COUNT(*) FROM time_slot')->fetchColumn() . "\n";
echo 'time_slot_day ' . $pdo->query('SELECT COUNT(*) FROM time_slot_day')->fetchColumn() . "\n";
echo 'time_slot_period ' . $pdo->query('SELECT COUNT(*) FROM time_slot_period')->fetchColumn() . "\n";
echo 'classes ' . $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn() . "\n";
echo 'student_history ' . $pdo->query('SELECT COUNT(*) FROM student_history')->fetchColumn() . "\n";
echo 'faculty_history ' . $pdo->query('SELECT COUNT(*) FROM faculty_history')->fetchColumn() . "\n";
echo 'sections missing slot ' . $pdo->query('SELECT COUNT(*) FROM sections WHERE time_slot_id IS NULL')->fetchColumn() . "\n";
echo 'bad room ids ' . $pdo->query('SELECT COUNT(*) FROM rooms WHERE room_id NOT REGEXP "^[A-Z]{3}[0-9]{3}$"')->fetchColumn() . "\n";
