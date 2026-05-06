<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/form-sale-helpers.php';

if (!adm_can_manage()) {
    flash_set('error', 'You do not have permission to create applications.');
    redirect(APP_URL . '/admissions/index.php');
}

$page_title = 'New Application';
$user       = auth_user();
$errors     = [];

// ── Pending form sales for the link section ───────────────────────────────────
$pending_forms = db()->query(
    'SELECT id, form_number, buyer_name, buyer_mobile, buyer_email, sold_at
     FROM adm_form_sales
     WHERE status = \'pending\'
     ORDER BY sold_at DESC
     LIMIT 200'
)->fetchAll();

// ── Departments & programs ────────────────────────────────────────────────────
$departments = db()->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$programs_by_dept = [];
foreach (db()->query(
    'SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
)->fetchAll() as $p) {
    $programs_by_dept[(int)$p['dept_id']][] = $p;
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_name           = trim($_POST['student_name']           ?? '');
    $father_name            = trim($_POST['father_name']            ?? '');
    $mother_name            = trim($_POST['mother_name']            ?? '');
    $status                 = in_array($_POST['status'] ?? '', ['ready_for_admission','draft','cancelled'], true)
                              ? $_POST['status'] : 'ready_for_admission';
    $dept_id                = (int)($_POST['dept_id']    ?? 0) ?: null;
    $program_id             = (int)($_POST['program_id'] ?? 0) ?: null;
    $year                   = trim($_POST['year'] ?? '') ?: null;
    $semesters_raw          = $_POST['semester'] ?? [];
    $semester               = is_array($semesters_raw) ? implode(',', $semesters_raw) : (trim($semesters_raw) ?: null);
    $sex                    = in_array($_POST['sex'] ?? '', ['Male','Female','Other'], true) ? $_POST['sex'] : null;
    $date_of_birth          = trim($_POST['date_of_birth']          ?? '') ?: null;
    $nationality            = trim($_POST['nationality']            ?? '') ?: null;
    $place_of_birth         = trim($_POST['place_of_birth']         ?? '') ?: null;
    $religion               = trim($_POST['religion']               ?? '') ?: null;
    $nid_birth_cert         = trim($_POST['nid_birth_cert']         ?? '') ?: null;
    $blood_group            = trim($_POST['blood_group']            ?? '') ?: null;
    $present_address_1      = trim($_POST['present_address_1']      ?? '') ?: null;
    $present_address_2      = trim($_POST['present_address_2']      ?? '') ?: null;
    $present_contact        = trim($_POST['present_contact']        ?? '') ?: null;
    $present_email          = trim($_POST['present_email']          ?? '') ?: null;
    $permanent_address_1    = trim($_POST['permanent_address_1']    ?? '') ?: null;
    $permanent_address_2    = trim($_POST['permanent_address_2']    ?? '') ?: null;
    $permanent_contact      = trim($_POST['permanent_contact']      ?? '') ?: null;
    $permanent_email        = trim($_POST['permanent_email']        ?? '') ?: null;
    $experience             = trim($_POST['experience']             ?? '') ?: null;
    $guardian_name          = trim($_POST['guardian_name']          ?? '') ?: null;
    $guardian_profession    = trim($_POST['guardian_profession']    ?? '') ?: null;
    $guardian_address_1     = trim($_POST['guardian_address_1']     ?? '') ?: null;
    $guardian_address_2     = trim($_POST['guardian_address_2']     ?? '') ?: null;
    $guardian_phone         = trim($_POST['guardian_phone']         ?? '') ?: null;
    $guardian_email         = trim($_POST['guardian_email']         ?? '') ?: null;
    $guardian_relationship  = trim($_POST['guardian_relationship']  ?? '') ?: null;
    $guardian_monthly_income= trim($_POST['guardian_monthly_income']?? '') ?: null;
    $local_guardian_name    = trim($_POST['local_guardian_name']    ?? '') ?: null;
    $local_guardian_address_1 = trim($_POST['local_guardian_address_1'] ?? '') ?: null;
    $local_guardian_address_2 = trim($_POST['local_guardian_address_2'] ?? '') ?: null;
    $local_guardian_address_3 = trim($_POST['local_guardian_address_3'] ?? '') ?: null;
    $local_guardian_contact = trim($_POST['local_guardian_contact'] ?? '') ?: null;
    $reference_name         = trim($_POST['reference_name']         ?? '') ?: null;
    $reference_address_1    = trim($_POST['reference_address_1']    ?? '') ?: null;
    $reference_address_2    = trim($_POST['reference_address_2']    ?? '') ?: null;
    $reference_address_3    = trim($_POST['reference_address_3']    ?? '') ?: null;
    $reference_contact      = trim($_POST['reference_contact']      ?? '') ?: null;
    $expelled_answer        = ($_POST['expelled_answer'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
    $expelled_detail        = trim($_POST['expelled_detail']        ?? '') ?: null;
    $office_program         = trim($_POST['office_program']         ?? '') ?: null;
    $office_student_id      = trim($_POST['office_student_id']      ?? '') ?: null;
    $office_batch_no        = trim($_POST['office_batch_no']        ?? '') ?: null;
    $office_decision        = trim($_POST['office_decision']        ?? '') ?: null;
    $office_checked_by      = trim($_POST['office_checked_by']      ?? '') ?: null;

    if ($student_name === '') $errors[] = 'Student name is required.';

    // Form sale link
    $form_sale_id     = (int)($_POST['form_sale_id'] ?? 0) ?: null;
    $sale_form_number = null;
    if ($form_sale_id) {
        $sale_check = db()->prepare(
            'SELECT id, form_number, status FROM adm_form_sales WHERE id = ? AND status = ?'
        );
        $sale_check->execute([$form_sale_id, 'pending']);
        $sale_row = $sale_check->fetch();
        if (!$sale_row) {
            $errors[] = 'The selected form sale is no longer waiting for admission or does not exist.';
            $form_sale_id = null;
        } else {
            $sale_form_number = $sale_row['form_number'];
        }
    }

    // Academic records
    $acad_rows = [];
    $exam_names = $_POST['exam_name'] ?? [];
    if (is_array($exam_names)) {
        foreach ($exam_names as $idx => $exam_name) {
            $row = [
                'exam_name'        => trim($exam_name),
                'session'          => trim($_POST['acad_session'][$idx]          ?? ''),
                'group_name'       => trim($_POST['group_name'][$idx]            ?? ''),
                'board_university' => trim($_POST['board_university'][$idx]      ?? ''),
                'year_of_passing'  => trim($_POST['year_of_passing'][$idx]       ?? ''),
                'division_grade'   => trim($_POST['division_grade'][$idx]        ?? ''),
                'total_marks_cgpa' => trim($_POST['total_marks_cgpa'][$idx]      ?? ''),
                'sort_order'       => $idx,
            ];
            if (array_filter($row)) {
                $acad_rows[] = $row;
            }
        }
    }

    // Photo upload
    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = adm_upload_photo($_FILES['photo']);
        if ($uploaded === false && empty($errors)) {
            $errors[] = 'Photo upload failed.';
        } elseif ($uploaded !== false) {
            $photo = $uploaded;
        }
    }

    if (empty($errors)) {
        $app_number = $sale_form_number ?? adm_generate_number();

        db()->prepare(
            'INSERT INTO admissions_applications
               (app_number, status, dept_id, program_id, year, semester,
                student_name, father_name, mother_name,
                present_address_1, present_address_2, present_contact, present_email,
                permanent_address_1, permanent_address_2, permanent_contact, permanent_email,
                nationality, date_of_birth, place_of_birth, religion, nid_birth_cert,
                blood_group, sex, photo, experience,
                guardian_name, guardian_profession, guardian_address_1, guardian_address_2,
                guardian_phone, guardian_email, guardian_relationship, guardian_monthly_income,
                local_guardian_name, local_guardian_address_1, local_guardian_address_2, local_guardian_address_3, local_guardian_contact,
                reference_name, reference_address_1, reference_address_2, reference_address_3, reference_contact,
                expelled_answer, expelled_detail,
                office_program, office_student_id, office_batch_no, office_decision, office_checked_by,
                created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $app_number, $status, $dept_id, $program_id, $year, $semester,
            $student_name, $father_name, $mother_name,
            $present_address_1, $present_address_2, $present_contact, $present_email,
            $permanent_address_1, $permanent_address_2, $permanent_contact, $permanent_email,
            $nationality, $date_of_birth, $place_of_birth, $religion, $nid_birth_cert,
            $blood_group, $sex, $photo, $experience,
            $guardian_name, $guardian_profession, $guardian_address_1, $guardian_address_2,
            $guardian_phone, $guardian_email, $guardian_relationship, $guardian_monthly_income,
            $local_guardian_name, $local_guardian_address_1, $local_guardian_address_2, $local_guardian_address_3, $local_guardian_contact,
            $reference_name, $reference_address_1, $reference_address_2, $reference_address_3, $reference_contact,
            $expelled_answer, $expelled_detail,
            $office_program, $office_student_id, $office_batch_no, $office_decision, $office_checked_by,
            $user['id'],
        ]);
        $app_id = (int)db()->lastInsertId();

        // Mark linked form sale as used
        if ($form_sale_id) {
            db()->prepare(
                'UPDATE adm_form_sales SET status = ?, application_id = ? WHERE id = ?'
            )->execute(['used', $app_id, $form_sale_id]);
        }

        // Insert academic records
        if ($acad_rows) {
            $ins = db()->prepare(
                'INSERT INTO admissions_academic_records
                   (application_id, exam_name, session, group_name, board_university, year_of_passing, division_grade, total_marks_cgpa, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($acad_rows as $row) {
                $ins->execute([
                    $app_id,
                    $row['exam_name'], $row['session'], $row['group_name'],
                    $row['board_university'], $row['year_of_passing'],
                    $row['division_grade'], $row['total_marks_cgpa'], $row['sort_order'],
                ]);
            }
        }

        log_change('admissions', 'CREATE', $app_id, $app_number);
        flash_set('success', 'Application ' . $app_number . ' created successfully.');
        redirect(APP_URL . '/admissions/view.php?id=' . $app_id);
    }
}

require_once __DIR__ . '/../includes/header.php';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-plus-circle me-2 text-primary"></i>New Application</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active">New Application</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/admissions/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <!-- Left column -->
        <div class="col-12 col-xl-8">

            <!-- Section 0: Form Sale Lookup -->
            <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #ffc107 !important">
                <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
                    <span><i class="fas fa-receipt me-2 text-warning"></i>Link Form Sale <small class="text-muted fw-normal">(sets Application Number)</small></span>
                    <span class="badge bg-warning text-dark"><?= count($pending_forms) ?> Waiting</span>
                </div>
                <div class="card-body pb-0">
                    <!-- Selected form info -->
                    <div id="fs_found_info" class="alert alert-success py-2 mb-3 small d-flex align-items-center justify-content-between" style="display:none !important">
                        <span>
                            <i class="fas fa-check-circle me-1"></i>
                            Linked: <strong id="fs_found_number"></strong> — <strong id="fs_found_name"></strong> |
                            <span id="fs_found_mobile"></span> |
                            <span id="fs_found_email"></span>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-3" id="fsClearBtn">
                            <i class="fas fa-times me-1"></i>Unlink
                        </button>
                    </div>

                    <!-- Search -->
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="fs_search_input" class="form-control"
                                   placeholder="Search by form no, name, mobile or email…">
                        </div>
                        <div class="form-text text-muted">Click a row below to link it — the form number becomes the Application Number.</div>
                    </div>
                </div>

                <!-- Pending forms table -->
                <div class="table-responsive" style="max-height:260px; overflow-y:auto;">
                    <table class="table table-hover table-sm align-middle mb-0" id="fsPendingTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th class="ps-3">Form No</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th>Email</th>
                                <th>Sold At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($pending_forms)): ?>
                            <tr id="fsNoResults"><td colspan="5" class="text-center text-muted py-3">No forms waiting for admission.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pending_forms as $pf): ?>
                            <tr class="fs-pending-row" style="cursor:pointer"
                                data-id="<?= (int)$pf['id'] ?>"
                                data-form-number="<?= h($pf['form_number']) ?>"
                                data-name="<?= h($pf['buyer_name']) ?>"
                                data-mobile="<?= h($pf['buyer_mobile']) ?>"
                                data-email="<?= h($pf['buyer_email'] ?? '') ?>">
                                <td class="ps-3 fw-semibold text-warning"><?= h($pf['form_number']) ?></td>
                                <td><?= h($pf['buyer_name']) ?></td>
                                <td class="text-muted small"><?= h($pf['buyer_mobile']) ?></td>
                                <td class="text-muted small"><?= $pf['buyer_email'] ? h($pf['buyer_email']) : '—' ?></td>
                                <td class="text-muted small"><?= h(date('d M Y', strtotime($pf['sold_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr id="fsNoResults" style="display:none"><td colspan="5" class="text-center text-muted py-3">No matching forms found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white py-2 small text-muted">
                    Showing pending (waiting for admission) forms only.
                </div>

                <input type="hidden" name="form_sale_id" id="form_sale_id_input" value="<?= h($_POST['form_sale_id'] ?? '') ?>">
                <input type="hidden" name="fs_number_lookup" id="fs_number_lookup_hidden" value="">
            </div>

            <!-- Section 1: Application Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Application Info</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Application Number <span class="text-muted fw-normal small">(= Form Number)</span></label>
                            <input type="text" id="app_number_preview" class="form-control bg-light fw-semibold"
                                   value="<?= h($_POST['fs_number_lookup'] ?? '') ?>"
                                   placeholder="← Select a form sale above"
                                   readonly>
                            <div class="form-text"><i class="fas fa-info-circle me-1 text-primary"></i>Populated from the linked form sale. Auto-generated if none linked.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Status</label>
                            <?php $status_options = [
                                'ready_for_admission' => 'Ready for Admission',
                                'draft'               => 'Draft',
                                'cancelled'           => 'Cancelled',
                            ]; ?>
                            <select name="status" class="form-select">
                                <?php foreach ($status_options as $sv => $sl): ?>
                                <option value="<?= $sv ?>" <?= (($_POST['status'] ?? 'ready_for_admission') === $sv) ? 'selected' : '' ?>><?= h($sl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Department</label>
                            <select name="dept_id" id="dept_id" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)($_POST['dept_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Program</label>
                            <select name="program_id" id="program_id" class="form-select">
                                <option value="">— Select Program —</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Year</label>
                            <input type="text" name="year" class="form-control" value="<?= h($_POST['year'] ?? date('Y')) ?>" maxlength="4" placeholder="e.g. 2025">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Semester</label>
                            <div class="d-flex gap-4 mt-1">
                                <?php foreach (['Spring','Summer','Fall'] as $sem): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="semester[]" id="sem_<?= $sem ?>" value="<?= $sem ?>"
                                           <?php $prev = $_POST['semester'] ?? []; echo is_array($prev) && in_array($sem, $prev) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sem_<?= $sem ?>"><?= $sem ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Personal Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user me-2 text-success"></i>Student Personal Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Student Name <span class="text-danger">*</span></label>
                            <input type="text" name="student_name" class="form-control" value="<?= h($_POST['student_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Father's Name</label>
                            <input type="text" name="father_name" class="form-control" value="<?= h($_POST['father_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Mother's Name</label>
                            <input type="text" name="mother_name" class="form-control" value="<?= h($_POST['mother_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Sex</label>
                            <div class="d-flex gap-3 mt-1">
                                <?php foreach (['Male','Female','Other'] as $s): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="sex" id="sex_<?= $s ?>" value="<?= $s ?>"
                                           <?= (($_POST['sex'] ?? '') === $s) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sex_<?= $s ?>"><?= $s ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?= h($_POST['date_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Nationality</label>
                            <input type="text" name="nationality" class="form-control" value="<?= h($_POST['nationality'] ?? 'Bangladeshi') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Place of Birth</label>
                            <input type="text" name="place_of_birth" class="form-control" value="<?= h($_POST['place_of_birth'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Religion</label>
                            <input type="text" name="religion" class="form-control" value="<?= h($_POST['religion'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">NID / Birth Certificate No</label>
                            <input type="text" name="nid_birth_cert" class="form-control" value="<?= h($_POST['nid_birth_cert'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Blood Group</label>
                            <input type="text" name="blood_group" class="form-control" value="<?= h($_POST['blood_group'] ?? '') ?>" placeholder="e.g. A+">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Address -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-map-marker-alt me-2 text-warning"></i>Address</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12"><strong class="small text-muted text-uppercase">Present Address</strong></div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="present_address_1" class="form-control" value="<?= h($_POST['present_address_1'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="present_address_2" class="form-control" value="<?= h($_POST['present_address_2'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Contact No</label>
                            <input type="text" name="present_contact" class="form-control" value="<?= h($_POST['present_contact'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="present_email" class="form-control" value="<?= h($_POST['present_email'] ?? '') ?>">
                        </div>
                        <div class="col-12"><hr class="my-1"><strong class="small text-muted text-uppercase">Permanent Address</strong></div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="permanent_address_1" class="form-control" value="<?= h($_POST['permanent_address_1'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="permanent_address_2" class="form-control" value="<?= h($_POST['permanent_address_2'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Contact No</label>
                            <input type="text" name="permanent_contact" class="form-control" value="<?= h($_POST['permanent_contact'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="permanent_email" class="form-control" value="<?= h($_POST['permanent_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 4: Academic Qualifications -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-graduation-cap me-2 text-info"></i>Academic Qualifications</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addAcadRow"><i class="fas fa-plus me-1"></i>Add Row</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered mb-0" id="acadTable">
                        <thead class="table-light">
                            <tr>
                                <th>Exam Name</th>
                                <th>Session</th>
                                <th>Group</th>
                                <th>Board/University</th>
                                <th>Year</th>
                                <th>Division/Grade</th>
                                <th>Marks/CGPA</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="acadBody">
                            <?php
                            $prev_acad = [];
                            if (!empty($_POST['exam_name'])) {
                                foreach ($_POST['exam_name'] as $idx => $en) {
                                    $prev_acad[] = [
                                        'exam_name'        => $en,
                                        'session'          => $_POST['acad_session'][$idx] ?? '',
                                        'group_name'       => $_POST['group_name'][$idx] ?? '',
                                        'board_university' => $_POST['board_university'][$idx] ?? '',
                                        'year_of_passing'  => $_POST['year_of_passing'][$idx] ?? '',
                                        'division_grade'   => $_POST['division_grade'][$idx] ?? '',
                                        'total_marks_cgpa' => $_POST['total_marks_cgpa'][$idx] ?? '',
                                    ];
                                }
                            }
                            if (empty($prev_acad)) {
                                $prev_acad = [['exam_name'=>'','session'=>'','group_name'=>'','board_university'=>'','year_of_passing'=>'','division_grade'=>'','total_marks_cgpa'=>'']];
                            }
                            foreach ($prev_acad as $idx => $ar):
                            ?>
                            <tr class="acad-row">
                                <td>
                                    <select name="exam_name[]" class="acad-exam-sel" style="width:130px">
                                        <option value="">— Select —</option>
                                        <?php foreach (['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level'] as $en): ?>
                                        <option value="<?= h($en) ?>" <?= h($ar['exam_name']) === $en ? 'selected' : '' ?>><?= h($en) ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($ar['exam_name'] !== '' && !in_array($ar['exam_name'], ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level'])): ?>
                                        <option value="<?= h($ar['exam_name']) ?>" selected><?= h($ar['exam_name']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="acad_session[]" class="form-control form-control-sm" value="<?= h($ar['session']) ?>"></td>
                                <td class="acad-group-td">
                                    <select name="group_name[]" class="acad-group-sel" style="width:130px">
                                        <option value="">— Select —</option>
                                        <?php if ($ar['group_name'] !== ''): ?>
                                        <option value="<?= h($ar['group_name']) ?>" selected><?= h($ar['group_name']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="board_university[]" class="acad-board-sel" style="width:170px">
                                        <option value="">— Select —</option>
                                        <?php if ($ar['board_university'] !== ''): ?>
                                        <option value="<?= h($ar['board_university']) ?>" selected><?= h($ar['board_university']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="year_of_passing[]" class="form-control form-control-sm" value="<?= h($ar['year_of_passing']) ?>" style="width:70px"></td>
                                <td><input type="text" name="division_grade[]" class="form-control form-control-sm" value="<?= h($ar['division_grade']) ?>"></td>
                                <td><input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm" value="<?= h($ar['total_marks_cgpa']) ?>"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Section 5: Experience -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-briefcase me-2 text-secondary"></i>Experience</div>
                <div class="card-body">
                    <textarea name="experience" class="form-control" rows="3" placeholder="Work experience, if any…"><?= h($_POST['experience'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Section 6: Guardian Particulars -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-users me-2 text-purple" style="color:#6f42c1"></i>Guardian Particulars</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Guardian Name</label>
                            <input type="text" name="guardian_name" class="form-control" value="<?= h($_POST['guardian_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Profession</label>
                            <input type="text" name="guardian_profession" class="form-control" value="<?= h($_POST['guardian_profession'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="guardian_address_1" class="form-control" value="<?= h($_POST['guardian_address_1'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="guardian_address_2" class="form-control" value="<?= h($_POST['guardian_address_2'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="guardian_phone" class="form-control" value="<?= h($_POST['guardian_phone'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="guardian_email" class="form-control" value="<?= h($_POST['guardian_email'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Relationship</label>
                            <input type="text" name="guardian_relationship" class="form-control" value="<?= h($_POST['guardian_relationship'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Monthly Average Income</label>
                            <input type="text" name="guardian_monthly_income" class="form-control" value="<?= h($_POST['guardian_monthly_income'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 7: Local Guardian -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-home me-2 text-teal" style="color:#20c997"></i>Local Guardian</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="local_guardian_name" class="form-control" value="<?= h($_POST['local_guardian_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="local_guardian_contact" class="form-control" value="<?= h($_POST['local_guardian_contact'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="local_guardian_address_1" class="form-control" value="<?= h($_POST['local_guardian_address_1'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="local_guardian_address_2" class="form-control" value="<?= h($_POST['local_guardian_address_2'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 3</label>
                            <input type="text" name="local_guardian_address_3" class="form-control" value="<?= h($_POST['local_guardian_address_3'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 8: Reference -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user-tie me-2 text-dark"></i>Reference</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" name="reference_name" class="form-control" value="<?= h($_POST['reference_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Contact</label>
                            <input type="text" name="reference_contact" class="form-control" value="<?= h($_POST['reference_contact'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 1</label>
                            <input type="text" name="reference_address_1" class="form-control" value="<?= h($_POST['reference_address_1'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 2</label>
                            <input type="text" name="reference_address_2" class="form-control" value="<?= h($_POST['reference_address_2'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Address Line 3</label>
                            <input type="text" name="reference_address_3" class="form-control" value="<?= h($_POST['reference_address_3'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 9: Additional Questions -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-question-circle me-2 text-danger"></i>Additional Questions</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Have you ever been expelled from any institution?</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="expelled_answer" id="expelled_no" value="No"
                                           <?= (($_POST['expelled_answer'] ?? 'No') === 'No') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                    <label class="form-check-label" for="expelled_no">No</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="expelled_answer" id="expelled_yes" value="Yes"
                                           <?= (($_POST['expelled_answer'] ?? '') === 'Yes') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                    <label class="form-check-label" for="expelled_yes">Yes</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12" id="expelled_detail_wrap" style="<?= (($_POST['expelled_answer'] ?? '') !== 'Yes') ? 'display:none' : '' ?>">
                            <label class="form-label">If yes, provide details</label>
                            <input type="text" name="expelled_detail" class="form-control" value="<?= h($_POST['expelled_detail'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 10: For Office Use Only -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-stamp me-2 text-secondary"></i>For Office Use Only</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Program</label>
                            <input type="text" name="office_program" class="form-control" value="<?= h($_POST['office_program'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Student ID No</label>
                            <input type="text" name="office_student_id" class="form-control" value="<?= h($_POST['office_student_id'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Batch No</label>
                            <input type="text" name="office_batch_no" class="form-control" value="<?= h($_POST['office_batch_no'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Decision</label>
                            <input type="text" name="office_decision" class="form-control" value="<?= h($_POST['office_decision'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Checked By</label>
                            <input type="text" name="office_checked_by" class="form-control" value="<?= h($_POST['office_checked_by'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /Left column -->

        <!-- Right column: Photo -->
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm mb-4 sticky-top" style="top:80px">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-camera me-2 text-info"></i>Applicant Photo</div>
                <div class="card-body text-center">
                    <div id="photoPreviewWrap" class="mb-3">
                        <img id="photoPreview" src="" class="img-thumbnail" style="max-width:160px;max-height:200px;display:none">
                        <div id="photoPlaceholder" class="border rounded d-flex align-items-center justify-content-center bg-light mx-auto" style="width:160px;height:200px">
                            <i class="fas fa-user fa-3x text-muted"></i>
                        </div>
                    </div>
                    <label class="form-label">Upload Photo (max 2 MB)</label>
                    <input type="file" name="photo" id="photoInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text">JPG, PNG, GIF, WebP accepted</div>
                </div>
            </div>
        </div>
    </div><!-- /row -->

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Application</button>
        <a href="<?= APP_URL ?>/admissions/index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script>
// ── Department → Program dynamic filter ──────────────────────────────────────
var deptPrograms = <?= json_encode($programs_by_dept, JSON_HEX_TAG) ?>;

document.getElementById('dept_id').addEventListener('change', function() {
    var deptId = parseInt(this.value);
    var sel = document.getElementById('program_id');
    sel.innerHTML = '<option value="">— Select Program —</option>';
    if (deptId && deptPrograms[deptId]) {
        deptPrograms[deptId].forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.program_name;
            sel.appendChild(opt);
        });
    }
});

// Pre-select program on page load (after POST error)
(function() {
    var selDept = document.getElementById('dept_id');
    var selProg = document.getElementById('program_id');
    var selectedDept = parseInt(selDept.value);
    var selectedProg = <?= (int)($_POST['program_id'] ?? 0) ?>;
    if (selectedDept && deptPrograms[selectedDept]) {
        selProg.innerHTML = '<option value="">— Select Program —</option>';
        deptPrograms[selectedDept].forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.program_name;
            if (p.id == selectedProg) opt.selected = true;
            selProg.appendChild(opt);
        });
    }
})();

// ── Academic Qualifications: exam/group/board data ───────────────────────────
var ACAD_DATA = {
    'SSC': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet'],
        defaultBoard: null, showGroup: true
    },
    'Dakhil': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Bangladesh Madrasah Education Board'],
        defaultBoard: 'Bangladesh Madrasah Education Board', showGroup: true
    },
    'O Level': {
        groups: [],
        boards: ['Cambridge','Edexcel'],
        defaultBoard: null, showGroup: false
    },
    'SSC (Vocational)': {
        groups: ['Electrical','Mechanical','Computer','Civil','Electronics','Refrigeration & Air Conditioning','Welding & Fabrication','Auto Mechanic','Drafting (Civil)','Drafting (Mechanical)'],
        boards: ['Bangladesh Technical Education Board'],
        defaultBoard: 'Bangladesh Technical Education Board', showGroup: true
    },
    'HSC': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet','Madrasah Education Board','Technical Education Board'],
        defaultBoard: null, showGroup: true
    },
    'Alim': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Bangladesh Madrasah Education Board'],
        defaultBoard: 'Bangladesh Madrasah Education Board', showGroup: true
    },
    'A Level': {
        groups: [],
        boards: ['Cambridge','Edexcel'],
        defaultBoard: null, showGroup: false
    }
};

function acadUpdateGroupBoard(tr, newExam, setDefault) {
    var data     = ACAD_DATA[newExam] || { groups: [], boards: [], defaultBoard: null, showGroup: true };
    var tsGroup  = tr._tsGroup;
    var tsBoard  = tr._tsBoard;
    var groupTd  = tr.querySelector('.acad-group-td');

    // Update group options
    tsGroup.clearOptions();
    tsGroup.addOption({ value: '', text: '— Select —' });
    data.groups.forEach(function(g) { tsGroup.addOption({ value: g, text: g }); });
    if (!data.showGroup) {
        tsGroup.setValue('', true);
        if (groupTd) groupTd.style.opacity = '0.35';
    } else {
        if (groupTd) groupTd.style.opacity = '';
    }

    // Update board options
    tsBoard.clearOptions();
    tsBoard.addOption({ value: '', text: '— Select —' });
    data.boards.forEach(function(b) { tsBoard.addOption({ value: b, text: b }); });
    if (setDefault && data.defaultBoard) {
        tsBoard.setValue(data.defaultBoard, true);
    }
}

function initAcadRow(tr) {
    var examSel  = tr.querySelector('select.acad-exam-sel');
    var groupSel = tr.querySelector('select.acad-group-sel');
    var boardSel = tr.querySelector('select.acad-board-sel');
    if (!examSel || !groupSel || !boardSel) return;

    var savedExam  = examSel.value;
    var savedGroup = groupSel.value;
    var savedBoard = boardSel.value;

    var tsExam = new TomSelect(examSel, {
        create: true, allowEmptyOption: true, maxOptions: 20,
        plugins: ['clear_button'],
        placeholder: '— Select / Type —'
    });
    var tsGroup = new TomSelect(groupSel, {
        create: true, allowEmptyOption: true, maxOptions: 30,
        plugins: ['clear_button'],
        placeholder: '— Select / Type —'
    });
    var tsBoard = new TomSelect(boardSel, {
        create: true, allowEmptyOption: true, maxOptions: 20,
        plugins: ['clear_button'],
        placeholder: '— Select / Type —'
    });

    tr._tsExam  = tsExam;
    tr._tsGroup = tsGroup;
    tr._tsBoard = tsBoard;

    // Populate group/board for the currently saved exam value (don't set default board)
    if (savedExam) {
        var data = ACAD_DATA[savedExam] || { groups: [], boards: [], defaultBoard: null, showGroup: true };
        tsGroup.clearOptions();
        tsGroup.addOption({ value: '', text: '— Select —' });
        data.groups.forEach(function(g) { tsGroup.addOption({ value: g, text: g }); });
        if (savedGroup) tsGroup.addOption({ value: savedGroup, text: savedGroup });
        tsGroup.setValue(savedGroup, true);

        tsBoard.clearOptions();
        tsBoard.addOption({ value: '', text: '— Select —' });
        data.boards.forEach(function(b) { tsBoard.addOption({ value: b, text: b }); });
        if (savedBoard) tsBoard.addOption({ value: savedBoard, text: savedBoard });
        tsBoard.setValue(savedBoard, true);

        var groupTd = tr.querySelector('.acad-group-td');
        if (!data.showGroup && groupTd) groupTd.style.opacity = '0.35';
    }

    // On exam change → update group & board
    tsExam.on('change', function(val) {
        acadUpdateGroupBoard(tr, val, true);
    });
}

// ── Academic records dynamic rows ─────────────────────────────────────────────
document.getElementById('addAcadRow').addEventListener('click', function() {
    var tbody = document.getElementById('acadBody');
    var tr = document.createElement('tr');
    tr.className = 'acad-row';
    tr.innerHTML = '<td>'
        + '<select name="exam_name[]" class="acad-exam-sel" style="width:130px">'
        + '<option value="">— Select —</option>'
        + ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level'].map(function(e){return '<option value="'+e+'">'+e+'</option>';}).join('')
        + '</select></td>'
        + '<td><input type="text" name="acad_session[]" class="form-control form-control-sm"></td>'
        + '<td class="acad-group-td"><select name="group_name[]" class="acad-group-sel" style="width:130px"><option value="">— Select —</option></select></td>'
        + '<td><select name="board_university[]" class="acad-board-sel" style="width:170px"><option value="">— Select —</option></select></td>'
        + '<td><input type="text" name="year_of_passing[]" class="form-control form-control-sm" style="width:70px"></td>'
        + '<td><input type="text" name="division_grade[]" class="form-control form-control-sm"></td>'
        + '<td><input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm"></td>'
        + '<td><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
    initAcadRow(tr);
});

document.getElementById('acadBody').addEventListener('click', function(e) {
    if (e.target.closest('.removeRow')) {
        var row = e.target.closest('tr');
        if (document.querySelectorAll('#acadBody tr').length > 1) {
            row.remove();
        }
    }
});

// Init TomSelect on all existing rows
document.querySelectorAll('#acadBody tr.acad-row').forEach(function(tr) {
    // Mark group td for opacity toggle
    var groupSel = tr.querySelector('select.acad-group-sel');
    if (groupSel && groupSel.parentElement.tagName === 'TD') {
        groupSel.parentElement.classList.add('acad-group-td');
    }
    initAcadRow(tr);
});

// ── Photo preview ─────────────────────────────────────────────────────────────
document.getElementById('photoInput').addEventListener('change', function() {
    var file = this.files[0];
    if (file) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('photoPreview').src = e.target.result;
            document.getElementById('photoPreview').style.display = '';
            document.getElementById('photoPlaceholder').style.display = 'none';
        };
        reader.readAsDataURL(file);
    }
});

// ── Expelled detail toggle ────────────────────────────────────────────────────
function toggleExpelled() {
    var yes = document.getElementById('expelled_yes').checked;
    document.getElementById('expelled_detail_wrap').style.display = yes ? '' : 'none';
}

// ── Form Sale Pending List ────────────────────────────────────────────────────
(function () {
    var rows       = document.querySelectorAll('.fs-pending-row');
    var searchBox  = document.getElementById('fs_search_input');
    var noResults  = document.getElementById('fsNoResults');
    var foundInfo  = document.getElementById('fs_found_info');
    var clearBtn   = document.getElementById('fsClearBtn');
    var idInput    = document.getElementById('form_sale_id_input');
    var selectedId = idInput ? idInput.value : '';

    // Restore selection highlight on page reload (e.g. after validation error)
    if (selectedId) {
        rows.forEach(function(r) {
            if (String(r.dataset.id) === String(selectedId)) {
                selectRow(r, false);
            }
        });
    }

    // Search / filter
    if (searchBox) {
        searchBox.addEventListener('input', function () {
            var q = searchBox.value.trim().toLowerCase();
            var visible = 0;
            rows.forEach(function (r) {
                var match = !q
                    || r.dataset.formNumber.toLowerCase().indexOf(q) >= 0
                    || r.dataset.name.toLowerCase().indexOf(q) >= 0
                    || r.dataset.mobile.toLowerCase().indexOf(q) >= 0
                    || r.dataset.email.toLowerCase().indexOf(q) >= 0;
                r.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
        });
    }

    // Row click → select
    rows.forEach(function (r) {
        r.addEventListener('click', function () {
            selectRow(r, true);
        });
    });

    function selectRow(r, autoFill) {
        // Highlight row
        rows.forEach(function(rr) { rr.classList.remove('table-warning'); });
        r.classList.add('table-warning');

        idInput.value = r.dataset.id;
        document.getElementById('fs_number_lookup_hidden').value = r.dataset.formNumber;

        // Update Application Number preview
        var appNumField = document.getElementById('app_number_preview');
        if (appNumField) appNumField.value = r.dataset.formNumber;

        // Show linked info bar
        document.getElementById('fs_found_number').textContent  = r.dataset.formNumber;
        document.getElementById('fs_found_name').textContent    = r.dataset.name;
        document.getElementById('fs_found_mobile').textContent  = r.dataset.mobile;
        document.getElementById('fs_found_email').textContent   = r.dataset.email;
        foundInfo.style.removeProperty('display');
        foundInfo.style.display = '';

        // Auto-fill main form fields (only if empty)
        if (autoFill) {
            var nameInput   = document.querySelector('[name="student_name"]');
            var mobileInput = document.querySelector('[name="present_contact"]');
            var emailInput  = document.querySelector('[name="present_email"]');
            if (nameInput   && nameInput.value   === '') nameInput.value   = r.dataset.name;
            if (mobileInput && mobileInput.value === '') mobileInput.value = r.dataset.mobile;
            if (emailInput  && emailInput.value  === '' && r.dataset.email) emailInput.value = r.dataset.email;
        }
    }

    // Unlink button
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            idInput.value = '';
            document.getElementById('fs_number_lookup_hidden').value = '';
            foundInfo.style.display = 'none';
            rows.forEach(function(r) { r.classList.remove('table-warning'); });
            var appNumField = document.getElementById('app_number_preview');
            if (appNumField) appNumField.value = '';
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
