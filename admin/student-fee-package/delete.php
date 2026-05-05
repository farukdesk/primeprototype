<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

$id  = (int)($_POST['id'] ?? 0);
$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Fee package not found.');
    redirect(APP_URL . '/student-fee-package/index.php');
}

$label = $pkg['student_name'] . ' (' . $pkg['student_sid'] . ') – ' . $pkg['program_name'];

// Cascade delete: sfp_semester_fees rows are removed via ON DELETE CASCADE
db()->prepare('DELETE FROM sfp_packages WHERE id = ?')->execute([$id]);

log_change('student-fee-package', 'DELETE', $id, $label, null, null, null, 'Package deleted.');

flash_set('success', 'Fee package for <strong>' . h($pkg['student_name']) . '</strong> deleted.');
redirect(APP_URL . '/student-fee-package/index.php');
