<?php
/**
 * Apply (or update) a scholarship discount to a single semester fee row.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

$package_id   = (int)($_POST['package_id']  ?? 0);
$sf_id        = (int)($_POST['sf_id']        ?? 0);
$discount_pct = (float)($_POST['discount_pct'] ?? 0);
$sc_note      = trim($_POST['sc_note']       ?? '');

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($sf_id <= 0)      $errors[] = 'Invalid semester row.';
if ($discount_pct < 0 || $discount_pct > 100) $errors[] = 'Discount must be between 0 and 100.';

if (empty($errors)) {
    // Fetch the semester row and verify it belongs to this package
    $sf_stmt = db()->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if (!$sf) {
        $errors[] = 'Semester fee record not found.';
    }
}

if (empty($errors)) {
    $tuition_fee      = (float)$sf['tuition_fee'];
    $sc_amount        = round($tuition_fee * $discount_pct / 100, 2);
    $tuition_payable  = round($tuition_fee - $sc_amount, 2);

    $user = auth_user();

    db()->prepare(
        'UPDATE sfp_semester_fees
         SET scholarship_discount_pct = ?,
             scholarship_amount       = ?,
             tuition_payable          = ?,
             note                     = ?,
             updated_by               = ?,
             updated_at               = NOW()
         WHERE id = ?'
    )->execute([
        $discount_pct,
        $sc_amount,
        $tuition_payable,
        $sc_note ?: null,
        $user['id'],
        $sf_id,
    ]);

    $pkg_stmt = db()->prepare('SELECT student_id FROM sfp_packages WHERE id = ?');
    $pkg_stmt->execute([$package_id]);
    $pkg_row = $pkg_stmt->fetch();

    log_change(
        'student-fee-package', 'UPDATE', $package_id,
        'Semester #' . $sf['semester_number'],
        'scholarship_discount_pct',
        $sf['scholarship_discount_pct'],
        $discount_pct,
        'Scholarship applied: ' . $discount_pct . '% off'
    );

    flash_set('success', 'Scholarship of <strong>' . number_format($discount_pct, 2) . '%</strong> applied to Semester #' . $sf['semester_number'] . '.');
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/student-fee-package/view.php?id=' . $package_id);
