<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    flash_set('error', 'Invalid department ID.');
    redirect(APP_URL . '/departments/index.php');
}

$stmt = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$stmt->execute([$id]);
$dept = $stmt->fetch();

if (!$dept) {
    flash_set('error', 'Department not found.');
    redirect(APP_URL . '/departments/index.php');
}

// Delete associated uploaded files for overview head photo
$ov = db()->prepare('SELECT head_photo FROM dept_overview WHERE dept_id = ?');
$ov->execute([$id]);
$overview = $ov->fetch();
if ($overview && $overview['head_photo']) {
    $path = UPLOAD_DIR . '/departments/' . $overview['head_photo'];
    if (file_exists($path)) @unlink($path);
}

// Delete uploaded files for faculty, alumni, clubs, facilities, notices, routines, prime-pride
$tables_with_files = [
    ['dept_faculty',           'photo'],
    ['dept_alumni',            'photo'],
    ['dept_clubs',             'logo'],
    ['dept_facilities',        'image'],
    ['dept_prime_pride',       'image'],
    ['dept_notices',           'attachment'],
    ['dept_routines',          'file_path'],
];
foreach ($tables_with_files as [$table, $col]) {
    $rows = db()->prepare("SELECT $col FROM $table WHERE dept_id = ?");
    $rows->execute([$id]);
    foreach ($rows->fetchAll() as $row) {
        if (!empty($row[$col])) {
            $path = UPLOAD_DIR . '/departments/' . $row[$col];
            if (file_exists($path)) @unlink($path);
        }
    }
}

db()->prepare('DELETE FROM dept_departments WHERE id = ?')->execute([$id]);

flash_set('success', "Department <strong>" . h($dept['name']) . "</strong> and all related data deleted.");
redirect(APP_URL . '/departments/index.php');
