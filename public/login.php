<?php

declare(strict_types=1);

require __DIR__ . '/../app/lib/view.php';
require __DIR__ . '/../app/lib/bootstrap.php';
bootstrap_app();
require __DIR__ . '/../app/lib/url.php';
require __DIR__ . '/../app/lib/db.php';
require __DIR__ . '/../app/lib/auth.php';
require __DIR__ . '/../app/lib/csrf.php';

header('Content-Type: text/html; charset=utf-8');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$dbOk = false;
try {
    db();
    $dbOk = true;
} catch (Throwable) {
}

auth_start_session();

if ($dbOk && auth_is_portal_user() && !$isPost) {
    header('Location: ' . url('/admin.php?view=dashboard'));
    exit;
}

$loginError = null;
$registerError = null;
$registered = isset($_GET['registered']) && (string)$_GET['registered'] !== '0';
$lastIntent = '';

if ($isPost && $dbOk) {
    try {
        csrf_require_valid();
        $intent = (string)($_POST['intent'] ?? '');
        $lastIntent = $intent;
        if ($intent === 'login') {
            $email = trim((string)($_POST['email'] ?? $_POST['username'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            if ($email === '' || $p === '') {
                $loginError = 'Enter email and password.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $loginError = 'Enter a valid email address.';
            } elseif (($row = auth_verify_portal_credentials($email, $p)) !== null) {
                auth_establish_portal_session($row);
                header('Location: ' . url('/admin.php?view=dashboard'));
                exit;
            } else {
                $loginError = 'Invalid email or password.';
            }
        } elseif ($intent === 'register') {
            $email = trim((string)($_POST['email'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            $c = (string)($_POST['confirm_password'] ?? '');
            if ($p !== $c) {
                $registerError = 'Passwords do not match.';
            } else {
                [$ok, $err] = auth_create_admin($email, $p);
                if ($ok) {
                    header('Location: ' . url('/login.php?registered=1'));
                    exit;
                }
                $registerError = $err ?: 'Registration failed.';
            }
        } else {
            $loginError = 'Invalid form submission.';
        }
    } catch (Throwable $e) {
        $msg = app_debug() ? $e->getMessage() : 'Database error. Run php scripts/migrate.php.';
        if ($lastIntent === 'register') {
            $registerError = $msg;
        } else {
            $loginError = $msg;
        }
    }
} elseif ($isPost && !$dbOk) {
    $loginError = 'Database is not available.';
}

$csrf = csrf_token();
$initialRegister = $registerError !== null
    || (isset($_GET['view']) && (string)$_GET['view'] === 'register')
    || (isset($_GET['register']) && (string)$_GET['register'] !== '0');

$pageTitle = 'Admin sign in — Ashford University';

$inputClass = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-sky-400/50 focus:outline-none focus:ring-2 focus:ring-sky-400/20 dark:border-white/10 dark:bg-slate-950/50 dark:text-white dark:placeholder:text-slate-500';
$alertSuccessClass = 'mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100';
$alertErrorClass = 'mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100';
$alertWarnClass = 'mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-50₀/3₀ dark:bg-amber-5₀₀/₁₀ dark:text-amber-1₀₀';
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
      <a href="<?= htmlspecialchars(url('/')) ?>" class="text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white">← Site home</a>
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

    <div id="panelLogin" class="rounded-3xl border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-slate-900/90 dark:shadow-black/30 <?= $initialRegister ? 'hidden' : '' ?>">
      <div class="text-center">
        <img
          src="<?= htmlspecialchars(url('/assets/img/ashford_university_icon.svg')) ?>"
          alt="Ashford University"
          width="56"
          height="56"
          class="mx-auto h-14 w-14 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-white/15"
        />
        <h1 class="mt-4 text-2xl font-semibold text-slate-900 dark:text-white">Ashford University</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Admin portal</p>
      </div>
      <?php if ($registered): ?>
        <div class="<?= htmlspecialchars($alertSuccessClass) ?>">Account created. Sign in below.</div>
      <?php endif; ?>
      <?php if ($loginError): ?>
        <div class="<?= htmlspecialchars($alertErrorClass) ?>"><?= htmlspecialchars($loginError) ?></div>
      <?php endif; ?>
      <form class="mt-6 space-y-4" method="post" action="<?= htmlspecialchars(url('/login.php')) ?>" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
        <input type="hidden" name="intent" value="login" />
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required
            class="<?= htmlspecialchars($inputClass) ?>"
            placeholder="you@school.edu" <?= $dbOk ? '' : 'disabled' ?> />
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required
            class="<?= htmlspecialchars($inputClass) ?>"
            placeholder="Password" <?= $dbOk ? '' : 'disabled' ?> />
        </div>
        <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-slate-950 shadow-sm hover:bg-sky-400 disabled:opacity-50 dark:shadow-sky-900/20" <?= $dbOk ? '' : 'disabled' ?>>Sign in</button>
      </form>
      <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
        No account? <button type="button" id="btnShowRegister" class="font-semibold text-sky-700 hover:text-sky-600 dark:text-sky-300 dark:hover:text-sky-200">Register</button>
      </p>
    </div>

    <div id="panelRegister" class="rounded-3xl border border-slate-200 bg-white p-8 shadow-xl shadow-slate-200/50 dark:border-white/10 dark:bg-slate-900/90 dark:shadow-black/30 <?= $initialRegister ? '' : 'hidden' ?>">
      <div class="text-center">
        <img
          src="<?= htmlspecialchars(url('/assets/img/ashford_university_icon.svg')) ?>"
          alt=""
          width="48"
          height="48"
          class="mx-auto h-12 w-12 rounded-2xl object-cover ring-1 ring-slate-200 dark:ring-white/15"
          aria-hidden="true"
        />
        <h2 class="mt-3 text-xl font-semibold text-slate-900 dark:text-white">Create admin account</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Full admin role (seed other roles via scripts).</p>
      </div>
      <?php if ($registerError): ?>
        <div class="<?= htmlspecialchars($alertErrorClass) ?>"><?= htmlspecialchars($registerError) ?></div>
      <?php endif; ?>
      <form class="mt-6 space-y-4" method="post" action="<?= htmlspecialchars(url('/login.php?view=register')) ?>" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
        <input type="hidden" name="intent" value="register" />
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="reg_email">Email</label>
          <input id="reg_email" name="email" type="email" autocomplete="email" required class="<?= htmlspecialchars($inputClass) ?>" placeholder="you@school.edu" <?= $dbOk ? '' : 'disabled' ?> />
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="reg_password">Password</label>
          <input id="reg_password" name="password" type="password" minlength="8" required class="<?= htmlspecialchars($inputClass) ?>" <?= $dbOk ? '' : 'disabled' ?> />
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700 dark:text-slate-300" for="reg_confirm">Confirm password</label>
          <input id="reg_confirm" name="confirm_password" type="password" minlength="8" required class="<?= htmlspecialchars($inputClass) ?>" <?= $dbOk ? '' : 'disabled' ?> />
        </div>
        <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-slate-950 shadow-sm hover:bg-sky-400 disabled:opacity-50 dark:shadow-sky-900/20" <?= $dbOk ? '' : 'disabled' ?>>Create account</button>
      </form>
      <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
        Have an account? <button type="button" id="btnShowLogin" class="font-semibold text-sky-700 hover:text-sky-600 dark:text-sky-300 dark:hover:text-sky-200">Sign in</button>
      </p>
    </div>

    <p class="relative z-10 mt-8 text-center text-xs text-slate-500 dark:text-slate-500">
      Use credentials provided by your administrator.
    </p>
  </main>
  <script>
    (function () {
      var base = <?= json_encode(url('/login.php'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?>;
      var loginPanel = document.getElementById('panelLogin');
      var regPanel = document.getElementById('panelRegister');
      document.getElementById('btnShowRegister')?.addEventListener('click', function () {
        loginPanel.classList.add('hidden');
        regPanel.classList.remove('hidden');
        history.replaceState(null, '', base + '?view=register');
      });
      document.getElementById('btnShowLogin')?.addEventListener('click', function () {
        regPanel.classList.add('hidden');
        loginPanel.classList.remove('hidden');
        history.replaceState(null, '', base);
      });
    })();
  </script>
  <?php require __DIR__ . '/../app/views/partials/theme_boot.php'; ?>
</body>
</html>
