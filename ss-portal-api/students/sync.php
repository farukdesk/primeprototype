<?php
/**
 * SS Portal – students/sync.php (POST)
 * Send or retry sending a local student to Prime University.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sync.php';

$user = ssp_require_login();
if (!ssp_is_post()) {
    ssp_redirect('dashboard.php');
}
ssp_csrf_verify();

$id     = (int)($_POST['id'] ?? 0);
$result = ssp_sync_student($id, (int)$user['id']);

if ($result['ok']) {
    ssp_flash('success', $result['message'] . (empty($result['already']) && !isset($result['changed_fields']) ? ' University Student ID: ' . ($result['student_id'] ?? 'n/a') . '.' : ''));
    foreach ($result['warnings'] ?? [] as $w) {
        ssp_flash('warning', 'University warning: ' . $w);
    }
} elseif (!empty($result['errors'])) {
    ssp_flash('error', 'Prime University rejected the data: ' . $result['message'] . ' Edit the student to correct the fields.');
} else {
    ssp_flash('error', 'Sending failed: ' . $result['message'] . (!empty($result['retryable']) ? ' You can retry safely.' : ''));
}

ssp_redirect($id > 0 ? 'students/view.php?id=' . $id : 'dashboard.php');
