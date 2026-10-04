-- Prefilled course catalog + offerings so Admin → Courses / course detail reads naturally.
-- Prerequisites: migrations applied (through 018+) and core CSV import so users, departments,
-- students, and faculty exist (php scripts/import_all.php).
--
-- Apply with MySQL client:
--   mysql -u USER -p DATABASE < database/seeds/prefill_course_demo.sql
--
-- Or use the PHP seed (recommended; resolves faculty/student IDs safely):
--   php scripts/seed_demo_registration.php

SET NAMES utf8mb4;

INSERT INTO terms (code, name, start_date, end_date)
VALUES
  ('fall2026', 'Fall 2026', '2026-08-20', '2026-12-15'),
  ('spring2027', 'Spring 2027', '2027-01-11', '2027-05-08'),
  ('fall2027', 'Fall 2027', '2027-08-23', '2027-12-16')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  start_date = VALUES(start_date),
  end_date = VALUES(end_date);

-- Catalog rows (physical column order after migrations: … description after course_name, is_active after dept_id).
-- dept_id NULL where your import may not include that department code (avoids FK errors).
INSERT INTO courses (course_id, course_name, description, credits, dept_id, is_active) VALUES
('ENG101', 'English Composition I',
 'College reading, drafting, and revision. Argumentative and analytical essays, research basics, and MLA documentation. Required for most majors.',
 4, 'ENGL', 1),
('ENG102', 'English Composition II',
 'Advanced composition emphasizing synthesis across sources, rhetoric, and revision. Builds directly on ENG101.',
 4, 'ENGL', 1),
('HIS103', 'History of Ideas',
 'Major themes in intellectual history from antiquity through the early modern period. Primary texts and discussion.',
 4, 'HIS', 1),
('CS101', 'Introduction to Computer Science',
 'Problem solving, algorithms, and programming fundamentals using a high-level language. Lab projects and pair exercises.',
 4, NULL, 1),
('CS201', 'Data Structures',
 'Abstract data types, lists, trees, hashing, graphs, and algorithmic complexity. Programming-intensive.',
 4, NULL, 1),
('MATH150', 'Calculus I',
 'Limits, derivatives, and integrals of algebraic and transcendental functions with applications.',
 4, NULL, 1),
('BIO101', 'General Biology I',
 'Cell structure, genetics, evolution, and ecology. Weekly lab experiments and scientific writing. Satisfies lab science requirements for many programs.',
 4, 'BIO', 1),
('BIO0098', 'Introduction to Biological Inquiry',
 'Laboratory and lecture introduction to scientific reasoning in biology: hypothesis design, data literacy, microscopy, statistics basics, and evolution as a unifying theme. Prepares majors and pre-health students for upper-level work.',
 3, 'BIO', 1),
('CHE0105', 'General Chemistry I',
 'Atomic structure, stoichiometry, gases, thermochemistry, and chemical bonding. Lecture and problem-solving studio; foundation for organic chemistry and laboratory sciences.',
 4, 'CHE', 1),
('BI0101', 'Biology Foundations',
 'Molecular and cellular foundations of life: macromolecules, metabolism, cell division, genetics, and gene expression. Laboratories cover microscopy, biochemical assays, and experimental design. For continuing students in biology and allied health after completing gateway prerequisites.',
 3, 'BIO', 1)
ON DUPLICATE KEY UPDATE
  course_name = VALUES(course_name),
  credits = VALUES(credits),
  dept_id = VALUES(dept_id),
  description = VALUES(description),
  is_active = VALUES(is_active);

-- Prerequisites: BI0101 requires BIO0098, CHE0105, and BIO101 (3 prereqs — useful for catalog UI demo).
INSERT IGNORE INTO course_prereqs (course_id, prereq_course_id) VALUES
('ENG102', 'ENG101'),
('CS201', 'CS101'),
('BI0101', 'BIO0098'),
('BI0101', 'CHE0105'),
('BI0101', 'BIO101');

-- Fall 2026 sections (skip if this course+term already has a row).
INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'ENG101', t.term_id, f.faculty_id, 'MWF', '09:00-09:50', 'ENG-201', 32
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'ENG101' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'ENG102', t.term_id, f.faculty_id, 'TR', '09:30-10:45', 'ENG-204', 28
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'ENG102' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'HIS103', t.term_id, f.faculty_id, 'TR', '13:00-14:15', 'LIB-1107', 36
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'HIS103' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'CS101', t.term_id, f.faculty_id, 'MWF', '11:00-11:50', 'SCI-105', 40
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'CS101' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'CS201', t.term_id, f.faculty_id, 'MWF', '13:00-13:50', 'SCI-210', 30
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'CS201' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'MATH150', t.term_id, f.faculty_id, 'TR', '10:00-11:15', 'MATH-140', 45
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'MATH150' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'BIO101', t.term_id, f.faculty_id, 'MW', '14:00-15:40', 'LAB-3B', 24
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2026'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'BIO101' AND s.term_id = t.term_id
  );

-- Spring 2027 sample offerings (second term on course detail dropdown).
INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'ENG101', t.term_id, f.faculty_id, 'MWF', '10:00-10:50', 'ENG-201', 32
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'spring2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'ENG101' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'HIS103', t.term_id, f.faculty_id, 'TR', '11:00-12:15', 'LIB-1107', 36
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'spring2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'HIS103' AND s.term_id = t.term_id
  );

-- Fall 2027 showcase (multiple BI0101 sections + gateway courses).
INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'BI0101', t.term_id, f.faculty_id, 'T', '14:30-15:45', 'BIO-100', 25
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.term_id = t.term_id AND s.course_id = 'BI0101'
    AND s.meeting_days = 'T' AND s.meeting_time = '14:30-15:45' AND s.room = 'BIO-100'
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'BI0101', t.term_id, f.faculty_id, 'MWF', '09:00-09:50', 'BIO-101', 25
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.term_id = t.term_id AND s.course_id = 'BI0101'
    AND s.meeting_days = 'MWF' AND s.meeting_time = '09:00-09:50' AND s.room = 'BIO-101'
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'BI0101', t.term_id, f.faculty_id, 'TR', '10:00-10:50', 'BIO-102', 25
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.term_id = t.term_id AND s.course_id = 'BI0101'
    AND s.meeting_days = 'TR' AND s.meeting_time = '10:00-10:50' AND s.room = 'BIO-102'
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'BIO0098', t.term_id, f.faculty_id, 'MW', '11:00-12:15', 'BIO-090', 28
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'BIO0098' AND s.term_id = t.term_id
  );

INSERT INTO sections (course_id, term_id, faculty_id, meeting_days, meeting_time, room, capacity)
SELECT 'CHE0105', t.term_id, f.faculty_id, 'MWF', '13:00-13:50', 'CHE-110', 40
FROM terms t
CROSS JOIN (SELECT faculty_id FROM faculty ORDER BY faculty_id LIMIT 1) f
WHERE t.code = 'fall2027'
  AND NOT EXISTS (
    SELECT 1 FROM sections s WHERE s.course_id = 'CHE0105' AND s.term_id = t.term_id
  );

-- Light enrollments: first several students into fall2026 ENG101 + HIS103 + CS101 sections.
INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'enrolled'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 6) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2026'
WHERE s.course_id = 'ENG101';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'enrolled'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 4 OFFSET 2) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2026'
WHERE s.course_id = 'HIS103';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'enrolled'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 5 OFFSET 1) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2026'
WHERE s.course_id = 'CS101';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'waitlisted'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 2 OFFSET 8) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2026'
WHERE s.course_id = 'CS101';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'enrolled'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 6) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2027'
WHERE s.course_id = 'BI0101' AND s.room = 'BIO-100';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'enrolled'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 6 OFFSET 4) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2027'
WHERE s.course_id = 'BI0101' AND s.room = 'BIO-101';

INSERT IGNORE INTO enrollments (student_id, section_id, status)
SELECT st.student_id, s.section_id, 'waitlisted'
FROM (SELECT student_id FROM students ORDER BY student_id LIMIT 2 OFFSET 14) st
CROSS JOIN sections s
JOIN terms t ON t.term_id = s.term_id AND t.code = 'fall2027'
WHERE s.course_id = 'BI0101' AND s.room = 'BIO-100';
