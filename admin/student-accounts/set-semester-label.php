<?php
/**
 * Set a human-readable label on a single semester fee row (e.g. "Summer 2026").
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$sf_id          = (int)($_POST['sf_id']         ?? 0);
$package_id     = (int)($_POST['package_id']    ?? 0);
$semester_label = trim($_POST['semester_label'] ?? '');

if ($sf_id > 0 && $package_id > 0) {
    $sf_stmt = db()->prepare('SELECT id, semester_number FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if ($sf) {
        $user = auth_user();
        db()->prepare(
            'UPDATE sfp_semester_fees SET semester_label = ?, updated_by = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$semester_label ?: null, $user['id'], $sf_id]);

        flash_set('success', 'Label updated for Semester #' . $sf['semester_number'] . '.');
    } else {
        flash_set('error', 'Semester fee record not found.');
    }
}

redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
