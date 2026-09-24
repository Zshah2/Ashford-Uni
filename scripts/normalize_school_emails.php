<?php

declare(strict_types=1);

/**
 * Normalize Ashford school emails for BOTH students and faculty.
 *
 * Fixes cases like:
 * - aalexander900596@ashford.edu
 * - faculty902522@ashford.edu
 * - any @ashford.edu email whose local-part ends with 2+ digits
 *
 * Desired output:
 * - firstInitial + lastName + optional single digit (1–9, then 0) @ashford.edu
 *   e.g. zshah@ashford.edu, zshah2@ashford.edu
 *
 * Usage:
 *   php scripts/normalize_school_emails.php
 */

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/db.php';
require_once __DIR__ . '/../app/lib/northbridge_email.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function needs_normalize(string $email): bool
{
    $e = strtolower(trim($email));
    if ($e === '') return false;
    if (!str_ends_with($e, '@' . northbridge_email_domain())) return false;

    [$local] = explode('@', $e, 2);
    $local = trim($local);
    if ($local === '') return true;

    // bad: faculty12345...
    if (preg_match('/^faculty\d+$/', $local)) return true;

    // bad: ends with 2+ digits (e.g. aalexander900596)
    if (preg_match('/\d{2,}$/', $local)) return true;

    return false;
}

$uUpd = $pdo->prepare('UPDATE users SET email = ? WHERE user_id = ?');
$fUpd = $pdo->prepare('UPDATE faculty SET email = ? WHERE faculty_id = ?');

$users = $pdo->query('SELECT user_id, first_name, last_name, email FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$fixedUsers = 0;
foreach ($users as $u) {
    $uid = (int)($u['user_id'] ?? 0);
    if ($uid <= 0) continue;
    $email = (string)($u['email'] ?? '');
    if (!needs_normalize($email)) continue;

    $new = northbridge_allocate_school_email($pdo, (string)($u['first_name'] ?? ''), (string)($u['last_name'] ?? ''), $uid);
    $uUpd->execute([$new, $uid]);
    $fixedUsers++;
}

$faculty = $pdo->query('
  SELECT f.faculty_id, u.first_name, u.last_name, f.email
  FROM faculty f
  INNER JOIN users u ON u.user_id = f.faculty_id
')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$fixedFaculty = 0;
foreach ($faculty as $f) {
    $fid = (int)($f['faculty_id'] ?? 0);
    if ($fid <= 0) continue;
    $email = (string)($f['email'] ?? '');
    if (!needs_normalize($email)) continue;

    $new = northbridge_allocate_school_email($pdo, (string)($f['first_name'] ?? ''), (string)($f['last_name'] ?? ''), $fid);
    $fUpd->execute([$new, $fid]);
    // keep users.email in sync when it's also a bad school email or blank
    $pdo->prepare('
      UPDATE users SET email = ?
      WHERE user_id = ? AND (
        email IS NULL OR TRIM(email) = ""
        OR LOWER(TRIM(email)) REGEXP ?
        OR LOWER(TRIM(email)) REGEXP ?
      )
    ')->execute([$new, $fid, '^faculty[0-9]+@', '\\\\d{2,}@$']);
    $fixedFaculty++;
}

fwrite(STDOUT, "Normalized users emails: {$fixedUsers}\n");
fwrite(STDOUT, "Normalized faculty emails: {$fixedFaculty}\n");
fwrite(STDOUT, "Done.\n");

