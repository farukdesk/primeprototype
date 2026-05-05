<?php
/**
 * Update the tuition fee for a single semester row.
 * After 4 months (i.e. from semester 2 onwards) tuition may change.
 * Scholarship amounts stored in sfp_semester_scholarships are recalculated
 * proportionally and totals are written back to sfp_semester_fees.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

$package_id  = (int)($_POST['package_id']  ?? 0);
$sf_id       = (int)($_POST['sf_id']       ?? 0);
$tuition_fee = (float)($_POST['tuition_fee'] ?? 0);

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($sf_id <= 0)      $errors[] = 'Invalid semester row.';
if ($tuition_fee < 0) $errors[] = 'Tuition fee cannot be negative.';

if (empty($errors)) {
    $sf_stmt = db()->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if (!$sf) {
        $errors[] = 'Semester fee record not found.';
    }
}

if (empty($errors)) {
    $user        = auth_user();
    $old_tuition = (float)$sf['tuition_fee'];

    // Update tuition_fee
    db()->prepare(
        'UPDATE sfp_semester_fees
         SET tuition_fee = ?, updated_by = ?, updated_at = NOW()
         WHERE id = ?'
    )->execute([round($tuition_fee, 2), $user['id'], $sf_id]);

    // Recalculate scholarship amounts based on new tuition
    // Update each individual scholarship amount proportionally
    $scs = db()->prepare('SELECT id, discount_pct FROM sfp_semester_scholarships WHERE sf_id = ?');
    $scs->execute([$sf_id]);
    $update_sc = db()->prepare('UPDATE sfp_semester_scholarships SET amount = ? WHERE id = ?');
    foreach ($scs->fetchAll() as $sc) {
        $new_amount = round($tuition_fee * (float)$sc['discount_pct'] / 100, 2);
        $update_sc->execute([$new_amount, $sc['id']]);
    }

    // Recalculate aggregated totals in sfp_semester_fees
    sfp_recalculate_semester($sf_id, $user['id']);

    log_change(
        'student-fee-package', 'UPDATE', $package_id,
        'Semester #' . $sf['semester_number'],
        'tuition_fee',
        $old_tuition,
        $tuition_fee,
        'Tuition fee updated for semester #' . $sf['semester_number']
    );

    flash_set('success',
        'Tuition fee for Semester #' . $sf['semester_number']
        . ' updated to <strong>' . number_format($tuition_fee, 2) . ' BDT</strong>.'
    );
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/student-fee-package/view.php?id=' . $package_id);
