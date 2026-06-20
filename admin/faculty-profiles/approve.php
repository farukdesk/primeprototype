<?php
/**
 * Approve or Reject a Faculty Registration.
 * POST only. Redirects back to pending.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/fp-helpers.php';

if (!fp_can_manage_pending()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/faculty-profiles/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/faculty-profiles/pending.php');
}

csrf_check();

$id     = (int)($_POST['id']     ?? 0);
$action = trim($_POST['action']  ?? '');
$notes  = trim($_POST['notes']   ?? '');

if (!$id || !in_array($action, ['approve', 'reject'], true)) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/faculty-profiles/pending.php');
}

// Load the registration
$stmt = db()->prepare(
    'SELECT fr.*, d.name AS dept_name
     FROM faculty_registrations fr
     LEFT JOIN dept_departments d ON d.id = fr.dept_id
     WHERE fr.id = ? AND fr.status = \'pending\' LIMIT 1'
);
$stmt->execute([$id]);
$reg = $stmt->fetch();

if (!$reg) {
    flash_set('error', 'Registration not found or already processed.');
    redirect(APP_URL . '/faculty-profiles/pending.php');
}

$reviewer_id = auth_user()['id'];

if ($action === 'reject') {
    // ── Reject ────────────────────────────────────────────────────────────────
    db()->prepare(
        "UPDATE faculty_registrations SET status='rejected', notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?"
    )->execute([$notes ?: null, $reviewer_id, $id]);

    // Send rejection email
    send_template_email('faculty_rejected', $reg['email'], $reg['full_name'], [
        'full_name' => $reg['full_name'],
        'notes'     => $notes ?: '',
    ]);

    log_change('faculty-pending', 'UPDATE', $id, $reg['full_name'] . ' (' . $reg['email'] . ')',
        'status', 'pending', 'rejected',
        'Faculty registration rejected' . ($notes ? ': ' . $notes : ''));

    flash_set('success', 'Registration for ' . h($reg['full_name']) . ' has been rejected.');
    redirect(APP_URL . '/faculty-profiles/pending.php');
}

// ── Approve ────────────────────────────────────────────────────────────────────

// 1. Generate username from email (local part, sanitized, ensure uniqueness)
$local    = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $reg['email'])[0]));
if (strlen($local) < 3) $local = 'faculty' . $local;
$username = $local;
$suffix   = 1;
while (true) {
    $check = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $check->execute([$username]);
    if (!$check->fetchColumn()) break;
    $username = $local . $suffix;
    $suffix++;
}

// 2. Generate 8-character random password (mix of letters + digits)
$chars    = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
$password = '';
for ($i = 0; $i < 8; $i++) {
    $password .= $chars[random_int(0, strlen($chars) - 1)];
}
$password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

// 3. Find the Faculty group id
$faculty_group = db()->prepare("SELECT id FROM user_groups WHERE name = 'Faculty' LIMIT 1");
$faculty_group->execute();
$faculty_group_id = $faculty_group->fetchColumn();
if (!$faculty_group_id) {
    flash_set('error', 'Faculty user group not found. Please ensure the Faculty group exists.');
    redirect(APP_URL . '/faculty-profiles/pending.php');
}

// 4. Create the user (active)
db()->prepare(
    'INSERT INTO users (group_id, username, email, password, full_name, phone, is_active)
     VALUES (?,?,?,?,?,?,1)'
)->execute([
    $faculty_group_id,
    $username,
    $reg['email'],
    $password_hash,
    $reg['full_name'],
    $reg['phone'],
]);
$new_user_id = (int)db()->lastInsertId();

// 5. Create faculty_profiles record with dept_id
db()->prepare(
    'INSERT INTO faculty_profiles (user_id, dept_id) VALUES (?,?)
     ON DUPLICATE KEY UPDATE dept_id = VALUES(dept_id)'
)->execute([$new_user_id, $reg['dept_id']]);

// 6. Auto-map to dept_faculty if dept_id provided
if ($reg['dept_id']) {
    $existing = db()->prepare('SELECT id FROM dept_faculty WHERE user_id = ? AND dept_id = ? LIMIT 1');
    $existing->execute([$new_user_id, $reg['dept_id']]);
    if (!$existing->fetch()) {
        db()->prepare(
            'INSERT INTO dept_faculty (dept_id, user_id, name, email, is_head, sort_order, is_active)
             VALUES (?,?,?,?,0,99,1)'
        )->execute([
            $reg['dept_id'],
            $new_user_id,
            $reg['full_name'],
            $reg['email'],
        ]);
    }
}

// 7. Move ID card file to faculty_files as is_id_card=1 (if uploaded)
if ($reg['id_card_stored']) {
    $src = UPLOAD_DIR . '/faculty-registrations/' . basename($reg['id_card_stored']);
    $dest_dir = UPLOAD_DIR . '/faculty-profiles/files';
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $dest = $dest_dir . '/' . basename($reg['id_card_stored']);
    if (file_exists($src)) {
        rename($src, $dest);
    }
    db()->prepare(
        'INSERT INTO faculty_files
           (user_id, file_name, description, stored_name, original_name, mime_type, file_size, is_id_card, uploaded_by)
         VALUES (?,?,?,?,?,?,?,1,?)'
    )->execute([
        $new_user_id,
        'ID Card / Joining Letter',
        'Uploaded during registration',
        basename($reg['id_card_stored']),
        $reg['id_card_original'] ?? $reg['id_card_stored'],
        $reg['id_card_mime'],
        $reg['id_card_size'],
        $reviewer_id,
    ]);
}

// 8. Mark registration as approved
db()->prepare(
    "UPDATE faculty_registrations SET status='approved', notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?"
)->execute([$notes ?: null, $reviewer_id, $id]);

// 9. Send approval email with credentials
$login_url = APP_URL . '/login.php';
send_template_email('faculty_approved', $reg['email'], $reg['full_name'], [
    'full_name' => $reg['full_name'],
    'username'  => $username,
    'password'  => $password,
    'login_url' => $login_url,
]);

// 10. Log the action
log_change('faculty-pending', 'UPDATE', $id, $reg['full_name'] . ' (' . $reg['email'] . ')',
    'status', 'pending', 'approved',
    'Faculty registration approved. User ID: ' . $new_user_id . ', Username: ' . $username);

flash_set('success', 'Registration approved! Account created for ' . h($reg['full_name']) . '. Credentials sent to ' . h($reg['email']) . '.');
redirect(APP_URL . '/faculty-profiles/pending.php');
