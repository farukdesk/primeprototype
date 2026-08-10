<?php
/**
 * Delete a DRAFT mark sheet.
 *
 * Only the sheet's creator (or a super admin) may delete, and only while the
 * sheet is still a draft. Pending, returned and published sheets are protected
 * so the approval workflow and published results can never be silently removed.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/results/index.php?tab=my_sheets');
}
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$user = auth_user();

$stmt = db()->prepare('SELECT * FROM result_mark_sheets WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$sheet = $stmt->fetch();

if (!$sheet) {
    flash_set('error', 'Mark sheet not found.');
    redirect(APP_URL . '/results/index.php?tab=my_sheets');
}

$is_creator = (int)$sheet['created_by'] === (int)$user['id'];
if (!$is_creator && !is_super_admin()) {
    flash_set('error', 'You can only delete your own mark sheets.');
    redirect(APP_URL . '/results/index.php?tab=my_sheets');
}
if ($sheet['workflow_status'] !== 'draft') {
    flash_set('error', 'Only draft mark sheets can be deleted. Submitted or published sheets are protected.');
    redirect(APP_URL . '/results/index.php?tab=my_sheets');
}

try {
    db()->prepare('DELETE FROM result_sheet_grades WHERE sheet_id = ?')->execute([$id]);
} catch (Throwable $_e) {}
try {
    db()->prepare('DELETE FROM wf_sheet_history WHERE sheet_id = ?')->execute([$id]);
} catch (Throwable $_e) {}
db()->prepare('DELETE FROM result_mark_sheets WHERE id = ?')->execute([$id]);

$label = trim(($sheet['subject_code'] ? '[' . $sheet['subject_code'] . '] ' : '') . (string)($sheet['subject_title'] ?? ''));
if (function_exists('log_change')) {
    log_change(
        'results', 'DELETE', $id,
        $label !== '' ? $label : ('Sheet #' . $id),
        null, null, null,
        'Draft mark sheet'
            . ($label !== '' ? ' "' . $label . '"' : ' #' . $id)
            . ($sheet['semester'] ? ' (' . $sheet['semester'] . ')' : '')
            . ' deleted by ' . ($user['full_name'] ?? ('user #' . (int)$user['id'])) . '.'
    );
}

flash_set('success', 'Draft mark sheet <strong>' . h($label !== '' ? $label : ('#' . $id)) . '</strong> deleted.');
redirect(APP_URL . '/results/index.php?tab=my_sheets');
