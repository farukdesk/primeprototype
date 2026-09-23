<?php
/**
 * Student Transfer – helper functions
 * ================================================================
 * Two kinds of one-way student transfer, each recorded as a permanent row in
 * `student_transfers` (see admin/student-transfer.sql for the full schema
 * rationale):
 *
 *   'department' — moves students.dept_id (and optionally program_id /
 *      student_id) to a new department/program. Different from the older
 *      student_batch_transfers-style "also belongs to" model: this is a real
 *      move of the student's actual department.
 *
 *   'batch' — moves students.batch_id/batch to a new batch. Coexists with the
 *      pre-existing `student_batch_transfers` table (dual-batch membership,
 *      see admin/students/helpers.php) — that mechanism is untouched by this
 *      module; this is a separate, real one-way move.
 *
 * These helpers are dependency-light (db() + auth helpers only) so they can
 * be required standalone from this module's own pages.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';   // log_change()
require_once __DIR__ . '/../students/helpers.php';     // sm_generate_student_id()
require_once __DIR__ . '/../admissions/helpers.php';   // adm_sid_sync_after_external_issue()

const STT_SLUG = 'student-transfer';

// ── Permission helpers ──────────────────────────────────────────────────────
function stt_can_view(): bool   { return can_access(STT_SLUG, 'can_view'); }
function stt_can_create(): bool { return can_access(STT_SLUG, 'can_create'); }
function stt_can_delete(): bool { return can_access(STT_SLUG, 'can_delete'); }

// ── Reference data ──────────────────────────────────────────────────────────

/** All departments, keyed by id. */
function stt_dept_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $rows = db()->query(
            'SELECT id, name, code, faculty_label FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $d) {
            $map[(int)$d['id']] = $d;
        }
    }
    return $map;
}

/** All academic programs, keyed by id. */
function stt_program_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $rows = db()->query(
            'SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $p) {
            $map[(int)$p['id']] = $p;
        }
    }
    return $map;
}

/** All active batches, keyed by id. */
function stt_batch_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $rows = db()->query(
            'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $b) {
            $map[(int)$b['id']] = $b;
        }
    }
    return $map;
}

/**
 * Departments the current user is allowed to transfer students into/out of.
 * Super admins / unscoped users get every active department.
 */
function stt_allowed_depts(): array
{
    $scope = get_dept_scope(); // null = unrestricted, else int[] of allowed dept_ids
    $all   = stt_dept_map();
    if ($scope === null) {
        return $all;
    }
    $scope = array_flip($scope);
    return array_filter($all, static fn($d) => isset($scope[(int)$d['id']]));
}

// ── Student lookup ──────────────────────────────────────────────────────────

/**
 * Fetch a student's core fields needed by this module, or null if not found.
 */
function stt_get_student(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, student_id, full_name, dept_id, program_id, admitted_semester,
                batch, batch_id, status
           FROM students WHERE id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Department transfer ─────────────────────────────────────────────────────

/**
 * Create a Department Transfer: moves the student's dept_id/program_id (and
 * optionally reissues their student_id), snapshots the fee package that
 * existed at the time (if any), and writes one student_transfers row.
 *
 * @param int         $student_id
 * @param int         $to_dept_id
 * @param int         $to_program_id     0 = no program (cleared)
 * @param string      $id_mode           'keep' | 'auto' | 'manual'
 * @param string      $manual_student_id Only used when $id_mode === 'manual'
 * @param string|null $reason
 * @param int         $created_by
 * @return array{ok:bool,message:string,transfer_id:?int}
 */
function stt_create_department_transfer(
    int $student_id,
    int $to_dept_id,
    int $to_program_id,
    string $id_mode,
    string $manual_student_id,
    ?string $reason,
    int $created_by
): array {
    $student = stt_get_student($student_id);
    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found.', 'transfer_id' => null];
    }

    $depts = stt_dept_map();
    if (!isset($depts[$to_dept_id])) {
        return ['ok' => false, 'message' => 'Please choose a valid department.', 'transfer_id' => null];
    }
    if (!can_access_dept($to_dept_id) || !can_access_dept((int)$student['dept_id'])) {
        return ['ok' => false, 'message' => 'You do not have permission to transfer this student to/from that department.', 'transfer_id' => null];
    }

    $programs = stt_program_map();
    if ($to_program_id > 0) {
        if (!isset($programs[$to_program_id]) || (int)$programs[$to_program_id]['dept_id'] !== $to_dept_id) {
            return ['ok' => false, 'message' => 'The selected program does not belong to the chosen department.', 'transfer_id' => null];
        }
    } else {
        $to_program_id = 0;
    }

    $from_dept_id    = (int)$student['dept_id'];
    $from_program_id = (int)($student['program_id'] ?? 0);

    if ($from_dept_id === $to_dept_id && $from_program_id === $to_program_id) {
        return ['ok' => false, 'message' => 'That is already this student\'s current department and program.', 'transfer_id' => null];
    }

    // ── Resolve the student ID for after the transfer ──────────────────────
    $old_sid = (string)$student['student_id'];
    $new_sid = $old_sid;

    if ($id_mode === 'auto') {
        try {
            $generated = sm_generate_student_id((string)$student['admitted_semester'], $to_dept_id, $to_program_id);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not auto-generate a new Student ID: ' . $e->getMessage(), 'transfer_id' => null];
        }
        if ($generated === null) {
            return ['ok' => false, 'message' => 'No Student ID numbering exists yet for the target department/program/semester, so an ID cannot be auto-generated. Choose "Type a new ID" instead.', 'transfer_id' => null];
        }
        $new_sid = $generated;
    } elseif ($id_mode === 'manual') {
        $manual_student_id = trim($manual_student_id);
        if ($manual_student_id === '') {
            return ['ok' => false, 'message' => 'Please type the new Student ID.', 'transfer_id' => null];
        }
        if (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $manual_student_id)) {
            return ['ok' => false, 'message' => 'Student ID must be 1–20 alphanumeric characters (digits, letters or hyphens).', 'transfer_id' => null];
        }
        if ($manual_student_id !== $old_sid) {
            $dup = db()->prepare('SELECT id FROM students WHERE student_id = ? AND id != ?');
            $dup->execute([$manual_student_id, $student_id]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'message' => 'Student ID "' . $manual_student_id . '" is already in use.', 'transfer_id' => null];
            }
        }
        $new_sid = $manual_student_id;
    }
    // 'keep' → $new_sid stays $old_sid

    // Snapshot any fee package that exists right now, before anything changes.
    $old_package_id = null;
    $pkg_stmt = db()->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
    $pkg_stmt->execute([$student_id]);
    $old_package_id = $pkg_stmt->fetchColumn() ?: null;

    $to_dept_row    = $depts[$to_dept_id];
    $from_dept_row  = $depts[$from_dept_id] ?? null;
    $to_program_row = $to_program_id > 0 ? ($programs[$to_program_id] ?? null) : null;
    $from_program_row = $from_program_id > 0 ? ($programs[$from_program_id] ?? null) : null;

    $db = db();
    $db->beginTransaction();
    try {
        $upd = $db->prepare(
            'UPDATE students
                SET dept_id = ?, program_id = ?, student_id = ?, faculty_label = ?
              WHERE id = ?'
        );
        $upd->execute([
            $to_dept_id,
            $to_program_id ?: null,
            $new_sid,
            $to_dept_row['faculty_label'] ?: null,
            $student_id,
        ]);

        $ins = $db->prepare(
            'INSERT INTO student_transfers
                (student_id, kind, from_dept_id, to_dept_id, from_dept_name, to_dept_name,
                 from_program_id, to_program_id, from_program_name, to_program_name,
                 old_student_id, new_student_id, old_package_id, package_action, reason, created_by)
             VALUES (?, \'department\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'none\', ?, ?)'
        );
        $ins->execute([
            $student_id,
            $from_dept_id ?: null,
            $to_dept_id,
            $from_dept_row['name'] ?? null,
            $to_dept_row['name'],
            $from_program_id ?: null,
            $to_program_id ?: null,
            $from_program_row['program_name'] ?? null,
            $to_program_row['program_name'] ?? null,
            $old_sid,
            $new_sid,
            $old_package_id,
            ($reason !== null && $reason !== '') ? $reason : null,
            $created_by,
        ]);
        $transfer_id = (int)$db->lastInsertId();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $label = $student['full_name'] . ' (' . $old_sid . ')';
    log_change('students', 'UPDATE', $student_id, $label, 'dept_id', $from_dept_id, $to_dept_id,
        'Department transfer: ' . ($from_dept_row['name'] ?? $from_dept_id) . ' → ' . $to_dept_row['name']);
    if ($from_program_id !== $to_program_id) {
        log_change('students', 'UPDATE', $student_id, $label, 'program_id', $from_program_id ?: null, $to_program_id ?: null,
            'Department transfer: program changed to ' . ($to_program_row['program_name'] ?? '— None —'));
    }
    if ($new_sid !== $old_sid) {
        log_change('students', 'UPDATE', $student_id, $label, 'student_id', $old_sid, $new_sid,
            'Department transfer: Student ID reissued.');

        // Keep Admissions → Settings → Student ID's "next serial" counter in
        // sync, so a future admission never reissues the serial we just used
        // (no-ops when the new ID doesn't match that program's configured
        // prefix, e.g. it was typed manually or the pattern predates it).
        if ($to_program_id > 0) {
            adm_sid_sync_after_external_issue($to_program_id, $new_sid);
        }
    }

    return ['ok' => true, 'message' => 'Department transfer recorded for ' . $student['full_name'] . '.', 'transfer_id' => $transfer_id];
}

/**
 * Attempt to end (delete) the fee package snapshotted on a department
 * transfer. Refuses when the package has recorded payments — packages with
 * payment history must be reconciled manually in Student Accounts, never
 * silently altered (see STUDENT-FEE-ARCHITECTURE.md).
 *
 * @return array{ok:bool,message:string}
 */
function stt_try_end_package(int $transfer_id, int $student_id, int $user_id): array
{
    require_once __DIR__ . '/../student-accounts/helpers.php';

    $transfer = stt_get_transfer($transfer_id);
    if (!$transfer || (int)$transfer['student_id'] !== $student_id || $transfer['kind'] !== 'department') {
        return ['ok' => false, 'message' => 'Transfer record not found.'];
    }
    $package_id = (int)($transfer['old_package_id'] ?? 0);
    if ($package_id <= 0) {
        return ['ok' => false, 'message' => 'There is no fee package to end for this transfer.'];
    }

    if (sfp_package_payment_count($package_id) > 0) {
        db()->prepare('UPDATE student_transfers SET package_action = \'blocked_has_payments\' WHERE id = ?')
            ->execute([$transfer_id]);
        return ['ok' => false, 'message' => 'This student has recorded payments under the old fee package — it cannot be removed automatically. Please reconcile finances manually in Student Accounts first.'];
    }

    $pkg = db()->prepare('SELECT id, program_name FROM sfp_packages WHERE id = ?');
    $pkg->execute([$package_id]);
    $pkg_row = $pkg->fetch(PDO::FETCH_ASSOC);
    if (!$pkg_row) {
        // Already gone (e.g. deleted separately) — just record the outcome.
        db()->prepare('UPDATE student_transfers SET package_action = \'ended\' WHERE id = ?')->execute([$transfer_id]);
        return ['ok' => true, 'message' => 'The old fee package no longer exists.'];
    }

    db()->prepare('DELETE FROM sfp_packages WHERE id = ?')->execute([$package_id]);
    db()->prepare('UPDATE student_transfers SET package_action = \'ended\' WHERE id = ?')->execute([$transfer_id]);

    log_change('student-accounts', 'DELETE', $package_id, $pkg_row['program_name'] ?? null, null, null, null,
        'Fee package ended as part of a department transfer (student_transfers #' . $transfer_id . ').');

    return ['ok' => true, 'message' => 'The old fee package has been ended. You can now assign a new one.'];
}

/** Admin explicitly chose to leave the old package unchanged. */
function stt_dismiss_package(int $transfer_id): void
{
    db()->prepare('UPDATE student_transfers SET package_action = \'kept\' WHERE id = ? AND kind = \'department\'')
        ->execute([$transfer_id]);
}

/** Re-open the fee-package decision (in case the admin dismissed it by mistake). */
function stt_reopen_package(int $transfer_id): void
{
    db()->prepare('UPDATE student_transfers SET package_action = \'none\' WHERE id = ? AND kind = \'department\'')
        ->execute([$transfer_id]);
}

// ── Batch transfer ──────────────────────────────────────────────────────────

/**
 * Create a Batch Transfer: a real, one-way move of the student's batch.
 * Separate from the pre-existing dual-membership `student_batch_transfers`
 * table — see the module docblock at the top of this file.
 *
 * @return array{ok:bool,message:string,transfer_id:?int}
 */
function stt_create_batch_transfer(int $student_id, int $to_batch_id, ?string $reason, int $created_by): array
{
    $student = stt_get_student($student_id);
    if (!$student) {
        return ['ok' => false, 'message' => 'Student not found.', 'transfer_id' => null];
    }
    if (!can_access_dept((int)$student['dept_id'])) {
        return ['ok' => false, 'message' => 'You do not have permission to transfer this student.', 'transfer_id' => null];
    }

    $batches = stt_batch_map();
    if (!isset($batches[$to_batch_id])) {
        return ['ok' => false, 'message' => 'Please choose a valid batch.', 'transfer_id' => null];
    }

    $from_batch_id = (int)($student['batch_id'] ?? 0);
    if ($from_batch_id === $to_batch_id) {
        return ['ok' => false, 'message' => 'The student is already in this batch.', 'transfer_id' => null];
    }

    $to_batch_row   = $batches[$to_batch_id];
    $from_batch_row = $from_batch_id > 0 ? ($batches[$from_batch_id] ?? null) : null;

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE students SET batch_id = ?, batch = ? WHERE id = ?')
           ->execute([$to_batch_id, $to_batch_row['name'], $student_id]);

        $ins = $db->prepare(
            'INSERT INTO student_transfers
                (student_id, kind, from_batch_id, to_batch_id, from_batch_name, to_batch_name, reason, created_by)
             VALUES (?, \'batch\', ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $student_id,
            $from_batch_id ?: null,
            $to_batch_id,
            $from_batch_row['name'] ?? null,
            $to_batch_row['name'],
            ($reason !== null && $reason !== '') ? $reason : null,
            $created_by,
        ]);
        $transfer_id = (int)$db->lastInsertId();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    log_change('students', 'UPDATE', $student_id, $student['full_name'] . ' (' . $student['student_id'] . ')',
        'batch_id', $from_batch_id ?: null, $to_batch_id,
        'Batch transfer: ' . ($from_batch_row['name'] ?? '— none —') . ' → ' . $to_batch_row['name']);

    return ['ok' => true, 'message' => 'Batch transfer recorded for ' . $student['full_name'] . '.', 'transfer_id' => $transfer_id];
}

// ── Revert ───────────────────────────────────────────────────────────────────
//
// Reverting restores the student's actual academic fields to their exact
// pre-transfer values. The student_transfers row itself is marked reverted
// (reverted_at/reverted_by), never deleted, so the history still shows both
// that the transfer happened AND that it was later undone.
//
// A revert is refused when the student's current state no longer matches
// exactly what this transfer produced — e.g. a later transfer already moved
// them again — so reverting can never silently clobber more recent changes.
//
// Fee packages are NEVER touched by a revert, for department transfers,
// consistent with never automating money changes: if the old package was
// ended as part of the transfer, that deletion is permanent and reverting the
// department does not bring it back (stt_revert_department_transfer() flags
// this in its message so the admin isn't surprised).

/**
 * Whether a transfer can currently be reverted.
 */
function stt_can_revert(array $transfer): bool
{
    if (!empty($transfer['reverted_at'])) {
        return false;
    }
    $student = stt_get_student((int)$transfer['student_id']);
    if (!$student) {
        return false;
    }
    if ($transfer['kind'] === 'department') {
        if (empty($transfer['from_dept_id'])) {
            return false; // Source department no longer known (e.g. it was deleted) — nothing safe to restore.
        }
        return (int)$student['dept_id'] === (int)$transfer['to_dept_id']
            && (int)($student['program_id'] ?? 0) === (int)($transfer['to_program_id'] ?? 0)
            && (string)$student['student_id'] === (string)$transfer['new_student_id'];
    }
    return (int)($student['batch_id'] ?? 0) === (int)$transfer['to_batch_id'];
}

/** Dispatches to the right revert routine for this transfer's kind. */
function stt_revert_transfer(int $transfer_id, int $user_id): array
{
    $transfer = stt_get_transfer($transfer_id);
    if (!$transfer) {
        return ['ok' => false, 'message' => 'Transfer record not found.'];
    }
    return $transfer['kind'] === 'department'
        ? stt_revert_department_transfer($transfer, $user_id)
        : stt_revert_batch_transfer($transfer, $user_id);
}

/**
 * Revert a Department Transfer: restores dept_id/program_id/student_id to
 * their pre-transfer values.
 *
 * @return array{ok:bool,message:string}
 */
function stt_revert_department_transfer(array $transfer, int $user_id): array
{
    if (!stt_can_revert($transfer)) {
        return ['ok' => false, 'message' => 'This transfer can no longer be reverted — either it was already reverted, or the student\'s record has changed since (e.g. another transfer happened after this one).'];
    }

    $student_id = (int)$transfer['student_id'];
    $student    = stt_get_student($student_id);

    if (!can_access_dept((int)$transfer['from_dept_id']) || !can_access_dept((int)$transfer['to_dept_id'])) {
        return ['ok' => false, 'message' => 'You do not have permission to revert this transfer.'];
    }

    $restore_sid = (string)($transfer['old_student_id'] ?: $student['student_id']);
    if ($restore_sid !== $student['student_id']) {
        $dup = db()->prepare('SELECT id FROM students WHERE student_id = ? AND id != ?');
        $dup->execute([$restore_sid, $student_id]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'message' => 'Cannot revert: the previous Student ID "' . $restore_sid . '" is now in use by another student.'];
        }
    }

    $from_dept_row = stt_dept_map()[(int)$transfer['from_dept_id']] ?? null;

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare(
            'UPDATE students SET dept_id = ?, program_id = ?, student_id = ?, faculty_label = ? WHERE id = ?'
        )->execute([
            $transfer['from_dept_id'],
            $transfer['from_program_id'] ?: null,
            $restore_sid,
            $from_dept_row['faculty_label'] ?? null,
            $student_id,
        ]);

        $db->prepare('UPDATE student_transfers SET reverted_at = NOW(), reverted_by = ? WHERE id = ?')
           ->execute([$user_id, (int)$transfer['id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $label = $student['full_name'] . ' (' . $restore_sid . ')';
    log_change('students', 'UPDATE', $student_id, $label, 'dept_id', $transfer['to_dept_id'], $transfer['from_dept_id'],
        'Department transfer #' . $transfer['id'] . ' reverted: back to ' . ($from_dept_row['name'] ?? '—'));

    $message = 'Department transfer reverted — ' . $student['full_name'] . ' is back to their previous department/program/ID.';
    if ($transfer['package_action'] === 'ended') {
        $message .= ' Note: the fee package that was ended during this transfer was permanently deleted and has NOT been restored.';
    }

    return ['ok' => true, 'message' => $message];
}

/**
 * Revert a Batch Transfer: restores batch_id/batch to their pre-transfer
 * values.
 *
 * @return array{ok:bool,message:string}
 */
function stt_revert_batch_transfer(array $transfer, int $user_id): array
{
    if (!stt_can_revert($transfer)) {
        return ['ok' => false, 'message' => 'This transfer can no longer be reverted — either it was already reverted, or the student\'s record has changed since (e.g. another transfer happened after this one).'];
    }

    $student_id = (int)$transfer['student_id'];
    $student    = stt_get_student($student_id);

    if (!can_access_dept((int)$student['dept_id'])) {
        return ['ok' => false, 'message' => 'You do not have permission to revert this transfer.'];
    }

    $restore_batch_id   = $transfer['from_batch_id'] !== null ? (int)$transfer['from_batch_id'] : null;
    $restore_batch_name = $transfer['from_batch_name'];

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE students SET batch_id = ?, batch = ? WHERE id = ?')
           ->execute([$restore_batch_id, $restore_batch_name, $student_id]);

        $db->prepare('UPDATE student_transfers SET reverted_at = NOW(), reverted_by = ? WHERE id = ?')
           ->execute([$user_id, (int)$transfer['id']]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    log_change('students', 'UPDATE', $student_id, $student['full_name'] . ' (' . $student['student_id'] . ')',
        'batch_id', $transfer['to_batch_id'], $restore_batch_id,
        'Batch transfer #' . $transfer['id'] . ' reverted: back to ' . ($restore_batch_name ?? '— none —'));

    return ['ok' => true, 'message' => 'Batch transfer reverted — ' . $student['full_name'] . ' is back in ' . ($restore_batch_name ?? 'their previous batch') . '.'];
}

// ── Lookups for view/index pages ────────────────────────────────────────────

/** Fetch a single transfer record joined with student + creator name. */
function stt_get_transfer(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT t.*, s.full_name AS student_name, s.student_id AS student_sid,
                u.full_name AS created_by_name, r.full_name AS reverted_by_name
           FROM student_transfers t
           JOIN students s ON s.id = t.student_id
      LEFT JOIN users u     ON u.id = t.created_by
      LEFT JOIN users r     ON r.id = t.reverted_by
          WHERE t.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Small badge for a transfer kind. */
function stt_kind_badge(string $kind): string
{
    return $kind === 'batch'
        ? '<span class="badge bg-info text-dark"><i class="fas fa-users me-1"></i>Batch Transfer</span>'
        : '<span class="badge bg-primary"><i class="fas fa-building me-1"></i>Department Transfer</span>';
}

/** "Reverted" badge shown next to a transfer that has been undone. */
function stt_reverted_badge(): string
{
    return '<span class="badge bg-secondary"><i class="fas fa-rotate-left me-1"></i>Reverted</span>';
}
