<?php
/**
 * AJAX – search students within a batch for manual course enrollment.
 * Accepts: batch_id (required), q (search string), limit (default 50)
 * Returns: [{id, text, student_id, full_name, section}]
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!co_is_staff()) { echo '[]'; exit; }

$batch_id = (int)($_GET['batch_id'] ?? 0);
$q        = trim($_GET['q'] ?? '');
$limit    = (int)($_GET['limit'] ?? 50);

if ($batch_id <= 0) { echo '[]'; exit; }

$rows = co_batch_students($batch_id, $q, $limit);

foreach ($rows as &$row) {
    $section     = $row['section'] ? ' — Sec ' . $row['section'] : '';
    $row['text'] = $row['student_id'] . ' · ' . $row['full_name'] . $section;
}
unset($row);

echo json_encode(array_values($rows));
