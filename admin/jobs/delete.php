<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/jobs/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
$stmt->execute([$id]);
$job = $stmt->fetch();

if (!$job) {
    flash_set('error', 'Job posting not found.');
    redirect(APP_URL . '/jobs/index.php');
}

// Delete all CV files from disk before removing DB record
$atts = db()->prepare('SELECT cv_filename FROM job_applications WHERE job_id = ? AND cv_filename IS NOT NULL');
$atts->execute([$id]);
foreach ($atts->fetchAll() as $row) {
    $path = UPLOAD_DIR . '/jobs/' . $row['cv_filename'];
    if (file_exists($path)) @unlink($path);
}

// Delete job (CASCADE removes applications)
db()->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);

flash_set('success', 'Job posting <strong>' . h($job['title']) . '</strong> deleted.');
redirect(APP_URL . '/jobs/index.php');
