<?php
/**
 * AJAX – all active student batches (independent of program).
 * Student profiles use student_batches, so course offers do too.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$batches = co_student_batches();

// Normalise to a label key that the JS expects
foreach ($batches as &$b) {
    $b['label'] = $b['name'];
}
unset($b);

echo json_encode($batches);
