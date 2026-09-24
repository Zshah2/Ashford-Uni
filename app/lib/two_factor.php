<?php

declare(strict_types=1);

require_once __DIR__ . '/two_factor_config.php';
require_once __DIR__ . '/auth.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

function twofa_load_composer(): bool
{
    static $loaded = false;
    if ($loaded) {
        return class_exists(PHPMailer::class);
    }
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        $loaded = true;
    }

    return class_exists(PHPMailer::class);
}

function twofa_is_enabled(): bool
{
    $cfg = twofa_config();

    return !empty($cfg['portal_2fa_enabled']);
}

function twofa_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function twofa_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function twofa_invalidate_pending(PDO $pdo, string $email): void
{
    $id = twofa_normalize_email($email);
    $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE identifier = ? AND used = 0')->execute([$id]);
}

function twofa_store_code(PDO $pdo, string $email, string $code): void
{
    $cfg = twofa_config();
    $minutes = max(1, (int)($cfg['otp_expiry_minutes'] ?? 5));
    $id = twofa_normalize_email($email);
    $pdo->prepare('
      INSERT INTO otp_codes (identifier, code, expires_at, used)
      VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0)
    ')->execute([$id, $code, $minutes]);
}

function twofa_dev_log_enabled(array $cfg): bool
{
    return !empty($cfg['dev_log_otp']);
}

function twofa_dev_log_code(string $email, string $code): void
{
    $dir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . " {$email} code={$code}\n";
    @file_put_contents($dir . '/otp.log', $line, FILE_APPEND | LOCK_EX);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['dev_otp_preview'] = $code;
    $_SESSION['dev_otp_preview_for'] = twofa_normalize_email($email);
}

/**
 * @return array{0: bool, 1: ?string}
 */
function twofa_send_email(string $email, string $code, string $recipientName = ''): array
{
    if (!twofa_load_composer()) {
        return [false, 'Email is not configured. Run: composer install'];
    }

    $cfg = twofa_config();
    $host = trim((string)($cfg['smtp_host'] ?? ''));
    $smtpUser = trim((string)($cfg['smtp_username'] ?? ''));
    $smtpPass = (string)($cfg['smtp_password'] ?? '');
    if ($host === '' || ($smtpUser !== '' && $smtpPass === '')) {
        if (twofa_dev_log_enabled($cfg)) {
            twofa_dev_log_code($email, $code);

            return [true, null];
        }

        if ($host === '') {
            return [false, 'Set smtp_host in app/config/2fa_config.php (e.g. smtp.office365.com).'];
        }

        return [false, 'Set smtp_password in app/config/2fa_config.php (or SMTP_PASSWORD in the environment).'];
    }

    $minutes = max(1, (int)($cfg['otp_expiry_minutes'] ?? 5));
    $fromEmail = trim((string)($cfg['from_email'] ?? ''));
    $fromName = trim((string)($cfg['from_name'] ?? 'Ashford College'));
    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid from_email in 2fa_config.php'];
    }

    $greeting = $recipientName !== '' ? 'Hi ' . $recipientName . ',' : 'Hello,';
    $plain = "{$greeting}\n\nYour Ashford College sign-in code is:\n\n{$code}\n\nThis code expires in {$minutes} minutes.\n\nIf you did not request this, you can ignore this email.";
    $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'there', ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:system-ui,sans-serif;max-width:32rem;color:#0f172a">'
        . '<p style="margin:0 0 1rem">Hi ' . $safeName . ',</p>'
        . '<p style="margin:0 0 1rem">Your sign-in verification code for <strong>Ashford College</strong> is:</p>'
        . '<p style="margin:0 0 1.25rem;font-size:2rem;font-weight:700;letter-spacing:0.35em">' . $safeCode . '</p>'
        . '<p style="margin:0;color:#64748b;font-size:0.875rem">Expires in ' . (int)$minutes . ' minutes. If you did not request this, ignore this email.</p>'
        . '</div>';

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int)($cfg['smtp_port'] ?? 587);
        $enc = strtolower(trim((string)($cfg['smtp_encryption'] ?? 'tls')));
        if ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        if ($smtpUser !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);
        $mail->Subject = 'Your Ashford College sign-in code';
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $plain;
        $mail->send();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        unset($_SESSION['dev_otp_preview'], $_SESSION['dev_otp_preview_for']);

        return [true, null];
    } catch (MailException $e) {
        $msg = app_debug() ? $e->getMessage() : 'Could not send verification email.';

        return [false, $msg];
    } catch (Throwable $e) {
        $msg = app_debug() ? $e->getMessage() : 'Could not send verification email.';

        return [false, $msg];
    }
}

/**
 * @return array{0: bool, 1: ?string}
 */
function twofa_issue_and_send(PDO $pdo, string $email): array
{
    $email = twofa_normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid email address.'];
    }

    twofa_invalidate_pending($pdo, $email);
    $code = twofa_generate_code();
    twofa_store_code($pdo, $email, $code);

    $recipientName = '';
    $authRow = auth_fetch_user_by_email($email);
    if ($authRow !== null) {
        $recipientName = auth_resolve_display_name($authRow);
    }

    return twofa_send_email($email, $code, $recipientName);
}

function twofa_verify(PDO $pdo, string $email, string $code): bool
{
    $email = twofa_normalize_email($email);
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }

    $stmt = $pdo->prepare('
      SELECT id FROM otp_codes
      WHERE identifier = ? AND code = ? AND used = 0 AND expires_at > NOW()
      ORDER BY id DESC
      LIMIT 1
    ');
    $stmt->execute([$email, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $pdo->prepare('UPDATE otp_codes SET used = 1 WHERE id = ?')->execute([(int)$row['id']]);

    return true;
}
