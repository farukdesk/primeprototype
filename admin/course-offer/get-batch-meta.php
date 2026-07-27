<?php
/**
 * AJAX – distinct shifts and sections available in a batch.
 * Derived from the students of the batch (incl. active batch transfers),
 * same source as the enrollment filters. Empty arrays mean the batch has
 * no shifts / no sections, so the form keeps those fields disabled.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$batch_id = (int)($_GET['batch_id'] ?? 0);

if ($batch_id <= 0) {
    echo json_encode(['shifts' => [], 'sections' => []]);
    exit;
}

echo json_encode([
    'shifts'   => co_batch_shifts($batch_id),
    'sections' => co_batch_sections($batch_id),
]);
