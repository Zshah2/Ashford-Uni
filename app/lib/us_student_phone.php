<?php

declare(strict_types=1);

/**
 * Assign a unique US-style NANP display number (area code + exchange + line) for students.
 * Uses real-looking area codes; avoids collisions with existing users.phone_number.
 */

/**
 * Normalize a US phone to display form "(XXX) XXX-XXXX".
 * Accepts digits with optional +1, spaces, dashes, or parentheses.
 * Returns null if not a valid 10-digit NANP number.
 */
function northbridge_normalize_us_phone(string $input): ?string
{
    $digits = preg_replace('/\D+/', '', trim($input));
    if ($digits === null || $digits === '') {
        return null;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10) {
        return null;
    }
    // NANP: area code and exchange cannot start with 0 or 1
    if ($digits[0] === '0' || $digits[0] === '1' || $digits[3] === '0' || $digits[3] === '1') {
        return null;
    }

    return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
}

/** True if input can be normalized to a valid US phone. */
function northbridge_us_phone_is_valid(string $input): bool
{
    return northbridge_normalize_us_phone($input) !== null;
}

function northbridge_allocate_us_student_phone(PDO $pdo, int $userId): string
{
    $areaCodes = ['201', '202', '212', '213', '214', '305', '310', '312', '404', '415', '503', '617', '702', '713', '718', '801', '817', '469', '972', '512', '206', '253', '425', '917', '646', '347', '929'];
    $stmt = $pdo->prepare('
      SELECT 1 FROM users
      WHERE user_id <> ? AND phone_number IS NOT NULL AND TRIM(phone_number) <> "" AND TRIM(phone_number) = ?
      LIMIT 1
    ');
    for ($i = 0; $i < 20000; $i++) {
        $ac = $areaCodes[($userId + $i) % count($areaCodes)];
        $prefix = 201 + (($userId * 17 + $i * 11) % 799);
        $line = ($userId * 13 + $i * 7) % 10000;
        $formatted = sprintf('(%s) %03d-%04d', $ac, $prefix, $line);
        $stmt->execute([$userId, $formatted]);
        if (!$stmt->fetch()) {
            return $formatted;
        }
    }

    return sprintf('(%s) %03d-%04d', $areaCodes[0], ($userId % 799) + 200, $userId % 10000);
}
