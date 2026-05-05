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
    $semester_type        = trim($_POST['semester_type']        ?? '');
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

    $valid_semester_types = ['bi_semester', 'trimester', ''];
    if (!in_array($semester_type, $valid_semester_types, true)) $semester_type = '';

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
               (student_id, dept_id, program_id, admitted_semester, semester_type, batch, batch_id, year, shift,
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
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $student_id,
            $dept_id,
            $program_id ?: null,
            $admitted_sem,
            $semester_type    ?: null,
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
/* ── Searchable Select ──────────────────────────────────────────────────────── */
.searchable-select-wrap { position: relative; }
.searchable-select-wrap .ss-list {
    position: absolute; top: 100%; left: 0; right: 0;
    max-height: 220px; overflow-y: auto;
    background: #fff; border: 1px solid #dee2e6; border-top: 0;
    border-radius: 0 0 8px 8px; z-index: 1050; display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}
.searchable-select-wrap .ss-list.open { display: block; }
.searchable-select-wrap .ss-list .ss-item {
    padding: 8px 14px; cursor: pointer; font-size: .875rem;
    transition: background .12s;
}
.searchable-select-wrap .ss-list .ss-item:hover,
.searchable-select-wrap .ss-list .ss-item.active { background: #eef2ff; color: #3730a3; }

/* ── Section Cards ──────────────────────────────────────────────────────────── */
.form-section { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,.06); margin-bottom: 1.5rem; overflow: visible; }
.form-section .card-header {
    border-radius: 14px 14px 0 0 !important;
    padding: 14px 24px;
    border-bottom: 1px solid rgba(0,0,0,.07);
    display: flex; align-items: center; gap: 12px;
}
.form-section .card-header .section-icon {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem; flex-shrink: 0;
}
.form-section .card-header .section-title { font-size: .95rem; font-weight: 600; margin: 0; color: #1e293b; }
.form-section .card-header .section-badge {
    margin-left: auto; font-size: .7rem; padding: 3px 9px; border-radius: 20px; font-weight: 600;
}
.form-section .card-body { padding: 24px; }

/* Section accent colours */
.sec-enrollment .card-header  { background: #eff6ff; }
.sec-enrollment .section-icon { background: #dbeafe; color: #2563eb; }
.sec-personal   .card-header  { background: #f0fdf4; }
.sec-personal   .section-icon { background: #dcfce7; color: #16a34a; }
.sec-parents    .card-header  { background: #faf5ff; }
.sec-parents    .section-icon { background: #ede9fe; color: #7c3aed; }
.sec-fees       .card-header  { background: #fffbeb; }
.sec-fees       .section-icon { background: #fef3c7; color: #d97706; }
.sec-quals      .card-header  { background: #ecfeff; }
.sec-quals      .section-icon { background: #cffafe; color: #0891b2; }

/* ── Parent sub-sections ────────────────────────────────────────────────────── */
.parent-sub { background: #fafafa; border: 1px solid #e8eaf0; border-radius: 10px; padding: 18px 20px; }
.parent-sub-header { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 14px; display: flex; align-items: center; gap: 7px; }

/* ── Photo upload area ──────────────────────────────────────────────────────── */
.photo-upload-area {
    border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px 16px;
    text-align: center; cursor: pointer; transition: border-color .2s, background .2s;
    background: #f8fafc;
}
.photo-upload-area:hover { border-color: #4f8ef7; background: #eff6ff; }
.photo-upload-area input[type=file] { display: none; }
.photo-preview {
    width: 100px; height: 120px; border-radius: 8px; object-fit: cover;
    border: 2px solid #e2e8f0; display: none; margin: 0 auto 10px;
}
.photo-preview.has-image { display: block; }
.photo-placeholder { font-size: 2.5rem; color: #94a3b8; margin-bottom: 6px; }

/* ── Fee grid ───────────────────────────────────────────────────────────────── */
.fee-input-group { position: relative; }
.fee-input-group .fee-prefix {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    font-size: .75rem; font-weight: 600; color: #64748b; pointer-events: none;
}
.fee-input-group input { padding-left: 36px; }

/* ── Qualification rows ──────────────────────────────────────────────────────── */
.qual-row {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;
    position: relative; transition: box-shadow .15s;
}
.qual-row:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }
.qual-row-num {
    position: absolute; top: -10px; left: 16px;
    background: #0891b2; color: #fff; font-size: .7rem; font-weight: 700;
    padding: 2px 10px; border-radius: 20px;
}

/* ── Page header ─────────────────────────────────────────────────────────────── */
.page-header-bar {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
    border-radius: 16px; padding: 24px 28px; margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
}
.page-header-bar .page-title { color: #fff; font-size: 1.35rem; font-weight: 700; margin: 0; }
.page-header-bar .page-subtitle { color: rgba(255,255,255,.7); font-size: .82rem; margin: 3px 0 0; }
.page-header-bar .breadcrumb { background: none; margin: 0; padding: 0; }
.page-header-bar .breadcrumb-item a { color: rgba(255,255,255,.65); text-decoration: none; font-size: .82rem; }
.page-header-bar .breadcrumb-item a:hover { color: #fff; }
.page-header-bar .breadcrumb-item.active { color: rgba(255,255,255,.9); font-size: .82rem; }
.page-header-bar .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.4); }

/* ── Sticky action bar ───────────────────────────────────────────────────────── */
.sticky-action-bar {
    position: sticky; bottom: 0; z-index: 100;
    background: #fff; border-top: 1px solid #e2e8f0;
    padding: 14px 24px; margin: 0 -24px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: 0 -4px 16px rgba(0,0,0,.08);
}

/* ── Section anchor nav ──────────────────────────────────────────────────────── */
.section-nav {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 20px;
}
.section-nav a {
    font-size: .78rem; font-weight: 500; padding: 5px 14px; border-radius: 20px;
    text-decoration: none; border: 1px solid #e2e8f0; color: #475569;
    background: #fff; transition: all .15s;
}
.section-nav a:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }

/* ── Misc helpers ────────────────────────────────────────────────────────────── */
.form-check-label-sm { font-size: .83rem; }
.taka-prefix { font-size: .72rem; }
</style>

<!-- ── Page Header ──────────────────────────────────────────────────────────── -->
<div class="page-header-bar">
    <div>
        <h1 class="page-title"><i class="fas fa-user-plus me-2"></i>Add New Student</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mt-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
                <li class="breadcrumb-item active">Add Student</li>
            </ol>
        </nav>
    </div>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-sm btn-light" style="border-radius:8px;">
        <i class="fas fa-arrow-left me-1"></i> Back to List
    </a>
</div>

<!-- ── Section quick-nav ────────────────────────────────────────────────────── -->
<div class="section-nav">
    <a href="#sec-enrollment"><i class="fas fa-id-badge me-1"></i>Enrollment</a>
    <a href="#sec-personal"><i class="fas fa-user me-1"></i>Personal</a>
    <a href="#sec-parents"><i class="fas fa-users me-1"></i>Parents</a>
    <a href="#sec-quals"><i class="fas fa-graduation-cap me-1"></i>Qualifications</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4">
    <div class="fw-semibold mb-1"><i class="fas fa-exclamation-circle me-1"></i>Please fix the following errors:</div>
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" id="student-create-form">
<?= csrf_field() ?>

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 – ENROLLMENT INFO
═══════════════════════════════════════════════════════════ -->
<div class="card form-section sec-enrollment" id="sec-enrollment">
    <div class="card-header">
        <div class="section-icon"><i class="fas fa-id-badge"></i></div>
        <span class="section-title">Enrollment Information</span>
        <span class="section-badge badge bg-primary bg-opacity-10 text-primary">Required</span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <!-- Student ID -->
            <div class="col-12 col-md-6">
                <label class="form-label">Student ID <span class="text-danger">*</span>
                    <small class="text-muted fw-normal">(12 digits)</small>
                </label>
                <div class="d-flex gap-3 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="student_id_mode" id="sid_manual"
                               value="manual" <?= (old('student_id_mode','manual') === 'manual') ? 'checked' : '' ?>>
                        <label class="form-check-label form-check-label-sm" for="sid_manual">Enter manually</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="student_id_mode" id="sid_auto"
                               value="auto" <?= (old('student_id_mode','manual') === 'auto') ? 'checked' : '' ?>>
                        <label class="form-check-label form-check-label-sm" for="sid_auto">Auto-generate</label>
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
                <div id="sid_auto_note" class="alert alert-info py-2 mb-0 mt-2" style="font-size:.82rem;display:none;">
                    <i class="fas fa-magic me-1"></i>
                    ID will be auto-generated from department, program &amp; admitted semester.
                </div>
            </div>
            <!-- Status -->
            <div class="col-12 col-md-6">
                <label class="form-label">Enrollment Status</label>
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
                <label class="form-label">Department <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="dept_search"
                           placeholder="Search department…" autocomplete="off" data-target="dept_id">
                    <input type="hidden" name="dept_id" id="dept_id" value="<?= old('dept_id') ?>">
                    <div class="ss-list" id="dept_list">
                        <?php foreach ($departments as $d): ?>
                        <div class="ss-item" data-value="<?= $d['id'] ?>" data-label="<?= h($d['name']) ?>"
                             data-code="<?= h($d['code']) ?>" data-faculty="<?= h($d['faculty_label']) ?>">
                            <?= h($d['name']) ?> <small class="text-muted">(<?= h($d['code']) ?>)</small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Faculty (auto-populated) -->
            <div class="col-12 col-md-4">
                <label class="form-label">Faculty <small class="text-muted fw-normal">— auto-filled</small></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-building"></i></span>
                    <input type="text" class="form-control" id="faculty_display"
                           value="<?= old('faculty_label') ?>" readonly placeholder="Auto-filled from department">
                </div>
                <input type="hidden" name="faculty_label" id="faculty_label" value="<?= old('faculty_label') ?>">
            </div>
            <!-- Program -->
            <div class="col-12 col-md-4">
                <label class="form-label">Academic Program</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="prog_search"
                           placeholder="Search program…" autocomplete="off" data-target="program_id">
                    <input type="hidden" name="program_id" id="program_id" value="<?= old('program_id') ?>">
                    <div class="ss-list" id="prog_list">
                        <div class="ss-item" data-value="" data-label="" data-type="">— None —</div>
                        <?php foreach ($all_programs as $p): ?>
                        <div class="ss-item" data-value="<?= $p['id'] ?>" data-label="<?= h($p['program_name']) ?>"
                             data-dept="<?= $p['dept_id'] ?>" data-type="<?= h($p['program_type'] ?? '') ?>">
                            <?= h($p['program_name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Admitted Semester -->
            <div class="col-12 col-md-4">
                <label class="form-label">Admitted Semester <span class="text-danger">*</span></label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="sem_search"
                           placeholder="Search semester…" autocomplete="off" data-target="admitted_semester">
                    <input type="hidden" name="admitted_semester" id="admitted_semester" value="<?= old('admitted_semester') ?>">
                    <div class="ss-list" id="sem_list">
                        <?php foreach ($semesters as $sem): ?>
                        <div class="ss-item" data-value="<?= h($sem) ?>" data-label="<?= h($sem) ?>"><?= h($sem) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Semester Type -->
            <div class="col-12 col-md-4">
                <label class="form-label">Semester Type</label>
                <select name="semester_type" class="form-select">
                    <option value="">— Select —</option>
                    <option value="bi_semester"  <?= old('semester_type') === 'bi_semester'  ? 'selected' : '' ?>>Bi Semester (Spring / Fall)</option>
                    <option value="trimester"    <?= old('semester_type') === 'trimester'    ? 'selected' : '' ?>>Trimester (Spring / Summer / Fall)</option>
                </select>
            </div>
            <!-- Batch -->
            <div class="col-12 col-md-4">
                <label class="form-label">Batch</label>
                <div class="searchable-select-wrap">
                    <input type="text" class="form-control ss-trigger" id="batch_search"
                           placeholder="Search batch…" autocomplete="off" data-target="batch_id">
                    <input type="hidden" name="batch_id" id="batch_id" value="<?= old('batch_id') ?>">
                    <input type="hidden" name="batch" id="batch_text" value="<?= old('batch') ?>">
                    <div class="ss-list" id="batch_list">
                        <div class="ss-item" data-value="" data-label="">— None —</div>
                        <div class="ss-item" data-value="" data-label="Internal Batch" data-internal="1">
                            Internal Batch
                        </div>
                        <?php foreach ($batches as $b): ?>
                        <div class="ss-item" data-value="<?= $b['id'] ?>" data-label="<?= h($b['name']) ?>">
                            <?= h($b['name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- Shift -->
            <div class="col-12 col-md-4">
                <label class="form-label">Shift</label>
                <select name="shift" class="form-select">
                    <option value="">— Select —</option>
                    <?php foreach (['Day','Evening','Morning'] as $sh): ?>
                    <option value="<?= $sh ?>" <?= old('shift') === $sh ? 'selected' : '' ?>><?= $sh ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Program Type (auto-populated) -->
            <div class="col-12 col-md-6">
                <label class="form-label">Program Type <small class="text-muted fw-normal">— auto-detected</small></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-layer-group"></i></span>
                    <input type="text" class="form-control" id="program_type_display"
                           value="<?= old('program_type') ?>" readonly placeholder="Auto-detected">
                </div>
                <input type="hidden" name="program_type" id="program_type" value="<?= old('program_type') ?>">
            </div>
            <!-- Year -->
            <div class="col-12 col-md-6">
                <label class="form-label">Year</label>
                <input type="text" class="form-control" name="year"
                       value="<?= old('year', date('Y')) ?>" maxlength="10" placeholder="<?= date('Y') ?>">
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 2 – PERSONAL INFORMATION + PHOTO
═══════════════════════════════════════════════════════════ -->
<div class="card form-section sec-personal" id="sec-personal">
    <div class="card-header">
        <div class="section-icon"><i class="fas fa-user"></i></div>
        <span class="section-title">Personal Information</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <!-- Photo upload (right column on md+) -->
            <div class="col-12 col-md-2 d-flex flex-column align-items-center">
                <label class="form-label w-100 text-center">Photo</label>
                <div class="photo-upload-area w-100" id="photo_drop_area" title="Click to upload photo">
                    <img src="" alt="Preview" class="photo-preview" id="photo_preview">
                    <div id="photo_placeholder">
                        <div class="photo-placeholder"><i class="fas fa-camera"></i></div>
                        <div style="font-size:.78rem;color:#64748b;">Click to upload</div>
                        <div style="font-size:.72rem;color:#94a3b8;margin-top:3px;">JPG, PNG, GIF, WEBP · max 5 MB</div>
                    </div>
                    <input type="file" name="photo" id="photo_input" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
            </div>
            <!-- Personal fields -->
            <div class="col-12 col-md-10">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" value="<?= old('full_name') ?>" required maxlength="200" placeholder="Student's full name">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                            <input type="email" class="form-control" name="email" value="<?= old('email') ?>" maxlength="200" placeholder="student@example.com">
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                            <input type="text" class="form-control" name="phone" value="<?= old('phone') ?>" maxlength="30" placeholder="01XXXXXXXXX">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Gender</label>
                        <select name="sex" class="form-select">
                            <option value="">— Select —</option>
                            <?php foreach (['Male','Female','Other'] as $sx): ?>
                            <option value="<?= $sx ?>" <?= old('sex') === $sx ? 'selected' : '' ?>><?= $sx ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" class="form-control" name="dob" value="<?= old('dob') ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Blood Group</label>
                        <select name="blood_group" class="form-select">
                            <option value="">— —</option>
                            <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                            <option value="<?= $bg ?>" <?= old('blood_group') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Place of Birth</label>
                        <input type="text" class="form-control" name="place_of_birth" value="<?= old('place_of_birth') ?>" maxlength="200">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label">Religion</label>
                        <input type="text" class="form-control" name="religion" value="<?= old('religion') ?>" maxlength="100">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Nationality</label>
                        <input type="text" class="form-control" name="nationality" value="<?= old('nationality','Bangladeshi') ?>" maxlength="100">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="country" value="<?= old('country','Bangladesh') ?>" maxlength="100">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">NID Number</label>
                        <input type="text" class="form-control" name="nid" value="<?= old('nid') ?>" maxlength="50" placeholder="National ID">
                    </div>
                </div>
            </div>

            <!-- Address block -->
            <div class="col-12"><hr class="my-1" style="border-color:#e8eaf0;"></div>
            <!-- District (searchable) -->
            <div class="col-12 col-md-4">
                <label class="form-label">District</label>
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
                <label class="form-label">Thana / Upazila</label>
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
                <label class="form-label">Present Address</label>
                <textarea class="form-control" name="present_address" rows="2" placeholder="House, Road, Area…"><?= old('present_address') ?></textarea>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label">Permanent Address</label>
                <textarea class="form-control" name="permanent_address" rows="2" placeholder="Village/Town, Thana, District…"><?= old('permanent_address') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 3 – PARENTS / GUARDIAN
═══════════════════════════════════════════════════════════ -->
<div class="card form-section sec-parents" id="sec-parents">
    <div class="card-header">
        <div class="section-icon"><i class="fas fa-users"></i></div>
        <span class="section-title">Parents / Guardian Information</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <!-- Father -->
            <div class="col-12 col-lg-6">
                <div class="parent-sub">
                    <div class="parent-sub-header" style="color:#2563eb;">
                        <i class="fas fa-male"></i> Father's Information
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="father_name" value="<?= old('father_name') ?>" maxlength="200" placeholder="Father's full name">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                <input type="text" class="form-control" name="father_phone" value="<?= old('father_phone') ?>" maxlength="30">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Yearly Income</label>
                            <div class="input-group">
                                <span class="input-group-text">BDT</span>
                                <input type="number" class="form-control" name="father_yearly_income" value="<?= old('father_yearly_income') ?>" min="0" step="0.01" placeholder="0">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Occupation</label>
                            <input type="text" class="form-control" name="father_occupation" value="<?= old('father_occupation') ?>" maxlength="200">
                        </div>
                    </div>
                </div>
            </div>
            <!-- Mother -->
            <div class="col-12 col-lg-6">
                <div class="parent-sub">
                    <div class="parent-sub-header" style="color:#db2777;">
                        <i class="fas fa-female"></i> Mother's Information
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="mother_name" value="<?= old('mother_name') ?>" maxlength="200" placeholder="Mother's full name">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                <input type="text" class="form-control" name="mother_phone" value="<?= old('mother_phone') ?>" maxlength="30">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Yearly Income</label>
                            <div class="input-group">
                                <span class="input-group-text">BDT</span>
                                <input type="number" class="form-control" name="mother_yearly_income" value="<?= old('mother_yearly_income') ?>" min="0" step="0.01" placeholder="0">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Occupation</label>
                            <input type="text" class="form-control" name="mother_occupation" value="<?= old('mother_occupation') ?>" maxlength="200">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 4 – ACADEMIC QUALIFICATIONS
═══════════════════════════════════════════════════════════ -->
<div class="card form-section sec-quals" id="sec-quals">
    <div class="card-header">
        <div class="section-icon"><i class="fas fa-graduation-cap"></i></div>
        <span class="section-title">Academic Qualifications</span>
        <button type="button" class="btn btn-sm ms-auto" id="add_qual_btn"
                style="background:#cffafe;color:#0e7490;border:1px solid #a5f3fc;border-radius:8px;font-size:.8rem;">
            <i class="fas fa-plus me-1"></i> Add Row
        </button>
    </div>
    <div class="card-body">
        <div id="qual_container">
            <!-- Row 0 (default) -->
            <div class="qual-row mb-3" id="qual_row_0">
                <div class="qual-row-num">Qualification #1</div>
                <?= sm_qual_row_html(0, [], $exam_titles, $boards, $groups) ?>
            </div>
        </div>
        <p class="text-muted mb-0" style="font-size:.8rem;"><i class="fas fa-info-circle me-1"></i>Click <strong>+ Add Row</strong> to add more qualifications (SSC, HSC, Bachelor, etc.).</p>
    </div>
</div>

<!-- ══ Sticky action bar ═══════════════════════════════════════════════════════ -->
<div class="sticky-action-bar">
    <button type="submit" class="btn btn-primary px-5" style="border-radius:9px;font-weight:600;">
        <i class="fas fa-save me-2"></i>Save Student
    </button>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary px-4" style="border-radius:9px;">
        <i class="fas fa-times me-1"></i>Cancel
    </a>
    <span class="ms-auto text-muted" style="font-size:.78rem;"><i class="fas fa-asterisk text-danger me-1" style="font-size:.6rem;"></i>Required fields</span>
</div>

</form>

<script>
// ── Reference data for JS ─────────────────────────────────────────────────────
var DEPT_MAP  = <?= json_encode($dept_map,  JSON_UNESCAPED_UNICODE) ?>;
var PROG_MAP  = <?= json_encode($prog_map,  JSON_UNESCAPED_UNICODE) ?>;
var EXAM_DATA  = <?= json_encode(array_values($exam_titles), JSON_UNESCAPED_UNICODE) ?>;
var BOARD_DATA = <?= json_encode(array_values($boards),      JSON_UNESCAPED_UNICODE) ?>;
var GROUP_DATA = <?= json_encode(array_values($groups),      JSON_UNESCAPED_UNICODE) ?>;
var THANA_MAP  = <?= json_encode($thana_map, JSON_UNESCAPED_UNICODE) ?>;

// ── Photo preview ─────────────────────────────────────────────────────────────
(function() {
    var area    = document.getElementById('photo_drop_area');
    var input   = document.getElementById('photo_input');
    var preview = document.getElementById('photo_preview');
    var placeholder = document.getElementById('photo_placeholder');

    area.addEventListener('click', function() { input.click(); });
    input.addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.add('has-image');
            placeholder.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
})();

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
    thanaInput.placeholder = districtId ? 'Search thana…' : 'Select district first…';
    thanaInput.value = '';
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
        '<div class="qual-row-num">Qualification #' + (idx + 1) + '</div>'
        + '<div class="d-flex justify-content-end mb-2">'
        + '<button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;font-size:.75rem;" '
        + 'onclick="this.closest(\'.qual-row\').remove()"><i class="fas fa-times me-1"></i>Remove</button></div>'
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
