<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id      = (int)($_POST['id']      ?? 0);
$dept_id = (int)($_POST['dept_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ?');
$stmt->execute([$id]);
$program = $stmt->fetch();

if (!$program) {
    flash_set('error', 'Program not found.');
    redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
}

db()->prepare('DELETE FROM dept_academic_programs WHERE id = ?')->execute([$id]);
flash_set('success', 'Academic program deleted.');
redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . ($dept_id ?: $program['dept_id']));
