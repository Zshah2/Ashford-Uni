<?php

declare(strict_types=1);

/**
 * Course offerings list (sections joined to courses + instructors) for admin browse.
 *
 * @param array<string, scalar|array|null> $get
 *
 * @return array{
 *   terms: list<array<string, mixed>>,
 *   term_id: int|null,
 *   dept_rows: list<array<string, mixed>>,
 *   dept_id: string,
 *   crn: string,
 *   faculty_id: int|null,
 *   q: string,
 *   course_sections: list<array<string, mixed>>,
 *   course_sections_total: int,
 *   page: int,
 *   per_page: int,
 *   total_pages: int,
 *   catalog_courses: list<array<string, mixed>>,
 *   faculty_rows: list<array<string, mixed>>
 * }
 */
function admin_course_offerings_state(PDO $pdo, array $get): array
{
    $terms = [];
    try {
        $terms = $pdo->query('
          SELECT term_id, code, name, start_date, end_date
          FROM terms
          ORDER BY COALESCE(start_date, "1970-01-01") DESC, term_id DESC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $terms = [];
    }

    $deptRows = [];
    try {
        $deptRows = $pdo->query('SELECT dept_id, dept_name FROM departments ORDER BY dept_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $deptRows = [];
    }

    $validDeptIds = array_map(static fn ($r) => (string)($r['dept_id'] ?? ''), $deptRows);

    $termId = null;
    if ($terms !== []) {
        /** @var list<int> $validTermIds */
        $validTermIds = array_map(static fn ($t) => (int)$t['term_id'], $terms);
        $tidRaw = $get['term_id'] ?? null;
        if (isset($tidRaw) && ctype_digit((string)$tidRaw) && in_array((int)$tidRaw, $validTermIds, true)) {
            $termId = (int)$tidRaw;
        } else {
            $termId = (int)$terms[0]['term_id'];
        }
    }

    $deptFilter = trim((string)($get['dept_id'] ?? ''));
    if ($deptFilter !== '' && !in_array($deptFilter, $validDeptIds, true)) {
        $deptFilter = '';
    }

    $q = trim((string)($get['q'] ?? ''));
    $crn = trim((string)($get['crn'] ?? ''));
    if ($crn !== '' && !ctype_digit($crn)) {
        $crn = '';
    }
    $facultyRaw = trim((string)($get['faculty_id'] ?? ''));
    $facultyId = $facultyRaw !== '' && ctype_digit($facultyRaw) ? (int)$facultyRaw : null;

    $perPage = (int)($get['per_page'] ?? 50);
    if (!in_array($perPage, [25, 50, 100, 200], true)) {
        $perPage = 50;
    }
    $page = max(1, (int)($get['page'] ?? 1));

    $rows = [];
    $total = 0;

    if ($termId !== null) {
        $where = ['s.term_id = ?'];
        $bind = [$termId];
        if ($deptFilter !== '') {
            $where[] = 'c.dept_id = ?';
            $bind[] = $deptFilter;
        }
        if ($crn !== '') {
            $where[] = 's.section_id = ?';
            $bind[] = (int)$crn;
        }
        if ($facultyId !== null) {
            $where[] = 's.faculty_id = ?';
            $bind[] = $facultyId;
        }
        if ($q !== '') {
            $where[] = '(
              CAST(s.section_id AS CHAR) LIKE ?
              OR c.course_id LIKE ?
              OR LOWER(c.course_name) LIKE ?
              OR LOWER(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) LIKE ?
              OR LOWER(COALESCE(s.room, "")) LIKE ?
              OR LOWER(COALESCE(s.meeting_days, "")) LIKE ?
              OR LOWER(COALESCE(s.meeting_time, "")) LIKE ?
            )';
            $slike = '%' . strtolower($q) . '%';
            $idLike = '%' . $q . '%';
            array_push($bind, $idLike, $idLike, $slike, $slike, $slike, $slike, $slike);
        }
        $whereSql = implode(' AND ', $where);

        $countSql = "
          SELECT COUNT(*)
          FROM sections s
          JOIN courses c ON c.course_id = s.course_id
          LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
          LEFT JOIN users u ON u.user_id = f.faculty_id
          WHERE {$whereSql}
        ";
        try {
            $cst = $pdo->prepare($countSql);
            $cst->execute($bind);
            $total = (int)$cst->fetchColumn();
        } catch (Throwable) {
            $total = 0;
        }

        $offset = ($page - 1) * $perPage;
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "
          SELECT
            s.section_id,
            s.term_id,
            s.faculty_id,
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
            s.capacity,
            (SELECT COUNT(*) FROM enrollments e
             WHERE e.section_id = s.section_id AND e.status = 'enrolled') AS enrolled_count
          FROM sections s
          JOIN courses c ON c.course_id = s.course_id
          JOIN terms t ON t.term_id = s.term_id
          LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
          LEFT JOIN users u ON u.user_id = f.faculty_id
          WHERE {$whereSql}
          ORDER BY c.course_id, s.section_id
                    LIMIT {$perPage} OFFSET {$offset}
        ";
        try {
            $st = $pdo->prepare($sql);
                        $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $rows = [];
        }

        $editSectionId = isset($get['edit_section']) && ctype_digit((string)$get['edit_section'])
            ? (int)$get['edit_section']
            : null;
        if ($editSectionId !== null && $editSectionId > 0) {
            $found = false;
            foreach ($rows as $row) {
                if ((int)($row['section_id'] ?? 0) === $editSectionId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                try {
                    $est = $pdo->prepare("
                      SELECT
                        s.section_id,
                        s.term_id,
                        s.faculty_id,
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
                        s.capacity,
                        (SELECT COUNT(*) FROM enrollments e
                         WHERE e.section_id = s.section_id AND e.status = 'enrolled') AS enrolled_count
                      FROM sections s
                      JOIN courses c ON c.course_id = s.course_id
                      JOIN terms t ON t.term_id = s.term_id
                      LEFT JOIN faculty f ON f.faculty_id = s.faculty_id
                      LEFT JOIN users u ON u.user_id = f.faculty_id
                      WHERE s.section_id = ? AND s.term_id = ?
                      LIMIT 1
                    ");
                    $est->execute([$editSectionId, $termId]);
                    $editRow = $est->fetch(PDO::FETCH_ASSOC);
                    if ($editRow) {
                        array_unshift($rows, $editRow);
                    }
                } catch (Throwable) {
                }
            }
        }
    } else {
        $totalPages = 1;
    }

    $catalogCourses = [];
    try {
        $catalogCourses = $pdo->query('
          SELECT course_id, course_name, credits
          FROM courses
          ORDER BY course_id
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $catalogCourses = [];
    }

    $facultyRows = [];
    try {
        $facultyRows = $pdo->query('
          SELECT f.faculty_id, u.first_name, u.last_name
          FROM faculty f
          INNER JOIN users u ON u.user_id = f.faculty_id
          ORDER BY u.last_name, u.first_name, f.faculty_id
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $facultyRows = [];
    }

    return [
        'terms' => $terms,
        'term_id' => $termId,
        'dept_rows' => $deptRows,
        'dept_id' => $deptFilter,
        'crn' => $crn,
        'faculty_id' => $facultyId,
        'q' => $q,
        'course_sections' => $rows,
        'course_sections_total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, $totalPages),
        'catalog_courses' => $catalogCourses,
        'faculty_rows' => $facultyRows,
    ];
}
