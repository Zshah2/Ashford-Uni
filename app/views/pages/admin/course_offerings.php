<?php
/** @var array $app */
/** @var list<array<string, mixed>> $terms */
/** @var int|null $term_id */
/** @var list<array<string, mixed>> $dept_rows */
/** @var string $dept_id */
/** @var string $crn */
/** @var int|null $faculty_id */
/** @var string $q */
/** @var list<array<string, mixed>> $course_sections */
/** @var int $course_sections_total */
/** @var int $page */
/** @var int $per_page */
/** @var int $total_pages */

$terms = $terms ?? [];
$term_id = $term_id ?? null;
$dept_rows = $dept_rows ?? [];
$dept_id = $dept_id ?? '';
$crn = $crn ?? '';
$faculty_id = isset($faculty_id) && $faculty_id !== null ? (int)$faculty_id : null;
$q = $q ?? '';
$course_sections = $course_sections ?? [];
$course_sections_total = (int)($course_sections_total ?? 0);
$page = max(1, (int)($page ?? 1));
$per_page = (int)($per_page ?? 50);
if (!in_array($per_page, [25, 50, 100, 200], true)) {
    $per_page = 50;
}
$total_pages = max(1, (int)($total_pages ?? 1));

$from = $course_sections_total > 0 ? (($page - 1) * $per_page + 1) : 0;
$to = min($course_sections_total, ($page - 1) * $per_page + count($course_sections));

$pagerUrl = static function (int $p) use ($term_id, $dept_id, $crn, $faculty_id, $q, $per_page): string {
    $qparams = ['view' => 'courses', 'page' => max(1, $p)];
    if ($term_id !== null) {
        $qparams['term_id'] = (string)$term_id;
    }
    if ($dept_id !== '') {
        $qparams['dept_id'] = $dept_id;
    }
    if ($crn !== '') {
      $qparams['crn'] = $crn;
    }
    if ($faculty_id !== null) {
      $qparams['faculty_id'] = (string)$faculty_id;
    }
    if ($q !== '') {
        $qparams['q'] = $q;
    }
    if ($per_page !== 50) {
        $qparams['per_page'] = (string)$per_page;
    }

    return url('/admin.php?' . http_build_query($qparams));
};

$fmtInstr = static function (?string $first, ?string $last): string {
    $f = trim((string)$first);
    $l = trim((string)$last);
    if ($f === '' && $l === '') {
        return '—';
    }

    return htmlspecialchars(trim($f . ' ' . $l), ENT_QUOTES, 'UTF-8');
};

/** @var list<array<string, mixed>> $catalog_courses */
/** @var list<array<string, mixed>> $faculty_rows */
/** @var bool $isAdmin */

$catalog_courses = $catalog_courses ?? [];
$faculty_rows = $faculty_rows ?? [];
$highlight_section = isset($_GET['highlight_section']) && ctype_digit((string)$_GET['highlight_section'])
    ? (int)$_GET['highlight_section']
    : null;
$edit_section_id = isset($_GET['edit_section']) && ctype_digit((string)$_GET['edit_section'])
    ? (int)$_GET['edit_section']
    : null;

$coursesPageUrl = static function (?int $editSection = null) use ($term_id, $dept_id, $crn, $faculty_id, $q, $per_page, $page): string {
    $qparams = ['view' => 'courses', 'page' => $page];
    if ($term_id !== null) {
        $qparams['term_id'] = (string)$term_id;
    }
    if ($dept_id !== '') {
        $qparams['dept_id'] = $dept_id;
    }
    if ($crn !== '') {
      $qparams['crn'] = $crn;
    }
    if ($faculty_id !== null) {
      $qparams['faculty_id'] = (string)$faculty_id;
    }
    if ($q !== '') {
        $qparams['q'] = $q;
    }
    if ($per_page !== 50) {
        $qparams['per_page'] = (string)$per_page;
    }
    if ($editSection !== null && $editSection > 0) {
        $qparams['edit_section'] = (string)$editSection;
    }

    return url('/admin.php?' . http_build_query($qparams));
};

$courseDetailHref = static function (string $courseId, ?int $sectionId = null) use ($term_id): string {
    $q = ['view' => 'course', 'course_id' => trim($courseId)];
    if ($term_id !== null) {
        $q['term_id'] = (string)$term_id;
    }
    if ($sectionId !== null && $sectionId > 0) {
        $q['highlight_section'] = (string)$sectionId;
    }

    return url('/admin.php?' . http_build_query($q));
};
?>
<h1 class="<?= htmlspecialchars(ui_h1()) ?>">Courses</h1>
<p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">
  Scheduled sections for the selected term: course, credits, instructor, meeting pattern, and enrollment.
  Need a catalog course first?
  <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=catalog')) ?>">Open Catalog</a>.
  For the student and faculty directory, use
  <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=schedule')) ?>">Master schedule</a>.
</p>

<div id="course-filters-wrap" class="mt-5">
  <div class="mb-2 flex items-center justify-between gap-3">
    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">Course filters</span>
    <button id="course-filters-toggle" type="button" class="<?= htmlspecialchars(ui_btn_secondary()) ?>">Hide filters</button>
  </div>
  <div id="course-filters-panel" class="<?= htmlspecialchars(ui_card('p-5')) ?>">
  <form class="grid gap-4" method="get">
    <input type="hidden" name="view" value="courses" />
    <div class="grid gap-4 sm:grid-cols-2">
      <div class="min-w-0">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-term">Term</label>
        <select id="co-term" name="term_id" class="<?= htmlspecialchars(ui_select()) ?> w-full">
          <?php foreach ($terms as $t): $tid = (int)($t['term_id'] ?? 0); ?>
            <option value="<?= (int)$tid ?>" <?= $term_id !== null && $tid === $term_id ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($t['code'] ?? '')) ?> — <?= htmlspecialchars((string)($t['name'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="min-w-0">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-dept">Department</label>
        <select id="co-dept" name="dept_id" class="<?= htmlspecialchars(ui_select()) ?> w-full">
          <option value="">All departments</option>
          <?php foreach ($dept_rows as $d): $did = (string)($d['dept_id'] ?? ''); ?>
            <option value="<?= htmlspecialchars($did) ?>" <?= $did !== '' && $did === $dept_id ? 'selected' : '' ?>>
              <?= htmlspecialchars($did) ?> — <?= htmlspecialchars((string)($d['dept_name'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="min-w-0">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-crn">CRN / Section ID</label>
        <input id="co-crn" name="crn" value="<?= htmlspecialchars($crn) ?>" inputmode="numeric" pattern="[0-9]+" class="<?= htmlspecialchars(ui_input()) ?> w-full" placeholder="e.g. 1042" />
      </div>
      <div class="min-w-0">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-faculty">Professor</label>
        <select id="co-faculty" name="faculty_id" class="<?= htmlspecialchars(ui_select()) ?> w-full">
          <option value="">All professors</option>
          <?php foreach ($faculty_rows as $f): $fid = (int)($f['faculty_id'] ?? 0); ?>
            <option value="<?= $fid ?>" <?= $faculty_id === $fid ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)($f['last_name'] ?? '') . ', ' . (string)($f['first_name'] ?? ''))) ?> (<?= $fid ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="min-w-0">
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-q">Search courses</label>
      <input id="co-q" name="q" value="<?= htmlspecialchars($q) ?>" class="<?= htmlspecialchars(ui_input()) ?> w-full" placeholder="Course ID, title, instructor, section ID, room…" />
    </div>
    <div class="flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
      <div class="w-full sm:w-40">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="co-per">Rows per page</label>
        <select id="co-per" name="per_page" class="<?= htmlspecialchars(ui_select()) ?> w-full">
          <?php foreach ([25, 50, 100, 200] as $pp): ?>
            <option value="<?= (int)$pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= (int)$pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?> !px-3 !py-2 text-sm">Apply</button>
      <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=courses')) ?>">Reset</a>
    </div>
  </form>
  </div>
  <button id="course-filters-show" type="button" class="hidden <?= htmlspecialchars(ui_btn_secondary()) ?>">Show filters</button>
</div>

<script>
(function () {
  var panel = document.getElementById('course-filters-panel');
  var toggle = document.getElementById('course-filters-toggle');
  var show = document.getElementById('course-filters-show');
  if (!panel || !toggle || !show) return;

  function setHidden(hidden) {
    panel.classList.toggle('hidden', hidden);
    toggle.classList.toggle('hidden', hidden);
    show.classList.toggle('hidden', !hidden);
    try { localStorage.setItem('course_filters_hidden', hidden ? '1' : '0'); } catch (e) {}
  }

  toggle.addEventListener('click', function () { setHidden(true); });
  show.addEventListener('click', function () { setHidden(false); });

  try {
    if (localStorage.getItem('course_filters_hidden') === '1') setHidden(true);
  } catch (e) {}
})();
</script>

<?php if (!empty($isAdmin) && $terms !== []): ?>
  <details class="group mt-5 rounded-2xl border border-indigo-200 bg-indigo-50/40 shadow-sm open:bg-white dark:border-indigo-900 dark:bg-indigo-950/30 dark:open:bg-slate-900" <?= $highlight_section !== null ? 'open' : '' ?>>
    <summary class="cursor-pointer list-none px-5 py-4 text-sm font-semibold text-indigo-950 dark:text-indigo-100">
      <span class="inline-flex items-center gap-2">
        <span class="rounded-lg bg-indigo-600 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-white">Admin</span>
        Add new section for this term
      </span>
      <span class="mt-1 block text-xs font-normal text-indigo-900/80 dark:text-indigo-200/80">Creates a class offering in MySQL · blocks instructor/room time conflicts</span>
    </summary>
    <form class="border-t border-indigo-200/80 px-5 py-5 dark:border-indigo-900/80" method="post" action="<?= htmlspecialchars(url('/admin.php?view=courses')) ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? csrf_token()) ?>" />
      <input type="hidden" name="action" value="section_save" />
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-term">Term</label>
          <select id="ns-term" name="term_id" required class="<?= htmlspecialchars(ui_select()) ?>">
            <?php foreach ($terms as $t): $tid = (int)($t['term_id'] ?? 0); ?>
              <option value="<?= (int)$tid ?>" <?= $term_id !== null && $tid === $term_id ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($t['code'] ?? '')) ?> — <?= htmlspecialchars((string)($t['name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-course">Course</label>
          <select id="ns-course" name="course_id" required class="<?= htmlspecialchars(ui_select()) ?>">
            <?php if ($catalog_courses === []): ?>
              <option value="">No courses in catalog — add one under Catalog first</option>
            <?php else: ?>
              <option value="">Select course…</option>
              <?php foreach ($catalog_courses as $c): $cid = (string)($c['course_id'] ?? ''); ?>
                <option value="<?= htmlspecialchars($cid) ?>"><?= htmlspecialchars($cid) ?> — <?= htmlspecialchars((string)($c['course_name'] ?? '')) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <?php if ($catalog_courses === []): ?>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
              No catalog courses yet.
              <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=catalog')) ?>">Add a course in Catalog</a>
              before creating sections.
            </p>
          <?php endif; ?>
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-faculty">Instructor</label>
          <select id="ns-faculty" name="faculty_id" class="<?= htmlspecialchars(ui_select()) ?>">
            <option value="">Unassigned</option>
            <?php foreach ($faculty_rows as $f): $fid = (int)($f['faculty_id'] ?? 0); ?>
              <option value="<?= (int)$fid ?>"><?= htmlspecialchars(trim((string)($f['last_name'] ?? '') . ', ' . (string)($f['first_name'] ?? ''))) ?> (<?= (int)$fid ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-days">Meeting days</label>
          <input id="ns-days" name="meeting_days" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="MWF or TR" />
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-time">Meeting time</label>
          <input id="ns-time" name="meeting_time" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="10:00-11:15" pattern="\d{1,2}:\d{2}-\d{1,2}:\d{2}" title="Use format like 10:00-11:15" />
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-room">Room</label>
          <input id="ns-room" name="room" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="204" />
        </div>
        <div>
          <label class="<?= htmlspecialchars(ui_label()) ?>" for="ns-cap">Capacity</label>
          <input id="ns-cap" name="capacity" type="number" min="1" max="999" value="30" required class="<?= htmlspecialchars(ui_input()) ?>" />
        </div>
      </div>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?>" <?= $catalog_courses === [] ? 'disabled' : '' ?>>Create section</button>
        <p class="text-xs text-slate-500 dark:text-slate-400">Time format <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">HH:MM-HH:MM</code>. Conflicts checked against same instructor or room in this term.</p>
      </div>
    </form>
  </details>
<?php endif; ?>

<div class="mt-6 <?= htmlspecialchars(ui_card()) ?>">
  <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between">
    <div class="text-sm font-semibold text-slate-800 dark:text-slate-100">
      Sections
      <?php if ($course_sections_total > 0): ?>
        <span class="font-normal text-slate-500 dark:text-slate-400">· <?= (int)$course_sections_total ?> total · rows <?= (int)$from ?>–<?= (int)$to ?></span>
      <?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
      <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Pagination">
        <?php if ($page > 1): ?>
          <a class="rounded-lg border border-slate-200 px-2.5 py-1 font-semibold text-indigo-700 hover:bg-slate-50 dark:border-slate-600 dark:text-indigo-300 dark:hover:bg-slate-800" href="<?= htmlspecialchars($pagerUrl($page - 1)) ?>">Prev</a>
        <?php endif; ?>
        <span class="px-2 text-slate-600 dark:text-slate-400">Page <?= (int)$page ?> / <?= (int)$total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <a class="rounded-lg border border-slate-200 px-2.5 py-1 font-semibold text-indigo-700 hover:bg-slate-50 dark:border-slate-600 dark:text-indigo-300 dark:hover:bg-slate-800" href="<?= htmlspecialchars($pagerUrl($page + 1)) ?>">Next</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-left text-sm">
      <thead class="<?= htmlspecialchars(ui_thead()) ?>">
        <tr>
          <th class="px-4 py-3">Section</th>
          <th class="px-4 py-3">Course</th>
          <th class="px-4 py-3">Credits</th>
          <th class="px-4 py-3">Instructor</th>
          <th class="px-4 py-3">Schedule</th>
          <th class="px-4 py-3">Room</th>
          <th class="px-4 py-3 text-right">Enrolled</th>
          <th class="px-4 py-3 text-right">Cap</th>
          <?php if (!empty($isAdmin)): ?>
            <th class="px-4 py-3 text-right">Actions</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
        <?php if ($terms === []): ?>
          <tr><td class="px-4 py-6 <?= htmlspecialchars(ui_muted()) ?>" colspan="<?= !empty($isAdmin) ? 9 : 8 ?>">No terms configured yet.</td></tr>
        <?php elseif ($course_sections === []): ?>
          <tr>
            <td class="px-4 py-6 <?= htmlspecialchars(ui_muted()) ?>" colspan="<?= !empty($isAdmin) ? 9 : 8 ?>">
              No sections match these filters.
              <?php if (!empty($isAdmin)): ?>
                <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=catalog')) ?>">Add a catalog course</a>
                or open Add new section above.
              <?php endif; ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($course_sections as $r): ?>
            <?php
            $cidRow = (string)($r['course_id'] ?? '');
            $secRow = (int)($r['section_id'] ?? 0);
            $isEditing = !empty($isAdmin) && $edit_section_id !== null && $secRow === $edit_section_id;
            $facIdRow = isset($r['faculty_id']) && $r['faculty_id'] !== null && $r['faculty_id'] !== ''
                ? (int)$r['faculty_id']
                : null;
            $termIdRow = (int)($r['term_id'] ?? 0);
            ?>
            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/50<?= ($highlight_section !== null && $secRow === $highlight_section) || $isEditing ? ' bg-emerald-50/80 ring-1 ring-inset ring-emerald-300 dark:bg-emerald-950/40 dark:ring-emerald-700' : '' ?>">
              <td class="px-4 py-3 font-mono text-xs font-semibold">
                <a class="text-indigo-700 hover:text-indigo-900 hover:underline dark:text-indigo-300" href="<?= htmlspecialchars($courseDetailHref($cidRow, $secRow > 0 ? $secRow : null)) ?>"><?= $secRow ?></a>
              </td>
              <td class="px-4 py-3">
                <a class="block rounded-lg outline-none ring-indigo-600/0 hover:bg-indigo-50/80 hover:ring-1 hover:ring-indigo-200 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-indigo-950/40 dark:hover:ring-indigo-800" href="<?= htmlspecialchars($courseDetailHref($cidRow)) ?>">
                  <span class="font-semibold text-indigo-900 dark:text-indigo-200"><?= htmlspecialchars($cidRow) ?></span>
                  <span class="block text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)($r['course_name'] ?? '')) ?></span>
                </a>
              </td>
              <td class="px-4 py-3 tabular-nums"><?= htmlspecialchars((string)($r['credits'] ?? '—')) ?></td>
              <td class="px-4 py-3"><?= $fmtInstr($r['fac_first'] ?? null, $r['fac_last'] ?? null) ?></td>
              <td class="px-4 py-3 text-slate-700 dark:text-slate-300">
                <?= htmlspecialchars(trim((string)($r['meeting_days'] ?? ''))) ?>
                <?php if (trim((string)($r['meeting_time'] ?? '')) !== ''): ?>
                  <span class="text-slate-500 dark:text-slate-400"><?= htmlspecialchars(trim((string)($r['meeting_time'] ?? ''))) ?></span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3"><?= htmlspecialchars(trim((string)($r['room'] ?? '')) !== '' ? (string)$r['room'] : '—') ?></td>
              <td class="px-4 py-3 text-right tabular-nums"><?= (int)($r['enrolled_count'] ?? 0) ?></td>
              <td class="px-4 py-3 text-right tabular-nums"><?= htmlspecialchars((string)($r['capacity'] ?? '—')) ?></td>
              <?php if (!empty($isAdmin)): ?>
                <td class="px-4 py-3 text-right">
                  <?php if ($isEditing): ?>
                    <a class="text-xs font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="<?= htmlspecialchars($coursesPageUrl(null)) ?>">Cancel</a>
                  <?php else: ?>
                    <a class="text-xs font-semibold text-indigo-700 hover:text-indigo-900 hover:underline dark:text-indigo-300" href="<?= htmlspecialchars($coursesPageUrl($secRow)) ?>">Edit</a>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
            <?php if ($isEditing): ?>
              <tr class="bg-indigo-50/50 dark:bg-indigo-950/20">
                <td colspan="<?= !empty($isAdmin) ? 9 : 8 ?>" class="px-4 py-4">
                  <form method="post" action="<?= htmlspecialchars(url('/admin.php?view=courses' . ($term_id !== null ? '&term_id=' . $term_id : ''))) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? csrf_token()) ?>" />
                    <input type="hidden" name="action" value="section_update" />
                    <input type="hidden" name="section_id" value="<?= (int)$secRow ?>" />
                    <input type="hidden" name="term_id" value="<?= (int)$termIdRow ?>" />
                    <div class="mb-3 text-sm font-semibold text-indigo-950 dark:text-indigo-100">
                      Edit section #<?= (int)$secRow ?> · <?= htmlspecialchars($cidRow) ?>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                      <div>
                        <label class="<?= htmlspecialchars(ui_label()) ?>" for="es-faculty-<?= (int)$secRow ?>">Instructor</label>
                        <select id="es-faculty-<?= (int)$secRow ?>" name="faculty_id" class="<?= htmlspecialchars(ui_select()) ?>">
                          <option value="" <?= $facIdRow === null ? 'selected' : '' ?>>Unassigned</option>
                          <?php foreach ($faculty_rows as $f): $fid = (int)($f['faculty_id'] ?? 0); ?>
                            <option value="<?= (int)$fid ?>" <?= $facIdRow !== null && $fid === $facIdRow ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)($f['last_name'] ?? '') . ', ' . (string)($f['first_name'] ?? ''))) ?> (<?= (int)$fid ?>)</option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div>
                        <label class="<?= htmlspecialchars(ui_label()) ?>" for="es-days-<?= (int)$secRow ?>">Meeting days</label>
                        <input id="es-days-<?= (int)$secRow ?>" name="meeting_days" value="<?= htmlspecialchars(trim((string)($r['meeting_days'] ?? ''))) ?>" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="MWF or TR" />
                      </div>
                      <div>
                        <label class="<?= htmlspecialchars(ui_label()) ?>" for="es-time-<?= (int)$secRow ?>">Meeting time</label>
                        <input id="es-time-<?= (int)$secRow ?>" name="meeting_time" value="<?= htmlspecialchars(trim((string)($r['meeting_time'] ?? ''))) ?>" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="10:00-11:15" pattern="\d{1,2}:\d{2}-\d{1,2}:\d{2}" title="Use format like 10:00-11:15" />
                      </div>
                      <div>
                        <label class="<?= htmlspecialchars(ui_label()) ?>" for="es-room-<?= (int)$secRow ?>">Room</label>
                        <input id="es-room-<?= (int)$secRow ?>" name="room" value="<?= htmlspecialchars(trim((string)($r['room'] ?? ''))) ?>" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="204" />
                      </div>
                      <div>
                        <label class="<?= htmlspecialchars(ui_label()) ?>" for="es-cap-<?= (int)$secRow ?>">Capacity</label>
                        <input id="es-cap-<?= (int)$secRow ?>" name="capacity" type="number" min="1" max="999" value="<?= (int)($r['capacity'] ?? 30) ?>" required class="<?= htmlspecialchars(ui_input()) ?>" />
                      </div>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                      <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?>">Save section</button>
                      <a class="text-sm font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white" href="<?= htmlspecialchars($coursesPageUrl(null)) ?>">Cancel</a>
                      <p class="text-xs text-slate-500 dark:text-slate-400">Assign instructor later or update schedule. Conflicts checked against same instructor or room in this term.</p>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
