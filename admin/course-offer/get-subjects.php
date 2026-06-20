<?php
/**
 * AJAX – search subjects (course_curriculum) across ALL departments and programs.
 * Accepts: q (search string), limit (max results, default 30)
 * Returns: [{id, text, course_code, course_name, program_name, dept_name, credit}]
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json; charset=utf-8');

$q     = trim($_GET['q']     ?? '');
$limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));

$params = [];
$where  = '1=1';

if ($q !== '') {
    $where   .= ' AND (c.course_code LIKE ? OR c.course_name LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}

$st = db()->prepare(
    "SELECT c.id,
            c.course_code,
            c.course_name,
            c.credit,
            p.program_name,
            d.name AS dept_name
       FROM course_curriculum c
       JOIN dept_academic_programs p ON p.id = c.program_id
       JOIN dept_departments       d ON d.id = p.dept_id
      WHERE $where
      ORDER BY d.name ASC, p.program_name ASC, c.sort_order ASC, c.sl_no ASC
      LIMIT ?"
);
$params[] = $limit;
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Build a human-readable text label for TomSelect
foreach ($rows as &$row) {
    $code  = $row['course_code'] ? '[' . $row['course_code'] . '] ' : '';
    $row['text'] = $code . $row['course_name']
        . ' — ' . $row['program_name']
        . ' (' . $row['dept_name'] . ')';
}
unset($row);

echo json_encode(array_values($rows));
