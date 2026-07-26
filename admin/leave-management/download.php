<?php
/**
 * Download an approved leave request as a "Leave Application" PDF.
 * Available only once the request is approved, and only to users who may view
 * the request (requester, admin, or a member of any approval-step group).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
// Module access OR self-service (Administrative / Faculty employee types).
if (!lm_can_view()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}
require_once dirname(__DIR__) . '/../vendor/autoload.php';

$user = auth_user();
$id   = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid request.'); redirect(APP_URL . '/leave-management/index.php'); }

$stmt = db()->prepare(
    'SELECT r.*, u.full_name AS requester_name, u.email AS requester_email,
            sp.employee_id, sd.name AS dept_name
       FROM leave_requests r
       JOIN users u ON u.id = r.user_id
  LEFT JOIN staff_profiles sp ON sp.user_id = u.id
  LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
      WHERE r.id = ?'
);
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { flash_set('error', 'Leave request not found.'); redirect(APP_URL . '/leave-management/index.php'); }

$approvals = lm_request_approvals($id);

// ── Visibility: requester, admin, or a member of any approval-step group ──────
$is_owner  = (int)$req['user_id'] === (int)$user['id'];
$is_admin  = lm_is_admin();
$group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
$in_flow   = false;
foreach ($approvals as $a) {
    if (in_array((int)$a['group_id'], $group_ids, true)) { $in_flow = true; break; }
}
if (!$is_owner && !$is_admin && !$in_flow) {
    flash_set('error', 'You do not have permission to view this request.');
    redirect(APP_URL . '/leave-management/index.php');
}

// ── Only approved requests can be downloaded ─────────────────────────────────
if ($req['status'] !== 'approved') {
    flash_set('error', 'The leave application PDF is available only after the request is approved.');
    redirect(APP_URL . '/leave-management/view.php?id=' . $id);
}

$profile = [
    'employee_id' => $req['employee_id'] ?? '',
    'dept_name'   => $req['dept_name'] ?? '',
];

$html = lm_build_pdf_html($req, $approvals, $profile);

$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'leave-application-' . $id . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
