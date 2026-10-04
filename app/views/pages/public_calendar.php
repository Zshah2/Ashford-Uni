<?php
/** @var list<array<string, mixed>> $events */
?>
<section class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
  <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Ashford College</p>
  <h1 class="mt-2 font-serif text-4xl font-semibold text-slate-900 dark:text-white">Academic calendar</h1>
  <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Fall 2026, Winter 2027, and Spring 2027. No login required.</p>
  <div class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
    <table class="w-full text-left text-sm">
      <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800">
        <tr><th class="px-4 py-3">Dates</th><th class="px-4 py-3">Event</th><th class="px-4 py-3">Term</th></tr>
      </thead>
      <tbody>
        <?php foreach ($events as $event): ?>
          <tr class="border-t border-slate-100 dark:border-slate-800">
            <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string)$event['start_date']) ?><?php if (!empty($event['end_date']) && $event['end_date'] !== $event['start_date']): ?> – <?= htmlspecialchars((string)$event['end_date']) ?><?php endif; ?></td>
            <td class="px-4 py-3">
              <div class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars((string)$event['event_name']) ?></div>
              <?php if (!empty($event['notes'])): ?><div class="text-slate-500"><?= htmlspecialchars((string)$event['notes']) ?></div><?php endif; ?>
            </td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($event['term_name'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
