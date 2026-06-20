<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/jobs/applications.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM job_applications WHERE id = ?');
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    flash_set('error', 'Application not found.');
    redirect(APP_URL . '/jobs/applications.php');
}

// Delete CV file from disk if it exists
if (!empty($app['cv_filename'])) {
    $path = UPLOAD_DIR . '/jobs/' . $app['cv_filename'];
    if (file_exists($path)) @unlink($path);
}

db()->prepare('DELETE FROM job_applications WHERE id = ?')->execute([$id]);

flash_set('success', 'Application from <strong>' . h($app['full_name']) . '</strong> deleted.');
redirect(APP_URL . '/jobs/applications.php');
