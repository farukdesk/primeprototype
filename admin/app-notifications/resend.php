<?php
/**
 * App Notification – Resend handler (POST)
 * =========================================
 * Re-delivers a previously sent notification to its original audience using
 * the targeting stored by send.php (see admin/app-notifications-resend.sql).
 * Older notifications that predate resend support have no stored audience;
 * for those the compose form is pre-filled instead so the admin can pick the
 * audience and send again.
 *
 * Like send.php, the resend is queue-based (memory-safe for any audience
 * size); the module page polls process.php until delivery completes.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications', 'can_create');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/app-notifications/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$n  = $id ? apn_find($id) : null;
if (!$n) {
    flash_set('error', 'Notification not found.');
    redirect(APP_URL . '/app-notifications/index.php');
}

if (!apn_fcm_is_configured()) {
    flash_set('error', 'Firebase Cloud Messaging is not configured yet. Please set it up on the Settings page.');
    redirect(APP_URL . '/app-notifications/index.php');
}

$audience = (string)($n['audience_code'] ?? '');
if ($audience === '' || !in_array($audience, APN_AUDIENCES, true)) {
    // No stored targeting (sent before the resend migration) – fall back to
    // pre-filling the compose form so the admin can choose the audience.
    save_old(['title' => $n['title'], 'body' => $n['body'], 'url' => (string)($n['url'] ?? '')]);
    flash_set('warning', 'The original audience of this notification was not stored, so it cannot be resent automatically. The compose form has been pre-filled – choose the audience and send.');
    redirect(APP_URL . '/app-notifications/index.php');
}

$target_user_id  = (int)($n['target_user_id'] ?? 0);
$target_group_id = (int)($n['target_group_id'] ?? 0);
$target_batch_id = (int)($n['target_batch_id'] ?? 0);
$employee_type   = (string)($n['employee_type'] ?? '');
$url_or_null     = !empty($n['url']) ? $n['url'] : null;
$user            = auth_user();

apn_queue_ensure_schema();

if (!apn_queue_is_available()) {
    // Legacy synchronous fallback (queue schema unavailable).
    if ($audience === 'batch') {
        flash_set('error', 'Batch targeting requires the delivery queue. Please run admin/app-notifications-queue.sql once and try again.');
        redirect(APP_URL . '/app-notifications/index.php');
    }
    $result = apn_send_to_audience(
        $audience, $n['title'], $n['body'], $url_or_null,
        $target_user_id, $target_group_id, $employee_type
    );
    apn_record_with_audience(
        $n['title'], $n['body'], $url_or_null, $user['id'] ?? null,
        $result, $audience, $target_user_id, $target_group_id, $employee_type
    );
    $audience_label = apn_audience_label($audience, $result['detail'] ?? null);
    if ($result['status'] === 'sent' && $result['total'] === 0) {
        flash_set('warning', "No registered devices matched \"{$audience_label}\", so nothing was delivered. The resend was recorded.");
    } elseif ($result['status'] === 'sent') {
        flash_set('success', "Notification resent to {$result['sent']} device(s) – {$audience_label}.");
    } elseif ($result['status'] === 'partial') {
        flash_set('warning', "Notification partially redelivered: {$result['sent']} sent, {$result['failed']} failed ({$audience_label}).");
    } else {
        flash_set('error', $result['error'] ?? 'Notification could not be redelivered.');
    }
    redirect(APP_URL . '/app-notifications/index.php');
}

// Queue-based resend: record a new history row, then enqueue the recipients.
$detail      = apn_audience_detail($audience, $target_user_id, $target_group_id, $employee_type, $target_batch_id);
$placeholder = ['total' => 0, 'sent' => 0, 'failed' => 0, 'status' => 'queued', 'error' => null, 'detail' => $detail];

$notification_id = apn_record_with_audience(
    $n['title'], $n['body'], $url_or_null, $user['id'] ?? null,
    $placeholder, $audience, $target_user_id, $target_group_id, $employee_type
);
try {
    db()->prepare('UPDATE app_notifications SET target_batch_id = ? WHERE id = ?')
       ->execute([$target_batch_id > 0 ? $target_batch_id : null, $notification_id]);
} catch (Throwable $e) {
}

$queued         = apn_queue_audience($notification_id, $audience, $target_user_id, $target_group_id, $employee_type, $target_batch_id);
$audience_label = apn_audience_label($audience, $detail);

if ($queued === 0) {
    flash_set('warning', "No registered devices matched \"{$audience_label}\", so nothing was delivered. The resend was recorded.");
    redirect(APP_URL . '/app-notifications/index.php');
}

flash_set('success', "Notification queued for {$queued} device(s) – {$audience_label}. Delivery is running below; keep this page open until it completes.");
redirect(APP_URL . '/app-notifications/index.php?processing=' . $notification_id);
