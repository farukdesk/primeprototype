<?php
/**
 * Add a new scholarship entry to a single semester fee row.
 * A semester can hold multiple named scholarships; totals are recalculated
 * and written back into sfp_semester_fees after every change.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

$package_id   = (int)($_POST['package_id']   ?? 0);
$sf_id        = (int)($_POST['sf_id']        ?? 0);
$label        = trim($_POST['sc_label']      ?? '');
$discount_pct = (float)($_POST['discount_pct'] ?? 0);
$sc_note      = trim($_POST['sc_note']       ?? '');

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($sf_id <= 0)      $errors[] = 'Invalid semester row.';
if ($label === '')    $errors[] = 'Scholarship label is required.';
if ($discount_pct < 0.01 || $discount_pct > 100) $errors[] = 'Discount must be between 0.01 and 100.';

if (empty($errors)) {
    // Verify the semester row belongs to this package
    $sf_stmt = db()->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if (!$sf) {
        $errors[] = 'Semester fee record not found.';
    }
}

if (empty($errors)) {
    // Apply discount to the *remaining* payable balance, not the original tuition fee.
    // sfp_recalculate_semester() will also recompute cascaded amounts for all rows.
    $tuition_payable = (float)$sf['tuition_payable'];
    $amount          = round($tuition_payable * $discount_pct / 100, 2);
    $user        = auth_user();

    db()->prepare(
        'INSERT INTO sfp_semester_scholarships
           (sf_id, label, discount_pct, amount, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $sf_id,
        $label,
        round($discount_pct, 2),
        $amount,
        $sc_note ?: null,
        $user['id'],
    ]);

    // Recalculate totals in the semester row
    sfp_recalculate_semester($sf_id, $user['id']);

    log_change(
        'student-fee-package', 'UPDATE', $package_id,
        'Semester #' . $sf['semester_number'],
        'scholarship_added',
        null,
        $label . ' (' . number_format($discount_pct, 2) . '%)',
        'Scholarship "' . $label . '" (' . number_format($discount_pct, 2) . '%) added to semester #' . $sf['semester_number']
    );

    flash_set('success',
        'Scholarship <strong>' . h($label) . '</strong> ('
        . number_format($discount_pct, 2) . '%) added to Semester #'
        . $sf['semester_number'] . '.'
    );
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/student-fee-package/view.php?id=' . $package_id);
