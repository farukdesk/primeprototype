<?php
/**
 * AJAX: return current workflow status + brief history for a mark sheet.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/workflow-helpers.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['error' => 'Invalid ID']); exit; }

$stmt = db()->prepare(
    'SELECT ms.id, ms.workflow_status, ms.submitted_at, ms.reviewed_at,
            ms.hod_approved_at, ms.published_at, ms.returned_at,
            ms.reviewer_remarks, ms.hod_remarks, ms.return_remarks,
            ms.returned_to_step,
            u_c.username AS creator_name,
            u_r.username AS reviewer_name,
            u_h.username AS hod_name,
            u_p.username AS publisher_name,
            u_ret.username AS returned_by_name
     FROM result_mark_sheets ms
     LEFT JOIN users u_c   ON u_c.id   = ms.created_by
     LEFT JOIN users u_r   ON u_r.id   = ms.reviewed_by
     LEFT JOIN users u_h   ON u_h.id   = ms.hod_approved_by
     LEFT JOIN users u_p   ON u_p.id   = ms.published_by
     LEFT JOIN users u_ret ON u_ret.id = ms.returned_by
     WHERE ms.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Sheet not found']);
    exit;
}

echo json_encode($row);
