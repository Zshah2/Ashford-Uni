<?php
/** @var string $dept_id_param */
/** @var array<string, mixed>|null $department */
/** @var list<array<string, mixed>> $faculty */
/** @var list<array<string, mixed>> $students */
/** @var int $student_count */
/** @var list<array<string, mixed>> $courses */

$dept_id_param = $dept_id_param ?? '';
$department = $department ?? null;
$faculty = $faculty ?? [];
$students = $students ?? [];
$student_count = (int)($student_count ?? 0);
$courses = $courses ?? [];

$peopleHref = static function (int $uid): string {
    return url('/admin.php?view=people&id=' . $uid);
};

$courseHref = static function (string $cid): string {
    return url('/admin.php?' . http_build_query(['view' => 'course', 'course_id' => $cid]));
};

$fmtName = static function (?string $first, ?string $last): string {
    $f = trim((string)$first);
    $l = trim((string)$last);
    if ($f === '' && $l === '') {
        return '—';
    }

    return htmlspecialchars(trim($f . ' ' . $l), ENT_QUOTES, 'UTF-8');
};

$fmtInitials = static function (?string $first, ?string $last): string {
    $f = strtoupper(substr(trim((string)$first), 0, 1));
    $l = strtoupper(substr(trim((string)$last), 0, 1));
    $s = $f . $l;

    return $s !== '' ? $s : '?';
};

$fmtDate = static function ($d): string {
    $s = trim((string)$d);
    if ($s === '') {
        return '—';
    }
    $ts = strtotime($s);

    return $ts !== false ? date('M j, Y', $ts) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$statChip = static function (string $label, int $value): string {
    return '<div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-700 dark:bg-slate-900">'
        . '<div class="text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">' . htmlspecialchars($label) . '</div>'
        . '<div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900 dark:text-white">' . $value . '</div>'
        . '</div>';
};
?>
<?php if ($department === null): ?>
  <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Department</h1>
  <div class="mt-6 <?= htmlspecialchars(ui_empty()) ?>">
    <?php if ($dept_id_param === ''): ?>
      <p class="font-semibold text-slate-800 dark:text-slate-200">No department was specified</p>
      <p class="mt-1">Choose a department from the directory to see faculty, students, and catalog courses.</p>
    <?php else: ?>
      <p class="font-semibold text-slate-800 dark:text-slate-200">Department not found</p>
      <p class="mt-1">No record for <span class="font-mono font-semibold"><?= htmlspecialchars($dept_id_param) ?></span>.</p>
    <?php endif; ?>
    <p class="mt-4">
      <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">← Back to Departments</a>
    </p>
  </div>
<?php else: ?>
  <?php
      $did = (string)$department['dept_id'];
      $deptName = (string)($department['dept_name'] ?? '');
      $room = trim((string)($department['room_number'] ?? ''));
      $building = trim((string)($department['building_number'] ?? ''));
      $locationParts = [];
      if ($building !== '') {
          $locationParts[] = 'Building ' . $building;
      }
      if ($room !== '') {
          $locationParts[] = 'Room ' . $room;
      }
      $location = implode(' · ', $locationParts);
      $chairId = isset($department['chair_id']) ? (int)$department['chair_id'] : 0;
      $facultyN = count($faculty);
      $courseN = count($courses);
      $email = trim((string)($department['email'] ?? ''));
      $phone = trim((string)($department['phone_number'] ?? ''));
      $coursesListHref = url('/admin.php?' . http_build_query(['view' => 'courses', 'dept_id' => $did]));
  ?>
  <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
    <a class="hover:text-indigo-700 dark:hover:text-indigo-300" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">Departments</a>
    <span class="mx-1.5 text-slate-300 dark:text-slate-600">/</span>
    <span class="font-mono text-slate-600 dark:text-slate-300"><?= htmlspecialchars($did) ?></span>
  </p>

  <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
    <div class="flex min-w-0 items-start gap-4">
      <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 font-mono text-sm font-bold tracking-wide text-white shadow-sm ring-1 ring-indigo-500/30 dark:bg-indigo-500">
        <?= htmlspecialchars($did) ?>
      </span>
      <div class="min-w-0">
        <h1 class="<?= htmlspecialchars(ui_h1()) ?>"><?= htmlspecialchars($deptName !== '' ? $deptName : $did) ?></h1>
        <?php if ($location !== ''): ?>
          <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-400"><?= htmlspecialchars($location) ?></p>
        <?php endif; ?>
        <?php if ($email !== '' || $phone !== ''): ?>
          <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600 dark:text-slate-400">
            <?php if ($email !== ''): ?>
              <a class="<?= htmlspecialchars(ui_link()) ?>" href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
            <?php endif; ?>
            <?php if ($phone !== ''): ?>
              <span><?= htmlspecialchars($phone) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
    <a class="<?= htmlspecialchars(ui_btn_secondary()) ?> shrink-0" href="<?= htmlspecialchars(url('/admin.php?view=departments')) ?>">All departments</a>
  </div>

  <div class="mt-6 grid gap-3 sm:grid-cols-3">
    <?= $statChip('Faculty', $facultyN) ?>
    <?= $statChip('Students declared', $student_count) ?>
    <?= $statChip('Catalog courses', $courseN) ?>
  </div>

  <div class="mt-6 <?= htmlspecialchars(ui_card('p-5')) ?>">
    <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Overview</h2>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Chair, office contact, and departmental assistant on file.</p>
    <dl class="mt-4 grid gap-4 sm:grid-cols-2">
      <div class="sm:col-span-2 rounded-xl border border-slate-100 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40">
        <dt class="<?= htmlspecialchars(ui_label()) ?>">Chair</dt>
        <dd class="mt-2">
          <?php if ($chairId > 0): ?>
            <div class="flex items-start gap-3">
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-bold text-indigo-800 ring-1 ring-indigo-200 dark:bg-indigo-950/70 dark:text-indigo-100 dark:ring-indigo-800">
                <?= htmlspecialchars($fmtInitials((string)($department['chair_first'] ?? ''), (string)($department['chair_last'] ?? ''))) ?>
              </span>
              <div class="min-w-0">
                <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars($peopleHref($chairId)) ?>">
                  <?= $fmtName((string)($department['chair_first'] ?? ''), (string)($department['chair_last'] ?? '')) ?>
                </a>
                <span class="ml-1 inline-flex rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">ID <?= $chairId ?></span>
                <?php if (!empty($department['chair_email'])): ?>
                  <div class="mt-0.5 text-xs text-slate-500"><?= htmlspecialchars((string)$department['chair_email']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <span class="text-sm text-slate-500">No chair assigned</span>
          <?php endif; ?>

          <?php if (!empty($isAdmin)): ?>
            <?php
              $chairPickerIds = [];
              foreach ($faculty as $fRow) {
                  $chairPickerIds[(int)($fRow['faculty_id'] ?? 0)] = true;
              }
              $chairNeedsExtra = $chairId > 0 && !isset($chairPickerIds[$chairId]);
            ?>
            <form method="post" action="<?= htmlspecialchars(url('/admin.php')) ?>" class="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf ?? csrf_token()) ?>" />
              <input type="hidden" name="action" value="department_chair_save" />
              <input type="hidden" name="dept_id" value="<?= htmlspecialchars($did) ?>" />
              <label class="<?= htmlspecialchars(ui_label()) ?>" for="dept-chair-id">Assign chair</label>
              <div class="mt-1.5 flex flex-wrap items-end gap-3">
                <select id="dept-chair-id" name="chair_id" class="<?= htmlspecialchars(ui_select()) ?> min-w-[16rem] flex-1">
                  <option value=""<?= $chairId === 0 ? ' selected' : '' ?>>No chair</option>
                  <?php if ($chairNeedsExtra): ?>
                    <option value="<?= $chairId ?>" selected>
                      <?= $fmtName((string)($department['chair_first'] ?? ''), (string)($department['chair_last'] ?? '')) ?> (ID <?= $chairId ?>)
                    </option>
                  <?php endif; ?>
                  <?php foreach ($faculty as $fRow): ?>
                    <?php
                      $fid = (int)($fRow['faculty_id'] ?? 0);
                      if ($fid <= 0) {
                          continue;
                      }
                      $label = trim((string)($fRow['last_name'] ?? '') . ', ' . (string)($fRow['first_name'] ?? ''));
                    ?>
                    <option value="<?= $fid ?>"<?= $fid === $chairId ? ' selected' : '' ?>>
                      <?= htmlspecialchars($label !== '' ? $label : 'Faculty') ?> (ID <?= $fid ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?>">Save chair</button>
              </div>
              <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Choose a faculty member in this department, or clear the assignment.</p>
            </form>
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt class="<?= htmlspecialchars(ui_label()) ?>">Department assistant</dt>
        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100"><?= !empty($department['dept_assistant']) ? htmlspecialchars((string)$department['dept_assistant']) : '—' ?></dd>
      </div>
      <div>
        <dt class="<?= htmlspecialchars(ui_label()) ?>">Location</dt>
        <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100"><?= $location !== '' ? htmlspecialchars($location) : '—' ?></dd>
      </div>
    </dl>
  </div>

  <div class="mt-6 overflow-hidden <?= htmlspecialchars(ui_card()) ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
      <div>
        <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Faculty</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Appointments in this department</p>
      </div>
      <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600"><?= $facultyN ?> member<?= $facultyN === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($faculty === []): ?>
      <div class="px-5 py-6">
        <div class="<?= htmlspecialchars(ui_empty()) ?>">No faculty appointments in this department.</div>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="<?= htmlspecialchars(ui_thead()) ?>">
            <tr>
              <th class="px-5 py-3">Faculty</th>
              <th class="px-4 py-3">Rank / type</th>
              <th class="px-4 py-3">Time</th>
              <th class="px-5 py-3">Appointed</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
            <?php foreach ($faculty as $f): ?>
              <?php
                $fid = (int)($f['faculty_id'] ?? 0);
                $rankType = trim((string)($f['rank'] ?? '') . (($f['rank'] ?? '') !== '' && ($f['faculty_type'] ?? '') !== '' ? ' · ' : '') . (string)($f['faculty_type'] ?? ''));
              ?>
              <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50">
                <td class="px-5 py-3">
                  <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600">
                      <?= htmlspecialchars($fmtInitials((string)($f['first_name'] ?? ''), (string)($f['last_name'] ?? ''))) ?>
                    </span>
                    <div class="min-w-0">
                      <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars($peopleHref($fid)) ?>">
                        <?= $fmtName((string)($f['first_name'] ?? ''), (string)($f['last_name'] ?? '')) ?>
                      </a>
                      <div class="font-mono text-xs text-slate-500">ID <?= $fid ?></div>
                      <?php if (!empty($f['email'])): ?>
                        <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)$f['email']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <?php if ($rankType !== ''): ?>
                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600"><?= htmlspecialchars($rankType) ?></span>
                  <?php else: ?>
                    <span class="text-slate-500">—</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 tabular-nums text-slate-700 dark:text-slate-300">
                  <?= isset($f['percent_time']) && $f['percent_time'] !== null && $f['percent_time'] !== '' ? (int)$f['percent_time'] . '%' : '—' ?>
                </td>
                <td class="px-5 py-3 text-xs text-slate-600 dark:text-slate-400"><?= $fmtDate($f['date_of_appointment'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-6 overflow-hidden <?= htmlspecialchars(ui_card()) ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
      <div>
        <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Students declared</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Majors and minors linked to this department</p>
      </div>
      <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600"><?= $student_count ?> total</span>
    </div>
    <?php if ($student_count === 0): ?>
      <div class="px-5 py-6">
        <div class="<?= htmlspecialchars(ui_empty()) ?>">No students have declared this department.</div>
      </div>
    <?php else: ?>
      <?php if ($student_count > count($students)): ?>
        <p class="border-b border-slate-100 px-5 py-2 text-xs text-slate-500 dark:border-slate-800">Showing first <?= count($students) ?> of <?= $student_count ?> declarations.</p>
      <?php endif; ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="<?= htmlspecialchars(ui_thead()) ?>">
            <tr>
              <th class="px-5 py-3">Student</th>
              <th class="px-4 py-3">Role</th>
              <th class="px-5 py-3">Declared</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
            <?php foreach ($students as $s): ?>
              <?php $sid = (int)($s['student_id'] ?? 0); ?>
              <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50">
                <td class="px-5 py-3">
                  <div class="flex items-start gap-3">
                    <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600">
                      <?= htmlspecialchars($fmtInitials((string)($s['first_name'] ?? ''), (string)($s['last_name'] ?? ''))) ?>
                    </span>
                    <div class="min-w-0">
                      <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars($peopleHref($sid)) ?>">
                        <?= $fmtName((string)($s['first_name'] ?? ''), (string)($s['last_name'] ?? '')) ?>
                      </a>
                      <div class="font-mono text-xs text-slate-500">ID <?= $sid ?></div>
                      <?php if (!empty($s['email'])): ?>
                        <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)$s['email']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold capitalize text-indigo-900 ring-1 ring-indigo-200 dark:bg-indigo-950/50 dark:text-indigo-100 dark:ring-indigo-800">
                    <?= htmlspecialchars((string)($s['declaration_role'] ?? 'major')) ?>
                  </span>
                </td>
                <td class="px-5 py-3 text-xs text-slate-600 dark:text-slate-400"><?= $fmtDate($s['date_of_declaration'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-6 overflow-hidden <?= htmlspecialchars(ui_card()) ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
      <div>
        <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Catalog courses</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
          Courses owned by this department
          · <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars($coursesListHref) ?>">View offerings</a>
        </p>
      </div>
      <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-600"><?= $courseN ?> course<?= $courseN === 1 ? '' : 's' ?></span>
    </div>
    <?php if ($courses === []): ?>
      <div class="px-5 py-6">
        <div class="<?= htmlspecialchars(ui_empty()) ?>">No courses in the catalog for this department.</div>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="<?= htmlspecialchars(ui_thead()) ?>">
            <tr>
              <th class="px-5 py-3">Course</th>
              <th class="px-4 py-3">Credits</th>
              <th class="px-5 py-3">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
            <?php foreach ($courses as $c): ?>
              <?php $cid = (string)($c['course_id'] ?? ''); ?>
              <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/50">
                <td class="px-5 py-3">
                  <a class="font-mono font-semibold <?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars($courseHref($cid)) ?>"><?= htmlspecialchars($cid) ?></a>
                  <div class="text-xs text-slate-600 dark:text-slate-400"><?= htmlspecialchars((string)($c['course_name'] ?? '')) ?></div>
                </td>
                <td class="px-4 py-3 tabular-nums text-slate-700 dark:text-slate-300"><?= (int)($c['credits'] ?? 0) ?></td>
                <td class="px-5 py-3">
                  <?php if ((int)($c['is_active'] ?? 1) === 1): ?>
                    <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-100 dark:ring-emerald-800">Active</span>
                  <?php else: ?>
                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-600">Inactive</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
