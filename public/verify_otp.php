<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/bootstrap.php';
bootstrap_app();
require __DIR__ . '/../app/lib/url.php';
require __DIR__ . '/../app/lib/db.php';
require __DIR__ . '/../app/lib/auth.php';
require __DIR__ . '/../app/lib/two_factor.php';
require __DIR__ . '/../app/lib/csrf.php';

header('Content-Type: text/html; charset=utf-8');

$dbOk = false;
try {
    db();
    $dbOk = true;
} catch (Throwable) {
}

auth_start_session();

if (!twofa_is_enabled()) {
    if (auth_is_portal_user()) {
        header('Location: ' . url('/admin.php?view=dashboard'));
        exit;
    }
    if (auth_has_pending_2fa()) {
        auth_clear_pending_2fa();
    }
    header('Location: ' . url('/login.php'));
    exit;
}

if ($dbOk && auth_is_portal_user()) {
    header('Location: ' . url('/admin.php?view=dashboard'));
    exit;
}

if (!auth_has_pending_2fa()) {
    header('Location: ' . url('/login.php'));
    exit;
}

$verifyError = null;
$resendSuccess = null;
$resendError = null;
$pendingEmail = (string)$_SESSION['pending_2fa_email'];
$maskedEmail = preg_replace('/(^.).*(@.*$)/', '$1***$2', $pendingEmail) ?: $pendingEmail;

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isPost && $dbOk) {
    try {
        csrf_require_valid();
        $action = (string)($_POST['action'] ?? 'verify');

        if ($action === 'resend') {
            $now = time();
            $after = (int)($_SESSION['twofa_resend_after'] ?? 0);
            if ($after > $now) {
                $resendError = 'Please wait ' . ($after - $now) . ' seconds before requesting another code.';
            } else {
                $pdo = db();
                [$sent, $mailErr] = twofa_issue_and_send($pdo, $pendingEmail);
                if ($sent) {
                    $_SESSION['twofa_resend_after'] = $now + 60;
                    $resendSuccess = 'A new verification code was sent.';
                } else {
                    $resendError = $mailErr ?? 'Could not send verification code.';
                }
            }
        } else {
            $code = (string)($_POST['code'] ?? '');
            $pdo = db();
            if (twofa_verify($pdo, $pendingEmail, $code) && auth_complete_pending_2fa()) {
                header('Location: ' . url('/admin.php?view=dashboard'));
                exit;
            }
            $verifyError = 'Invalid or expired code. Please try again.';
        }
    } catch (Throwable $e) {
        $verifyError = app_debug() ? $e->getMessage() : 'Something went wrong. Try again.';
    }
} elseif ($isPost && !$dbOk) {
    $verifyError = 'Database is not available.';
}

$csrf = csrf_token();
$pageTitle = 'Verify sign-in — Ashford College';
$devOtpPreview = null;
if (
    isset($_SESSION['dev_otp_preview'], $_SESSION['dev_otp_preview_for'])
    && is_string($_SESSION['dev_otp_preview'])
    && twofa_normalize_email((string)$_SESSION['dev_otp_preview_for']) === twofa_normalize_email($pendingEmail)
) {
    $devOtpPreview = $_SESSION['dev_otp_preview'];
}

$inputClass = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-400/50 focus:outline-none focus:ring-2 focus:ring-sky-400/20 dark:border-white/10 dark:bg-slate-950/50 dark:text-white dark:placeholder:text-slate-500';
$alertSuccessClass = 'mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100';
$alertErrorClass = 'mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100';
$alertWarnClass = 'mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <?php require __DIR__ . '/../app/views/partials/theme_init.php'; ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { fontFamily: { sans: ['DM Sans', 'system-ui', 'sans-serif'] } } } },
    };
  </script>
  <link rel="stylesheet" href="<?= htmlspecialchars(url('/assets/css/theme.css')) ?>" />
</head>
<body class="nb-staff min-h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
  <div class="pointer-events-none fixed inset-0" aria-hidden="true">
    <div class="absolute left-1/2 top-0 h-96 w-96 -translate-x-1/2 rounded-full bg-sky-400/25 blur-3xl dark:bg-sky-600/20"></div>
    <div class="absolute bottom-0 right-0 h-64 w-64 rounded-full bg-indigo-400/15 blur-3xl dark:bg-indigo-600/10"></div>
  </div>

  <header class="relative z-10 border-b border-slate-200 bg-white/80 backdrop-blur dark:border-white/10 dark:bg-slate-950/80">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
      <a href="<?= htmlspecialchars(url('/login.php')) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">← Back to sign in</a>
      <?php require __DIR__ . '/../app/views/partials/theme_toggle.php'; ?>
    </div>
  </header>

  <main class="relative z-10 mx-auto max-w-md px-4 py-10 sm:px-6">
    <?php if (!$dbOk): ?>
      <div class="<?= htmlspecialchars($alertWarnClass) ?>">
        <strong class="block font-semibold">Cannot connect to MySQL.</strong>
        <span class="mt-1 block text-amber-900/90 dark:text-amber-100/90"><?= htmlspecialchars(db_connection_help_message()) ?></span>
      </div>
    <?php endif; ?>

    <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-slate-900/90 dark:shadow-black/30">
      <div class="text-center">
        <?php
        $logoClass = 'mx-auto h-14 w-auto max-w-[15rem] object-contain';
        $logoAlt = 'Ashford College';
        require view_path('partials/brand_logo.php');
        ?>
        <h1 class="mt-4 text-2xl font-semibold text-slate-900 dark:text-white">Check your email</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
          We sent a 6-digit code to <span class="font-medium text-slate-700 dark:text-slate-300"><?= htmlspecialchars($maskedEmail) ?></span>.
        </p>
      </div>

      <?php if ($resendSuccess): ?>
        <div class="<?= htmlspecialchars($alertSuccessClass) ?>"><?= htmlspecialchars($resendSuccess) ?></div>
      <?php endif; ?>
      <?php if ($resendError): ?>
        <div class="<?= htmlspecialchars($alertErrorClass) ?>"><?= htmlspecialchars($resendError) ?></div>
      <?php endif; ?>
      <?php if ($verifyError): ?>
        <div class="<?= htmlspecialchars($alertErrorClass) ?>"><?= htmlspecialchars($verifyError) ?></div>
      <?php endif; ?>
      <?php if ($devOtpPreview !== null): ?>
        <div class="<?= htmlspecialchars($alertWarnClass) ?>">
          <strong class="block font-semibold">Local testing (no email sent)</strong>
          <span class="mt-1 block">Add <code class="text-xs">smtp_password</code> in <code class="text-xs">app/config/2fa_config.local.php</code> to receive codes in your inbox. For now, use:</span>
          <p class="mt-2 font-mono text-2xl font-bold tracking-[0.35em]"><?= htmlspecialchars($devOtpPreview) ?></p>
        </div>
      <?php endif; ?>

      <form class="mt-6 space-y-4" method="post" action="<?= htmlspecialchars(url('/verify_otp.php')) ?>" autocomplete="one-time-code">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
        <input type="hidden" name="action" value="verify" />
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="code">Verification code</label>
          <input
            id="code"
            name="code"
            type="text"
            inputmode="numeric"
            pattern="[0-9]{6}"
            maxlength="6"
            minlength="6"
            required
            autofocus
            class="<?= htmlspecialchars($inputClass) ?> text-center text-lg tracking-[0.35em] font-mono"
            placeholder="000000"
            <?= $dbOk ? '' : 'disabled' ?>
          />
        </div>
        <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-slate-950 shadow-sm hover:bg-sky-400 disabled:opacity-50 dark:shadow-sky-900/20" <?= $dbOk ? '' : 'disabled' ?>>Verify and continue</button>
      </form>

      <form class="mt-4 text-center" method="post" action="<?= htmlspecialchars(url('/verify_otp.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
        <input type="hidden" name="action" value="resend" />
        <button type="submit" class="text-sm font-semibold text-sky-700 hover:text-sky-600 dark:text-sky-300 dark:hover:text-sky-200" <?= $dbOk ? '' : 'disabled' ?>>Resend code</button>
      </form>
    </div>
  </main>
  <?php require __DIR__ . '/../app/views/partials/theme_boot.php'; ?>
</body>
</html>
