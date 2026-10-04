<?php
/** @var string $view */
/** @var bool $isAdmin */
$view = $view ?? '';
$isAdmin = (bool)($isAdmin ?? false);

$adminNavItem = static function (string $href, string $label, bool $active): string {
    $cls = $active
        ? 'block rounded-xl px-3 py-2 font-semibold text-indigo-950 bg-indigo-50 ring-1 ring-indigo-200 dark:bg-indigo-500/15 dark:text-indigo-100 dark:ring-indigo-500/30'
        : 'block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/5';

    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
};

$adminNavGroup = static function (string $label): string {
    return '<div class="pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">' . htmlspecialchars($label) . '</div>';
};
?>
<nav class="space-y-1 text-sm">
  <?php if (!empty($isStat)): ?>
    <?= $adminNavItem(url('/admin.php?view=stats'), 'Department statistics', $view === 'stats') ?>
  <?php else: ?>
  <?= $adminNavItem(url('/admin.php?view=dashboard'), 'Dashboard', $view === 'dashboard') ?>
  <?= $adminNavGroup('People & directory') ?>
  <?= $adminNavItem(url('/admin.php?view=people'), 'People', $view === 'people') ?>
  <?= $adminNavItem(url('/admin.php?view=schedule'), 'Master schedule', $view === 'schedule') ?>
  <?= $adminNavGroup('Scheduling & enrollment') ?>
  <?= $adminNavItem(url('/admin.php?view=courses'), 'Courses', $view === 'courses' || $view === 'course') ?>
  <?= $adminNavItem(url('/admin.php?view=enrollment'), 'Enrollment', $view === 'enrollment') ?>
  <?= $adminNavItem(url('/admin.php?view=departments'), 'Departments', $view === 'departments' || $view === 'department') ?>
  <?= $adminNavItem(url('/admin.php?view=registration'), 'Registration', $view === 'registration') ?>
  <?php if ($isAdmin): ?>
    <?= $adminNavGroup('Catalog & records') ?>
    <?= $adminNavItem(url('/admin.php?view=catalog'), 'Catalog', $view === 'catalog') ?>
    <?= $adminNavItem(url('/admin.php?view=terms'), 'Terms', $view === 'terms') ?>
  <?php endif; ?>
  <?= $adminNavGroup('Student & admin') ?>
  <?= $adminNavItem(url('/admin.php?view=holds'), 'Holds', $view === 'holds') ?>
  <?php if ($isAdmin): ?>
    <?= $adminNavItem(url('/admin.php?view=accounts'), 'Accounts', $view === 'accounts') ?>
    <?= $adminNavItem(url('/admin.php?view=team'), 'Team access', $view === 'team') ?>
  <?php endif; ?>
  <?= $adminNavGroup('Insights & preferences') ?>
  <?= $adminNavItem(url('/admin.php?view=reports'), 'Reports & Analytics', $view === 'reports') ?>
  <?= $adminNavItem(url('/admin.php?view=messages'), 'Messages', $view === 'messages') ?>
  <?= $adminNavItem(url('/admin.php?view=settings'), 'Settings', $view === 'settings') ?>
  <?php endif; ?>
</nav>
