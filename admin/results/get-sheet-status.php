<?php
/**
 * AJAX: Return current workflow status + chain progress for a sheet.
 * Used by workflow-review.php and mark-entry.php status panels.
 * GET ?id=SHEET_ID
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/workflow-helpers.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

try {
    $stmt = db()->prepare(
        'SELECT ms.id, ms.workflow_status, ms.current_step_order, ms.chain_id,
                ms.subject_title, ms.semester,
                s.step_label AS current_step_label,
                g.name       AS current_group_name
         FROM result_mark_sheets ms
         LEFT JOIN wf_chain_steps s ON s.chain_id = ms.chain_id AND s.step_order = ms.current_step_order
         LEFT JOIN user_groups g    ON g.id = s.group_id
         WHERE ms.id = ?'
    );
    $stmt->execute([$id]);
    $sheet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sheet) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    // Chain steps for progress bar
    $steps = $sheet['chain_id'] ? wf_get_chain_steps((int)$sheet['chain_id']) : [];

    // History
    $history = wf_get_sheet_history($id);

    echo json_encode([
        'id'                  => $sheet['id'],
        'workflow_status'     => $sheet['workflow_status'],
        'current_step_order'  => $sheet['current_step_order'],
        'current_step_label'  => $sheet['current_step_label'],
        'current_group_name'  => $sheet['current_group_name'],
        'chain_steps'         => $steps,
        'history'             => $history,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
