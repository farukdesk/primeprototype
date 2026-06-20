<?php
/**
 * Delete a single scholarship entry from a semester fee row.
 * Totals in sfp_semester_fees are recalculated after deletion.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$scholarship_id = (int)($_POST['scholarship_id'] ?? 0);
$package_id     = (int)($_POST['package_id']     ?? 0);

if ($scholarship_id > 0 && $package_id > 0) {
    // Verify the scholarship belongs to a semester in this package
    $sc_stmt = db()->prepare(
        'SELECT ss.*, sf.semester_number, sf.package_id
         FROM sfp_semester_scholarships ss
         JOIN sfp_semester_fees sf ON sf.id = ss.sf_id
         WHERE ss.id = ? AND sf.package_id = ?'
    );
    $sc_stmt->execute([$scholarship_id, $package_id]);
    $sc = $sc_stmt->fetch();

    if ($sc) {
        $user  = auth_user();
        $sf_id = (int)$sc['sf_id'];

        db()->prepare('DELETE FROM sfp_semester_scholarships WHERE id = ?')
            ->execute([$scholarship_id]);

        // Recalculate aggregated totals
        sfp_recalculate_semester($sf_id, $user['id']);

        log_change(
            'student-accounts', 'UPDATE', $package_id,
            'Semester #' . $sc['semester_number'],
            'scholarship_removed',
            $sc['label'] . ' (' . number_format((float)$sc['discount_pct'], 2) . '%)',
            null,
            'Scholarship "' . $sc['label'] . '" removed from semester #' . $sc['semester_number']
        );

        flash_set('success',
            'Scholarship <strong>' . h($sc['label']) . '</strong> removed from Semester #'
            . $sc['semester_number'] . '.'
        );
    } else {
        flash_set('error', 'Scholarship record not found or does not belong to this package.');
    }
} else {
    flash_set('error', 'Invalid request.');
}

redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
