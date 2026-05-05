<?php
/**
 * Remove scholarship discount from a single semester fee row.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-fee-package', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-fee-package/index.php');
}

csrf_check();

$sf_id      = (int)($_POST['sf_id']      ?? 0);
$package_id = (int)($_POST['package_id'] ?? 0);

if ($sf_id > 0 && $package_id > 0) {
    $sf_stmt = db()->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if ($sf) {
        $user = auth_user();
        db()->prepare(
            'UPDATE sfp_semester_fees
             SET scholarship_award_id     = NULL,
                 scholarship_discount_pct = 0,
                 scholarship_amount       = 0,
                 tuition_payable          = tuition_fee,
                 note                     = NULL,
                 updated_by               = ?,
                 updated_at               = NOW()
             WHERE id = ?'
        )->execute([$user['id'], $sf_id]);

        log_change(
            'student-fee-package', 'UPDATE', $package_id,
            'Semester #' . $sf['semester_number'],
            'scholarship_discount_pct',
            $sf['scholarship_discount_pct'],
            0,
            'Scholarship removed from semester #' . $sf['semester_number']
        );

        flash_set('success', 'Scholarship removed from Semester #' . $sf['semester_number'] . '.');
    } else {
        flash_set('error', 'Semester fee record not found.');
    }
}

redirect(APP_URL . '/student-fee-package/view.php?id=' . $package_id);
