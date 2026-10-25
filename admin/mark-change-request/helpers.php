<?php
/**
 * Mark Change Request (MCR) — shared helpers
 *
 * Faculty self-service module under the Controller of Examinations:
 *  - Any Faculty group member searches a student by Student ID.
 *  - All PUBLISHED semester results of that student are listed.
 *  - The faculty proposes corrected marks for one course, writing a
 *    mandatory reason note.
 *  - The request then travels through the SAME department approval chain
 *    (wf_chains / wf_chain_steps) used for original mark-sheet approval.
 *  - When the FINAL chain step approves, the correction is applied to
 *    result_sheet_grades and the change is logged.
 *
 * Schema mirrors admin/mark-change-request-v1.sql and is auto-migrated
 * at runtime by mcr_ensure_schema().
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../results/workflow-helpers.php';

// ── Schema / module registration ─────────────────────────────────────────────

function mcr_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `mcr_requests` (
                `id`                 INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `grade_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → result_sheet_grades.id',
                `sheet_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → result_mark_sheets.id',
                `student_id`         INT(10) UNSIGNED DEFAULT NULL,
                `student_sid`        VARCHAR(25) NOT NULL,
                `student_name`       VARCHAR(200) DEFAULT NULL,
                `dept_id`            INT(10) UNSIGNED NOT NULL,
                `program_id`         INT(10) UNSIGNED DEFAULT NULL,
                `chain_id`           INT(10) UNSIGNED NOT NULL COMMENT 'FK → wf_chains.id',
                `current_step_order` TINYINT(3) UNSIGNED NOT NULL,
                `old_marks_json`     LONGTEXT DEFAULT NULL,
                `old_is_absent`      TINYINT(1) NOT NULL DEFAULT 0,
                `old_total`          DECIMAL(5,2) DEFAULT NULL,
                `old_letter`         VARCHAR(10) DEFAULT NULL,
                `old_point`          DECIMAL(4,2) DEFAULT NULL,
                `new_marks_json`     LONGTEXT DEFAULT NULL,
                `new_is_absent`      TINYINT(1) NOT NULL DEFAULT 0,
                `new_total`          DECIMAL(5,2) DEFAULT NULL,
                `new_letter`         VARCHAR(10) DEFAULT NULL,
                `new_point`          DECIMAL(4,2) DEFAULT NULL,
                `reason`             TEXT NOT NULL COMMENT 'Mandatory note from the faculty explaining the change',
                `status`             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `requested_by`       INT(10) UNSIGNED NOT NULL,
                `decided_at`         DATETIME DEFAULT NULL,
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_mcr_grade`     (`grade_id`),
                KEY `idx_mcr_status`    (`status`),
                KEY `idx_mcr_requester` (`requested_by`),
                KEY `idx_mcr_student`   (`student_sid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Mark change requests routed through the dept mark approval chain'"
        );
        db()->exec(
            "CREATE TABLE IF NOT EXISTS `mcr_history` (
                `id`         INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `request_id` INT(10) UNSIGNED NOT NULL,
                `step_order` TINYINT(3) UNSIGNED NOT NULL,
                `step_label` VARCHAR(200) DEFAULT NULL,
                `group_id`   INT(10) UNSIGNED DEFAULT NULL,
                `action`     ENUM('submitted','approved','applied','rejected') NOT NULL,
                `acted_by`   INT(10) UNSIGNED DEFAULT NULL,
                `acted_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `remarks`    TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_mcrh_req` (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Audit trail of every action on a mark change request'"
        );

        // Register the module so it appears on the Module Access page.
        db()->exec(
            "INSERT IGNORE INTO modules
                (name, slug, description, icon, parent_id, sort_order,
                 is_active, can_view, can_create, can_edit, can_delete)
             VALUES
                ('Mark Change Request', 'mark-change-request',
                 'Faculty request corrections to published student results; routed through the department mark approval chain.',
                 'fas fa-file-pen', NULL, 98, 1, 1, 1, 0, 0)"
        );

        // Grant view+create to every active Faculty group (one-time, idempotent).
        db()->exec(
            "INSERT INTO group_module_access (group_id, module_id, can_view, can_create, can_edit, can_delete)
             SELECT g.id, m.id, 1, 1, 0, 0
               FROM user_groups g
               JOIN modules m ON m.slug = 'mark-change-request'
              WHERE g.name LIKE '%Faculty%' AND g.is_active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM group_module_access gma
                     WHERE gma.group_id = g.id AND gma.module_id = m.id
                )"
        );
    } catch (Throwable $e) {
        // Insufficient privileges — run admin/mark-change-request-v1.sql manually.
        error_log('mcr_ensure_schema: ' . $e->getMessage());
    }
}

// ── Access ────────────────────────────────────────────────────────────────────

/** Can the current user open the module at all (requester OR chain approver)? */
function mcr_can_view_module(): bool
{
    return is_super_admin() || can_access('mark-change-request') || wf_has_approver_role();
}

/** Can the current user create change requests? */
function mcr_can_request(): bool
{
    return is_super_admin() || can_access('mark-change-request');
}

// ── Student & result lookups ─────────────────────────────────────────────────

/** Find a student by their Student ID (exact match first, then LIKE). */
function mcr_find_student(string $sid): ?array
{
    $sid = trim($sid);
    if ($sid === '') return null;

    $sql = 'SELECT s.id, s.student_id, s.full_name, s.batch, s.status, s.photo,
                   s.dept_id, s.program_id,
                   d.name AS dept_name, p.program_name
              FROM students s
              LEFT JOIN dept_departments d        ON d.id = s.dept_id
              LEFT JOIN dept_academic_programs p  ON p.id = s.program_id
             WHERE s.student_id = ? LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute([$sid]);
    $row = $st->fetch();
    if ($row) return $row;

    $st = db()->prepare(str_replace('s.student_id = ?', 's.student_id LIKE ?', $sql));
    $st->execute(['%' . $sid . '%']);
    return $st->fetch() ?: null;
}

/**
 * All PUBLISHED result rows of a student, grouped by semester (newest first).
 * Returns ['Spring-2026' => ['label' => ..., 'courses' => [rows]], ...]
 */
function mcr_student_results(array $student): array
{
    $st = db()->prepare(
        "SELECT g.id AS grade_id, g.is_absent, g.marks_json,
                g.attendance, g.class_test, g.mid_term, g.final_exam,
                g.total_marks, g.letter_grade, g.grade_point,
                ms.id AS sheet_id, ms.semester, ms.subject_code, ms.subject_title,
                ms.credits, ms.dept_id, ms.program_id, ms.curriculum_id
           FROM result_sheet_grades g
           JOIN result_mark_sheets ms ON ms.id = g.sheet_id
          WHERE ms.workflow_status = 'published'
            AND (g.student_id = ? OR g.student_sid = ?)
          ORDER BY ms.id ASC"
    );
    $st->execute([(int)$student['id'], (string)$student['student_id']]);
    $rows = $st->fetchAll();

    $sems = [];
    foreach ($rows as $r) {
        $label = trim((string)$r['semester']);
        if ($label === '') $label = 'Unspecified';
        $key = $label;
        if (!isset($sems[$key])) {
            $sems[$key] = ['label' => $label, 'sort' => mcr_semester_sort_key($label), 'courses' => []];
        }
        $sems[$key]['courses'][] = $r;
    }
    uasort($sems, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    return $sems;
}

/** Sortable key for semester labels like "Spring-2026" / "Fall 2024". */
function mcr_semester_sort_key(string $label): int
{
    $year = 0; $season = 0;
    if (preg_match('/(\d{4})/', $label, $m)) $year = (int)$m[1];
    if (stripos($label, 'spring') !== false)      $season = 1;
    elseif (stripos($label, 'summer') !== false)  $season = 2;
    elseif (stripos($label, 'fall') !== false || stripos($label, 'autumn') !== false) $season = 3;
    return $year * 10 + $season;
}

// ── Distribution & marks helpers ─────────────────────────────────────────────

/**
 * Mark distribution for a sheet: curriculum config → legacy 4-column fallback,
 * padded so it always covers $marks_count components.
 * Returns list of ['name' => ..., 'max' => float].
 */
function mcr_distribution(?int $curriculum_id, int $marks_count): array
{
    $dist = [];
    if ($curriculum_id) {
        try {
            $st = db()->prepare(
                'SELECT distribution_name, max_marks
                   FROM cc_mark_distributions
                  WHERE curriculum_id = ?
                  ORDER BY sort_order ASC, id ASC'
            );
            $st->execute([$curriculum_id]);
            foreach ($st->fetchAll() as $d) {
                $dist[] = ['name' => (string)$d['distribution_name'], 'max' => (float)$d['max_marks']];
            }
        } catch (Throwable $e) { /* table may not exist */ }
    }
    if (empty($dist)) {
        $dist = [
            ['name' => 'Attendance', 'max' => (float)WF_MAX_ATTENDANCE],
            ['name' => 'Class Test', 'max' => (float)WF_MAX_CLASS_TEST],
            ['name' => 'Mid Term',   'max' => (float)WF_MAX_MID_TERM],
            ['name' => 'Final Exam', 'max' => (float)WF_MAX_FINAL_EXAM],
        ];
    }
    while (count($dist) < $marks_count) {
        $dist[] = ['name' => 'Part ' . (count($dist) + 1), 'max' => (float)WF_MAX_TOTAL];
    }
    return $dist;
}

/** Component marks of a grade row: marks_json → legacy columns fallback. */
function mcr_grade_marks(array $grade): array
{
    if (!empty($grade['marks_json'])) {
        $m = json_decode((string)$grade['marks_json'], true);
        if (is_array($m)) {
            return array_map(static fn($v) => ($v === null || $v === '') ? null : (float)$v, array_values($m));
        }
    }
    return [
        $grade['attendance'] !== null ? (float)$grade['attendance'] : null,
        $grade['class_test'] !== null ? (float)$grade['class_test'] : null,
        $grade['mid_term']   !== null ? (float)$grade['mid_term']   : null,
        $grade['final_exam'] !== null ? (float)$grade['final_exam'] : null,
    ];
}

/**
 * Compute total/letter/point for a set of component marks — identical rules
 * to wf_upsert_grade() (clamping, grace, Incom on absent, null on empty).
 * Returns ['marks' => clamped[], 'total', 'letter', 'point'].
 */
function mcr_compute(array $marks, int $is_absent, array $dist): array
{
    $clamped = [];
    foreach (array_values($marks) as $i => $v) {
        if ($v === null || $v === '') { $clamped[$i] = null; continue; }
        $max = isset($dist[$i]) ? (float)$dist[$i]['max'] : (float)WF_MAX_TOTAL;
        $clamped[$i] = min(max((float)$v, 0), $max);
    }

    if (count(array_filter($clamped, static fn($v) => $v !== null)) === 0) {
        return ['marks' => $clamped, 'total' => null, 'letter' => null, 'point' => null];
    }
    if ($is_absent) {
        return ['marks' => $clamped, 'total' => null, 'letter' => 'Incom', 'point' => 0.00];
    }
    $total = min(array_sum(array_map(static fn($v) => $v ?? 0, $clamped)), WF_MAX_TOTAL);
    $total = wf_apply_grace($total);
    $g     = wf_compute_grade($total);
    return ['marks' => $clamped, 'total' => $total, 'letter' => $g['letter'], 'point' => $g['point']];
}

// ── Request fetchers ──────────────────────────────────────────────────────────

function mcr_get_request(int $id): ?array
{
    $st = db()->prepare(
        'SELECT r.*,
                ms.semester, ms.subject_code, ms.subject_title, ms.credits,
                ms.curriculum_id, ms.workflow_status AS sheet_status,
                d.name AS dept_name, p.program_name,
                c.name AS chain_name,
                u.full_name AS requester_name,
                s.step_label AS current_step_label,
                s.group_id   AS current_group_id,
                g2.name      AS current_group_name,
                s.is_final   AS current_step_is_final
           FROM mcr_requests r
           JOIN result_mark_sheets ms          ON ms.id = r.sheet_id
           LEFT JOIN dept_departments d        ON d.id  = r.dept_id
           LEFT JOIN dept_academic_programs p  ON p.id  = r.program_id
           LEFT JOIN wf_chains c               ON c.id  = r.chain_id
           LEFT JOIN wf_chain_steps s          ON s.chain_id = r.chain_id
                                              AND s.step_order = r.current_step_order
           LEFT JOIN user_groups g2            ON g2.id = s.group_id
           LEFT JOIN users u                   ON u.id  = r.requested_by
          WHERE r.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Open (pending) request for a grade row, if any. */
function mcr_open_request_for_grade(int $grade_id): ?array
{
    $st = db()->prepare("SELECT * FROM mcr_requests WHERE grade_id = ? AND status = 'pending' LIMIT 1");
    $st->execute([$grade_id]);
    return $st->fetch() ?: null;
}

/** Map of grade_id => request_id for all grades with an open request. */
function mcr_open_requests_by_grade(array $grade_ids): array
{
    if (empty($grade_ids)) return [];
    $phs = implode(',', array_fill(0, count($grade_ids), '?'));
    $st  = db()->prepare("SELECT grade_id, id FROM mcr_requests WHERE status = 'pending' AND grade_id IN ($phs)");
    $st->execute(array_map('intval', $grade_ids));
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['grade_id']] = (int)$r['id'];
    return $out;
}

/** Requests created by a user (newest first). */
function mcr_my_requests(int $user_id): array
{
    $st = db()->prepare(
        'SELECT r.*, ms.semester, ms.subject_code, ms.subject_title,
                s.step_label AS current_step_label
           FROM mcr_requests r
           JOIN result_mark_sheets ms ON ms.id = r.sheet_id
           LEFT JOIN wf_chain_steps s ON s.chain_id = r.chain_id
                                     AND s.step_order = r.current_step_order
          WHERE r.requested_by = ?
          ORDER BY r.created_at DESC'
    );
    $st->execute([$user_id]);
    return $st->fetchAll();
}

/** Pending requests the current user can act on (their group = current step). */
function mcr_approver_queue(): array
{
    $where  = ["r.status = 'pending'"];
    $params = [];

    if (!is_super_admin()) {
        $group_ids = wf_user_group_ids();
        if (empty($group_ids)) return [];
        $phs     = implode(',', array_fill(0, count($group_ids), '?'));
        $where[] = "EXISTS (
            SELECT 1 FROM wf_chain_steps s2
             WHERE s2.chain_id = r.chain_id
               AND s2.step_order = r.current_step_order
               AND s2.group_id IN ($phs)
        )";
        array_push($params, ...$group_ids);

        $dept_scope = get_dept_scope();
        if ($dept_scope !== null) {
            if (empty($dept_scope)) return [];
            $dphs    = implode(',', array_fill(0, count($dept_scope), '?'));
            $where[] = "r.dept_id IN ($dphs)";
            array_push($params, ...$dept_scope);
        }
    }

    try {
        $st = db()->prepare(
            'SELECT r.*, ms.semester, ms.subject_code, ms.subject_title,
                    d.name AS dept_name, u.full_name AS requester_name,
                    s.step_label AS current_step_label
               FROM mcr_requests r
               JOIN result_mark_sheets ms          ON ms.id = r.sheet_id
               LEFT JOIN dept_departments d        ON d.id  = r.dept_id
               LEFT JOIN users u                   ON u.id  = r.requested_by
               LEFT JOIN wf_chain_steps s          ON s.chain_id = r.chain_id
                                                  AND s.step_order = r.current_step_order
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY r.updated_at ASC'
        );
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Number of pending requests the current user can act on (sidebar badge). */
function mcr_pending_count_for_user(): int
{
    try { return count(mcr_approver_queue()); } catch (Throwable $e) { return 0; }
}

/** Can the current user approve/reject this request at its current step? */
function mcr_can_review(array $req): bool
{
    if ($req['status'] !== 'pending') return false;
    if (is_super_admin()) return true;

    $step = wf_get_step((int)$req['chain_id'], (int)$req['current_step_order']);
    if (!$step) return false;
    return in_array((int)$step['group_id'], wf_user_group_ids(), true);
}

function mcr_get_history(int $request_id): array
{
    $st = db()->prepare(
        'SELECT h.*, u.full_name AS actor_name, g.name AS group_name
           FROM mcr_history h
           LEFT JOIN users u       ON u.id = h.acted_by
           LEFT JOIN user_groups g ON g.id = h.group_id
          WHERE h.request_id = ?
          ORDER BY h.acted_at ASC, h.id ASC'
    );
    $st->execute([$request_id]);
    return $st->fetchAll();
}

function _mcr_log(int $request_id, int $step_order, ?string $step_label,
                  ?int $group_id, string $action, int $acted_by, string $remarks = ''): void
{
    db()->prepare(
        'INSERT INTO mcr_history
            (request_id, step_order, step_label, group_id, action, acted_by, acted_at, remarks)
         VALUES (?,?,?,?,?,?,NOW(),?)'
    )->execute([$request_id, $step_order, $step_label, $group_id ?: null, $action, $acted_by, $remarks ?: null]);
}

function _mcr_label(array $req): string
{
    $label = trim(
        (!empty($req['subject_code']) ? $req['subject_code'] . ' — ' : '')
        . (string)($req['subject_title'] ?? '')
    );
    if (!empty($req['student_sid'])) $label .= ' · ' . $req['student_sid'];
    return $label !== '' ? $label : 'Request #' . (int)($req['id'] ?? 0);
}

/** Notify everyone in the group of the request's current step. */
function mcr_notify_pending_approvers(int $request_id, int $exclude_user_id = 0, string $context = ''): void
{
    try {
        require_once __DIR__ . '/../includes/notifications.php';
        $req = mcr_get_request($request_id);
        if (!$req || $req['status'] !== 'pending') return;
        $step = wf_get_step((int)$req['chain_id'], (int)$req['current_step_order']);
        if (!$step || (int)$step['group_id'] < 1) return;

        notify_group_members(
            (int)$step['group_id'],
            'Mark change approval required: ' . _mcr_label($req),
            $context !== '' ? $context : 'A mark change request is awaiting your approval.',
            APP_URL . '/mark-change-request/view.php?id=' . $request_id,
            'marks-approval',
            $exclude_user_id
        );
    } catch (Throwable $e) {
        error_log('mcr_notify_pending_approvers: ' . $e->getMessage());
    }
}

// ── Workflow actions ──────────────────────────────────────────────────────────

/**
 * Create & submit a new mark change request for a published grade row.
 * Returns [ok(bool), message(string), request_id(int)].
 */
function mcr_submit(int $grade_id, array $new_marks, int $new_is_absent, string $reason, int $user_id): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A note explaining the reason for the change is required.', 0];
    }

    $st = db()->prepare(
        'SELECT g.*, ms.dept_id AS ms_dept_id, ms.program_id AS ms_program_id,
                ms.curriculum_id, ms.workflow_status, ms.subject_code, ms.subject_title
           FROM result_sheet_grades g
           JOIN result_mark_sheets ms ON ms.id = g.sheet_id
          WHERE g.id = ?'
    );
    $st->execute([$grade_id]);
    $grade = $st->fetch();
    if (!$grade) return [false, 'Result row not found.', 0];
    if ($grade['workflow_status'] !== 'published') {
        return [false, 'Only published results can be corrected through a change request.', 0];
    }
    if (mcr_open_request_for_grade($grade_id)) {
        return [false, 'A change request for this result is already pending.', 0];
    }

    $dept_id    = (int)$grade['ms_dept_id'];
    $program_id = $grade['ms_program_id'] !== null ? (int)$grade['ms_program_id'] : null;

    // Route through the ORIGINAL department's mark approval chain.
    $chain = wf_resolve_chain($dept_id, $program_id);
    if (!$chain) {
        return [false, 'No active mark approval chain is configured for this department. Ask the administrator to set one up in Workflow Chains.', 0];
    }
    $entry = wf_get_entry_step((int)$chain['id']);
    $first = $entry ? wf_get_next_step((int)$chain['id'], (int)$entry['step_order']) : null;
    if (!$first) {
        return [false, 'The department approval chain has no approver steps configured.', 0];
    }

    $old_marks = mcr_grade_marks($grade);
    $dist      = mcr_distribution($grade['curriculum_id'] ? (int)$grade['curriculum_id'] : null,
                                  max(count($old_marks), count($new_marks)));
    // Pad so old/new mark lists compare component-by-component at equal length.
    while (count($old_marks) < count($dist)) {
        $old_marks[] = null;
    }
    $new       = mcr_compute($new_marks, $new_is_absent, $dist);

    $old_json = json_encode(array_values($old_marks));
    $new_json = json_encode(array_values($new['marks']));
    if ($old_json === $new_json && (int)$grade['is_absent'] === $new_is_absent) {
        return [false, 'The proposed marks are identical to the current marks — nothing to change.', 0];
    }

    db()->prepare(
        "INSERT INTO mcr_requests
            (grade_id, sheet_id, student_id, student_sid, student_name,
             dept_id, program_id, chain_id, current_step_order,
             old_marks_json, old_is_absent, old_total, old_letter, old_point,
             new_marks_json, new_is_absent, new_total, new_letter, new_point,
             reason, status, requested_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?)"
    )->execute([
        $grade_id,
        (int)$grade['sheet_id'],
        $grade['student_id'] !== null ? (int)$grade['student_id'] : null,
        (string)$grade['student_sid'],
        $grade['student_name'],
        $dept_id,
        $program_id,
        (int)$chain['id'],
        (int)$first['step_order'],
        $old_json,
        (int)$grade['is_absent'],
        $grade['total_marks'],
        $grade['letter_grade'],
        $grade['grade_point'],
        $new_json,
        $new_is_absent,
        $new['total'],
        $new['letter'],
        $new['point'],
        $reason,
        $user_id,
    ]);
    $request_id = (int)db()->lastInsertId();

    _mcr_log($request_id, (int)($entry['step_order'] ?? 0),
             $entry['step_label'] ?? 'Requester', (int)($entry['group_id'] ?? 0),
             'submitted', $user_id, $reason);

    mcr_notify_pending_approvers(
        $request_id, $user_id,
        'A mark change request was submitted and is awaiting your approval.'
    );

    return [true, 'Mark change request submitted. It is now pending in the department approval flow.', $request_id];
}

/**
 * Approve the request at its current step.
 * If the current step is the chain's FINAL step, the change is applied to
 * result_sheet_grades and the request becomes "approved".
 */
function mcr_approve(int $request_id, int $user_id, string $remarks = ''): array
{
    $req = mcr_get_request($request_id);
    if (!$req || $req['status'] !== 'pending') return [false, 'This request is not pending.'];
    if (!mcr_can_review($req)) return [false, 'You are not an approver for the current step.'];

    $step = wf_get_step((int)$req['chain_id'], (int)$req['current_step_order']);
    if (!$step) return [false, 'Workflow step could not be resolved.'];

    if ((int)$step['is_final'] === 1) {
        // Final approval → apply the change to the published result.
        $err = mcr_apply($req, $user_id);
        if ($err !== null) return [false, $err];

        db()->prepare(
            "UPDATE mcr_requests
                SET status = 'approved', decided_at = NOW(), updated_at = NOW()
              WHERE id = ?"
        )->execute([$request_id]);

        _mcr_log($request_id, (int)$step['step_order'], $step['step_label'],
                 (int)$step['group_id'], 'applied', $user_id, $remarks);

        if ((int)$req['requested_by'] !== $user_id) {
            _wf_notify_user(
                (int)$req['requested_by'],
                'Mark change approved: ' . _mcr_label($req),
                'Your mark change request completed the approval chain and the result has been updated.'
                    . ($remarks !== '' ? ' Remarks: ' . $remarks : ''),
                APP_URL . '/mark-change-request/view.php?id=' . $request_id
            );
        }
        return [true, 'Request fully approved — the student result has been updated.'];
    }

    $next = wf_get_next_step((int)$req['chain_id'], (int)$step['step_order']);
    if (!$next) return [false, 'No next step found in the approval chain.'];

    db()->prepare(
        'UPDATE mcr_requests SET current_step_order = ?, updated_at = NOW() WHERE id = ?'
    )->execute([(int)$next['step_order'], $request_id]);

    _mcr_log($request_id, (int)$step['step_order'], $step['step_label'],
             (int)$step['group_id'], 'approved', $user_id, $remarks);

    mcr_notify_pending_approvers(
        $request_id, $user_id,
        'Approved at "' . $step['step_label'] . '" and forwarded to you for approval.'
    );
    if ((int)$req['requested_by'] !== $user_id) {
        _wf_notify_user(
            (int)$req['requested_by'],
            'Mark change request approved a step: ' . _mcr_label($req),
            'Approved at "' . $step['step_label'] . '" and forwarded to "' . $next['step_label'] . '".'
                . ($remarks !== '' ? ' Remarks: ' . $remarks : ''),
            APP_URL . '/mark-change-request/view.php?id=' . $request_id
        );
    }
    return [true, 'Approved and forwarded to "' . $next['step_label'] . '".'];
}

/** Reject the request (remarks required). */
function mcr_reject(int $request_id, int $user_id, string $remarks): array
{
    $remarks = trim($remarks);
    if ($remarks === '') return [false, 'Remarks are required when rejecting a request.'];

    $req = mcr_get_request($request_id);
    if (!$req || $req['status'] !== 'pending') return [false, 'This request is not pending.'];
    if (!mcr_can_review($req)) return [false, 'You are not an approver for the current step.'];

    $step = wf_get_step((int)$req['chain_id'], (int)$req['current_step_order']);

    db()->prepare(
        "UPDATE mcr_requests
            SET status = 'rejected', decided_at = NOW(), updated_at = NOW()
          WHERE id = ?"
    )->execute([$request_id]);

    _mcr_log($request_id, (int)($step['step_order'] ?? 0), $step['step_label'] ?? null,
             isset($step['group_id']) ? (int)$step['group_id'] : null, 'rejected', $user_id, $remarks);

    if ((int)$req['requested_by'] !== $user_id) {
        _wf_notify_user(
            (int)$req['requested_by'],
            'Mark change rejected: ' . _mcr_label($req),
            'Rejected at "' . ($step['step_label'] ?? 'approval') . '". Remarks: ' . $remarks,
            APP_URL . '/mark-change-request/view.php?id=' . $request_id
        );
    }
    return [true, 'Request rejected.'];
}

/**
 * Apply an approved change to result_sheet_grades.
 * Returns null on success, or an error message.
 */
function mcr_apply(array $req, int $user_id): ?string
{
    $st = db()->prepare('SELECT * FROM result_sheet_grades WHERE id = ?');
    $st->execute([(int)$req['grade_id']]);
    $grade = $st->fetch();
    if (!$grade) return 'The underlying result row no longer exists.';

    $new_marks = json_decode((string)$req['new_marks_json'], true);
    if (!is_array($new_marks)) $new_marks = [];

    $no_valid   = (count(array_filter($new_marks, static fn($v) => $v !== null)) === 0);
    $marks_json = $no_valid ? null : json_encode(array_values($new_marks));

    db()->prepare(
        'UPDATE result_sheet_grades
            SET is_absent   = ?,
                attendance  = ?, class_test = ?, mid_term = ?, final_exam = ?,
                marks_json  = ?,
                total_marks = ?, letter_grade = ?, grade_point = ?,
                updated_at  = NOW()
          WHERE id = ?'
    )->execute([
        (int)$req['new_is_absent'],
        $new_marks[0] ?? null, $new_marks[1] ?? null,
        $new_marks[2] ?? null, $new_marks[3] ?? null,
        $marks_json,
        $req['new_total'], $req['new_letter'], $req['new_point'],
        (int)$req['grade_id'],
    ]);

    try {
        if (!function_exists('log_change')) {
            require_once __DIR__ . '/../change-log/helpers.php';
        }
        log_change(
            'results', 'UPDATE', (int)$req['sheet_id'],
            trim(($req['subject_code'] ? $req['subject_code'] . ' — ' : '') . (string)$req['subject_title']),
            'mark-change-request',
            json_encode([
                'student' => $req['student_sid'],
                'marks'   => json_decode((string)$req['old_marks_json'], true),
                'total'   => $req['old_total'],
                'grade'   => $req['old_letter'],
                'absent'  => (int)$req['old_is_absent'],
            ]),
            json_encode([
                'student' => $req['student_sid'],
                'marks'   => $new_marks,
                'total'   => $req['new_total'],
                'grade'   => $req['new_letter'],
                'absent'  => (int)$req['new_is_absent'],
            ]),
            sprintf('Mark Change Request #%d approved — result of %s updated. Reason: %s',
                    (int)$req['id'], (string)$req['student_sid'], (string)$req['reason'])
        );
    } catch (Throwable $e) { /* change-log optional */ }

    return null;
}

// ── UI helpers ────────────────────────────────────────────────────────────────

function mcr_status_badge(string $status): string
{
    return match ($status) {
        'pending'  => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        default    => '<span class="badge bg-secondary">' . h($status) . '</span>',
    };
}

/** "72.5 → A (3.75)" style summary for a request side (old/new). */
function mcr_result_summary(?string $total, ?string $letter, ?string $point, int $is_absent): string
{
    if ($is_absent)        return 'Incom (absent)';
    if ($letter === null && $total === null) return '—';
    $out = $total !== null ? rtrim(rtrim(number_format((float)$total, 2, '.', ''), '0'), '.') : '—';
    if ($letter !== null) {
        $out .= ' · ' . $letter;
        if ($point !== null) $out .= ' (' . number_format((float)$point, 2) . ')';
    }
    return $out;
}

// NOTE: mcr_ensure_schema() is called by the module pages (index/create/view),
// not at include-time, so the sidebar can include this file cheaply.
