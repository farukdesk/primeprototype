<?php
/**
 * IT Support Tickets – @mention user search (AJAX)
 * Returns up to 10 active users matching the given query (username or full name).
 */

require_once __DIR__ . '/../includes/auth.php';

auth_check();

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');

if (mb_strlen($q) < 3) {
    echo json_encode([]);
    exit;
}

// Escape LIKE special characters to prevent unintended wildcard matching
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
$like = '%' . $escaped . '%';
$stmt = db()->prepare(
    'SELECT username, full_name
     FROM users
     WHERE is_active = 1
       AND (username LIKE ? OR full_name LIKE ?)
     ORDER BY username
     LIMIT 10'
);
$stmt->execute([$like, $like]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
