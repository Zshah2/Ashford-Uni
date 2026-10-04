-- Core schema for CollegeWeb (minimal-clean import + registration tables)

CREATE TABLE IF NOT EXISTS auth_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS users (
  user_id BIGINT UNSIGNED NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  apt_no VARCHAR(20) NULL,
  street VARCHAR(200) NULL,
  city VARCHAR(120) NULL,
  state VARCHAR(40) NULL,
  zip_code VARCHAR(20) NULL,
  gender VARCHAR(20) NULL,
  dob DATE NULL,
  user_type VARCHAR(30) NOT NULL,
  PRIMARY KEY (user_id),
  KEY idx_users_user_type (user_type),
  KEY idx_users_last_first (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS students (
  student_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_students_user FOREIGN KEY (student_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS faculty (
  faculty_id BIGINT UNSIGNED NOT NULL,
  office_number VARCHAR(50) NULL,
  `rank` VARCHAR(50) NULL,
  faculty_type VARCHAR(50) NULL,
  PRIMARY KEY (faculty_id),
  CONSTRAINT fk_faculty_user FOREIGN KEY (faculty_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS departments (
  dept_id VARCHAR(10) NOT NULL,
  dept_name VARCHAR(200) NOT NULL,
  room_number VARCHAR(50) NULL,
  building_number VARCHAR(50) NULL,
  chair_id BIGINT UNSIGNED NULL,
  email VARCHAR(200) NULL,
  phone_number VARCHAR(40) NULL,
  dept_assistant VARCHAR(200) NULL,
  PRIMARY KEY (dept_id),
  KEY idx_departments_chair_id (chair_id),
  CONSTRAINT fk_departments_chair FOREIGN KEY (chair_id) REFERENCES users(user_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS faculty_departments (
  faculty_id BIGINT UNSIGNED NOT NULL,
  dept_id VARCHAR(10) NOT NULL,
  percent_time INT NULL,
  date_of_appointment DATE NULL,
  PRIMARY KEY (faculty_id, dept_id),
  CONSTRAINT fk_fac_dept_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fac_dept_dept FOREIGN KEY (dept_id) REFERENCES departments(dept_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS student_departments (
  student_id BIGINT UNSIGNED NOT NULL,
  dept_id VARCHAR(10) NOT NULL,
  date_of_declaration DATE NULL,
  PRIMARY KEY (student_id, dept_id),
  CONSTRAINT fk_stu_dept_student FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_stu_dept_dept FOREIGN KEY (dept_id) REFERENCES departments(dept_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS undergrad_students (
  student_id BIGINT UNSIGNED NOT NULL,
  student_type VARCHAR(30) NOT NULL,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_ug_student FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ug_credit_limits (
  student_id BIGINT UNSIGNED NOT NULL,
  student_type VARCHAR(30) NOT NULL,
  year INT NOT NULL,
  max_credit INT NOT NULL,
  min_credit INT NOT NULL,
  total_credit_earned INT NOT NULL,
  PRIMARY KEY (student_id),
  CONSTRAINT fk_ug_credit_student FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS grad_student_programs (
  student_id BIGINT UNSIGNED NOT NULL,
  program_id BIGINT UNSIGNED NOT NULL,
  year INT NULL,
  thesis_year INT NULL,
  total_credit_earned INT NULL,
  PRIMARY KEY (student_id, program_id),
  CONSTRAINT fk_grad_prog_student FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Registration core
CREATE TABLE IF NOT EXISTS programs (
  program_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  level ENUM('UG','GRAD') NOT NULL DEFAULT 'UG',
  dept_id VARCHAR(10) NULL,
  PRIMARY KEY (program_id),
  KEY idx_programs_dept_id (dept_id),
  CONSTRAINT fk_programs_dept FOREIGN KEY (dept_id) REFERENCES departments(dept_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS courses (
  course_id VARCHAR(30) NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  credits INT NOT NULL,
  dept_id VARCHAR(10) NULL,
  PRIMARY KEY (course_id),
  KEY idx_courses_dept_id (dept_id),
  CONSTRAINT fk_courses_dept FOREIGN KEY (dept_id) REFERENCES departments(dept_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS terms (
  term_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(10) NOT NULL, -- e.g. fall2026, spring2027
  name VARCHAR(100) NOT NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  PRIMARY KEY (term_id),
  UNIQUE KEY uq_terms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sections (
  section_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id VARCHAR(30) NOT NULL,
  term_id BIGINT UNSIGNED NOT NULL,
  faculty_id BIGINT UNSIGNED NULL,
  meeting_days VARCHAR(20) NULL, -- e.g. MWF, TR
  meeting_time VARCHAR(40) NULL, -- e.g. 10:00-11:15
  room VARCHAR(50) NULL,
  capacity INT NOT NULL DEFAULT 30,
  PRIMARY KEY (section_id),
  KEY idx_sections_course_term (course_id, term_id),
  CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(course_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sections_term FOREIGN KEY (term_id) REFERENCES terms(term_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sections_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  enrollment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  status ENUM('enrolled','dropped','waitlisted') NOT NULL DEFAULT 'enrolled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (enrollment_id),
  UNIQUE KEY uq_enroll_student_section (student_id, section_id),
  KEY idx_enroll_student (student_id),
  KEY idx_enroll_section (section_id),
  CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_enroll_section FOREIGN KEY (section_id) REFERENCES sections(section_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

