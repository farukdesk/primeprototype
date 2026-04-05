<?php
/**
 * AJAX endpoint – returns a JSON array of programs for a given dept_id.
 * Called by the index page when the department dropdown changes.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if ($dept_id <= 0) {
    echo json_encode([]);
    exit;
}

$programs = cc_programs($dept_id);
echo json_encode($programs);
