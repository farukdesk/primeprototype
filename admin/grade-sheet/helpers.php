<?php
/**
 * Grade Sheet Module – Shared Helpers
 *
 * Builds a department / batch / semester tabulation grade sheet (Semester
 * Final Examination) from the existing published results pipeline:
 *
 *   result_mark_sheets (ms)          one published sheet per subject/semester
 *     → co_offer_subjects (cos)      links the sheet to a course offer
 *     → co_offers        (o)         dept / program / batch / semester scope
 *     → course_curriculum(c)         course code / name / credit (columns)
 *   result_sheet_grades (g)          per-student letter grade / grade point
 *     → students          (s)        student id / name / batch / shift / section
 *
 * Only PUBLISHED sheets are considered — a grade sheet is an official document.
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function gs_can_view(): bool
{
    return is_super_admin() || can_access('grade-sheet', 'can_view');
}

// ── Department scope (same rule as results/report.php) ─────────────────────────
// Explicit scope (get_dept_scope) always wins. When none is configured, faculty
// members are limited to their own department(s); non-faculty staff (e.g. the
// Controller of Examinations office) remain unrestricted.
function gs_dept_scope(): ?array
{
    $scope = get_dept_scope();
    if (is_super_admin() || $scope !== null) {
        return $scope;
    }
    $own_uid   = (int)(auth_user()['id'] ?? 0);
    $own_depts = [];
    $is_faculty = false;
    foreach (['faculty_profiles' => '', 'dept_faculty' => ' AND is_active = 1'] as $tbl => $extra) {
        try {
            $st = db()->prepare("SELECT dept_id FROM {$tbl} WHERE user_id = ?{$extra}");
            $st->execute([$own_uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $is_faculty = true;
                if ($d) $own_depts[] = (int)$d;
            }
        } catch (Throwable $_e) {}
    }
    return $is_faculty ? array_values(array_unique($own_depts)) : null;
}

// ── Grade label formatting ────────────────────────────────────────────────────
// The official grade sheet renders letter grades with a descriptive suffix,
// e.g. "A+(plus)", "A(regular)". Unknown grades fall back to the raw letter.
function gs_grade_label(?string $letter): string
{
    $g = strtoupper(trim((string)$letter));
    return match ($g) {
        'A+'    => 'A+(plus)',
        'A'     => 'A(regular)',
        'A-'    => 'A-(minus)',
        'B+'    => 'B+(plus)',
        'B'     => 'B(regular)',
        'B-'    => 'B-(minus)',
        'C+'    => 'C+(plus)',
        'C'     => 'C(regular)',
        'D'     => 'D',
        'F'     => 'F',
        default => ($g !== '' ? $g : '—'),
    };
}

// ── Dropdown data ─────────────────────────────────────────────────────────────

/** Exams that have at least one published mark sheet, newest first. */
function gs_exams(): array
{
    try {
        return db()->query(
            "SELECT e.id, e.exam_name, e.exam_year, e.start_date, e.end_date
               FROM ei_exams e
              WHERE EXISTS (SELECT 1 FROM result_mark_sheets ms
                             WHERE ms.exam_id = e.id AND ms.workflow_status = 'published')
              ORDER BY e.exam_year DESC, e.start_date DESC, e.exam_name ASC"
        )->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
}

function gs_exam_label(array $e): string
{
    return trim((string)$e['exam_name'] . ' ' . (string)($e['exam_year'] ?? ''));
}

/** Departments honouring the current user's scope. */
function gs_departments(): array
{
    try {
        $rows  = db()->query('SELECT id, name, faculty_label FROM dept_departments WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
        $scope = gs_dept_scope();
        if ($scope !== null) {
            $rows = array_values(array_filter($rows, fn($d) => in_array((int)$d['id'], $scope, true)));
        }
        return $rows;
    } catch (Throwable $_e) {
        return [];
    }
}

function gs_programs(): array
{
    try {
        return db()->query('SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC')->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
}

function gs_batches(): array
{
    try {
        return db()->query('SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }
}

/** Distinct offer semesters (terms), newest first. */
function gs_semesters(): array
{
    try {
        $rows = db()->query("SELECT DISTINCT semester FROM co_offers WHERE semester IS NOT NULL AND semester <> ''")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $_e) {
        return [];
    }
    usort($rows, static function ($a, $b) {
        $rank = static function (string $s): array {
            $year   = preg_match('/(\d{4})/', $s, $m) ? (int)$m[1] : 0;
            $season = 0;
            if (stripos($s, 'fall')   !== false) $season = 3;
            elseif (stripos($s, 'summer') !== false) $season = 2;
            elseif (stripos($s, 'spring') !== false) $season = 1;
            return [$year, $season];
        };
        return $rank($b) <=> $rank($a);
    });
    return $rows;
}

/** Distinct non-empty shifts from student records, alphabetical. */
function gs_shifts(): array
{
    try {
        $rows = db()->query("SELECT DISTINCT shift FROM students WHERE shift IS NOT NULL AND shift <> '' ORDER BY shift ASC")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    } catch (Throwable $_e) {
        return [];
    }
}

/** Distinct non-empty sections from student records, naturally sorted. */
function gs_sections(): array
{
    try {
        $rows = db()->query("SELECT DISTINCT section FROM students WHERE section IS NOT NULL AND section <> ''")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $_e) {
        return [];
    }
    $rows = array_map('strval', $rows);
    usort($rows, 'strnatcasecmp');
    return $rows;
}

// ── Core aggregator ───────────────────────────────────────────────────────────

/**
 * Build the grade-sheet dataset for a selection.
 *
 * @param array $f  Filters: exam_id(int, required), dept_id, program_id,
 *                  batch_id, semester, shift, section.
 * @return array {
 *   meta:    ['dept_name','faculty_label','program_name','batch_name','semester','shift','section'],
 *   courses: [ ['code'=>'EEE 427','name'=>…,'credit'=>3.0], … ]  (column order),
 *   students:[ sid => ['sid'=>…,'name'=>…,'grades'=>[code=>['letter'=>…,'point'=>…,'is_absent'=>…]],
 *                       'sgpa'=>?string,'remark'=>?string] ],
 * }
 */
function gs_build(array $f): array
{
    $exam_id    = (int)($f['exam_id'] ?? 0);
    $f_dept     = (int)($f['dept_id'] ?? 0);
    $f_program  = (int)($f['program_id'] ?? 0);
    $f_batch    = (int)($f['batch_id'] ?? 0);
    $f_semester = trim((string)($f['semester'] ?? ''));
    $f_shift    = trim((string)($f['shift'] ?? ''));
    $f_section  = trim((string)($f['section'] ?? ''));

    $empty = ['meta' => [], 'courses' => [], 'students' => []];
    if ($exam_id <= 0) return $empty;

    $where  = ["ms.exam_id = ?", "ms.workflow_status = 'published'"];
    $params = [$exam_id];

    $scope = gs_dept_scope();
    if ($scope !== null) {
        if (empty($scope)) return $empty;
        $where[] = 'o.dept_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        array_push($params, ...array_map('intval', $scope));
    }
    if ($f_dept > 0)    { $where[] = 'o.dept_id = ?';    $params[] = $f_dept; }
    if ($f_program > 0) { $where[] = 'o.program_id = ?'; $params[] = $f_program; }
    if ($f_batch > 0)   { $where[] = 'o.batch_id = ?';   $params[] = $f_batch; }
    if ($f_semester !== '') { $where[] = 'o.semester = ?'; $params[] = $f_semester; }

    try {
        $sql = "SELECT c.course_code, c.course_name, c.credit,
                       cos.sort_order AS course_sort,
                       g.student_id, g.student_sid, g.student_name,
                       g.letter_grade, g.grade_point, g.is_absent,
                       s.full_name  AS s_full_name,
                       s.student_id AS s_student_id,
                       s.shift      AS s_shift,
                       s.section    AS s_section,
                       s.batch_id   AS s_batch_id,
                       s.batch      AS s_batch_label,
                       o.batch_id   AS o_batch_id
                  FROM result_mark_sheets ms
                  JOIN co_offer_subjects cos ON cos.id = ms.offer_subject_id
                  JOIN co_offers         o   ON o.id  = cos.offer_id
                  JOIN course_curriculum c   ON c.id  = cos.curriculum_id
                  JOIN result_sheet_grades g ON g.sheet_id = ms.id
             LEFT JOIN students           s   ON s.id  = g.student_id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY cos.sort_order ASC, c.course_code ASC, g.student_sid ASC";
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $_e) {
        return $empty;
    }

    // Shared selection meta (dept / program / semester / shift / section).
    $base_meta = gs_base_meta($f_dept, $f_program, $f_semester, $f_shift, $f_section);

    // Apply the optional shift / section filter up front so every downstream
    // consumer (single sheet or per-batch split) sees the same student set.
    $rows = array_values(array_filter($rows, static function ($r) use ($f_shift, $f_section) {
        if ($f_shift !== '' && strcasecmp(trim((string)$r['s_shift']), $f_shift) !== 0) return false;
        if ($f_section !== '' && strcasecmp(trim((string)$r['s_section']), $f_section) !== 0) return false;
        return true;
    }));

    return gs_assemble_dataset($rows, $base_meta);
}

/**
 * Build one grade-sheet dataset per batch for a selection.
 *
 * When the selection resolves to a single batch (or no distinguishable batch),
 * a single group is returned — identical to gs_build(). When students from more
 * than one batch match, each batch is returned as its own self-contained grade
 * sheet (own course columns, own student list, own batch caption) so the
 * preview and print output produce *separate* sheets per batch.
 *
 * @return array[] List of datasets, each: ['meta'=>…,'courses'=>…,'students'=>…].
 */
function gs_build_groups(array $f): array
{
    $exam_id    = (int)($f['exam_id'] ?? 0);
    $f_dept     = (int)($f['dept_id'] ?? 0);
    $f_program  = (int)($f['program_id'] ?? 0);
    $f_batch    = (int)($f['batch_id'] ?? 0);
    $f_semester = trim((string)($f['semester'] ?? ''));
    $f_shift    = trim((string)($f['shift'] ?? ''));
    $f_section  = trim((string)($f['section'] ?? ''));

    if ($exam_id <= 0) return [];

    $where  = ["ms.exam_id = ?", "ms.workflow_status = 'published'"];
    $params = [$exam_id];

    $scope = gs_dept_scope();
    if ($scope !== null) {
        if (empty($scope)) return [];
        $where[] = 'o.dept_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        array_push($params, ...array_map('intval', $scope));
    }
    if ($f_dept > 0)    { $where[] = 'o.dept_id = ?';    $params[] = $f_dept; }
    if ($f_program > 0) { $where[] = 'o.program_id = ?'; $params[] = $f_program; }
    if ($f_batch > 0)   { $where[] = 'o.batch_id = ?';   $params[] = $f_batch; }
    if ($f_semester !== '') { $where[] = 'o.semester = ?'; $params[] = $f_semester; }

    try {
        $sql = "SELECT c.course_code, c.course_name, c.credit,
                       cos.sort_order AS course_sort,
                       g.student_id, g.student_sid, g.student_name,
                       g.letter_grade, g.grade_point, g.is_absent,
                       s.full_name  AS s_full_name,
                       s.student_id AS s_student_id,
                       s.shift      AS s_shift,
                       s.section    AS s_section,
                       s.batch_id   AS s_batch_id,
                       s.batch      AS s_batch_label,
                       o.batch_id   AS o_batch_id
                  FROM result_mark_sheets ms
                  JOIN co_offer_subjects cos ON cos.id = ms.offer_subject_id
                  JOIN co_offers         o   ON o.id  = cos.offer_id
                  JOIN course_curriculum c   ON c.id  = cos.curriculum_id
                  JOIN result_sheet_grades g ON g.sheet_id = ms.id
             LEFT JOIN students           s   ON s.id  = g.student_id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY cos.sort_order ASC, c.course_code ASC, g.student_sid ASC";
        $st = db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $_e) {
        return [];
    }

    $base_meta = gs_base_meta($f_dept, $f_program, $f_semester, $f_shift, $f_section);

    // Apply optional shift / section filter.
    $rows = array_values(array_filter($rows, static function ($r) use ($f_shift, $f_section) {
        if ($f_shift !== '' && strcasecmp(trim((string)$r['s_shift']), $f_shift) !== 0) return false;
        if ($f_section !== '' && strcasecmp(trim((string)$r['s_section']), $f_section) !== 0) return false;
        return true;
    }));

    if (empty($rows)) return [];

    // ── Partition rows by batch ───────────────────────────────────────────────
    // Prefer the student's own batch; fall back to the offer batch when a
    // student record is missing. Rows with no resolvable batch share one group.
    $buckets = [];                 // batch_key => ['batch_id'=>?int,'rows'=>[]]
    foreach ($rows as $r) {
        $bid = $r['s_batch_id'] ?? $r['o_batch_id'] ?? null;
        $bid = ($bid !== null && (int)$bid > 0) ? (int)$bid : 0;
        $key = (string)$bid;
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['batch_id' => $bid, 'rows' => []];
        }
        $buckets[$key]['rows'][] = $r;
    }

    // A single batch (or unresolved) behaves exactly like the old single sheet.
    $batch_names = gs_batch_names();
    $groups = [];
    foreach ($buckets as $bucket) {
        $meta = $base_meta;
        $bid  = (int)$bucket['batch_id'];
        if ($bid > 0) {
            $meta['batch_name'] = $batch_names[$bid]
                ?? (string)($bucket['rows'][0]['s_batch_label'] ?? '');
        }
        $groups[] = gs_assemble_dataset($bucket['rows'], $meta);
    }

    // Order sheets by batch caption for a stable, predictable sequence.
    usort($groups, static fn($a, $b) => strnatcasecmp(
        (string)($a['meta']['batch_name'] ?? ''),
        (string)($b['meta']['batch_name'] ?? '')
    ));

    return $groups;
}

/** Selection meta shared by every grade-sheet group (batch_name filled later). */
function gs_base_meta(int $f_dept, int $f_program, string $f_semester, string $f_shift, string $f_section): array
{
    $meta = [
        'dept_name'     => '',
        'faculty_label' => '',
        'program_name'  => '',
        'batch_name'    => '',
        'semester'      => $f_semester,
        'shift'         => $f_shift,
        'section'       => $f_section,
    ];
    try {
        if ($f_dept > 0) {
            $q = db()->prepare('SELECT name, faculty_label FROM dept_departments WHERE id = ?');
            $q->execute([$f_dept]);
            if ($d = $q->fetch()) {
                $meta['dept_name']     = (string)$d['name'];
                $meta['faculty_label'] = (string)($d['faculty_label'] ?? '');
            }
        }
        if ($f_program > 0) {
            $q = db()->prepare('SELECT program_name FROM dept_academic_programs WHERE id = ?');
            $q->execute([$f_program]);
            $meta['program_name'] = (string)($q->fetchColumn() ?: '');
        }
    } catch (Throwable $_e) {}
    return $meta;
}

/** id => name map of active student batches. */
function gs_batch_names(): array
{
    try {
        $rows = db()->query('SELECT id, name FROM student_batches')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('strval', $rows);
    } catch (Throwable $_e) {
        return [];
    }
}

/**
 * Turn a set of result rows into a self-contained grade-sheet dataset
 * (distinct course columns + per-student grades + SGPA/remark).
 */
function gs_assemble_dataset(array $rows, array $meta): array
{
    // ── Columns: distinct courses in stable order ─────────────────────────────
    $courses = [];               // code => ['code','name','credit']
    foreach ($rows as $r) {
        $code = trim((string)$r['course_code']);
        if ($code === '' || isset($courses[$code])) continue;
        $courses[$code] = [
            'code'   => $code,
            'name'   => (string)$r['course_name'],
            'credit' => $r['credit'] !== null ? (float)$r['credit'] : null,
        ];
    }

    // ── Students × grades ─────────────────────────────────────────────────────
    $students = [];
    foreach ($rows as $r) {
        $sid  = (string)($r['s_student_id'] ?? $r['student_sid']);
        $code = trim((string)$r['course_code']);
        if (!isset($students[$sid])) {
            $students[$sid] = [
                'sid'    => $sid,
                'name'   => (string)($r['s_full_name'] ?? $r['student_name']),
                'grades' => [],
            ];
        }
        $students[$sid]['grades'][$code] = [
            'letter'    => (string)$r['letter_grade'],
            'point'     => $r['grade_point'] !== null ? (float)$r['grade_point'] : null,
            'is_absent' => (int)$r['is_absent'] === 1,
        ];
    }

    // Natural sort students by ID (…-1, …-2, …-10).
    uksort($students, 'strnatcasecmp');

    // ── SGPA / remark per student ─────────────────────────────────────────────
    foreach ($students as $sid => &$sd) {
        $sd['sgpa']   = null;
        $sd['remark'] = null;

        $has_grade = false;
        $total_pts = 0.0;
        $total_cr  = 0.0;
        $has_fail  = false;
        foreach ($sd['grades'] as $code => $ge) {
            if ($ge['is_absent']) continue;
            $letter = strtoupper(trim($ge['letter']));
            if ($letter === '' || $letter === 'INCOM') continue;
            $has_grade = true;
            // A failing (F) course stays visible in its own subject cell, but is
            // skipped here: SGPA is computed only from passed/completed courses.
            if ($letter === 'F') { $has_fail = true; continue; }
            $credit = $courses[$code]['credit'] ?? null;
            if ($ge['point'] !== null && $credit !== null && $credit > 0) {
                $total_cr  += $credit;
                $total_pts += $ge['point'] * $credit;
            }
        }

        if (!$has_grade) {
            $sd['remark'] = 'Absent';
        } elseif ($total_cr > 0) {
            // SGPA on the passed/completed courses; flag any F in the remark.
            $sd['sgpa']   = number_format($total_pts / $total_cr, 2);
            if ($has_fail) $sd['remark'] = 'F';
        } elseif ($has_fail) {
            // No completed course to average — only failing grade(s).
            $sd['remark'] = 'F';
        } else {
            $sd['remark'] = 'N/A';
        }
    }
    unset($sd);

    return [
        'meta'     => $meta,
        'courses'  => array_values($courses),
        'students' => $students,
    ];
}
