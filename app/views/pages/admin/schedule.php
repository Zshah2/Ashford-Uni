<?php
/** @var array $app */
/** @var array<string, bool> $schedule_panels */
/** @var bool $schedule_embed_preservation */
/** @var string $search_q */
/** @var string $course_q */
/** @var string $catalog_dept */
/** @var string $sec_q */
/** @var list<string>|array $valid_dept_ids_for_select */
/** @var array $student_rows */
/** @var array $faculty_rows */
/** @var array $terms */
/** @var int|null $term_id */
/** @var string $dept_id */
/** @var array $sections */
/** @var array $dept_rows */
/** @var array $course_rows */
/** @var int $student_total */
/** @var int $faculty_total */
/** @var int $stu_page */
/** @var int $fac_page */
/** @var int $schedule_per_page */
/** @var bool $schedule_unified_roster */
/** @var array $roster_rows */
/** @var int $roster_total */
$schedule_panels = $schedule_panels ?? [];
$schedule_embed_preservation = $schedule_embed_preservation ?? false;
$search_q = $search_q ?? '';
$course_q = $course_q ?? '';
$catalog_dept = $catalog_dept ?? '';
$sec_q = $sec_q ?? '';
$student_rows = $student_rows ?? [];
$faculty_rows = $faculty_rows ?? [];
$dept_rows = $dept_rows ?? [];
$course_rows = $course_rows ?? [];
$terms = $terms ?? [];
$sections = $sections ?? [];
$valid_dept_ids_for_select = $valid_dept_ids_for_select ?? [];
$student_total = (int)($student_total ?? 0);
$faculty_total = (int)($faculty_total ?? 0);
$stu_page = max(1, (int)($stu_page ?? 1));
$fac_page = max(1, (int)($fac_page ?? 1));
$schedule_per_page = (int)($schedule_per_page ?? 50);
if (!in_array($schedule_per_page, [25, 50, 100, 200], true)) {
    $schedule_per_page = 50;
}
$roster_rows = $roster_rows ?? [];
$roster_total = (int)($roster_total ?? 0);
$schedule_unified_roster = !empty($schedule_unified_roster);
/** GET form target: routed URL, or admin.php?view=schedule when embedded in public/admin.php */
$schedule_form_action = isset($schedule_form_action) && is_string($schedule_form_action) && $schedule_form_action !== ''
    ? $schedule_form_action
    : url('/admin/schedule');
$schedule_reset_href = str_contains((string)$schedule_form_action, 'admin.php')
    ? url('/admin.php?view=schedule')
    : url('/admin/schedule');
/** GET forms must send view=schedule as a field; browsers often strip ?view= from action on submit */
$schedule_needs_view_hidden = str_contains((string)$schedule_form_action, 'admin.php');
$schedule_embedded = $schedule_needs_view_hidden;
$schedule_href_people = $schedule_embedded
    ? url('/admin.php?view=people')
    : url('/admin/students/search');
$schedule_href_student_record = static function (int $studentId) use ($schedule_embedded): string {
    return $schedule_embedded
        ? url('/admin.php?view=people&id=' . $studentId)
        : url('/admin/students/show?student_id=' . $studentId);
};
$schedule_href_faculty_person = static function (int $facultyId): string {
    return url('/admin.php?view=people&id=' . $facultyId);
};
$schedule_href_add_person = url('/admin.php?view=people#add-person');
/** Linked ID styling: sky = student, violet = faculty (matches admin ID lookup badges). */
$schedule_id_link_student = 'inline-flex rounded-md bg-sky-100 px-2 py-0.5 font-mono text-sm font-semibold tabular-nums text-sky-950 ring-1 ring-inset ring-sky-200/90 hover:bg-sky-200/90';
$schedule_id_link_faculty = 'inline-flex rounded-md bg-violet-100 px-2 py-0.5 font-mono text-sm font-semibold tabular-nums text-violet-950 ring-1 ring-inset ring-violet-200/90 hover:bg-violet-200/90';
$fmtContact = static function ($v): string {
    $s = trim((string)($v ?? ''));

    return $s === '' ? '—' : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$telHref = static function (string $display): string {
    $digits = preg_replace('/\D+/', '', $display);
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }

    return $digits !== '' ? $digits : preg_replace('/\s+/', '', $display);
};
$schedulePagerHref = static function (int $stuP, int $facP) use (
    $schedule_form_action,
    $schedule_needs_view_hidden,
    $schedule_panels,
    $search_q,
    $course_q,
    $catalog_dept,
    $term_id,
    $dept_id,
    $sec_q,
    $schedule_per_page,
    $schedule_unified_roster
): string {
    $q = [];
    if (!empty($schedule_needs_view_hidden)) {
        $q['view'] = 'schedule';
    }
    $q['sched_filter'] = '1';
    foreach ($schedule_panels as $pk => $on) {
        if ($on) {
            $q['panels[' . $pk . ']'] = '1';
        }
    }
    if ($search_q !== '') {
        $q['q'] = $search_q;
    }
    if ($course_q !== '') {
        $q['course_q'] = $course_q;
    }
    if ($catalog_dept !== '') {
        $q['catalog_dept'] = $catalog_dept;
    }
    if (($term_id ?? null) !== null) {
        $q['term_id'] = (string)(int)$term_id;
    }
    if (($dept_id ?? '') !== '') {
        $q['dept_id'] = $dept_id;
    }
    if (($sec_q ?? '') !== '') {
        $q['sec_q'] = $sec_q;
    }
    $q['per_page'] = (string)$schedule_per_page;
    $q['stu_page'] = (string)$stuP;
    $q['fac_page'] = $schedule_unified_roster ? '1' : (string)$facP;
    $sep = str_contains($schedule_form_action, '?') ? '&' : '?';

    return $schedule_form_action . $sep . http_build_query($q);
};
/** @return list<int|null> page numbers; null = ellipsis gap */
$schedulePaginationPages = static function (int $current, int $last): array {
    if ($last <= 1) {
        return [];
    }
    if ($last <= 11) {
        return range(1, $last);
    }
    $out = [1];
    $start = max(2, $current - 2);
    $end = min($last - 1, $current + 2);
    if ($start > 2) {
        $out[] = null;
    }
    for ($i = $start; $i <= $end; $i++) {
        $out[] = $i;
    }
    if ($end < $last - 1) {
        $out[] = null;
    }
    $out[] = $last;

    return $out;
};
?>

<h1 class="<?= htmlspecialchars(ui_h1()) ?>">Master schedule</h1>
<p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">
  A full directory of <strong class="font-semibold text-slate-800 dark:text-slate-200">students and faculty</strong>. Search by ID, name, email, or phone. Row IDs link to the person’s record.
</p>

<details class="group mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4 shadow-sm open:ring-1 open:ring-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/30 dark:open:ring-indigo-900">
  <summary class="cursor-pointer list-none text-sm font-semibold text-indigo-950 dark:text-indigo-100 [&::-webkit-details-marker]:hidden">
    Quick links
  </summary>
  <div class="mt-4 flex flex-wrap gap-2">
    <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin/students/search')) ?>">ID lookup</a>
    <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin')) ?>">Dashboard</a>
    <a class="<?= htmlspecialchars(ui_btn_secondary()) ?>" href="<?= htmlspecialchars(url('/admin/holds')) ?>">Holds</a>
  </div>
</details>

<div id="schedule-filters-wrap" class="mt-6 space-y-3">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div class="text-sm font-semibold text-slate-900">Filters</div>
    <div class="flex items-center gap-2">
      <button id="schedule-filters-hide" type="button" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Hide filters</button>
      <a href="<?= htmlspecialchars($schedule_reset_href) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Reset</a>
    </div>
  </div>

  <form id="schedule-filters-form" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" method="get" action="<?= htmlspecialchars($schedule_form_action) ?>">
  <?php if (!empty($schedule_needs_view_hidden)): ?>
    <input type="hidden" name="view" value="schedule" />
  <?php endif; ?>
  <input type="hidden" name="sched_filter" value="1" />

  <div class="grid gap-4 sm:grid-cols-2">
    <div class="min-w-0">
      <label class="block text-xs font-semibold uppercase text-slate-500" for="schedule-q">People search (students &amp; faculty)</label>
      <input
        type="search"
        id="schedule-q"
        name="q"
        value="<?= htmlspecialchars($search_q) ?>"
        placeholder="ID, name, email, or phone"
        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm"
        autocomplete="off"
      />
    </div>
    <div class="flex items-end gap-4">
      <div>
        <label class="block text-xs font-semibold uppercase text-slate-500" for="schedule-per-page">Rows per page</label>
        <select id="schedule-per-page" name="per_page" class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm">
          <?php foreach ([25, 50, 100, 200] as $pp): ?>
            <option value="<?= (int)$pp ?>"<?= $schedule_per_page === $pp ? ' selected' : '' ?>><?= (int)$pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pb-0.5 text-xs text-slate-500">Applies to the roster list.</div>
    </div>
  </div>

  <div class="flex flex-wrap items-center gap-4">
    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
      <input type="checkbox" name="panels[students]" value="1" <?= !empty($schedule_panels['students']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
      Students
    </label>
    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
      <input type="checkbox" name="panels[faculty]" value="1" <?= !empty($schedule_panels['faculty']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
      Faculty
    </label>
  </div>

  <input type="hidden" name="stu_page" value="1" />
  <input type="hidden" name="fac_page" value="1" />

  <div class="flex flex-wrap items-center gap-3">
    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" type="submit">Apply filters</button>
    <a href="<?= htmlspecialchars($schedule_reset_href) ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Reset everything</a>
    <a href="<?= htmlspecialchars($schedule_href_people) ?>" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-900 hover:bg-indigo-100">Update information</a>
    <a href="<?= htmlspecialchars($schedule_href_add_person) ?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100">Add person</a>
  </div>
</form>

  <button id="schedule-filters-fab" type="button" class="fixed bottom-5 right-5 z-40 hidden rounded-full bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
    Filters
  </button>
</div>

<script>
(function () {
  var wrap = document.getElementById('schedule-filters-wrap');
  var form = document.getElementById('schedule-filters-form');
  var hideBtn = document.getElementById('schedule-filters-hide');
  var fab = document.getElementById('schedule-filters-fab');
  var focusInput = document.getElementById('schedule-q');
  if (!wrap || !form || !hideBtn || !fab) return;

  function setHidden(on) {
    form.classList.toggle('hidden', on);
    fab.classList.toggle('hidden', !on);
    hideBtn.textContent = on ? 'Show filters' : 'Hide filters';
    try { localStorage.setItem('schedule_filters_hidden', on ? '1' : '0'); } catch (e) {}
  }

  hideBtn.addEventListener('click', function () {
    var nowHidden = !form.classList.contains('hidden');
    setHidden(nowHidden);
    if (!nowHidden && focusInput) {
      focusInput.focus();
    }
  });

  fab.addEventListener('click', function () {
    setHidden(false);
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(function () { if (focusInput) focusInput.focus(); }, 250);
  });

  try {
    var saved = localStorage.getItem('schedule_filters_hidden');
    if (saved === '1') setHidden(true);
  } catch (e) {}
})();
</script>

<?php if ($schedule_unified_roster): ?>
  <?php
    $roster_page_count = max(1, (int)ceil($roster_total / max(1, $schedule_per_page)));
    $roster_row_from = $roster_total === 0 ? 0 : (($stu_page - 1) * $schedule_per_page + 1);
    $roster_row_to = min($roster_total, $stu_page * $schedule_per_page);
    $rosterPagesList = $schedulePaginationPages($stu_page, $roster_page_count);
    $rosterFacNameCounts = [];
    foreach ($roster_rows as $rr) {
        if (($rr['roster_kind'] ?? '') !== 'Faculty') {
            continue;
        }
        $ln = strtolower(trim((string)($rr['last_name'] ?? '')));
        $fn = strtolower(trim((string)($rr['first_name'] ?? '')));
        $key = $ln . '|' . $fn;
        if ($ln === '' || $fn === '') {
            continue;
        }
        $rosterFacNameCounts[$key] = ($rosterFacNameCounts[$key] ?? 0) + 1;
    }
  ?>
  <div class="mt-10 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
    <h2 class="text-lg font-semibold text-slate-900">
      People (students + faculty)
      <span class="block text-sm font-normal text-slate-500 sm:inline sm:pl-1">
        <?= (int)$roster_total ?> total<?php if ($roster_total > 0): ?> · rows <?= (int)$roster_row_from ?>–<?= (int)$roster_row_to ?><?php endif; ?>
      </span>
    </h2>
    <?php if ($roster_total > $schedule_per_page): ?>
      <nav class="flex max-w-full flex-col gap-2 text-sm" aria-label="Roster pagination">
        <div class="-mx-1 flex max-w-full items-center gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
          <?php if ($stu_page > 1): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page - 1, 1)) ?>">Prev</a>
          <?php endif; ?>
          <?php foreach ($rosterPagesList as $pn): ?>
            <?php if ($pn === null): ?>
              <span class="shrink-0 px-1 text-slate-400 select-none" aria-hidden="true">…</span>
            <?php elseif ((int)$pn === (int)$stu_page): ?>
              <span class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg bg-indigo-600 px-2 text-sm font-bold text-white shadow-sm" aria-current="page"><?= (int)$pn ?></span>
            <?php else: ?>
              <a class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg border border-slate-200 bg-white px-2 font-semibold text-indigo-800 shadow-sm hover:border-indigo-200 hover:bg-indigo-50" href="<?= htmlspecialchars($schedulePagerHref((int)$pn, 1)) ?>"><?= (int)$pn ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($stu_page < $roster_page_count): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page + 1, 1)) ?>">Next</a>
          <?php endif; ?>
        </div>
        <span class="text-xs text-slate-500">Page <?= (int)$stu_page ?> of <?= (int)$roster_page_count ?> · scroll numbers on small screens</span>
      </nav>
    <?php endif; ?>
  </div>

  <div class="mt-3 w-full min-w-0 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
    <table class="min-w-[96rem] w-full text-left text-sm">
      <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
        <tr>
          <th class="px-4 py-3">Role</th>
          <th class="px-4 py-3">ID</th>
          <th class="px-4 py-3">Last name</th>
          <th class="px-4 py-3">First name</th>
          <th class="px-4 py-3">Departments</th>
          <th class="px-4 py-3">Address</th>
          <th class="px-4 py-3">Email</th>
          <th class="px-4 py-3">Phone</th>
          <th class="px-4 py-3">Office</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($roster_rows as $r): ?>
          <?php
            $isFac = (($r['roster_kind'] ?? '') === 'Faculty');
            $uid = (int)($r['user_id'] ?? 0);
            $idHref = $isFac ? $schedule_href_faculty_person($uid) : $schedule_href_student_record($uid);
          ?>
          <tr class="hover:bg-slate-50/80">
            <td class="px-4 py-3 text-xs font-semibold uppercase tracking-wide <?= $isFac ? 'text-violet-700' : 'text-sky-800' ?>">
              <?= htmlspecialchars((string)($r['roster_kind'] ?? '')) ?>
            </td>
            <td class="px-4 py-3">
              <a class="<?= $isFac ? htmlspecialchars($schedule_id_link_faculty, ENT_QUOTES, 'UTF-8') : htmlspecialchars($schedule_id_link_student, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($idHref) ?>"><?= $uid ?></a>
            </td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)($r['last_name'] ?? '')) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)($r['first_name'] ?? '')) ?></td>
            <td class="px-4 py-3 max-w-[24rem] truncate text-slate-700">
              <?php $dl = trim((string)($r['dept_list'] ?? '')); ?>
              <?= $dl !== '' ? htmlspecialchars($dl) : '—' ?>
            </td>
            <td class="px-4 py-3 max-w-[26rem] truncate text-slate-600">
              <?php
                $apt = trim((string)($r['apt_no'] ?? ''));
                $street = trim((string)($r['street'] ?? ''));
                $city = trim((string)($r['city'] ?? ''));
                $state = trim((string)($r['state'] ?? ''));
                $zip = trim((string)($r['zip_code'] ?? ''));
                $parts = [];
                $line1 = trim(($apt !== '' ? ($apt . ' ') : '') . $street);
                if ($line1 !== '') $parts[] = $line1;
                $line2 = trim($city . ($state !== '' ? (', ' . $state) : '') . ($zip !== '' ? (' ' . $zip) : ''));
                if ($line2 !== '') $parts[] = $line2;
                echo $parts ? htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8') : '—';
              ?>
            </td>
            <td class="px-4 py-3 max-w-[14rem] truncate text-slate-700">
              <?php $em = trim((string)($r['email'] ?? '')); ?>
              <?php if ($em !== ''): ?>
                <a class="text-indigo-700 hover:underline" href="mailto:<?= htmlspecialchars($em, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($em) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-slate-700">
              <?php $ph = trim((string)($r['phone_number'] ?? '')); ?>
              <?php if ($ph !== ''): ?>
                <a class="hover:underline" href="tel:<?= htmlspecialchars($telHref($ph), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ph) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-slate-600"><?php
              if (!$isFac) {
                  echo '—';
              } else {
                  $off = trim((string)($r['office_number'] ?? ''));
                  echo $off !== '' ? htmlspecialchars($off) : '—';
              }
            ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$roster_rows): ?>
          <tr>
            <td class="px-4 py-6 text-center text-slate-500" colspan="9">
              <?= $search_q !== '' ? 'No people match this search.' : 'No people rows in the database yet.' ?>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!$schedule_unified_roster && !empty($schedule_panels['students'])): ?>
  <?php
    $stu_page_count = max(1, (int)ceil($student_total / max(1, $schedule_per_page)));
    $stu_row_from = $student_total === 0 ? 0 : (($stu_page - 1) * $schedule_per_page + 1);
    $stu_row_to = min($student_total, $stu_page * $schedule_per_page);
  ?>
  <div class="mt-10 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
    <h2 class="text-lg font-semibold text-slate-900">
      Students
      <span class="block text-sm font-normal text-slate-500 sm:inline sm:pl-1">
        <?= (int)$student_total ?> total<?php if ($student_total > 0): ?> · rows <?= (int)$stu_row_from ?>–<?= (int)$stu_row_to ?><?php endif; ?>
      </span>
    </h2>
    <?php if ($student_total > $schedule_per_page): ?>
      <?php $stuPagesList = $schedulePaginationPages($stu_page, $stu_page_count); ?>
      <nav class="flex max-w-full flex-col gap-2 text-sm" aria-label="Students pagination">
        <div class="-mx-1 flex max-w-full items-center gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
          <?php if ($stu_page > 1): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page - 1, $fac_page)) ?>">Prev</a>
          <?php endif; ?>
          <?php foreach ($stuPagesList as $pn): ?>
            <?php if ($pn === null): ?>
              <span class="shrink-0 px-1 text-slate-400 select-none" aria-hidden="true">…</span>
            <?php elseif ((int)$pn === (int)$stu_page): ?>
              <span class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg bg-indigo-600 px-2 text-sm font-bold text-white shadow-sm" aria-current="page"><?= (int)$pn ?></span>
            <?php else: ?>
              <a class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg border border-slate-200 bg-white px-2 font-semibold text-indigo-800 shadow-sm hover:border-indigo-200 hover:bg-indigo-50" href="<?= htmlspecialchars($schedulePagerHref((int)$pn, $fac_page)) ?>"><?= (int)$pn ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($stu_page < $stu_page_count): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page + 1, $fac_page)) ?>">Next</a>
          <?php endif; ?>
        </div>
        <span class="text-xs text-slate-500">Page <?= (int)$stu_page ?> of <?= (int)$stu_page_count ?> · scroll numbers on small screens</span>
      </nav>
    <?php endif; ?>
  </div>
  <div class="mt-3 w-full min-w-0 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
    <table class="min-w-[110rem] w-full text-left text-sm">
      <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
        <tr>
          <th class="px-4 py-3">Student ID</th>
          <th class="px-4 py-3">Last name</th>
          <th class="px-4 py-3">First name</th>
          <th class="px-4 py-3">Middle</th>
          <th class="px-4 py-3">Type</th>
          <th class="px-4 py-3">Majors / minors</th>
          <th class="px-4 py-3">Address</th>
          <th class="px-4 py-3">Email</th>
          <th class="px-4 py-3">Phone</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($student_rows as $r): ?>
          <tr class="hover:bg-slate-50/80">
            <td class="px-4 py-3">
              <a class="<?= htmlspecialchars($schedule_id_link_student, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($schedule_href_student_record((int)$r['user_id'])) ?>"><?= (int)$r['user_id'] ?></a>
            </td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)$r['last_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)$r['first_name']) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($r['middle_name'] ?? '')) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$r['user_type']) ?></td>
            <td class="px-4 py-3 max-w-[26rem] truncate text-slate-700">
              <?php $dl = trim((string)($r['dept_list'] ?? '')); ?>
              <?= $dl !== '' ? htmlspecialchars($dl) : '—' ?>
            </td>
            <td class="px-4 py-3 max-w-[28rem] truncate text-slate-600">
              <?php
                $apt = trim((string)($r['apt_no'] ?? ''));
                $street = trim((string)($r['street'] ?? ''));
                $city = trim((string)($r['city'] ?? ''));
                $state = trim((string)($r['state'] ?? ''));
                $zip = trim((string)($r['zip_code'] ?? ''));
                $parts = [];
                $line1 = trim(($apt !== '' ? ($apt . ' ') : '') . $street);
                if ($line1 !== '') $parts[] = $line1;
                $line2 = trim($city . ($state !== '' ? (', ' . $state) : '') . ($zip !== '' ? (' ' . $zip) : ''));
                if ($line2 !== '') $parts[] = $line2;
                echo $parts ? htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8') : '—';
              ?>
            </td>
            <td class="px-4 py-3 max-w-[14rem] truncate text-slate-700">
              <?php $em = trim((string)($r['email'] ?? '')); ?>
              <?php if ($em !== ''): ?>
                <a class="text-indigo-700 hover:underline" href="mailto:<?= htmlspecialchars($em, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($em) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-slate-700">
              <?php $ph = trim((string)($r['phone_number'] ?? '')); ?>
              <?php if ($ph !== ''): ?>
                <a class="hover:underline" href="tel:<?= htmlspecialchars($telHref($ph), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ph) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$student_rows): ?>
          <tr>
            <td class="px-4 py-6 text-center text-slate-500" colspan="9">
              <?= $search_q !== '' ? 'No students match this search.' : 'No student rows in the database yet.' ?>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!$schedule_unified_roster && !empty($schedule_panels['faculty'])): ?>
  <?php
    $fac_page_count = max(1, (int)ceil($faculty_total / max(1, $schedule_per_page)));
    $fac_row_from = $faculty_total === 0 ? 0 : (($fac_page - 1) * $schedule_per_page + 1);
    $fac_row_to = min($faculty_total, $fac_page * $schedule_per_page);
    $facNameCounts = [];
    foreach ($faculty_rows as $fr) {
        $ln = strtolower(trim((string)($fr['last_name'] ?? '')));
        $fn = strtolower(trim((string)($fr['first_name'] ?? '')));
        if ($ln === '' || $fn === '') {
            continue;
        }
        $key = $ln . '|' . $fn;
        $facNameCounts[$key] = ($facNameCounts[$key] ?? 0) + 1;
    }
  ?>
  <div class="mt-10 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
    <h2 class="text-lg font-semibold text-slate-900">
      Faculty
      <span class="block text-sm font-normal text-slate-500 sm:inline sm:pl-1">
        <?= (int)$faculty_total ?> total<?php if ($faculty_total > 0): ?> · rows <?= (int)$fac_row_from ?>–<?= (int)$fac_row_to ?><?php endif; ?>
      </span>
    </h2>
    <?php if ($faculty_total > $schedule_per_page): ?>
      <?php $facPagesList = $schedulePaginationPages($fac_page, $fac_page_count); ?>
      <nav class="flex max-w-full flex-col gap-2 text-sm" aria-label="Faculty pagination">
        <div class="-mx-1 flex max-w-full items-center gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]">
          <?php if ($fac_page > 1): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page, $fac_page - 1)) ?>">Prev</a>
          <?php endif; ?>
          <?php foreach ($facPagesList as $pn): ?>
            <?php if ($pn === null): ?>
              <span class="shrink-0 px-1 text-slate-400 select-none" aria-hidden="true">…</span>
            <?php elseif ((int)$pn === (int)$fac_page): ?>
              <span class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg bg-indigo-600 px-2 text-sm font-bold text-white shadow-sm" aria-current="page"><?= (int)$pn ?></span>
            <?php else: ?>
              <a class="grid h-8 min-w-[2rem] shrink-0 place-items-center rounded-lg border border-slate-200 bg-white px-2 font-semibold text-indigo-800 shadow-sm hover:border-indigo-200 hover:bg-indigo-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page, (int)$pn)) ?>"><?= (int)$pn ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($fac_page < $fac_page_count): ?>
            <a class="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" href="<?= htmlspecialchars($schedulePagerHref($stu_page, $fac_page + 1)) ?>">Next</a>
          <?php endif; ?>
        </div>
        <span class="text-xs text-slate-500">Page <?= (int)$fac_page ?> of <?= (int)$fac_page_count ?> · scroll numbers on small screens</span>
      </nav>
    <?php endif; ?>
  </div>
  <div class="mt-3 w-full min-w-0 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
    <table class="min-w-[124rem] w-full text-left text-sm">
      <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase text-slate-500">
        <tr>
          <th class="px-4 py-3">Faculty ID</th>
          <th class="px-4 py-3">Last name</th>
          <th class="px-4 py-3">First name</th>
          <th class="px-4 py-3">Middle</th>
          <th class="px-4 py-3">Type</th>
          <th class="px-4 py-3">Departments</th>
          <th class="px-4 py-3">Address</th>
          <th class="px-4 py-3">Office</th>
          <th class="px-4 py-3">Rank</th>
          <th class="px-4 py-3">Faculty type</th>
          <th class="px-4 py-3">Email</th>
          <th class="px-4 py-3">Phone</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($faculty_rows as $r): ?>
          <tr class="hover:bg-slate-50/80">
            <td class="px-4 py-3">
              <a class="<?= htmlspecialchars($schedule_id_link_faculty, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($schedule_href_faculty_person((int)$r['faculty_id'])) ?>"><?= (int)$r['faculty_id'] ?></a>
            </td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)$r['last_name']) ?></td>
            <td class="px-4 py-3"><?= htmlspecialchars((string)($r['first_name'] ?? '')) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($r['middle_name'] ?? '')) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$r['user_type']) ?></td>
            <td class="px-4 py-3 max-w-[28rem] truncate text-slate-700">
              <?php $dl = trim((string)($r['dept_list'] ?? '')); ?>
              <?= $dl !== '' ? htmlspecialchars($dl) : '—' ?>
            </td>
            <td class="px-4 py-3 max-w-[30rem] truncate text-slate-600">
              <?php
                $apt = trim((string)($r['apt_no'] ?? ''));
                $street = trim((string)($r['street'] ?? ''));
                $city = trim((string)($r['city'] ?? ''));
                $state = trim((string)($r['state'] ?? ''));
                $zip = trim((string)($r['zip_code'] ?? ''));
                $parts = [];
                $line1 = trim(($apt !== '' ? ($apt . ' ') : '') . $street);
                if ($line1 !== '') $parts[] = $line1;
                $line2 = trim($city . ($state !== '' ? (', ' . $state) : '') . ($zip !== '' ? (' ' . $zip) : ''));
                if ($line2 !== '') $parts[] = $line2;
                echo $parts ? htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8') : '—';
              ?>
            </td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($r['office_number'] ?? '')) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($r['faculty_rank'] ?? '')) ?></td>
            <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($r['faculty_type'] ?? '')) ?></td>
            <td class="px-4 py-3 max-w-[14rem] truncate text-slate-700">
              <?php $em = trim((string)($r['email'] ?? '')); ?>
              <?php if ($em !== ''): ?>
                <a class="text-indigo-700 hover:underline" href="mailto:<?= htmlspecialchars($em, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($em) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-slate-700">
              <?php $ph = trim((string)($r['phone_number'] ?? '')); ?>
              <?php if ($ph !== ''): ?>
                <a class="hover:underline" href="tel:<?= htmlspecialchars($telHref($ph), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ph) ?></a>
              <?php else: ?>
                <span class="text-slate-400"><?= $fmtContact('') ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$faculty_rows): ?>
          <tr>
            <td class="px-4 py-6 text-center text-slate-500" colspan="12">
              <?= $search_q !== '' ? 'No faculty match this search.' : 'No faculty rows in the database yet.' ?>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php /* People-only view: terms/departments/courses/sections intentionally omitted. */ ?>
