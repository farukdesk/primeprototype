<?php
/**
 * API – POST /api/auth/logout.php
 * =================================
 * Invalidates the current API token (deletes it from the database).
 *
 * Request headers:
 *   Authorization: ******
 *
 * Success response:
 *   { "ok": true, "message": "Logged out successfully." }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

$API_USER = api_auth();

// Delete ALL tokens for this device if a device_id header is present,
// otherwise delete only the exact token that was presented.
$device_id = trim($_SERVER['HTTP_X_DEVICE_ID'] ?? '');

if ($device_id !== '') {
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND device_id = ?')
       ->execute([$API_USER['user_id'], $device_id]);
} else {
    // Identify the token row by its hash (same hash used in login.php)
    preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m);
    $raw_token   = trim($m[1] ?? '');
    $stored_hash = hash('sha256', $raw_token);
    db()->prepare('DELETE FROM api_tokens WHERE token = ?')
       ->execute([$stored_hash]);
}

api_ok(['message' => 'Logged out successfully.']);
