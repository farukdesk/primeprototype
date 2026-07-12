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

// Qualifications & experiences (child rows)
$quals = sp_qualifications($user_id);
$exps  = sp_experiences($user_id);

$page_title = 'My Employee Profile';
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
            log_change('staff-profile', 'UPDATE', (int)$user_id, 'Password changed by user');
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

        // Employee profile fields
        $employee_id   = trim($_POST['employee_id']   ?? '') ?: null;
        $finger_id     = trim($_POST['finger_id']     ?? '') ?: null;
        $dept_type     = in_array($_POST['department_type'] ?? '', ['administrative','educational'])
                         ? $_POST['department_type'] : null;
        $staff_dept_id = (int)($_POST['staff_dept_id'] ?? 0) ?: null;
        $designation   = trim($_POST['designation']   ?? '') ?: null;

        $father_name   = trim($_POST['father_name']   ?? '') ?: null;
        $mother_name   = trim($_POST['mother_name']   ?? '') ?: null;
        $job_type      = in_array($_POST['job_type'] ?? '', SP_JOB_TYPES, true) ? $_POST['job_type'] : null;
        $gender        = in_array($_POST['gender'] ?? '', SP_GENDERS, true) ? $_POST['gender'] : null;
        $religion      = trim($_POST['religion']      ?? '') ?: null;
        $blood_group   = trim($_POST['blood_group']   ?? '') ?: null;
        $national_id   = trim($_POST['national_id']   ?? '') ?: null;
        $date_of_birth = trim($_POST['date_of_birth'] ?? '') ?: null;
        $joining_date  = trim($_POST['joining_date']  ?? '') ?: null;
        $nationality   = trim($_POST['nationality']   ?? '') ?: null;
        $birth_place   = trim($_POST['birth_place']   ?? '') ?: null;
        $emp_status    = in_array($_POST['employee_status'] ?? '', SP_EMPLOYEE_STATUSES, true)
                         ? $_POST['employee_status'] : 'Active';

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
                 (user_id, photo, employee_id, finger_id, department_type, staff_dept_id, designation,
                  father_name, mother_name, job_type, gender, religion, blood_group, national_id,
                  date_of_birth, joining_date, nationality, birth_place, employee_status,
                  emergency_contact_name, emergency_contact_relation, emergency_contact_address)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                  photo=VALUES(photo), employee_id=VALUES(employee_id), finger_id=VALUES(finger_id),
                  department_type=VALUES(department_type), staff_dept_id=VALUES(staff_dept_id),
                  designation=VALUES(designation),
                  father_name=VALUES(father_name), mother_name=VALUES(mother_name),
                  job_type=VALUES(job_type), gender=VALUES(gender), religion=VALUES(religion),
                  blood_group=VALUES(blood_group), national_id=VALUES(national_id),
                  date_of_birth=VALUES(date_of_birth), joining_date=VALUES(joining_date),
                  nationality=VALUES(nationality), birth_place=VALUES(birth_place),
                  employee_status=VALUES(employee_status),
                  emergency_contact_name=VALUES(emergency_contact_name),
                  emergency_contact_relation=VALUES(emergency_contact_relation),
                  emergency_contact_address=VALUES(emergency_contact_address)'
            )->execute([
                $user_id, $photo, $employee_id, $finger_id, $dept_type, $staff_dept_id, $designation,
                $father_name, $mother_name, $job_type, $gender, $religion, $blood_group, $national_id,
                $date_of_birth, $joining_date, $nationality, $birth_place, $emp_status,
                $ec_name, $ec_relation, $ec_address,
            ]);

            // Replace qualification & experience rows
            sp_save_qualifications($user_id, $_POST);
            sp_save_experiences($user_id, $_POST);

            log_change('staff-profile', 'UPDATE', (int)$user_id, 'Employee profile updated');
            $success = true;

            // Reload current user and profile
            $current_user = auth_user();
            $sp_stmt->execute([$user_id]);
            $sp = $sp_stmt->fetch() ?: [];
            $quals = sp_qualifications($user_id);
            $exps  = sp_experiences($user_id);
        } else {
            // Keep submitted values so the form re-populates
            $sp = array_merge($sp, [
                'photo'                      => $photo,
                'employee_id'                => $employee_id,
                'finger_id'                  => $finger_id,
                'department_type'            => $dept_type,
                'staff_dept_id'              => $staff_dept_id,
                'designation'                => $designation,
                'father_name'                => $father_name,
                'mother_name'                => $mother_name,
                'job_type'                   => $job_type,
                'gender'                     => $gender,
                'religion'                   => $religion,
                'blood_group'                => $blood_group,
                'national_id'                => $national_id,
                'date_of_birth'              => $date_of_birth,
                'joining_date'               => $joining_date,
                'nationality'                => $nationality,
                'birth_place'                => $birth_place,
                'employee_status'            => $emp_status,
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

<?php
$dept_type_cur = $sp['department_type'] ?? '';
$is_faculty    = $dept_type_cur === 'educational';
// Ensure at least one empty row is rendered for repeatable sections
$quals_r = $quals ?: [[]];
$exps_r  = $exps  ?: [[]];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">My Employee Profile</li>
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

<?php if ($is_faculty): ?>
<div class="alert alert-info d-flex align-items-center">
    <i class="fas fa-graduation-cap me-2"></i>
    <div>
        You are registered as a <strong>Faculty</strong> employee. Academic details
        (qualifications, research, publications, courses&hellip;) are managed in your
        <a href="<?= APP_URL ?>/faculty-profiles/my-profile.php" class="alert-link">Faculty Profile</a>.
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>My Employee Profile</h6>
    </div>
    <div class="card-body p-4">

        <!-- Nav tabs -->
        <ul class="nav nav-tabs mb-4" id="profileTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-basic">Basic Info</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-personal">Personal</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-employment">Employment</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-qualifications">Qualifications</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-experience">Experience</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-emergency">Emergency Contact</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-password">Change Password</a></li>
        </ul>

        <div class="tab-content">

            <!-- ── Single profile form spanning all non-password tabs ─────────── -->
            <form method="POST" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <!-- Tab: Basic Info -->
                <div class="tab-pane fade show active" id="tab-basic">
                    <div class="row g-3">
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
                        <div class="col-md-8">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-medium">Employee Name <span class="text-danger">*</span></label>
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
                                <div class="col-md-3">
                                    <label class="form-label fw-medium">Employee ID</label>
                                    <input type="text" name="employee_id" class="form-control" style="border-radius:10px;"
                                           value="<?= h($sp['employee_id'] ?? '') ?>" maxlength="100">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-medium">Finger ID</label>
                                    <input type="text" name="finger_id" class="form-control" style="border-radius:10px;"
                                           value="<?= h($sp['finger_id'] ?? '') ?>" maxlength="100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Personal -->
                <div class="tab-pane fade" id="tab-personal">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Father Name</label>
                            <input type="text" name="father_name" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['father_name'] ?? '') ?>" maxlength="200">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Mother Name</label>
                            <input type="text" name="mother_name" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['mother_name'] ?? '') ?>" maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Gender</label>
                            <select name="gender" class="form-select" style="border-radius:10px;">
                                <option value="">— Select —</option>
                                <?php foreach (SP_GENDERS as $g): ?>
                                <option value="<?= h($g) ?>" <?= ($sp['gender'] ?? '') === $g ? 'selected' : '' ?>><?= h($g) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Religion</label>
                            <input type="text" name="religion" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['religion'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Blood Group</label>
                            <select name="blood_group" class="form-select" style="border-radius:10px;">
                                <option value="">— Select —</option>
                                <?php foreach (SP_BLOOD_GROUPS as $bg): ?>
                                <option value="<?= h($bg) ?>" <?= ($sp['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= h($bg) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">National ID</label>
                            <input type="text" name="national_id" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['national_id'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Nationality</label>
                            <input type="text" name="nationality" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['nationality'] ?? '') ?>" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium">Birth Place</label>
                            <input type="text" name="birth_place" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['birth_place'] ?? '') ?>" maxlength="200">
                        </div>
                    </div>
                </div>

                <!-- Tab: Employment -->
                <div class="tab-pane fade" id="tab-employment">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Employee Type</label>
                            <select name="department_type" id="dept_type_select" class="form-select" style="border-radius:10px;">
                                <option value="">— Select type —</option>
                                <?php foreach (SP_EMPLOYEE_TYPES as $val => $label): ?>
                                <option value="<?= h($val) ?>" <?= ($sp['department_type'] ?? '') === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="staff_dept_id" id="dept_select" class="form-select" style="border-radius:10px;">
                                <option value="0">— Select department —</option>
                                <?php foreach ($admin_depts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" data-type="administrative"
                                        <?= (int)($sp['staff_dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                                <?php foreach ($edu_depts as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" data-type="educational"
                                        <?= (int)($sp['staff_dept_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Departments are filtered by the selected Employee Type above.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Designation</label>
                            <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['designation'] ?? '') ?>" maxlength="200"
                                   placeholder="e.g. Senior HR Officer, IT Administrator">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Job Type <small class="text-muted">(Category)</small></label>
                            <select name="job_type" class="form-select" style="border-radius:10px;">
                                <option value="">— Select —</option>
                                <?php foreach (SP_JOB_TYPES as $jt): ?>
                                <option value="<?= h($jt) ?>" <?= ($sp['job_type'] ?? '') === $jt ? 'selected' : '' ?>><?= h($jt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control" style="border-radius:10px;"
                                   value="<?= h($sp['joining_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Employee Status</label>
                            <select name="employee_status" class="form-select" style="border-radius:10px;">
                                <?php foreach (SP_EMPLOYEE_STATUSES as $st): ?>
                                <option value="<?= h($st) ?>" <?= ($sp['employee_status'] ?? 'Active') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tab: Qualifications -->
                <div class="tab-pane fade" id="tab-qualifications">
                    <p class="text-muted small">Degree, Group, Board/University, Passing Year, Grade, GPA/Result.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="qual-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Degree</th><th>Group</th><th>Board/University</th>
                                    <th>Passing Year</th><th>Grade</th><th>GPA/Result</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($quals_r as $q): ?>
                                <tr>
                                    <td><input type="text" name="q_degree[]" class="form-control form-control-sm" value="<?= h($q['degree'] ?? '') ?>"></td>
                                    <td><input type="text" name="q_group[]" class="form-control form-control-sm" value="<?= h($q['group_name'] ?? '') ?>"></td>
                                    <td><input type="text" name="q_board[]" class="form-control form-control-sm" value="<?= h($q['board_university'] ?? '') ?>"></td>
                                    <td><input type="text" name="q_year[]" class="form-control form-control-sm" value="<?= h($q['passing_year'] ?? '') ?>"></td>
                                    <td><input type="text" name="q_grade[]" class="form-control form-control-sm" value="<?= h($q['grade'] ?? '') ?>"></td>
                                    <td><input type="text" name="q_result[]" class="form-control form-control-sm" value="<?= h($q['gpa_result'] ?? '') ?>"></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger row-remove"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-add-row="qual-table">
                        <i class="fas fa-plus me-1"></i> Add Qualification
                    </button>
                </div>

                <!-- Tab: Experience -->
                <div class="tab-pane fade" id="tab-experience">
                    <p class="text-muted small">Position, Organization, Department, Joining Date, Resign Date.</p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="exp-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Position</th><th>Organization</th><th>Department</th>
                                    <th>Joining Date</th><th>Resign Date</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($exps_r as $x): ?>
                                <tr>
                                    <td><input type="text" name="x_position[]" class="form-control form-control-sm" value="<?= h($x['position'] ?? '') ?>"></td>
                                    <td><input type="text" name="x_organization[]" class="form-control form-control-sm" value="<?= h($x['organization'] ?? '') ?>"></td>
                                    <td><input type="text" name="x_department[]" class="form-control form-control-sm" value="<?= h($x['department'] ?? '') ?>"></td>
                                    <td><input type="date" name="x_joining[]" class="form-control form-control-sm" value="<?= h($x['joining_date'] ?? '') ?>"></td>
                                    <td><input type="date" name="x_resign[]" class="form-control form-control-sm" value="<?= h($x['resign_date'] ?? '') ?>"></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger row-remove"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-add-row="exp-table">
                        <i class="fas fa-plus me-1"></i> Add Experience
                    </button>
                </div>

                <!-- Tab: Emergency Contact -->
                <div class="tab-pane fade" id="tab-emergency">
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
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                </div>
            </form>

            <!-- Password form (separate) -->
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
// Filter department options based on selected Employee Type
(function () {
    const typeSelect = document.getElementById('dept_type_select');
    const deptSelect = document.getElementById('dept_select');
    if (!typeSelect || !deptSelect) return;
    function filterDepts() {
        const chosen = typeSelect.value;
        Array.from(deptSelect.options).forEach(function (opt) {
            if (!opt.dataset.type) return;
            opt.hidden = chosen !== '' && opt.dataset.type !== chosen;
        });
        const selectedOpt = deptSelect.options[deptSelect.selectedIndex];
        if (selectedOpt && selectedOpt.dataset.type && selectedOpt.dataset.type !== chosen) {
            deptSelect.value = '0';
        }
    }
    typeSelect.addEventListener('change', filterDepts);
    filterDepts();
}());

// Repeatable rows for qualifications & experiences
(function () {
    function clearRow(tr) {
        tr.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
        return tr;
    }
    document.querySelectorAll('[data-add-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const table = document.getElementById(btn.dataset.addRow);
            const body  = table.querySelector('tbody');
            const clone = clearRow(body.rows[0].cloneNode(true));
            body.appendChild(clone);
        });
    });
    document.addEventListener('click', function (e) {
        const rm = e.target.closest('.row-remove');
        if (!rm) return;
        const body = rm.closest('tbody');
        const row  = rm.closest('tr');
        if (body.rows.length > 1) {
            row.remove();
        } else {
            clearRow(row); // keep at least one blank row
        }
    });
}());
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
