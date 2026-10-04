<?php
/** @var list<array<string, mixed>> $courses */
?>
<section class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
  <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Ashford College</p>
  <h1 class="mt-2 font-serif text-4xl font-semibold text-slate-900 dark:text-white">University catalog</h1>
  <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Courses and prerequisites. A course may have none, one, or several. No login required.</p>
  <div class="mt-8 space-y-3">
    <?php foreach ($courses as $course): ?>
      <article class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= htmlspecialchars((string)$course['course_id']) ?> · <?= htmlspecialchars((string)$course['course_name']) ?></h2>
          <span class="text-xs font-semibold uppercase text-slate-500"><?= (int)$course['credits'] ?> credits<?= !empty($course['dept_name']) ? ' · ' . htmlspecialchars((string)$course['dept_name']) : '' ?></span>
        </div>
        <?php if (!empty($course['description'])): ?>
          <p class="mt-2 text-sm text-slate-600 dark:text-slate-300"><?= htmlspecialchars((string)$course['description']) ?></p>
        <?php endif; ?>
        <p class="mt-2 text-sm text-slate-500">Prerequisites: <?= htmlspecialchars((string)($course['prereqs'] !== '' && $course['prereqs'] !== null ? $course['prereqs'] : 'None')) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>
