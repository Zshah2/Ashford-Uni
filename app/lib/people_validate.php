<?php

declare(strict_types=1);

/**
 * Validation helpers for admin "Add person" (and related profile formats).
 */

function people_name_is_valid(string $name, bool $allowEmpty = false): bool
{
    $name = trim($name);
    if ($name === '') {
        return $allowEmpty;
    }
    if (strlen($name) > 50) {
        return false;
    }

    // Letters (unicode), spaces, hyphen, apostrophe only.
    return (bool)preg_match("/^[\p{L}][\p{L}\s'\-]{0,49}$/u", $name);
}

/** Student IDs: 1000000–1999999. Faculty IDs: 9000000–9999999. Always 7 digits. */
function people_id_is_valid_for_type(int $userId, string $personType): bool
{
    if ($userId < 1000000 || $userId > 9999999) {
        return false;
    }
    if ($personType === 'Student') {
        return $userId >= 1000000 && $userId <= 1999999;
    }
    if ($personType === 'Faculty') {
        return $userId >= 9000000 && $userId <= 9999999;
    }

    return false;
}

function people_suggest_next_id(PDO $pdo, string $personType): int
{
    if ($personType === 'Faculty') {
        $lo = 9000000;
        $hi = 9999999;
        $fallback = 9000000;
    } else {
        $lo = 1000000;
        $hi = 1999999;
        $fallback = 1000000;
    }
    try {
        $st = $pdo->prepare('SELECT COALESCE(MAX(user_id), ?) FROM users WHERE user_id BETWEEN ? AND ?');
        $st->execute([$lo - 1, $lo, $hi]);
        $max = (int)$st->fetchColumn();
        $next = max($lo, $max + 1);
        if ($next > $hi) {
            return $fallback;
        }

        return $next;
    } catch (Throwable) {
        return $fallback;
    }
}

/** DOB must parse and yield age 16–80 inclusive. */
function people_dob_is_valid(string $dobIn, ?string &$normalized = null): bool
{
    $normalized = null;
    $dobIn = trim($dobIn);
    if ($dobIn === '') {
        return false;
    }
    $ts = strtotime($dobIn);
    if ($ts === false) {
        return false;
    }
    $dob = date('Y-m-d', $ts);
    $age = (int)date_diff(date_create($dob), date_create('today'))->y;
    if ($age < 16 || $age > 80) {
        return false;
    }
    $normalized = $dob;

    return true;
}

/** Campus office like AB-2024, Lib-1106, AB-0021. */
function people_office_is_valid(string $office, bool $allowEmpty = true): bool
{
    $office = trim($office);
    if ($office === '') {
        return $allowEmpty;
    }
    if (strlen($office) > 50) {
        return false;
    }

    return (bool)preg_match('/^[A-Za-z][A-Za-z0-9]{0,9}-[A-Za-z0-9]{1,10}$/', $office);
}

function people_us_zip_is_valid(string $zip, bool $allowEmpty = true): bool
{
    $zip = trim($zip);
    if ($zip === '') {
        return $allowEmpty;
    }

    return (bool)preg_match('/^\d{5}(-\d{4})?$/', $zip);
}

/**
 * NY metro / Long Island–leaning area codes for campus contact numbers.
 *
 * @return list<string>
 */
function people_preferred_phone_area_codes(): array
{
    return [
        '212', '315', '347', '516', '518', '585', '607', '631', '646', '680',
        '716', '718', '838', '845', '914', '917', '929', '934',
        '201', '551', '609', '732', '848', '856', '862', '908', '973',
        '203', '475', '860', '959',
        '215', '267', '412', '484', '570', '610', '717', '724', '814', '878',
        '339', '351', '413', '508', '617', '774', '781', '857', '978',
    ];
}

function people_phone_area_code_allowed(string $normalizedPhone): bool
{
    if (!preg_match('/^\((\d{3})\)/', $normalizedPhone, $m)) {
        return false;
    }

    return in_array($m[1], people_preferred_phone_area_codes(), true);
}
