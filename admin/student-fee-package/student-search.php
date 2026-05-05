<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$user = auth_user();
if (!$user) { echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$stmt = db()->prepare(
    'SELECT id, student_id, full_name
     FROM students
     WHERE (full_name LIKE ? OR student_id LIKE ?) AND status = \'Active\'
     ORDER BY full_name
     LIMIT 15'
);
$stmt->execute(["%$q%", "%$q%"]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);
