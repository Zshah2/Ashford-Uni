-- Point each existing department at the office already stored in department.csv.

ALTER TABLE departments
  ADD COLUMN building_room_id VARCHAR(20) NULL AFTER building_number,
  ADD KEY idx_departments_room (building_room_id),
  ADD CONSTRAINT fk_departments_room FOREIGN KEY (building_room_id) REFERENCES rooms(room_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

UPDATE departments
SET building_room_id = room_number
WHERE room_number IS NOT NULL
  AND room_number <> ''
  AND building_room_id IS NULL
  AND room_number IN (SELECT room_id FROM rooms);
