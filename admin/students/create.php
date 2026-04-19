<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Add New Student';
$user       = auth_user();
$errors     = [];
clear_old();

// Load reference data
$departments  = sm_dept_data();
$all_programs = sm_program_data();
$semesters    = sm_semester_list();
$batches      = sm_batches();
$exam_titles  = sm_exam_titles();
$boards       = sm_boards();
$groups       = sm_academic_groups();
$districts    = sm_bd_districts();
$thanas       = sm_bd_thanas();

// Build lookup maps for JS
$dept_map    = [];
foreach ($departments as $d) {
    $dept_map[$d['id']] = ['faculty_label' => $d['faculty_label']];
}
$prog_map    = [];
foreach ($all_programs as $p) {
    $prog_map[$p['id']] = ['program_type' => $p['program_type'] ?? '', 'dept_id' => $p['dept_id']];
}
$thana_map = [];
foreach ($thanas as $t) {
    $thana_map[$t['district_id']][] = ['id' => $t['id'], 'name' => $t['name']];
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id_mode = $_POST['student_id_mode'] ?? 'manual';
    $student_id_in   = trim($_POST['student_id'] ?? '');
    $dept_id         = (int)($_POST['dept_id']   ?? 0);
    $program_id      = (int)($_POST['program_id'] ?? 0);
    $admitted_sem    = trim($_POST['admitted_semester'] ?? '');
    $full_name       = trim($_POST['full_name']   ?? '');
    $status          = $_POST['status']           ?? 'Active';

    // Personal
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
    $country              = trim($_POST['country']              ?? 'Bangladesh');
    $district_id          = (int)($_POST['district_id']         ?? 0);
    $thana_id             = (int)($_POST['thana_id']            ?? 0);
    $email                = trim($_POST['email']                ?? '');
    $phone                = trim($_POST['phone']                ?? '');
    $place_of_birth       = trim($_POST['place_of_birth']       ?? '');
    $sex                  = $_POST['sex']                       ?? '';
    $religion             = trim($_POST['religion']             ?? '');
    $dob                  = trim($_POST['dob']                  ?? '');
    $blood_group          = trim($_POST['blood_group']          ?? '');
    $nid                  = trim($_POST['nid']                  ?? '');
    $batch                = trim($_POST['batch']                ?? '');
    $batch_id             = (int)($_POST['batch_id']            ?? 0);
    $year                 = trim($_POST['year']                 ?? '');
    $shift                = trim($_POST['shift']                ?? '');
    $poor_meritorious     = isset($_POST['poor_meritorious'])     ? 1 : 0;
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

    // Derive faculty_label from selected department
    $dept_faculty_label = null;
    foreach ($departments as $d) {
        if ((int)$d['id'] === $dept_id) {
            $dept_faculty_label = $d['faculty_label'] ?: null;
            break;
        }
    }

    // Qualifications
    $qual_rows = [];
    if (!empty($_POST['qual'])) {
        foreach ($_POST['qual'] as $idx => $q) {
            $row = [
                'exam_title_id'        => (int)($q['exam_title_id']       ?? 0) ?: null,
                'exam_name'            => trim($q['exam_name']             ?? ''),
                'session'              => trim($q['session']               ?? ''),
                'group_id'             => (int)($q['group_id']             ?? 0) ?: null,
                'group_name'           => trim($q['group_name']            ?? ''),
                'board_id'             => (int)($q['board_id']             ?? 0) ?: null,
                'board_university'     => trim($q['board_university']      ?? ''),
                'passing_year'         => trim($q['passing_year']          ?? ''),
                'division_class_grade' => trim($q['division_class_grade']  ?? ''),
                'obtained_marks_gpa'   => trim($q['obtained_marks_gpa']    ?? ''),
            ];
            // Keep row if any meaningful field is filled
            $check = array_filter([
                $row['exam_title_id'], $row['exam_name'], $row['session'],
                $row['board_id'], $row['board_university'], $row['group_id'], $row['group_name'],
                $row['passing_year'], $row['division_class_grade'], $row['obtained_marks_gpa'],
            ]);
            if ($check) {
                $qual_rows[] = $row;
            }
        }
    }

    // ── Validate ─────────────────────────────────────────────────────────────
    if ($full_name === '')     $errors[] = 'Full name is required.';
    if ($dept_id   <= 0)      $errors[] = 'Department is required.';
    if ($admitted_sem === '')  $errors[] = 'Admitted semester is required.';

    $valid_statuses = ['Active', 'Inactive', 'Graduated', 'Dropped'];
    if (!in_array($status, $valid_statuses, true)) $status = 'Active';

    $valid_sexes = ['Male', 'Female', 'Other'];
    if (!in_array($sex, $valid_sexes, true)) $sex = '';

    // Determine student_id
    $student_id = '';
    if ($student_id_mode === 'auto') {
        if ($dept_id > 0 && $admitted_sem !== '') {
            $student_id = sm_generate_student_id($admitted_sem, $dept_id, $program_id ?: 0);
        } else {
            $errors[] = 'Department and admitted semester are required to auto-generate the student ID.';
        }
    } else {
        $student_id = $student_id_in;
        if ($student_id === '') {
            $errors[] = 'Student ID is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9\-]{1,20}$/', $student_id)) {
            $errors[] = 'Student ID must be 1–20 alphanumeric characters (digits, letters or hyphens).';
        } else {
            $exists = db()->prepare('SELECT id FROM students WHERE student_id = ?');
            $exists->execute([$student_id]);
            if ($exists->fetchColumn()) {
                $errors[] = 'Student ID "' . h($student_id) . '" is already in use.';
            }
        }
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }

    // Photo
    $photo_name = null;
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = sm_upload_photo($_FILES['photo']);
        if ($uploaded === false) {
            $errors[] = 'Invalid photo. Allowed: JPG, PNG, GIF, WEBP; max 5 MB.';
        } else {
            $photo_name = $uploaded;
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->prepare(
            'INSERT INTO students
               (student_id, dept_id, program_id, admitted_semester, batch, batch_id, year, shift,
                full_name, father_name, father_phone, father_occupation, father_yearly_income,
                mother_name, mother_phone, mother_occupation, mother_yearly_income,
                present_address, permanent_address, nationality, country,
                district_id, thana_id, faculty_label,
                email, phone,
                dob, blood_group, nid,
                place_of_birth, sex, religion, photo,
                poor_meritorious, freedom_fighter_quota,
                waiver_percent, form_fee, regi_fee, tuition_fee, misc_fee,
                project_fee, total_fee, waiver_amount, total_payable, monthly_installment,
                ref_number, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $student_id,
            $dept_id,
            $program_id ?: null,
            $admitted_sem,
            $batch          ?: null,
            $batch_id       ?: null,
            $year           ?: null,
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
            $country              ?: 'Bangladesh',
            $district_id          ?: null,
            $thana_id             ?: null,
            $dept_faculty_label,
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
            $user['id'],
        ]);
        $new_id = (int)$pdo->lastInsertId();

        // Save academic qualifications
        foreach ($qual_rows as $qi => $q) {
            $pdo->prepare(
                'INSERT INTO student_academic_qualifications
                   (student_id, exam_title_id, exam_name, session, group_id, group_name,
                    board_id, board_university,
                    passing_year, division_class_grade, obtained_marks_gpa, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $new_id,
                $q['exam_title_id'],
                $q['exam_name']            ?: null,
                $q['session']              ?: null,
                $q['group_id'],
                $q['group_name']           ?: null,
                $q['board_id'],
                $q['board_university']     ?: null,
                $q['passing_year']         ?: null,
                $q['division_class_grade'] ?: null,
                $q['obtained_marks_gpa']   ?: null,
                $qi,
            ]);
        }

        log_change('students', 'CREATE', $new_id, $full_name . ' (' . $student_id . ')',
                   null, null, null,
                   'New student added: ' . $full_name);

        flash_set('success', 'Student <strong>' . h($full_name) . '</strong> added successfully.');
        redirect(APP_URL . '/students/view.php?id=' . $new_id);
    }

    save_old($_POST);
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
            <li class="breadcrumb-item active">Add Student</li>
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
        <!-- Student ID -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Student ID <span class="text-danger">*</span>
                    <small class="text-muted fw-normal">(12 digits)</small>
                </label>
                <div class="d-flex gap-2 mb-1">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="student_id_mode" id="sid_manual"
                               value="manual" <?= (old('student_id_mode','manual') === 'manual') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sid_manual">Enter manually</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="student_id_mode" id="sid_auto"
                               value="auto" <?= (old('student_id_mode','manual') === 'auto') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sid_auto">Auto-generate</label>
                    </div>
                </div>
                <div id="sid_manual_wrap">
                    <input type="text" class="form-control" name="student_id"
                           id="student_id_input"
                           placeholder="e.g. 250102010001"
                           maxlength="12"
                           pattern="\d{12}"
                           value="<?= old('student_id') ?>">
                    <div class="form-text">Format: YY + Semester(01/02/03) + DeptID(2) + ProgID(2) + Seq(4)</div>
                </div>
                <div id="sid_auto_note" class="alert alert-info py-2 mb-0 mt-1" style="display:none;font-size:.85rem;">
                    <i class="fas fa-magic me-1"></i>
                    ID will be auto-generated based on department, program and admitted semester.
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['Active','Inactive','Graduated','Dropped'] as $s): ?>
                    <option value="<?= $s ?>" <?= old('status','Active') === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row g-3">
            <!-- Department -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="dept_search"
                           placeholder="Search department…"
                           autocomplete="off"
                           data-target="dept_id">
                    <input type="hidden" name="dept_id" id="dept_id" value="<?= old('dept_id') ?>">
                    <div class="ss-list" id="dept_list">
                        <?php foreach ($departments as $d): ?>
                        <div class="ss-item" data-value="<?= $d['id'] ?>" data-label="<?= h($d['name']) ?>"
                             data-code="<?= h($d['code']) ?>"
                             data-faculty="<?= h($d['faculty_label']) ?>">
                            <?= h($d['name']) ?> <small class="text-muted">(<?= h($d['code']) ?>)</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Faculty (auto-populated) -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Faculty</label>
                <input type="text" class="form-control" id="faculty_display"
                       value="<?= old('faculty_label') ?>" readonly
                       placeholder="Auto-filled from department" style="background:#f8f9fa;">
                <input type="hidden" name="faculty_label" id="faculty_label" value="<?= old('faculty_label') ?>">
            </div>
            <!-- Program -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Academic Program</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="prog_search"
                           placeholder="Search program…"
                           autocomplete="off"
                           data-target="program_id">
                    <input type="hidden" name="program_id" id="program_id" value="<?= old('program_id') ?>">
                    <div class="ss-list" id="prog_list">
                        <div class="ss-item" data-value="" data-label="" data-type="">— None —</div>
                        <?php foreach ($all_programs as $p): ?>
                        <div class="ss-item" data-value="<?= $p['id'] ?>" data-label="<?= h($p['program_name']) ?>"
                             data-dept="<?= $p['dept_id'] ?>"
                             data-type="<?= h($p['program_type'] ?? '') ?>">
                            <?= h($p['program_name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Program Type (auto-populated) -->
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Program Type</label>
                <input type="text" class="form-control" id="program_type_display"
                       value="<?= old('program_type') ?>" readonly
                       placeholder="Auto-detected from program" style="background:#f8f9fa;">
                <input type="hidden" name="program_type" id="program_type" value="<?= old('program_type') ?>">
            </div>
            <!-- Admitted Semester -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Admitted Semester <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="sem_search"
                           placeholder="Search semester…"
                           autocomplete="off"
                           data-target="admitted_semester">
                    <input type="hidden" name="admitted_semester" id="admitted_semester" value="<?= old('admitted_semester') ?>">
                    <div class="ss-list" id="sem_list">
                        <?php foreach ($semesters as $sem): ?>
                        <div class="ss-item" data-value="<?= h($sem) ?>" data-label="<?= h($sem) ?>"><?= h($sem) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <!-- Batch (searchable from DB) -->
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Batch</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="batch_search"
                           placeholder="Search batch…"
                           autocomplete="off"
                           data-target="batch_id">
                    <input type="hidden" name="batch_id" id="batch_id" value="<?= old('batch_id') ?>">
                    <!-- Also keep text field for manual entry -->
                    <input type="hidden" name="batch" id="batch_text" value="<?= old('batch') ?>">
                    <div class="ss-list" id="batch_list">
                        <div class="ss-item" data-value="" data-label="">— None —</div>
                        <?php foreach ($batches as $b): ?>
                        <div class="ss-item" data-value="<?= $b['id'] ?>" data-label="<?= h($b['name']) ?>">
                            <?= h($b['name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Year -->
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold">Year</label>
                <input type="text" class="form-control" name="year"
                       value="<?= old('year', date('Y')) ?>" maxlength="10" placeholder="e.g. <?= date('Y') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Shift</label>
                <select name="shift" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Day','Evening','Morning'] as $sh): ?>
                    <option value="<?= $sh ?>" <?= old('shift') === $sh ? 'selected' : '' ?>><?= $sh ?></option>
                    <?php endforeach; ?>
                </select>
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
                <input type="text" class="form-control" name="full_name" value="<?= old('full_name') ?>" required maxlength="200">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" class="form-control" name="email" value="<?= old('email') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Phone</label>
                <input type="text" class="form-control" name="phone" value="<?= old('phone') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Sex</label>
                <select name="sex" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female','Other'] as $sx): ?>
                    <option value="<?= $sx ?>" <?= old('sex') === $sx ? 'selected' : '' ?>><?= $sx ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Religion</label>
                <input type="text" class="form-control" name="religion" value="<?= old('religion') ?>" maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Nationality</label>
                <input type="text" class="form-control" name="nationality" value="<?= old('nationality','Bangladeshi') ?>" maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Country</label>
                <input type="text" class="form-control" name="country" value="<?= old('country','Bangladesh') ?>" maxlength="100">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Place of Birth</label>
                <input type="text" class="form-control" name="place_of_birth" value="<?= old('place_of_birth') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Date of Birth</label>
                <input type="date" class="form-control" name="dob" value="<?= old('dob') ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label fw-semibold">Blood Group</label>
                <select name="blood_group" class="form-select">
                    <option value="">— —</option>
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                    <option value="<?= $bg ?>" <?= old('blood_group') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">NID Number</label>
                <input type="text" class="form-control" name="nid" value="<?= old('nid') ?>" maxlength="50">
            </div>
            <!-- District (searchable) -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">District</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="district_search"
                           placeholder="Search district…" autocomplete="off" data-target="district_id">
                    <input type="hidden" name="district_id" id="district_id" value="<?= old('district_id') ?>">
                    <div class="ss-list" id="district_list">
                        <div class="ss-item" data-value="" data-label="">— None —</div>
                        <?php
                        $cur_div = '';
                        foreach ($districts as $dist):
                            if ($dist['division'] !== $cur_div) {
                                $cur_div = $dist['division'];
                        ?>
                        <div class="ss-item" data-value="" data-label="" style="font-weight:600;background:#f0f4ff;pointer-events:none;font-size:.75rem;padding:4px 12px;color:#555;">
                            — <?= h($cur_div) ?> Division —
                        </div>
                        <?php } ?>
                        <div class="ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= h($dist['name']) ?>"
                             data-division="<?= h($dist['division']) ?>">
                            <?= h($dist['name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Thana (searchable, filtered by district) -->
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Thana / Upazila</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="thana_search"
                           placeholder="Select district first…" autocomplete="off" data-target="thana_id">
                    <input type="hidden" name="thana_id" id="thana_id" value="<?= old('thana_id') ?>">
                    <div class="ss-list" id="thana_list">
                        <div class="ss-item" data-value="" data-label="" data-district="">— None —</div>
                        <?php foreach ($thanas as $th): ?>
                        <div class="ss-item" data-value="<?= $th['id'] ?>" data-label="<?= h($th['name']) ?>"
                             data-district="<?= $th['district_id'] ?>">
                            <?= h($th['name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Present Address</label>
                <textarea class="form-control" name="present_address" rows="2"><?= old('present_address') ?></textarea>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Permanent Address</label>
                <textarea class="form-control" name="permanent_address" rows="2"><?= old('permanent_address') ?></textarea>
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
                <input type="text" class="form-control" name="father_name" value="<?= old('father_name') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Phone Number</label>
                <input type="text" class="form-control" name="father_phone" value="<?= old('father_phone') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Occupation</label>
                <input type="text" class="form-control" name="father_occupation" value="<?= old('father_occupation') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Yearly Income (BDT)</label>
                <input type="number" class="form-control" name="father_yearly_income"
                       value="<?= old('father_yearly_income') ?>" min="0" step="0.01">
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
                <input type="text" class="form-control" name="mother_name" value="<?= old('mother_name') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Phone Number</label>
                <input type="text" class="form-control" name="mother_phone" value="<?= old('mother_phone') ?>" maxlength="30">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Occupation</label>
                <input type="text" class="form-control" name="mother_occupation" value="<?= old('mother_occupation') ?>" maxlength="200">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold">Yearly Income (BDT)</label>
                <input type="number" class="form-control" name="mother_yearly_income"
                       value="<?= old('mother_yearly_income') ?>" min="0" step="0.01">
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
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Upload Photo</label>
                <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
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
                           value="1" <?= old('poor_meritorious') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="poor_meritorious">Poor / Meritorious Quota</label>
                </div>
            </div>
            <div class="col-auto">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="freedom_fighter_quota" id="freedom_fighter_quota"
                           value="1" <?= old('freedom_fighter_quota') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="freedom_fighter_quota">Freedom Fighter Family Quota</label>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Waiver %</label>
                <input type="text" class="form-control" name="waiver_percent" value="<?= old('waiver_percent') ?>" maxlength="10" placeholder="e.g. 25">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Waiver Amount</label>
                <input type="number" class="form-control" name="waiver_amount" value="<?= old('waiver_amount') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Form Fee</label>
                <input type="number" class="form-control" name="form_fee" value="<?= old('form_fee') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Regi. Fee</label>
                <input type="number" class="form-control" name="regi_fee" value="<?= old('regi_fee') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Tuition Fee</label>
                <input type="number" class="form-control" name="tuition_fee" value="<?= old('tuition_fee') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Misc Fee</label>
                <input type="text" class="form-control" name="misc_fee" value="<?= old('misc_fee') ?>" maxlength="50">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Project Fee</label>
                <input type="number" class="form-control" name="project_fee" value="<?= old('project_fee') ?>" min="0">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-semibold">Total Fee</label>
                <input type="number" class="form-control" name="total_fee" value="<?= old('total_fee') ?>" min="0">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Total Payable</label>
                <input type="text" class="form-control" name="total_payable" value="<?= old('total_payable') ?>" maxlength="50">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold">Monthly Installment</label>
                <input type="text" class="form-control" name="monthly_installment" value="<?= old('monthly_installment') ?>" maxlength="50">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label fw-semibold">Ref / Receipt Number</label>
                <input type="text" class="form-control" name="ref_number" value="<?= old('ref_number') ?>" maxlength="100">
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
            <!-- Row 0 (default) -->
            <div class="qual-row mb-3" id="qual_row_0">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong style="font-size:.85rem;">Qualification #1</strong>
                </div>
                <?= sm_qual_row_html(0, [], $exam_titles, $boards, $groups) ?>
            </div>
        </div>
        <small class="text-muted">Click <strong>+ Add Row</strong> to add more qualifications.</small>
    </div>
</div>

<!-- Submit -->
<div class="d-flex gap-3 mb-5">
    <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;">
        <i class="fas fa-save me-1"></i> Save Student
    </button>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary px-4" style="border-radius:10px;">
        Cancel
    </a>
</div>

</form>

<script>
// ── Reference data for JS ─────────────────────────────────────────────────────
var DEPT_MAP = <?= json_encode($dept_map, JSON_UNESCAPED_UNICODE) ?>;
var PROG_MAP = <?= json_encode($prog_map, JSON_UNESCAPED_UNICODE) ?>;
var EXAM_DATA = <?= json_encode(array_values($exam_titles), JSON_UNESCAPED_UNICODE) ?>;
var BOARD_DATA = <?= json_encode(array_values($boards), JSON_UNESCAPED_UNICODE) ?>;
var GROUP_DATA = <?= json_encode(array_values($groups), JSON_UNESCAPED_UNICODE) ?>;
var THANA_MAP = <?= json_encode($thana_map, JSON_UNESCAPED_UNICODE) ?>;

// ── Searchable select widget ──────────────────────────────────────────────────
function initSearchableSelect(wrap) {
    var input   = wrap.querySelector('.ss-trigger');
    if (!input) return;
    var targetId = input.dataset.target;
    var targetEl = document.getElementById(targetId);
    var list     = wrap.querySelector('.ss-list');
    var items    = Array.from(list.querySelectorAll('.ss-item'));

    var currentVal = targetEl ? targetEl.value : '';
    if (currentVal) {
        var match = items.find(function(i){ return String(i.dataset.value) === String(currentVal); });
        if (match) input.value = match.dataset.label;
    }

    input.addEventListener('focus', function() { list.classList.add('open'); filterList(''); });
    input.addEventListener('input', function() { filterList(this.value); list.classList.add('open'); });

    function filterList(q) {
        q = q.toLowerCase();
        items.forEach(function(item) {
            var noVal = item.dataset.value === '' && item.style.pointerEvents === 'none';
            item.style.display = (noVal || item.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    }

    items.forEach(function(item) {
        if (item.style.pointerEvents === 'none') return; // division headers
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            if (targetEl) targetEl.value = item.dataset.value;
            input.value = item.dataset.label;
            list.classList.remove('open');

            if (targetId === 'dept_id') {
                updateFaculty(item.dataset.value);
                filterProgsByDept(item.dataset.value);
            }
            if (targetId === 'program_id') {
                updateProgramType(item.dataset.value, item.dataset.type);
            }
            if (targetId === 'batch_id') {
                document.getElementById('batch_text').value = item.dataset.label;
            }
            if (targetId === 'district_id') {
                filterThanasByDistrict(item.dataset.value);
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) list.classList.remove('open');
    });
}

document.querySelectorAll('.searchable-select-wrap').forEach(function(wrap) {
    initSearchableSelect(wrap);
});

function updateFaculty(deptId) {
    var info = DEPT_MAP[deptId] || {};
    var label = info.faculty_label || '';
    document.getElementById('faculty_display').value = label;
    document.getElementById('faculty_label').value   = label;
}

function updateProgramType(progId, typeFromData) {
    var type = typeFromData || (PROG_MAP[progId] ? PROG_MAP[progId].program_type : '') || '';
    document.getElementById('program_type_display').value = type;
    document.getElementById('program_type').value          = type;
}

function filterProgsByDept(deptId) {
    var progList  = document.getElementById('prog_list');
    var progInput = document.getElementById('prog_search');
    var progVal   = document.getElementById('program_id');
    progInput.value = '';
    progVal.value   = '';
    document.getElementById('program_type_display').value = '';
    document.getElementById('program_type').value = '';
    Array.from(progList.querySelectorAll('.ss-item')).forEach(function(item) {
        if (!item.dataset.dept || item.dataset.dept === deptId || item.dataset.value === '') {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function filterThanasByDistrict(districtId) {
    var thanaList  = document.getElementById('thana_list');
    var thanaInput = document.getElementById('thana_search');
    var thanaVal   = document.getElementById('thana_id');
    thanaInput.value = districtId ? '' : '';
    thanaInput.placeholder = districtId ? 'Search thana…' : 'Select district first…';
    thanaVal.value = '';
    Array.from(thanaList.querySelectorAll('.ss-item')).forEach(function(item) {
        var d = item.dataset.district;
        item.style.display = (!d || d === '' || d === districtId) ? '' : 'none';
    });
}

// ── Student ID mode toggle ────────────────────────────────────────────────────
function updateSidMode() {
    var mode = document.querySelector('input[name="student_id_mode"]:checked').value;
    document.getElementById('sid_manual_wrap').style.display = mode === 'manual' ? '' : 'none';
    document.getElementById('sid_auto_note').style.display   = mode === 'auto'   ? '' : 'none';
}
document.querySelectorAll('input[name="student_id_mode"]').forEach(function(r) {
    r.addEventListener('change', updateSidMode);
});
updateSidMode();

// ── Qual row searchable select (within qual rows) ─────────────────────────────
function initQualSearchableSelects(rowEl) {
    rowEl.querySelectorAll('.qual-ss-wrap').forEach(function(wrap) {
        var input    = wrap.querySelector('.qual-ss-trigger');
        if (!input) return;
        var targetId = input.dataset.target;
        var targetEl = document.getElementById(targetId);
        var list     = wrap.querySelector('.qual-ss-list');
        var items    = Array.from(list.querySelectorAll('.qual-ss-item'));

        input.addEventListener('focus',  function() { list.style.display = 'block'; filterQList(''); });
        input.addEventListener('input',  function() { filterQList(this.value); list.style.display = 'block'; });

        function filterQList(q) {
            q = q.toLowerCase();
            items.forEach(function(item) {
                item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }

        items.forEach(function(item) {
            item.addEventListener('mousedown', function(e) {
                e.preventDefault();
                if (targetEl) targetEl.value = item.dataset.value;
                input.value = item.dataset.label;
                list.style.display = 'none';

                // Sync text fallback field for legacy data
                var prefix = targetId.replace(/_exam_id$/, '').replace(/_board_id$/, '').replace(/_grp_id$/, '');
                if (targetId.endsWith('_exam_id')) {
                    var nameEl = document.getElementById(prefix + '_exam_name');
                    if (nameEl) nameEl.value = item.dataset.label;
                } else if (targetId.endsWith('_board_id')) {
                    var nameEl = document.getElementById(prefix + '_board_name');
                    if (nameEl) nameEl.value = item.dataset.label;
                } else if (targetId.endsWith('_grp_id')) {
                    var nameEl = document.getElementById(prefix + '_grp_name');
                    if (nameEl) nameEl.value = item.dataset.label;
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!wrap.contains(e.target)) list.style.display = 'none';
        });
    });
}

// Init existing qual rows
document.querySelectorAll('.qual-row').forEach(function(row) {
    initQualSearchableSelects(row);
});

// ── Dynamic qualification rows ────────────────────────────────────────────────
var qualCount = 1;
document.getElementById('add_qual_btn').addEventListener('click', function() {
    var idx  = qualCount++;
    var uid  = 'q' + idx;
    var row  = document.createElement('div');
    row.className = 'qual-row mb-3';
    row.id = 'qual_row_' + idx;

    var examOpts  = '<div class="qual-ss-item" data-value="" data-label="" style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None (type manually) —</div>'
        + EXAM_DATA.map(function(e) {
            return '<div class="qual-ss-item" data-value="' + e.id + '" data-label="' + escHtml(e.name) + '" style="padding:5px 10px;cursor:pointer;font-size:.8rem;">'
                + escHtml(e.name) + (e.short_name ? ' <small style="color:#999;">(' + escHtml(e.short_name) + ')</small>' : '') + '</div>';
        }).join('');

    var boardOpts = '<div class="qual-ss-item" data-value="" data-label="" style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None (type manually) —</div>'
        + BOARD_DATA.map(function(b) {
            return '<div class="qual-ss-item" data-value="' + b.id + '" data-label="' + escHtml(b.name) + '" style="padding:5px 10px;cursor:pointer;font-size:.8rem;">'
                + escHtml(b.name) + (b.short_name ? ' <small style="color:#999;">(' + escHtml(b.short_name) + ')</small>' : '') + '</div>';
        }).join('');

    var groupOpts = '<div class="qual-ss-item" data-value="" data-label="" style="padding:5px 10px;cursor:pointer;font-size:.8rem;color:#999;">— None —</div>'
        + GROUP_DATA.map(function(g) {
            return '<div class="qual-ss-item" data-value="' + g.id + '" data-label="' + escHtml(g.name) + '" style="padding:5px 10px;cursor:pointer;font-size:.8rem;">' + escHtml(g.name) + '</div>';
        }).join('');

    var listStyle = 'position:absolute;top:100%;left:0;right:0;max-height:180px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1060;display:none;';

    row.innerHTML =
        '<div class="d-flex justify-content-between align-items-center mb-2">'
        + '<strong style="font-size:.85rem;">Qualification #' + (idx + 1) + '</strong>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;" '
        + 'onclick="this.closest(\'.qual-row\').remove()"><i class="fas fa-times"></i></button></div>'
        + '<input type="hidden" name="qual[' + idx + '][id]" value="0">'
        + '<div class="row g-2">'
        // Exam Title
        + '<div class="col-12 col-md-4"><label class="form-label" style="font-size:.8rem;">Exam Title</label>'
        + '<div class="qual-ss-wrap" style="position:relative;">'
        + '<input type="text" class="form-control form-control-sm qual-ss-trigger" id="' + uid + '_exam_txt" placeholder="SSC, HSC, O Level…" autocomplete="off" data-target="' + uid + '_exam_id">'
        + '<input type="hidden" name="qual[' + idx + '][exam_title_id]" id="' + uid + '_exam_id" value="">'
        + '<input type="hidden" name="qual[' + idx + '][exam_name]" id="' + uid + '_exam_name" value="">'
        + '<div class="qual-ss-list" style="' + listStyle + '">' + examOpts + '</div>'
        + '</div></div>'
        // Session
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Session</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][session]" placeholder="2018-2019"></div>'
        // Group
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Academic Group</label>'
        + '<div class="qual-ss-wrap" style="position:relative;">'
        + '<input type="text" class="form-control form-control-sm qual-ss-trigger" id="' + uid + '_grp_txt" placeholder="Science, Arts…" autocomplete="off" data-target="' + uid + '_grp_id">'
        + '<input type="hidden" name="qual[' + idx + '][group_id]" id="' + uid + '_grp_id" value="">'
        + '<input type="hidden" name="qual[' + idx + '][group_name]" id="' + uid + '_grp_name" value="">'
        + '<div class="qual-ss-list" style="' + listStyle + '">' + groupOpts + '</div>'
        + '</div></div>'
        // Board
        + '<div class="col-12 col-md-4"><label class="form-label" style="font-size:.8rem;">Academic Board / University</label>'
        + '<div class="qual-ss-wrap" style="position:relative;">'
        + '<input type="text" class="form-control form-control-sm qual-ss-trigger" id="' + uid + '_board_txt" placeholder="Dhaka Board, NU…" autocomplete="off" data-target="' + uid + '_board_id">'
        + '<input type="hidden" name="qual[' + idx + '][board_id]" id="' + uid + '_board_id" value="">'
        + '<input type="hidden" name="qual[' + idx + '][board_university]" id="' + uid + '_board_name" value="">'
        + '<div class="qual-ss-list" style="' + listStyle + '">' + boardOpts + '</div>'
        + '</div></div>'
        // Passing Year
        + '<div class="col-6 col-md-2"><label class="form-label" style="font-size:.8rem;">Year of Passing</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][passing_year]" placeholder="2019"></div>'
        // Grade
        + '<div class="col-6 col-md-3"><label class="form-label" style="font-size:.8rem;">Division / Class / Grade</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][division_class_grade]" placeholder="A+"></div>'
        // GPA
        + '<div class="col-12 col-md-3"><label class="form-label" style="font-size:.8rem;">Obtained Marks / GPA / CGPA</label>'
        + '<input type="text" class="form-control form-control-sm" name="qual[' + idx + '][obtained_marks_gpa]" placeholder="5.00"></div>'
        + '</div>';

    document.getElementById('qual_container').appendChild(row);
    initQualSearchableSelects(row);
});

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
