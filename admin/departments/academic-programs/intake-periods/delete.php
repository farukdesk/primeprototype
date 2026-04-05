<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id         = (int)($_POST['id']         ?? 0);
$program_id = (int)($_POST['program_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM program_intake_periods WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Intake period not found.');
    redirect(APP_URL . '/departments/academic-programs/intake-periods/index.php?program_id=' . $program_id);
}

db()->prepare('DELETE FROM program_intake_periods WHERE id = ?')->execute([$id]);
flash_set('success', 'Intake period deleted.');
redirect(APP_URL . '/departments/academic-programs/intake-periods/index.php?program_id=' . ($program_id ?: $item['program_id']));
