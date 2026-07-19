<?php
/**
 * Session keepalive / status endpoint for the idle auto-logout feature.
 *
 *   GET session-ping.php?action=ping    – refresh last_activity (user is active)
 *   GET session-ping.php?action=status  – report remaining idle time without refreshing
 *
 * Returns JSON: { ok: bool, remaining?: int, reason?: string }
 */
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'unauthenticated']);
    exit;
}

$last    = $_SESSION['last_activity'] ?? time();
$elapsed = time() - $last;

// Session already idle past the limit – destroy it
if ($elapsed > IDLE_TIMEOUT) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'timeout']);
    exit;
}

$action = $_GET['action'] ?? 'ping';
if ($action === 'ping') {
    $_SESSION['last_activity'] = time();
    $elapsed = 0;
}

echo json_encode(['ok' => true, 'remaining' => max(0, IDLE_TIMEOUT - $elapsed)]);
