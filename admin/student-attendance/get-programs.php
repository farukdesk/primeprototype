<?php
/**
 * AJAX – programs for a given dept_id (Student Attendance filter cascade).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if ($dept_id <= 0) { echo '[]'; exit; }

// Only expose programs for departments the user is scoped to.
if (!can_access_dept($dept_id)) { echo '[]'; exit; }

echo json_encode(sa_programs($dept_id));
