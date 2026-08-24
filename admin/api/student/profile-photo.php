<?php
/**
 * Student Portal API – POST /api/student/profile-photo.php
 * ==========================================================
 * Lets the signed-in student upload / replace their profile picture from the
 * mobile app. Stores the file in admin/uploads/students (the same directory
 * the website reads from, see auth/me.php) and updates students.photo.
 *
 * Request: multipart/form-data with a single "photo" file field
 *          (JPG / PNG / WEBP, max 5 MB).
 *
 * Success response:
 *   { "ok": true, "message": "Profile photo updated.", "photo_url": "https://…" }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];

if (empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'] ?? '')) {
    sp_api_error(400, 'A "photo" file field is required.');
}

$file = $_FILES['photo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    sp_api_error(400, 'The upload failed. Please try again.');
}
if ((int)$file['size'] > 5 * 1024 * 1024) {
    sp_api_error(400, 'The photo must be 5 MB or smaller.');
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo   = new finfo(FILEINFO_MIME_TYPE);
$mime    = (string)$finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) {
    sp_api_error(400, 'Only JPG, PNG or WEBP images are allowed.');
}

$dir = UPLOAD_DIR . '/students';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    sp_api_error(500, 'Could not prepare the upload directory.');
}

$name = 'student-' . (int)$student['student_db_id'] . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    sp_api_error(500, 'Could not save the photo. Please try again.');
}

try {
    db()->prepare('UPDATE students SET photo = ? WHERE id = ?')
       ->execute([$name, (int)$student['student_db_id']]);
} catch (Throwable $e) {
    @unlink($dir . '/' . $name);
    sp_api_error(500, 'Could not update the profile. Please try again.');
}

// Best-effort: remove the previous photo file so uploads do not pile up.
$old = (string)($student['photo'] ?? '');
if ($old !== '' && $old !== $name && strpos($old, '/') === false && is_file($dir . '/' . $old)) {
    @unlink($dir . '/' . $old);
}

sp_api_ok([
    'message'   => 'Profile photo updated.',
    'photo_url' => (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/students/' . $name,
]);
