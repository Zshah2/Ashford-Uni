-- Majors, minors, advisors, degree requirements, academic calendar, and the stat role.

CREATE TABLE IF NOT EXISTS majors (
  major_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  major_name VARCHAR(200) NOT NULL,
  dept_id VARCHAR(10) NOT NULL,
  PRIMARY KEY (major_id),
  UNIQUE KEY uq_majors_name (major_name),
  KEY idx_majors_dept (dept_id),
  CONSTRAINT fk_majors_dept FOREIGN KEY (dept_id) REFERENCES departments (dept_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS minors (
  minor_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  minor_name VARCHAR(200) NOT NULL,
  dept_id VARCHAR(10) NOT NULL,
  PRIMARY KEY (minor_id),
  UNIQUE KEY uq_minors_name (minor_name),
  KEY idx_minors_dept (dept_id),
  CONSTRAINT fk_minors_dept FOREIGN KEY (dept_id) REFERENCES departments (dept_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE students
  ADD COLUMN major_id BIGINT UNSIGNED NULL AFTER student_id,
  ADD COLUMN minor_id BIGINT UNSIGNED NULL AFTER major_id,
  ADD KEY idx_students_major (major_id),
  ADD KEY idx_students_minor (minor_id),
  ADD CONSTRAINT fk_students_major FOREIGN KEY (major_id) REFERENCES majors (major_id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  ADD CONSTRAINT fk_students_minor FOREIGN KEY (minor_id) REFERENCES minors (minor_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS advisors (
  faculty_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (faculty_id, student_id),
  KEY idx_advisors_student (student_id),
  CONSTRAINT fk_advisors_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (faculty_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_advisors_student FOREIGN KEY (student_id) REFERENCES students (student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE faculty
  ADD COLUMN dept_id VARCHAR(10) NULL AFTER faculty_type,
  ADD KEY idx_faculty_dept (dept_id),
  ADD CONSTRAINT fk_faculty_primary_dept FOREIGN KEY (dept_id) REFERENCES departments (dept_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS degree_requirements (
  requirement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  major_id BIGINT UNSIGNED NULL,
  minor_id BIGINT UNSIGNED NULL,
  course_id VARCHAR(30) NOT NULL,
  PRIMARY KEY (requirement_id),
  UNIQUE KEY uq_degree_major_course (major_id, course_id),
  KEY idx_degree_minor (minor_id),
  CONSTRAINT fk_degree_major FOREIGN KEY (major_id) REFERENCES majors (major_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_degree_minor FOREIGN KEY (minor_id) REFERENCES minors (minor_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_degree_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS academic_calendar (
  calendar_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  term_id BIGINT UNSIGNED NULL,
  event_name VARCHAR(200) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  notes VARCHAR(400) NULL,
  PRIMARY KEY (calendar_id),
  KEY idx_calendar_start (start_date),
  CONSTRAINT fk_calendar_term FOREIGN KEY (term_id) REFERENCES terms (term_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE auth_users
  MODIFY COLUMN role ENUM('admin','limited','viewer','stat') NOT NULL DEFAULT 'admin',
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER role;
