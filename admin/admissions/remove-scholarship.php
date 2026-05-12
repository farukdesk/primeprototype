<?php
/**
 * Admissions – Remove / Clear Scholarship from an application.
 * POST params: id
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admissions/index.php');
}

csrf_check();

$id = (int)($_POST['id'] ?? 0);

if ($id > 0) {
    db()->prepare(
        'UPDATE admissions_applications
            SET scholarship_label  = NULL,
                scholarship_amount = 0.00
          WHERE id = ?'
    )->execute([$id]);

    log_change(
        'admissions', 'UPDATE', $id,
        'Scholarship',
        'scholarship_removed',
        null,
        null,
        'Scholarship removed from application #' . $id
    );

    flash_set('success', 'Scholarship removed.');
}

redirect(APP_URL . '/admissions/view.php?id=' . $id);
