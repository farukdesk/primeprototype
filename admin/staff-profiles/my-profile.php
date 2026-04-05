<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-profile', 'can_edit');

require_once __DIR__ . '/sp-helpers.php';

$user_id      = (int)auth_user()['id'];
$current_user = auth_user();

// Load existing profile
$sp_stmt = db()->prepare('SELECT * FROM staff_profiles WHERE user_id = ?');
$sp_stmt->execute([$user_id]);
$sp = $sp_stmt->fetch() ?: [];

// Load all active staff departments (grouped by type) for the dropdowns
$all_depts = db()->query(
    "SELECT id, name, type FROM staff_departments WHERE is_active = 1 ORDER BY type ASC, sort_order ASC, name ASC"
)->fetchAll();

$admin_depts = array_filter($all_depts, fn($d) => $d['type'] === 'administrative');
$edu_depts   = array_filter($all_depts, fn($d) => $d['type'] === 'educational');

$page_title = 'My Staff Profile';
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'profile';

    // ── Password change ────────────────────────────────────────────────────────
    if ($action === 'password') {
        $cur_pass  = $_POST['current_password'] ?? '';
        $new_pass  = $_POST['new_password']      ?? '';
        $conf_pass = $_POST['confirm_password']  ?? '';

        if ($cur_pass === '' || $new_pass === '' || $conf_pass === '') {
            $errors[] = 'All password fields are required.';
        } elseif (!password_verify($cur_pass, $current_user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new_pass) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new_pass !== $conf_pass) {
            $errors[] = 'New password and confirmation do not match.';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                 ->execute([$hash, $user_id]);
            log_change('staff_profiles', $user_id, 'password_change', 'Password changed by user');
            $success = true;
        }

    // ── Profile save ──────────────────────────────────────────────────────────
    } else {
        // Basic user fields
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $phone     = trim($_POST['phone']     ?? '') ?: null;

        if ($full_name === '') $errors[] = 'Full name is required.';
        if ($email === '')     $errors[] = 'Email is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

        // Check email uniqueness (exclude current user)
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $chk = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $chk->execute([$email, $user_id]);
            if ($chk->fetch()) $errors[] = 'That email address is already used by another account.';
        }

        // Staff profile fields
        $employee_id   = trim($_POST['employee_id']   ?? '') ?: null;
        $dept_type     = in_array($_POST['department_type'] ?? '', ['administrative','educational'])
                         ? $_POST['department_type'] : null;
        $staff_dept_id = (int)($_POST['staff_dept_id'] ?? 0) ?: null;
        $designation   = trim($_POST['designation']   ?? '') ?: null;

        $ec_name     = trim($_POST['emergency_contact_name']     ?? '') ?: null;
        $ec_relation = trim($_POST['emergency_contact_relation'] ?? '') ?: null;
        $ec_address  = trim($_POST['emergency_contact_address']  ?? '') ?: null;

        // Photo upload
        $photo = $sp['photo'] ?? null;
        if (!empty($_FILES['photo']['name'])) {
            $uploaded = sp_upload_photo($_FILES['photo']);
            if ($uploaded === false) {
                $errors[] = 'Invalid photo. Allowed: jpg, jpeg, png, gif, webp (max 5 MB).';
            } else {
                if (!empty($sp['photo'])) {
                    $old = UPLOAD_DIR . '/staff-profiles/' . basename($sp['photo']);
                    if (file_exists($old)) @unlink($old);
                }
                $photo = $uploaded;
            }
        }

        if (empty($errors)) {
            // Update users table
            db()->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?')
                 ->execute([$full_name, $email, $phone, $user_id]);

            // Upsert staff_profiles
            db()->prepare(
                'INSERT INTO staff_profiles
                 (user_id, photo, employee_id, department_type, staff_dept_id, designation,
                  emergency_contact_name, emergency_contact_relation, emergency_contact_address)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                  photo=VALUES(photo), employee_id=VALUES(employee_id),
                  department_type=VALUES(department_type), staff_dept_id=VALUES(staff_dept_id),
                  designation=VALUES(designation),
                  emergency_contact_name=VALUES(emergency_contact_name),
                  emergency_contact_relation=VALUES(emergency_contact_relation),
                  emergency_contact_address=VALUES(emergency_contact_address)'
            )->execute([
                $user_id, $photo, $employee_id, $dept_type, $staff_dept_id, $designation,
                $ec_name, $ec_relation, $ec_address,
            ]);

            log_change('staff_profiles', $user_id, 'update', 'Staff profile updated');
            $success = true;

            // Reload current user and profile
            $current_user = auth_user();
            $sp_stmt->execute([$user_id]);
            $sp = $sp_stmt->fetch() ?: [];
        } else {
            // Keep submitted values so the form re-populates
            $sp = array_merge($sp, [
                'photo'                      => $photo,
                'employee_id'                => $employee_id,
                'department_type'            => $dept_type,
                'staff_dept_id'              => $staff_dept_id,
                'designation'                => $designation,
                'emergency_contact_name'     => $ec_name,
                'emergency_contact_relation' => $ec_relation,
                'emergency_contact_address'  => $ec_address,
            ]);
            $current_user = array_merge($current_user, [
                'full_name' => $full_name,
                'email'     => $email,
                'phone'     => $phone ?? '',
            ]);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">My Staff Profile</li>
        </ol>
    </nav>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i> <strong>Saved!</strong> Your profile has been updated successfully.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>My Staff Profile</h6>
    </div>
    <div class="card-body p-4">

        <!-- Nav tabs -->
        <ul class="nav nav-tabs mb-4" id="profileTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-basic">Basic Info</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-dept">Department &amp; Role</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-emergency">Emergency Contact</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-password">Change Password</a></li>
        </ul>

        <div class="tab-content">

            <!-- ── Tab 1: Basic Info ─────────────────────────────────────────── -->
            <div class="tab-pane fade show active" id="tab-basic">
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="row g-3">

                        <!-- Photo -->
                        <div class="col-md-4 text-center">
                            <?php if (!empty($sp['photo'])): ?>
                            <img src="<?= UPLOAD_URL ?>/staff-profiles/<?= h($sp['photo']) ?>"
                                 alt="Profile Photo"
                                 style="height:100px;width:100px;border-radius:50%;object-fit:cover;border:2px solid #4f8ef7;" class="mb-2">
                            <?php else: ?>
                            <div style="height:100px;width:100px;border-radius:50%;background:#e9ecef;display:inline-flex;align-items:center;justify-content:center;font-size:2rem;color:#adb5bd;" class="mb-2">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="form-label fw-medium d-block">Profile Photo</label>
                                <input type="file" name="photo" class="form-control" style="border-radius:10px;"
                                       accept=".jpg,.jpeg,.png,.gif,.webp">
                                <small class="text-muted">Leave blank to keep current photo. jpg/jpeg/png/gif/webp, max 5 MB.</small>
                            </div>
                        </div>

                        <!-- Core user fields -->
                        <div class="col-md-8">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" style="border-radius:10px;"
                                           value="<?= h($current_user['full_name'] ?? '') ?>" maxlength="150" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium">Username</label>
                                    <input type="text" class="form-control" style="border-radius:10px;background:#f8f9fa;"
                                           value="<?= h($current_user['username'] ?? '') ?>" readonly>
                                    <small class="text-muted">Username cannot be changed. Contact an administrator.</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" style="border-radius:10px;"
                                           value="<?= h($current_user['email'] ?? '') ?>" maxlength="191" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" style="border-radius:10px;"
                                           value="<?= h($current_user['phone'] ?? '') ?>" maxlength="30">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-medium">Employee ID</label>
                                    <input type="text" name="employee_id" class="form-control" style="border-radius:10px;"
                                           value="<?= h($sp['employee_id'] ?? '') ?>" maxlength="100">
                                </div>
                            </div>
                        </div>

                    </div><!-- /.row -->

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Tab 2: Department & Role ──────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-dept">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <!-- Re-send read-only user fields so the profile save handler has them -->
                    <input type="hidden" name="full_name" value="<?= h($current_user['full_name'] ?? '') ?>">
                    <input type="hidden" name="email"     value="<?= h($current_user['email']     ?? '') ?>">
                    <input type="hidden" name="phone"     value="<?= h($current_user['phone']     ?? '') ?>">
                    <input type="hidden" name="employee_id" value="<?= h($sp['employee_id'] ?? '') ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department Type</label>
                            <select name="department_type" id="dept_type_select" class="form-select" style="border-radius:10px;">
                                <option value="">— Select type —</option>
                                <option value="administrative" <?= ($sp['department_type'] ?? '') === 'administrative' ? 'selected' : '' ?>>
                                    Administrative Department
                                </option>
                                <option value="educational" <?= ($sp['department_type'] ?? '') === 'educational' ? 'selected' : '' ?>>
                                    Educational Department
                                </option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="staff_dept_id" id="dept_select" class="form-select" style="border-radius:10px;">
                                <option value="0">— Select department —</option>
                                <?php foreach ($admin_depts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"
                                        data-type="administrative"
                                        <?= (int)($sp['staff_dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php foreach ($edu_depts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"
                                        data-type="educational"
                                        <?= (int)($sp['staff_dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Departments are filtered by the selected type above.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium">Designation</label>
                            <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['designation'] ?? '') ?>" maxlength="200"
                                   placeholder="e.g. Senior HR Officer, IT Administrator">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Tab 3: Emergency Contact ──────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-emergency">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">
                    <input type="hidden" name="full_name"      value="<?= h($current_user['full_name'] ?? '') ?>">
                    <input type="hidden" name="email"          value="<?= h($current_user['email']     ?? '') ?>">
                    <input type="hidden" name="phone"          value="<?= h($current_user['phone']     ?? '') ?>">
                    <input type="hidden" name="employee_id"    value="<?= h($sp['employee_id']    ?? '') ?>">
                    <input type="hidden" name="department_type" value="<?= h($sp['department_type'] ?? '') ?>">
                    <input type="hidden" name="staff_dept_id"  value="<?= h($sp['staff_dept_id']  ?? '') ?>">
                    <input type="hidden" name="designation"    value="<?= h($sp['designation']    ?? '') ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Contact Person Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['emergency_contact_name'] ?? '') ?>" maxlength="150">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Relationship</label>
                            <input type="text" name="emergency_contact_relation" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['emergency_contact_relation'] ?? '') ?>" maxlength="100"
                                   placeholder="e.g. Spouse, Parent, Sibling">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Address</label>
                            <textarea name="emergency_contact_address" class="form-control" style="border-radius:10px;" rows="4"
                                      placeholder="Full address of the emergency contact"><?= h($sp['emergency_contact_address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Tab 4: Change Password ─────────────────────────────────────── -->
            <div class="tab-pane fade" id="tab-password">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="row g-3" style="max-width:500px;">
                        <div class="col-12">
                            <label class="form-label fw-medium">Current Password <span class="text-danger">*</span></label>
                            <input type="password" name="current_password" class="form-control" style="border-radius:10px;" required autocomplete="current-password">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">New Password <span class="text-danger">*</span></label>
                            <input type="password" name="new_password" class="form-control" style="border-radius:10px;" required autocomplete="new-password">
                            <small class="text-muted">Minimum 8 characters.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Confirm New Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" style="border-radius:10px;" required autocomplete="new-password">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-warning" style="border-radius:10px;">
                            <i class="fas fa-key me-1"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>

        </div><!-- /.tab-content -->
    </div><!-- /.card-body -->
</div><!-- /.card -->

<script>
// Filter department options based on selected department type
(function () {
    const typeSelect = document.getElementById('dept_type_select');
    const deptSelect = document.getElementById('dept_select');
    if (!typeSelect || !deptSelect) return;

    function filterDepts() {
        const chosen = typeSelect.value;
        Array.from(deptSelect.options).forEach(function (opt) {
            if (!opt.dataset.type) return; // keep the placeholder
            opt.hidden = chosen !== '' && opt.dataset.type !== chosen;
        });
        // Reset dept selection when type changes if current selection doesn't match
        const selectedOpt = deptSelect.options[deptSelect.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.type && selectedOpt.dataset.type !== chosen) {
            deptSelect.value = '0';
        }
    }

    typeSelect.addEventListener('change', filterDepts);
    filterDepts(); // run on page load
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
