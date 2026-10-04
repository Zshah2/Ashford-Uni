<?php
/** @var array $app */
?>
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-fuchsia-500/25 dark:bg-[#0a0f1f]/92">
  <div class="border-b border-slate-200 bg-slate-50 text-xs text-slate-600 dark:border-fuchsia-500/20 dark:bg-gradient-to-r dark:from-indigo-950 dark:via-violet-950 dark:to-fuchsia-950 dark:text-slate-200">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-2 sm:px-6">
      <div class="hidden sm:flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="font-semibold text-slate-800 dark:text-slate-100">Information for:</span>
        <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="#admissions">Students</a>
        <span class="text-slate-300 dark:text-fuchsia-400/40">·</span>
        <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="/login.php">Faculty &amp; Staff</a>
        <span class="text-slate-300 dark:text-fuchsia-400/40">·</span>
        <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="#visit">Families</a>
        <span class="text-slate-300 dark:text-fuchsia-400/40">·</span>
        <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="#visit">Visitors</a>
        <span class="text-slate-300 dark:text-fuchsia-400/40">·</span>
        <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="#news">Alumni</a>
      </div>

      <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:justify-end">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 sm:hidden">
          <span class="font-semibold text-slate-800 dark:text-slate-100">For:</span>
          <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="#admissions">Students</a>
          <span class="text-slate-300 dark:text-fuchsia-400/40">·</span>
          <a class="transition-colors hover:text-indigo-700 dark:hover:text-cyan-200" href="/login.php">Admin sign in</a>
        </div>

        <a class="inline-flex items-center gap-2 font-semibold text-slate-600 transition-colors hover:text-indigo-700 dark:text-cyan-100/90 dark:hover:text-cyan-50" href="#programs">
          <svg class="h-4 w-4 text-fuchsia-300/90" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M11 19a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z" stroke="currentColor" stroke-width="2"/>
            <path d="M21 21l-4.3-4.3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Search
        </a>
      </div>
    </div>
  </div>

  <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
    <a href="<?= htmlspecialchars(url('/')) ?>" class="group inline-flex items-center gap-3">
      <?php
      $logoClass = 'h-10 w-auto max-w-[11rem] shrink-0 object-contain sm:h-11 sm:max-w-[13rem]';
      $logoAlt = $app['site']['name'];
      require view_path('partials/brand_logo.php');
      ?>
    </a>

    <nav class="hidden items-center gap-5 lg:flex" aria-label="Primary">
      <?php foreach ($app['nav'] as $item): ?>
        <a class="text-sm font-semibold text-slate-700 transition-colors hover:text-indigo-700 dark:text-slate-200 dark:hover:text-cyan-200" href="<?= htmlspecialchars(nav_url($item['href'])) ?>">
          <?= htmlspecialchars($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="flex items-center gap-2">
      <?php require view_path('partials/theme_toggle.php'); ?>
      <div class="hidden items-center gap-2 lg:flex">
      <a class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition-colors hover:text-indigo-800 dark:text-cyan-200/90 dark:hover:text-cyan-50" href="<?= htmlspecialchars(nav_url('/login.php')) ?>">
        Login
      </a>
      <a class="rounded-xl bg-gradient-to-r from-fuchsia-600 to-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-fuchsia-900/35 transition hover:from-fuchsia-500 hover:to-violet-500" href="<?= htmlspecialchars(nav_url($app['cta']['primary']['href'])) ?>">
        <?= htmlspecialchars($app['cta']['primary']['label']) ?>
      </a>
      </div>
    <button
      id="mobileMenuButton"
      class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-slate-50 p-2 text-slate-700 shadow-sm hover:bg-slate-100 lg:hidden dark:border-fuchsia-500/30 dark:bg-fuchsia-950/30 dark:text-cyan-100 dark:hover:bg-fuchsia-900/40"
      type="button"
      aria-label="Open menu"
      aria-expanded="false"
      aria-controls="mobileMenu"
    >
      <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
    </div>
  </div>

  <div id="mobileMenu" class="hidden border-t border-slate-200 bg-white lg:hidden dark:border-fuchsia-500/20 dark:bg-[#0a0f1f]">
    <div class="mx-auto max-w-6xl px-4 py-3 sm:px-6">
      <div class="flex flex-col gap-2">
        <?php foreach ($app['nav'] as $item): ?>
          <a class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-fuchsia-950/50 dark:hover:text-cyan-100" href="<?= htmlspecialchars(nav_url($item['href'])) ?>">
            <?= htmlspecialchars($item['label']) ?>
          </a>
        <?php endforeach; ?>
        <div class="mt-2 grid grid-cols-2 gap-2">
          <a class="rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-center text-sm font-semibold text-cyan-900 shadow-sm hover:bg-cyan-100 dark:border-cyan-500/25 dark:bg-cyan-950/25 dark:text-cyan-100 dark:hover:bg-cyan-900/35" href="<?= htmlspecialchars(nav_url('/login.php')) ?>">
            Login
          </a>
          <a class="rounded-xl bg-gradient-to-r from-fuchsia-600 to-violet-600 px-3 py-2 text-center text-sm font-semibold text-white shadow-lg shadow-fuchsia-900/30 hover:from-fuchsia-500 hover:to-violet-500" href="<?= htmlspecialchars(nav_url($app['cta']['primary']['href'])) ?>">
            <?= htmlspecialchars($app['cta']['primary']['label']) ?>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>
