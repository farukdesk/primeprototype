<?php
require_once __DIR__ . '/helpers.php';
auth_check();
require_access('homepage', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/homepage/index.php');
}
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $stmt = db()->prepare('SELECT photo FROM homepage_testimonials WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        hp_delete_photo($row['photo'] ?? '');
        db()->prepare('DELETE FROM homepage_testimonials WHERE id = ?')->execute([$id]);
        flash_set('success', 'Testimonial deleted.');
    } else {
        flash_set('error', 'Testimonial not found.');
    }
} else {
    flash_set('error', 'Invalid ID.');
}

redirect(APP_URL . '/homepage/index.php');
