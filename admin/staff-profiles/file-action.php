<?php
/**
 * Upload / delete a titled file attachment (Issues/Complaints or Evaluation
 * Record) on an employee profile. Admin-only (staff-profile can_edit).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

if (!sp_is_admin()) {
    require_access('staff-profile', 'can_edit');
}
csrf_check();

$user_id  = (int)($_POST['user_id'] ?? 0);
$category = (string)($_POST['category'] ?? '');
$action   = (string)($_POST['action'] ?? '');
$back     = APP_URL . '/staff-profiles/view.php?id=' . $user_id . '#sp-files-' . h($category);

if ($user_id < 1 || !array_key_exists($category, SP_FILE_CATEGORIES)) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/staff-profiles/index.php');
}

// The target user must have an employee profile.
$chk = db()->prepare('SELECT COUNT(*) FROM staff_profiles WHERE user_id = ? AND department_type IS NOT NULL');
$chk->execute([$user_id]);
if ((int)$chk->fetchColumn() === 0) {
    flash_set('error', 'Employee profile not found.');
    redirect(APP_URL . '/staff-profiles/index.php');
}

sp_ensure_files_table();

if ($action === 'upload') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        flash_set('error', 'Please enter a title for the file.');
        redirect($back);
    }
    $stored = sp_upload_profile_file($_FILES['file'] ?? []);
    if (is_string($stored)) {
        flash_set('error', $stored);
        redirect($back);
    }
    db()->prepare(
        'INSERT INTO staff_profile_files
            (user_id, category, title, file_name, original_name, mime_type, file_size, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $user_id, $category, mb_substr($title, 0, 255),
        $stored['file'], mb_substr($stored['original'], 0, 255),
        $stored['mime'], $stored['size'], (int)auth_user()['id'],
    ]);
    flash_set('success', SP_FILE_CATEGORIES[$category] . ' file uploaded.');
    redirect($back);
}

if ($action === 'delete') {
    $id = (int)($_POST['file_id'] ?? 0);
    $st = db()->prepare('SELECT * FROM staff_profile_files WHERE id = ? AND user_id = ? AND category = ?');
    $st->execute([$id, $user_id, $category]);
    $row = $st->fetch();
    if (!$row) {
        flash_set('error', 'File not found.');
        redirect($back);
    }
    db()->prepare('DELETE FROM staff_profile_files WHERE id = ?')->execute([$id]);
    $abs = UPLOAD_DIR . '/' . SP_FILES_SUBDIR . '/' . basename($row['file_name']);
    if (is_file($abs)) @unlink($abs);
    flash_set('success', 'File deleted.');
    redirect($back);
}

flash_set('error', 'Unknown action.');
redirect($back);
