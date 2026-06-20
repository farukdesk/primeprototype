<?php
/**
 * AJAX endpoint – returns a JSON array of active faculty for a given dept_id.
 * Called by create/edit forms when the department context is loaded.
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

$faculty = cc_get_dept_faculty($dept_id);
echo json_encode($faculty);
