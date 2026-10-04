-- Winter 2027 session, the week before Spring 2027.
-- Semester id is the mnemonic winter2027.

UPDATE terms
SET name = 'Winter 2027', start_date = '2027-01-04', end_date = '2027-01-11'
WHERE code IN ('WI26', 'WI27', 'winter2027');

UPDATE terms
SET code = 'winter2027'
WHERE code IN ('WI26', 'WI27');

INSERT INTO terms (code, name, start_date, end_date, registration_open)
SELECT 'winter2027', 'Winter 2027', '2027-01-04', '2027-01-11', 1
WHERE NOT EXISTS (SELECT 1 FROM terms WHERE code IN ('winter2027', 'WI27'));
