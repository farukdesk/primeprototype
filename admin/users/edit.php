<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/cv-helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$me   = auth_user();

// Carry the Users list's current search / group filter / page back through
// this page so saving (or cancelling) an edit returns to the same filtered view.
$return_qs = isset($_GET['return']) ? (string)$_GET['return'] : '';
$return_qs = str_replace(["\r", "\n"], '', $return_qs);
$users_index_url = APP_URL . '/users/index.php' . ($return_qs !== '' ? '?' . $return_qs : '');

// Users can edit their own profile; others need the edit permission
if ($id !== (int)$me['id']) {
    require_access('users', 'can_edit');
}

$stmt = db()->prepare(
    'SELECT u.*, g.is_super FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?'
);
$stmt->execute([$id]);
$edit_user = $stmt->fetch();

if (!$edit_user) {
    flash_set('error', 'User not found.');
    redirect($users_index_url);
}

// Load user's current group assignments
$gstmt = db()->prepare(
    'SELECT group_id, is_primary FROM user_group_assignments WHERE user_id = ?'
);
$gstmt->execute([$id]);
$current_assignments = $gstmt->fetchAll();
$current_group_ids   = array_column($current_assignments, 'group_id');
$current_primary_id  = (int)($edit_user['group_id']);
foreach ($current_assignments as $a) {
    if ($a['is_primary']) { $current_primary_id = (int)$a['group_id']; break; }
}

// Current Employee Type (department_type on staff_profiles: administrative | educational[=Faculty])
$sp_stmt = db()->prepare('SELECT department_type FROM staff_profiles WHERE user_id = ?');
$sp_stmt->execute([$id]);
$employee_type = (string)($sp_stmt->fetchColumn() ?: '');

// Only user-managers may set the Employee Type (self-editing users cannot).
$can_manage_users = is_super_admin() || can_access('users', 'can_edit');
$is_self          = ($id === (int)$me['id']);
// Managers may always edit the CV; a self-editing user may edit their own CV
// only if they already have an employee type assigned.
$can_edit_cv      = $can_manage_users || ($is_self && $employee_type !== '');

// Load current CV / profile data for display.
[$cv_sp, $cv_quals, $cv_exps, $cv_fp] = cv_load_for_user($id);

$page_title = 'Edit User';
$errors     = [];
$groups     = db()->query('SELECT id, name, is_super FROM user_groups WHERE is_active = 1 ORDER BY name')->fetchAll();
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name        = trim($_POST['full_name']      ?? '');
    $username         = trim($_POST['username']        ?? '');
    $email            = trim($_POST['email']           ?? '');
    $phone            = trim($_POST['phone']           ?? '');
    $student_sid      = trim($_POST['student_sid']     ?? '');
    $posted_emp_type  = in_array($_POST['employee_type'] ?? '', ['administrative','educational'], true)
                        ? $_POST['employee_type'] : '';
    // Self-editing users cannot change their employee type – keep the stored one.
    $employee_type    = $can_manage_users ? $posted_emp_type : $employee_type;
    $password         = $_POST['password']  ?? '';
    $password2        = $_POST['password2'] ?? '';
    $selected_groups  = array_map('intval', (array)($_POST['group_ids'] ?? []));
    $selected_groups  = array_filter($selected_groups);
    $primary_group_id = (int)($_POST['primary_group_id'] ?? 0);

    if (is_super_admin() && $id !== (int)$me['id']) {
        $is_active = isset($_POST['is_active']) ? 1 : 0;
    } else {
        $is_active = (int)$edit_user['is_active'];
    }

    // Non-super-admin cannot change groups or assign super group
    if (!is_super_admin()) {
        $selected_groups  = $current_group_ids;
        $primary_group_id = $current_primary_id;
    }

    if ($full_name === '')             $errors[] = 'Full name is required.';
    if ($username === '')              $errors[] = 'Username is required.';
    if (!preg_match('/^\w{3,60}$/', $username)) $errors[] = 'Username: 3–60 alphanumeric/underscore chars.';
    if ($email === '')                 $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (is_super_admin() && empty($selected_groups)) $errors[] = 'Please select at least one group.';

    if (!empty($selected_groups) && !in_array($primary_group_id, $selected_groups, true)) {
        $primary_group_id = (int)reset($selected_groups);
    }

    if ($password !== '') {
        if (strlen($password) < 8)    $errors[] = 'New password must be at least 8 characters.';
        if ($password !== $password2)  $errors[] = 'Passwords do not match.';
    }

    // Validate any optional CV uploads (photo / faculty CV PDF).
    if ($can_edit_cv) {
        $errors = array_merge($errors, cv_validate_uploads($_FILES));
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
        $db = db();

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $db->prepare(
                'UPDATE users SET group_id=?, username=?, email=?, password=?, full_name=?, phone=?, student_sid=?, is_active=? WHERE id=?'
            )->execute([$primary_group_id, $username, $email, $hash, $full_name, $phone ?: null, $student_sid ?: null, $is_active, $id]);
        } else {
            $db->prepare(
                'UPDATE users SET group_id=?, username=?, email=?, full_name=?, phone=?, student_sid=?, is_active=? WHERE id=?'
            )->execute([$primary_group_id, $username, $email, $full_name, $phone ?: null, $student_sid ?: null, $is_active, $id]);
        }

        // Employee Type → staff_profiles.department_type (Administrative / Faculty)
        // plus the full standard CV / profile (qualifications, experiences and,
        // for Faculty, the academic faculty_profiles record).
        if ($can_edit_cv && $employee_type !== '') {
            cv_save_profiles($id, $employee_type, $_POST, $_FILES);
        }

        // Update multi-group assignments (super admin only, or when groups changed)
        if (is_super_admin()) {
            // Remove old assignments, re-insert new ones
            $db->prepare('DELETE FROM user_group_assignments WHERE user_id = ?')->execute([$id]);
            $ins = $db->prepare(
                'INSERT IGNORE INTO user_group_assignments (user_id, group_id, is_primary) VALUES (?,?,?)'
            );
            foreach ($selected_groups as $gid) {
                $ins->execute([$id, $gid, ($gid === $primary_group_id) ? 1 : 0]);
            }

            // Log group changes
            $old_names = [];
            foreach ($current_group_ids as $gid) {
                $gn = $db->prepare('SELECT name FROM user_groups WHERE id = ?');
                $gn->execute([$gid]);
                $r = $gn->fetch();
                if ($r) $old_names[] = $r['name'] . ($gid === $current_primary_id ? ' (primary)' : '');
            }
            $new_names = [];
            foreach ($selected_groups as $gid) {
                $gn = $db->prepare('SELECT name FROM user_groups WHERE id = ?');
                $gn->execute([$gid]);
                $r = $gn->fetch();
                if ($r) $new_names[] = $r['name'] . ($gid === $primary_group_id ? ' (primary)' : '');
            }
            $old_str = implode(', ', $old_names);
            $new_str = implode(', ', $new_names);
            if ($old_str !== $new_str) {
                log_change('users', 'UPDATE', $id, $username, 'groups', $old_str, $new_str, "User group assignments updated");
            }
        }

        flash_set('success', "User <strong>" . h($full_name) . "</strong> updated.");
        redirect($id === (int)$me['id']
            ? APP_URL . '/users/edit.php?id=' . $id . ($return_qs !== '' ? '&return=' . urlencode($return_qs) : '')
            : $users_index_url);
    }

    // Repopulate for re-display
    $current_group_ids  = $selected_groups;
    $current_primary_id = $primary_group_id;
    $edit_user = array_merge($edit_user, compact('full_name', 'username', 'email', 'phone', 'is_active'));

    // Preserve typed CV / profile values on validation error.
    if ($can_edit_cv) {
        $cv_sp    = array_merge($cv_sp, $_POST);
        $cv_fp    = array_merge($cv_fp, $_POST);
        $cv_quals = cv_posted_rows($_POST, ['degree' => 'q_degree', 'group_name' => 'q_group',
                        'board_university' => 'q_board', 'passing_year' => 'q_year',
                        'grade' => 'q_grade', 'gpa_result' => 'q_result']);
        $cv_exps  = cv_posted_rows($_POST, ['position' => 'x_position', 'organization' => 'x_organization',
                        'department' => 'x_department', 'joining_date' => 'x_joining',
                        'resign_date' => 'x_resign']);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= h($users_index_url) ?>">Users</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-edit me-2 text-muted"></i>Edit: <?= h($edit_user['full_name']) ?></h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= h($edit_user['full_name']) ?>" required maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control"
                           value="<?= h($edit_user['username']) ?>" required maxlength="60">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($edit_user['email']) ?>" required maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($edit_user['phone'] ?? '') ?>" maxlength="30">
                </div>
                <?php if (is_super_admin()): ?>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Student ID
                        <small class="text-muted">(links user to their student fee record)</small>
                    </label>
                    <input type="text" name="student_sid" class="form-control"
                           value="<?= h($edit_user['student_sid'] ?? '') ?>" maxlength="50" autocomplete="off">
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label fw-medium">New Password
                        <small class="text-muted">(leave blank to keep current)</small>
                    </label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                </div>
                <?php if ($can_manage_users): ?>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Employee Type</label>
                    <select name="employee_type" id="employee_type" class="form-select">
                        <option value="">— Not an employee —</option>
                        <option value="administrative" <?= $employee_type === 'administrative' ? 'selected' : '' ?>>Administrative</option>
                        <option value="educational" <?= $employee_type === 'educational' ? 'selected' : '' ?>>Faculty</option>
                    </select>
                    <small class="text-muted" id="employee_type_help">
                        Administrative employees are managed in
                        <a href="<?= APP_URL ?>/staff-profiles/index.php" target="_blank">Employee Profiles</a>;
                        Faculty details come from
                        <a href="<?= APP_URL ?>/faculty-profiles/index.php" target="_blank">Faculty Profiles</a>.
                    </small>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Confirm New Password</label>
                    <input type="password" name="password2" class="form-control" autocomplete="new-password">
                </div>

                <?php if (is_super_admin()): ?>
                <!-- Multi-group selection -->
                <div class="col-12">
                    <label class="form-label fw-medium">User Groups <span class="text-danger">*</span></label>
                    <div class="border rounded p-3" style="border-radius:10px!important;max-height:220px;overflow-y:auto;">
                        <?php foreach ($groups as $g): ?>
                        <?php $checked = in_array((int)$g['id'], $current_group_ids, false); ?>
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
                    <small class="text-muted">Permissions are the union of all selected groups.</small>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-medium">Primary Group <span class="text-danger">*</span></label>
                    <select name="primary_group_id" id="primaryGroupSelect" class="form-select" required>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>"
                            <?= $current_primary_id == $g['id'] ? 'selected' : '' ?>
                            <?= !in_array((int)$g['id'], $current_group_ids, false) ? 'hidden' : '' ?>>
                            <?= h($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Displayed as the user's main group in listings.</small>
                </div>
                <?php endif; ?>

                <?php if (is_super_admin() && (int)$me['id'] !== $id): ?>
                <div class="col-md-6 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               <?= $edit_user['is_active'] ? 'checked' : '' ?> value="1">
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($can_edit_cv): ?>
            <?php cv_render_section($employee_type, $cv_sp, $cv_quals, $cv_exps, $cv_fp); ?>
            <?php endif; ?>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update User
                </button>
                <a href="<?= h($users_index_url) ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
function updatePrimary() {
    const sel   = document.getElementById('primaryGroupSelect');
    if (!sel) return;
    const boxes = document.querySelectorAll('.group-checkbox:checked');
    const checkedVals = Array.from(boxes).map(b => b.value);
    Array.from(sel.options).forEach(opt => {
        opt.hidden = !checkedVals.includes(opt.value);
    });
    if (sel.value && !checkedVals.includes(sel.value)) {
        sel.value = checkedVals[0] || '';
    }
    if (checkedVals.length === 1) sel.value = checkedVals[0];
}
document.addEventListener('DOMContentLoaded', updatePrimary);
</script>
<?php if ($can_edit_cv) cv_render_script(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
