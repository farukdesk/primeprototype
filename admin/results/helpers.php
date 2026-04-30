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

// ── Grade validation constants ────────────────────────────────────────────────

const RM_MARKS_MIN = 0;
const RM_MARKS_MAX = 100;

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
 * Returns marking categories for a single subject, ordered by sort_order.
 */
function rm_get_mark_categories(int $subject_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM result_mark_categories
         WHERE subject_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$subject_id]);
    return $stmt->fetchAll();
}

/**
 * Returns all marking categories for all subjects of an exam,
 * keyed by subject_id: [ subject_id => [ category, ... ] ]
 */
function rm_get_all_mark_categories(int $exam_id): array
{
    $stmt = db()->prepare(
        'SELECT mc.*
         FROM result_mark_categories mc
         JOIN result_subjects rs ON rs.id = mc.subject_id
         WHERE rs.exam_id = ?
         ORDER BY mc.subject_id ASC, mc.sort_order ASC, mc.id ASC'
    );
    $stmt->execute([$exam_id]);
    $rows = $stmt->fetchAll();
    $keyed = [];
    foreach ($rows as $row) {
        $keyed[(int)$row['subject_id']][] = $row;
    }
    return $keyed;
}

/**
 * Returns grade-detail rows keyed by [grade_id][category_id].
 */
function rm_get_grade_details_for_exam(int $exam_id): array
{
    $stmt = db()->prepare(
        'SELECT gd.grade_id, gd.category_id, gd.marks_obtained
         FROM result_grade_details gd
         JOIN result_grades rg ON rg.id = gd.grade_id
         WHERE rg.exam_id = ?'
    );
    $stmt->execute([$exam_id]);
    $rows = $stmt->fetchAll();
    $keyed = [];
    foreach ($rows as $row) {
        $keyed[(int)$row['grade_id']][(int)$row['category_id']] = (float)$row['marks_obtained'];
    }
    return $keyed;
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

/**
 * Returns the 12 academic semester labels (shared with course-curriculum).
 */
function rm_academic_semester_labels(): array
{
    return [
        1  => '1st Year 1st Semester',
        2  => '1st Year 2nd Semester',
        3  => '1st Year 3rd Semester',
        4  => '2nd Year 1st Semester',
        5  => '2nd Year 2nd Semester',
        6  => '2nd Year 3rd Semester',
        7  => '3rd Year 1st Semester',
        8  => '3rd Year 2nd Semester',
        9  => '3rd Year 3rd Semester',
        10 => '4th Year 1st Semester',
        11 => '4th Year 2nd Semester',
        12 => '4th Year 3rd Semester',
    ];
}

function rm_semester_list(): array
{
    $list = [];
    $end_year = (int)date('Y') + 10;
    for ($y = 2002; $y <= $end_year; $y++) {
        $list[] = 'Summer-' . $y;
        $list[] = 'Fall-'   . $y;
        $list[] = 'Spring-' . $y;
    }
    return $list;
}
