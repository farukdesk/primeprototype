<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('admissions');
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$app = adm_get($id);

if (!adm_can_delete()) {
    flash_set('error', 'You do not have permission to delete applications.');
    redirect(APP_URL . '/admissions/view.php?id=' . $id);
}

// Delete photo file if present
if ($app['photo']) {
    $photo_path = UPLOAD_DIR . '/' . ADM_PHOTO_SUBDIR . '/' . $app['photo'];
    if (file_exists($photo_path)) {
        unlink($photo_path);
    }
}

db()->prepare('DELETE FROM admissions_applications WHERE id = ?')->execute([$id]);

log_change('admissions', 'DELETE', $id, $app['app_number']);
flash_set('success', 'Application ' . $app['app_number'] . ' has been deleted.');
redirect(APP_URL . '/admissions/index.php');
