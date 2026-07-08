<?php
/**
 * Student Management – Bulk Quick Update
 *
 * Applies a quick update of Status, Shift and/or Section to a set of selected
 * students. Fields left as "keep" are not touched. Only students within the
 * current user's department scope are updated.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_edit');
require_once __DIR__ . '/helpers.php';

// Preserve the caller's active filters so we return to the same filtered list.
[$ret_qs, $back_url] = sm_return_url($_POST['ret'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
}

csrf_check();

// ── Collect and sanitise selected student ids ─────────────────────────────────
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [];
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

// ── Fields to update ──────────────────────────────────────────────────────────
$status  = $_POST['status']  ?? '';
$shift   = $_POST['shift']   ?? '';
$section = $_POST['section'] ?? '';

$valid_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];

$set   = [];
$vals  = [];
$applied = [];

if ($status !== '') {
    if (!in_array($status, $valid_statuses, true)) {
        flash_set('error', 'Invalid status selected.');
        redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
    }
    $set[]     = 'status = ?';
    $vals[]    = $status;
    $applied[] = 'Status → ' . $status;
}
if ($shift !== '') {
    if (!in_array($shift, SM_SHIFTS, true)) {
        flash_set('error', 'Invalid shift selected.');
        redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
    }
    $set[]     = 'shift = ?';
    $vals[]    = $shift;
    $applied[] = 'Shift → ' . $shift;
}
if ($section !== '') {
    if (!in_array($section, SM_SECTIONS, true)) {
        flash_set('error', 'Invalid section selected.');
        redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
    }
    $set[]     = 'section = ?';
    $vals[]    = $section;
    $applied[] = 'Section → ' . $section;
}

if (empty($ids)) {
    flash_set('error', 'No students were selected for the quick update.');
    redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
}
if (empty($set)) {
    flash_set('error', 'Choose at least one field (Status, Shift or Section) to update.');
    redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
}

// ── Restrict the target ids to the current user's department scope ────────────
$where  = ['s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
$params = $ids;

$dept_scope = get_dept_scope();
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        flash_set('error', 'You do not have permission to update these students.');
        redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
    }
    $where[]  = 's.dept_id IN (' . implode(',', array_fill(0, count($dept_scope), '?')) . ')';
    $params   = array_merge($params, $dept_scope);
}

// Fetch the students we are actually allowed to touch (for logging + count).
$sel = db()->prepare(
    'SELECT s.id, s.student_id, s.full_name FROM students s WHERE ' . implode(' AND ', $where)
);
$sel->execute($params);
$targets = $sel->fetchAll();

if (empty($targets)) {
    flash_set('error', 'No matching students were found within your department scope.');
    redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
}

$target_ids = array_map(fn($r) => (int)$r['id'], $targets);

// ── Apply the update ──────────────────────────────────────────────────────────
$id_phs   = implode(',', array_fill(0, count($target_ids), '?'));
$upd_sql  = 'UPDATE students SET ' . implode(', ', $set)
          . ' WHERE id IN (' . $id_phs . ')';
$upd_vals = array_merge($vals, $target_ids);

try {
    db()->beginTransaction();
    db()->prepare($upd_sql)->execute($upd_vals);

    $summary = implode(', ', $applied);
    foreach ($targets as $t) {
        $label = $t['full_name'] . ' (' . $t['student_id'] . ')';
        log_change('students', 'UPDATE', (int)$t['id'], $label,
                   null, null, null,
                   'Bulk quick update: ' . $summary);
    }

    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash_set('error', 'Could not apply the quick update right now. Please try again.');
    redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
}

$n = count($targets);
flash_set('success', 'Quick update applied to <strong>' . $n . '</strong> student' . ($n !== 1 ? 's' : '')
                     . ' (' . h(implode(', ', $applied)) . ').');
redirect($ret_qs !== '' ? $back_url : APP_URL . '/students/index.php');
