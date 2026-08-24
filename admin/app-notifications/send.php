<?php
/**
 * App Notification – Send handler (POST)
 * ======================================
 * Publishes a push notification to the selected audience: all students, a
 * specific student batch, all users/employees, an individual user, a user
 * group, an employee type (Administrative / Faculty) or everyone.
 *
 * Delivery is queue-based: recipients are inserted into
 * app_notification_queue with pure SQL (no device tokens are ever loaded into
 * PHP memory) and process.php – polled from the module page – delivers the
 * queue in small time-boxed chunks. This keeps memory flat and avoids
 * max_execution_time even for tens of thousands of devices.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications', 'can_create');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/app-notifications/index.php');
}
csrf_check();

$title = trim($_POST['title'] ?? '');
$body  = trim($_POST['body']  ?? '');
$url   = trim($_POST['url']   ?? '');

$audience = $_POST['audience'] ?? 'students';
if (!in_array($audience, APN_AUDIENCES, true)) {
    $audience = 'students';
}
$target_user_id  = (int)($_POST['target_user_id']  ?? 0);
$target_group_id = (int)($_POST['target_group_id'] ?? 0);
$target_batch_id = (int)($_POST['target_batch_id'] ?? 0);
$employee_type   = in_array($_POST['employee_type'] ?? '', ['administrative', 'educational'], true)
    ? $_POST['employee_type'] : '';

$errors = [];
if ($title === '')                 $errors[] = 'Title is required.';
if (mb_strlen($title) > 150)       $errors[] = 'Title must be 150 characters or fewer.';
if ($body === '')                  $errors[] = 'Message body is required.';
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
    $errors[] = 'Link URL is not a valid URL.';
}
if ($audience === 'user' && $target_user_id < 1) {
    $errors[] = 'Please choose the user to notify.';
}
if ($audience === 'group' && $target_group_id < 1) {
    $errors[] = 'Please choose the user group to notify.';
}
if ($audience === 'batch' && $target_batch_id < 1) {
    $errors[] = 'Please choose the student batch to notify.';
}
if ($audience === 'employee_type' && $employee_type === '') {
    $errors[] = 'Please choose the employee type to notify.';
}
if (!apn_fcm_is_configured()) {
    $errors[] = 'Firebase Cloud Messaging is not configured yet. Please set it up on the Settings page.';
}

if ($errors) {
    save_old(['title' => $title, 'body' => $body, 'url' => $url]);
    flash_set('error', implode(' ', $errors));
    redirect(APP_URL . '/app-notifications/index.php');
}

$user        = auth_user();
$url_or_null = $url !== '' ? $url : null;

apn_queue_ensure_schema();

if (!apn_queue_is_available()) {
    // The queue schema could not be created (e.g. the DB user lacks ALTER
    // privileges). Fall back to the legacy synchronous delivery.
    if ($audience === 'batch') {
        flash_set('error', 'Batch targeting requires the delivery queue. Please run admin/app-notifications-queue.sql once and try again.');
        redirect(APP_URL . '/app-notifications/index.php');
    }
    $result = apn_send_to_audience($audience, $title, $body, $url_or_null, $target_user_id, $target_group_id, $employee_type);
    apn_record_with_audience(
        $title, $body, $url_or_null, $user['id'] ?? null,
        $result, $audience, $target_user_id, $target_group_id, $employee_type
    );
    $audience_label = apn_audience_label($audience, $result['detail'] ?? null);
    if ($result['status'] === 'sent' && $result['total'] === 0) {
        flash_set('warning', "No registered devices matched \"{$audience_label}\", so nothing was delivered. The notification was recorded.");
    } elseif ($result['status'] === 'sent') {
        flash_set('success', "Notification sent to {$result['sent']} device(s) – {$audience_label}.");
    } elseif ($result['status'] === 'partial') {
        flash_set('warning', "Notification partially delivered: {$result['sent']} sent, {$result['failed']} failed ({$audience_label}).");
    } else {
        flash_set('error', $result['error'] ?? 'Notification could not be delivered.');
    }
    redirect(APP_URL . '/app-notifications/index.php');
}

// Queue-based delivery: record first, then enqueue recipients with pure SQL.
$detail      = apn_audience_detail($audience, $target_user_id, $target_group_id, $employee_type, $target_batch_id);
$placeholder = ['total' => 0, 'sent' => 0, 'failed' => 0, 'status' => 'queued', 'error' => null, 'detail' => $detail];

$notification_id = apn_record_with_audience(
    $title, $body, $url_or_null, $user['id'] ?? null,
    $placeholder, $audience, $target_user_id, $target_group_id, $employee_type
);
try {
    db()->prepare('UPDATE app_notifications SET target_batch_id = ? WHERE id = ?')
       ->execute([$target_batch_id > 0 ? $target_batch_id : null, $notification_id]);
} catch (Throwable $e) {
    // target_batch_id column missing (auto-added by ensure_schema) – ignore.
}

$queued         = apn_queue_audience($notification_id, $audience, $target_user_id, $target_group_id, $employee_type, $target_batch_id);
$audience_label = apn_audience_label($audience, $detail);

if ($queued === 0) {
    flash_set('warning', "No registered devices matched \"{$audience_label}\", so nothing was delivered. The notification was recorded.");
    redirect(APP_URL . '/app-notifications/index.php');
}

flash_set('success', "Notification queued for {$queued} device(s) – {$audience_label}. Delivery is running below; keep this page open until it completes.");
redirect(APP_URL . '/app-notifications/index.php?processing=' . $notification_id);
