<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-coe', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(APP_URL . '/office-of-coe/staff-index.php'); }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    try {
        $row = db()->prepare('SELECT photo FROM coe_staff WHERE id = ?');
        $row->execute([$id]);
        $st = $row->fetch();
        if ($st && $st['photo']) {
            $file = UPLOAD_DIR . '/office-of-coe/' . $st['photo'];
            if (is_file($file)) @unlink($file);
        }
        db()->prepare('DELETE FROM coe_staff WHERE id = ?')->execute([$id]);
        flash_set('success', 'Staff member deleted.');
    } catch (Throwable $e) {
        flash_set('error', 'Could not delete staff member.');
    }
}

redirect(APP_URL . '/office-of-coe/staff-index.php');
