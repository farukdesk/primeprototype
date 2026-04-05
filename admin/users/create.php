<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_access('users', 'can_create');

$page_title = 'Create User';
$errors     = [];
$groups     = db()->query('SELECT id, name, is_super FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name      = trim($_POST['full_name']      ?? '');
    $username       = trim($_POST['username']        ?? '');
    $email          = trim($_POST['email']           ?? '');
    $phone          = trim($_POST['phone']           ?? '');
    $selected_groups = array_map('intval', (array)($_POST['group_ids'] ?? []));
    $primary_group_id = (int)($_POST['primary_group_id'] ?? 0);
    $password       = $_POST['password']  ?? '';
    $password2      = $_POST['password2'] ?? '';
    $is_active      = isset($_POST['is_active']) ? 1 : 0;

    // Remove empty/zero entries
    $selected_groups = array_filter($selected_groups);

    // Non-super-admin cannot assign super admin groups
    if (!is_super_admin()) {
        foreach ($selected_groups as $gid) {
            $grp = db()->prepare('SELECT is_super FROM user_groups WHERE id = ?');
            $grp->execute([$gid]);
            $grpRow = $grp->fetch();
            if ($grpRow && $grpRow['is_super']) {
                $errors[] = 'You cannot assign users to the Super Admin group.';
                break;
            }
        }
    }

    if ($full_name === '')             $errors[] = 'Full name is required.';
    if ($username === '')              $errors[] = 'Username is required.';
    if (!preg_match('/^\w{3,60}$/', $username)) $errors[] = 'Username must be 3–60 alphanumeric/underscore characters.';
    if ($email === '')                 $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (empty($selected_groups))       $errors[] = 'Please select at least one group.';
    if ($password === '')              $errors[] = 'Password is required.';
    if (strlen($password) < 8)        $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)      $errors[] = 'Passwords do not match.';

    if (!empty($selected_groups) && !in_array($primary_group_id, $selected_groups, true)) {
        $primary_group_id = (int)reset($selected_groups);
    }

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM users WHERE username = ?');
        $dup->execute([$username]);
        if ($dup->fetch()) $errors[] = 'Username is already taken.';

        $dup2 = db()->prepare('SELECT id FROM users WHERE email = ?');
        $dup2->execute([$email]);
        if ($dup2->fetch()) $errors[] = 'Email is already registered.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $db   = db();
        $db->prepare(
            'INSERT INTO users (group_id, username, email, password, full_name, phone, is_active)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$primary_group_id, $username, $email, $hash, $full_name, $phone ?: null, $is_active]);

        $new_user_id = (int)$db->lastInsertId();

        // Save multi-group assignments
        $ins = $db->prepare(
            'INSERT IGNORE INTO user_group_assignments (user_id, group_id, is_primary) VALUES (?,?,?)'
        );
        foreach ($selected_groups as $gid) {
            $ins->execute([$new_user_id, $gid, ($gid === $primary_group_id) ? 1 : 0]);
        }

        // Log group assignments
        $group_names = [];
        foreach ($selected_groups as $gid) {
            $gn = db()->prepare('SELECT name FROM user_groups WHERE id = ?');
            $gn->execute([$gid]);
            $r = $gn->fetch();
            if ($r) $group_names[] = $r['name'] . ($gid === $primary_group_id ? ' (primary)' : '');
        }
        log_change('users', 'CREATE', $new_user_id, $username, 'groups', null, implode(', ', $group_names), "User created with groups: " . implode(', ', $group_names));

        flash_set('success', "User <strong>" . h($full_name) . "</strong> created.");
        redirect(APP_URL . '/users/index.php');
    }

    save_old(compact('full_name', 'username', 'email', 'phone', 'primary_group_id') + ['group_ids_post' => $selected_groups]);
}

$old_selected = $_SESSION['old']['group_ids_post'] ?? [];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Create</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>New User</h6>
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
                           value="<?= old('full_name') ?>" required maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= old('username') ?>" required maxlength="60" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= old('email') ?>" required maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= old('phone') ?>" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control"
                           required minlength="8" autocomplete="new-password">
                    <small class="text-muted">Minimum 8 characters.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="password2" class="form-control"
                           required minlength="8" autocomplete="new-password">
                </div>

                <!-- Multi-group selection -->
                <div class="col-12">
                    <label class="form-label fw-medium">User Groups <span class="text-danger">*</span></label>
                    <div class="border rounded p-3" style="border-radius:10px!important;max-height:220px;overflow-y:auto;">
                        <?php foreach ($groups as $g): ?>
                        <?php $checked = in_array((int)$g['id'], $old_selected, false); ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input group-checkbox" type="checkbox"
                                   name="group_ids[]" value="<?= $g['id'] ?>"
                                   id="grp_<?= $g['id'] ?>"
                                   <?= $checked ? 'checked' : '' ?>
                                   onchange="updatePrimary()">
                            <label class="form-check-label" for="grp_<?= $g['id'] ?>">
                                <?= h($g['name']) ?>
                                <?php if ($g['is_super']): ?>
                                <span class="badge badge-super ms-1" style="font-size:.65rem;">Super Admin</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <small class="text-muted">Select one or more groups. Permissions are the union of all selected groups.</small>
                </div>

                <!-- Primary group -->
                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Group <span class="text-danger">*</span></label>
                    <select name="primary_group_id" id="primaryGroupSelect" class="form-select" required>
                        <option value="">-- Select primary group --</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= old('primary_group_id') == $g['id'] ? 'selected' : '' ?>>
                            <?= h($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Displayed as the user's main group in listings.</small>
                </div>

                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Create User
                </button>
                <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
function updatePrimary() {
    const sel   = document.getElementById('primaryGroupSelect');
    const boxes = document.querySelectorAll('.group-checkbox:checked');
    const checkedVals = Array.from(boxes).map(b => b.value);
    // Show only options whose groups are checked
    Array.from(sel.options).forEach(opt => {
        if (opt.value === '') return;
        opt.hidden = !checkedVals.includes(opt.value);
    });
    // If current primary is no longer checked, reset
    if (sel.value && !checkedVals.includes(sel.value)) {
        sel.value = checkedVals[0] || '';
    }
    // Auto-select if only one group checked
    if (checkedVals.length === 1) sel.value = checkedVals[0];
}
document.addEventListener('DOMContentLoaded', updatePrimary);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>


$page_title = 'Create User';
$errors     = [];
$groups     = db()->query('SELECT id, name FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $group_id  = (int)($_POST['group_id'] ?? 0);
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Only super admin can assign super admin groups
    if (!is_super_admin()) {
        $grp = db()->prepare('SELECT is_super FROM user_groups WHERE id = ?');
        $grp->execute([$group_id]);
        $grpRow = $grp->fetch();
        if ($grpRow && $grpRow['is_super']) {
            $errors[] = 'You cannot assign users to the Super Admin group.';
        }
    }

    if ($full_name === '')             $errors[] = 'Full name is required.';
    if ($username === '')              $errors[] = 'Username is required.';
    if (!preg_match('/^\w{3,60}$/', $username)) $errors[] = 'Username must be 3–60 alphanumeric/underscore characters.';
    if ($email === '')                 $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($group_id === 0)               $errors[] = 'Please select a group.';
    if ($password === '')              $errors[] = 'Password is required.';
    if (strlen($password) < 8)        $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)      $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $dup = db()->prepare('SELECT id FROM users WHERE username = ?');
        $dup->execute([$username]);
        if ($dup->fetch()) $errors[] = 'Username is already taken.';

        $dup2 = db()->prepare('SELECT id FROM users WHERE email = ?');
        $dup2->execute([$email]);
        if ($dup2->fetch()) $errors[] = 'Email is already registered.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        db()->prepare(
            'INSERT INTO users (group_id, username, email, password, full_name, phone, is_active)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$group_id, $username, $email, $hash, $full_name, $phone ?: null, $is_active]);

        flash_set('success', "User <strong>" . h($full_name) . "</strong> created.");
        redirect(APP_URL . '/users/index.php');
    }

    save_old(compact('full_name', 'username', 'email', 'phone', 'group_id'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/users/index.php">Users</a></li>
            <li class="breadcrumb-item active">Create</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>New User</h6>
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
                           value="<?= old('full_name') ?>" required maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= old('username') ?>" required maxlength="60" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= old('email') ?>" required maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= old('phone') ?>" maxlength="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control"
                           required minlength="8" autocomplete="new-password">
                    <small class="text-muted">Minimum 8 characters.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="password2" class="form-control"
                           required minlength="8" autocomplete="new-password">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">User Group <span class="text-danger">*</span></label>
                    <select name="group_id" class="form-select" required>
                        <option value="">-- Select group --</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= old('group_id') == $g['id'] ? 'selected' : '' ?>>
                            <?= h($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Create User
                </button>
                <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
