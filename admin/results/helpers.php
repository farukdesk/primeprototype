<?php
/**
 * Result Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function rm_can_view(): bool
{
    return is_super_admin() || can_access('results', 'can_view');
}

function rm_is_staff(): bool
{
    return is_super_admin() || can_access('results', 'can_edit');
}

function rm_can_create(): bool
{
    return is_super_admin() || can_access('results', 'can_create');
}

function rm_can_delete(): bool
{
    return is_super_admin() || can_access('results', 'can_delete');
}

// ── Grading scale ─────────────────────────────────────────────────────────────

/**
 * Returns the grading scale as an array of threshold rules.
 * Each entry: [min, max_exclusive, letter, point]
 */
function rm_grading_scale(): array
{
    return [
        [80,  PHP_INT_MAX, 'A+', 4.00],
        [75,  80,          'A',  3.75],
        [70,  75,          'A-', 3.50],
        [65,  70,          'B+', 3.25],
        [60,  65,          'B',  3.00],
        [55,  60,          'B-', 2.75],
        [50,  55,          'C+', 2.50],
        [45,  50,          'C',  2.25],
        [40,  45,          'D',  2.00],
        [0,   40,          'F',  0.00],
    ];
}

/**
 * Compute letter grade and grade point from a numerical mark.
 * Returns ['letter' => 'A+', 'point' => 4.00] or null if marks is null/invalid.
 */
function rm_compute_grade(?float $marks): ?array
{
    if ($marks === null) return null;
    foreach (rm_grading_scale() as [$min, $max, $letter, $point]) {
        if ($marks >= $min && ($max === PHP_INT_MAX || $marks < $max)) {
            return ['letter' => $letter, 'point' => $point];
        }
    }
    return ['letter' => 'F', 'point' => 0.00];
}

// ── Data fetchers ─────────────────────────────────────────────────────────────

function rm_get_exam(int $id): array
{
    $stmt = db()->prepare(
        'SELECT e.*,
                d.name         AS dept_name,
                d.faculty_label,
                p.program_name
         FROM result_exams e
         JOIN dept_departments d       ON d.id = e.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = e.program_id
         WHERE e.id = ?'
    );
    $stmt->execute([$id]);
    $exam = $stmt->fetch();
    if (!$exam) {
        flash_set('error', 'Result exam not found.');
        redirect(APP_URL . '/results/index.php');
    }
    return $exam;
}

function rm_get_subjects(int $exam_id): array
{
    $stmt = db()->prepare(
        'SELECT s.*, cc.course_code AS cc_code, cc.course_name AS cc_name
         FROM result_subjects s
         LEFT JOIN course_curriculum cc ON cc.id = s.curriculum_id
         WHERE s.exam_id = ?
         ORDER BY s.sort_order ASC, s.id ASC'
    );
    $stmt->execute([$exam_id]);
    return $stmt->fetchAll();
}

/**
 * Returns grades keyed by [student_sid][subject_id].
 */
function rm_get_grades(int $exam_id): array
{
    $stmt = db()->prepare(
        'SELECT g.*, s.student_id AS s_student_id, s.full_name AS s_full_name
         FROM result_grades g
         LEFT JOIN students s ON s.id = g.student_id
         WHERE g.exam_id = ?
         ORDER BY g.student_name ASC, g.subject_id ASC'
    );
    $stmt->execute([$exam_id]);
    $rows = $stmt->fetchAll();

    $keyed = [];
    foreach ($rows as $row) {
        $sid = $row['student_sid'];
        $subj = (int)$row['subject_id'];
        $keyed[$sid][$subj] = $row;
    }
    return $keyed;
}

/**
 * Returns distinct student rows for an exam (one row per student).
 */
function rm_get_exam_students(int $exam_id): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT g.student_sid, g.student_name,
                s.full_name AS s_full_name, s.student_id AS s_student_id
         FROM result_grades g
         LEFT JOIN students s ON s.id = g.student_id
         WHERE g.exam_id = ?
         ORDER BY g.student_name ASC'
    );
    $stmt->execute([$exam_id]);
    return $stmt->fetchAll();
}

// ── Semester / batch list helpers ─────────────────────────────────────────────

function rm_semester_list(): array
{
    $list = [];
    for ($y = 2002; $y <= 2030; $y++) {
        $list[] = 'Summer-' . $y;
        $list[] = 'Fall-'   . $y;
        $list[] = 'Spring-' . $y;
    }
    return $list;
}
