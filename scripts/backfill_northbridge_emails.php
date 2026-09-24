<?php

declare(strict_types=1);

/**
 * Fill missing users.email (students) and faculty.email (faculty) using
 * first initial + last name @ashford.edu (unique across both tables).
 *
 * Usage: php scripts/backfill_northbridge_emails.php
 */

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/../app/lib/northbridge_email.php';

$pdo = db();

$uUp = $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?');
$stuIds = $pdo->query('
  SELECT u.user_id, u.first_name, u.last_name
  FROM users u
  INNER JOIN students s ON s.student_id = u.user_id
  WHERE u.email IS NULL OR TRIM(u.email) = ""
')->fetchAll(PDO::FETCH_ASSOC);

$n = 0;
foreach ($stuIds as $row) {
    $uid = (int)$row['user_id'];
    $email = northbridge_allocate_school_email($pdo, (string)$row['first_name'], (string)$row['last_name'], $uid);
    $uUp->execute([$email, $uid]);
    $n++;
}
fwrite(STDOUT, "Updated users.email for {$n} students.\n");

$fUp = $pdo->prepare('UPDATE faculty SET email = ? WHERE faculty_id = ?');
$facRows = $pdo->query('
  SELECT f.faculty_id, u.first_name, u.last_name
  FROM faculty f
  INNER JOIN users u ON u.user_id = f.faculty_id
  WHERE f.email IS NULL OR TRIM(f.email) = ""
     OR LOWER(TRIM(f.email)) REGEXP "^faculty[0-9]+@"
')->fetchAll(PDO::FETCH_ASSOC);

$m = 0;
foreach ($facRows as $row) {
    $fid = (int)$row['faculty_id'];
    $email = northbridge_allocate_school_email($pdo, (string)$row['first_name'], (string)$row['last_name'], $fid);
    $fUp->execute([$email, $fid]);
    $pdo->prepare('
      UPDATE users SET email = ?
      WHERE user_id = ? AND (
        email IS NULL OR TRIM(email) = ""
        OR LOWER(TRIM(email)) REGEXP "^faculty[0-9]+@"
      )
    ')->execute([$email, $fid]);
    $m++;
}
fwrite(STDOUT, "Updated faculty.email for {$m} faculty (and users.email when blank).\n");
fwrite(STDOUT, "Done.\n");
