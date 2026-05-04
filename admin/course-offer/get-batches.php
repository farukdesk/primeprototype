<?php
/**
 * AJAX – batches (intakes) for a given program_id.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$program_id = (int)($_GET['program_id'] ?? 0);
if ($program_id <= 0) { echo '[]'; exit; }

$batches = co_batches($program_id);

// Enrich with a formatted label for the UI
foreach ($batches as &$b) {
    $b['label'] = co_batch_label($b);
}
unset($b);

echo json_encode($batches);
