<?php

declare(strict_types=1);

/**
 * Bulk-fill catalog descriptions and infer prerequisite links so staff never have to
 * hand-enter every course for demos. Used by scripts/enrich_all_courses.php and import_all.php.
 */

function enrich_catalog_build_description(array $r): string
{
    $name = trim((string)($r['course_name'] ?? ''));
    $cid = trim((string)($r['course_id'] ?? ''));
    $cr = max(1, (int)($r['credits'] ?? 3));
    $deptName = trim((string)($r['dept_name'] ?? ''));
    $deptId = trim((string)($r['dept_id'] ?? ''));
    $deptLabel = $deptName !== '' ? $deptName : ($deptId !== '' ? $deptId : 'Ashford');

    return "{$name} ({$cid}) is a {$cr}-credit course offered through {$deptLabel}. "
        . 'Students engage with core concepts through readings, discussion, structured assessments, '
        . 'and (where applicable) laboratory or project work aligned to department learning outcomes. '
        . 'Consult the official syllabus each term for prerequisites as enforced at registration, grading weights, and required materials.';
}

/** @return array{0: string, 1: int}|null */
function enrich_catalog_infer_minus_hundred(string $courseId): ?array
{
    if (!preg_match('/^([A-Z]{2,10})(\d+)$/u', $courseId, $m)) {
        return null;
    }
    $prefix = $m[1];
    $num = (int)$m[2];
    if ($num < 200) {
        return null;
    }
    $lower = $num - 100;
    if ($lower < 100) {
        return null;
    }

    return [$prefix . $lower, $lower];
}

/**
 * Same letter-prefix, consecutive numbers (e.g. BIO102 after BIO101): higher requires lower.
 *
 * @param list<string> $courseIds
 * @return list<array{0: string, 1: string}>
 */
function enrich_catalog_infer_consecutive_pairs(array $courseIds): array
{
    /** @var array<string, list<array{id: string, n: int}>> $groups */
    $groups = [];
    foreach ($courseIds as $cid) {
        $cid = (string)$cid;
        if (!preg_match('/^([A-Z]{2,10})(\d+)$/u', $cid, $m)) {
            continue;
        }
        $prefix = $m[1];
        $n = (int)$m[2];
        $groups[$prefix][] = ['id' => $cid, 'n' => $n];
    }
    $pairs = [];
    foreach ($groups as $items) {
        usort($items, static fn ($a, $b) => $a['n'] <=> $b['n']);
        $count = count($items);
        for ($i = 1; $i < $count; $i++) {
            if ($items[$i]['n'] === $items[$i - 1]['n'] + 1) {
                $pairs[] = [$items[$i]['id'], $items[$i - 1]['id']];
            }
        }
    }

    return $pairs;
}

/**
 * @return array{descriptions: int, prereqs: int}
 */
function enrich_catalog_run(PDO $pdo, bool $force): array
{
    $pdo->beginTransaction();
    try {
        $out = enrich_catalog_run_inner($pdo, $force);
        $pdo->commit();

        return $out;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @return array{descriptions: int, prereqs: int}
 */
function enrich_catalog_run_inner(PDO $pdo, bool $force): array
{
    $sql = '
      SELECT c.course_id, c.course_name, c.credits, c.dept_id, c.description,
        d.dept_name
      FROM courses c
      LEFT JOIN departments d ON d.dept_id = c.dept_id
      ORDER BY c.course_id
    ';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $upd = $pdo->prepare('UPDATE courses SET description = ? WHERE course_id = ?');

    $descUpdated = 0;
    foreach ($rows as $r) {
        $cid = (string)($r['course_id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $existing = trim((string)($r['description'] ?? ''));
        if (!$force && $existing !== '') {
            continue;
        }
        $text = enrich_catalog_build_description($r);
        $upd->execute([$text, $cid]);
        $descUpdated++;
    }

    $existIds = $pdo->query('SELECT course_id FROM courses')->fetchAll(PDO::FETCH_COLUMN);
    /** @var array<string, true> $existSet */
    $existSet = [];
    foreach ($existIds as $id) {
        $existSet[(string)$id] = true;
    }

    $insPre = $pdo->prepare('INSERT IGNORE INTO course_prereqs (course_id, prereq_course_id) VALUES (?, ?)');
    $prereqAdded = 0;

    foreach ($rows as $r) {
        $cid = (string)($r['course_id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $cand = enrich_catalog_infer_minus_hundred($cid);
        if ($cand !== null) {
            [$prereqId, $_lower] = $cand;
            if ($prereqId !== $cid && isset($existSet[$prereqId])) {
                $insPre->execute([$cid, $prereqId]);
                if ($insPre->rowCount() > 0) {
                    $prereqAdded++;
                }
            }
        }
    }

    $allIds = array_keys($existSet);
    foreach (enrich_catalog_infer_consecutive_pairs($allIds) as [$high, $low]) {
        $insPre->execute([$high, $low]);
        if ($insPre->rowCount() > 0) {
            $prereqAdded++;
        }
    }

    return ['descriptions' => $descUpdated, 'prereqs' => $prereqAdded];
}

