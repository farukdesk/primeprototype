<?php
/**
 * App Notification – Queue processor (AJAX)
 * ==========================================
 * Delivers one small, time-boxed chunk of a queued notification and returns
 * JSON progress. index.php polls this endpoint after send.php/resend.php has
 * queued the recipients, so bulk sends never exhaust PHP memory or hit
 * max_execution_time – each request only ever touches a bounded number of
 * rows regardless of the audience size.
 *
 * Response: { ok, done, total, sent, failed, pending, error }
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    echo json_encode(['ok' => false, 'error' => 'Missing notification id.']);
    exit;
}

// Each poll processes up to ~40 sends within a ~20 second budget; the page
// keeps polling until the queue is drained.
@set_time_limit(90);
ignore_user_abort(true);

$progress = apn_process_queue($id, 40, 20);

if ($progress['error'] !== null) {
    echo json_encode(['ok' => false, 'error' => $progress['error']]);
    exit;
}

echo json_encode(array_merge(['ok' => true], $progress));
