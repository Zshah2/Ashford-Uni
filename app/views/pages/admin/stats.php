<?php
/** @var PDO $pdo */
$statPersonId = (int)($_SESSION['auth']['person_id'] ?? 0);
if ($statPersonId < 1) {
    $authId = (int)($_SESSION['auth']['id'] ?? 0);
    if ($authId > 0) {
        $personStmt = $pdo->prepare('SELECT person_id FROM auth_users WHERE id = ?');
        $personStmt->execute([$authId]);
        $statPersonId = (int)$personStmt->fetchColumn();
    }
}
$own = null;
if ($statPersonId > 0) {
    $ownStmt = $pdo->prepare('
      SELECT u.first_name, u.middle_name, u.last_name, u.email, f.faculty_type, f.`rank`, f.office_number, f.phone_number, d.dept_name
      FROM users u
      INNER JOIN faculty f ON f.faculty_id = u.user_id
      LEFT JOIN departments d ON d.dept_id = f.dept_id
      WHERE u.user_id = ? AND f.faculty_type = "Fulltime"
    ');
    $ownStmt->execute([$statPersonId]);
    $own = $ownStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$terms = $pdo->query('SELECT term_id, code, name FROM terms WHERE code IN ("fall2026","spring2027") ORDER BY start_date')->fetchAll(PDO::FETCH_ASSOC);
$termCode = (string)($_GET['term'] ?? 'fall2026');
if (!in_array($termCode, ['fall2026', 'spring2027'], true)) {
    $termCode = 'fall2026';
}
$termId = 0;
foreach ($terms as $termRow) {
    if ((string)$termRow['code'] === $termCode) {
        $termId = (int)$termRow['term_id'];
    }
}
$sectionId = isset($_GET['section_id']) && ctype_digit((string)$_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$sectionChoices = [];
if ($termId > 0) {
    $choiceStmt = $pdo->prepare('
      SELECT s.section_id, c.course_id, c.course_name, s.meeting_days, s.meeting_time, s.room, s.capacity
      FROM sections s
      INNER JOIN courses c ON c.course_id = s.course_id
      WHERE s.term_id = ?
      ORDER BY c.course_id, s.section_id
    ');
    $choiceStmt->execute([$termId]);
    $sectionChoices = $choiceStmt->fetchAll(PDO::FETCH_ASSOC);
}
$anonymousRows = [];
if ($sectionId > 0) {
    $anonStmt = $pdo->prepare('
      SELECT
        COALESCE(ug.academic_year_level, (
          SELECT p.name FROM grad_student_programs g
          INNER JOIN programs p ON p.program_id = g.program_id
          WHERE g.student_id = e.student_id LIMIT 1
        ), "") AS year_label,
        COALESCE(ug.student_type, "Graduate") AS load_label,
        e.status
      FROM enrollments e
      INNER JOIN sections s ON s.section_id = e.section_id
      LEFT JOIN undergrad_students ug ON ug.student_id = e.student_id
      WHERE e.section_id = ? AND s.term_id = ? AND e.status IN ("enrolled", "waitlisted")
      ORDER BY year_label, load_label, e.status
    ');
    $anonStmt->execute([$sectionId, $termId]);
    $anonymousRows = $anonStmt->fetchAll(PDO::FETCH_ASSOC);
}
$seatStmt = $pdo->prepare('
  SELECT
    COUNT(*) AS sections,
    COALESCE(SUM(capacity), 0) AS seats
  FROM sections
  WHERE term_id = ?
');
$seatStmt->execute([$termId]);
$seats = $seatStmt->fetch(PDO::FETCH_ASSOC) ?: ['sections' => 0, 'seats' => 0];
$enrolledStmt = $pdo->prepare('
  SELECT COUNT(*) FROM enrollments e
  INNER JOIN sections sx ON sx.section_id = e.section_id
  WHERE sx.term_id = ? AND e.status = "enrolled"
');
$enrolledStmt->execute([$termId]);
$seats['enrolled'] = (int)$enrolledStmt->fetchColumn();
$openSeats = max(0, (int)$seats['seats'] - (int)$seats['enrolled']);
$userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$courseCount = (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$scheduleRows = [];
if ($termId > 0) {
    $schedStmt = $pdo->prepare('
      SELECT c.course_id, c.course_name, s.meeting_days, s.meeting_time, s.room, s.capacity,
        CONCAT(u.first_name, " ", u.last_name) AS instructor,
        (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = s.section_id AND e.status = "enrolled") AS enrolled
      FROM sections s
      INNER JOIN courses c ON c.course_id = s.course_id
      LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
      LEFT JOIN users u ON u.user_id = f.faculty_id
      WHERE s.term_id = ?
      ORDER BY c.course_id, s.meeting_days, s.meeting_time
      LIMIT 80
    ');
    $schedStmt->execute([$termId]);
    $scheduleRows = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
}
$courseGrades = $pdo->query('
  SELECT c.course_id, c.course_name, COUNT(*) AS graded, ROUND(AVG(r.grade_points), 2) AS avg_points
  FROM student_course_results r
  INNER JOIN courses c ON c.course_id = r.course_id
  GROUP BY c.course_id, c.course_name
  ORDER BY c.course_id
')->fetchAll(PDO::FETCH_ASSOC);
$deptGrades = $pdo->query('
  SELECT d.dept_name, COUNT(*) AS graded, ROUND(AVG(r.grade_points), 2) AS avg_points
  FROM student_course_results r
  INNER JOIN courses c ON c.course_id = r.course_id
  INNER JOIN departments d ON d.dept_id = c.dept_id
  GROUP BY d.dept_id, d.dept_name
  ORDER BY d.dept_name
')->fetchAll(PDO::FETCH_ASSOC);
$audits = $pdo->query('
  SELECT m.major_name,
    COUNT(DISTINCT s.student_id) AS students,
    COUNT(DISTINCT dr.course_id) AS required_courses,
    COUNT(r.course_id) AS completed_rows
  FROM majors m
  LEFT JOIN students s ON s.major_id = m.major_id
  LEFT JOIN degree_requirements dr ON dr.major_id = m.major_id
  LEFT JOIN student_course_results r
    ON r.student_id = s.student_id AND r.course_id = dr.course_id AND r.grade_points >= 1
  GROUP BY m.major_id, m.major_name
  ORDER BY m.major_name
')->fetchAll(PDO::FETCH_ASSOC);
$holdStats = $pdo->query('
  SELECT hold_type, COUNT(*) AS total_holds, SUM(is_active = 1) AS active_holds
  FROM student_holds
  GROUP BY hold_type
  ORDER BY hold_type
')->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="space-y-6">
  <div>
    <h1 class="<?= htmlspecialchars(ui_h1()) ?>">Department statistics</h1>
    <p class="mt-1 <?= htmlspecialchars(ui_muted()) ?>">Counts and schedules only. Student names, IDs, addresses, emails, and phones are not shown.</p>
  </div>

  <section class="<?= htmlspecialchars(ui_card('p-4')) ?>">
    <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Your record</h2>
    <?php if ($own): ?>
      <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-500">Name</dt><dd class="font-medium"><?= htmlspecialchars(trim($own['first_name'] . ' ' . (string)$own['middle_name'] . ' ' . $own['last_name'])) ?></dd></div>
        <div><dt class="text-slate-500">Department</dt><dd class="font-medium"><?= htmlspecialchars((string)($own['dept_name'] ?? '—')) ?></dd></div>
        <div><dt class="text-slate-500">Rank</dt><dd class="font-medium"><?= htmlspecialchars((string)($own['rank'] ?? '—')) ?> · <?= htmlspecialchars((string)$own['faculty_type']) ?></dd></div>
        <div><dt class="text-slate-500">Office</dt><dd class="font-medium"><?= htmlspecialchars((string)($own['office_number'] ?? '—')) ?></dd></div>
        <div><dt class="text-slate-500">Email</dt><dd class="font-medium"><?= htmlspecialchars((string)($own['email'] ?? '—')) ?></dd></div>
        <div><dt class="text-slate-500">Phone</dt><dd class="font-medium"><?= htmlspecialchars((string)($own['phone_number'] ?? '—')) ?></dd></div>
      </dl>
    <?php else: ?>
      <p class="mt-2 text-sm text-slate-600">This login is not linked to a full-time faculty record.</p>
    <?php endif; ?>
  </section>

  <section class="grid gap-3 sm:grid-cols-3">
    <div class="<?= htmlspecialchars(ui_card('p-4')) ?>"><div class="text-xs font-semibold uppercase text-slate-500">Courses</div><div class="mt-1 text-2xl font-semibold"><?= number_format($courseCount) ?></div></div>
    <div class="<?= htmlspecialchars(ui_card('p-4')) ?>"><div class="text-xs font-semibold uppercase text-slate-500">Users</div><div class="mt-1 text-2xl font-semibold"><?= number_format($userCount) ?></div></div>
    <div class="<?= htmlspecialchars(ui_card('p-4')) ?>"><div class="text-xs font-semibold uppercase text-slate-500">Open seats · <?= htmlspecialchars($termCode) ?></div><div class="mt-1 text-2xl font-semibold"><?= number_format($openSeats) ?></div><div class="text-xs text-slate-500"><?= number_format((int)$seats['enrolled']) ?> enrolled of <?= number_format((int)$seats['seats']) ?> seats</div></div>
  </section>

  <section class="<?= htmlspecialchars(ui_card('p-4')) ?>">
    <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Anonymous section roster</h2>
    <form method="get" action="<?= htmlspecialchars(url('/admin.php')) ?>" class="mt-3 flex flex-wrap gap-2">
      <input type="hidden" name="view" value="stats" />
      <select name="term" class="<?= htmlspecialchars(ui_select('mt-0 w-auto')) ?>">
        <?php foreach ($terms as $termRow): ?>
          <option value="<?= htmlspecialchars((string)$termRow['code']) ?>" <?= $termCode === (string)$termRow['code'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$termRow['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="section_id" class="<?= htmlspecialchars(ui_select('mt-0 w-auto max-w-xl')) ?>">
        <option value="">Choose a section</option>
        <?php foreach ($sectionChoices as $choice): ?>
          <option value="<?= (int)$choice['section_id'] ?>" <?= $sectionId === (int)$choice['section_id'] ? 'selected' : '' ?>><?= htmlspecialchars($choice['course_id'] . ' ' . $choice['course_name'] . ' · ' . $choice['meeting_days'] . ' ' . $choice['meeting_time']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="<?= htmlspecialchars(ui_btn_primary()) ?>" type="submit">View</button>
    </form>
    <?php if ($sectionId > 0): ?>
      <p class="mt-3 text-sm text-slate-600"><?= count($anonymousRows) ?> registered. No names or IDs.</p>
      <div class="mt-2 overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Year</th><th>Load</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($anonymousRows as $row): ?>
              <tr class="border-t border-slate-100">
                <td class="py-2"><?= htmlspecialchars((string)$row['year_label'] !== '' ? (string)$row['year_label'] : '—') ?></td>
                <td><?= htmlspecialchars((string)$row['load_label']) ?></td>
                <td><?= htmlspecialchars((string)$row['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($anonymousRows === []): ?>
              <tr><td class="py-3 text-slate-500" colspan="3">No enrolled or waitlisted students in this section.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="<?= htmlspecialchars(ui_card('p-4')) ?>">
    <h2 class="<?= htmlspecialchars(ui_h2()) ?>"><?= htmlspecialchars($termCode) ?> schedule</h2>
    <p class="mt-1 text-xs text-slate-500">Instructor and meeting time only. First 80 sections.</p>
    <div class="mt-3 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Course</th><th>When</th><th>Room</th><th>Instructor</th><th>Enrolled</th><th>Seats</th></tr></thead>
        <tbody>
          <?php foreach ($scheduleRows as $row): ?>
            <tr class="border-t border-slate-100">
              <td class="py-2"><?= htmlspecialchars($row['course_id'] . ' ' . $row['course_name']) ?></td>
              <td><?= htmlspecialchars(trim((string)$row['meeting_days'] . ' ' . (string)$row['meeting_time'])) ?></td>
              <td><?= htmlspecialchars((string)$row['room']) ?></td>
              <td><?= htmlspecialchars((string)$row['instructor']) ?></td>
              <td><?= (int)$row['enrolled'] ?></td>
              <td><?= (int)$row['capacity'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="grid gap-3 lg:grid-cols-2">
    <div class="<?= htmlspecialchars(ui_card('p-4')) ?>">
      <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Course grade statistics</h2>
      <div class="mt-3 max-h-80 overflow-auto">
        <table class="w-full text-left text-sm">
          <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Course</th><th>Graded</th><th>Avg points</th></tr></thead>
          <tbody>
            <?php foreach ($courseGrades as $row): ?>
              <tr class="border-t border-slate-100"><td class="py-2"><?= htmlspecialchars($row['course_id'] . ' ' . $row['course_name']) ?></td><td><?= (int)$row['graded'] ?></td><td><?= htmlspecialchars((string)$row['avg_points']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="<?= htmlspecialchars(ui_card('p-4')) ?>">
      <h2 class="<?= htmlspecialchars(ui_h2()) ?>">Department grade statistics</h2>
      <table class="mt-3 w-full text-left text-sm">
        <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Department</th><th>Graded</th><th>Avg points</th></tr></thead>
        <tbody>
          <?php foreach ($deptGrades as $row): ?>
            <tr class="border-t border-slate-100"><td class="py-2"><?= htmlspecialchars((string)$row['dept_name']) ?></td><td><?= (int)$row['graded'] ?></td><td><?= htmlspecialchars((string)$row['avg_points']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <h2 class="mt-6 <?= htmlspecialchars(ui_h2()) ?>">Degree progress</h2>
      <table class="mt-3 w-full text-left text-sm">
        <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Major</th><th>Students</th><th>Requirements met</th></tr></thead>
        <tbody>
          <?php foreach ($audits as $row): ?>
            <?php
              $possible = (int)$row['students'] * (int)$row['required_courses'];
              $rate = $possible > 0 ? (int)round(((int)$row['completed_rows'] / $possible) * 100) : 0;
            ?>
            <tr class="border-t border-slate-100"><td class="py-2"><?= htmlspecialchars((string)$row['major_name']) ?></td><td><?= (int)$row['students'] ?></td><td><?= $rate ?>%</td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <h2 class="mt-6 <?= htmlspecialchars(ui_h2()) ?>">Holds</h2>
      <table class="mt-3 w-full text-left text-sm">
        <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2">Hold type</th><th>Active</th><th>All</th></tr></thead>
        <tbody>
          <?php foreach ($holdStats as $row): ?>
            <tr class="border-t border-slate-100"><td class="py-2"><?= htmlspecialchars((string)$row['hold_type']) ?></td><td><?= (int)$row['active_holds'] ?></td><td><?= (int)$row['total_holds'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
