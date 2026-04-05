<?php
require_once __DIR__ . '/../includes/auth.php';

$id   = (int)($_GET['id'] ?? 0);
$me   = auth_user();

// Users can edit their own profile; others need the edit permission
if ($id !== (int)$me['id']) {
    require_access('users', 'can_edit');
}

$stmt = db()->prepare(
    'SELECT u.*, g.is_super FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?'
);
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'User not found.');
    redirect(APP_URL . '/users/index.php');
}

$page_title = 'Edit User';
$errors     = [];
$groups     = db()->query('SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $group_id  = (int)($_POST['group_id'] ?? $user['group_id']);
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';
    // Only super admin can change active status, and only for other users (never self)
    if (is_super_admin() && $id !== (int)$me['id']) {
        $is_active = isset($_POST['is_active']) ? 1 : 0;
    } else {
        $is_active = (int)$user['is_active']; // preserve current value
    }

    // Non-super-admin cannot change own group or assign super group
    if (!is_super_admin()) {
        $group_id = (int)$user['group_id']; // lock group to current
    }

    if ($full_name === '')             $errors[] = 'Full name is required.';
    if ($username === '')              $errors[] = 'Username is required.';
    if (!preg_match('/^\w{3,60}$/', $username)) $errors[] = 'Username: 3–60 alphanumeric/underscore chars.';
    if ($email === '')                 $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if ($password !== '') {
        if (strlen($password) < 8)    $errors[] = 'New password must be at least 8 characters.';
        if ($password !== $password2)  $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $dup->execute([$username, $id]);
        if ($dup->fetch()) $errors[] = 'Username is taken by another user.';

        $dup2 = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $dup2->execute([$email, $id]);
        if ($dup2->fetch()) $errors[] = 'Email is used by another account.';
    }

    if (empty($errors)) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            db()->prepare(
                'UPDATE users SET group_id=?, username=?, email=?, password=?, full_name=?, phone=?, is_active=? WHERE id=?'
            )->execute([$group_id, $username, $email, $hash, $full_name, $phone ?: null, $is_active, $id]);
        } else {
            db()->prepare(
                'UPDATE users SET group_id=?, username=?, email=?, full_name=?, phone=?, is_active=? WHERE id=?'
            )->execute([$group_id, $username, $email, $full_name, $phone ?: null, $is_active, $id]);
        }

        flash_set('success', "User <strong>" . h($full_name) . "</strong> updated.");
        redirect($id === (int)$me['id']
            ? APP_URL . '/users/edit.php?id=' . $id
            : APP_URL . '/users/index.php');
    }

    $user = array_merge($user, compact('full_name', 'username', 'email', 'phone', 'group_id', 'is_active'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-edit me-2 text-muted"></i>Edit: <?= h($user['full_name']) ?></h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= h($user['full_name']) ?>" required maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= h($user['username']) ?>" required maxlength="60">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($user['email']) ?>" required maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($user['phone'] ?? '') ?>" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">New Password
                        <small class="text-muted">(leave blank to keep current)</small>
                    </label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Confirm New Password</label>
                    <input type="password" name="password2" class="form-control" autocomplete="new-password">
                </div>

                <?php if (is_super_admin()): ?>
                <div class="col-md-6">
                    <label class="form-label fw-medium">User Group <span class="text-danger">*</span></label>
                    <select name="group_id" class="form-select" required>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= $user['group_id'] == $g['id'] ? 'selected' : '' ?>>
                            <?= h($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if (is_super_admin() && (int)$me['id'] !== $id): ?>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               <?= $user['is_active'] ? 'checked' : '' ?> value="1">
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update User
                </button>
                <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
