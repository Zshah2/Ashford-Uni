<?php

declare(strict_types=1);

/**
 * @return array<string, bool>
 */
function admin_schedule_level_join(string $level): string
{
    if ($level === 'undergrad') {
        return ' INNER JOIN undergrad_students ug_level ON ug_level.student_id = s.student_id';
    }
    if ($level === 'grad') {
        return ' INNER JOIN (SELECT DISTINCT student_id FROM grad_student_programs) grad_level ON grad_level.student_id = s.student_id';
    }

    return '';
}

function admin_schedule_panel_flags_from_list(array $pickedKeys): array
{
    $keys = ['students', 'faculty', 'terms', 'departments', 'courses', 'sections'];
    if ($pickedKeys === []) {
        return array_fill_keys($keys, false);
    }

    $picked = [];
    foreach ($pickedKeys as $p) {
        $p = strtolower(trim((string)$p));
        if (in_array($p, $keys, true)) {
            $picked[$p] = true;
        }
    }
    if ($picked === []) {
        return array_fill_keys($keys, false);
    }

    return array_combine($keys, array_map(static fn (string $k) => isset($picked[$k]), $keys)) ?: array_fill_keys($keys, false);
}

/**
 * Builds master-schedule data for the routed `/admin/schedule` page (`admin.php?view=schedule` redirects there).
 *
 * @param array<string, scalar|array|null> $get
 *
 * @return array{
 *   schedule_panels: array<string, bool>,
 *   search_q: string,
 *   course_q: string,
 *   catalog_dept: string,
 *   sec_q: string,
 *   valid_dept_ids_for_select: list<string>,
 *   student_rows: list<array<string, mixed>>,
 *   faculty_rows: list<array<string, mixed>>,
 *   dept_rows: list<array<string, mixed>>,
 *   course_rows: list<array<string, mixed>>,
 *   terms: list<array<string, mixed>>,
 *   term_id: int|null,
 *   dept_id: string,
 *   sections: list<array<string, mixed>>,
 *   schedule_embed_preservation: bool,
 *   student_total: int,
 *   faculty_total: int,
 *   stu_page: int,
 *   fac_page: int,
 *   schedule_per_page: int,
 *   schedule_unified_roster: bool,
 *   roster_rows: list<array<string, mixed>>,
 *   roster_total: int
 * }
 */
function admin_schedule_state(PDO $pdo, array $get): array
{
    $keysOrder = ['students', 'faculty', 'terms', 'departments', 'courses', 'sections'];
    $panelsGet = isset($get['panels']) ? $get['panels'] : null;
    $filtersSubmitted = isset($get['sched_filter']) && (string)$get['sched_filter'] === '1';

    if (!$filtersSubmitted) {
        // Master schedule is primarily a people directory. Default to people-only panels.
        $panelFlags = array_fill_keys($keysOrder, false);
        $panelFlags['students'] = true;
        $panelFlags['faculty'] = true;
    } elseif (is_array($panelsGet)) {
        $picked = [];
        foreach ($keysOrder as $k) {
            if (!empty($panelsGet[$k])) {
                $picked[] = $k;
            }
        }
        $panelFlags = admin_schedule_panel_flags_from_list($picked);
    } elseif (is_string($panelsGet) && trim($panelsGet) !== '') {
        $picked = array_filter(array_map('trim', explode(',', strtolower($panelsGet))));
        $panelFlags = admin_schedule_panel_flags_from_list($picked);
    } else {
        $panelFlags = array_fill_keys($keysOrder, false);
    }

    $rosterLevel = strtolower(trim((string)($get['level'] ?? '')));
    if (!in_array($rosterLevel, ['undergrad', 'grad'], true)) {
        $rosterLevel = '';
    }
    if ($rosterLevel !== '') {
        $panelFlags = array_fill_keys($keysOrder, false);
        $panelFlags['students'] = true;
    }

    $searchQ = trim((string)($get['q'] ?? ''));
    $searchIdError = null;
    try {
        $searchIdError = people_id_lookup_error($pdo, $searchQ);
    } catch (Throwable) {
        $searchIdError = null;
    }
    $courseQ = trim((string)($get['course_q'] ?? ''));
    $catalogDept = trim((string)($get['catalog_dept'] ?? ''));

    $validDeptIds = [];
    try {
        $validDeptIds = $pdo->query('SELECT dept_id FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($validDeptIds)) {
            $validDeptIds = [];
        }
    } catch (Throwable) {
        $validDeptIds = [];
    }
    if ($catalogDept !== '' && !in_array($catalogDept, $validDeptIds, true)) {
        $catalogDept = '';
    }

    $secQ = trim((string)($get['sec_q'] ?? ''));
    $studentRows = [];
    $facultyRows = [];
    $studentTotal = 0;
    $facultyTotal = 0;
    $rosterRows = [];
    $rosterTotal = 0;
    $unifiedRoster = ($panelFlags['students'] ?? false) && ($panelFlags['faculty'] ?? false);

    $perPage = (int)($get['per_page'] ?? 50);
    if (!in_array($perPage, [25, 50, 100, 200], true)) {
        $perPage = 50;
    }
    $stuPage = max(1, (int)($get['stu_page'] ?? 1));
    $facPage = max(1, (int)($get['fac_page'] ?? 1));

    try {
        if ($searchIdError !== null) {
            // ID was not a real student or faculty member. Leave the roster empty.
        } elseif ($unifiedRoster) {
            $stuFrom = ' FROM users u
              INNER JOIN students s ON s.student_id = u.user_id
              LEFT JOIN (
                SELECT
                  sd.student_id,
                  GROUP_CONCAT(
                    CONCAT(
                      sd.dept_id,
                      CASE
                        WHEN COALESCE(sd.declaration_role, "") = "" THEN ""
                        ELSE CONCAT(" (", sd.declaration_role, ")")
                      END
                    )
                    ORDER BY sd.dept_id
                    SEPARATOR ", "
                  ) AS dept_roles
                FROM student_departments sd
                GROUP BY sd.student_id
              ) sdagg ON sdagg.student_id = u.user_id' . admin_schedule_level_join($rosterLevel);
            $stuWhereSql = '';
            $stuParams = [];
            if ($searchQ !== '') {
                $stuWhereSql = ' WHERE (
                  CAST(u.user_id AS CHAR) LIKE ?
                  OR LOWER(u.first_name) LIKE ?
                  OR LOWER(u.last_name) LIKE ?
                  OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
                  OR LOWER(COALESCE(u.email, "")) LIKE ?
                  OR COALESCE(u.phone_number, "") LIKE ?
                )';
                $likeId = '%' . $searchQ . '%';
                $likeName = '%' . strtolower($searchQ) . '%';
                $phoneLike = '%' . $searchQ . '%';
                $stuParams = [$likeId, $likeName, $likeName, $likeName, $likeName, $phoneLike];
            }
            $facFrom = ' FROM users u
              INNER JOIN faculty f ON f.faculty_id = u.user_id
              LEFT JOIN (
                SELECT
                  fd.faculty_id,
                  GROUP_CONCAT(
                    CONCAT(fd.dept_id, CASE WHEN d.dept_name IS NULL THEN "" ELSE CONCAT(" — ", d.dept_name) END)
                    ORDER BY fd.dept_id
                    SEPARATOR ", "
                  ) AS dept_names
                FROM faculty_departments fd
                LEFT JOIN departments d ON d.dept_id = fd.dept_id
                GROUP BY fd.faculty_id
              ) fdagg ON fdagg.faculty_id = u.user_id';
            $facWhereSql = '';
            $facParams = [];
            if ($searchQ !== '') {
                $facWhereSql = ' WHERE (
                  CAST(u.user_id AS CHAR) LIKE ?
                  OR LOWER(u.first_name) LIKE ?
                  OR LOWER(u.last_name) LIKE ?
                  OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
                  OR LOWER(COALESCE(f.email, "")) LIKE ?
                  OR COALESCE(f.phone_number, "") LIKE ?
                )';
                $likeId = '%' . $searchQ . '%';
                $likeName = '%' . strtolower($searchQ) . '%';
                $phoneLike = '%' . $searchQ . '%';
                $facParams = [$likeId, $likeName, $likeName, $likeName, $likeName, $phoneLike];
            }
            $cntSt = $pdo->prepare('SELECT COUNT(*)' . $stuFrom . $stuWhereSql);
            $cntSt->execute($stuParams);
            $studentTotal = (int)$cntSt->fetchColumn();
            $cntFac = $pdo->prepare('SELECT COUNT(*)' . $facFrom . $facWhereSql);
            $cntFac->execute($facParams);
            $facultyTotal = (int)$cntFac->fetchColumn();
            $rosterTotal = $studentTotal + $facultyTotal;
            $rosterMaxPage = $rosterTotal > 0 ? (int)ceil($rosterTotal / $perPage) : 1;
            $stuPage = min(max(1, $stuPage), max(1, $rosterMaxPage));
            $facPage = 1;
            $stuOffset = ($stuPage - 1) * $perPage;
            $lim = max(1, min(200, $perPage));
            $off = max(0, $stuOffset);

            $unionSql = '
              SELECT * FROM (
                SELECT
                  \'Student\' AS roster_kind,
                  u.user_id,
                  u.first_name,
                  u.middle_name,
                  u.last_name,
                  u.user_type,
                  (CAST(u.apt_no AS CHAR(40)) COLLATE utf8mb4_0900_ai_ci) AS apt_no,
                  (CAST(u.street AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS street,
                  u.city,
                  u.state,
                  (CAST(u.zip_code AS CHAR(20)) COLLATE utf8mb4_0900_ai_ci) AS zip_code,
                  (CAST(u.email AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS email,
                  (CAST(u.phone_number AS CHAR(64)) COLLATE utf8mb4_0900_ai_ci) AS phone_number,
                  (CAST(sdagg.dept_roles AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS dept_list,
                  (CAST(NULL AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS office_number,
                  (CAST(NULL AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS faculty_rank,
                  (CAST(NULL AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS faculty_type
                ' . $stuFrom . '
                ' . $stuWhereSql . '
                UNION ALL
                SELECT
                  \'Faculty\' AS roster_kind,
                  u.user_id,
                  u.first_name,
                  u.middle_name,
                  u.last_name,
                  u.user_type,
                  (CAST(u.apt_no AS CHAR(40)) COLLATE utf8mb4_0900_ai_ci) AS apt_no,
                  (CAST(u.street AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS street,
                  u.city,
                  u.state,
                  (CAST(u.zip_code AS CHAR(20)) COLLATE utf8mb4_0900_ai_ci) AS zip_code,
                  (CAST(f.email AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS email,
                  (CAST(f.phone_number AS CHAR(64)) COLLATE utf8mb4_0900_ai_ci) AS phone_number,
                  (CAST(fdagg.dept_names AS CHAR(255)) COLLATE utf8mb4_0900_ai_ci) AS dept_list,
                  (CAST(f.office_number AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS office_number,
                  (CAST(f.`rank` AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS faculty_rank,
                  (CAST(f.faculty_type AS CHAR(50)) COLLATE utf8mb4_0900_ai_ci) AS faculty_type
                ' . $facFrom . '
                ' . $facWhereSql . '
              ) combined
              ORDER BY last_name, first_name, roster_kind, user_id
              LIMIT ' . $lim . ' OFFSET ' . $off;
            $uSt = $pdo->prepare($unionSql);
            $uSt->execute(array_merge($stuParams, $facParams));
            $rosterRows = $uSt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($panelFlags['students'] ?? false) {
            $stuFrom = ' FROM users u
              INNER JOIN students s ON s.student_id = u.user_id
              LEFT JOIN (
                SELECT
                  sd.student_id,
                  GROUP_CONCAT(
                    CONCAT(
                      sd.dept_id,
                      CASE
                        WHEN COALESCE(sd.declaration_role, "") = "" THEN ""
                        ELSE CONCAT(" (", sd.declaration_role, ")")
                      END
                    )
                    ORDER BY sd.dept_id
                    SEPARATOR ", "
                  ) AS dept_roles
                FROM student_departments sd
                GROUP BY sd.student_id
              ) sdagg ON sdagg.student_id = u.user_id'
                . admin_schedule_level_join($rosterLevel) . '
              LEFT JOIN undergrad_students ug_info ON ug_info.student_id = s.student_id
              LEFT JOIN majors maj ON maj.major_id = s.major_id
              LEFT JOIN minors mino ON mino.minor_id = s.minor_id
              LEFT JOIN (
                SELECT g.student_id, MIN(p.name) AS program_name
                FROM grad_student_programs g
                INNER JOIN programs p ON p.program_id = g.program_id
                GROUP BY g.student_id
              ) gprog ON gprog.student_id = s.student_id';
            $stuWhereSql = '';
            $stuParams = [];
            if ($searchQ !== '') {
                $stuWhereSql = ' WHERE (
                  CAST(u.user_id AS CHAR) LIKE ?
                  OR LOWER(u.first_name) LIKE ?
                  OR LOWER(u.last_name) LIKE ?
                  OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
                  OR LOWER(COALESCE(u.email, "")) LIKE ?
                  OR COALESCE(u.phone_number, "") LIKE ?
                )';
                $likeId = '%' . $searchQ . '%';
                $likeName = '%' . strtolower($searchQ) . '%';
                $phoneLike = '%' . $searchQ . '%';
                $stuParams = [$likeId, $likeName, $likeName, $likeName, $likeName, $phoneLike];
            }
            $cntSt = $pdo->prepare('SELECT COUNT(*)' . $stuFrom . $stuWhereSql);
            $cntSt->execute($stuParams);
            $studentTotal = (int)$cntSt->fetchColumn();
            $maxStuPage = $studentTotal > 0 ? (int)ceil($studentTotal / $perPage) : 1;
            $stuPage = min(max(1, $stuPage), max(1, $maxStuPage));
            $stuOffset = ($stuPage - 1) * $perPage;

            $lim = max(1, min(200, $perPage));
            $off = max(0, $stuOffset);
            $stuSql = '
              SELECT
                u.user_id, u.first_name, u.middle_name, u.last_name, u.user_type,
                u.apt_no, u.street, u.city, u.state, u.zip_code,
                u.email, u.phone_number,
                sdagg.dept_roles AS dept_list,
                COALESCE(ug_info.student_type, CASE WHEN gprog.student_id IS NOT NULL THEN "Graduate" ELSE u.user_type END) AS student_load,
                COALESCE(ug_info.academic_year_level, gprog.program_name, "") AS student_year,
                CONCAT_WS(", ",
                  IF(maj.major_name IS NULL, NULL, CONCAT(maj.major_name, " (major)")),
                  IF(mino.minor_name IS NULL, NULL, CONCAT(mino.minor_name, " (minor)"))
                ) AS program_list,
                CASE
                  WHEN EXISTS (
                    SELECT 1 FROM enrollments en
                    INNER JOIN sections sec ON sec.section_id = en.section_id
                    INNER JOIN terms tm ON tm.term_id = sec.term_id
                    WHERE en.student_id = u.user_id AND en.status = "enrolled" AND CURDATE() BETWEEN tm.start_date AND tm.end_date
                  ) THEN "Enrolled"
                  WHEN EXISTS (
                    SELECT 1 FROM enrollments en
                    INNER JOIN sections sec ON sec.section_id = en.section_id
                    INNER JOIN terms tm ON tm.term_id = sec.term_id
                    WHERE en.student_id = u.user_id AND en.status = "waitlisted" AND CURDATE() BETWEEN tm.start_date AND tm.end_date
                  ) THEN "Waitlisted"
                  ELSE "Not enrolled"
                END AS enrollment_status
            ' . $stuFrom . $stuWhereSql . ' ORDER BY u.last_name, u.first_name, u.user_id LIMIT ' . $lim . ' OFFSET ' . $off;
            $stSt = $pdo->prepare($stuSql);
            $stSt->execute($stuParams);
            $studentRows = $stSt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($panelFlags['faculty'] ?? false) {
            $facFrom = ' FROM users u
              INNER JOIN faculty f ON f.faculty_id = u.user_id
              LEFT JOIN (
                SELECT
                  fd.faculty_id,
                  GROUP_CONCAT(
                    CONCAT(fd.dept_id, CASE WHEN d.dept_name IS NULL THEN "" ELSE CONCAT(" — ", d.dept_name) END)
                    ORDER BY fd.dept_id
                    SEPARATOR ", "
                  ) AS dept_names
                FROM faculty_departments fd
                LEFT JOIN departments d ON d.dept_id = fd.dept_id
                GROUP BY fd.faculty_id
              ) fdagg ON fdagg.faculty_id = u.user_id';
            $facWhereSql = '';
            $facParams = [];
            if ($searchQ !== '') {
                $facWhereSql = ' WHERE (
                  CAST(u.user_id AS CHAR) LIKE ?
                  OR LOWER(u.first_name) LIKE ?
                  OR LOWER(u.last_name) LIKE ?
                  OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
                  OR LOWER(COALESCE(f.email, "")) LIKE ?
                  OR COALESCE(f.phone_number, "") LIKE ?
                )';
                $likeId = '%' . $searchQ . '%';
                $likeName = '%' . strtolower($searchQ) . '%';
                $phoneLike = '%' . $searchQ . '%';
                $facParams = [$likeId, $likeName, $likeName, $likeName, $likeName, $phoneLike];
            }
            $cntFac = $pdo->prepare('SELECT COUNT(*)' . $facFrom . $facWhereSql);
            $cntFac->execute($facParams);
            $facultyTotal = (int)$cntFac->fetchColumn();
            $maxFacPage = $facultyTotal > 0 ? (int)ceil($facultyTotal / $perPage) : 1;
            $facPage = min(max(1, $facPage), max(1, $maxFacPage));
            $facOffset = ($facPage - 1) * $perPage;

            $flim = max(1, min(200, $perPage));
            $foff = max(0, $facOffset);
            $facSql = '
              SELECT u.user_id AS faculty_id, u.first_name, u.middle_name, u.last_name, u.user_type,
                     u.apt_no, u.street, u.city, u.state, u.zip_code,
                     f.office_number, f.`rank` AS faculty_rank, f.faculty_type,
                     f.email, f.phone_number,
                     fdagg.dept_names AS dept_list
            ' . $facFrom . $facWhereSql . ' ORDER BY u.last_name, u.first_name, u.user_id LIMIT ' . $flim . ' OFFSET ' . $foff;
            $facSt = $pdo->prepare($facSql);
            $facSt->execute($facParams);
            $facultyRows = $facSt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable) {
    }

    $deptRows = [];
    $courseRows = [];
    try {
        if (($panelFlags['departments'] ?? false)) {
            if ($catalogDept !== '') {
                $stDept = $pdo->prepare('
                  SELECT dept_id, dept_name, email, phone_number, building_number, room_number
                  FROM departments WHERE dept_id = ? ORDER BY dept_id
                ');
                $stDept->execute([$catalogDept]);
                $deptRows = $stDept->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $deptRows = $pdo->query('
                  SELECT dept_id, dept_name, email, phone_number, building_number, room_number
                  FROM departments ORDER BY dept_id
                ')->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if (($panelFlags['courses'] ?? false)) {
            $cWhere = [];
            $cBind = [];
            if ($catalogDept !== '') {
                $cWhere[] = 'c.dept_id = ?';
                $cBind[] = $catalogDept;
            }
            if ($courseQ !== '') {
                $cWhere[] = '(c.course_id LIKE ? OR LOWER(c.course_name) LIKE ?)';
                $cBind[] = '%' . $courseQ . '%';
                $cBind[] = '%' . strtolower($courseQ) . '%';
            }
            $csql = '
              SELECT c.course_id, c.course_name, c.credits, c.dept_id
              FROM courses c
            ';
            if ($cWhere !== []) {
                $csql .= ' WHERE ' . implode(' AND ', $cWhere);
            }
            $csql .= ' ORDER BY c.dept_id, c.course_id';
            $cst = $pdo->prepare($csql);
            $cst->execute($cBind);
            $courseRows = $cst->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable) {
    }

    $terms = [];
    try {
        $terms = $pdo->query('
          SELECT term_id, code, name, start_date, end_date FROM terms ORDER BY COALESCE(start_date, "1970-01-01") DESC, term_id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);
        if ($terms === false) {
            $terms = [];
        }
    } catch (Throwable) {
        $terms = [];
    }

    $termId = null;
    if ($terms !== []) {
        /** @var list<int> $validTermIds */
        $validTermIds = array_map(static fn ($t) => (int)$t['term_id'], $terms);
        $tidRaw = $get['term_id'] ?? null;
        if (isset($tidRaw) && ctype_digit((string)$tidRaw) && in_array((int)$tidRaw, $validTermIds, true)) {
            $termId = (int)$tidRaw;
        } else {
            $termId = (int)$terms[0]['term_id'];
            $today = date('Y-m-d');
            foreach ($terms as $termRow) {
                $start = (string)($termRow['start_date'] ?? '');
                $end = (string)($termRow['end_date'] ?? '');
                if ($start !== '' && $end !== '' && $today >= $start && $today <= $end) {
                    $termId = (int)$termRow['term_id'];
                    break;
                }
            }
        }
    }

    $deptFilter = trim((string)($get['dept_id'] ?? ''));
    if ($deptFilter !== '' && !in_array($deptFilter, $validDeptIds, true)) {
        $deptFilter = '';
    }

    $sections = [];

    $allPanelsOn = !in_array(false, $panelFlags, true);

    if (($panelFlags['sections'] ?? false) && $termId !== null) {
        $sql = '
          SELECT
            s.section_id,
            c.course_id,
            c.course_name,
            c.credits,
            c.dept_id,
            t.code AS term_code,
            t.name AS term_name,
            u.first_name AS fac_first,
            u.last_name AS fac_last,
            s.meeting_days,
            s.meeting_time,
            s.room,
            s.capacity
          FROM sections s
          JOIN courses c ON c.course_id = s.course_id
          JOIN terms t ON t.term_id = s.term_id
          LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
          LEFT JOIN users u ON u.user_id = f.faculty_id
          WHERE s.term_id = ?
        ';
        $bind = [$termId];
        if ($deptFilter !== '') {
            $sql .= ' AND c.dept_id = ?';
            $bind[] = $deptFilter;
        }
        if ($secQ !== '') {
            $sql .= ' AND (
              CAST(s.section_id AS CHAR) LIKE ?
              OR c.course_id LIKE ?
              OR LOWER(c.course_name) LIKE ?
              OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
              OR LOWER(COALESCE(s.room, "")) LIKE ?
              OR LOWER(COALESCE(s.meeting_days, "")) LIKE ?
              OR LOWER(COALESCE(s.meeting_time, "")) LIKE ?
            )';
            $slike = '%' . strtolower($secQ) . '%';
            $idLike = '%' . $secQ . '%';
            array_push($bind, $idLike, $idLike, $slike, $slike, $slike, $slike, $slike);
        }
        $sql .= ' ORDER BY c.course_id, s.section_id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $scheduleEmbedPreserve = !$allPanelsOn
        || $filtersSubmitted
        || $searchQ !== ''
        || $courseQ !== ''
        || $catalogDept !== ''
        || $secQ !== ''
        || $deptFilter !== ''
        || $perPage !== 50
        || $stuPage > 1
        || $facPage > 1;

    return [
        'schedule_panels' => $panelFlags,
        'schedule_embed_preservation' => $scheduleEmbedPreserve,
        'search_q' => $searchQ,
        'search_id_error' => $searchIdError,
        'course_q' => $courseQ,
        'catalog_dept' => $catalogDept,
        'sec_q' => $secQ,
        'valid_dept_ids_for_select' => $validDeptIds,
        'student_rows' => $studentRows,
        'faculty_rows' => $facultyRows,
        'student_total' => $studentTotal,
        'faculty_total' => $facultyTotal,
        'schedule_unified_roster' => $unifiedRoster,
        'roster_rows' => $rosterRows,
        'roster_total' => $rosterTotal,
        'stu_page' => $stuPage,
        'fac_page' => $facPage,
        'schedule_per_page' => $perPage,
        'dept_rows' => $deptRows,
        'course_rows' => $courseRows,
        'terms' => $terms,
        'term_id' => $termId,
        'dept_id' => $deptFilter,
        'sections' => $sections,
        'roster_level' => $rosterLevel,
    ];
}
