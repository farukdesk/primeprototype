<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_delete');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$id  = (int)($_POST['id'] ?? 0);
$pkg = sfp_get_package($id);

if (!$pkg) {
    flash_set('error', 'Student account not found.');
    redirect(APP_URL . '/student-accounts/index.php');
}

$label = $pkg['student_name'] . ' (' . $pkg['student_sid'] . ') – ' . $pkg['program_name'];

// Guard: a student account that already has recorded payments / vouchers must
// not be deleted, so the financial record and the money received are preserved.
if (sfp_package_payment_count($id) > 0) {
    flash_set('error', 'This student account has recorded payments or vouchers and cannot be deleted. Reverse or delete the related vouchers first.');
    redirect(APP_URL . '/student-accounts/index.php');
}

// Cascade delete: sfp_semester_fees rows are removed via ON DELETE CASCADE
db()->prepare('DELETE FROM sfp_packages WHERE id = ?')->execute([$id]);

log_change('student-accounts', 'DELETE', $id, $label, null, null, null, 'Package deleted.');

flash_set('success', 'Student account for <strong>' . h($pkg['student_name']) . '</strong> deleted.');
redirect(APP_URL . '/student-accounts/index.php');
