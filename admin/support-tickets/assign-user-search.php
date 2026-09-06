<?php
/**
 * IT Support Tickets – Assign-ticket user search (AJAX).
 * Returns active users matching the query (name, username or email),
 * grouped by their primary user group. Staff only.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!st_is_staff()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Escape LIKE special characters to prevent unintended wildcard matching
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
$like = '%' . $escaped . '%';

$stmt = db()->prepare(
    "SELECT u.id, u.full_name, u.username, COALESCE(g.name, 'No Group') AS group_name
     FROM users u
     LEFT JOIN user_groups g ON g.id = u.group_id
     WHERE u.is_active = 1
       AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)
     ORDER BY group_name ASC, u.full_name ASC
     LIMIT 30"
);
$stmt->execute([$like, $like, $like]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
