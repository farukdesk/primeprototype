<?php
/**
 * VC Approval – Reject a pending scholarship request.
 * POST-only. Accessible to users with vc-approval can_edit, and super-admins.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('vc-approval', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/vc-approval/index.php');
}

csrf_check();

$id          = (int)($_POST['id'] ?? 0);
$review_note = trim($_POST['review_note'] ?? '');
$user        = auth_user();

if ($review_note === '') {
    flash_set('error', 'A reason for rejection is required.');
    redirect(APP_URL . '/vc-approval/index.php?tab=pending');
}

$req = vca_get_request($id);

if (!$req) {
    flash_set('error', 'Approval request not found.');
    redirect(APP_URL . '/vc-approval/index.php');
}

if ($req['status'] !== 'pending') {
    flash_set('error', 'This request is no longer pending (status: ' . h($req['status']) . ').');
    redirect(APP_URL . '/vc-approval/index.php');
}

db()->prepare(
    "UPDATE vc_scholarship_approvals
     SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
     WHERE id = ?"
)->execute([$user['id'], $review_note, $id]);

log_change(
    'vc-approval', 'UPDATE', $id,
    $req['student_name'] . ' – ' . $req['label'],
    'status',
    'pending',
    'rejected',
    'VC rejected scholarship "' . $req['label'] . '" for ' . $req['student_name'] . '. Reason: ' . $review_note
);

flash_set('success',
    'Scholarship request <strong>' . h($req['label']) . '</strong> for '
    . h($req['student_name']) . ' has been rejected.'
);

redirect(APP_URL . '/vc-approval/index.php?tab=pending');
