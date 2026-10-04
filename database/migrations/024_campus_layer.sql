-- Campus layer from existing room codes in storage/import.
-- Faculty offices: faculty.csv. Department offices: department.csv.
-- First load used hyphen room ids such as AB-2024. scripts/apply_midterm_schema.php rewrites them to a building code plus three digits, such as NAB102.
-- Lecture and lab subtype tables are ready; this data has offices only.
-- Class.time_slot_id is reserved for Thursday's time_slot entity (no FK yet).

CREATE TABLE IF NOT EXISTS buildings (
  building_id VARCHAR(10) NOT NULL,
  building_name VARCHAR(200) NOT NULL,
  building_use VARCHAR(80) NOT NULL,
  PRIMARY KEY (building_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS rooms (
  room_id VARCHAR(20) NOT NULL,
  building_id VARCHAR(10) NOT NULL,
  room_number VARCHAR(20) NOT NULL,
  room_type ENUM('lecture', 'lab', 'office') NOT NULL,
  PRIMARY KEY (room_id),
  UNIQUE KEY uq_rooms_building_number (building_id, room_number),
  CONSTRAINT fk_rooms_building FOREIGN KEY (building_id) REFERENCES buildings(building_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lecture_rooms (
  room_id VARCHAR(20) NOT NULL,
  seats_available INT NOT NULL,
  PRIMARY KEY (room_id),
  CONSTRAINT fk_lecture_room FOREIGN KEY (room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lab_rooms (
  room_id VARCHAR(20) NOT NULL,
  workstations INT NOT NULL,
  PRIMARY KEY (room_id),
  CONSTRAINT fk_lab_room FOREIGN KEY (room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS office_rooms (
  room_id VARCHAR(20) NOT NULL,
  desk_count INT NOT NULL,
  PRIMARY KEY (room_id),
  CONSTRAINT fk_office_room FOREIGN KEY (room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS semesters (
  semester_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  semester_name VARCHAR(40) NOT NULL,
  semester_year SMALLINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  PRIMARY KEY (semester_id),
  UNIQUE KEY uq_semester_name_year (semester_name, semester_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS classes (
  crn BIGINT UNSIGNED NOT NULL,
  course_id VARCHAR(30) NOT NULL,
  section_number VARCHAR(10) NOT NULL,
  faculty_id BIGINT UNSIGNED NOT NULL,
  time_slot_id BIGINT UNSIGNED NULL,
  lecture_id VARCHAR(20) NOT NULL,
  semester_id BIGINT UNSIGNED NOT NULL,
  available_seats INT NOT NULL,
  PRIMARY KEY (crn),
  UNIQUE KEY uq_class_course_section_semester (course_id, section_number, semester_id),
  KEY idx_classes_faculty (faculty_id),
  KEY idx_classes_lecture (lecture_id),
  KEY idx_classes_semester (semester_id),
  CONSTRAINT fk_classes_course FOREIGN KEY (course_id) REFERENCES courses(course_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_classes_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_classes_lecture FOREIGN KEY (lecture_id) REFERENCES lecture_rooms(room_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_classes_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS holds (
  hold_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hold_type VARCHAR(80) NOT NULL,
  PRIMARY KEY (hold_id),
  UNIQUE KEY uq_holds_type (hold_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS admins (
  admin_id BIGINT UNSIGNED NOT NULL,
  security_level ENUM('view_only', 'limited', 'update_enabled') NOT NULL,
  PRIMARY KEY (admin_id),
  CONSTRAINT fk_admins_auth FOREIGN KEY (admin_id) REFERENCES auth_users(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO buildings (building_id, building_name, building_use) VALUES
  ('AB', 'Academic Building', 'academic'),
  ('Lib', 'Library', 'library'),
  ('Admin', 'Administration', 'administration'),
  ('Gym', 'Gymnasium', 'athletics'),
  ('SSC', 'Student Services Center', 'student services');

INSERT IGNORE INTO rooms (room_id, building_id, room_number, room_type) VALUES
  ('AB-0021', 'AB', '0021', 'office'),
  ('AB-0023', 'AB', '0023', 'office'),
  ('AB-1024', 'AB', '1024', 'office'),
  ('AB-2002', 'AB', '2002', 'office'),
  ('AB-2003', 'AB', '2003', 'office'),
  ('AB-2006', 'AB', '2006', 'office'),
  ('AB-2009', 'AB', '2009', 'office'),
  ('AB-2012', 'AB', '2012', 'office'),
  ('AB-2013', 'AB', '2013', 'office'),
  ('AB-2016', 'AB', '2016', 'office'),
  ('AB-2020', 'AB', '2020', 'office'),
  ('AB-2021', 'AB', '2021', 'office'),
  ('AB-2022', 'AB', '2022', 'office'),
  ('AB-2024', 'AB', '2024', 'office'),
  ('AB-2025', 'AB', '2025', 'office'),
  ('AB-3002', 'AB', '3002', 'office'),
  ('AB-3003', 'AB', '3003', 'office'),
  ('AB-3004', 'AB', '3004', 'office'),
  ('AB-3005', 'AB', '3005', 'office'),
  ('AB-3007', 'AB', '3007', 'office'),
  ('AB-3011', 'AB', '3011', 'office'),
  ('AB-3022', 'AB', '3022', 'office'),
  ('AB-3024', 'AB', '3024', 'office'),
  ('AB-3026', 'AB', '3026', 'office'),
  ('AB-3030', 'AB', '3030', 'office'),
  ('AB-3032', 'AB', '3032', 'office'),
  ('AB-3033', 'AB', '3033', 'office'),
  ('AB-3039', 'AB', '3039', 'office'),
  ('AB-3040', 'AB', '3040', 'office'),
  ('AB-3041', 'AB', '3041', 'office'),
  ('AB-3050', 'AB', '3050', 'office'),
  ('Admin-2002', 'Admin', '2002', 'office'),
  ('Gym-1017', 'Gym', '1017', 'office'),
  ('Lib-1106', 'Lib', '1106', 'office'),
  ('Lib-1107', 'Lib', '1107', 'office'),
  ('Lib-2107', 'Lib', '2107', 'office'),
  ('Lib-2108', 'Lib', '2108', 'office'),
  ('SSC-201', 'SSC', '201', 'office'),
  ('SSC-204', 'SSC', '204', 'office');

INSERT IGNORE INTO office_rooms (room_id, desk_count) VALUES
  ('AB-0021', 1),
  ('AB-0023', 4),
  ('AB-1024', 4),
  ('AB-2002', 4),
  ('AB-2003', 4),
  ('AB-2006', 4),
  ('AB-2009', 4),
  ('AB-2012', 1),
  ('AB-2013', 4),
  ('AB-2016', 4),
  ('AB-2020', 4),
  ('AB-2021', 4),
  ('AB-2022', 1),
  ('AB-2024', 1),
  ('AB-2025', 4),
  ('AB-3002', 4),
  ('AB-3003', 4),
  ('AB-3004', 4),
  ('AB-3005', 4),
  ('AB-3007', 4),
  ('AB-3011', 4),
  ('AB-3022', 4),
  ('AB-3024', 4),
  ('AB-3026', 2),
  ('AB-3030', 4),
  ('AB-3032', 4),
  ('AB-3033', 4),
  ('AB-3039', 4),
  ('AB-3040', 4),
  ('AB-3041', 4),
  ('AB-3050', 4),
  ('Admin-2002', 4),
  ('Gym-1017', 4),
  ('Lib-1106', 1),
  ('Lib-1107', 1),
  ('Lib-2107', 4),
  ('Lib-2108', 4),
  ('SSC-201', 4),
  ('SSC-204', 4);

INSERT IGNORE INTO semesters (semester_name, semester_year, start_date, end_date) VALUES
  ('Fall', 2026, '2026-08-20', '2026-12-15'),
  ('Spring', 2027, '2027-01-11', '2027-05-08'),
  ('Fall', 2027, '2027-08-23', '2027-12-16');

INSERT IGNORE INTO holds (hold_type) VALUES
  ('Bursar'),
  ('Academic'),
  ('Registration'),
  ('Immunization'),
  ('Financial aid'),
  ('Disciplinary'),
  ('Other');
