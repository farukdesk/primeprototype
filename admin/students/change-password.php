<?php
/**
 * Student Portal – Change Password
 * Allows the logged-in student to change their own portal account password.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

if (!is_portal_student()) {
    redirect(APP_URL . '/index.php');
}

$user = auth_user();
$page_title = 'Change Password';

// ── Handle POST ───────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    // Fetch current hash from DB
    $row = db()->prepare('SELECT password FROM users WHERE id = ? AND is_active = 1');
    $row->execute([$user['id']]);
    $row = $row->fetch();

    if (!$row || !password_verify($current, $row['password'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
           ->execute([$hash, $user['id']]);
        flash_set('success', 'Password changed successfully.');
        redirect(APP_URL . '/students/my-profile.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.cp-card {
    max-width: 480px;
    margin: 40px auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    padding: 36px 40px;
}
.cp-card h4 {
    font-weight: 700;
    color: #1e3a5f;
    margin-bottom: 6px;
}
.cp-card .text-muted-sm {
    font-size: .85rem;
    color: #6c757d;
    margin-bottom: 24px;
}
</style>

<div class="cp-card">
    <h4><i class="fas fa-lock me-2" style="color:#2563eb"></i>Change Password</h4>
    <p class="text-muted-sm">Enter your current password and choose a new one (minimum 8 characters).</p>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
        <?php endforeach; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">

        <div class="mb-3">
            <label class="form-label fw-semibold">Current Password</label>
            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">New Password</label>
            <input type="password" name="new_password" class="form-control" required autocomplete="new-password" minlength="8">
            <div class="form-text">At least 8 characters.</div>
        </div>
        <div class="mb-4">
            <label class="form-label fw-semibold">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password" minlength="8">
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i>Update Password
            </button>
            <a href="<?= APP_URL ?>/students/my-profile.php" class="btn btn-outline-secondary px-4">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
