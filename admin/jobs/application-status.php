<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('jobs', 'can_edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/jobs/applications.php');
}
csrf_check();

$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!in_array($status, ['pending','reviewing','shortlisted','rejected'], true)) {
    flash_set('error', 'Invalid status value.');
    redirect(APP_URL . '/jobs/application-view.php?id=' . $id);
}

$stmt = db()->prepare('SELECT id FROM job_applications WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    flash_set('error', 'Application not found.');
    redirect(APP_URL . '/jobs/applications.php');
}

db()->prepare(
    'UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?'
)->execute([$status, $id]);

flash_set('success', 'Application status updated to <strong>' . h(ucfirst($status)) . '</strong>.');
redirect(APP_URL . '/jobs/application-view.php?id=' . $id);
