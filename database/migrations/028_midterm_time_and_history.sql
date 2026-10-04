-- Midterm relations: time slot (many-to-many with day and period) and class history.
-- Semester ids are mnemonic names such as fall2026. Room ids are a building code plus three digits.

CREATE TABLE IF NOT EXISTS `day` (
  day_id CHAR(1) NOT NULL,
  weekday VARCHAR(12) NOT NULL,
  PRIMARY KEY (day_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS period (
  period_id VARCHAR(20) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  PRIMARY KEY (period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS time_slot (
  time_slot_id VARCHAR(40) NOT NULL,
  PRIMARY KEY (time_slot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS time_slot_day (
  time_slot_id VARCHAR(40) NOT NULL,
  day_id CHAR(1) NOT NULL,
  PRIMARY KEY (time_slot_id, day_id),
  CONSTRAINT fk_tsd_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(time_slot_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tsd_day FOREIGN KEY (day_id) REFERENCES `day`(day_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS time_slot_period (
  time_slot_id VARCHAR(40) NOT NULL,
  period_id VARCHAR(20) NOT NULL,
  PRIMARY KEY (time_slot_id, period_id),
  CONSTRAINT fk_tsp_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(time_slot_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tsp_period FOREIGN KEY (period_id) REFERENCES period(period_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS grade_letters (
  grade VARCHAR(5) NOT NULL,
  PRIMARY KEY (grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
