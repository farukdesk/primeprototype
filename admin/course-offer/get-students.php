<?php
/**
 * AJAX – filtered + paginated student search for bulk enrollment.
 * Accepts: batch_id (0 or omitted = search across all batches),
 *          q (search string), section, shift,
 *          page (default 1), per_page (default 25)
 * Returns: {
 *   rows: [{id, student_id, full_name, section, shift, batch_name, dept_name, program_name}],
 *   total, page, per_page, pages
 * }
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!co_is_staff()) {
    echo json_encode(['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0]);
    exit;
}

$batch_id = (int)($_GET['batch_id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = (int)($_GET['per_page'] ?? 25);
$per_page = max(1, min(100, $per_page));

$filters = [
    'q'       => trim($_GET['q'] ?? ''),
    'section' => trim($_GET['section'] ?? ''),
    'shift'   => trim($_GET['shift'] ?? ''),
];

$result = co_batch_students_filtered($batch_id, $filters, $page, $per_page);
$total  = (int)$result['total'];
$pages  = (int)ceil($total / $per_page);

echo json_encode([
    'rows'     => array_values($result['rows']),
    'total'    => $total,
    'page'     => $page,
    'per_page' => $per_page,
    'pages'    => $pages,
]);
