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
    'SELECT fs.id, fs.form_number, fs.buyer_name, fs.buyer_mobile, fs.buyer_email, fs.sold_at,
            sd.student_name  AS sd_student_name,
            sd.father_name   AS sd_father_name,
            sd.mother_name   AS sd_mother_name,
            sd.gender        AS sd_gender,
            sd.date_of_birth AS sd_date_of_birth,
            sd.blood_group   AS sd_blood_group,
            sd.nationality   AS sd_nationality,
            sd.place_of_birth AS sd_place_of_birth,
            sd.nid_birth_cert AS sd_nid_birth_cert,
            sd.religion      AS sd_religion,
            sd.permanent_address_1  AS sd_perm_addr1,
            sd.permanent_address_2  AS sd_perm_addr2,
            sd.permanent_area       AS sd_perm_area,
            sd.permanent_district_id AS sd_perm_district_id,
            sd.permanent_thana_id   AS sd_perm_thana_id,
            sd.permanent_post_code  AS sd_perm_post_code,
            sd.present_same_as_permanent AS sd_same_as_perm,
            sd.present_address_1   AS sd_pres_addr1,
            sd.present_address_2   AS sd_pres_addr2,
            sd.present_area        AS sd_pres_area,
            sd.present_district_id AS sd_pres_district_id,
            sd.present_thana_id    AS sd_pres_thana_id,
            sd.present_post_code   AS sd_pres_post_code,
            sd.experience          AS sd_experience,
            sd.guardian_name       AS sd_guardian_name,
            sd.guardian_profession AS sd_guardian_profession,
            sd.guardian_relationship AS sd_guardian_relationship,
            sd.guardian_monthly_income AS sd_guardian_monthly_income,
            sd.guardian_address_1  AS sd_guardian_address_1,
            sd.guardian_address_2  AS sd_guardian_address_2,
            sd.guardian_phone      AS sd_guardian_phone,
            sd.guardian_email      AS sd_guardian_email,
            sd.local_guardian_name AS sd_local_guardian_name,
            sd.local_guardian_address_1 AS sd_local_guardian_address_1,
            sd.local_guardian_address_2 AS sd_local_guardian_address_2,
            sd.local_guardian_address_3 AS sd_local_guardian_address_3,
            sd.local_guardian_contact AS sd_local_guardian_contact,
            sd.reference_name      AS sd_reference_name,
            sd.reference_address_1 AS sd_reference_address_1,
            sd.reference_address_2 AS sd_reference_address_2,
            sd.reference_address_3 AS sd_reference_address_3,
            sd.reference_contact   AS sd_reference_contact
     FROM adm_form_sales fs
     LEFT JOIN adm_form_sale_student_details sd ON sd.form_sale_id = fs.id
     WHERE fs.status = \'pending\'
     ORDER BY fs.sold_at DESC
     LIMIT 200'
)->fetchAll();

// ── Academic records for pending form sales (for autofill) ───────────────────
$sd_acad_map = [];
if (!empty($pending_forms)) {
    $fs_ids = array_column($pending_forms, 'id');
    $in_placeholders = implode(',', array_fill(0, count($fs_ids), '?'));
    try {
        $acad_raw = db()->prepare(
            "SELECT form_sale_id, exam_name, session, group_name, board_university,
                    year_of_passing, division_grade, total_marks_cgpa
             FROM adm_form_sale_academic_records
             WHERE form_sale_id IN ($in_placeholders)
             ORDER BY sort_order ASC"
        );
        $acad_raw->execute($fs_ids);
        foreach ($acad_raw->fetchAll() as $ar) {
            $sd_acad_map[(int)$ar['form_sale_id']][] = [
                'exam_name'        => $ar['exam_name']        ?? '',
                'session'          => $ar['session']          ?? '',
                'group_name'       => $ar['group_name']       ?? '',
                'board_university' => $ar['board_university'] ?? '',
                'year_of_passing'  => $ar['year_of_passing']  ?? '',
                'division_grade'   => $ar['division_grade']   ?? '',
                'total_marks_cgpa' => $ar['total_marks_cgpa'] ?? '',
            ];
        }
    } catch (Throwable $_ae) {}
}

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

$financial_programs = adm_get_financial_programs();
$financial_programs_by_id = [];
$financial_programs_map = [];
foreach ($financial_programs as $fp) {
    $financial_programs_by_id[(int)$fp['id']] = $fp;
    $financial_programs_map[(int)$fp['id']] = [
        'program_name'             => $fp['program_name'],
        'total_semesters'          => (int)$fp['total_semesters'],
        'total_months'             => (int)$fp['total_months'],
        'tuition_per_semester'     => (float)$fp['tuition_per_semester'],
        'admission_fees'           => (float)$fp['admission_fees'],
        'reg_fee_per_semester'     => (float)$fp['reg_fee_per_semester'],
        'fixed_institutional_fees' => (float)$fp['fixed_institutional_fees'],
        'english_course_fee'       => (float)$fp['english_course_fee'],
        'form_id_fee'              => (float)$fp['form_id_fee'],
    ];
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
    $financial_package_id   = (int)($_POST['financial_package_id']  ?? 0) ?: null;

    $scholarship_label      = trim($_POST['scholarship_label']     ?? '');
    $sc_discount_type       = in_array($_POST['discount_type']     ?? '', ['percentage', 'fixed'], true)
                              ? $_POST['discount_type'] : 'percentage';
    $sc_discount_pct        = (float)($_POST['discount_pct']       ?? 0);
    $sc_fixed_input         = (float)($_POST['scholarship_amount_fixed'] ?? 0);
    $sc_applies_fixed       = isset($_POST['applies_to_fixed'])    ? 1 : 0;
    $sc_applies_english     = isset($_POST['applies_to_english'])  ? 1 : 0;
    $scholarship_amount     = 0.0;

    $financial_package_name = null;
    $financial_total_semesters = null;
    $financial_total_months = null;
    $financial_tuition_per_semester = null;
    $financial_admission_fee = null;
    $financial_registration_fee_per_semester = null;
    $financial_fixed_institutional_fees = null;
    $financial_english_course_fee = null;
    $financial_form_id_fee = null;

    if ($student_name === '') $errors[] = 'Student name is required.';
    if (!$financial_package_id) {
        $errors[] = 'Please assign a financial package.';
    } elseif (!isset($financial_programs_by_id[$financial_package_id])) {
        $errors[] = 'Selected financial package is invalid.';
    } else {
        $pkg = $financial_programs_by_id[$financial_package_id];
        $financial_package_name = $pkg['program_name'];
        $financial_total_semesters = (int)$pkg['total_semesters'];
        $financial_total_months = (int)$pkg['total_months'];
        $financial_tuition_per_semester = (float)$pkg['tuition_per_semester'];
        $financial_admission_fee = (float)$pkg['admission_fees'];
        $financial_registration_fee_per_semester = (float)$pkg['reg_fee_per_semester'];
        $financial_fixed_institutional_fees = (float)$pkg['fixed_institutional_fees'];
        $financial_english_course_fee = (float)$pkg['english_course_fee'];
        $financial_form_id_fee = (float)$pkg['form_id_fee'];
    }

    // Scholarship amount computation
    if ($scholarship_label !== '' && $financial_tuition_per_semester !== null) {
        $sc_total_sems   = max(1, (int)$financial_total_semesters);
        $sc_fixed_sem    = round((float)$financial_fixed_institutional_fees / $sc_total_sems, 2);
        $sc_english_sem  = round((float)$financial_english_course_fee       / $sc_total_sems, 2);
        if ($sc_discount_type === 'percentage') {
            if ($sc_discount_pct >= 0.0001 && $sc_discount_pct <= 100) {
                $scholarship_amount  = round($financial_tuition_per_semester * $sc_discount_pct / 100, 2);
                if ($sc_applies_fixed)   $scholarship_amount += round($sc_fixed_sem   * $sc_discount_pct / 100, 2);
                if ($sc_applies_english) $scholarship_amount += round($sc_english_sem * $sc_discount_pct / 100, 2);
                $scholarship_amount = round($scholarship_amount, 2);
            } else {
                $scholarship_label = '';
            }
        } else {
            if ($sc_fixed_input >= 0.01) {
                $scholarship_amount = round($sc_fixed_input, 2);
                $sc_discount_pct    = 0.0;
            } else {
                $scholarship_label = '';
            }
        }
    }

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

        $application_values = [
            $app_number, $status, $dept_id, $program_id, $year, $semester,
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
            $financial_package_id, $financial_package_name, $financial_total_semesters, $financial_total_months,
            $financial_tuition_per_semester, $financial_admission_fee, $financial_registration_fee_per_semester,
            $financial_fixed_institutional_fees, $financial_english_course_fee, $financial_form_id_fee,
            $scholarship_label ?: null, $scholarship_amount, $sc_discount_type, $sc_discount_pct, $sc_applies_fixed, $sc_applies_english,
            $user['id'],
        ];
        $application_placeholders = implode(',', array_fill(0, count($application_values), '?'));

        db()->prepare(
            'INSERT INTO admissions_applications
               (app_number, status, dept_id, program_id, year, semester,
                student_name, father_name, mother_name,
                present_address_1, present_address_2, present_area, present_district_id, present_thana_id, present_post_code, present_contact, present_email,
                permanent_address_1, permanent_address_2, permanent_area, permanent_district_id, permanent_thana_id, permanent_post_code, permanent_contact, permanent_email,
                nationality, date_of_birth, place_of_birth, religion, nid_birth_cert,
                blood_group, sex, photo, experience,
                guardian_name, guardian_profession, guardian_address_1, guardian_address_2,
                guardian_phone, guardian_email, guardian_relationship, guardian_monthly_income,
                local_guardian_name, local_guardian_address_1, local_guardian_address_2, local_guardian_address_3, local_guardian_contact,
                reference_name, reference_address_1, reference_address_2, reference_address_3, reference_contact,
                 expelled_answer, expelled_detail,
                 office_university_batch, office_dept_batch, office_section, office_shift, office_decision, office_checked_by,
                 financial_package_id, financial_package_name, financial_total_semesters, financial_total_months,
                 financial_tuition_per_semester, financial_admission_fee, financial_registration_fee_per_semester,
                 financial_fixed_institutional_fees, financial_english_course_fee, financial_form_id_fee,
                 scholarship_label, scholarship_amount, scholarship_discount_type, scholarship_discount_pct, scholarship_applies_to_fixed, scholarship_applies_to_english,
                 created_by)
             VALUES (' . $application_placeholders . ')'
        )->execute($application_values);
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
<style>
.adm-step-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .4rem .85rem; border: 2px solid transparent; background: transparent;
    border-radius: 2rem; font-size: .82rem; font-weight: 500; color: #6c757d;
    transition: all .18s; white-space: nowrap; cursor: pointer; flex-shrink: 0;
}
.adm-step-btn:hover { background: #f1f3f5; color: #343a40; }
.adm-step-btn.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }
.adm-step-btn.visited { color: #0d6efd; border-color: #c8d9fb; background: #eef3ff; }
.adm-step-btn .step-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%; background: #dee2e6;
    color: #495057; font-size: .68rem; font-weight: 700; flex-shrink: 0; line-height: 1;
}
.adm-step-btn.active .step-num { background: rgba(255,255,255,.28); color: #fff; }
.adm-step-btn.visited .step-num { background: #0d6efd; color: #fff; }
.adm-step-divider { color: #ced4da; font-size: .65rem; flex-shrink: 0; }
.adm-tab-pane { display: none; }
.adm-tab-pane.active { display: block; }
.adm-section-label {
    display: block; font-size: .68rem; font-weight: 700; letter-spacing: .09em;
    text-transform: uppercase; color: #adb5bd; margin-bottom: .6rem;
}
.adm-card-hdr {
    background: linear-gradient(135deg,#f8f9fb 0%,#ffffff 100%);
    border-bottom: 1px solid #f0f2f5; padding: .7rem 1rem;
}
.adm-card-hdr .card-icon {
    width: 30px; height: 30px; border-radius: 8px; display: inline-flex;
    align-items: center; justify-content: center; font-size: .8rem; flex-shrink: 0;
}
.adm-fp-summary { background: #f8f9fb; border: 1px solid #e9ecef; border-radius: 10px; padding: 1rem; }
.adm-fp-stat { text-align: center; }
.adm-fp-stat .label { font-size: .7rem; color: #6c757d; margin-bottom: .15rem; }
.adm-fp-stat .value { font-size: .9rem; font-weight: 600; color: #212529; }
.adm-address-sep { border: 0; border-top: 2px dashed #dee2e6; margin: 1.25rem 0; }
.adm-nav-bar { background: #fff; border-top: 1px solid #e9ecef; border-radius: 0 0 12px 12px; }
.acad-row { background: #fff; border: 1px solid #dee2e6; border-radius: .375rem; margin-bottom: .5rem; padding: .5rem; }
#acadBody .acad-row + .acad-row { border-top-color: #dee2e6; border-top-left-radius: 0; border-top-right-radius: 0; margin-top: -.0625rem; }
#acadBody .acad-row:first-child { border-top-left-radius: .375rem; border-top-right-radius: .375rem; }
#acadBody .acad-row:last-child  { border-bottom-left-radius: .375rem; border-bottom-right-radius: .375rem; }
#acadBody .acad-row:not(:first-child) { border-top-left-radius: 0; border-top-right-radius: 0; }
#acadBody .acad-row:not(:last-child)  { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.acad-group-td.ts-hidden-accessible ~ .ts-wrapper { opacity: 0.35; pointer-events: none; }
@media (max-width: 575px) {
    .adm-step-btn .step-label { display: none; }
    .adm-step-btn { padding: .35rem .55rem; }
    .adm-step-btn .step-num { width: 22px; height: 22px; font-size: .75rem; }
}
</style>

<!-- Page Header -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i>New Admission Application</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active">New Application</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/admissions/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back to List
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
    <div class="d-flex align-items-start gap-2">
        <i class="fas fa-exclamation-triangle text-danger mt-1"></i>
        <div>
            <strong>Please fix the following before saving:</strong>
            <ul class="mb-0 mt-1 ps-3">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>


<form method="post" enctype="multipart/form-data" novalidate id="admCreateForm">
    <?= csrf_field() ?>

    <!-- ── Step Navigation Bar ──────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-2 p-sm-3">
            <div class="d-flex align-items-center overflow-auto gap-0 pb-1" id="admStepNav"
                 style="-webkit-overflow-scrolling:touch;scrollbar-width:none">
                <button type="button" class="adm-step-btn active" data-step="1" id="admStep1">
                    <span class="step-num">1</span>
                    <span class="step-label">Application</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="2" id="admStep2">
                    <span class="step-num">2</span>
                    <span class="step-label">Personal</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="3" id="admStep3">
                    <span class="step-num">3</span>
                    <span class="step-label">Address</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="4" id="admStep4">
                    <span class="step-num">4</span>
                    <span class="step-label">Academic</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="5" id="admStep5">
                    <span class="step-num">5</span>
                    <span class="step-label">Guardian</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="6" id="admStep6">
                    <span class="step-num">6</span>
                    <span class="step-label">Office</span>
                </button>
                <span class="adm-step-divider px-1">›</span>
                <button type="button" class="adm-step-btn" data-step="7" id="admStep7">
                    <span class="step-num">7</span>
                    <span class="step-label">Scholarship</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 1 — Application Setup
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane active" id="admPane1">

        <!-- Form Sale Lookup -->
        <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #ffc107 !important">
            <div class="adm-card-hdr d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="card-icon bg-warning bg-opacity-15"><i class="fas fa-receipt text-warning"></i></span>
                    <span class="fw-semibold">Link Form Sale</span>
                    <small class="text-muted fw-normal">— sets Application Number</small>
                </div>
                <span class="badge bg-warning text-dark rounded-pill px-3"><?= count($pending_forms) ?> Waiting</span>
            </div>
            <div class="card-body">
                <div id="fs_found_info" class="alert alert-success py-2 mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="display:none !important">
                    <span class="small">
                        <i class="fas fa-check-circle me-1"></i>
                        Linked: <strong id="fs_found_number"></strong> — <strong id="fs_found_name"></strong>
                        <span class="text-muted ms-1" id="fs_found_mobile"></span>
                        <span class="text-muted ms-1" id="fs_found_email"></span>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="fsClearBtn">
                        <i class="fas fa-unlink me-1"></i>Unlink
                    </button>
                </div>
                <div class="input-group mb-1">
                    <span class="input-group-text bg-light"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="fs_search_input" class="form-control"
                           placeholder="Search by form number, name, mobile or email…">
                </div>
                <div class="form-text mb-0">Click a row below to link it — the form number becomes the Application Number.</div>
            </div>
            <div class="table-responsive" style="max-height:220px;overflow-y:auto">
                <table class="table table-hover table-sm align-middle mb-0" id="fsPendingTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-3">Form No</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th class="d-none d-md-table-cell">Email</th>
                            <th class="d-none d-sm-table-cell text-end pe-3">Sold At</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($pending_forms)): ?>
                        <tr id="fsNoResults"><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-inbox me-2"></i>No forms waiting for admission.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pending_forms as $pf): ?>
                        <tr class="fs-pending-row" style="cursor:pointer"
                            data-id="<?= (int)$pf['id'] ?>"
                            data-form-number="<?= h($pf['form_number']) ?>"
                            data-name="<?= h($pf['buyer_name']) ?>"
                            data-mobile="<?= h($pf['buyer_mobile']) ?>"
                            data-email="<?= h($pf['buyer_email'] ?? '') ?>"
                            data-sd-student-name="<?= h($pf['sd_student_name'] ?? '') ?>"
                            data-sd-father-name="<?= h($pf['sd_father_name'] ?? '') ?>"
                            data-sd-mother-name="<?= h($pf['sd_mother_name'] ?? '') ?>"
                            data-sd-gender="<?= h($pf['sd_gender'] ?? '') ?>"
                            data-sd-dob="<?= h($pf['sd_date_of_birth'] ?? '') ?>"
                            data-sd-blood-group="<?= h($pf['sd_blood_group'] ?? '') ?>"
                            data-sd-nationality="<?= h($pf['sd_nationality'] ?? '') ?>"
                            data-sd-place-of-birth="<?= h($pf['sd_place_of_birth'] ?? '') ?>"
                            data-sd-nid="<?= h($pf['sd_nid_birth_cert'] ?? '') ?>"
                            data-sd-religion="<?= h($pf['sd_religion'] ?? '') ?>"
                            data-sd-perm-addr1="<?= h($pf['sd_perm_addr1'] ?? '') ?>"
                            data-sd-perm-addr2="<?= h($pf['sd_perm_addr2'] ?? '') ?>"
                            data-sd-perm-area="<?= h($pf['sd_perm_area'] ?? '') ?>"
                            data-sd-perm-district="<?= h($pf['sd_perm_district_id'] ?? '') ?>"
                            data-sd-perm-thana="<?= h($pf['sd_perm_thana_id'] ?? '') ?>"
                            data-sd-perm-post="<?= h($pf['sd_perm_post_code'] ?? '') ?>"
                            data-sd-same-as-perm="<?= $pf['sd_same_as_perm'] ?? '' ?>"
                            data-sd-pres-addr1="<?= h($pf['sd_pres_addr1'] ?? '') ?>"
                            data-sd-pres-addr2="<?= h($pf['sd_pres_addr2'] ?? '') ?>"
                            data-sd-pres-area="<?= h($pf['sd_pres_area'] ?? '') ?>"
                            data-sd-pres-district="<?= h($pf['sd_pres_district_id'] ?? '') ?>"
                            data-sd-pres-thana="<?= h($pf['sd_pres_thana_id'] ?? '') ?>"
                            data-sd-pres-post="<?= h($pf['sd_pres_post_code'] ?? '') ?>"
                            data-sd-experience="<?= h($pf['sd_experience'] ?? '') ?>"
                            data-sd-guardian-name="<?= h($pf['sd_guardian_name'] ?? '') ?>"
                            data-sd-guardian-profession="<?= h($pf['sd_guardian_profession'] ?? '') ?>"
                            data-sd-guardian-relationship="<?= h($pf['sd_guardian_relationship'] ?? '') ?>"
                            data-sd-guardian-income="<?= h($pf['sd_guardian_monthly_income'] ?? '') ?>"
                            data-sd-guardian-addr1="<?= h($pf['sd_guardian_address_1'] ?? '') ?>"
                            data-sd-guardian-addr2="<?= h($pf['sd_guardian_address_2'] ?? '') ?>"
                            data-sd-guardian-phone="<?= h($pf['sd_guardian_phone'] ?? '') ?>"
                            data-sd-guardian-email="<?= h($pf['sd_guardian_email'] ?? '') ?>"
                            data-sd-lg-name="<?= h($pf['sd_local_guardian_name'] ?? '') ?>"
                            data-sd-lg-addr1="<?= h($pf['sd_local_guardian_address_1'] ?? '') ?>"
                            data-sd-lg-addr2="<?= h($pf['sd_local_guardian_address_2'] ?? '') ?>"
                            data-sd-lg-addr3="<?= h($pf['sd_local_guardian_address_3'] ?? '') ?>"
                            data-sd-lg-contact="<?= h($pf['sd_local_guardian_contact'] ?? '') ?>"
                            data-sd-ref-name="<?= h($pf['sd_reference_name'] ?? '') ?>"
                            data-sd-ref-addr1="<?= h($pf['sd_reference_address_1'] ?? '') ?>"
                            data-sd-ref-addr2="<?= h($pf['sd_reference_address_2'] ?? '') ?>"
                            data-sd-ref-addr3="<?= h($pf['sd_reference_address_3'] ?? '') ?>"
                            data-sd-ref-contact="<?= h($pf['sd_reference_contact'] ?? '') ?>"
                            data-sd-acad="<?= h(json_encode($sd_acad_map[(int)$pf['id']] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>">
                            <td class="ps-3 fw-semibold text-warning"><?= h($pf['form_number']) ?></td>
                            <td>
                                <?= h($pf['buyer_name']) ?>
                                <?php if (!empty($pf['sd_student_name'])): ?>
                                <span class="badge bg-success ms-1" title="Student details submitted"><i class="fas fa-check-circle"></i></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= h($pf['buyer_mobile']) ?></td>
                            <td class="d-none d-md-table-cell text-muted small"><?= $pf['buyer_email'] ? h($pf['buyer_email']) : '—' ?></td>
                            <td class="d-none d-sm-table-cell text-muted small text-end pe-3"><?= h(date('d M Y', strtotime($pf['sold_at']))) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="fsNoResults" style="display:none"><td colspan="5" class="text-center text-muted py-3">No matching forms found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-light py-2 small text-muted">
                Showing pending forms (waiting for admission) only.
            </div>
            <input type="hidden" name="form_sale_id" id="form_sale_id_input" value="<?= h($_POST['form_sale_id'] ?? '') ?>">
            <input type="hidden" name="fs_number_lookup" id="fs_number_lookup_hidden" value="">
        </div>

        <!-- Application Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-primary bg-opacity-10"><i class="fas fa-file-alt text-primary"></i></span>
                <span class="fw-semibold">Application Details</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-medium">Application Number</label>
                        <input type="text" id="app_number_preview" class="form-control bg-light text-muted fw-semibold"
                               value="<?= h($_POST['fs_number_lookup'] ?? '') ?>"
                               placeholder="Auto-generated (or from form sale)"
                               readonly>
                        <div class="form-text"><i class="fas fa-info-circle me-1 text-primary"></i>Auto-generated if no form sale is linked.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-medium">Status</label>
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
                        <label class="form-label fw-medium">Department</label>
                        <select name="dept_id" id="dept_id" class="form-select">
                            <option value="">— Select Department —</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)($_POST['dept_id'] ?? 0) == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-medium">Program</label>
                        <select name="program_id" id="program_id" class="form-select">
                            <option value="">— Select Program —</option>
                        </select>
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label fw-medium">Year</label>
                        <input type="text" name="year" class="form-control" value="<?= h($_POST['year'] ?? date('Y')) ?>" maxlength="4" placeholder="e.g. 2025">
                    </div>
                    <div class="col-12 col-sm-8">
                        <label class="form-label fw-medium d-block">Semester</label>
                        <div class="d-flex gap-4 mt-1 flex-wrap">
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

        <!-- Financial Package -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-success bg-opacity-10"><i class="fas fa-coins text-success"></i></span>
                <span class="fw-semibold">Financial Package <span class="text-danger">*</span></span>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-medium">Select Package</label>
                        <select name="financial_package_id" id="financial_package_id" class="form-select" required>
                            <option value="">— Select Financial Package —</option>
                            <?php
                            $current_fp_dtype = '';
                            foreach ($financial_programs as $fp):
                                if ($fp['degree_type_name'] !== $current_fp_dtype) {
                                    if ($current_fp_dtype !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . h($fp['degree_type_name']) . '">';
                                    $current_fp_dtype = $fp['degree_type_name'];
                                }
                            ?>
                            <option value="<?= (int)$fp['id'] ?>" <?= (int)($_POST['financial_package_id'] ?? 0) === (int)$fp['id'] ? 'selected' : '' ?>>
                                <?= h($fp['program_name']) ?>
                            </option>
                            <?php endforeach; if ($current_fp_dtype !== '') echo '</optgroup>'; ?>
                        </select>
                        <div class="form-text">Fee snapshot is saved with the application for statement generation.</div>
                    </div>
                    <div class="col-12 col-md-6" id="fp_preview_wrap" style="display:none">
                        <label class="form-label fw-medium">Package Summary</label>
                        <div class="adm-fp-summary">
                            <div class="fw-semibold mb-2 small" id="financial_package_name_view"></div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="adm-fp-stat">
                                        <div class="label">Semesters</div>
                                        <div class="value" id="financial_total_semesters_view"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="adm-fp-stat">
                                        <div class="label">Months</div>
                                        <div class="value" id="financial_total_months_view"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="adm-fp-stat">
                                        <div class="label">Tuition / Semester</div>
                                        <div class="value text-success" id="financial_tuition_per_semester_view"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="adm-fp-stat">
                                        <div class="label">Admission Fee</div>
                                        <div class="value text-primary" id="financial_admission_fee_view"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /Pane 1 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 2 — Personal Information
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane2">
        <div class="row g-4">

            <!-- Photo -->
            <div class="col-12 col-sm-5 col-md-4 col-lg-3">
                <div class="card border-0 shadow-sm h-auto">
                    <div class="adm-card-hdr d-flex align-items-center gap-2">
                        <span class="card-icon bg-info bg-opacity-10"><i class="fas fa-camera text-info"></i></span>
                        <span class="fw-semibold">Photo</span>
                    </div>
                    <div class="card-body text-center py-3">
                        <div class="mb-3" id="photoPreviewWrap">
                            <img id="photoPreview" src="" class="img-thumbnail rounded-3 shadow-sm"
                                 style="max-width:150px;max-height:190px;display:none">
                            <div id="photoPlaceholder" class="border rounded-3 d-flex flex-column align-items-center justify-content-center bg-light mx-auto"
                                 style="width:150px;height:190px">
                                <i class="fas fa-user fa-3x text-muted mb-2"></i>
                                <small class="text-muted">No photo</small>
                            </div>
                        </div>
                        <input type="file" name="photo" id="photoInput" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.gif,.webp">
                        <div class="form-text mt-1">JPG, PNG, WebP · max 2 MB</div>
                    </div>
                </div>
            </div>

            <!-- Personal Fields -->
            <div class="col-12 col-sm-7 col-md-8 col-lg-9">
                <div class="card border-0 shadow-sm">
                    <div class="adm-card-hdr d-flex align-items-center gap-2">
                        <span class="card-icon bg-success bg-opacity-10"><i class="fas fa-user text-success"></i></span>
                        <span class="fw-semibold">Student Personal Information</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-medium">Student Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="student_name" class="form-control form-control-lg"
                                       value="<?= h($_POST['student_name'] ?? '') ?>"
                                       placeholder="Enter the student's full name" required autofocus>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">Father's Name</label>
                                <input type="text" name="father_name" class="form-control" value="<?= h($_POST['father_name'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">Mother's Name</label>
                                <input type="text" name="mother_name" class="form-control" value="<?= h($_POST['mother_name'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Gender</label>
                                <div class="d-flex gap-3 mt-1 flex-wrap">
                                    <?php foreach (['Male','Female','Other'] as $s): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="sex" id="sex_<?= $s ?>" value="<?= $s ?>"
                                               <?= (($_POST['sex'] ?? '') === $s) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="sex_<?= $s ?>"><?= $s ?></label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-4">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control" value="<?= h($_POST['date_of_birth'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-sm-6 col-lg-4">
                                <label class="form-label">Blood Group</label>
                                <select name="blood_group" class="form-select">
                                    <option value="">— Select —</option>
                                    <?php
                                    $bloodGroups = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
                                    $curBG = $_POST['blood_group'] ?? '';
                                    foreach ($bloodGroups as $bg): ?>
                                    <option value="<?= $bg ?>" <?= $curBG === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                    <?php endforeach;
                                    if ($curBG && !in_array($curBG, $bloodGroups)): ?>
                                    <option value="<?= h($curBG) ?>" selected><?= h($curBG) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-4">
                                <label class="form-label">Nationality</label>
                                <input type="text" name="nationality" class="form-control" value="<?= h($_POST['nationality'] ?? 'Bangladeshi') ?>">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">Place of Birth</label>
                                <input type="text" name="place_of_birth" class="form-control" value="<?= h($_POST['place_of_birth'] ?? '') ?>">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">Religion</label>
                                <select name="religion" class="form-select">
                                    <option value="">— Select —</option>
                                    <?php
                                    $religions = ['Islam','Hinduism','Christianity','Buddhism','Other'];
                                    $curRel = $_POST['religion'] ?? '';
                                    foreach ($religions as $rel): ?>
                                    <option value="<?= $rel ?>" <?= $curRel === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                    <?php endforeach;
                                    if ($curRel && !in_array($curRel, $religions)): ?>
                                    <option value="<?= h($curRel) ?>" selected><?= h($curRel) ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label">NID / Birth Certificate No</label>
                                <input type="text" name="nid_birth_cert" class="form-control" value="<?= h($_POST['nid_birth_cert'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div><!-- /Pane 2 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 3 — Address
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane3">
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-warning bg-opacity-10"><i class="fas fa-map-marker-alt text-warning"></i></span>
                <span class="fw-semibold">Address Details</span>
            </div>
            <div class="card-body">

                <!-- Permanent Address -->
                <span class="adm-section-label"><i class="fas fa-home me-1"></i>Permanent Address</span>
                <div class="row g-3 mb-2">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">House / Building</label>
                        <input type="text" name="permanent_address_1" class="form-control"
                               value="<?= h($_POST['permanent_address_1'] ?? '') ?>" placeholder="e.g. House 12, ABC Tower">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Road / Street</label>
                        <input type="text" name="permanent_address_2" class="form-control"
                               value="<?= h($_POST['permanent_address_2'] ?? '') ?>" placeholder="e.g. Road 5, Mirpur Ave">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Area / Locality</label>
                        <input type="text" name="permanent_area" class="form-control"
                               value="<?= h($_POST['permanent_area'] ?? '') ?>" placeholder="e.g. Dhanmondi, Gulshan">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">District</label>
                        <div class="searchable-select-wrap" style="position:relative">
                            <input type="text" class="form-control adm-ss-trigger" id="perm_district_search"
                                   placeholder="Search district…" autocomplete="off" data-target="permanent_district_id">
                            <input type="hidden" name="permanent_district_id" id="permanent_district_id"
                                   value="<?= h($_POST['permanent_district_id'] ?? '') ?>">
                            <div class="adm-ss-list" id="perm_district_list"
                                 style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none">
                                <div class="adm-ss-item" data-value="" data-label="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem">— None —</div>
                                <?php
                                $cur_div = '';
                                foreach ($bd_districts as $dist):
                                    if ($dist['division'] !== $cur_div) {
                                        $cur_div = $dist['division'];
                                ?>
                                <div class="adm-ss-item" data-value="" data-label="" style="padding:3px 12px;font-weight:600;background:#f0f4ff;pointer-events:none;font-size:.75rem;color:#555">— <?= h($cur_div) ?> Division —</div>
                                <?php } ?>
                                <div class="adm-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= h($dist['name']) ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem"><?= h($dist['name']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Thana / Upazila</label>
                        <div class="searchable-select-wrap" style="position:relative">
                            <input type="text" class="form-control adm-ss-trigger" id="perm_thana_search"
                                   placeholder="Select district first…" autocomplete="off" data-target="permanent_thana_id">
                            <input type="hidden" name="permanent_thana_id" id="permanent_thana_id"
                                   value="<?= h($_POST['permanent_thana_id'] ?? '') ?>">
                            <div class="adm-ss-list" id="perm_thana_list" data-current-district="<?= h($_POST['permanent_district_id'] ?? '') ?>"
                                 style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none">
                                <div class="adm-ss-item" data-value="" data-label="" data-district="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem">— None —</div>
                                <?php foreach ($bd_thanas as $th): ?>
                                <div class="adm-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= h($th['name']) ?>" data-district="<?= $th['district_id'] ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem"><?= h($th['name']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-3">
                        <label class="form-label">Post Code</label>
                        <input type="text" name="permanent_post_code" class="form-control"
                               value="<?= h($_POST['permanent_post_code'] ?? '') ?>" placeholder="e.g. 1207">
                    </div>
                    <div class="col-12 col-sm-5">
                        <label class="form-label">Mobile</label>
                        <input type="text" name="permanent_contact" class="form-control"
                               value="<?= h($_POST['permanent_contact'] ?? '') ?>" placeholder="01XXXXXXXXX">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="permanent_email" class="form-control"
                               value="<?= h($_POST['permanent_email'] ?? '') ?>">
                    </div>
                </div>

                <hr class="adm-address-sep">

                <!-- Present Address -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <span class="adm-section-label mb-0"><i class="fas fa-map-pin me-1"></i>Present Address</span>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="same_as_permanent" value="1">
                        <label class="form-check-label small text-muted" for="same_as_permanent">
                            <i class="fas fa-copy me-1"></i>Same as Permanent Address
                        </label>
                    </div>
                </div>

                <div id="present_address_fields">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label">House / Building</label>
                            <input type="text" name="present_address_1" class="form-control"
                                   value="<?= h($_POST['present_address_1'] ?? '') ?>" placeholder="e.g. House 12, ABC Tower">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Road / Street</label>
                            <input type="text" name="present_address_2" class="form-control"
                                   value="<?= h($_POST['present_address_2'] ?? '') ?>" placeholder="e.g. Road 5, Mirpur Ave">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Area / Locality</label>
                            <input type="text" name="present_area" class="form-control"
                                   value="<?= h($_POST['present_area'] ?? '') ?>" placeholder="e.g. Dhanmondi, Gulshan">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">District</label>
                            <div class="searchable-select-wrap" style="position:relative">
                                <input type="text" class="form-control adm-ss-trigger" id="pres_district_search"
                                       placeholder="Search district…" autocomplete="off" data-target="present_district_id">
                                <input type="hidden" name="present_district_id" id="present_district_id"
                                       value="<?= h($_POST['present_district_id'] ?? '') ?>">
                                <div class="adm-ss-list" id="pres_district_list"
                                     style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none">
                                    <div class="adm-ss-item" data-value="" data-label="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem">— None —</div>
                                    <?php
                                    $cur_div = '';
                                    foreach ($bd_districts as $dist):
                                        if ($dist['division'] !== $cur_div) {
                                            $cur_div = $dist['division'];
                                    ?>
                                    <div class="adm-ss-item" data-value="" data-label="" style="padding:3px 12px;font-weight:600;background:#f0f4ff;pointer-events:none;font-size:.75rem;color:#555">— <?= h($cur_div) ?> Division —</div>
                                    <?php } ?>
                                    <div class="adm-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= h($dist['name']) ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem"><?= h($dist['name']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">Thana / Upazila</label>
                            <div class="searchable-select-wrap" style="position:relative">
                                <input type="text" class="form-control adm-ss-trigger" id="pres_thana_search"
                                       placeholder="Select district first…" autocomplete="off" data-target="present_thana_id">
                                <input type="hidden" name="present_thana_id" id="present_thana_id"
                                       value="<?= h($_POST['present_thana_id'] ?? '') ?>">
                                <div class="adm-ss-list" id="pres_thana_list" data-current-district="<?= h($_POST['present_district_id'] ?? '') ?>"
                                     style="position:absolute;top:100%;left:0;right:0;max-height:200px;overflow-y:auto;background:#fff;border:1px solid #dee2e6;border-top:0;border-radius:0 0 6px 6px;z-index:1050;display:none">
                                    <div class="adm-ss-item" data-value="" data-label="" data-district="" style="padding:6px 12px;cursor:pointer;color:#999;font-size:.85rem">— None —</div>
                                    <?php foreach ($bd_thanas as $th): ?>
                                    <div class="adm-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= h($th['name']) ?>" data-district="<?= $th['district_id'] ?>" style="padding:6px 12px;cursor:pointer;font-size:.85rem"><?= h($th['name']) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <label class="form-label">Post Code</label>
                            <input type="text" name="present_post_code" class="form-control"
                                   value="<?= h($_POST['present_post_code'] ?? '') ?>" placeholder="e.g. 1207">
                        </div>
                        <div class="col-12 col-sm-5">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="present_contact" class="form-control"
                                   value="<?= h($_POST['present_contact'] ?? '') ?>" placeholder="01XXXXXXXXX">
                        </div>
                        <div class="col-12 col-sm-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="present_email" class="form-control"
                                   value="<?= h($_POST['present_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div><!-- /Pane 3 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 4 — Academic Qualifications & Experience
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane4">

        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="card-icon bg-info bg-opacity-10"><i class="fas fa-graduation-cap text-info"></i></span>
                    <span class="fw-semibold">Academic Qualifications</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addAcadRow">
                    <i class="fas fa-plus me-1"></i>Add Row
                </button>
            </div>
            <div class="px-3 pt-3 pb-3" id="acadBody">
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
                <div class="acad-row">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-sm-6 col-lg-3">
                            <label class="form-label form-label-sm mb-1">Exam Name</label>
                            <?php $all_known_exams = ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level','Bachelor Degree','Diploma']; ?>
                            <select name="exam_name[]" class="acad-exam-sel w-100">
                                <option value="">— Select —</option>
                                <?php foreach ($all_known_exams as $en): ?>
                                <option value="<?= h($en) ?>" <?= h($ar['exam_name']) === $en ? 'selected' : '' ?>><?= h($en) ?></option>
                                <?php endforeach; ?>
                                <?php if ($ar['exam_name'] !== '' && !in_array($ar['exam_name'], $all_known_exams)): ?>
                                <option value="<?= h($ar['exam_name']) ?>" selected><?= h($ar['exam_name']) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <?php $is_subject_mode = in_array($ar['exam_name'], ['Bachelor Degree','Diploma']); ?>
                        <div class="col-6 col-sm-3 col-lg-2 acad-group-td">
                            <label class="form-label form-label-sm mb-1 acad-subject-lbl"><?= $is_subject_mode ? 'Subject' : 'Group' ?></label>
                            <select name="group_name[]" class="acad-group-sel w-100" <?= $is_subject_mode ? 'disabled' : '' ?>>
                                <option value="">— Select —</option>
                                <?php if (!$is_subject_mode && $ar['group_name'] !== ''): ?>
                                <option value="<?= h($ar['group_name']) ?>" selected><?= h($ar['group_name']) ?></option>
                                <?php endif; ?>
                            </select>
                            <input type="text" name="group_name[]"
                                   class="acad-subject-inp form-control form-control-sm<?= $is_subject_mode ? '' : ' d-none' ?>"
                                   placeholder="Enter subject name"
                                   value="<?= $is_subject_mode ? h($ar['group_name']) : '' ?>"
                                   <?= $is_subject_mode ? '' : 'disabled' ?>>
                        </div>
                        <div class="col-12 col-sm-9 col-lg-4">
                            <label class="form-label form-label-sm mb-1">Board / University</label>
                            <select name="board_university[]" class="acad-board-sel w-100">
                                <option value="">— Select —</option>
                                <?php if ($ar['board_university'] !== ''): ?>
                                <option value="<?= h($ar['board_university']) ?>" selected><?= h($ar['board_university']) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3 col-lg-1">
                            <label class="form-label form-label-sm mb-1">Session</label>
                            <input type="text" name="acad_session[]" class="form-control form-control-sm" value="<?= h($ar['session']) ?>" placeholder="e.g. 2020">
                        </div>
                        <div class="col-4 col-sm-3 col-lg-1">
                            <label class="form-label form-label-sm mb-1">Year</label>
                            <input type="text" name="year_of_passing[]" class="form-control form-control-sm" value="<?= h($ar['year_of_passing']) ?>" placeholder="YYYY">
                        </div>
                        <div class="col-4 col-sm-3 col-lg-1">
                            <label class="form-label form-label-sm mb-1">Grade</label>
                            <input type="text" name="division_grade[]" class="form-control form-control-sm" value="<?= h($ar['division_grade']) ?>">
                        </div>
                        <div class="col-4 col-sm-3 col-lg-1">
                            <label class="form-label form-label-sm mb-1 acad-marks-lbl"><?= $is_subject_mode ? 'Marks / GPA / CGPA' : 'Marks/GPA' ?></label>
                            <input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm" value="<?= h($ar['total_marks_cgpa']) ?>">
                        </div>
                        <div class="col-auto ms-auto d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-danger removeRow" title="Remove row"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-secondary bg-opacity-10"><i class="fas fa-briefcase text-secondary"></i></span>
                <span class="fw-semibold">Experience</span>
                <small class="text-muted fw-normal">(optional)</small>
            </div>
            <div class="card-body">
                <textarea name="experience" class="form-control" rows="3"
                          placeholder="Work experience, internships, or other relevant activities…"><?= h($_POST['experience'] ?? '') ?></textarea>
            </div>
        </div>

    </div><!-- /Pane 4 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 5 — Guardian & References
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane5">

        <!-- Guardian Particulars -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon" style="background:rgba(111,66,193,.1)"><i class="fas fa-users" style="color:#6f42c1"></i></span>
                <span class="fw-semibold">Guardian Particulars</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Guardian Name</label>
                        <input type="text" name="guardian_name" class="form-control" value="<?= h($_POST['guardian_name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Profession</label>
                        <input type="text" name="guardian_profession" class="form-control" value="<?= h($_POST['guardian_profession'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Relationship</label>
                        <select name="guardian_relationship" class="form-select">
                            <option value="">— Select —</option>
                            <?php
                            $guardRelations = ['Father','Mother','Spouse','Brother','Sister','Uncle','Aunt','Other'];
                            $curGRel = $_POST['guardian_relationship'] ?? '';
                            foreach ($guardRelations as $gr): ?>
                            <option value="<?= $gr ?>" <?= $curGRel === $gr ? 'selected' : '' ?>><?= $gr ?></option>
                            <?php endforeach;
                            if ($curGRel && !in_array($curGRel, $guardRelations)): ?>
                            <option value="<?= h($curGRel) ?>" selected><?= h($curGRel) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Monthly Average Income</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light small text-muted">BDT</span>
                            <input type="text" name="guardian_monthly_income" class="form-control"
                                   value="<?= h($_POST['guardian_monthly_income'] ?? '') ?>" placeholder="e.g. 50,000">
                        </div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="guardian_phone" class="form-control"
                               value="<?= h($_POST['guardian_phone'] ?? '') ?>" placeholder="01XXXXXXXXX">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="guardian_email" class="form-control" value="<?= h($_POST['guardian_email'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" name="guardian_address_1" class="form-control" value="<?= h($_POST['guardian_address_1'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" name="guardian_address_2" class="form-control" value="<?= h($_POST['guardian_address_2'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Local Guardian -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon" style="background:rgba(32,201,151,.1)"><i class="fas fa-home" style="color:#20c997"></i></span>
                <span class="fw-semibold">Local Guardian</span>
                <small class="text-muted fw-normal">(if different from main guardian)</small>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="local_guardian_name" class="form-control" value="<?= h($_POST['local_guardian_name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Contact</label>
                        <input type="text" name="local_guardian_contact" class="form-control"
                               value="<?= h($_POST['local_guardian_contact'] ?? '') ?>" placeholder="01XXXXXXXXX">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" name="local_guardian_address_1" class="form-control" value="<?= h($_POST['local_guardian_address_1'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" name="local_guardian_address_2" class="form-control" value="<?= h($_POST['local_guardian_address_2'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 3</label>
                        <input type="text" name="local_guardian_address_3" class="form-control" value="<?= h($_POST['local_guardian_address_3'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Reference -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-dark bg-opacity-10"><i class="fas fa-user-tie text-dark"></i></span>
                <span class="fw-semibold">Reference</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Name</label>
                        <input type="text" name="reference_name" class="form-control" value="<?= h($_POST['reference_name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Contact</label>
                        <input type="text" name="reference_contact" class="form-control"
                               value="<?= h($_POST['reference_contact'] ?? '') ?>" placeholder="01XXXXXXXXX">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 1</label>
                        <input type="text" name="reference_address_1" class="form-control" value="<?= h($_POST['reference_address_1'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 2</label>
                        <input type="text" name="reference_address_2" class="form-control" value="<?= h($_POST['reference_address_2'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Address Line 3</label>
                        <input type="text" name="reference_address_3" class="form-control" value="<?= h($_POST['reference_address_3'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /Pane 5 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 6 — Office & Declarations
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane6">

        <!-- Declaration -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-danger bg-opacity-10"><i class="fas fa-question-circle text-danger"></i></span>
                <span class="fw-semibold">Declaration</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-medium">Have you ever been expelled from any institution?</label>
                        <div class="d-flex gap-4 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="expelled_answer" id="expelled_no" value="No"
                                       <?= (($_POST['expelled_answer'] ?? 'No') === 'No') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                <label class="form-check-label fw-medium" for="expelled_no">
                                    <i class="fas fa-times-circle text-success me-1"></i>No
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="expelled_answer" id="expelled_yes" value="Yes"
                                       <?= (($_POST['expelled_answer'] ?? '') === 'Yes') ? 'checked' : '' ?> onchange="toggleExpelled()">
                                <label class="form-check-label fw-medium" for="expelled_yes">
                                    <i class="fas fa-exclamation-circle text-danger me-1"></i>Yes
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12" id="expelled_detail_wrap" style="<?= (($_POST['expelled_answer'] ?? '') !== 'Yes') ? 'display:none' : '' ?>">
                        <label class="form-label">Please provide details</label>
                        <textarea name="expelled_detail" class="form-control" rows="2"
                                  placeholder="Explain the circumstances…"><?= h($_POST['expelled_detail'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- For Office Use Only -->
        <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #6c757d !important">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-secondary bg-opacity-10"><i class="fas fa-stamp text-secondary"></i></span>
                <span class="fw-semibold">For Office Use Only</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label class="form-label">University Batch</label>
                        <input type="text" name="office_university_batch" class="form-control" value="<?= h($_POST['office_university_batch'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-6">
                        <label class="form-label">Department Batch</label>
                        <input type="text" name="office_dept_batch" class="form-control" value="<?= h($_POST['office_dept_batch'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Section</label>
                        <input type="text" name="office_section" class="form-control" value="<?= h($_POST['office_section'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Shift</label>
                        <input type="text" name="office_shift" class="form-control" value="<?= h($_POST['office_shift'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Decision</label>
                        <input type="text" name="office_decision" class="form-control" value="<?= h($_POST['office_decision'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-sm-4">
                        <label class="form-label">Checked By</label>
                        <input type="text" name="office_checked_by" class="form-control" value="<?= h($_POST['office_checked_by'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /Pane 6 -->

    <!-- ════════════════════════════════════════════════════════════════════════
         Pane 7 — Scholarship / Waiver
    ════════════════════════════════════════════════════════════════════════════ -->
    <div class="adm-tab-pane" id="admPane7">

        <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #ffc107 !important">
            <div class="adm-card-hdr d-flex align-items-center gap-2">
                <span class="card-icon bg-warning bg-opacity-15"><i class="fas fa-graduation-cap text-warning"></i></span>
                <span class="fw-semibold">Scholarship / Waiver</span>
                <small class="text-muted fw-normal">— optional, shown as discount on first semester</small>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Leave the label blank to skip scholarship. The scholarship will be shown as a discount on the first semester in the payment statement.
                </p>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Tuition Fee (First Semester)</label>
                        <div class="input-group">
                            <span class="input-group-text">BDT</span>
                            <input type="text" id="sc-tuition-display" class="form-control bg-light" readonly value="">
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Scholarship Label</label>
                        <input type="text" name="scholarship_label" id="sc-label" class="form-control"
                               placeholder="e.g. Merit Scholarship, Freedom Fighter, Sports Award"
                               value="<?= h($_POST['scholarship_label'] ?? '') ?>">
                        <div class="form-text">Required only if you want to assign a scholarship/waiver.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Scholarship Type</label>
                        <div class="d-flex gap-4">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="percentage"
                                       id="sc-type-pct"
                                       <?= (($_POST['discount_type'] ?? 'percentage') === 'percentage') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sc-type-pct">
                                    <i class="fas fa-percent me-1 text-secondary"></i>Percentage
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="discount_type" value="fixed"
                                       id="sc-type-fixed"
                                       <?= (($_POST['discount_type'] ?? '') === 'fixed') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sc-type-fixed">
                                    <i class="fas fa-money-bill-wave me-1 text-secondary"></i>Fixed Amount
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4" id="sc-pct-wrap">
                        <label class="form-label fw-semibold">Discount %</label>
                        <div class="input-group">
                            <input type="number" name="discount_pct" id="sc-pct"
                                   class="form-control" step="0.0001" min="0.0001" max="100"
                                   value="<?= h($_POST['discount_pct'] ?? '') ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>

                    <div class="col-12 col-md-4 d-none" id="sc-fixed-wrap">
                        <label class="form-label fw-semibold">Fixed Scholarship Amount</label>
                        <div class="input-group">
                            <span class="input-group-text">BDT</span>
                            <input type="number" name="scholarship_amount_fixed" id="sc-fixed-amount"
                                   class="form-control" step="0.01" min="0.01" placeholder="e.g. 5000"
                                   value="<?= h($_POST['scholarship_amount_fixed'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="col-12 col-md-4" id="sc-calc-wrap">
                        <label class="form-label fw-semibold">Scholarship Amount (auto-calculated)</label>
                        <div class="input-group">
                            <input type="text" id="sc-calc-amount" class="form-control bg-light" readonly>
                            <span class="input-group-text">BDT</span>
                        </div>
                    </div>

                    <!-- Fee scope: only for percentage type -->
                    <div class="col-12" id="sc-scope-wrap">
                        <label class="form-label fw-semibold small">Also apply discount to:</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_fixed" value="1"
                                       id="sc-applies-fixed"
                                       <?= isset($_POST['applies_to_fixed']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="sc-applies-fixed">
                                    Institutional &amp; Development Fees
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_english" value="1"
                                       id="sc-applies-english"
                                       <?= isset($_POST['applies_to_english']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="sc-applies-english">
                                    English Language Fee
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /Pane 7 -->

    <!-- Step Navigation Footer -->
    <div class="card border-0 shadow-sm mb-5">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2 py-3">
            <button type="button" id="admPrevBtn" class="btn btn-outline-secondary" style="display:none">
                <i class="fas fa-chevron-left me-1"></i>Previous
            </button>
            <span id="admStepCounter" class="text-muted small order-last order-sm-0 w-100 w-sm-auto text-center text-sm-start">Step 1 of 7</span>
            <div class="d-flex gap-2 ms-auto">
                <button type="button" id="admNextBtn" class="btn btn-primary px-4">
                    Next <i class="fas fa-chevron-right ms-1"></i>
                </button>
                <button type="submit" id="admSaveBtn" class="btn btn-success px-4" style="display:none">
                    <i class="fas fa-save me-1"></i>Save Application
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile: floating save bar -->
    <div class="d-md-none position-fixed bottom-0 start-0 end-0 bg-white border-top shadow-lg px-3 py-2" style="z-index:1030" id="admMobileBar">
        <div class="d-flex gap-2">
            <button type="button" id="admMobilePrev" class="btn btn-outline-secondary" style="display:none">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" id="admMobileNext" class="btn btn-primary flex-grow-1">
                Next <i class="fas fa-chevron-right ms-1"></i>
            </button>
            <button type="submit" id="admMobileSave" class="btn btn-success flex-grow-1" style="display:none">
                <i class="fas fa-save me-1"></i>Save
            </button>
        </div>
    </div>
    <!-- Spacer so mobile bar doesn't overlap last card -->
    <div class="d-md-none" style="height:60px"></div>

</form>

<script>
var financialPrograms = <?= json_encode($financial_programs_map, JSON_HEX_TAG) ?>;

// Scholarship base values (updated when financial package changes)
var scTuitionBase = 0, scFixedBase = 0, scEnglishBase = 0;

function renderFinancialPackagePreview(packageId) {
    var data = packageId && financialPrograms[packageId] ? financialPrograms[packageId] : null;
    var wrap = document.getElementById('fp_preview_wrap');
    if (wrap) wrap.style.display = data ? '' : 'none';
    if (!data) {
        scTuitionBase = 0; scFixedBase = 0; scEnglishBase = 0;
        var td = document.getElementById('sc-tuition-display');
        if (td) td.value = '';
        scRecalcAmount();
        return;
    }
    document.getElementById('financial_package_name_view').textContent = data.program_name;
    document.getElementById('financial_total_semesters_view').textContent = data.total_semesters;
    document.getElementById('financial_total_months_view').textContent = data.total_months;
    document.getElementById('financial_tuition_per_semester_view').textContent = 'BDT ' + Number(data.tuition_per_semester).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('financial_admission_fee_view').textContent = 'BDT ' + Number(data.admission_fees).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    // Update scholarship bases
    var sems = Math.max(1, data.total_semesters);
    scTuitionBase = Number(data.tuition_per_semester) || 0;
    scFixedBase   = Math.round(Number(data.fixed_institutional_fees) / sems * 100) / 100;
    scEnglishBase = Math.round(Number(data.english_course_fee)       / sems * 100) / 100;
    var td = document.getElementById('sc-tuition-display');
    if (td) td.value = Number(scTuitionBase).toLocaleString('en-BD', {minimumFractionDigits:2});
    scRecalcAmount();
}

var financialPackageSel = document.getElementById('financial_package_id');
if (financialPackageSel) {
    financialPackageSel.addEventListener('change', function() { renderFinancialPackagePreview(this.value); });
    renderFinancialPackagePreview(financialPackageSel.value);
}

// ── Department → Program ──────────────────────────────────────────────────────
var deptPrograms = <?= json_encode($programs_by_dept, JSON_HEX_TAG) ?>;

document.getElementById('dept_id').addEventListener('change', function() {
    var deptId = parseInt(this.value);
    var sel = document.getElementById('program_id');
    sel.innerHTML = '<option value="">— Select Program —</option>';
    if (deptId && deptPrograms[deptId]) {
        deptPrograms[deptId].forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id; opt.textContent = p.program_name;
            sel.appendChild(opt);
        });
    }
});

(function() {
    var selDept = document.getElementById('dept_id');
    var selProg = document.getElementById('program_id');
    var selectedDept = parseInt(selDept.value);
    var selectedProg = <?= (int)($_POST['program_id'] ?? 0) ?>;
    if (selectedDept && deptPrograms[selectedDept]) {
        selProg.innerHTML = '<option value="">— Select Program —</option>';
        deptPrograms[selectedDept].forEach(function(p) {
            var opt = document.createElement('option');
            opt.value = p.id; opt.textContent = p.program_name;
            if (p.id == selectedProg) opt.selected = true;
            selProg.appendChild(opt);
        });
    }
})();

// ── Academic Qualifications ───────────────────────────────────────────────────
var ACAD_DATA = {
    'SSC':            { groups:['Science','Arts','Commerce'], boards:['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet'], defaultBoard:null, showGroup:true, isSubject:false },
    'Dakhil':         { groups:['Science','Arts','Commerce'], boards:['Bangladesh Madrasah Education Board'], defaultBoard:'Bangladesh Madrasah Education Board', showGroup:true, isSubject:false },
    'O Level':        { groups:[], boards:['Cambridge','Edexcel'], defaultBoard:null, showGroup:false, isSubject:false },
    'SSC (Vocational)':{ groups:['Electrical','Mechanical','Computer','Civil','Electronics','Refrigeration & Air Conditioning','Welding & Fabrication','Auto Mechanic','Drafting (Civil)','Drafting (Mechanical)'], boards:['Bangladesh Technical Education Board'], defaultBoard:'Bangladesh Technical Education Board', showGroup:true, isSubject:false },
    'HSC':            { groups:['Science','Arts','Commerce'], boards:['Barisal','Chattogram','Cumilla','Dhaka','Dinajpur','Jashore','Mymensingh','Rajshahi','Sylhet','Madrasah Education Board','Technical Education Board'], defaultBoard:null, showGroup:true, isSubject:false },
    'Alim':           { groups:['Science','Arts','Commerce'], boards:['Bangladesh Madrasah Education Board'], defaultBoard:'Bangladesh Madrasah Education Board', showGroup:true, isSubject:false },
    'A Level':        { groups:[], boards:['Cambridge','Edexcel'], defaultBoard:null, showGroup:false, isSubject:false },
    'Bachelor Degree':{ groups:[], boards:[], defaultBoard:null, showGroup:false, isSubject:true },
    'Diploma':        { groups:[], boards:[], defaultBoard:null, showGroup:false, isSubject:true }
};

function acadUpdateGroupBoard(tr, newExam, setDefault) {
    var data = ACAD_DATA[newExam] || { groups:[], boards:[], defaultBoard:null, showGroup:true, isSubject:false };
    var tsGroup = tr._tsGroup, tsBoard = tr._tsBoard, groupTd = tr.querySelector('.acad-group-td');
    var subjectInp = tr.querySelector('.acad-subject-inp');
    var subjectLbl = tr.querySelector('.acad-subject-lbl');
    var marksLbl   = tr.querySelector('.acad-marks-lbl');
    var tsWrapper  = groupTd ? groupTd.querySelector('.ts-wrapper') : null;

    if (data.isSubject) {
        tsGroup.disable();
        if (tsWrapper) tsWrapper.style.display = 'none';
        if (subjectInp) { subjectInp.disabled = false; subjectInp.classList.remove('d-none'); }
        if (subjectLbl) subjectLbl.textContent = 'Subject';
        if (groupTd) groupTd.style.opacity = '';
    } else {
        tsGroup.enable();
        if (tsWrapper) tsWrapper.style.display = '';
        if (subjectInp) { subjectInp.disabled = true; subjectInp.value = ''; subjectInp.classList.add('d-none'); }
        if (subjectLbl) subjectLbl.textContent = 'Group';
        tsGroup.clearOptions(); tsGroup.addOption({ value:'', text:'— Select —' });
        data.groups.forEach(function(g) { tsGroup.addOption({ value:g, text:g }); });
        if (!data.showGroup) { tsGroup.setValue('', true); if (groupTd) groupTd.style.opacity='0.35'; }
        else { if (groupTd) groupTd.style.opacity=''; }
    }
    if (marksLbl) marksLbl.textContent = data.isSubject ? 'Marks / GPA / CGPA' : 'Marks/GPA';
    tsBoard.clearOptions(); tsBoard.addOption({ value:'', text:'— Select —' });
    data.boards.forEach(function(b) { tsBoard.addOption({ value:b, text:b }); });
    if (setDefault && data.defaultBoard) tsBoard.setValue(data.defaultBoard, true);
}

function initAcadRow(tr) {
    var examSel = tr.querySelector('select.acad-exam-sel');
    var groupSel = tr.querySelector('select.acad-group-sel');
    var boardSel = tr.querySelector('select.acad-board-sel');
    if (!examSel || !groupSel || !boardSel) return;
    var savedExam = examSel.value, savedGroup = groupSel.value, savedBoard = boardSel.value;
    var tsExam  = new TomSelect(examSel,  { create:true, allowEmptyOption:true, maxOptions:20, plugins:['clear_button'], placeholder:'— Select / Type —', dropdownParent:'body' });
    var tsGroup = new TomSelect(groupSel, { create:true, allowEmptyOption:true, maxOptions:30, plugins:['clear_button'], placeholder:'— Select —', dropdownParent:'body' });
    var tsBoard = new TomSelect(boardSel, { create:true, allowEmptyOption:true, maxOptions:20, plugins:['clear_button'], placeholder:'— Select —', dropdownParent:'body' });
    tr._tsExam = tsExam; tr._tsGroup = tsGroup; tr._tsBoard = tsBoard;
    if (savedExam) {
        var data = ACAD_DATA[savedExam] || { groups:[], boards:[], defaultBoard:null, showGroup:true, isSubject:false };
        var groupTd   = tr.querySelector('.acad-group-td');
        var tsWrapper = groupTd ? groupTd.querySelector('.ts-wrapper') : null;
        var subjectInp = tr.querySelector('.acad-subject-inp');
        var marksLbl   = tr.querySelector('.acad-marks-lbl');
        if (data.isSubject) {
            tsGroup.disable();
            if (tsWrapper) tsWrapper.style.display = 'none';
            if (subjectInp) { subjectInp.disabled = false; subjectInp.classList.remove('d-none'); }
            if (marksLbl) marksLbl.textContent = 'Marks / GPA / CGPA';
        } else {
            tsGroup.clearOptions(); tsGroup.addOption({ value:'', text:'— Select —' });
            data.groups.forEach(function(g) { tsGroup.addOption({ value:g, text:g }); });
            if (savedGroup && data.groups.indexOf(savedGroup) === -1) tsGroup.addOption({ value:savedGroup, text:savedGroup });
            tsGroup.setValue(savedGroup, true);
            tsBoard.clearOptions(); tsBoard.addOption({ value:'', text:'— Select —' });
            data.boards.forEach(function(b) { tsBoard.addOption({ value:b, text:b }); });
            if (savedBoard && data.boards.indexOf(savedBoard) === -1) tsBoard.addOption({ value:savedBoard, text:savedBoard });
            tsBoard.setValue(savedBoard, true);
            if (!data.showGroup && groupTd) groupTd.style.opacity = '0.35';
        }
    }
    tsExam.on('change', function(val) { acadUpdateGroupBoard(tr, val, true); });
}

document.getElementById('addAcadRow').addEventListener('click', function() {
    var container = document.getElementById('acadBody');
    var row = document.createElement('div');
    row.className = 'acad-row';
    var examOpts = ['SSC','Dakhil','O Level','SSC (Vocational)','HSC','Alim','A Level','Bachelor Degree','Diploma']
        .map(function(e){ return '<option value="'+e+'">'+e+'</option>'; }).join('');
    row.innerHTML =
        '<div class="row g-2 align-items-end">'
        + '<div class="col-12 col-sm-6 col-lg-3">'
        +   '<label class="form-label form-label-sm mb-1">Exam Name</label>'
        +   '<select name="exam_name[]" class="acad-exam-sel w-100"><option value="">— Select —</option>'+examOpts+'</select>'
        + '</div>'
        + '<div class="col-6 col-sm-3 col-lg-2 acad-group-td">'
        +   '<label class="form-label form-label-sm mb-1 acad-subject-lbl">Group</label>'
        +   '<select name="group_name[]" class="acad-group-sel w-100"><option value="">— Select —</option></select>'
        +   '<input type="text" name="group_name[]" class="acad-subject-inp form-control form-control-sm d-none" placeholder="Enter subject name" disabled>'
        + '</div>'
        + '<div class="col-12 col-sm-9 col-lg-4">'
        +   '<label class="form-label form-label-sm mb-1">Board / University</label>'
        +   '<select name="board_university[]" class="acad-board-sel w-100"><option value="">— Select —</option></select>'
        + '</div>'
        + '<div class="col-6 col-sm-3 col-lg-1">'
        +   '<label class="form-label form-label-sm mb-1">Session</label>'
        +   '<input type="text" name="acad_session[]" class="form-control form-control-sm" placeholder="e.g. 2020">'
        + '</div>'
        + '<div class="col-4 col-sm-3 col-lg-1">'
        +   '<label class="form-label form-label-sm mb-1">Year</label>'
        +   '<input type="text" name="year_of_passing[]" class="form-control form-control-sm" placeholder="YYYY">'
        + '</div>'
        + '<div class="col-4 col-sm-3 col-lg-1">'
        +   '<label class="form-label form-label-sm mb-1">Grade</label>'
        +   '<input type="text" name="division_grade[]" class="form-control form-control-sm">'
        + '</div>'
        + '<div class="col-4 col-sm-3 col-lg-1">'
        +   '<label class="form-label form-label-sm mb-1 acad-marks-lbl">Marks/GPA</label>'
        +   '<input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm">'
        + '</div>'
        + '<div class="col-auto ms-auto d-flex align-items-end">'
        +   '<button type="button" class="btn btn-sm btn-outline-danger removeRow" title="Remove row"><i class="fas fa-times"></i></button>'
        + '</div>'
        + '</div>';
    container.appendChild(row);
    initAcadRow(row);
});

document.getElementById('acadBody').addEventListener('click', function(e) {
    if (e.target.closest('.removeRow')) {
        var row = e.target.closest('.acad-row');
        if (document.querySelectorAll('#acadBody .acad-row').length > 1) row.remove();
    }
});

document.querySelectorAll('#acadBody .acad-row').forEach(function(row) {
    initAcadRow(row);
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
    document.getElementById('expelled_detail_wrap').style.display =
        document.getElementById('expelled_yes').checked ? '' : 'none';
}

// ── Address: district/thana searchable selects ────────────────────────────────
// Bug fix: thana dropdown previously showed all districts' thanas regardless of
// selected district. Now filterAdmList respects the current district filter
// stored in list.dataset.currentDistrict.
var ADM_THANA_MAP = <?= json_encode($bd_thana_map, JSON_UNESCAPED_UNICODE) ?>;

function admFilterThanas(listId, searchId, valId, districtId, clearVal) {
    clearVal = (clearVal !== false);
    var list  = document.getElementById(listId);
    var input = document.getElementById(searchId);
    var valEl = document.getElementById(valId);
    if (!list || !input) return;
    // Store current district so filterAdmList can respect it on focus/type
    list.dataset.currentDistrict = districtId ? String(districtId) : '';
    input.placeholder = districtId ? 'Search thana…' : 'Select district first…';
    if (clearVal) { input.value = ''; if (valEl) valEl.value = ''; }
    // Apply district filter immediately
    Array.from(list.querySelectorAll('.adm-ss-item')).forEach(function(item) {
        if (item.style.pointerEvents === 'none') { item.style.display = ''; return; }
        var d = item.dataset.district;
        // Show: None item (d === ''), thanas matching the selected district
        item.style.display = (d === '' || (districtId && d === String(districtId))) ? '' : 'none';
    });
}

function admInitAddressSelect(wrap) {
    var input = wrap.querySelector('.adm-ss-trigger');
    if (!input) return;
    var targetId = input.dataset.target;
    var targetEl = document.getElementById(targetId);
    var list     = wrap.querySelector('.adm-ss-list');
    var items    = Array.from(list.querySelectorAll('.adm-ss-item'));

    // Restore label from saved value on page reload
    var currentVal = targetEl ? targetEl.value : '';
    if (currentVal) {
        var match = items.find(function(i) { return String(i.dataset.value) === String(currentVal); });
        if (match) input.value = match.dataset.label;
    }

    input.addEventListener('focus', function() { list.style.display = ''; filterAdmList(''); });
    input.addEventListener('input', function() { list.style.display = ''; filterAdmList(this.value); });

    function filterAdmList(q) {
        q = q.toLowerCase();
        // For thana lists: list.dataset.currentDistrict is set (''); for district lists: undefined
        var curDist = list.dataset.currentDistrict;
        var isThanaList = (curDist !== undefined);
        items.forEach(function(item) {
            if (item.style.pointerEvents === 'none') {
                // Division header — show in district lists, hide in thana lists
                item.style.display = isThanaList ? 'none' : '';
                return;
            }
            var textMatch = item.textContent.toLowerCase().includes(q);
            if (isThanaList) {
                // District filter: show None (d==='') + matching district thanas
                var itemDist = item.dataset.district !== undefined ? (item.dataset.district || '') : '';
                var distOk = (itemDist === '') || (!!curDist && itemDist === curDist);
                item.style.display = (distOk && textMatch) ? '' : 'none';
            } else {
                item.style.display = textMatch ? '' : 'none';
            }
        });
    }

    items.forEach(function(item) {
        if (item.style.pointerEvents === 'none') return;
        item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            if (targetEl) targetEl.value = item.dataset.value;
            input.value = item.dataset.label;
            list.style.display = 'none';
            if (targetId === 'permanent_district_id')
                admFilterThanas('perm_thana_list', 'perm_thana_search', 'permanent_thana_id', item.dataset.value, true);
            if (targetId === 'present_district_id')
                admFilterThanas('pres_thana_list', 'pres_thana_search', 'present_thana_id', item.dataset.value, true);
        });
    });

    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) list.style.display = 'none';
    });
}

// On page reload with POST data: restore district filter for thana lists
(function() {
    [
        { distId:'permanent_district_id', listId:'perm_thana_list', searchId:'perm_thana_search', valId:'permanent_thana_id' },
        { distId:'present_district_id',   listId:'pres_thana_list', searchId:'pres_thana_search', valId:'present_thana_id'   },
    ].forEach(function(cfg) {
        var distEl = document.getElementById(cfg.distId);
        if (distEl && distEl.value) admFilterThanas(cfg.listId, cfg.searchId, cfg.valId, distEl.value, false);
    });
})();

document.querySelectorAll('.searchable-select-wrap').forEach(function(wrap) {
    if (wrap.querySelector('.adm-ss-trigger')) admInitAddressSelect(wrap);
});

// ── "Same as Permanent Address" checkbox ─────────────────────────────────────
(function() {
    var cb = document.getElementById('same_as_permanent');
    if (!cb) return;
    cb.addEventListener('change', function() {
        var isSame = this.checked;
        var wrap = document.getElementById('present_address_fields');
        [['permanent_address_1','present_address_1'],['permanent_address_2','present_address_2'],
         ['permanent_area','present_area'],['permanent_contact','present_contact'],
         ['permanent_post_code','present_post_code'],['permanent_email','present_email']
        ].forEach(function(pair) {
            var src = document.querySelector('[name="'+pair[0]+'"]');
            var dst = document.querySelector('[name="'+pair[1]+'"]');
            if (!src || !dst) return;
            if (isSame) { dst.value = src.value; dst.setAttribute('readonly', true); }
            else dst.removeAttribute('readonly');
        });
        var permDistId = document.getElementById('permanent_district_id');
        var presDistId = document.getElementById('present_district_id');
        var permDistTxt = document.getElementById('perm_district_search');
        var presDistTxt = document.getElementById('pres_district_search');
        if (isSame && permDistId && presDistId) {
            presDistId.value = permDistId.value;
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
            presThanaId.value = permThanaId.value;
            presThanaTxt.value = permThanaTxt.value;
            presThanaTxt.setAttribute('readonly', true);
        } else if (!isSame && presThanaTxt) {
            presThanaTxt.removeAttribute('readonly');
        }
        if (wrap) wrap.style.opacity = isSame ? '0.65' : '';
    });
})();

// ── Form Sale pending list ────────────────────────────────────────────────────
(function() {
    var rows       = document.querySelectorAll('.fs-pending-row');
    var searchBox  = document.getElementById('fs_search_input');
    var noResults  = document.getElementById('fsNoResults');
    var foundInfo  = document.getElementById('fs_found_info');
    var clearBtn   = document.getElementById('fsClearBtn');
    var idInput    = document.getElementById('form_sale_id_input');
    var selectedId = idInput ? idInput.value : '';

    if (selectedId) rows.forEach(function(r) { if (String(r.dataset.id) === String(selectedId)) selectRow(r, false); });

    if (searchBox) {
        searchBox.addEventListener('input', function() {
            var q = searchBox.value.trim().toLowerCase();
            var visible = 0;
            rows.forEach(function(r) {
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

    rows.forEach(function(r) { r.addEventListener('click', function() { selectRow(r, true); }); });

    function selectRow(r, autoFill) {
        rows.forEach(function(rr) { rr.classList.remove('table-warning'); });
        r.classList.add('table-warning');
        idInput.value = r.dataset.id;
        document.getElementById('fs_number_lookup_hidden').value = r.dataset.formNumber;
        var appNumField = document.getElementById('app_number_preview');
        if (appNumField) appNumField.value = r.dataset.formNumber;
        document.getElementById('fs_found_number').textContent = r.dataset.formNumber;
        document.getElementById('fs_found_name').textContent   = r.dataset.name;
        document.getElementById('fs_found_mobile').textContent = r.dataset.mobile;
        document.getElementById('fs_found_email').textContent  = r.dataset.email;
        foundInfo.style.removeProperty('display');
        foundInfo.style.display = '';
        if (autoFill) {
            var nameInput   = document.querySelector('[name="student_name"]');
            var mobileInput = document.querySelector('[name="present_contact"]');
            var emailInput  = document.querySelector('[name="present_email"]');
            if (nameInput   && !nameInput.value)   nameInput.value   = r.dataset.sdStudentName || r.dataset.name;
            if (mobileInput && !mobileInput.value) mobileInput.value = r.dataset.mobile;
            if (emailInput  && !emailInput.value && r.dataset.email) emailInput.value = r.dataset.email;

            // Pre-fill student details if available
            var sd = {
                father_name:     r.dataset.sdFatherName,
                mother_name:     r.dataset.sdMotherName,
                gender:          r.dataset.sdGender,
                date_of_birth:   r.dataset.sdDob,
                blood_group:     r.dataset.sdBloodGroup,
                nationality:     r.dataset.sdNationality,
                place_of_birth:  r.dataset.sdPlaceOfBirth,
                nid_birth_cert:  r.dataset.sdNid,
                religion:        r.dataset.sdReligion,
            };
            // Text inputs / date
            ['father_name','mother_name','date_of_birth','nationality','place_of_birth','nid_birth_cert'].forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && sd[f]) el.value = sd[f];
            });
            // Radio: gender (sex)
            if (sd.gender) {
                var sexRadio = document.querySelector('[name="sex"][value="' + sd.gender + '"]');
                if (sexRadio) sexRadio.checked = true;
            }
            // Select: blood_group
            if (sd.blood_group) {
                var bgSel = document.querySelector('[name="blood_group"]');
                if (bgSel && !bgSel.value) bgSel.value = sd.blood_group;
            }
            // Select: religion
            if (sd.religion) {
                var relSel = document.querySelector('[name="religion"]');
                if (relSel && !relSel.value) relSel.value = sd.religion;
            }

            // Permanent address
            var permFields = {
                permanent_address_1: r.dataset.sdPermAddr1,
                permanent_address_2: r.dataset.sdPermAddr2,
                permanent_area:      r.dataset.sdPermArea,
                permanent_post_code: r.dataset.sdPermPost,
            };
            ['permanent_address_1','permanent_address_2','permanent_area','permanent_post_code'].forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && permFields[f]) el.value = permFields[f];
            });
            // Permanent contact / email
            var permContact = document.querySelector('[name="permanent_contact"]');
            var permEmail   = document.querySelector('[name="permanent_email"]');
            if (permContact && !permContact.value && r.dataset.mobile) permContact.value = r.dataset.mobile;
            if (permEmail   && !permEmail.value   && r.dataset.email)  permEmail.value   = r.dataset.email;

            // Permanent district + thana via searchable select
            if (r.dataset.sdPermDistrict) {
                var permDistHid = document.getElementById('permanent_district_id');
                var permDistSrch = document.getElementById('perm_district_search');
                if (permDistHid && !permDistHid.value) {
                    permDistHid.value = r.dataset.sdPermDistrict;
                    // Update display text
                    var permDistItem = document.querySelector('#perm_district_list [data-value="' + r.dataset.sdPermDistrict + '"]');
                    if (permDistItem && permDistSrch) permDistSrch.value = permDistItem.dataset.label;
                    permDistHid.dispatchEvent(new Event('change'));
                }
            }
            if (r.dataset.sdPermThana) {
                var permThanaHid = document.getElementById('permanent_thana_id');
                var permThanaSrch = document.getElementById('perm_thana_search');
                if (permThanaHid && !permThanaHid.value) {
                    permThanaHid.value = r.dataset.sdPermThana;
                    var permThanaItem = document.querySelector('#perm_thana_list [data-value="' + r.dataset.sdPermThana + '"]');
                    if (permThanaItem && permThanaSrch) permThanaSrch.value = permThanaItem.dataset.label;
                }
            }

            // Present address
            var presAddr1 = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermAddr1 : r.dataset.sdPresAddr1;
            var presAddr2 = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermAddr2 : r.dataset.sdPresAddr2;
            var presArea  = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermArea  : r.dataset.sdPresArea;
            var presPost  = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermPost  : r.dataset.sdPresPost;
            var presDistId = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermDistrict : r.dataset.sdPresDistrict;
            var presThanaId = r.dataset.sdSameAsPerm === '1' ? r.dataset.sdPermThana : r.dataset.sdPresThana;

            var presF = { present_address_1: presAddr1, present_address_2: presAddr2, present_area: presArea, present_post_code: presPost };
            ['present_address_1','present_address_2','present_area','present_post_code'].forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && presF[f]) el.value = presF[f];
            });
            var presContact = document.querySelector('[name="present_contact"]');
            if (presContact && !presContact.value && r.dataset.mobile) presContact.value = r.dataset.mobile;

            if (presDistId) {
                var presDistHid  = document.getElementById('present_district_id');
                var presDistSrch = document.getElementById('pres_district_search');
                if (presDistHid && !presDistHid.value) {
                    presDistHid.value = presDistId;
                    var presDistItem = document.querySelector('#pres_district_list [data-value="' + presDistId + '"]');
                    if (presDistItem && presDistSrch) presDistSrch.value = presDistItem.dataset.label;
                    presDistHid.dispatchEvent(new Event('change'));
                }
            }
            if (presThanaId) {
                var presThanaHid  = document.getElementById('present_thana_id');
                var presThanaSrch = document.getElementById('pres_thana_search');
                if (presThanaHid && !presThanaHid.value) {
                    presThanaHid.value = presThanaId;
                    var presThanaItem = document.querySelector('#pres_thana_list [data-value="' + presThanaId + '"]');
                    if (presThanaItem && presThanaSrch) presThanaSrch.value = presThanaItem.dataset.label;
                }
            }

            // Check "same as permanent" checkbox if applicable
            if (r.dataset.sdSameAsPerm === '1') {
                var sameChk = document.getElementById('same_as_permanent');
                if (sameChk) sameChk.checked = true;
            }

            // Experience
            var expEl = document.querySelector('[name="experience"]');
            if (expEl && !expEl.value && r.dataset.sdExperience) expEl.value = r.dataset.sdExperience;

            // Guardian Particulars
            var guardianTextFields = {
                guardian_name:           r.dataset.sdGuardianName,
                guardian_profession:     r.dataset.sdGuardianProfession,
                guardian_monthly_income: r.dataset.sdGuardianIncome,
                guardian_address_1:      r.dataset.sdGuardianAddr1,
                guardian_address_2:      r.dataset.sdGuardianAddr2,
                guardian_phone:          r.dataset.sdGuardianPhone,
                guardian_email:          r.dataset.sdGuardianEmail,
            };
            Object.keys(guardianTextFields).forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && guardianTextFields[f]) el.value = guardianTextFields[f];
            });
            // Guardian relationship (select – may be free-text from student form)
            if (r.dataset.sdGuardianRelationship) {
                var grSel = document.querySelector('[name="guardian_relationship"]');
                if (grSel && !grSel.value) {
                    var grOpt = grSel.querySelector('option[value="' + r.dataset.sdGuardianRelationship + '"]');
                    if (!grOpt) {
                        var newOpt = document.createElement('option');
                        newOpt.value = r.dataset.sdGuardianRelationship;
                        newOpt.textContent = r.dataset.sdGuardianRelationship;
                        grSel.appendChild(newOpt);
                    }
                    grSel.value = r.dataset.sdGuardianRelationship;
                }
            }

            // Local Guardian
            var lgFields = {
                local_guardian_name:      r.dataset.sdLgName,
                local_guardian_address_1: r.dataset.sdLgAddr1,
                local_guardian_address_2: r.dataset.sdLgAddr2,
                local_guardian_address_3: r.dataset.sdLgAddr3,
                local_guardian_contact:   r.dataset.sdLgContact,
            };
            Object.keys(lgFields).forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && lgFields[f]) el.value = lgFields[f];
            });

            // Reference
            var refFields = {
                reference_name:      r.dataset.sdRefName,
                reference_address_1: r.dataset.sdRefAddr1,
                reference_address_2: r.dataset.sdRefAddr2,
                reference_address_3: r.dataset.sdRefAddr3,
                reference_contact:   r.dataset.sdRefContact,
            };
            Object.keys(refFields).forEach(function(f) {
                var el = document.querySelector('[name="' + f + '"]');
                if (el && !el.value && refFields[f]) el.value = refFields[f];
            });

            // Academic Qualifications (from adm_form_sale_academic_records)
            try {
                var acadData = JSON.parse(r.dataset.sdAcad || '[]');
                if (acadData && acadData.length) {
                    var acadContainer = document.getElementById('acadBody');
                    if (acadContainer) {
                        // Only fill if all existing rows have no exam selected
                        var existingAcadRows = Array.from(acadContainer.querySelectorAll('.acad-row'));
                        var allAcadEmpty = existingAcadRows.every(function(row) {
                            return !row._tsExam || !row._tsExam.getValue();
                        });
                        if (allAcadEmpty) {
                            existingAcadRows.forEach(function(row) { row.remove(); });
                        }
                        var examOptsList = Object.keys(ACAD_DATA);
                        var examOptsHtml = examOptsList.map(function(e) {
                            return '<option value="' + e + '">' + e + '</option>';
                        }).join('');
                        acadData.forEach(function(ar) {
                            if (!ar.exam_name) return;
                            var newRow = document.createElement('div');
                            newRow.className = 'acad-row';
                            newRow.innerHTML =
                                '<div class="row g-2 align-items-end">'
                                + '<div class="col-12 col-sm-6 col-lg-3">'
                                +   '<label class="form-label form-label-sm mb-1">Exam Name</label>'
                                +   '<select name="exam_name[]" class="acad-exam-sel w-100"><option value="">— Select —</option>' + examOptsHtml + '</select>'
                                + '</div>'
                                + '<div class="col-6 col-sm-3 col-lg-2 acad-group-td">'
                                +   '<label class="form-label form-label-sm mb-1 acad-subject-lbl">Group</label>'
                                +   '<select name="group_name[]" class="acad-group-sel w-100"><option value="">— Select —</option></select>'
                                +   '<input type="text" name="group_name[]" class="acad-subject-inp form-control form-control-sm d-none" placeholder="Enter subject name" disabled>'
                                + '</div>'
                                + '<div class="col-12 col-sm-9 col-lg-4">'
                                +   '<label class="form-label form-label-sm mb-1">Board / University</label>'
                                +   '<select name="board_university[]" class="acad-board-sel w-100"><option value="">— Select —</option></select>'
                                + '</div>'
                                + '<div class="col-6 col-sm-3 col-lg-1">'
                                +   '<label class="form-label form-label-sm mb-1">Session</label>'
                                +   '<input type="text" name="acad_session[]" class="form-control form-control-sm" placeholder="e.g. 2020">'
                                + '</div>'
                                + '<div class="col-4 col-sm-3 col-lg-1">'
                                +   '<label class="form-label form-label-sm mb-1">Year</label>'
                                +   '<input type="text" name="year_of_passing[]" class="form-control form-control-sm" placeholder="YYYY">'
                                + '</div>'
                                + '<div class="col-4 col-sm-3 col-lg-1">'
                                +   '<label class="form-label form-label-sm mb-1">Grade</label>'
                                +   '<input type="text" name="division_grade[]" class="form-control form-control-sm">'
                                + '</div>'
                                + '<div class="col-4 col-sm-3 col-lg-1">'
                                +   '<label class="form-label form-label-sm mb-1 acad-marks-lbl">Marks/GPA</label>'
                                +   '<input type="text" name="total_marks_cgpa[]" class="form-control form-control-sm">'
                                + '</div>'
                                + '<div class="col-auto ms-auto d-flex align-items-end">'
                                +   '<button type="button" class="btn btn-sm btn-outline-danger removeRow" title="Remove row"><i class="fas fa-times"></i></button>'
                                + '</div>'
                                + '</div>';
                            acadContainer.appendChild(newRow);
                            initAcadRow(newRow);
                            // Set exam (fires acadUpdateGroupBoard synchronously)
                            if (newRow._tsExam) {
                                if (!newRow._tsExam.getOption(ar.exam_name)) {
                                    newRow._tsExam.addOption({ value: ar.exam_name, text: ar.exam_name });
                                }
                                newRow._tsExam.setValue(ar.exam_name); // fires change → acadUpdateGroupBoard
                            }
                            // Set group/board after acadUpdateGroupBoard has run
                            var acadEntry = ACAD_DATA[ar.exam_name];
                            // If exam not in ACAD_DATA, default to subject-input mode to avoid data loss
                            var isSubjectMode = acadEntry ? acadEntry.isSubject : true;
                            if (ar.group_name) {
                                if (isSubjectMode) {
                                    var subjInp = newRow.querySelector('.acad-subject-inp');
                                    if (subjInp) subjInp.value = ar.group_name;
                                } else if (newRow._tsGroup) {
                                    if (!newRow._tsGroup.getOption(ar.group_name)) {
                                        newRow._tsGroup.addOption({ value: ar.group_name, text: ar.group_name });
                                    }
                                    newRow._tsGroup.setValue(ar.group_name, true);
                                }
                            }
                            if (ar.board_university && newRow._tsBoard) {
                                if (!newRow._tsBoard.getOption(ar.board_university)) {
                                    newRow._tsBoard.addOption({ value: ar.board_university, text: ar.board_university });
                                }
                                newRow._tsBoard.setValue(ar.board_university, true);
                            }
                            // Simple text fields
                            var sessEl  = newRow.querySelector('[name="acad_session[]"]');
                            var yearEl  = newRow.querySelector('[name="year_of_passing[]"]');
                            var gradeEl = newRow.querySelector('[name="division_grade[]"]');
                            var marksEl = newRow.querySelector('[name="total_marks_cgpa[]"]');
                            if (sessEl  && ar.session)          sessEl.value  = ar.session;
                            if (yearEl  && ar.year_of_passing)  yearEl.value  = ar.year_of_passing;
                            if (gradeEl && ar.division_grade)   gradeEl.value = ar.division_grade;
                            if (marksEl && ar.total_marks_cgpa) marksEl.value = ar.total_marks_cgpa;
                        });
                    }
                }
            } catch (_acadErr) {}
        }
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            idInput.value = '';
            document.getElementById('fs_number_lookup_hidden').value = '';
            foundInfo.style.display = 'none';
            rows.forEach(function(r) { r.classList.remove('table-warning'); });
            var appNumField = document.getElementById('app_number_preview');
            if (appNumField) appNumField.value = '';
        });
    }
})();

// ── Scholarship / Waiver ──────────────────────────────────────────────────────
function scRecalcAmount() {
    var typePct = document.getElementById('sc-type-pct');
    if (!typePct || !typePct.checked) return;
    var pct = parseFloat(document.getElementById('sc-pct').value) || 0;
    var base = scTuitionBase;
    if (document.getElementById('sc-applies-fixed').checked)   base += scFixedBase;
    if (document.getElementById('sc-applies-english').checked) base += scEnglishBase;
    var amt = Math.round(base * pct / 100 * 100) / 100;
    var el = document.getElementById('sc-calc-amount');
    if (el) el.value = amt.toLocaleString('en-BD', {minimumFractionDigits:2});
}

function scSwitchType(type) {
    var pctWrap    = document.getElementById('sc-pct-wrap');
    var fixedWrap  = document.getElementById('sc-fixed-wrap');
    var scopeWrap  = document.getElementById('sc-scope-wrap');
    var calcWrap   = document.getElementById('sc-calc-wrap');
    var pctInput   = document.getElementById('sc-pct');
    var fixedInput = document.getElementById('sc-fixed-amount');
    if (!pctWrap) return;
    if (type === 'fixed') {
        pctWrap.classList.add('d-none');
        fixedWrap.classList.remove('d-none');
        scopeWrap.classList.add('d-none');
        calcWrap.classList.add('d-none');
        pctInput.value = '';
    } else {
        pctWrap.classList.remove('d-none');
        fixedWrap.classList.add('d-none');
        scopeWrap.classList.remove('d-none');
        calcWrap.classList.remove('d-none');
        fixedInput.value = '';
    }
    scRecalcAmount();
}

(function() {
    var radios = document.querySelectorAll('input[name="discount_type"]');
    radios.forEach(function(r) {
        r.addEventListener('change', function() { scSwitchType(this.value); });
    });
    var pctEl = document.getElementById('sc-pct');
    if (pctEl) pctEl.addEventListener('input', scRecalcAmount);
    var afEl = document.getElementById('sc-applies-fixed');
    if (afEl) afEl.addEventListener('change', scRecalcAmount);
    var aeEl = document.getElementById('sc-applies-english');
    if (aeEl) aeEl.addEventListener('change', scRecalcAmount);
    // Set initial UI state from POST value
    var checkedType = document.querySelector('input[name="discount_type"]:checked');
    if (checkedType) scSwitchType(checkedType.value);
})();

// ── Step Wizard Navigation ────────────────────────────────────────────────────
(function() {
    var TOTAL   = 7;
    var current = 1;
    var panes   = [], stepBtns = [];
    for (var i = 1; i <= TOTAL; i++) {
        panes.push(document.getElementById('admPane' + i));
        stepBtns.push(document.getElementById('admStep' + i));
    }
    var prevBtn      = document.getElementById('admPrevBtn');
    var nextBtn      = document.getElementById('admNextBtn');
    var saveBtn      = document.getElementById('admSaveBtn');
    var counter      = document.getElementById('admStepCounter');
    var mobPrev      = document.getElementById('admMobilePrev');
    var mobNext      = document.getElementById('admMobileNext');
    var mobSave      = document.getElementById('admMobileSave');

    function goTo(n) {
        stepBtns[current - 1].classList.remove('active');
        stepBtns[current - 1].classList.add('visited');
        panes[current - 1].classList.remove('active');

        current = Math.max(1, Math.min(TOTAL, n));

        panes[current - 1].classList.add('active');
        stepBtns[current - 1].classList.remove('visited');
        stepBtns[current - 1].classList.add('active');

        var isFirst = current === 1;
        var isLast  = current === TOTAL;

        prevBtn.style.display = isFirst ? 'none' : '';
        nextBtn.style.display = isLast  ? 'none' : '';
        saveBtn.style.display = isLast  ? ''     : 'none';
        if (mobPrev) mobPrev.style.display = isFirst ? 'none' : '';
        if (mobNext) mobNext.style.display = isLast  ? 'none' : '';
        if (mobSave) mobSave.style.display = isLast  ? ''     : 'none';
        if (counter) counter.textContent = 'Step ' + current + ' of ' + TOTAL;

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Init
    goTo(1);

    prevBtn.addEventListener('click', function() { goTo(current - 1); });
    nextBtn.addEventListener('click', function() { goTo(current + 1); });
    if (mobPrev) mobPrev.addEventListener('click', function() { goTo(current - 1); });
    if (mobNext) mobNext.addEventListener('click', function() { goTo(current + 1); });

    stepBtns.forEach(function(btn, idx) {
        btn.addEventListener('click', function() { goTo(idx + 1); });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
