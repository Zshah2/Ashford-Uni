<?php
/** @var list<array<string, mixed>> $departments */

$departments = $departments ?? [];
$deptCount = count($departments);
$createdDeptId = strtoupper(trim((string)($_GET['created'] ?? '')));
$flashMsg = trim((string)($_GET['msg'] ?? ''));
$openAddDept = in_array($flashMsg, ['dept_invalid', 'dept_exists'], true);

$fmtName = static function (?string $first, ?string $last): string {
    $f = trim((string)$first);
    $l = trim((string)$last);
    if ($f === '' && $l === '') {
        return '';
    }

    return trim($f . ' ' . $l);
};

$fmtLocation = static function (array $d): string {
    $building = trim((string)($d['building_number'] ?? ''));
    $room = trim((string)($d['room_number'] ?? ''));
    if ($building === '' && $room === '') {
        return '';
    }
    if ($building !== '' && $room !== '') {
        return 'Building ' . $building . ' · Room ' . $room;
    }

    return $building !== '' ? 'Building ' . $building : 'Room ' . $room;
};
?>
<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Departments</h1>
    <p class="mt-2 <?= htmlspecialchars(ui_muted()) ?>">
      Academic units in the catalog. Open a department for faculty, declared students, and courses.
    </p>
  </div>
  <div class="inline-flex items-center gap-2 self-start rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-900 ring-1 ring-indigo-200 dark:bg-indigo-950/50 dark:text-indigo-100 dark:ring-indigo-800">
    <span class="tabular-nums"><?= $deptCount ?></span>
    department<?= $deptCount === 1 ? '' : 's' ?>
  </div>
</div>

<?php if (!empty($isAdmin)): ?>
  <details class="group mt-6 rounded-2xl border border-indigo-200 bg-indigo-50/40 shadow-sm open:bg-white dark:border-indigo-900 dark:bg-indigo-950/30 dark:open:bg-slate-900"<?= $openAddDept ? ' open' : '' ?>>
    <summary class="cursor-pointer list-none px-5 py-4 text-sm font-semibold text-indigo-950 dark:text-indigo-100">
      <span class="inline-flex items-center gap-2">
        <span class="rounded-lg bg-indigo-600 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-white">Admin</span>
        Add department
      </span>
      <span class="mt-1 block text-xs font-normal text-indigo-900/80 dark:text-indigo-200/80">Create a new academic unit · assign a chair from the department page after saving</span>
    </summary>
    <form class="grid gap-4 border-t border-indigo-200/80 px-5 py-5 md:grid-cols-2 dark:border-indigo-900/80" method="post" action="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? csrf_token()) ?>" />
      <input type="hidden" name="action" value="department_save" />
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-id-new">Department code</label>
        <input id="dept-id-new" name="dept_id" class="<?= htmlspecialchars(ui_input('font-mono uppercase')) ?>" placeholder="ART" maxlength="10" pattern="[A-Za-z0-9]+" required />
        <p class="mt-1 text-xs text-slate-500">Short unique ID, e.g. COM or ENGL (max 10 characters).</p>
      </div>
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-name-new">Department name</label>
        <input id="dept-name-new" name="dept_name" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="Visual Arts" required />
      </div>
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-building-new">Building</label>
        <input id="dept-building-new" name="building_number" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="Lib" />
      </div>
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-room-new">Room</label>
        <input id="dept-room-new" name="room_number" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="1106" />
      </div>
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-email-new">Email</label>
        <input id="dept-email-new" name="email" type="email" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="art@ashford.edu" />
      </div>
      <div>
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-phone-new">Phone</label>
        <input id="dept-phone-new" name="phone_number" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="(516) 867-4800" />
      </div>
      <div class="md:col-span-2">
        <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-assistant-new">Department assistant</label>
        <input id="dept-assistant-new" name="dept_assistant" class="<?= htmlspecialchars(ui_input()) ?>" placeholder="Optional contact name" />
      </div>
      <div class="md:col-span-2">
        <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?>">Create department</button>
      </div>
    </form>
  </details>
<?php endif; ?>

<?php if ($departments === []): ?>
  <div class="mt-6 <?= htmlspecialchars(ui_empty()) ?>">
    <p class="font-semibold text-slate-800 dark:text-slate-200">No departments found</p>
    <p class="mt-1">Import or add department rows to populate this directory.</p>
  </div>
<?php else: ?>
  <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($departments as $d): ?>
      <?php
        $did = (string)($d['dept_id'] ?? '');
        $deptLink = url('/admin.php?' . http_build_query([
            'view' => 'department',
            'dept_id' => $did,
        ]));
        $name = (string)($d['dept_name'] ?? '');
        $chairName = $fmtName((string)($d['chair_first'] ?? ''), (string)($d['chair_last'] ?? ''));
        $location = $fmtLocation($d);
        $facultyN = (int)($d['faculty_count'] ?? 0);
        $studentN = (int)($d['student_count'] ?? 0);
        $courseN = (int)($d['course_count'] ?? 0);
        $isCreated = $createdDeptId !== '' && strtoupper($did) === $createdDeptId;
        $cardClass = $isCreated
            ? 'group flex flex-col rounded-2xl border border-indigo-400 bg-indigo-50/70 p-5 shadow-md ring-2 ring-indigo-200 transition hover:-translate-y-0.5 hover:border-indigo-500 hover:shadow-lg dark:border-indigo-500 dark:bg-indigo-950/40 dark:ring-indigo-500/40'
            : 'group flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md hover:ring-1 hover:ring-indigo-200/80 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-indigo-500/60 dark:hover:ring-indigo-500/30';
      ?>
      <a
        id="dept-<?= htmlspecialchars($did) ?>"
        class="<?= $cardClass ?>"
        href="<?= htmlspecialchars($deptLink) ?>"
      >
        <div class="flex items-start gap-3">
          <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 font-mono text-xs font-bold tracking-wide text-indigo-800 ring-1 ring-indigo-200 group-hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-100 dark:ring-indigo-800">
            <?= htmlspecialchars($did) ?>
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-slate-900 group-hover:text-indigo-800 dark:text-white dark:group-hover:text-indigo-200">
              <?= htmlspecialchars($name !== '' ? $name : $did) ?>
            </h2>
            <?php if ($location !== ''): ?>
              <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400"><?= htmlspecialchars($location) ?></p>
            <?php endif; ?>
          </div>
          <span class="mt-1 shrink-0 text-slate-300 transition group-hover:text-indigo-500 dark:text-slate-600 dark:group-hover:text-indigo-300" aria-hidden="true">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>
          </span>
        </div>

        <p class="mt-4 truncate text-sm text-slate-600 dark:text-slate-300">
          <?php if ($chairName !== ''): ?>
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Chair</span>
            <span class="mt-0.5 block truncate font-medium text-slate-800 dark:text-slate-100"><?= htmlspecialchars($chairName) ?></span>
          <?php else: ?>
            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Chair</span>
            <span class="mt-0.5 block text-slate-500 dark:text-slate-400">No chair assigned</span>
          <?php endif; ?>
        </p>

        <dl class="mt-4 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
          <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Faculty</dt>
            <dd class="mt-0.5 text-sm font-semibold tabular-nums text-slate-900 dark:text-white"><?= $facultyN ?></dd>
          </div>
          <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Students</dt>
            <dd class="mt-0.5 text-sm font-semibold tabular-nums text-slate-900 dark:text-white"><?= $studentN ?></dd>
          </div>
          <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Courses</dt>
            <dd class="mt-0.5 text-sm font-semibold tabular-nums text-slate-900 dark:text-white"><?= $courseN ?></dd>
          </div>
        </dl>
        <p class="mt-3 text-xs font-semibold text-indigo-700 group-hover:text-indigo-900 dark:text-indigo-300 dark:group-hover:text-indigo-200">
          <?= $isCreated ? 'Just created · Open department →' : 'Open department →' ?>
        </p>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($createdDeptId !== ''): ?>
    <script>
      (function () {
        var el = document.getElementById('dept-<?= htmlspecialchars($createdDeptId, ENT_QUOTES) ?>');
        if (el && el.scrollIntoView) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      })();
    </script>
  <?php endif; ?>
<?php endif; ?>
