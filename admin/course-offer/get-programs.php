<?php
/**
 * AJAX – programs for a given dept_id.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if ($dept_id <= 0) { echo '[]'; exit; }

echo json_encode(co_programs($dept_id));
