<?php
/** @var string $termName */
/** @var list<array<string, mixed>> $rows */
?>
<section class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
  <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Ashford College</p>
  <h1 class="mt-2 font-serif text-4xl font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars($termName) ?> master schedule</h1>
  <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Original Ashford sections. No login required.</p>
  <div class="mt-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
    <table class="min-w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
        <tr><th class="px-4 py-3">Course</th><th class="px-4 py-3">Days</th><th class="px-4 py-3">Time</th><th class="px-4 py-3">Room</th><th class="px-4 py-3">Instructor</th><th class="px-4 py-3">Seats</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr class="border-t border-slate-100 dark:border-slate-800">
            <td class="px-4 py-3"><span class="font-semibold"><?= htmlspecialchars((string)$row['course_id']) ?></span> <?= htmlspecialchars((string)$row['course_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)$row['meeting_days']) ?></td>
            <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string)$row['meeting_time']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)$row['room']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars(trim((string)$row['instructor'])) ?></td>
            <td class="px-4 py-3"><?= (int)$row['capacity'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
