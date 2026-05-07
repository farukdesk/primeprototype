<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_edit');

$id   = (int)($_GET['id'] ?? 0);
$prog = cf_get_program($id);
if (!$prog) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/course-fees/index.php'); }

$page_title = 'Edit – ' . $prog['program_name'];
$errors     = [];
$db         = db();

$degree_types = cf_get_degree_types();

// Count how many students are currently using this program
$student_count_stmt = $db->prepare('SELECT COUNT(*) FROM sfp_packages WHERE cf_program_id = ?');
$student_count_stmt->execute([$id]);
$students_using_program = (int)$student_count_stmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $degree_type_id  = (int)($_POST['degree_type_id'] ?? 0);
    $program_slug    = trim($_POST['program_slug']    ?? '');
    $program_name    = trim($_POST['program_name']    ?? '');
    $sort_order      = (int)($_POST['sort_order']     ?? 0);
    $is_active       = isset($_POST['is_active']) ? 1 : 0;

    $total_credits   = $_POST['total_credits']   !== '' ? (float)$_POST['total_credits']   : null;
    $duration_years  = $_POST['duration_years']  !== '' ? (float)$_POST['duration_years']  : null;
    $total_semesters = $_POST['total_semesters'] !== '' ? (int)$_POST['total_semesters']   : null;
    $total_months    = $_POST['total_months']    !== '' ? (int)$_POST['total_months']       : null;

    $std_tuition     = $_POST['standard_tuition_full']    !== '' ? (int)$_POST['standard_tuition_full']    : null;
    $tps             = $_POST['tuition_per_semester']     !== '' ? (float)$_POST['tuition_per_semester']   : null;
    $adm_fees        = $_POST['admission_fees']           !== '' ? (int)$_POST['admission_fees']           : null;
    $fix_inst        = $_POST['fixed_institutional_fees'] !== '' ? (int)$_POST['fixed_institutional_fees'] : null;
    $eng_fee         = (int)($_POST['english_course_fee'] ?? 0);
    $sn_cap          = $_POST['safety_net_cap']           !== '' ? (int)$_POST['safety_net_cap']           : null;
    $sn_per          = $_POST['safety_net_per_semester']  !== '' ? (float)$_POST['safety_net_per_semester']: null;
    $att_req         = (int)($_POST['attendance_requirement']   ?? 70);
    $sn_gpa          = (float)($_POST['safety_net_gpa_threshold'] ?? 3.00);
    $sch_type        = $_POST['scholarship_type'] ?? 'regular_bachelor';
    $init_tiers      = trim($_POST['initial_waiver_tiers'] ?? '');
    $merit_tiers     = trim($_POST['merit_waiver_tiers']   ?? '');

    $tuf             = $_POST['tuition_full']         !== '' ? (int)$_POST['tuition_full']         : null;
    $adm_m           = $_POST['admission_fee_m']      !== '' ? (int)$_POST['admission_fee_m']       : null;
    $reg_fee         = $_POST['registration_fee']     !== '' ? (int)$_POST['registration_fee']      : null;
    $inst_fees       = $_POST['institutional_fees']   !== '' ? (int)$_POST['institutional_fees']    : null;
    $camp_waiver     = $_POST['campaign_waiver']      !== '' ? (int)$_POST['campaign_waiver']       : null;
    $tot_cost        = $_POST['total_program_cost']   !== '' ? (int)$_POST['total_program_cost']    : null;
    $tot_waiver      = $_POST['total_after_waiver']   !== '' ? (int)$_POST['total_after_waiver']    : null;
    $monthly_fix     = $_POST['monthly_fixed']        !== '' ? (float)$_POST['monthly_fixed']       : null;
    $ext_waiver      = $_POST['external_waiver']      !== '' ? (int)$_POST['external_waiver']       : null;
    $ext_final       = $_POST['external_final']       !== '' ? (int)$_POST['external_final']        : null;
    $ext_monthly     = $_POST['external_monthly']     !== '' ? (float)$_POST['external_monthly']    : null;
    $int_waiver      = $_POST['internal_waiver']      !== '' ? (int)$_POST['internal_waiver']       : null;
    $int_final       = $_POST['internal_final']       !== '' ? (int)$_POST['internal_final']        : null;
    $int_monthly     = $_POST['internal_monthly']     !== '' ? (float)$_POST['internal_monthly']    : null;

    // Per-program fee constants (moved from global settings)
    $adm_fee_base       = ($_POST['admission_fee_base'] ?? '')       !== '' ? (int)$_POST['admission_fee_base']       : null;
    $reg_fee_sem        = ($_POST['reg_fee_per_semester'] ?? '')     !== '' ? (int)$_POST['reg_fee_per_semester']     : null;
    $reg_fee_tot        = ($_POST['reg_fee_total'] ?? '')            !== '' ? (int)$_POST['reg_fee_total']            : null;
    $id_card            = ($_POST['id_card_fee'] ?? '')              !== '' ? (int)$_POST['id_card_fee']              : null;
    $adm_form           = ($_POST['admission_form_fee'] ?? '')       !== '' ? (int)$_POST['admission_form_fee']       : null;
    if ($id_card === null && $adm_form === null) {
        $form_id = null;
    } else {
        $form_id_total = (int)($id_card ?? 0) + (int)($adm_form ?? 0);
        $form_id = $form_id_total > 0 ? $form_id_total : null;
    }
    $bi_start_month     = $_POST['bi_semester_start_month']  !== '' ? (int)$_POST['bi_semester_start_month']  : null;
    $tri_start_month    = $_POST['tri_semester_start_month'] !== '' ? (int)$_POST['tri_semester_start_month'] : null;

    if ($degree_type_id <= 0) $errors[] = 'Degree type is required.';
    if ($program_slug === '')  $errors[] = 'Program slug is required.';
    elseif (!preg_match('/^[a-z0-9\-]+$/', $program_slug)) $errors[] = 'Slug must be lowercase letters, numbers, and hyphens only.';
    if ($program_name === '')  $errors[] = 'Program name is required.';

    foreach (['initial_waiver_tiers' => $init_tiers, 'merit_waiver_tiers' => $merit_tiers] as $field => $json) {
        if ($json !== '') {
            json_decode($json);
            if (json_last_error() !== JSON_ERROR_NONE) $errors[] = ucwords(str_replace('_', ' ', $field)) . ' must be valid JSON.';
        }
    }

    if (empty($errors)) {
        $dup = $db->prepare('SELECT id FROM cf_programs WHERE program_slug=? AND id<>?');
        $dup->execute([$program_slug, $id]);
        if ($dup->fetchColumn()) $errors[] = 'A different program already uses this slug.';
    }

    if (empty($errors)) {
        $db->prepare(
            'UPDATE cf_programs SET
             degree_type_id=?, program_slug=?, program_name=?, sort_order=?, is_active=?,
             admission_fee_base=?, reg_fee_per_semester=?, reg_fee_total=?, form_id_fee=?,
             id_card_fee=?, admission_form_fee=?, bi_semester_start_month=?, tri_semester_start_month=?,
             total_credits=?, duration_years=?, total_semesters=?, total_months=?,
             standard_tuition_full=?, tuition_per_semester=?, admission_fees=?,
             fixed_institutional_fees=?, english_course_fee=?, safety_net_cap=?, safety_net_per_semester=?,
             attendance_requirement=?, safety_net_gpa_threshold=?, scholarship_type=?,
             initial_waiver_tiers=?, merit_waiver_tiers=?,
             tuition_full=?, admission_fee_m=?, registration_fee=?, institutional_fees=?,
             campaign_waiver=?, total_program_cost=?, total_after_waiver=?, monthly_fixed=?,
             external_waiver=?, external_final=?, external_monthly=?,
             internal_waiver=?, internal_final=?, internal_monthly=?
             WHERE id=?'
        )->execute([
            $degree_type_id, $program_slug, $program_name, $sort_order, $is_active,
            $adm_fee_base, $reg_fee_sem, $reg_fee_tot, $form_id, $id_card, $adm_form, $bi_start_month, $tri_start_month,
            $total_credits, $duration_years, $total_semesters, $total_months,
            $std_tuition, $tps, $adm_fees, $fix_inst, $eng_fee, $sn_cap, $sn_per,
            $att_req, $sn_gpa, $sch_type,
            $init_tiers ?: null, $merit_tiers ?: null,
            $tuf, $adm_m, $reg_fee, $inst_fees, $camp_waiver, $tot_cost, $tot_waiver, $monthly_fix,
            $ext_waiver, $ext_final, $ext_monthly, $int_waiver, $int_final, $int_monthly,
            $id,
        ]);

        log_change('course-fees', 'UPDATE', $id, $program_name, null, null, null, 'Program updated.');
        flash_set('success', 'Program updated successfully.');
        redirect(APP_URL . '/course-fees/view.php?id=' . $id);
    }

    save_old($_POST);
}

// Populate form values: use POST data if error, else DB data
$v = fn(string $k, mixed $default = '') => old($k, $prog[$k] ?? $default);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-pencil me-2 text-primary"></i>Edit Program</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $id ?>">View</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     IMPORTANT WARNING: Fee Changes Impact
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="alert alert-warning border-start border-warning border-4 shadow-sm mb-4" role="alert">
    <div class="d-flex align-items-start gap-3">
        <div class="flex-shrink-0" style="font-size: 2rem;">
            <i class="fas fa-exclamation-triangle text-warning"></i>
        </div>
        <div class="flex-grow-1">
            <h5 class="alert-heading mb-2">
                <i class="fas fa-info-circle me-2"></i>Important: Fee Changes Only Affect New Students
            </h5>
            <p class="mb-2">
                Changes to this program's fee structure will <strong>only apply to newly enrolled students</strong>. 
                Existing students retain their original fee package and will <strong>not be affected</strong> by these changes.
            </p>
            <?php if ($students_using_program > 0): ?>
            <div class="alert alert-info mb-0 mt-3 py-2">
                <i class="fas fa-users me-2"></i>
                <strong><?= number_format($students_using_program) ?></strong> student<?= $students_using_program !== 1 ? 's are' : ' is' ?> 
                currently using this program. Their fees are protected and will not change.
            </div>
            <?php else: ?>
            <div class="alert alert-success mb-0 mt-3 py-2">
                <i class="fas fa-check-circle me-2"></i>
                No students currently enrolled in this program. Changes can be made freely.
            </div>
            <?php endif; ?>
            <hr class="my-3">
            <p class="mb-0 small text-muted">
                <i class="fas fa-lightbulb me-1"></i>
                <strong>Why?</strong> Student fee packages are <em>snapshotted</em> at enrollment time. 
                This protects students from retroactive fee increases and maintains financial data integrity.
            </p>
        </div>
    </div>
</div>

<form method="post" novalidate id="cf-form">
    <?= csrf_field() ?>

    <div class="row g-4">
        <div class="col-lg-8">

            <!-- Basic Info -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3">
                    <i class="fas fa-info-circle me-2 text-primary"></i>Basic Information
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Degree Type <span class="text-danger">*</span></label>
                        <select name="degree_type_id" id="degreeTypeSelect" class="form-select" required
                                onchange="toggleDegreeFields()">
                            <option value="">— Select degree type —</option>
                            <?php foreach ($degree_types as $dt): ?>
                            <option value="<?= $dt['id'] ?>"
                                    data-slug="<?= h($dt['slug']) ?>"
                                <?= (int)$v('degree_type_id') === (int)$dt['id'] ? 'selected' : '' ?>>
                                <?= h($dt['icon'] . ' ' . $dt['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Program Name <span class="text-danger">*</span></label>
                        <input type="text" name="program_name" value="<?= h($v('program_name')) ?>"
                               class="form-control" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Program Slug <span class="text-danger">*</span></label>
                            <input type="text" name="program_slug" value="<?= h($v('program_slug')) ?>"
                                   class="form-control" pattern="[a-z0-9\-]+" required>
                            <div class="form-text">Lowercase letters, numbers, hyphens. Must be unique.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" name="sort_order" value="<?= (int)$v('sort_order', 0) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       value="1" <?= $v('is_active', 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Common Constants -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3">
                    <i class="fas fa-graduation-cap me-2 text-info"></i>Program Constants
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Total Credits</label>
                            <input type="number" name="total_credits" value="<?= h($v('total_credits')) ?>"
                                   class="form-control" step="0.5" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Duration (Years)</label>
                            <input type="number" name="duration_years" value="<?= h($v('duration_years')) ?>"
                                   class="form-control" step="0.5" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Total Semesters</label>
                            <input type="number" name="total_semesters" value="<?= h($v('total_semesters')) ?>"
                                   class="form-control" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Total Months</label>
                            <input type="number" name="total_months" value="<?= h($v('total_months')) ?>"
                                   class="form-control" min="1">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per-Program Fee Constants (Moved from Global Settings) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3 bg-success bg-opacity-10">
                    <i class="fas fa-money-bill-wave me-2 text-success"></i>Fee Structure (Per-Program)
                    <div class="form-text small fw-normal mt-1">These fees can be different for each program. Previously global settings.</div>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Admission Fee (One-time)</label>
                            <input type="number" name="admission_fee_base" value="<?= h($v('admission_fee_base')) ?>"
                                   class="form-control" min="0" placeholder="e.g. 10000">
                            <div class="form-text">Base admission fee (BDT)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Registration Fee / Semester</label>
                            <input type="number" name="reg_fee_per_semester" value="<?= h($v('reg_fee_per_semester')) ?>"
                                   class="form-control" min="0" placeholder="e.g. 1000">
                            <div class="form-text">Per semester registration (BDT)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Registration Fees Total</label>
                            <input type="number" name="reg_fee_total" value="<?= h($v('reg_fee_total')) ?>"
                                   class="form-control" min="0" placeholder="e.g. 12000">
                            <div class="form-text">Total across all semesters (BDT)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">ID Card Fee</label>
                            <input type="number" name="id_card_fee" value="<?= h($v('id_card_fee')) ?>"
                                   class="form-control" min="0" placeholder="e.g. 500">
                            <div class="form-text">One-time ID card fee (BDT)</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Admission Form Fee</label>
                            <input type="number" name="admission_form_fee" value="<?= h($v('admission_form_fee')) ?>"
                                   class="form-control" min="0" placeholder="e.g. 500">
                            <div class="form-text">One-time admission form fee (BDT)</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Bi-Semester Start Month</label>
                            <select name="bi_semester_start_month" class="form-select">
                                <option value="">Not specified</option>
                                <?php
                                $months = cf_get_months();
                                $bi_val = (int)$v('bi_semester_start_month', 0);
                                foreach ($months as $num => $name):
                                ?>
                                <option value="<?= $num ?>" <?= $bi_val === $num ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Starting month for bi-semester (2 semesters/year) programs</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tri-Semester Start Month</label>
                            <select name="tri_semester_start_month" class="form-select">
                                <option value="">Not specified</option>
                                <?php
                                $tri_val = (int)$v('tri_semester_start_month', 0);
                                foreach ($months as $num => $name):
                                ?>
                                <option value="<?= $num ?>" <?= $tri_val === $num ? 'selected' : '' ?>>
                                    <?= h($name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Starting month for tri-semester (3 semesters/year) programs</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bachelor / Diploma Section -->
            <div id="bachelorSection" class="card border-0 shadow-sm mb-4 d-none">
                <div class="card-header fw-semibold py-3 bg-primary bg-opacity-10">
                    <i class="fas fa-book me-2 text-primary"></i>Bachelor / Diploma Fee Constants
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Standard Tuition (Full)</label>
                            <input type="number" name="standard_tuition_full" value="<?= h($v('standard_tuition_full')) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tuition Per Semester</label>
                            <input type="number" name="tuition_per_semester" value="<?= h($v('tuition_per_semester')) ?>"
                                   class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Admission Fees (Day Total)</label>
                            <input type="number" name="admission_fees" value="<?= h($v('admission_fees')) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Fixed Institutional Fees</label>
                            <input type="number" name="fixed_institutional_fees" value="<?= h($v('fixed_institutional_fees')) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">English Course Fee</label>
                            <input type="number" name="english_course_fee" value="<?= h($v('english_course_fee', 0)) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Safety Net Cap</label>
                            <input type="number" name="safety_net_cap" value="<?= h($v('safety_net_cap')) ?>"
                                   class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Safety Net Per Semester</label>
                            <input type="number" name="safety_net_per_semester" value="<?= h($v('safety_net_per_semester')) ?>"
                                   class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Attendance Requirement (%)</label>
                            <select name="attendance_requirement" class="form-select">
                                <option value="70" <?= (int)$v('attendance_requirement', 70) === 70 ? 'selected' : '' ?>>70%</option>
                                <option value="60" <?= (int)$v('attendance_requirement', 70) === 60 ? 'selected' : '' ?>>60%</option>
                                <option value="50" <?= (int)$v('attendance_requirement', 70) === 50 ? 'selected' : '' ?>>50%</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Safety Net GPA Threshold</label>
                            <input type="number" name="safety_net_gpa_threshold" value="<?= h($v('safety_net_gpa_threshold', 3.00)) ?>"
                                   class="form-control" step="0.01" min="0" max="4">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Scholarship Type</label>
                            <select name="scholarship_type" class="form-select">
                                <?php foreach (['regular_bachelor' => 'Regular Bachelor', 'ba_bangla' => 'BA Bangla', 'llb' => 'LLB / BA English', 'diploma' => 'Diploma'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $v('scholarship_type') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Initial Waiver Tiers (JSON)</label>
                            <textarea name="initial_waiver_tiers" class="form-control font-monospace" rows="3"><?= h($v('initial_waiver_tiers')) ?></textarea>
                            <div class="form-text">Array of <code>{"min","max","pct"}</code> objects.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Merit Waiver Tiers (JSON)</label>
                            <textarea name="merit_waiver_tiers" class="form-control font-monospace" rows="3"><?= h($v('merit_waiver_tiers')) ?></textarea>
                            <div class="form-text">Semester GPA–based merit scholarship tiers.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Masters Section -->
            <div id="mastersSection" class="card border-0 shadow-sm mb-4 d-none">
                <div class="card-header fw-semibold py-3 bg-success bg-opacity-10">
                    <i class="fas fa-university me-2 text-success"></i>Masters Fee Constants
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tuition Full</label>
                            <input type="number" name="tuition_full" value="<?= h($v('tuition_full')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Admission Fee</label>
                            <input type="number" name="admission_fee_m" value="<?= h($v('admission_fee_m')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Registration Fee</label>
                            <input type="number" name="registration_fee" value="<?= h($v('registration_fee')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Institutional Fees</label>
                            <input type="number" name="institutional_fees" value="<?= h($v('institutional_fees')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Campaign Waiver</label>
                            <input type="number" name="campaign_waiver" value="<?= h($v('campaign_waiver')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Program Cost</label>
                            <input type="number" name="total_program_cost" value="<?= h($v('total_program_cost')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total After Waiver</label>
                            <input type="number" name="total_after_waiver" value="<?= h($v('total_after_waiver')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Monthly Fixed</label>
                            <input type="number" name="monthly_fixed" value="<?= h($v('monthly_fixed')) ?>" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                    <hr>
                    <div class="fw-semibold mb-3 text-muted small text-uppercase">Dual-Track (Optional)</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">External Waiver</label>
                            <input type="number" name="external_waiver" value="<?= h($v('external_waiver')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">External Final Cost</label>
                            <input type="number" name="external_final" value="<?= h($v('external_final')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">External Monthly</label>
                            <input type="number" name="external_monthly" value="<?= h($v('external_monthly')) ?>" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Internal Waiver</label>
                            <input type="number" name="internal_waiver" value="<?= h($v('internal_waiver')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Internal Final Cost</label>
                            <input type="number" name="internal_final" value="<?= h($v('internal_final')) ?>" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Internal Monthly</label>
                            <input type="number" name="internal_monthly" value="<?= h($v('internal_monthly')) ?>" class="form-control" step="0.01" min="0">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-8 -->

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100 mb-2">
                        <i class="fas fa-save me-1"></i> Save Changes
                    </button>
                    <a href="<?= APP_URL ?>/course-fees/view.php?id=<?= $id ?>" class="btn btn-outline-secondary w-100 mb-2">
                        Cancel
                    </a>
                    <?php if (cf_can_delete()): ?>
                    <a href="<?= APP_URL ?>/course-fees/delete.php?id=<?= $id ?>"
                       class="btn btn-outline-danger w-100"
                       onclick="return confirm('Delete this program? This cannot be undone.')">
                        <i class="fas fa-trash me-1"></i> Delete Program
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleDegreeFields() {
    var sel  = document.getElementById('degreeTypeSelect');
    var slug = sel.options[sel.selectedIndex]?.dataset?.slug || '';
    var bSec = document.getElementById('bachelorSection');
    var mSec = document.getElementById('mastersSection');
    bSec.classList.add('d-none');
    mSec.classList.add('d-none');
    if (slug === 'masters') {
        mSec.classList.remove('d-none');
    } else if (slug === 'regular-bachelor' || slug === 'bachelor-from-diploma') {
        bSec.classList.remove('d-none');
    }
}
toggleDegreeFields();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
