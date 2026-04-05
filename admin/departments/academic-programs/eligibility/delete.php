<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_access('dept-academic-programs', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/departments/index.php');
}
csrf_check();

$id         = (int)($_POST['id']         ?? 0);
$program_id = (int)($_POST['program_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM program_eligibility_criteria WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    flash_set('error', 'Criterion not found.');
    redirect(APP_URL . '/departments/academic-programs/eligibility/index.php?program_id=' . $program_id);
}
$prog = db()->prepare('SELECT dept_id FROM dept_academic_programs WHERE id = ?');
$prog->execute([$item['program_id']]);
$prog_row = $prog->fetch();
$dept_id = $prog_row ? (int)$prog_row['dept_id'] : 0;
require_access_dept($dept_id);

db()->prepare('DELETE FROM program_eligibility_criteria WHERE id = ?')->execute([$id]);
flash_set('success', 'Eligibility criterion deleted.');
redirect(APP_URL . '/departments/academic-programs/eligibility/index.php?program_id=' . ($program_id ?: $item['program_id']));
