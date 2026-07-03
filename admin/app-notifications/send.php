<?php
/**
 * App Notification – Send handler (POST)
 * ======================================
 * Publishes a push notification to every installed student device.
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

$errors = [];
if ($title === '')                 $errors[] = 'Title is required.';
if (mb_strlen($title) > 150)       $errors[] = 'Title must be 150 characters or fewer.';
if ($body === '')                  $errors[] = 'Message body is required.';
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
    $errors[] = 'Link URL is not a valid URL.';
}
if (!apn_fcm_is_configured()) {
    $errors[] = 'Firebase Cloud Messaging is not configured yet. Please set it up on the Settings page.';
}

if ($errors) {
    save_old(['title' => $title, 'body' => $body, 'url' => $url]);
    flash_set('error', implode(' ', $errors));
    redirect(APP_URL . '/app-notifications/index.php');
}

$result = apn_send_to_all_students($title, $body, $url !== '' ? $url : null);
$user   = auth_user();
apn_record($title, $body, $url !== '' ? $url : null, $user['id'] ?? null, $result);

if ($result['status'] === 'sent' && $result['total'] === 0) {
    flash_set('warning', 'No student devices are registered yet, so nothing was delivered. The notification was recorded.');
} elseif ($result['status'] === 'sent') {
    flash_set('success', "Notification sent to {$result['sent']} device(s).");
} elseif ($result['status'] === 'partial') {
    flash_set('warning', "Notification partially delivered: {$result['sent']} sent, {$result['failed']} failed.");
} else {
    flash_set('error', $result['error'] ?? 'Notification could not be delivered.');
}

redirect(APP_URL . '/app-notifications/index.php');
