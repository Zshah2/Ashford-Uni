<?php
/** @var list<array<string, mixed>> $teamRows */
$teamRows = $teamRows ?? [];

$teamCan = static function (array $row, string $cap): bool {
    $role = (string)($row['role'] ?? '');
    $username = (string)($row['username'] ?? '');
    $admin = $role === 'admin';
    $staff = $admin || $role === 'limited';

    return match ($cap) {
        'view' => in_array($role, ['admin', 'limited', 'viewer'], true),
        'build' => $admin,
        'accounts' => $admin,
        'holds' => $staff,
        'grades' => $username === 'mainadmin',
        default => false,
    };
};

$caps = [
    'view' => 'Open the admin portal',
    'build' => 'Create people, departments, courses, and sections',
    'build2' => 'Change prerequisites, terms, and registration windows',
    'accounts' => 'Reset passwords, change emails, turn accounts on or off',
    'holds' => 'Holds and registration add or drop',
    'grades' => 'Enter grades',
];
?>
<h1 class="<?= htmlspecialchars(ui_h1()) ?>">Team access</h1>
<p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">What each account can do when the professor tests the portal. Grades stay on the main admin account.</p>

<?php if ($teamRows === []): ?>
  <div class="mt-6 <?= htmlspecialchars(ui_flash('warn')) ?>">No team accounts found.</div>
<?php else: ?>
  <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
    <table class="min-w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-300">
        <tr>
          <th class="px-4 py-3">Can do</th>
          <?php foreach ($teamRows as $row): ?>
            <th class="px-4 py-3">
              <div class="normal-case tracking-normal text-slate-900 dark:text-white"><?= htmlspecialchars((string)($row['display_name'] ?: $row['username'])) ?></div>
              <div class="mt-1 font-normal normal-case tracking-normal text-slate-500"><?= htmlspecialchars((string)($row['email'] ?? '')) ?></div>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        <?php foreach ($caps as $key => $label): ?>
          <?php $capKey = $key === 'build2' ? 'build' : $key; ?>
          <tr>
            <td class="px-4 py-3 text-slate-800 dark:text-slate-100"><?= htmlspecialchars($label) ?></td>
            <?php foreach ($teamRows as $row): ?>
              <?php $yes = $teamCan($row, $capKey); ?>
              <td class="px-4 py-3 font-semibold <?= $yes ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-400' ?>"><?= $yes ? 'Yes' : 'No' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
