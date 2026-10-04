-- Ashford College schema demo. Run this inside the collegeweb database.
-- mysql -h 127.0.0.1 -u root -p collegeweb < docs/class_schema_demo.sql

SELECT DATABASE() AS database_name, VERSION() AS mysql_version;

SHOW TABLES;

-- Headcount the schema is holding
SELECT 'undergraduates' AS group_name, COUNT(*) AS total FROM undergrad_students
UNION ALL
SELECT 'graduates', COUNT(*) FROM grad_student_programs
UNION ALL
SELECT 'faculty', COUNT(*) FROM faculty
UNION ALL
SELECT 'students', COUNT(*) FROM students;

SELECT academic_year_level AS class_year, student_type AS load_type, COUNT(*) AS students
FROM undergrad_students
GROUP BY academic_year_level, student_type
ORDER BY FIELD(academic_year_level, 'Freshman', 'Sophomore', 'Junior', 'Senior'), student_type;

SELECT p.name AS program, COUNT(*) AS students
FROM grad_student_programs g
JOIN programs p ON p.program_id = g.program_id
GROUP BY p.name;

SELECT faculty_type, COUNT(*) AS faculty
FROM faculty
GROUP BY faculty_type;

-- A student row is a person, a major, a department, and an advisor
SELECT
  u.user_id AS student_id,
  CONCAT(u.first_name, ' ', u.last_name) AS student_name,
  m.major_name,
  d.dept_name,
  CONCAT(fu.first_name, ' ', fu.last_name) AS advisor
FROM students s
JOIN users u ON u.user_id = s.student_id
JOIN majors m ON m.major_id = s.major_id
JOIN departments d ON d.dept_id = m.dept_id
JOIN advisors a ON a.student_id = s.student_id
JOIN faculty f ON f.faculty_id = a.faculty_id
JOIN users fu ON fu.user_id = f.faculty_id
ORDER BY u.user_id
LIMIT 8;

-- One course, its prerequisites, and a section in Fall 2026
SELECT
  c.course_id,
  c.course_name,
  c.credits,
  GROUP_CONCAT(pr.prereq_course_id ORDER BY pr.prereq_course_id SEPARATOR ', ') AS prerequisites
FROM courses c
LEFT JOIN course_prereqs pr ON pr.course_id = c.course_id
WHERE c.course_id = 'BIO101'
GROUP BY c.course_id, c.course_name, c.credits;

SELECT
  s.section_id,
  c.course_id,
  c.course_name,
  t.code AS term,
  s.meeting_days,
  s.meeting_time,
  s.room,
  CONCAT(u.first_name, ' ', u.last_name) AS instructor
FROM sections s
JOIN courses c ON c.course_id = s.course_id
JOIN terms t ON t.term_id = s.term_id
LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
LEFT JOIN users u ON u.user_id = f.faculty_id
WHERE t.code = 'FA26' AND c.course_id = 'BIO101'
LIMIT 5;

-- Foreign keys, so the relationships are visible in MySQL
SELECT
  TABLE_NAME,
  COLUMN_NAME,
  REFERENCED_TABLE_NAME,
  REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, COLUMN_NAME;
