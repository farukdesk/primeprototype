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
$redirect_to = trim($_POST['redirect_to'] ?? '');

if ($id > 0) {
    db()->prepare(
        'UPDATE admissions_applications
            SET scholarship_label              = NULL,
                scholarship_amount             = 0.00,
                scholarship_discount_type      = NULL,
                scholarship_discount_pct       = 0.0000,
                scholarship_scope              = "first_semester",
                scholarship_applies_to_fixed   = 0,
                scholarship_applies_to_english = 0
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

$target = $redirect_to === 'statement' ? 'statement.php' : 'view.php';
redirect(APP_URL . '/admissions/' . $target . '?id=' . $id);
