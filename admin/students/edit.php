<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$student = sm_get_student($id);
$user    = auth_user();
$errors  = [];

$page_title = 'Edit Student – ' . $student['full_name'];

$departments  = db()->query(
    'SELECT id, name, code FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$all_programs = db()->query(
    'SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
)->fetchAll();

$semesters = sm_semester_list();

$qualifications = db()->prepare(
    'SELECT * FROM student_academic_qualifications WHERE student_id = ? ORDER BY sort_order ASC, id ASC'
);
$qualifications->execute([$id]);
$qualifications = $qualifications->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id_new  = trim($_POST['student_id']          ?? '');
    $dept_id         = (int)($_POST['dept_id']            ?? 0);
    $program_id      = (int)($_POST['program_id']         ?? 0);
    $admitted_sem    = trim($_POST['admitted_semester']    ?? '');
    $full_name       = trim($_POST['full_name']            ?? '');
    $status          = $_POST['status']                    ?? 'Active';

    $father_name          = trim($_POST['father_name']          ?? '');
    $father_phone         = trim($_POST['father_phone']         ?? '');
    $father_occupation    = trim($_POST['father_occupation']    ?? '');
    $father_yearly_income = trim($_POST['father_yearly_income'] ?? '');
    $mother_name          = trim($_POST['mother_name']          ?? '');
    $mother_phone         = trim($_POST['mother_phone']         ?? '');
    $mother_occupation    = trim($_POST['mother_occupation']    ?? '');
    $mother_yearly_income = trim($_POST['mother_yearly_income'] ?? '');
    $present_address      = trim($_POST['present_address']      ?? '');
    $permanent_address    = trim($_POST['permanent_address']    ?? '');
    $nationality          = trim($_POST['nationality']          ?? '');
    $email                = trim($_POST['email']                ?? '');
    $phone                = trim($_POST['phone']                ?? '');
    $place_of_birth       = trim($_POST['place_of_birth']       ?? '');
    $sex                  = $_POST['sex']                       ?? '';
    $religion             = trim($_POST['religion']             ?? '');
    $dob                  = trim($_POST['dob']                  ?? '');
    $blood_group          = trim($_POST['blood_group']          ?? '');
    $nid                  = trim($_POST['nid']                  ?? '');
    $batch                = trim($_POST['batch']                ?? '');
    $shift                = trim($_POST['shift']                ?? '');
    $poor_meritorious     = isset($_POST['poor_meritorious'])      ? 1 : 0;
    $freedom_fighter      = isset($_POST['freedom_fighter_quota']) ? 1 : 0;
    $waiver_percent       = trim($_POST['waiver_percent']       ?? '');
    $form_fee             = trim($_POST['form_fee']             ?? '');
    $regi_fee             = trim($_POST['regi_fee']             ?? '');
    $tuition_fee          = trim($_POST['tuition_fee']          ?? '');
    $misc_fee             = trim($_POST['misc_fee']             ?? '');
    $project_fee          = trim($_POST['project_fee']          ?? '');
    $total_fee            = trim($_POST['total_fee']            ?? '');
    $waiver_amount        = trim($_POST['waiver_amount']        ?? '');
    $total_payable        = trim($_POST['total_payable']        ?? '');
    $monthly_installment  = trim($_POST['monthly_installment']  ?? '');
    $ref_number           = trim($_POST['ref_number']           ?? '');

    $qual_rows = [];
    if (!empty($_POST['qual'])) {
        foreach ($_POST['qual'] as $qi => $q) {
            $row = [
                'id'                   => (int)($q['id'] ?? 0),
                'exam_name'            => trim($q['exam_name']            ?? ''),
                'session'              => trim($q['session']              ?? ''),
                'group_name'           => trim($q['group_name']           ?? ''),
                'board_university'     => trim($q['board_university']     ?? ''),
                'passing_year'         => trim($q['passing_year']         ?? ''),
                'division_class_grade' => trim($q['division_class_grade'] ?? ''),
                'obtained_marks_gpa'   => trim($q['obtained_marks_gpa']   ?? ''),
            ];
            if (array_filter(array_diff_key($row, ['id' => true]))) {
                $qual_rows[] = $row;
            }
        }
    }

    // ── Validate ─────────────────────────────────────────────────────────────
    if ($full_name === '')    $errors[] = 'Full name is required.';
    if ($dept_id   <= 0)     $errors[] = 'Department is required.';
    if ($admitted_sem === '') $errors[] = 'Admitted semester is required.';

    $valid_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped'];
    if (!in_array($status, $valid_statuses, true)) $status = 'Active';

    $valid_sexes = ['Male', 'Female', 'Other'];
    if (!in_array($sex, $valid_sexes, true)) $sex = '';

    if ($student_id_new === '') {
        $errors[] = 'Student ID is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $student_id_new)) {
        $errors[] = 'Student ID must be 1–20 alphanumeric characters (digits, letters or hyphens).';
    } elseif ($student_id_new !== $student['student_id']) {
        $dup = db()->prepare('SELECT id FROM students WHERE student_id = ? AND id != ?');
        $dup->execute([$student_id_new, $id]);
        if ($dup->fetchColumn()) {
            $errors[] = 'Student ID "' . h($student_id_new) . '" is already in use.';
        }
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    // Photo
    $photo_name = $student['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = sm_upload_photo($_FILES['photo']);
        if ($uploaded === false) {
            $errors[] = 'Invalid photo. Allowed: JPG, PNG, GIF, WEBP; max 5 MB.';
        } else {
            // Delete old photo
            if ($photo_name) {
                $old_path = UPLOAD_DIR . '/students/photos/' . $photo_name;
                if (is_file($old_path)) @unlink($old_path);
            }
            $photo_name = $uploaded;
        }
    }
    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        if ($photo_name) {
            $old_path = UPLOAD_DIR . '/students/photos/' . $photo_name;
            if (is_file($old_path)) @unlink($old_path);
        }
        $photo_name = null;
    }

    if (empty($errors)) {
        $old_name = $student['full_name'];
        $pdo      = db();
        $pdo->prepare(
            'UPDATE students SET
               student_id = ?, dept_id = ?, program_id = ?, admitted_semester = ?,
               batch = ?, shift = ?,
               full_name = ?, father_name = ?, father_phone = ?, father_occupation = ?,
               father_yearly_income = ?,
               mother_name = ?, mother_phone = ?, mother_occupation = ?,
               mother_yearly_income = ?,
               present_address = ?, permanent_address = ?, nationality = ?,
               email = ?, phone = ?,
               dob = ?, blood_group = ?, nid = ?,
               place_of_birth = ?, sex = ?, religion = ?,
               photo = ?,
               poor_meritorious = ?, freedom_fighter_quota = ?,
               waiver_percent = ?, form_fee = ?, regi_fee = ?, tuition_fee = ?,
               misc_fee = ?, project_fee = ?, total_fee = ?, waiver_amount = ?,
               total_payable = ?, monthly_installment = ?, ref_number = ?,
               status = ?
             WHERE id = ?'
        )->execute([
            $student_id_new,
            $dept_id,
            $program_id ?: null,
            $admitted_sem,
            $batch          ?: null,
            $shift          ?: null,
            $full_name,
            $father_name          ?: null,
            $father_phone         ?: null,
            $father_occupation    ?: null,
            $father_yearly_income !== '' ? (float)$father_yearly_income : null,
            $mother_name          ?: null,
            $mother_phone         ?: null,
            $mother_occupation    ?: null,
            $mother_yearly_income !== '' ? (float)$mother_yearly_income : null,
            $present_address      ?: null,
            $permanent_address    ?: null,
            $nationality          ?: null,
            $email                ?: null,
            $phone                ?: null,
            $dob                  !== '' ? $dob : null,
            $blood_group          ?: null,
            $nid                  ?: null,
            $place_of_birth       ?: null,
            $sex                  ?: null,
            $religion             ?: null,
            $photo_name,
            $poor_meritorious,
            $freedom_fighter,
            $waiver_percent       ?: null,
            $form_fee             !== '' ? (int)$form_fee    : null,
            $regi_fee             !== '' ? (int)$regi_fee    : null,
            $tuition_fee          !== '' ? (int)$tuition_fee : null,
            $misc_fee             ?: null,
            $project_fee          !== '' ? (int)$project_fee : null,
            $total_fee            !== '' ? (int)$total_fee   : null,
            $waiver_amount        !== '' ? (int)$waiver_amount : null,
            $total_payable        ?: null,
            $monthly_installment  ?: null,
            $ref_number           ?: null,
            $status,
            $id,
        ]);

        // Replace qualifications: delete existing, re-insert
        $pdo->prepare('DELETE FROM student_academic_qualifications WHERE student_id = ?')->execute([$id]);
        foreach ($qual_rows as $qi => $q) {
            $pdo->prepare(
                'INSERT INTO student_academic_qualifications
                   (student_id, exam_name, session, group_name, board_university,
                    passing_year, division_class_grade, obtained_marks_gpa, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id,
                $q['exam_name']            ?: null,
                $q['session']              ?: null,
                $q['group_name']           ?: null,
                $q['board_university']     ?: null,
                $q['passing_year']         ?: null,
                $q['division_class_grade'] ?: null,
                $q['obtained_marks_gpa']   ?: null,
                $qi,
            ]);
        }

        log_change('students', 'UPDATE', $id, $full_name . ' (' . $student_id_new . ')',
                   null, null, null,
                   'Student updated: ' . $full_name);

        flash_set('success', 'Student <strong>' . h($full_name) . '</strong> updated successfully.');
        redirect(APP_URL . '/students/view.php?id=' . $id);
    }
}

// Merge POST back into $student for re-displaying form on error
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $student = array_merge($student, $_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.searchable-select-wrap { position: relative; }
.searchable-select-wrap .ss-list {
    position: absolute; top: 100%; left: 0; right: 0;
    max-height: 220px; overflow-y: auto;
    background: #fff; border: 1px solid #dee2e6; border-top: 0;
    border-radius: 0 0 6px 6px; z-index: 1050; display: none;
}
.searchable-select-wrap .ss-list.open { display: block; }
.searchable-select-wrap .ss-list .ss-item {
    padding: 7px 12px; cursor: pointer; font-size: .875rem;
}
.searchable-select-wrap .ss-list .ss-item:hover,
.searchable-select-wrap .ss-list .ss-item.active { background: #f0f4ff; }
.qual-row { background: #fafafa; border: 1px solid #e8eaf0; border-radius: 10px; padding: 16px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/view.php?id=<?= $id ?>"><?= h($student['full_name']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data">
<?= csrf_field() ?>

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 – ENROLLMENT INFO
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-badge me-2 text-muted"></i>Enrollment Information</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Student ID <span class="text-danger">*</span>
                    <small class="text-muted fw-normal">(12 digits)</small>
                </label>
                <input type="text" class="form-control" name="student_id"
                       maxlength="12" pattern="\d{12}"
                       value="<?= h($student['student_id']) ?>" required>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['Active','Inactive','Graduated','Dropped'] as $s): ?>
                    <option value="<?= $s ?>" <?= $student['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="dept_search"
                           placeholder="Search department…"
                           autocomplete="off"
                           data-target="dept_id"
                           data-initial-value="<?= $student['dept_id'] ?>">
                    <input type="hidden" name="dept_id" id="dept_id" value="<?= $student['dept_id'] ?>">
                    <div class="ss-list" id="dept_list">
                        <?php foreach ($departments as $d): ?>
                        <div class="ss-item" data-value="<?= $d['id'] ?>" data-label="<?= h($d['name']) ?>"
                             data-code="<?= h($d['code']) ?>">
                            <?= h($d['name']) ?> <small class="text-muted">(<?= h($d['code']) ?>)</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Program</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="prog_search"
                           placeholder="Search program…"
                           autocomplete="off"
                           data-target="program_id"
                           data-initial-value="<?= $student['program_id'] ?>">
                    <input type="hidden" name="program_id" id="program_id" value="<?= $student['program_id'] ?>">
                    <div class="ss-list" id="prog_list">
                        <div class="ss-item" data-value="" data-label="">— None —</div>
                        <?php foreach ($all_programs as $p): ?>
                        <div class="ss-item" data-value="<?= $p['id'] ?>" data-label="<?= h($p['program_name']) ?>"
                             data-dept="<?= $p['dept_id'] ?>">
                            <?= h($p['program_name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Admitted Semester <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="sem_search"
                           placeholder="Search semester…"
                           autocomplete="off"
                           data-target="admitted_semester"
                           data-initial-value="<?= h($student['admitted_semester']) ?>">
                    <input type="hidden" name="admitted_semester" id="admitted_semester"
                           value="<?= h($student['admitted_semester']) ?>">
                    <div class="ss-list" id="sem_list">
                        <?php foreach ($semesters as $sem): ?>
                        <div class="ss-item" data-value="<?= h($sem) ?>" data-label="<?= h($sem) ?>"><?= h($sem) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Batch</label>
                <input type="text" class="form-control" name="batch"
                       value="<?= h($student['batch'] ?? '') ?>" maxlength="50" placeholder="e.g. 35th">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Shift</label>
                <select name="shift" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Day','Evening','Morning'] as $sh): ?>
                    <option value="<?= $sh ?>" <?= ($student['shift'] ?? '') === $sh ? 'selected' : '' ?>><?= $sh ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 2 – PERSONAL INFORMATION
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Personal Information</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="full_name"
                       value="<?= h($student['full_name']) ?>" required maxlength="200">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" class="form-control" name="email"
                       value="<?= h($student['email'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Phone</label>
                <input type="text" class="form-control" name="phone"
                       value="<?= h($student['phone'] ?? '') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Sex</label>
                <select name="sex" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female','Other'] as $sx): ?>
                    <option value="<?= $sx ?>" <?= ($student['sex'] ?? '') === $sx ? 'selected' : '' ?>><?= $sx ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Religion</label>
                <input type="text" class="form-control" name="religion"
                       value="<?= h($student['religion'] ?? '') ?>" maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Nationality</label>
                <input type="text" class="form-control" name="nationality"
                       value="<?= h($student['nationality'] ?? '') ?>" maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Place of Birth</label>
                <input type="text" class="form-control" name="place_of_birth"
                       value="<?= h($student['place_of_birth'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Date of Birth</label>
                <input type="date" class="form-control" name="dob"
                       value="<?= h($student['dob'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold">Blood Group</label>
                <select name="blood_group" class="form-select">
                    <option value="">— —</option>
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                    <option value="<?= $bg ?>" <?= ($student['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">NID Number</label>
                <input type="text" class="form-control" name="nid"
                       value="<?= h($student['nid'] ?? '') ?>" maxlength="50">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Present Address</label>
                <textarea class="form-control" name="present_address" rows="2"><?= h($student['present_address'] ?? '') ?></textarea>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Permanent Address</label>
                <textarea class="form-control" name="permanent_address" rows="2"><?= h($student['permanent_address'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 3 – FATHER'S INFO
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-male me-2 text-muted"></i>Father's Information</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Father's Name</label>
                <input type="text" class="form-control" name="father_name"
                       value="<?= h($student['father_name'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Phone Number</label>
                <input type="text" class="form-control" name="father_phone"
                       value="<?= h($student['father_phone'] ?? '') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Occupation</label>
                <input type="text" class="form-control" name="father_occupation"
                       value="<?= h($student['father_occupation'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Yearly Income (BDT)</label>
                <input type="number" class="form-control" name="father_yearly_income"
                       value="<?= h($student['father_yearly_income'] ?? '') ?>" min="0" step="0.01">
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 4 – MOTHER'S INFO
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-female me-2 text-muted"></i>Mother's Information</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Mother's Name</label>
                <input type="text" class="form-control" name="mother_name"
                       value="<?= h($student['mother_name'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Phone Number</label>
                <input type="text" class="form-control" name="mother_phone"
                       value="<?= h($student['mother_phone'] ?? '') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Occupation</label>
                <input type="text" class="form-control" name="mother_occupation"
                       value="<?= h($student['mother_occupation'] ?? '') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Yearly Income (BDT)</label>
                <input type="number" class="form-control" name="mother_yearly_income"
                       value="<?= h($student['mother_yearly_income'] ?? '') ?>" min="0" step="0.01">
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 5 – PHOTO
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-camera me-2 text-muted"></i>Photo</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3 align-items-end">
            <?php if ($student['photo']): ?>
            <div class="col-auto">
                <img src="<?= sm_photo_url($student['photo']) ?>"
                     alt="Photo" style="width:80px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #dee2e6;">
            </div>
            <div class="col-auto">
                <label class="form-check-label d-flex align-items-center gap-2">
                    <input type="checkbox" name="remove_photo" value="1" class="form-check-input">
                    Remove current photo
                </label>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold"><?= $student['photo'] ? 'Replace Photo' : 'Upload Photo' ?></label>
                <input type="file" class="form-control" name="photo"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <div class="form-text">JPG, PNG, GIF or WEBP — max 5 MB</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 5b – QUOTA & FEE INFORMATION
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-money-bill-wave me-2 text-muted"></i>Quota &amp; Fee Information</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3 mb-3">
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="poor_meritorious" id="poor_meritorious"
                           value="1" <?= !empty($student['poor_meritorious']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="poor_meritorious">Poor / Meritorious Quota</label>
                </div>
            </div>
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="freedom_fighter_quota" id="freedom_fighter_quota"
                           value="1" <?= !empty($student['freedom_fighter_quota']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="freedom_fighter_quota">Freedom Fighter Family Quota</label>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Waiver %</label>
                <input type="text" class="form-control" name="waiver_percent"
                       value="<?= h($student['waiver_percent'] ?? '') ?>" maxlength="10">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Waiver Amount</label>
                <input type="number" class="form-control" name="waiver_amount"
                       value="<?= h($student['waiver_amount'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Form Fee</label>
                <input type="number" class="form-control" name="form_fee"
                       value="<?= h($student['form_fee'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Regi. Fee</label>
                <input type="number" class="form-control" name="regi_fee"
                       value="<?= h($student['regi_fee'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Tuition Fee</label>
                <input type="number" class="form-control" name="tuition_fee"
                       value="<?= h($student['tuition_fee'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Misc Fee</label>
                <input type="text" class="form-control" name="misc_fee"
                       value="<?= h($student['misc_fee'] ?? '') ?>" maxlength="50">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Project Fee</label>
                <input type="number" class="form-control" name="project_fee"
                       value="<?= h($student['project_fee'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Total Fee</label>
                <input type="number" class="form-control" name="total_fee"
                       value="<?= h($student['total_fee'] ?? '') ?>" min="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Total Payable</label>
                <input type="text" class="form-control" name="total_payable"
                       value="<?= h($student['total_payable'] ?? '') ?>" maxlength="50">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Monthly Installment</label>
                <input type="text" class="form-control" name="monthly_installment"
                       value="<?= h($student['monthly_installment'] ?? '') ?>" maxlength="50">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Ref / Receipt Number</label>
                <input type="text" class="form-control" name="ref_number"
                       value="<?= h($student['ref_number'] ?? '') ?>" maxlength="100">
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 6 – ACADEMIC QUALIFICATIONS
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-muted"></i>Academic Qualifications</h6>
        <button type="button" class="btn btn-sm btn-outline-primary" id="add_qual_btn" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add Row
        </button>
    </div>
    <div class="card-body px-4 py-3">
        <div id="qual_container">
            <?php if (empty($qualifications)): ?>
            <!-- blank starter row -->
            <div class="qual-row mb-3" id="qual_row_0">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong style="font-size:.85rem;">Qualification #1</strong>
                </div>
                <?= sm_qual_row_html(0, []) ?>
            </div>
            <?php else: ?>
            <?php foreach ($qualifications as $qi => $q): ?>
            <div class="qual-row mb-3" id="qual_row_<?= $qi ?>">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong style="font-size:.85rem;">Qualification #<?= $qi + 1 ?></strong>
                    <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                            onclick="this.closest('.qual-row').remove()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?= sm_qual_row_html($qi, $q) ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <small class="text-muted">Click <strong>+ Add Row</strong> to add more qualifications.</small>
    </div>
</div>

<!-- Submit -->
<div class="d-flex gap-3 mb-5">
    <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;">
        <i class="fas fa-save me-1"></i> Update Student
    </button>
    <a href="<?= APP_URL ?>/students/view.php?id=<?= $id ?>" class="btn btn-outline-secondary px-4" style="border-radius:10px;">
        Cancel
    </a>
</div>

</form>

<script>
// ── Searchable select widget ──────────────────────────────────────────────────
document.querySelectorAll('.ss-trigger').forEach(function(input) {
    var targetId  = input.dataset.target;
    var targetEl  = document.getElementById(targetId);
    var wrap      = input.closest('.searchable-select-wrap');
    var list      = wrap.querySelector('.ss-list');
    var items     = Array.from(list.querySelectorAll('.ss-item'));

    // Pre-fill display value
    var currentVal = input.dataset.initialValue || targetEl.value;
    if (currentVal) {
        var match = items.find(function(i){ return String(i.dataset.value) === String(currentVal); });
        if (match) input.value = match.dataset.label;
    }

    input.addEventListener('focus', function() { list.classList.add('open'); filterList(''); });
    input.addEventListener('input', function() { filterList(this.value); list.classList.add('open'); });

    function filterList(q) {
        q = q.toLowerCase();
        items.forEach(function(item) {
            item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    items.forEach(function(item) {
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            targetEl.value = item.dataset.value;
            input.value    = item.dataset.label;
            list.classList.remove('open');
            if (targetId === 'dept_id') filterProgsByDept(item.dataset.value);
        });
    });

    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) list.classList.remove('open');
    });
});

function filterProgsByDept(deptId) {
    var progList  = document.getElementById('prog_list');
    var progInput = document.getElementById('prog_search');
    var progVal   = document.getElementById('program_id');
    progInput.value = '';
    progVal.value   = '';
    Array.from(progList.querySelectorAll('.ss-item')).forEach(function(item) {
        item.style.display = (!item.dataset.dept || item.dataset.dept === deptId || item.dataset.value === '') ? '' : 'none';
    });
}

// ── Dynamic qualification rows ────────────────────────────────────────────────
var qualCount = <?= max(count($qualifications), 1) ?>;
document.getElementById('add_qual_btn').addEventListener('click', function() {
    var idx = qualCount++;
    var row = document.createElement('div');
    row.className = 'qual-row mb-3';
    row.id = 'qual_row_' + idx;
    row.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-2">'
        + '<strong style="font-size:.85rem;">Qualification #' + (idx + 1) + '</strong>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;" '
        + 'onclick="this.closest(\'.qual-row\').remove()"><i class="fas fa-times"></i></button></div>'
        + '<input type="hidden" name="qual[' + idx + '][id]" value="0">'
        + '<div class="row g-2">'
        + '<div class="col-12 col-md-4"><label class="form-label" style="font-size:.8rem;">Exam Name</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][exam_name]" placeholder="e.g. SSC, HSC, B.Sc."></div>'
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Session</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][session]" placeholder="2018-2019"></div>'
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Group</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][group_name]" placeholder="Science"></div>'
        + '<div class="col-12 col-md-4"><label class="form-label" style="font-size:.8rem;">Board / University</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][board_university]" placeholder="Dhaka Board"></div>'
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Year of Passing</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][passing_year]" placeholder="2019"></div>'
        + '<div class="col-6 col-md-3"><label class="form-label" style="font-size:.8rem;">Division / Class / Grade</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][division_class_grade]" placeholder="A+"></div>'
        + '<div class="col-12 col-md-3"><label class="form-label" style="font-size:.8rem;">Obtained Marks / GPA / CGPA</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][obtained_marks_gpa]" placeholder="5.00"></div>'
        + '</div>';
    document.getElementById('qual_container').appendChild(row);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
