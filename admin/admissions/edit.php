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


// ── Bangladesh districts & thanas ─────────────────────────────────────────────
$bd_districts = adm_bd_districts();
$bd_thanas    = adm_bd_thanas();
$bd_thana_map = [];
foreach ($bd_thanas as $t) {
    $bd_thana_map[$t['district_id']][] = ['id' => $t['id'], 'name' => $t['name']];
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_name           = trim($_POST['student_name']           ?? '');
    $father_name            = trim($_POST['father_name']            ?? '');
    $mother_name            = trim($_POST['mother_name']            ?? '');
    $status                 = in_array($_POST['status'] ?? '', ['ready_for_admission','draft','cancelled','admission_complete'], true)
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
    $present_area           = trim($_POST['present_area']           ?? '') ?: null;
    $present_district_id    = (int)($_POST['present_district_id']   ?? 0) ?: null;
    $present_thana_id       = (int)($_POST['present_thana_id']      ?? 0) ?: null;
    $present_post_code      = trim($_POST['present_post_code']      ?? '') ?: null;
    $present_contact        = trim($_POST['present_contact']        ?? '') ?: null;
    $present_email          = trim($_POST['present_email']          ?? '') ?: null;
    $permanent_address_1    = trim($_POST['permanent_address_1']    ?? '') ?: null;
    $permanent_address_2    = trim($_POST['permanent_address_2']    ?? '') ?: null;
    $permanent_area         = trim($_POST['permanent_area']         ?? '') ?: null;
    $permanent_district_id  = (int)($_POST['permanent_district_id'] ?? 0) ?: null;
    $permanent_thana_id     = (int)($_POST['permanent_thana_id']    ?? 0) ?: null;
    $permanent_post_code    = trim($_POST['permanent_post_code']    ?? '') ?: null;
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
    $office_university_batch = trim($_POST['office_university_batch'] ?? '') ?: null;
    $office_dept_batch       = trim($_POST['office_dept_batch']       ?? '') ?: null;
    $office_section          = trim($_POST['office_section']          ?? '') ?: null;
    $office_shift            = trim($_POST['office_shift']            ?? '') ?: null;
    $office_decision         = trim($_POST['office_decision']         ?? '') ?: null;
    $office_checked_by       = trim($_POST['office_checked_by']       ?? '') ?: null;
    $promoter_source         = ($_POST['promoter_source'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
    $promoter_name           = trim($_POST['promoter_name']           ?? '') ?: null;
    $promoter_address        = trim($_POST['promoter_address']        ?? '') ?: null;
    $promoter_contact        = trim($_POST['promoter_contact']        ?? '') ?: null;
    $promoter_email          = trim($_POST['promoter_email']          ?? '') ?: null;
    $prime_student           = ($_POST['prime_student'] ?? 'No') === 'Yes' ? 'Yes' : 'No';
    $prime_student_id        = trim($_POST['prime_student_id']        ?? '') ?: null;
    $prime_department        = trim($_POST['prime_department']        ?? '') ?: null;
    $prime_program           = trim($_POST['prime_program']           ?? '') ?: null;
    $source_note             = trim($_POST['source_note']             ?? '') ?: null;
    if ($promoter_source !== 'Yes') {
        $promoter_name = $promoter_address = $promoter_contact = $promoter_email = null;
    }
    if ($prime_student !== 'Yes') {
        $prime_student_id = $prime_department = $prime_program = null;
    }

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
                present_address_1=?, present_address_2=?, present_area=?, present_district_id=?, present_thana_id=?, present_post_code=?, present_contact=?, present_email=?,
                permanent_address_1=?, permanent_address_2=?, permanent_area=?, permanent_district_id=?, permanent_thana_id=?, permanent_post_code=?, permanent_contact=?, permanent_email=?,
                nationality=?, date_of_birth=?, place_of_birth=?, religion=?, nid_birth_cert=?,
                blood_group=?, sex=?, photo=?, experience=?,
                guardian_name=?, guardian_profession=?, guardian_address_1=?, guardian_address_2=?,
                guardian_phone=?, guardian_email=?, guardian_relationship=?, guardian_monthly_income=?,
                local_guardian_name=?, local_guardian_address_1=?, local_guardian_address_2=?, local_guardian_address_3=?, local_guardian_contact=?,
                reference_name=?, reference_address_1=?, reference_address_2=?, reference_address_3=?, reference_contact=?,
                expelled_answer=?, expelled_detail=?,
                office_university_batch=?, office_dept_batch=?, office_section=?, office_shift=?, office_decision=?, office_checked_by=?,
                promoter_source=?, promoter_name=?, promoter_address=?, promoter_contact=?, promoter_email=?,
                prime_student=?, prime_student_id=?, prime_department=?, prime_program=?, source_note=?
             WHERE id=?'
        )->execute([
            $status, $dept_id, $program_id, $year, $semester,
            $student_name, $father_name, $mother_name,
            $present_address_1, $present_address_2, $present_area, $present_district_id, $present_thana_id, $present_post_code, $present_contact, $present_email,
            $permanent_address_1, $permanent_address_2, $permanent_area, $permanent_district_id, $permanent_thana_id, $permanent_post_code, $permanent_contact, $permanent_email,
            $nationality, $date_of_birth, $place_of_birth, $religion, $nid_birth_cert,
            $blood_group, $sex, $photo, $experience,
            $guardian_name, $guardian_profession, $guardian_address_1, $guardian_address_2,
            $guardian_phone, $guardian_email, $guardian_relationship, $guardian_monthly_income,
            $local_guardian_name, $local_guardian_address_1, $local_guardian_address_2, $local_guardian_address_3, $local_guardian_contact,
            $reference_name, $reference_address_1, $reference_address_2, $reference_address_3, $reference_contact,
            $expelled_answer, $expelled_detail,
            $office_university_batch, $office_dept_batch, $office_section, $office_shift, $office_decision, $office_checked_by,
            $promoter_source, $promoter_name, $promoter_address, $promoter_contact, $promoter_email,
            $prime_student, $prime_student_id, $prime_department, $prime_program, $source_note,
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
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>
<style>
/* Academic table: make TomSelect controls fill each cell properly */
#acadTable .ts-wrapper { width: 100%; min-width: 0; }
#acadTable td { vertical-align: middle; padding: .25rem .4rem; }
</style>

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
                                <?php foreach ([
                                    'ready_for_admission' => 'Ready for Admission',
                                    'draft'               => 'Draft',
                                    'cancelled'           => 'Cancelled',
                                    'admission_complete'  => 'Admitted',
                                ] as $sv => $sl): ?>
                                <option value="<?= $sv ?>" <?= ($app['status'] === $sv) ? 'selected' : '' ?>><?= h($sl) ?></option>
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
                        <!-- ── Permanent Address ─────────────────────────────── -->
                        <div class="col-12"><strong class="small text-muted text-uppercase">Permanent Address</strong></div>
                        <div class="col-12 col-md-6"><label class="form-label">House No./Building Name</label><input type="text" name="permanent_address_1" class="form-control" value="<?= $v('permanent_address_1') ?>" placeholder="e.g. House 12, ABC Tower"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Road Name/Street</label><input type="text" name="permanent_address_2" class="form-control" value="<?= $v('permanent_address_2') ?>" placeholder="e.g. Road 5, Mirpur Avenue"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Area/Locality <span class="text-muted small">(optional)</span></label><input type="text" name="permanent_area" class="form-control" value="<?= $v('permanent_area') ?>" placeholder="e.g. Dhanmondi, Gulshan"></div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">District</label>
                            <div class="searchable-select-wrap" style="position:relative;">
                                <input type="text" class="form-control adm-ss-trigger" id="perm_district_search" placeholder="Search district…" autocomplete="off" data-target="permanent_district_id">
                                <input type="hidden" name="permanent_district_id" id="permanent_district_id" value="<?= $v('permanent_district_id') ?>">
                                <div class="adm-ss-list" id="perm_district_list" style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none;">
                                    <div class="adm-ss-item" data-value="" data-label="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem;">— None —</div>
                                    <?php $cur_div = ''; foreach ($bd_districts as $dist): if ($dist['division'] !== $cur_div) { $cur_div = $dist['division']; ?><div class="adm-ss-item" data-value="" data-label="" style="padding:3px 12px;font-weight:600;background:#f0f4ff;pointer-events:none;font-size:.75rem;color:#555;">— <?= h($cur_div) ?> Division —</div><?php } ?><div class="adm-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= h($dist['name']) ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem;"><?= h($dist['name']) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Thana/Upazila</label>
                            <div class="searchable-select-wrap" style="position:relative;">
                                <input type="text" class="form-control adm-ss-trigger" id="perm_thana_search" placeholder="Select district first…" autocomplete="off" data-target="permanent_thana_id">
                                <input type="hidden" name="permanent_thana_id" id="permanent_thana_id" value="<?= $v('permanent_thana_id') ?>">
                                <div class="adm-ss-list" id="perm_thana_list" style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none;">
                                    <div class="adm-ss-item" data-value="" data-label="" data-district="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem;">— None —</div>
                                    <?php foreach ($bd_thanas as $th): ?><div class="adm-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= h($th['name']) ?>" data-district="<?= $th['district_id'] ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem;"><?= h($th['name']) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3"><label class="form-label">Mobile Number</label><input type="text" name="permanent_contact" class="form-control" value="<?= $v('permanent_contact') ?>" placeholder="01XXXXXXXXX"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Post Code</label><input type="text" name="permanent_post_code" class="form-control" value="<?= $v('permanent_post_code') ?>" placeholder="e.g. 1207"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Email</label><input type="email" name="permanent_email" class="form-control" value="<?= $v('permanent_email') ?>"></div>

                        <!-- ── Present Address ──────────────────────────────── -->
                        <div class="col-12"><hr class="my-1">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <strong class="small text-muted text-uppercase">Present Address</strong>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="same_as_permanent" value="1">
                                    <label class="form-check-label small" for="same_as_permanent">Same as Permanent Address</label>
                                </div>
                            </div>
                        </div>
                        <div id="present_address_fields">
                        <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">House No./Building Name</label><input type="text" name="present_address_1" class="form-control" value="<?= $v('present_address_1') ?>" placeholder="e.g. House 12, ABC Tower"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Road Name/Street</label><input type="text" name="present_address_2" class="form-control" value="<?= $v('present_address_2') ?>" placeholder="e.g. Road 5, Mirpur Avenue"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Area/Locality <span class="text-muted small">(optional)</span></label><input type="text" name="present_area" class="form-control" value="<?= $v('present_area') ?>" placeholder="e.g. Dhanmondi, Gulshan"></div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">District</label>
                            <div class="searchable-select-wrap" style="position:relative;">
                                <input type="text" class="form-control adm-ss-trigger" id="pres_district_search" placeholder="Search district…" autocomplete="off" data-target="present_district_id">
                                <input type="hidden" name="present_district_id" id="present_district_id" value="<?= $v('present_district_id') ?>">
                                <div class="adm-ss-list" id="pres_district_list" style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none;">
                                    <div class="adm-ss-item" data-value="" data-label="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem;">— None —</div>
                                    <?php $cur_div = ''; foreach ($bd_districts as $dist): if ($dist['division'] !== $cur_div) { $cur_div = $dist['division']; ?><div class="adm-ss-item" data-value="" data-label="" style="padding:3px 12px;font-weight:600;background:#f0f4ff;pointer-events:none;font-size:.75rem;color:#555;">— <?= h($cur_div) ?> Division —</div><?php } ?><div class="adm-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= h($dist['name']) ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem;"><?= h($dist['name']) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Thana/Upazila</label>
                            <div class="searchable-select-wrap" style="position:relative;">
                                <input type="text" class="form-control adm-ss-trigger" id="pres_thana_search" placeholder="Select district first…" autocomplete="off" data-target="present_thana_id">
                                <input type="hidden" name="present_thana_id" id="present_thana_id" value="<?= $v('present_thana_id') ?>">
                                <div class="adm-ss-list" id="pres_thana_list" style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none;">
                                    <div class="adm-ss-item" data-value="" data-label="" data-district="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem;">— None —</div>
                                    <?php foreach ($bd_thanas as $th): ?><div class="adm-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= h($th['name']) ?>" data-district="<?= $th['district_id'] ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem;"><?= h($th['name']) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3"><label class="form-label">Mobile Number</label><input type="text" name="present_contact" class="form-control" value="<?= $v('present_contact') ?>" placeholder="01XXXXXXXXX"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Post Code</label><input type="text" name="present_post_code" class="form-control" value="<?= $v('present_post_code') ?>" placeholder="e.g. 1207"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Email</label><input type="email" name="present_email" class="form-control" value="<?= $v('present_email') ?>"></div>
                        </div><!-- /row -->
                        </div><!-- /present_address_fields -->
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
                                <th style="min-width:160px">Exam Name</th>
                                <th style="min-width:80px">Session</th>
                                <th style="min-width:130px">Group/Subject</th>
                                <th style="min-width:170px">Board/University</th>
                                <th style="min-width:68px">Year</th>
                                <th style="min-width:85px">Division/Grade</th>
                                <th style="min-width:80px">Marks / GPA / CGPA</th>
                                <th style="width:38px"></th>
                            </tr>
                        </thead>
                        <tbody id="acadBody">
                            <?php
                            $rows_to_render = !empty($acad_records) ? $acad_records : [[]];
                            foreach ($rows_to_render as $ar):
                            ?>
                            <tr class="acad-row">
                                <td>
                                    <select name="exam_name[]" class="acad-exam-sel" style="width:100%">
                                        <?php $all_known_exams_edit = ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level','Bachelor Degree','Diploma']; ?>
                                        <option value="">— Select —</option>
                                        <?php foreach ($all_known_exams_edit as $en): ?>
                                        <option value="<?= h($en) ?>" <?= ($ar['exam_name'] ?? '') === $en ? 'selected' : '' ?>><?= h($en) ?></option>
                                        <?php endforeach; ?>
                                        <?php if (!empty($ar['exam_name']) && !in_array($ar['exam_name'], $all_known_exams_edit)): ?>
                                        <option value="<?= h($ar['exam_name']) ?>" selected><?= h($ar['exam_name']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="acad_session[]" class="form-control form-control-sm" value="<?= h($ar['session'] ?? '') ?>"></td>
                                <td class="acad-group-td">
                                    <?php $is_subject_mode_edit = in_array($ar['exam_name'] ?? '', ['Bachelor Degree','Diploma']); ?>
                                    <select name="group_name[]" class="acad-group-sel" style="width:100%" <?= $is_subject_mode_edit ? 'disabled' : '' ?>>
                                        <option value="">— Select —</option>
                                        <?php if (!$is_subject_mode_edit && !empty($ar['group_name'])): ?>
                                        <option value="<?= h($ar['group_name']) ?>" selected><?= h($ar['group_name']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <input type="text" name="group_name[]"
                                           class="acad-subject-inp form-control form-control-sm<?= $is_subject_mode_edit ? '' : ' d-none' ?>"
                                           placeholder="Enter subject name"
                                           value="<?= $is_subject_mode_edit ? h($ar['group_name'] ?? '') : '' ?>"
                                           <?= $is_subject_mode_edit ? '' : 'disabled' ?>>
                                </td>
                                <td>
                                    <select name="board_university[]" class="acad-board-sel" style="width:100%">
                                        <option value="">— Select —</option>
                                        <?php if (!empty($ar['board_university'])): ?>
                                        <option value="<?= h($ar['board_university']) ?>" selected><?= h($ar['board_university']) ?></option>
                                        <?php endif; ?>
                                    </select>
                                </td>
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

            <!-- Section 9b: Student Source -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-user-friends me-2 text-primary"></i>Student Source</div>
                <div class="card-body">
                    <div class="row g-3">
                        <!-- Promoter -->
                        <div class="col-12">
                            <label class="form-label">Student source promoter?</label>
                            <div class="d-flex gap-3 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="promoter_source" id="promoter_no" value="No"
                                           <?= (($app['promoter_source'] ?? 'No') === 'No') ? 'checked' : '' ?> onchange="togglePromoter()">
                                    <label class="form-check-label" for="promoter_no">No</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="promoter_source" id="promoter_yes" value="Yes"
                                           <?= (($app['promoter_source'] ?? '') === 'Yes') ? 'checked' : '' ?> onchange="togglePromoter()">
                                    <label class="form-check-label" for="promoter_yes">Yes</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12" id="promoter_fields_wrap" style="<?= (($app['promoter_source'] ?? '') !== 'Yes') ? 'display:none' : '' ?>">
                            <div class="row g-3">
                                <div class="col-12 col-md-6"><label class="form-label">Promoter Name</label><input type="text" name="promoter_name" class="form-control" value="<?= $v('promoter_name') ?>"></div>
                                <div class="col-12 col-md-6"><label class="form-label">Contact Number</label><input type="text" name="promoter_contact" class="form-control" value="<?= $v('promoter_contact') ?>"></div>
                                <div class="col-12 col-md-6"><label class="form-label">Email</label><input type="email" name="promoter_email" class="form-control" value="<?= $v('promoter_email') ?>"></div>
                                <div class="col-12 col-md-6"><label class="form-label">Address</label><input type="text" name="promoter_address" class="form-control" value="<?= $v('promoter_address') ?>"></div>
                            </div>
                        </div>

                        <!-- Prime Student -->
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="prime_student" id="prime_student" value="Yes"
                                       <?= (($app['prime_student'] ?? '') === 'Yes') ? 'checked' : '' ?> onchange="togglePrimeStudent()">
                                <label class="form-check-label" for="prime_student">Prime Student?</label>
                            </div>
                        </div>
                        <div class="col-12" id="prime_student_fields_wrap" style="<?= (($app['prime_student'] ?? '') !== 'Yes') ? 'display:none' : '' ?>">
                            <div class="row g-3">
                                <div class="col-12 col-md-4"><label class="form-label">Student ID</label><input type="text" name="prime_student_id" class="form-control" value="<?= $v('prime_student_id') ?>"></div>
                                <div class="col-12 col-md-4"><label class="form-label">Department</label><input type="text" name="prime_department" class="form-control" value="<?= $v('prime_department') ?>"></div>
                                <div class="col-12 col-md-4"><label class="form-label">Program</label><input type="text" name="prime_program" class="form-control" value="<?= $v('prime_program') ?>"></div>
                            </div>
                        </div>

                        <!-- Note -->
                        <div class="col-12">
                            <label class="form-label">Note</label>
                            <textarea name="source_note" class="form-control" rows="2" placeholder="Any additional note about the student source…"><?= $v('source_note') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 10: For Office Use Only -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-stamp me-2 text-secondary"></i>For Office Use Only</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="form-label">University Batch</label><input type="text" name="office_university_batch" class="form-control" value="<?= $v('office_university_batch') ?>"></div>
                        <div class="col-12 col-md-6"><label class="form-label">Department Batch</label><input type="text" name="office_dept_batch" class="form-control" value="<?= $v('office_dept_batch') ?>"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Section</label><input type="text" name="office_section" class="form-control" value="<?= $v('office_section') ?>"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Shift</label><input type="text" name="office_shift" class="form-control" value="<?= $v('office_shift') ?>"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Decision</label><input type="text" name="office_decision" class="form-control" value="<?= $v('office_decision') ?>"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Checked By</label><input type="text" name="office_checked_by" class="form-control" value="<?= $v('office_checked_by') ?>"></div>
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

// ── Academic Qualifications: exam/group/board data ───────────────────────────
var ACAD_DATA = {
    'SSC': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet'],
        defaultBoard: null, showGroup: true, isSubject: false
    },
    'Dakhil': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Bangladesh Madrasah Education Board'],
        defaultBoard: 'Bangladesh Madrasah Education Board', showGroup: true, isSubject: false
    },
    'O Level': {
        groups: [],
        boards: ['Cambridge','Edexcel'],
        defaultBoard: null, showGroup: false, isSubject: false
    },
    'SSC (Vocational)': {
        groups: ['Electrical','Mechanical','Computer','Civil','Electronics','Refrigeration & Air Conditioning','Welding & Fabrication','Auto Mechanic','Drafting (Civil)','Drafting (Mechanical)'],
        boards: ['Bangladesh Technical Education Board'],
        defaultBoard: 'Bangladesh Technical Education Board', showGroup: true, isSubject: false
    },
    'HSC': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet','Madrasah Education Board','Technical Education Board'],
        defaultBoard: null, showGroup: true, isSubject: false
    },
    'Alim': {
        groups: ['Science','Arts','Commerce'],
        boards: ['Bangladesh Madrasah Education Board'],
        defaultBoard: 'Bangladesh Madrasah Education Board', showGroup: true, isSubject: false
    },
    'A Level': {
        groups: [],
        boards: ['Cambridge','Edexcel'],
        defaultBoard: null, showGroup: false, isSubject: false
    },
    'Bachelor Degree': {
        groups: [], boards: [], defaultBoard: null, showGroup: false, isSubject: true
    },
    'Diploma': {
        groups: [], boards: [], defaultBoard: null, showGroup: false, isSubject: true
    }
};

function acadUpdateGroupBoard(tr, newExam, setDefault) {
    var data     = ACAD_DATA[newExam] || { groups: [], boards: [], defaultBoard: null, showGroup: true, isSubject: false };
    var tsGroup  = tr._tsGroup;
    var tsBoard  = tr._tsBoard;
    var groupTd  = tr.querySelector('.acad-group-td');
    var subjectInp = tr.querySelector('.acad-subject-inp');
    var tsWrapper  = groupTd ? groupTd.querySelector('.ts-wrapper') : null;

    if (data.isSubject) {
        tsGroup.disable();
        if (tsWrapper) tsWrapper.style.display = 'none';
        if (subjectInp) { subjectInp.disabled = false; subjectInp.classList.remove('d-none'); }
        if (groupTd) groupTd.style.opacity = '';
    } else {
        tsGroup.enable();
        if (tsWrapper) tsWrapper.style.display = '';
        if (subjectInp) { subjectInp.disabled = true; subjectInp.value = ''; subjectInp.classList.add('d-none'); }
        tsGroup.clearOptions();
        tsGroup.addOption({ value: '', text: '— Select —' });
        data.groups.forEach(function(g) { tsGroup.addOption({ value: g, text: g }); });
        if (!data.showGroup) {
            tsGroup.setValue('', true);
            if (groupTd) groupTd.style.opacity = '0.35';
        } else {
            if (groupTd) groupTd.style.opacity = '';
        }
    }

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
        placeholder: '— Select / Type —',
        dropdownParent: 'body'
    });
    var tsGroup = new TomSelect(groupSel, {
        create: true, allowEmptyOption: true, maxOptions: 30,
        plugins: ['clear_button'],
        placeholder: '— Select / Type —',
        dropdownParent: 'body'
    });
    var tsBoard = new TomSelect(boardSel, {
        create: true, allowEmptyOption: true, maxOptions: 20,
        plugins: ['clear_button'],
        placeholder: '— Select / Type —',
        dropdownParent: 'body'
    });

    tr._tsExam  = tsExam;
    tr._tsGroup = tsGroup;
    tr._tsBoard = tsBoard;

    if (savedExam) {
        var data = ACAD_DATA[savedExam] || { groups: [], boards: [], defaultBoard: null, showGroup: true, isSubject: false };
        var groupTd    = tr.querySelector('.acad-group-td');
        var tsWrapper  = groupTd ? groupTd.querySelector('.ts-wrapper') : null;
        var subjectInp = tr.querySelector('.acad-subject-inp');

        if (data.isSubject) {
            tsGroup.disable();
            if (tsWrapper) tsWrapper.style.display = 'none';
            if (subjectInp) { subjectInp.disabled = false; subjectInp.classList.remove('d-none'); }
        } else {
            tsGroup.clearOptions();
            tsGroup.addOption({ value: '', text: '— Select —' });
            data.groups.forEach(function(g) { tsGroup.addOption({ value: g, text: g }); });
            if (savedGroup && data.groups.indexOf(savedGroup) === -1) tsGroup.addOption({ value: savedGroup, text: savedGroup });
            tsGroup.setValue(savedGroup, true);

            tsBoard.clearOptions();
            tsBoard.addOption({ value: '', text: '— Select —' });
            data.boards.forEach(function(b) { tsBoard.addOption({ value: b, text: b }); });
            if (savedBoard && data.boards.indexOf(savedBoard) === -1) tsBoard.addOption({ value: savedBoard, text: savedBoard });
            tsBoard.setValue(savedBoard, true);

            if (!data.showGroup && groupTd) groupTd.style.opacity = '0.35';
        }
    }

    tsExam.on('change', function(val) {
        acadUpdateGroupBoard(tr, val, true);
    });
}

document.getElementById('addAcadRow').addEventListener('click', function() {
    var tbody = document.getElementById('acadBody');
    var tr = document.createElement('tr');
    tr.className = 'acad-row';
    tr.innerHTML = '<td>'
        + '<select name="exam_name[]" class="acad-exam-sel" style="width:100%">'
        + '<option value="">— Select —</option>'
        + ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level','Bachelor Degree','Diploma'].map(function(e){return '<option value="'+e+'">'+e+'</option>';}).join('')
        + '</select></td>'
        + '<td><input type="text" name="acad_session[]" class="form-control form-control-sm"></td>'
        + '<td class="acad-group-td">'
        +   '<select name="group_name[]" class="acad-group-sel" style="width:100%"><option value="">— Select —</option></select>'
        +   '<input type="text" name="group_name[]" class="acad-subject-inp form-control form-control-sm d-none" placeholder="Enter subject name" disabled>'
        + '</td>'
        + '<td><select name="board_university[]" class="acad-board-sel" style="width:100%"><option value="">— Select —</option></select></td>'
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

document.querySelectorAll('#acadBody tr.acad-row').forEach(function(tr) {
    initAcadRow(tr);
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
function togglePromoter() {
    document.getElementById('promoter_fields_wrap').style.display =
        document.getElementById('promoter_yes').checked ? '' : 'none';
}
function togglePrimeStudent() {
    document.getElementById('prime_student_fields_wrap').style.display =
        document.getElementById('prime_student').checked ? '' : 'none';
}

// ── Address: searchable district/thana selects ────────────────────────────────
var ADM_THANA_MAP = <?= json_encode($bd_thana_map, JSON_UNESCAPED_UNICODE) ?>;

function admInitAddressSelect(wrap) {
    var input    = wrap.querySelector('.adm-ss-trigger');
    if (!input) return;
    var targetId = input.dataset.target;
    var targetEl = document.getElementById(targetId);
    var list     = wrap.querySelector('.adm-ss-list');
    var items    = Array.from(list.querySelectorAll('.adm-ss-item'));

    var currentVal = targetEl ? targetEl.value : '';
    if (currentVal) {
        var match = items.find(function(i) { return String(i.dataset.value) === String(currentVal); });
        if (match) input.value = match.dataset.label;
    }

    // Pre-filter thanas if district is already set
    if (targetId === 'permanent_thana_id' && document.getElementById('permanent_district_id').value) {
        admFilterThanas('perm_thana_list', 'perm_thana_search', 'permanent_thana_id', document.getElementById('permanent_district_id').value);
        if (currentVal) {
            var mth = items.find(function(i) { return String(i.dataset.value) === String(currentVal); });
            if (mth) input.value = mth.dataset.label;
        }
    }
    if (targetId === 'present_thana_id' && document.getElementById('present_district_id').value) {
        admFilterThanas('pres_thana_list', 'pres_thana_search', 'present_thana_id', document.getElementById('present_district_id').value);
        if (currentVal) {
            var mth2 = items.find(function(i) { return String(i.dataset.value) === String(currentVal); });
            if (mth2) input.value = mth2.dataset.label;
        }
    }

    input.addEventListener('focus', function() { list.style.display = ''; filterAdmList(''); });
    input.addEventListener('input', function() { filterAdmList(this.value); list.style.display = ''; });

    function filterAdmList(q) {
        q = q.toLowerCase();
        items.forEach(function(item) {
            var header = item.style.pointerEvents === 'none';
            item.style.display = (header || item.textContent.toLowerCase().includes(q)) ? '' : 'none';
        });
    }

    items.forEach(function(item) {
        if (item.style.pointerEvents === 'none') return;
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            if (targetEl) targetEl.value = item.dataset.value;
            input.value = item.dataset.label;
            list.style.display = 'none';
            if (targetId === 'permanent_district_id') {
                admFilterThanas('perm_thana_list', 'perm_thana_search', 'permanent_thana_id', item.dataset.value, true);
            }
            if (targetId === 'present_district_id') {
                admFilterThanas('pres_thana_list', 'pres_thana_search', 'present_thana_id', item.dataset.value, true);
            }
        });
    });

    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) list.style.display = 'none';
    });
}

function admFilterThanas(listId, searchId, valId, districtId, clearVal) {
    var list  = document.getElementById(listId);
    var input = document.getElementById(searchId);
    var valEl = document.getElementById(valId);
    input.placeholder = districtId ? 'Search thana…' : 'Select district first…';
    if (clearVal) {
        input.value = '';
        if (valEl) valEl.value = '';
    }
    Array.from(list.querySelectorAll('.adm-ss-item')).forEach(function(item) {
        var d = item.dataset.district;
        item.style.display = (d === undefined || d === '' || d === districtId) ? '' : 'none';
    });
}

document.querySelectorAll('.searchable-select-wrap').forEach(function(wrap) {
    if (wrap.querySelector('.adm-ss-trigger')) admInitAddressSelect(wrap);
});

// ── Address: "Same as Permanent" checkbox ─────────────────────────────────────
(function() {
    var cb = document.getElementById('same_as_permanent');
    if (!cb) return;

    cb.addEventListener('change', function() {
        var isSame = this.checked;
        var wrap   = document.getElementById('present_address_fields');

        var fields = [
            ['permanent_address_1', 'present_address_1'],
            ['permanent_address_2', 'present_address_2'],
            ['permanent_area',      'present_area'],
            ['permanent_contact',   'present_contact'],
            ['permanent_post_code', 'present_post_code'],
            ['permanent_email',     'present_email'],
        ];
        fields.forEach(function(pair) {
            var src  = document.querySelector('[name="' + pair[0] + '"]');
            var dest = document.querySelector('[name="' + pair[1] + '"]');
            if (!src || !dest) return;
            if (isSame) {
                dest.value = src.value;
                dest.setAttribute('readonly', true);
            } else {
                dest.removeAttribute('readonly');
            }
        });

        var permDistId  = document.getElementById('permanent_district_id');
        var presDistId  = document.getElementById('present_district_id');
        var permDistTxt = document.getElementById('perm_district_search');
        var presDistTxt = document.getElementById('pres_district_search');
        if (isSame && permDistId && presDistId) {
            presDistId.value  = permDistId.value;
            presDistTxt.value = permDistTxt.value;
            presDistTxt.setAttribute('readonly', true);
            admFilterThanas('pres_thana_list', 'pres_thana_search', 'present_thana_id', permDistId.value, true);
        } else if (!isSame && presDistTxt) {
            presDistTxt.removeAttribute('readonly');
        }

        var permThanaId  = document.getElementById('permanent_thana_id');
        var presThanaId  = document.getElementById('present_thana_id');
        var permThanaTxt = document.getElementById('perm_thana_search');
        var presThanaTxt = document.getElementById('pres_thana_search');
        if (isSame && permThanaId && presThanaId) {
            presThanaId.value  = permThanaId.value;
            presThanaTxt.value = permThanaTxt.value;
            presThanaTxt.setAttribute('readonly', true);
        } else if (!isSame && presThanaTxt) {
            presThanaTxt.removeAttribute('readonly');
        }

        if (wrap) wrap.style.opacity = isSame ? '0.6' : '';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
