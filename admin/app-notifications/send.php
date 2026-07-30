<?php
/**
 * App Notification – Send handler (POST)
 * ======================================
 * Publishes a push notification to the selected audience: all students,
 * all users/employees, an individual user, a user group, an employee type
 * (Administrative / Faculty) or everyone.
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

$result = apn_send_to_audience(
    $audience, $title, $body, $url !== '' ? $url : null,
    $target_user_id, $target_group_id, $employee_type
);
$user = auth_user();
apn_record_with_audience($title, $body, $url !== '' ? $url : null, $user['id'] ?? null, $result, $audience);

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
