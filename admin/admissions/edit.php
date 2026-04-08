<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/helpers.php';

if (!adm_can_edit()) {
    flash_set('error', 'You do not have permission to edit applications.');
    redirect(APP_URL . '/admissions/index.php');
}

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$app = adm_get($id);
$acad_records = adm_get_academic_records($id);

$page_title = 'Edit Application – ' . $app['app_number'];
$user       = auth_user();
$errors     = [];

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
    $status                 = in_array($_POST['status'] ?? '', ['draft','submitted','approved','rejected'], true)
                              ? $_POST['status'] : 'draft';
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

    // Academic records
    $acad_rows = [];
    $exam_names = $_POST['exam_name'] ?? [];
    if (is_array($exam_names)) {
        foreach ($exam_names as $idx => $exam_name) {
            $row = [
                'exam_name'        => trim($exam_name),
                'session'          => trim($_POST['acad_session'][$idx]      ?? ''),
                'group_name'       => trim($_POST['group_name'][$idx]        ?? ''),
                'board_university' => trim($_POST['board_university'][$idx]  ?? ''),
                'year_of_passing'  => trim($_POST['year_of_passing'][$idx]   ?? ''),
                'division_grade'   => trim($_POST['division_grade'][$idx]    ?? ''),
                'total_marks_cgpa' => trim($_POST['total_marks_cgpa'][$idx]  ?? ''),
                'sort_order'       => $idx,
            ];
            if (array_filter($row)) {
                $acad_rows[] = $row;
            }
        }
    }

    // Photo upload – keep existing if no new file provided
    $photo = $app['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = adm_upload_photo($_FILES['photo']);
        if ($uploaded === false && empty($errors)) {
            $errors[] = 'Photo upload failed.';
        } elseif ($uploaded !== false) {
            // Delete old photo file
            if ($app['photo']) {
                $old_path = UPLOAD_DIR . '/' . ADM_PHOTO_SUBDIR . '/' . $app['photo'];
                if (file_exists($old_path)) {
                    unlink($old_path);
                }
            }
            $photo = $uploaded;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE admissions_applications SET
                status=?, dept_id=?, program_id=?, year=?, semester=?,
                student_name=?, father_name=?, mother_name=?,
                present_address_1=?, present_address_2=?, present_contact=?, present_email=?,
                permanent_address_1=?, permanent_address_2=?, permanent_contact=?, permanent_email=?,
                nationality=?, date_of_birth=?, place_of_birth=?, religion=?, nid_birth_cert=?,
                blood_group=?, sex=?, photo=?, experience=?,
                guardian_name=?, guardian_profession=?, guardian_address_1=?, guardian_address_2=?,
                guardian_phone=?, guardian_email=?, guardian_relationship=?, guardian_monthly_income=?,
                local_guardian_name=?, local_guardian_address_1=?, local_guardian_address_2=?, local_guardian_address_3=?, local_guardian_contact=?,
                reference_name=?, reference_address_1=?, reference_address_2=?, reference_address_3=?, reference_contact=?,
                expelled_answer=?, expelled_detail=?,
                office_program=?, office_student_id=?, office_batch_no=?, office_decision=?, office_checked_by=?
             WHERE id=?'
        )->execute([
            $status, $dept_id, $program_id, $year, $semester,
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
            $id,
        ]);

        // Replace academic records
        db()->prepare('DELETE FROM admissions_academic_records WHERE application_id = ?')->execute([$id]);
        if ($acad_rows) {
            $ins = db()->prepare(
                'INSERT INTO admissions_academic_records
                   (application_id, exam_name, session, group_name, board_university, year_of_passing, division_grade, total_marks_cgpa, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($acad_rows as $row) {
                $ins->execute([
                    $id,
                    $row['exam_name'], $row['session'], $row['group_name'],
                    $row['board_university'], $row['year_of_passing'],
                    $row['division_grade'], $row['total_marks_cgpa'], $row['sort_order'],
                ]);
            }
        }

        log_change('admissions', 'UPDATE', $id, $app['app_number']);
        flash_set('success', 'Application ' . $app['app_number'] . ' updated successfully.');
        redirect(APP_URL . '/admissions/view.php?id=' . $id);
    }

    // Re-populate $app with POST data for re-rendering form
    $app = array_merge($app, $_POST);
    $acad_records = $acad_rows;
}

// Helper: get value from app (for form pre-filling)
$v = function(string $key) use ($app): string {
    return h($app[$key] ?? '');
};

// Parse saved semesters
$saved_semesters = array_map('trim', explode(',', $app['semester'] ?? ''));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-primary"></i>Edit Application</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/view.php?id=<?= $id ?>"><?= h($app['app_number']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/admissions/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-4">
        <!-- Left column -->
        <div class="col-12 col-xl-8">

            <!-- Section 1: Application Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Application Info</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Application Number</label>
                            <input type="text" class="form-control bg-light" value="<?= h($app['app_number']) ?>" readonly disabled>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['draft','submitted','approved','rejected'] as $st): ?>
                                <option value="<?= $st ?>" <?= ($app['status'] === $st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Department</label>
                            <select name="dept_id" id="dept_id" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)($app['dept_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
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
                            <input type="text" name="year" class="form-control" value="<?= $v('year') ?>" maxlength="4" placeholder="e.g. 2025">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Semester</label>
                            <div class="d-flex gap-4 mt-1">
                                <?php foreach (['Spring','Summer','Fall'] as $sem): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="semester[]" id="sem_<?= $sem ?>" value="<?= $sem ?>"
                                           <?= in_array($sem, $saved_semesters) ? 'checked' : '' ?>>
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
                            <input type="text" name="student_name" class="form-control" value="<?= $v('student_name') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Father's Name</label>
                            <input type="text" name="father_name" class="form-control" value="<?= $v('father_name') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Mother's Name</label>
                            <input type="text" name="mother_name" class="form-control" value="<?= $v('mother_name') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Sex</label>
                            <div class="d-flex gap-3 mt-1">
                                <?php foreach (['Male','Female','Other'] as $s): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="sex" id="sex_<?= $s ?>" value="<?= $s ?>"
                                           <?= (($app['sex'] ?? '') === $s) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="sex_<?= $s ?>"><?= $s ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control" value="<?= $v('date_of_birth') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Nationality</label>
                            <input type="text" name="nationality" class="form-control" value="<?= $v('nationality') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Place of Birth</label>
                            <input type="text" name="place_of_birth" class="form-control" value="<?= $v('place_of_birth') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Religion</label>
                            <input type="text" name="religion" class="form-control" value="<?= $v('religion') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">NID / Birth Certificate No</label>
                            <input type="text" name="nid_birth_cert" class="form-control" value="<?= $v('nid_birth_cert') ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Blood Group</label>
                            <input type="text" name="blood_group" class="form-control" value="<?= $v('blood_group') ?>" placeholder="e.g. A+">
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
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="present_address_1" class="form-control" value="<?= $v('present_address_1') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="present_address_2" class="form-control" value="<?= $v('present_address_2') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Contact No</label><input type="text" name="present_contact" class="form-control" value="<?= $v('present_contact') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Email</label><input type="email" name="present_email" class="form-control" value="<?= $v('present_email') ?>"></div>
                        <div class="col-12"><hr class="my-1"><strong class="small text-muted text-uppercase">Permanent Address</strong></div>
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="permanent_address_1" class="form-control" value="<?= $v('permanent_address_1') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="permanent_address_2" class="form-control" value="<?= $v('permanent_address_2') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Contact No</label><input type="text" name="permanent_contact" class="form-control" value="<?= $v('permanent_contact') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Email</label><input type="email" name="permanent_email" class="form-control" value="<?= $v('permanent_email') ?>"></div>
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
                                <th>Exam Name</th><th>Session</th><th>Group</th>
                                <th>Board/University</th><th>Year</th><th>Division/Grade</th><th>Marks/CGPA</th><th></th>
                            </tr>
                        </thead>
                        <tbody id="acadBody">
                            <?php
                            $rows_to_render = !empty($acad_records) ? $acad_records : [[]];
                            foreach ($rows_to_render as $ar):
                            ?>
                            <tr>
                                <td><input type="text" name="exam_name[]" class="form-control form-control-sm" value="<?= h($ar['exam_name'] ?? '') ?>" placeholder="SSC/HSC/BSc…"></td>
                                <td><input type="text" name="acad_session[]" class="form-control form-control-sm" value="<?= h($ar['session'] ?? '') ?>"></td>
                                <td><input type="text" name="group_name[]" class="form-control form-control-sm" value="<?= h($ar['group_name'] ?? '') ?>"></td>
                                <td><input type="text" name="board_university[]" class="form-control form-control-sm" value="<?= h($ar['board_university'] ?? '') ?>"></td>
                                <td><input type="text" name="year_of_passing[]" class="form-control form-control-sm" value="<?= h($ar['year_of_passing'] ?? '') ?>" style="width:70px"></td>
                                <td><input type="text" name="division_grade[]" class="form-control form-control-sm" value="<?= h($ar['division_grade'] ?? '') ?>"></td>
                                <td><input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm" value="<?= h($ar['total_marks_cgpa'] ?? '') ?>"></td>
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
                    <textarea name="experience" class="form-control" rows="3"><?= $v('experience') ?></textarea>
                </div>
            </div>

            <!-- Section 6: Guardian Particulars -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-users me-2" style="color:#6f42c1"></i>Guardian Particulars</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">Guardian Name</label><input type="text" name="guardian_name" class="form-control" value="<?= $v('guardian_name') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Profession</label><input type="text" name="guardian_profession" class="form-control" value="<?= $v('guardian_profession') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 1</label><input type="text" name="guardian_address_1" class="form-control" value="<?= $v('guardian_address_1') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Address Line 2</label><input type="text" name="guardian_address_2" class="form-control" value="<?= $v('guardian_address_2') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Phone</label><input type="text" name="guardian_phone" class="form-control" value="<?= $v('guardian_phone') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Email</label><input type="email" name="guardian_email" class="form-control" value="<?= $v('guardian_email') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Relationship</label><input type="text" name="guardian_relationship" class="form-control" value="<?= $v('guardian_relationship') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Monthly Average Income</label><input type="text" name="guardian_monthly_income" class="form-control" value="<?= $v('guardian_monthly_income') ?>"></div>
                    </div>
                </div>
            </div>

            <!-- Section 7: Local Guardian -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-home me-2" style="color:#20c997"></i>Local Guardian</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">Name</label><input type="text" name="local_guardian_name" class="form-control" value="<?= $v('local_guardian_name') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Contact</label><input type="text" name="local_guardian_contact" class="form-control" value="<?= $v('local_guardian_contact') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 1</label><input type="text" name="local_guardian_address_1" class="form-control" value="<?= $v('local_guardian_address_1') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 2</label><input type="text" name="local_guardian_address_2" class="form-control" value="<?= $v('local_guardian_address_2') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 3</label><input type="text" name="local_guardian_address_3" class="form-control" value="<?= $v('local_guardian_address_3') ?>"></div>
                    </div>
                </div>
            </div>

            <!-- Section 8: Reference -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user-tie me-2 text-dark"></i>Reference</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">Name</label><input type="text" name="reference_name" class="form-control" value="<?= $v('reference_name') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Contact</label><input type="text" name="reference_contact" class="form-control" value="<?= $v('reference_contact') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 1</label><input type="text" name="reference_address_1" class="form-control" value="<?= $v('reference_address_1') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 2</label><input type="text" name="reference_address_2" class="form-control" value="<?= $v('reference_address_2') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Address Line 3</label><input type="text" name="reference_address_3" class="form-control" value="<?= $v('reference_address_3') ?>"></div>
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
                                           <?= (($app['expelled_answer'] ?? 'No') === 'No') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                    <label class="form-check-label" for="expelled_no">No</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="expelled_answer" id="expelled_yes" value="Yes"
                                           <?= (($app['expelled_answer'] ?? '') === 'Yes') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                    <label class="form-check-label" for="expelled_yes">Yes</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12" id="expelled_detail_wrap" style="<?= (($app['expelled_answer'] ?? '') !== 'Yes') ? 'display:none' : '' ?>">
                            <label class="form-label">If yes, provide details</label>
                            <input type="text" name="expelled_detail" class="form-control" value="<?= $v('expelled_detail') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 10: For Office Use Only -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-stamp me-2 text-secondary"></i>For Office Use Only</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">Program</label><input type="text" name="office_program" class="form-control" value="<?= $v('office_program') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Student ID No</label><input type="text" name="office_student_id" class="form-control" value="<?= $v('office_student_id') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Batch No</label><input type="text" name="office_batch_no" class="form-control" value="<?= $v('office_batch_no') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Decision</label><input type="text" name="office_decision" class="form-control" value="<?= $v('office_decision') ?>"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Checked By</label><input type="text" name="office_checked_by" class="form-control" value="<?= $v('office_checked_by') ?>"></div>
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
                        <?php if ($app['photo']): ?>
                        <img id="photoPreview" src="<?= UPLOAD_URL . '/' . ADM_PHOTO_SUBDIR . '/' . h($app['photo']) ?>"
                             class="img-thumbnail" style="max-width:160px;max-height:200px">
                        <div id="photoPlaceholder" style="display:none"></div>
                        <?php else: ?>
                        <img id="photoPreview" src="" class="img-thumbnail" style="max-width:160px;max-height:200px;display:none">
                        <div id="photoPlaceholder" class="border rounded d-flex align-items-center justify-content-center bg-light mx-auto" style="width:160px;height:200px">
                            <i class="fas fa-user fa-3x text-muted"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <label class="form-label">Upload New Photo (max 2 MB)</label>
                    <input type="file" name="photo" id="photoInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text">Leave blank to keep current photo</div>
                </div>
            </div>
        </div>
    </div><!-- /row -->

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Changes</button>
        <a href="<?= APP_URL ?>/admissions/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

<script>
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

(function() {
    var selDept = document.getElementById('dept_id');
    var selProg = document.getElementById('program_id');
    var selectedDept = parseInt(selDept.value);
    var selectedProg = <?= (int)($app['program_id'] ?? 0) ?>;
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

document.getElementById('addAcadRow').addEventListener('click', function() {
    var tbody = document.getElementById('acadBody');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><input type="text" name="exam_name[]" class="form-control form-control-sm" placeholder="SSC/HSC/BSc…"></td>'
                 + '<td><input type="text" name="acad_session[]" class="form-control form-control-sm"></td>'
                 + '<td><input type="text" name="group_name[]" class="form-control form-control-sm"></td>'
                 + '<td><input type="text" name="board_university[]" class="form-control form-control-sm"></td>'
                 + '<td><input type="text" name="year_of_passing[]" class="form-control form-control-sm" style="width:70px"></td>'
                 + '<td><input type="text" name="division_grade[]" class="form-control form-control-sm"></td>'
                 + '<td><input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm"></td>'
                 + '<td><button type="button" class="btn btn-sm btn-outline-danger removeRow"><i class="fas fa-times"></i></button></td>';
    tbody.appendChild(tr);
});

document.getElementById('acadBody').addEventListener('click', function(e) {
    if (e.target.closest('.removeRow')) {
        var row = e.target.closest('tr');
        if (document.querySelectorAll('#acadBody tr').length > 1) {
            row.remove();
        }
    }
});

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

function toggleExpelled() {
    var yes = document.getElementById('expelled_yes').checked;
    document.getElementById('expelled_detail_wrap').style.display = yes ? '' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
