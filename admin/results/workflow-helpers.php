<?php
/**
 * Results Workflow – Shared Helpers
 *
 * Fully configurable approval chains.
 * Admin defines chains (dept/program scoped).
 * Each chain has ordered steps with a user_group per step.
 * No hard-coded roles anywhere.
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Grading ───────────────────────────────────────────────────────────────────

const WF_MAX_ATTENDANCE = 10;
const WF_MAX_CLASS_TEST = 10;
const WF_MAX_MID_TERM   = 30;
const WF_MAX_FINAL_EXAM = 50;
const WF_MAX_TOTAL      = 100;

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

// ── Semester list ─────────────────────────────────────────────────────────────

function wf_semester_list(): array
{
    $list = [];
    $end  = (int)date('Y') + 5;
    for ($y = 2010; $y <= $end; $y++) {
        $list[] = 'Spring-' . $y;
        $list[] = 'Summer-' . $y;
        $list[] = 'Fall-'   . $y;
    }
    return array_reverse($list);
}

// ── Current user's group IDs ──────────────────────────────────────────────────

function wf_user_group_ids(): array
{
    $user = auth_user();
    return $user ? ($user['group_ids'] ?? [(int)$user['group_id']]) : [];
}

// ── Chain resolution ──────────────────────────────────────────────────────────

/**
 * Resolve the best-matching active chain for a dept+program.
 * Priority: exact dept+program > dept-only > global (dept IS NULL).
 */
function wf_resolve_chain(int $dept_id, ?int $program_id): ?array
{
    $rows = db()->prepare(
        'SELECT * FROM wf_chains WHERE is_active = 1
         AND (dept_id = ? OR dept_id IS NULL)
         ORDER BY
           CASE WHEN dept_id = ? AND program_id = ? THEN 0
                WHEN dept_id = ? AND program_id IS NULL THEN 1
                ELSE 2 END ASC,
           id ASC
         LIMIT 1'
    );
    $rows->execute([$dept_id, $dept_id, $program_id ?: null, $dept_id]);
    return $rows->fetch() ?: null;
}

/**
 * Get all steps for a chain, ordered by step_order.
 */
function wf_get_chain_steps(int $chain_id): array
{
    $stmt = db()->prepare(
        'SELECT s.*, g.name AS group_name
         FROM wf_chain_steps s
         JOIN user_groups g ON g.id = s.group_id
         WHERE s.chain_id = ?
         ORDER BY s.step_order ASC'
    );
    $stmt->execute([$chain_id]);
    return $stmt->fetchAll();
}

/**
 * Get a single step by chain_id + step_order.
 */
function wf_get_step(int $chain_id, int $step_order): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, g.name AS group_name
         FROM wf_chain_steps s
         JOIN user_groups g ON g.id = s.group_id
         WHERE s.chain_id = ? AND s.step_order = ?'
    );
    $stmt->execute([$chain_id, $step_order]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the entry step (is_entry=1) of a chain.
 */
function wf_get_entry_step(int $chain_id): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, g.name AS group_name
         FROM wf_chain_steps s
         JOIN user_groups g ON g.id = s.group_id
         WHERE s.chain_id = ? AND s.is_entry = 1
         LIMIT 1'
    );
    $stmt->execute([$chain_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the step AFTER current_step_order in a chain (null = no next step).
 */
function wf_get_next_step(int $chain_id, int $current_step_order): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, g.name AS group_name
         FROM wf_chain_steps s
         JOIN user_groups g ON g.id = s.group_id
         WHERE s.chain_id = ? AND s.step_order > ?
         ORDER BY s.step_order ASC
         LIMIT 1'
    );
    $stmt->execute([$chain_id, $current_step_order]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the step BEFORE current_step_order in a chain (null = no prev step).
 */
function wf_get_prev_step(int $chain_id, int $current_step_order): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, g.name AS group_name
         FROM wf_chain_steps s
         JOIN user_groups g ON g.id = s.group_id
         WHERE s.chain_id = ? AND s.step_order < ?
         ORDER BY s.step_order DESC
         LIMIT 1'
    );
    $stmt->execute([$chain_id, $current_step_order]);
    return $stmt->fetch() ?: null;
}

// ── User capability checks ────────────────────────────────────────────────────

/**
 * Can the current user create mark sheets?
 * True if their group is the entry step of any active chain
 * that applies to at least one dept in their scope.
 */
function wf_can_create_sheet(): bool
{
    if (is_super_admin()) return true;
    $group_ids = wf_user_group_ids();
    if (empty($group_ids)) return false;

    $dept_scope = get_dept_scope();

    $phs = implode(',', array_fill(0, count($group_ids), '?'));
    $params = $group_ids;

    $extra = '';
    if ($dept_scope !== null) {
        if (empty($dept_scope)) return false;
        $dphs    = implode(',', array_fill(0, count($dept_scope), '?'));
        $extra   = " AND (c.dept_id IN ($dphs) OR c.dept_id IS NULL)";
        array_push($params, ...$dept_scope);
    }

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM wf_chain_steps s
         JOIN wf_chains c ON c.id = s.chain_id AND c.is_active = 1
         WHERE s.is_entry = 1 AND s.group_id IN ($phs)$extra"
    );
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Returns the chain+entry-step rows the current user can submit for.
 * Used to filter the dept/program dropdowns in mark-entry.
 * Returns array of ['chain_id', 'dept_id', 'program_id', 'step_order', ...]
 */
function wf_get_creatable_chains(): array
{
    if (is_super_admin()) {
        $stmt = db()->prepare(
            'SELECT c.id AS chain_id, c.dept_id, c.program_id, c.name AS chain_name,
                    s.step_order, s.step_label, s.group_id
             FROM wf_chains c
             JOIN wf_chain_steps s ON s.chain_id = c.id AND s.is_entry = 1
             WHERE c.is_active = 1
             ORDER BY c.dept_id ASC, c.program_id ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $group_ids  = wf_user_group_ids();
    if (empty($group_ids)) return [];

    $dept_scope = get_dept_scope();
    $phs        = implode(',', array_fill(0, count($group_ids), '?'));
    $params     = $group_ids;

    $extra = '';
    if ($dept_scope !== null) {
        if (empty($dept_scope)) return [];
        $dphs  = implode(',', array_fill(0, count($dept_scope), '?'));
        $extra = " AND (c.dept_id IN ($dphs) OR c.dept_id IS NULL)";
        array_push($params, ...$dept_scope);
    }

    $stmt = db()->prepare(
        "SELECT c.id AS chain_id, c.dept_id, c.program_id, c.name AS chain_name,
                s.step_order, s.step_label, s.group_id
         FROM wf_chain_steps s
         JOIN wf_chains c ON c.id = s.chain_id AND c.is_active = 1
         WHERE s.is_entry = 1 AND s.group_id IN ($phs)$extra
         ORDER BY c.dept_id ASC, c.program_id ASC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Can the current user approve/reject the given sheet?
 * True if their group matches the step at sheet's current_step_order.
 */
function wf_can_approve_sheet(array $sheet): bool
{
    if (is_super_admin()) return true;
    if ($sheet['workflow_status'] !== 'pending') return false;

    $chain_id    = (int)$sheet['chain_id'];
    $step_order  = (int)$sheet['current_step_order'];
    $group_ids   = wf_user_group_ids();
    if (empty($group_ids)) return false;

    $step = wf_get_step($chain_id, $step_order);
    if (!$step) return false;

    return in_array((int)$step['group_id'], $group_ids, true);
}

/**
 * Does the current user have any approver role in any active chain?
 * (non-entry steps). Used to show/hide the Queue tab.
 */
function wf_has_approver_role(): bool
{
    if (is_super_admin()) return true;
    $group_ids = wf_user_group_ids();
    if (empty($group_ids)) return false;

    $phs  = implode(',', array_fill(0, count($group_ids), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM wf_chain_steps s
         JOIN wf_chains c ON c.id = s.chain_id AND c.is_active = 1
         WHERE s.is_entry = 0 AND s.group_id IN ($phs)"
    );
    $stmt->execute($group_ids);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get all pending sheets the current user can approve.
 */
function wf_get_approver_queue(): array
{
    $group_ids  = wf_user_group_ids();
    $dept_scope = get_dept_scope();

    if (!is_super_admin() && empty($group_ids)) return [];

    $where  = ["ms.workflow_status = 'pending'"];
    $params = [];

    if (!is_super_admin()) {
        $phs     = implode(',', array_fill(0, count($group_ids), '?'));
        // Sheet's current step must match user's group
        $where[] = "EXISTS (
            SELECT 1 FROM wf_chain_steps s2
            WHERE s2.chain_id = ms.chain_id
              AND s2.step_order = ms.current_step_order
              AND s2.group_id IN ($phs)
        )";
        array_push($params, ...$group_ids);

        if ($dept_scope !== null) {
            if (empty($dept_scope)) return [];
            $dphs    = implode(',', array_fill(0, count($dept_scope), '?'));
            $where[] = "(ms.dept_id IN ($dphs) OR c.dept_id IS NULL)";
            array_push($params, ...$dept_scope);
        }
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);

    try {
        $stmt = db()->prepare(
            "SELECT ms.*,
                    d.name          AS dept_name,
                    p.program_name,
                    c.name          AS chain_name,
                    u.username      AS creator_name,
                    s.step_label    AS current_step_label,
                    s.group_id      AS current_group_id,
                    g.name          AS current_group_name,
                    s.is_final      AS current_step_is_final,
                    (SELECT COUNT(*) FROM result_sheet_grades sg WHERE sg.sheet_id = ms.id) AS student_count
             FROM result_mark_sheets ms
             JOIN dept_departments d              ON d.id  = ms.dept_id
             LEFT JOIN dept_academic_programs p   ON p.id  = ms.program_id
             LEFT JOIN wf_chains c                ON c.id  = ms.chain_id
             LEFT JOIN wf_chain_steps s           ON s.chain_id = ms.chain_id
                                                 AND s.step_order = ms.current_step_order
             LEFT JOIN user_groups g              ON g.id  = s.group_id
             LEFT JOIN users u                    ON u.id  = ms.created_by
             $where_sql
             ORDER BY ms.updated_at ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// ── Sheet data fetchers ───────────────────────────────────────────────────────

function wf_get_sheet(int $id): array
{
    $stmt = db()->prepare(
        'SELECT ms.*,
                d.name           AS dept_name,
                d.faculty_label,
                p.program_name,
                c.name           AS chain_name,
                u.username       AS creator_name,
                s.step_label     AS current_step_label,
                s.group_id       AS current_group_id,
                g.name           AS current_group_name,
                s.is_final       AS current_step_is_final,
                s.is_entry       AS current_step_is_entry
         FROM result_mark_sheets ms
         JOIN dept_departments d              ON d.id = ms.dept_id
         LEFT JOIN dept_academic_programs p   ON p.id = ms.program_id
         LEFT JOIN wf_chains c               ON c.id = ms.chain_id
         LEFT JOIN wf_chain_steps s          ON s.chain_id = ms.chain_id
                                           AND s.step_order = ms.current_step_order
         LEFT JOIN user_groups g             ON g.id = s.group_id
         LEFT JOIN users u                   ON u.id = ms.created_by
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
         ORDER BY g.student_name ASC, g.student_sid ASC'
    );
    $stmt->execute([$sheet_id]);
    return $stmt->fetchAll();
}

function wf_get_sheet_history(int $sheet_id): array
{
    $stmt = db()->prepare(
        'SELECT h.*, u.username AS actor_name, g.name AS group_name
         FROM wf_sheet_history h
         LEFT JOIN users u         ON u.id = h.acted_by
         LEFT JOIN user_groups g   ON g.id = h.group_id
         WHERE h.sheet_id = ?
         ORDER BY h.acted_at ASC'
    );
    $stmt->execute([$sheet_id]);
    return $stmt->fetchAll();
}

// ── Grade upsert ──────────────────────────────────────────────────────────────

/**
 * Upsert a student's grades for a mark sheet.
 *
 * @param int    $sheet_id
 * @param int    $student_id_pk
 * @param string $student_sid
 * @param string $student_name
 * @param int    $is_absent
 * @param array  $marks  Indexed array of mark values (float|null) for each distribution component.
 *                       Index 0 → attendance (legacy), 1 → class_test, 2 → mid_term, 3 → final_exam.
 *                       For ≥5 distributions, extra values are stored only in marks_json.
 */
function wf_upsert_grade(
    int $sheet_id,
    int $student_id_pk,
    string $student_sid,
    string $student_name,
    int $is_absent,
    array $marks
): void {
    if ($is_absent) {
        $total      = null;
        $letter     = 'F';
        $point      = 0.00;
        $marks_json = null;
        // Keep legacy columns null
        $att = $ct = $mid = $fin = null;
    } else {
        // Clamp each mark to its max (use WF constants for first 4; no hard limit for extras)
        $maxes = [WF_MAX_ATTENDANCE, WF_MAX_CLASS_TEST, WF_MAX_MID_TERM, WF_MAX_FINAL_EXAM];
        $clamped = [];
        foreach ($marks as $i => $v) {
            if ($v === null) {
                $clamped[$i] = null;
            } else {
                $max = $maxes[$i] ?? PHP_INT_MAX;
                $clamped[$i] = min(max((float)$v, 0), $max);
            }
        }

        $all_null = (count(array_filter($clamped, fn($v) => $v !== null)) === 0);
        if ($all_null) {
            $total = null; $letter = null; $point = null;
            $marks_json = null;
        } else {
            $sum   = array_sum(array_map(fn($v) => $v ?? 0, $clamped));
            $total = min($sum, WF_MAX_TOTAL);
            $g     = wf_compute_grade($total);
            $letter = $g['letter'];
            $point  = $g['point'];
            // Always store full marks as JSON (re-encodes for accuracy)
            $marks_json = json_encode(array_values($clamped));
        }

        // Map first 4 into legacy columns
        $att = $clamped[0] ?? null;
        $ct  = $clamped[1] ?? null;
        $mid = $clamped[2] ?? null;
        $fin = $clamped[3] ?? null;
        $marks = $clamped;
    }

    db()->prepare(
        'INSERT INTO result_sheet_grades
           (sheet_id, student_id, student_sid, student_name,
            is_absent, attendance, class_test, mid_term, final_exam,
            marks_json, total_marks, letter_grade, grade_point)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           student_name  = VALUES(student_name),
           is_absent     = VALUES(is_absent),
           attendance    = VALUES(attendance),
           class_test    = VALUES(class_test),
           mid_term      = VALUES(mid_term),
           final_exam    = VALUES(final_exam),
           marks_json    = VALUES(marks_json),
           total_marks   = VALUES(total_marks),
           letter_grade  = VALUES(letter_grade),
           grade_point   = VALUES(grade_point)'
    )->execute([
        $sheet_id,
        $student_id_pk ?: null,
        $student_sid,
        $student_name,
        $is_absent ? 1 : 0,
        $att, $ct, $mid, $fin,
        $marks_json,
        $is_absent ? null  : $total,
        $is_absent ? 'F'   : $letter,
        $is_absent ? 0.00  : $point,
    ]);
}

// ── Workflow actions ──────────────────────────────────────────────────────────

/**
 * Advance sheet to the next step.
 * If the current step is the final step → publish.
 */
function wf_advance_sheet(int $sheet_id, int $user_id, string $remarks = ''): void
{
    $sheet = wf_get_sheet($sheet_id);
    $chain_id    = (int)$sheet['chain_id'];
    $step_order  = (int)$sheet['current_step_order'];
    $cur_step    = wf_get_step($chain_id, $step_order);
    if (!$cur_step) return;

    $is_final = (int)$cur_step['is_final'] === 1;

    if ($is_final) {
        // Publish
        db()->prepare(
            "UPDATE result_mark_sheets
             SET workflow_status = 'published', updated_at = NOW()
             WHERE id = ?"
        )->execute([$sheet_id]);

        _wf_log($sheet_id, $step_order, $cur_step['step_label'],
                (int)$cur_step['group_id'], 'published', $user_id, $remarks);
    } else {
        $next = wf_get_next_step($chain_id, $step_order);
        if (!$next) return; // no next step – shouldn't happen

        db()->prepare(
            "UPDATE result_mark_sheets
             SET workflow_status = 'pending', current_step_order = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([$next['step_order'], $sheet_id]);

        _wf_log($sheet_id, $step_order, $cur_step['step_label'],
                (int)$cur_step['group_id'], 'approved', $user_id, $remarks);
    }
}

/**
 * Return a sheet to a specific step.
 * If target is the entry step → status = 'returned' (back to teacher).
 * Otherwise → status = 'pending' (back to mid-step approver).
 */
function wf_return_sheet(int $sheet_id, int $user_id, int $target_step_order, string $remarks): void
{
    $sheet = wf_get_sheet($sheet_id);
    $chain_id   = (int)$sheet['chain_id'];
    $step_order = (int)$sheet['current_step_order'];
    $cur_step   = wf_get_step($chain_id, $step_order);
    if (!$cur_step) return;

    $target_step = wf_get_step($chain_id, $target_step_order);
    if (!$target_step) return;

    $new_status = $target_step['is_entry'] ? 'returned' : 'pending';

    db()->prepare(
        "UPDATE result_mark_sheets
         SET workflow_status = ?, current_step_order = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$new_status, $target_step_order, $sheet_id]);

    _wf_log($sheet_id, $step_order, $cur_step['step_label'],
            (int)$cur_step['group_id'], 'returned', $user_id, $remarks, $target_step_order);
}

/**
 * Teacher resubmits a returned sheet.
 */
function wf_resubmit_sheet(int $sheet_id, int $user_id): void
{
    $sheet = wf_get_sheet($sheet_id);
    $chain_id   = (int)$sheet['chain_id'];
    $entry_step = wf_get_entry_step($chain_id);
    if (!$entry_step) return;

    $next = wf_get_next_step($chain_id, (int)$entry_step['step_order']);
    if (!$next) return;

    db()->prepare(
        "UPDATE result_mark_sheets
         SET workflow_status = 'pending', current_step_order = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$next['step_order'], $sheet_id]);

    _wf_log($sheet_id, (int)$entry_step['step_order'], $entry_step['step_label'],
            (int)$entry_step['group_id'], 'submitted', $user_id, '');
}

/**
 * Internal: log a workflow action.
 */
function _wf_log(
    int $sheet_id, int $step_order, string $step_label,
    int $group_id, string $action, int $acted_by,
    string $remarks = '', ?int $returned_to = null
): void {
    db()->prepare(
        'INSERT INTO wf_sheet_history
           (sheet_id, step_order, step_label, group_id, action, acted_by, acted_at, remarks, returned_to_step)
         VALUES (?,?,?,?,?,?,NOW(),?,?)'
    )->execute([
        $sheet_id, $step_order, $step_label, $group_id,
        $action, $acted_by,
        $remarks ?: null, $returned_to,
    ]);
}

// ── UI helpers ────────────────────────────────────────────────────────────────

/**
 * Get signoff data for the print view from wf_sheet_history.
 * Returns reviewer, HOD and publisher names/timestamps (based on
 * sequential 'approved'/'published' actions in the history).
 *
 * Keys: reviewer_name, reviewed_at, hod_name, hod_approved_at,
 *       publisher_name, published_at
 */
function wf_get_sheet_signoffs(int $sheet_id): array
{
    $stmt = db()->prepare(
        "SELECT h.action, h.acted_at, h.step_label, h.step_order,
                u.username AS actor_name
           FROM wf_sheet_history h
           LEFT JOIN users u ON u.id = h.acted_by
          WHERE h.sheet_id = ?
          ORDER BY h.acted_at ASC, h.id ASC"
    );
    $stmt->execute([$sheet_id]);
    $rows = $stmt->fetchAll();

    $signoffs = [
        'reviewer_name'   => null,
        'reviewed_at'     => null,
        'hod_name'        => null,
        'hod_approved_at' => null,
        'publisher_name'  => null,
        'published_at'    => null,
    ];

    $approvals = [];
    foreach ($rows as $h) {
        if ($h['action'] === 'approved') {
            $approvals[] = $h;
        } elseif ($h['action'] === 'published') {
            $signoffs['publisher_name'] = $h['actor_name'];
            $signoffs['published_at']   = $h['acted_at'];
        }
    }

    if (isset($approvals[0])) {
        $signoffs['reviewer_name'] = $approvals[0]['actor_name'];
        $signoffs['reviewed_at']   = $approvals[0]['acted_at'];
    }
    if (isset($approvals[1])) {
        $signoffs['hod_name']        = $approvals[1]['actor_name'];
        $signoffs['hod_approved_at'] = $approvals[1]['acted_at'];
    }

    return $signoffs;
}

function wf_status_badge(string $status, string $step_label = ''): string
{
    $map = [
        'draft'     => ['bg-secondary',         'Draft'],
        'pending'   => ['bg-primary',            'Pending'],
        'returned'  => ['bg-danger',             'Returned'],
        'published' => ['bg-success',            'Published'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    $text = $step_label ? h($label) . ' – ' . h($step_label) : h($label);
    return '<span class="badge ' . $cls . '">' . $text . '</span>';
}
