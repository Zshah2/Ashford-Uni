<?php
/** @var array $app */
/** @var string $content */
/** @var string|null $pageTitle */
/** @var string $admin_username */
/** @var string $admin_role_label */
/** @var string $csrf */
/** @var string|null $admin_nav_active */
$docTitle = isset($pageTitle) && is_string($pageTitle) && $pageTitle !== ''
    ? $pageTitle
    : 'Administration — Ashford College';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($docTitle) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
  <?php require view_path('partials/theme_init.php'); ?>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { fontFamily: { sans: ['DM Sans', 'system-ui', 'sans-serif'] } } },
    };
  </script>
  <link rel="stylesheet" href="<?= htmlspecialchars(url('/assets/css/theme.css')) ?>" />
</head>
<body class="nb-staff min-h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
  <header class="relative border-b border-slate-200 bg-white/80 backdrop-blur dark:border-white/10 dark:bg-slate-950/80">
    <div class="mx-auto max-w-[min(100vw-2rem,110rem)] px-4 py-4 sm:px-6">
      <div class="flex flex-wrap items-center gap-3 lg:gap-4">
        <div class="flex min-w-0 flex-1 items-center gap-3 lg:flex-none">
          <a href="<?= htmlspecialchars(url('/admin')) ?>" class="flex min-w-0 items-center gap-3">
            <?php
            $logoClass = 'h-9 w-auto max-w-[10rem] shrink-0 object-contain sm:h-10 sm:max-w-[12rem]';
            $logoAlt = 'Ashford Admin';
            require view_path('partials/brand_logo.php');
            ?>
            <div class="min-w-0">
              <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">Ashford Admin</div>
              <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span><?= htmlspecialchars($admin_username ?? '') ?></span>
                <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200"><?= htmlspecialchars($admin_role_label ?? '') ?></span>
              </div>
            </div>
          </a>
        </div>

        <?php
        $admin_nav_layout = 'horizontal';
        require view_path('partials/admin_portal_nav.php');
        ?>

        <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:gap-3 lg:ml-auto lg:w-auto">
          <button
            type="button"
            id="admin-nav-open"
            class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 lg:hidden"
            aria-haspopup="dialog"
            aria-controls="admin-nav-drawer"
            aria-expanded="false"
          >
            <span class="font-mono text-base leading-none" aria-hidden="true">≡</span>
            Menu
          </button>

          <?php require view_path('partials/theme_toggle.php'); ?>

          <a href="<?= htmlspecialchars(url('/')) ?>" class="hidden text-sm text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white sm:inline">Site home</a>
          <form method="post" action="<?= htmlspecialchars(url('/logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? '') ?>" />
            <button type="submit" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10">Log out</button>
          </form>
        </div>
      </div>
    </div>
  </header>

  <div id="admin-nav-drawer" class="fixed inset-0 z-[80] hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Admin navigation">
    <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-[2px]" data-admin-nav-close="1"></div>
    <div class="absolute right-0 top-0 flex h-full w-[min(22rem,92vw)] flex-col overflow-hidden border-l border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
      <div class="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
        <div class="text-sm font-semibold text-slate-900 dark:text-white">Navigation</div>
        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-slate-200/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-slate-300 dark:hover:bg-slate-800" data-admin-nav-close="1">
          Close
        </button>
      </div>
      <div class="min-h-0 overflow-y-auto p-4">
        <?php
        $admin_nav_layout = 'stack';
        require view_path('partials/admin_portal_nav.php');
        ?>
      </div>
    </div>
  </div>

  <main class="relative mx-auto max-w-[min(100vw-2rem,110rem)] px-4 py-10 sm:px-6">
    <?= $content ?>
  </main>

  <script>
  (function () {
    var openBtn = document.getElementById('admin-nav-open');
    var drawer = document.getElementById('admin-nav-drawer');
    if (!openBtn || !drawer) return;

    function openDrawer() {
      drawer.classList.remove('hidden');
      document.documentElement.classList.add('overflow-hidden');
      openBtn.setAttribute('aria-expanded', 'true');
    }
    function closeDrawer() {
      drawer.classList.add('hidden');
      document.documentElement.classList.remove('overflow-hidden');
      openBtn.setAttribute('aria-expanded', 'false');
    }

    openBtn.addEventListener('click', openDrawer);
    drawer.querySelectorAll('[data-admin-nav-close]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        closeDrawer();
      });
    });
    drawer.querySelectorAll('a[href]').forEach(function (a) {
      a.addEventListener('click', function () {
        closeDrawer();
      });
    });
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (!drawer.classList.contains('hidden')) closeDrawer();
    });
  })();
  </script>

  <?php require view_path('partials/theme_boot.php'); ?>
</body>
</html>
