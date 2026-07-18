<?php
/**
 * Export the (optionally filtered) Users list as a CSV file.
 * Honours the same `search`, `group_ids[]` and `group_mode` filters as
 * admin/users/index.php so the export always matches what's on screen.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('users');

$search = trim($_GET['search'] ?? '');

$selected_group_ids = array_map('intval', (array)($_GET['group_ids'] ?? []));
if (empty($selected_group_ids) && !empty($_GET['group_id'])) {
    $selected_group_ids = [(int)$_GET['group_id']];
}
$selected_group_ids = array_values(array_unique(array_filter($selected_group_ids, static fn($v) => $v > 0)));

$group_mode = ($_GET['group_mode'] ?? 'in') === 'except' ? 'except' : 'in';

$where  = [];
$params = [];

if (!empty($selected_group_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_group_ids), '?'));
    $where[]  = $group_mode === 'except' ? "u.group_id NOT IN ($placeholders)" : "u.group_id IN ($placeholders)";
    $params   = array_merge($params, $selected_group_ids);
}
if ($search !== '') {
    $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR g.name LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$sql = 'SELECT u.full_name, u.username, u.email, u.phone, g.name AS group_name,
               d.name AS dept_name, u.is_active, u.created_at
        FROM users u
        JOIN user_groups g ON g.id = u.group_id
        LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
        LEFT JOIN dept_departments d ON d.id = fp.dept_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY u.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$filename = 'users-export-' . date('Ymd-His') . '.csv';

// Clear any buffered output so the CSV stream isn't corrupted.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens the file with correct encoding.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Name', 'Username', 'Email', 'Phone', 'Group', 'Department', 'Status', 'Created At']);

foreach ($users as $u) {
    fputcsv($out, [
        $u['full_name'],
        $u['username'],
        $u['email'],
        $u['phone'] ?? '',
        $u['group_name'],
        $u['dept_name'] ?? '',
        $u['is_active'] ? 'Active' : 'Inactive',
        $u['created_at'],
    ]);
}

fclose($out);
exit;
