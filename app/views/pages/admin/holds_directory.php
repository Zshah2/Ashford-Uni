<?php
/** @var array<int, array<string, mixed>> $holdRows */
$holdRows = $holdRows ?? [];
?>
<h1 class="<?= htmlspecialchars(ui_h1()) ?>">Active holds</h1>
<p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">
  Students with at least one active hold — registration may be blocked until cleared.
  Look up one student under
  <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">People</a>.
</p>

<div id="admin-active-holds-list" class="scroll-mt-28 mt-6 <?= htmlspecialchars(ui_table_wrap()) ?>">
  <table class="min-w-full text-left text-sm">
    <thead class="<?= htmlspecialchars(ui_thead()) ?>">
      <tr>
        <th class="px-4 py-3">Student ID</th>
        <th class="px-4 py-3">Name</th>
        <th class="px-4 py-3">Active holds</th>
        <th class="px-4 py-3">Latest hold</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
      <?php foreach ($holdRows as $index => $h): ?>
        <?php
        $studentId = (int)($h['student_id'] ?? 0);
        $holds = is_array($h['holds'] ?? null) ? $h['holds'] : [];
        $holdCount = count($holds);
        $holdName = trim((string)($h['first_name'] ?? '') . ' ' . (string)($h['last_name'] ?? ''));
        $detailsId = 'active-holds-details-' . $index;
        $latestHold = $holds[0] ?? [];
        ?>
        <tr class="group cursor-pointer transition-colors hover:bg-amber-50/70 dark:hover:bg-amber-950/20" tabindex="0" aria-controls="<?= htmlspecialchars($detailsId) ?>" aria-expanded="false" data-hold-row>
          <td class="px-4 py-3 font-mono text-xs">
            <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=people&id=' . $studentId . '&people_panel=hold')) ?>"><?= $studentId ?></a>
          </td>
          <td class="px-4 py-3"><?= $holdName !== '' ? htmlspecialchars($holdName) : '<span class="text-slate-400">—</span>' ?></td>
          <td class="px-4 py-3">
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-inset ring-amber-200">
              <?= $holdCount ?> active <?= $holdCount === 1 ? 'hold' : 'holds' ?>
              <span aria-hidden="true" class="text-amber-700 transition-transform group-aria-expanded:rotate-180">⌄</span>
            </span>
          </td>
          <td class="px-4 py-3 text-sm">
            <div class="font-medium"><?= htmlspecialchars((string)($latestHold['hold_type'] ?? '')) ?></div>
            <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars((string)($latestHold['created_at'] ?? '')) ?></div>
          </td>
        </tr>
        <tr id="<?= htmlspecialchars($detailsId) ?>" class="hidden bg-slate-50/80 dark:bg-slate-900/60" data-hold-details>
          <td colspan="4" class="px-4 py-3">
            <div class="grid gap-2 sm:grid-cols-2">
              <?php foreach ($holds as $hold): ?>
                <div class="rounded-lg border border-amber-100 bg-white px-3 py-2 text-sm shadow-sm dark:border-amber-900/50 dark:bg-slate-950">
                  <div class="font-semibold text-slate-900 dark:text-slate-100"><?= htmlspecialchars((string)($hold['hold_type'] ?? '')) ?></div>
                  <div class="mt-1 text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)($hold['note'] ?? '')) ?></div>
                  <div class="mt-1 text-xs text-slate-500 dark:text-slate-500">Since <?= htmlspecialchars((string)($hold['created_at'] ?? '')) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$holdRows): ?>
        <tr>
          <td class="px-4 py-8 text-center <?= htmlspecialchars(ui_muted()) ?>" colspan="4">
            No active holds. Use
            <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=people')) ?>">People</a>
            to look up a student.
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
  document.querySelectorAll('[data-hold-row]').forEach(function (row) {
    function toggle() {
      var details = document.getElementById(row.getAttribute('aria-controls'));
      if (!details) return;
      var expanded = row.getAttribute('aria-expanded') === 'true';
      row.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      details.classList.toggle('hidden', expanded);
    }
    row.addEventListener('click', function (event) {
      if (event.target.closest('a')) return;
      toggle();
    });
    row.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggle();
      }
    });
  });
</script>
