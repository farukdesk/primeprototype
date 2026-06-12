<?php
/**
 * Student Portal API – POST /api/student/auth/login.php
 * ========================================================
 * Authenticates a student portal user and returns a bearer token.
 *
 * Request (JSON or form-encoded):
 *   { "login": "student_username", "password": "...", "device_id": "...", "device_name": "..." }
 *
 * Success response:
 *   { "ok": true, "token": "...", "expires_at": "...", "user": {...}, "student": {...} }
 */

require_once __DIR__ . '/../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$input       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$login       = trim($input['login']       ?? $input['username'] ?? '');
$password    = trim($input['password']    ?? '');
$device_id   = trim($input['device_id']   ?? '');
$device_name = trim($input['device_name'] ?? '');

if ($login === '' || $password === '') {
    sp_api_error(400, 'Username and password are required.');
}

// Look up the user account
$stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.username, u.email, u.password, u.is_active
     FROM users u
     WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
     LIMIT 1'
);
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

// Verify password (bcrypt, MD5, SHA1 and plain-text for legacy compatibility)
$password_ok = false;
if ($user) {
    if (password_verify($password, $user['password'])) {
        $password_ok = true;
    } elseif (md5($password) === $user['password'] || sha1($password) === $user['password']) {
        $password_ok = true;
    } elseif ($password === $user['password']) {
        $password_ok = true;
    }
}

if (!$user || !$password_ok) {
    sp_api_error(401, 'Invalid username or password. Please check your credentials.');
}

// Confirm user is a student portal account
$stmt2 = db()->prepare(
    'SELECT s.id AS student_db_id,
            s.student_id, s.full_name AS student_name,
            s.phone, s.email AS student_email, s.photo,
            s.status, s.dept_id, s.program_id, s.batch_id,
            d.name AS dept_name, d.code AS dept_code,
            p.program_name, p.program_type,
            b.name AS batch_name
     FROM students s
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     LEFT JOIN student_batches b ON b.id = s.batch_id
     WHERE s.portal_user_id = ?
     LIMIT 1'
);
$stmt2->execute([(int)$user['id']]);
$student = $stmt2->fetch();

if (!$student) {
    sp_api_error(403, 'Your account is not linked to a student profile. Please contact the administrator.');
}

// Issue token (30-day expiry)
$raw_token   = bin2hex(random_bytes(32));
$stored_hash = hash('sha256', $raw_token);
$expires     = date('Y-m-d H:i:s', strtotime('+30 days'));

// Remove existing token for this device to avoid duplicates
if ($device_id !== '') {
    db()->prepare('DELETE FROM api_tokens WHERE user_id = ? AND device_id = ?')
       ->execute([$user['id'], $device_id]);
}

db()->prepare(
    'INSERT INTO api_tokens (user_id, token, device_id, device_name, expires_at)
     VALUES (?, ?, ?, ?, ?)'
)->execute([
    $user['id'],
    $stored_hash,
    $device_id ?: null,
    $device_name ?: null,
    $expires,
]);

db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
   ->execute([$user['id']]);

$photo_url = null;
if (!empty($student['photo'])) {
    $photo_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/students/' . $student['photo'];
}

sp_api_ok([
    'token'      => $raw_token,
    'expires_at' => $expires,
    'user' => [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'email'     => $user['email'],
    ],
    'student' => [
        'id'           => (int)$student['student_db_id'],
        'student_id'   => $student['student_id'],
        'full_name'    => $student['student_name'],
        'photo_url'    => $photo_url,
        'phone'        => $student['phone'],
        'email'        => $student['student_email'],
        'status'       => $student['status'],
        'dept_name'    => $student['dept_name'],
        'dept_code'    => $student['dept_code'],
        'program_name' => $student['program_name'],
        'program_type' => $student['program_type'],
        'batch_name'   => $student['batch_name'],
    ],
]);
