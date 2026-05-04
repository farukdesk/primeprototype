<?php
/**
 * AJAX – search faculty across ALL departments.
 * Accepts: q (search string), limit (max results, default 40)
 * Returns: [{id, text, name, designation, dept_name}]
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json; charset=utf-8');

$q     = trim($_GET['q']     ?? '');
$limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));

$params = [];
$where  = 'f.is_active = 1';

if ($q !== '') {
    $where   .= ' AND (f.name LIKE ? OR f.designation LIKE ? OR d.name LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$st = db()->prepare(
    "SELECT f.id,
            f.name,
            f.designation,
            d.name AS dept_name
       FROM dept_faculty      f
       JOIN dept_departments  d ON d.id = f.dept_id
      WHERE $where
      ORDER BY d.name ASC, f.sort_order ASC, f.name ASC
      LIMIT {$limit}"
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $desig       = $row['designation'] ? ', ' . $row['designation'] : '';
    $row['text'] = $row['name'] . $desig . ' (' . $row['dept_name'] . ')';
}
unset($row);

echo json_encode(array_values($rows));
