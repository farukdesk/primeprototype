<?php
/**
 * API – POST /api/auth/login.php
 * ================================
 * Authenticates an admin user and returns a bearer token.
 *
 * Request (JSON or form-encoded):
 * { "login": "username_or_email", "password": "...", "device_id": "...", "device_name": "..." }
 * অথবা ফ্ল্যাটার থেকে পাঠানো:
 * { "username": "username_or_email", "password": "...", "device_id": "...", "device_name": "..." }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use POST.');
}

// Accept JSON or form-encoded body
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// ফ্ল্যাটার অ্যাপ 'username' পাঠাতে পারে, আবার অন্যান্য সিস্টেম 'login' পাঠাতে পারে; দুটির জন্যই চেক করা হলো
$login       = trim($input['login'] ?? $input['username'] ?? '');
$password    = trim($input['password']    ?? '');
$device_id   = trim($input['device_id']   ?? '');
$device_name = trim($input['device_name'] ?? '');

if ($login === '' || $password === '') {
    api_error(400, 'login/username and password are required.');
}

$stmt = db()->prepare(
    'SELECT u.*, g.name AS group_name, g.is_super
     FROM users u
     JOIN user_groups g ON g.id = u.group_id
     WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
     LIMIT 1'
);
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

// ── পাসওয়ার্ড ভেরিফিকেশন (MD5 এবং password_hash দুটোর জন্যই সামঞ্জস্যপূর্ণ) ──
$is_password_correct = false;

if ($user) {
    // ১. প্রথমে স্ট্যান্ডার্ড password_verify চেক করবে
    if (password_verify($password, $user['password'])) {
        $is_password_correct = true;
    } 
    // ২. সেটি না মিললে ডাটাবেজে MD5 এনক্রিপশন আছে কিনা তা চেক করবে
    elseif (md5($password) === $user['password'] || sha1($password) === $user['password']) {
        $is_password_correct = true;
    }
    // ৩. যদি ডাটাবেজে একদম প্লেইন টেক্সট (Plain Text) পাসওয়ার্ড থাকে
    elseif ($password === $user['password']) {
        $is_password_correct = true;
    }
}

if (!$user || !$is_password_correct) {
    // Generic message to prevent username enumeration
    api_error(401, 'Invalid credentials. Please check your username/email and password.');
}

// ── Issue a new API token ─────────────────────────────────────────────────────
$raw_token  = bin2hex(random_bytes(32));               // 64-char hex token for the client
$stored_hash = hash('sha256', $raw_token);             // only the hash is stored
$expires    = date('Y-m-d H:i:s', strtotime('+30 days'));

// Remove existing tokens for this device to avoid duplicates
if ($device_id !== '') {
    db()->prepare(
        'DELETE FROM api_tokens WHERE user_id = ? AND device_id = ?'
    )->execute([$user['id'], $device_id]);
}

db()->prepare(
    'INSERT INTO api_tokens (user_id, token, device_id, device_name, expires_at)
     VALUES (?, ?, ?, ?, ?)'
)->execute([
    $user['id'],
    $stored_hash,
    $device_id   ?: null,
    $device_name ?: null,
    $expires,
]);

// Update last login
db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
   ->execute([$user['id']]);

// ── Build permissions list ────────────────────────────────────────────────────
$permissions = api_get_permissions((int)$user['id'], (int)$user['group_id'], (bool)$user['is_super']);

api_ok([
    'status'     => 'success', // ফ্ল্যাটার অ্যাপের সুবিধার্থে স্ট্যাটাস যোগ করা হলো
    'token'      => $raw_token,
    'expires_at' => $expires,
    'user'       => [
        'id'         => (int)$user['id'],
        'full_name'  => $user['full_name'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'group'      => $user['group_name'],
        'is_super'   => (bool)$user['is_super'],
        'avatar_url' => null,
    ],
    'permissions' => $permissions,
]);
