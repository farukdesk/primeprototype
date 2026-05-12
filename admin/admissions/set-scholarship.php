<?php
/**
 * Admissions – Set / Update Scholarship for an application.
 * Stores a single scholarship entry (label + fixed amount) on the application row.
 * POST params: id, scholarship_label, scholarship_amount
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admissions/index.php');
}

csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$label  = trim($_POST['scholarship_label']  ?? '');
$amount = (float)($_POST['scholarship_amount'] ?? 0);

$errors = [];

if ($id <= 0) $errors[] = 'Invalid application.';
if ($label === '') $errors[] = 'Scholarship label is required.';
if ($amount < 0.01) $errors[] = 'Scholarship amount must be greater than 0.';

if (empty($errors)) {
    // Verify the application exists
    $stmt = db()->prepare('SELECT id FROM admissions_applications WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        $errors[] = 'Application not found.';
    }
}

if (empty($errors)) {
    db()->prepare(
        'UPDATE admissions_applications
            SET scholarship_label  = ?,
                scholarship_amount = ?
          WHERE id = ?'
    )->execute([$label, round($amount, 2), $id]);

    log_change(
        'admissions', 'UPDATE', $id,
        'Scholarship',
        'scholarship_set',
        null,
        $label . ' – BDT ' . number_format($amount, 2),
        'Scholarship "' . $label . '" (BDT ' . number_format($amount, 2) . ') set for application #' . $id
    );

    flash_set('success', 'Scholarship <strong>' . h($label) . '</strong> (BDT ' . number_format($amount, 2) . ') saved.');
} else {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/admissions/view.php?id=' . $id);
