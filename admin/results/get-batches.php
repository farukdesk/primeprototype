<?php
/**
 * AJAX: return distinct batch values from the students table.
 * Filters by dept_id and optionally program_id.
 * Used to populate the batch datalist in mark-entry.
 *
 * GET params:
 *   dept_id     (int, optional)
 *   program_id  (int, optional)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
if (!rm_can_view()) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json');

$dept_id    = (int)($_GET['dept_id']    ?? 0);
$program_id = (int)($_GET['program_id'] ?? 0);

$where  = ["s.batch IS NOT NULL AND s.batch != ''"];
$params = [];

if ($dept_id > 0)    { $where[] = 's.dept_id = ?';    $params[] = $dept_id; }
if ($program_id > 0) { $where[] = 's.program_id = ?'; $params[] = $program_id; }

$sql  = 'SELECT DISTINCT s.batch FROM students s WHERE ' . implode(' AND ', $where)
      . ' ORDER BY s.batch ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$batches = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'batch');

echo json_encode(array_values($batches));
