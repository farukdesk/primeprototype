<?php
/**
 * Student Portal API – POST /api/student/auth/logout.php
 * =========================================================
 * Revokes the current session token.
 */

require_once __DIR__ . '/../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$header = api_authorization_header();
if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
    $hash = hash('sha256', trim($m[1]));
    db()->prepare('DELETE FROM api_tokens WHERE token = ?')->execute([$hash]);
}

sp_api_ok(['message' => 'Logged out successfully.']);
