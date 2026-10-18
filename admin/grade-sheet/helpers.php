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
                       s.section    AS s_section
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
        // Optional shift / section filter (applied on the student record).
        if ($f_shift !== '' && strcasecmp(trim((string)$r['s_shift']), $f_shift) !== 0) continue;
        if ($f_section !== '' && strcasecmp(trim((string)$r['s_section']), $f_section) !== 0) continue;

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
            if ($letter === 'F') { $has_fail = true; continue; }
            $credit = $courses[$code]['credit'] ?? null;
            if ($ge['point'] !== null && $credit !== null && $credit > 0) {
                $total_cr  += $credit;
                $total_pts += $ge['point'] * $credit;
            }
        }

        if (!$has_grade) {
            $sd['remark'] = 'Absent';
        } elseif ($has_fail) {
            // A failing grade means no SGPA until the course is cleared.
            $sd['remark'] = 'F';
        } elseif ($total_cr > 0) {
            $sd['sgpa'] = number_format($total_pts / $total_cr, 2);
        } else {
            $sd['remark'] = 'N/A';
        }
    }
    unset($sd);

    // ── Meta (department / program / batch names + selection labels) ──────────
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
        if ($f_batch > 0) {
            $q = db()->prepare('SELECT name FROM student_batches WHERE id = ?');
            $q->execute([$f_batch]);
            $meta['batch_name'] = (string)($q->fetchColumn() ?: '');
        }
    } catch (Throwable $_e) {}

    return [
        'meta'     => $meta,
        'courses'  => array_values($courses),
        'students' => $students,
    ];
}
