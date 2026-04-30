<?php
/**
 * Results Workflow – Shared Helpers
 * 4-stage approval: Teacher Entry → Reviewer → Dept Head → Controller
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Permission helpers ────────────────────────────────────────────────────────

/** Can the current user enter marks (teacher role)? */
function wf_can_enter(): bool
{
    return is_super_admin() || can_access('results-entry', 'can_create');
}

/** Can the current user edit a draft/returned sheet they own? */
function wf_can_edit_sheet(array $sheet): bool
{
    if (is_super_admin()) return true;
    $user = auth_user();
    if (!$user) return false;
    $owns = (int)$sheet['created_by'] === (int)$user['id'];
    $editable_status = in_array($sheet['workflow_status'], ['draft', 'returned'], true);
    return $owns && $editable_status && can_access('results-entry', 'can_edit');
}

/** Can the current user perform the reviewer action? */
function wf_can_review(): bool
{
    return is_super_admin() || can_access('results-review', 'can_edit');
}

/** Can the current user perform the HOD action? */
function wf_can_hod(): bool
{
    return is_super_admin() || can_access('results-hod', 'can_edit');
}

/** Can the current user publish (controller)? */
function wf_can_publish(): bool
{
    return is_super_admin() || can_access('results-controller', 'can_edit');
}

/** Has at least one workflow role (for index dashboard)? */
function wf_has_any_role(): bool
{
    return wf_can_enter() || wf_can_review() || wf_can_hod() || wf_can_publish();
}

// ── Status badge helper ───────────────────────────────────────────────────────

function wf_status_badge(string $status): string
{
    $map = [
        'draft'        => ['bg-secondary',       'Draft'],
        'submitted'    => ['bg-primary',          'Submitted'],
        'under_review' => ['bg-info text-dark',   'Under Review'],
        'hod_approved' => ['bg-warning text-dark','HOD Approved'],
        'published'    => ['bg-success',          'Published'],
        'returned'     => ['bg-danger',           'Returned'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

// ── Grading ───────────────────────────────────────────────────────────────────

/** Max marks per component – used for validation */
const WF_MAX_ATTENDANCE  = 10;
const WF_MAX_CLASS_TEST  = 10;
const WF_MAX_MID_TERM    = 30;
const WF_MAX_FINAL_EXAM  = 50;
const WF_MAX_TOTAL       = 100;

function wf_grading_scale(): array
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

function wf_compute_grade(?float $marks): ?array
{
    if ($marks === null) return null;
    foreach (wf_grading_scale() as [$min, $max, $letter, $point]) {
        if ($marks >= $min && ($max === PHP_INT_MAX || $marks < $max)) {
            return ['letter' => $letter, 'point' => $point];
        }
    }
    return ['letter' => 'F', 'point' => 0.00];
}

// ── Data fetchers ─────────────────────────────────────────────────────────────

function wf_get_sheet(int $id): array
{
    $stmt = db()->prepare(
        'SELECT ms.*,
                d.name          AS dept_name,
                d.faculty_label,
                p.program_name,
                u_c.username    AS creator_name,
                u_r.username    AS reviewer_name,
                u_h.username    AS hod_name,
                u_p.username    AS publisher_name
         FROM result_mark_sheets ms
         JOIN dept_departments d             ON d.id  = ms.dept_id
         LEFT JOIN dept_academic_programs p  ON p.id  = ms.program_id
         LEFT JOIN users u_c                 ON u_c.id = ms.created_by
         LEFT JOIN users u_r                 ON u_r.id = ms.reviewed_by
         LEFT JOIN users u_h                 ON u_h.id = ms.hod_approved_by
         LEFT JOIN users u_p                 ON u_p.id = ms.published_by
         WHERE ms.id = ?'
    );
    $stmt->execute([$id]);
    $sheet = $stmt->fetch();
    if (!$sheet) {
        flash_set('error', 'Mark sheet not found.');
        redirect(APP_URL . '/results/index.php');
    }
    return $sheet;
}

function wf_get_grades(int $sheet_id): array
{
    $stmt = db()->prepare(
        'SELECT g.*, s.full_name AS s_full_name, s.student_id AS s_student_id
         FROM result_sheet_grades g
         LEFT JOIN students s ON s.id = g.student_id
         WHERE g.sheet_id = ?
         ORDER BY g.student_name ASC, g.id ASC'
    );
    $stmt->execute([$sheet_id]);
    return $stmt->fetchAll();
}

/**
 * Upsert (insert or update) a single student grade row.
 * Computes total and grade automatically.
 */
function wf_upsert_grade(
    int $sheet_id,
    int $student_id_pk,
    string $student_sid,
    string $student_name,
    int $is_absent,
    ?float $attendance,
    ?float $class_test,
    ?float $mid_term,
    ?float $final_exam
): void {
    if ($is_absent) {
        // Absent: store absent flag, null marks, grade F
        $total  = null;
        $letter = 'F';
        $point  = 0.00;
    } else {
        // Clamp each component
        $att  = ($attendance  !== null) ? min(max($attendance,  0), WF_MAX_ATTENDANCE)  : null;
        $ct   = ($class_test  !== null) ? min(max($class_test,  0), WF_MAX_CLASS_TEST)  : null;
        $mid  = ($mid_term    !== null) ? min(max($mid_term,    0), WF_MAX_MID_TERM)    : null;
        $fin  = ($final_exam  !== null) ? min(max($final_exam,  0), WF_MAX_FINAL_EXAM)  : null;
        $total = ($att ?? 0) + ($ct ?? 0) + ($mid ?? 0) + ($fin ?? 0);
        if ($total > WF_MAX_TOTAL) $total = WF_MAX_TOTAL;
        $g      = wf_compute_grade($total);
        $letter = $g['letter'];
        $point  = $g['point'];

        // Restore null if all components null
        if ($att === null && $ct === null && $mid === null && $fin === null) {
            $total  = null;
            $letter = null;
            $point  = null;
        } else {
            $attendance  = $att;
            $class_test  = $ct;
            $mid_term    = $mid;
            $final_exam  = $fin;
        }
    }

    db()->prepare(
        'INSERT INTO result_sheet_grades
           (sheet_id, student_id, student_sid, student_name,
            is_absent, attendance, class_test, mid_term, final_exam,
            total_marks, letter_grade, grade_point)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           student_name  = VALUES(student_name),
           is_absent     = VALUES(is_absent),
           attendance    = VALUES(attendance),
           class_test    = VALUES(class_test),
           mid_term      = VALUES(mid_term),
           final_exam    = VALUES(final_exam),
           total_marks   = VALUES(total_marks),
           letter_grade  = VALUES(letter_grade),
           grade_point   = VALUES(grade_point)'
    )->execute([
        $sheet_id,
        $student_id_pk ?: null,
        $student_sid,
        $student_name,
        $is_absent ? 1 : 0,
        $is_absent ? null : ($attendance ?? null),
        $is_absent ? null : ($class_test ?? null),
        $is_absent ? null : ($mid_term   ?? null),
        $is_absent ? null : ($final_exam ?? null),
        $is_absent ? null : $total,
        $is_absent ? 'F' : $letter,
        $is_absent ? 0.00 : $point,
    ]);
}

// ── Semester list (shared) ────────────────────────────────────────────────────

function wf_semester_list(): array
{
    $list = [];
    $end_year = (int)date('Y') + 5;
    for ($y = 2010; $y <= $end_year; $y++) {
        $list[] = 'Spring-' . $y;
        $list[] = 'Summer-' . $y;
        $list[] = 'Fall-'   . $y;
    }
    return array_reverse($list);
}
