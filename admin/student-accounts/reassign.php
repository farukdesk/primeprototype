<?php
/**
 * Reassign an existing student account (fee package) to entirely new terms —
 * new programme, new tuition/fee constants, new monthly fee — optionally
 * together with a Department Transfer, WITHOUT losing any money already
 * collected.
 *
 * The existing sfp_packages row is edited in place (never deleted/replaced —
 * see sfp_reassign_package() for why), so sfp_payments keeps working exactly
 * as it does today. A required "reason" is logged via log_change(), matching
 * admin/STUDENT-FEE-ARCHITECTURE.md's guidance for deliberate per-student
 * package edits.
 *
 * Two-step preview/confirm flow (mirrors admin/semester-drop/bulk-amount.php):
 * "Preview Changes" shows exactly what will change — including whether any
 * already-paid amount would lose its specific semester/month tag — before
 * anything is saved.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';      // acc_student_fee_summary()
require_once __DIR__ . '/../student-transfer/helpers.php'; // optional Department Transfer

$id  = (int)($_GET['id'] ?? $_POST['package_id'] ?? 0);
$pkg = sfp_get_package($id);
if (!$pkg) {
    flash_set('error', 'Student account not found.');
    redirect(APP_URL . '/student-accounts/index.php');
}

$page_title    = 'Reassign Student Account';
$user          = auth_user();
$student       = stt_get_student((int)$pkg['student_id']);
$payment_count = sfp_package_payment_count($id);
$summary       = acc_student_fee_summary((int)$pkg['student_id']);
$paid_so_far   = (float)($summary['totals']['tuition']['paid'] ?? 0.0);

$cf_programs  = sfp_get_cf_programs();
$programs_map = [];
foreach ($cf_programs as $prog) {
    $months = (float)$prog['total_months'];
    $programs_map[$prog['id']] = [
        'program_name'             => $prog['program_name'],
        'total_semesters'          => (int)$prog['total_semesters'],
        'total_months'             => (int)$prog['total_months'],
        'standard_tuition_full'    => (int)$prog['standard_tuition_full'],
        'tuition_per_semester'     => (float)$prog['tuition_per_semester'],
        'admission_fees'           => (int)($prog['admission_fees'] ?? 0),
        'fixed_institutional_fees' => (int)$prog['fixed_institutional_fees'],
        'english_course_fee'       => (int)$prog['english_course_fee'],
        'safety_net_cap'           => (int)$prog['safety_net_cap'],
        'safety_net_per_semester'  => (float)$prog['safety_net_per_semester'],
        'attendance_requirement'   => (int)$prog['attendance_requirement'],
        'safety_net_gpa_threshold' => (float)$prog['safety_net_gpa_threshold'],
        'reg_fee_per_semester'     => (int)($prog['reg_fee_per_semester'] ?? 0),
        'form_id_fee'              => (int)($prog['form_id_fee'] ?? 0),
        'bi_semester_start_month'  => (int)($prog['bi_semester_start_month'] ?? 0),
        'tri_semester_start_month' => (int)($prog['tri_semester_start_month'] ?? 0),
    ];
}

$departments = stt_allowed_depts();
$programs    = stt_program_map();

$errors       = [];
$show_preview = false;
$dt_preview   = null;   // department-transfer preview text, if requested
$orphan_preview = 0.0;  // amount that would lose its semester tag, if shrinking

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'preview');

    $f = [
        'cf_program_id'            => (int)($_POST['cf_program_id'] ?? 0),
        'program_name'             => trim($_POST['program_name'] ?? ''),
        'total_semesters'          => (int)($_POST['total_semesters'] ?? 0),
        'total_months'             => (int)($_POST['total_months'] ?? 0),
        'standard_tuition_full'    => (int)($_POST['standard_tuition_full'] ?? 0),
        'tuition_per_semester'     => (float)($_POST['tuition_per_semester'] ?? 0),
        'admission_fees'           => (int)($_POST['admission_fees'] ?? 0),
        'fixed_institutional_fees' => (int)($_POST['fixed_institutional_fees'] ?? 0),
        'english_course_fee'       => (int)($_POST['english_course_fee'] ?? 0),
        'reg_fee_per_semester'     => (float)($_POST['reg_fee_per_semester'] ?? 0),
        'form_id_fee'              => (float)($_POST['form_id_fee'] ?? 0),
        'safety_net_cap'           => (float)($_POST['safety_net_cap'] ?? 0),
        'safety_net_per_semester'  => (float)($_POST['safety_net_per_semester'] ?? 0),
        'attendance_requirement'   => (int)($_POST['attendance_requirement'] ?? 70),
        'safety_net_gpa_threshold' => (float)($_POST['safety_net_gpa_threshold'] ?? 3.00),
        'bi_semester_start_month'  => (int)($_POST['bi_semester_start_month'] ?? 0),
        'tri_semester_start_month' => (int)($_POST['tri_semester_start_month'] ?? 0),
        'note'                     => trim($_POST['note'] ?? ''),
    ];
    $reason = trim($_POST['reason'] ?? '');

    $do_transfer   = ($_POST['do_transfer'] ?? '') === '1';
    $to_dept_id    = (int)($_POST['to_dept_id'] ?? 0);
    $to_program_id = (int)($_POST['to_program_id'] ?? 0);
    $id_mode_in    = (string)($_POST['id_mode'] ?? 'keep');
    $id_mode       = in_array($id_mode_in, ['auto', 'manual'], true) ? $id_mode_in : 'keep';
    $manual_sid    = trim($_POST['manual_student_id'] ?? '');

    if ($f['program_name'] === '')      $errors[] = 'Programme name is required.';
    if ($f['total_semesters'] <= 0)     $errors[] = 'Total semesters must be greater than 0.';
    if ($f['total_months'] <= 0)        $errors[] = 'Total months must be greater than 0.';
    if ($f['tuition_per_semester'] < 0) $errors[] = 'Tuition per semester cannot be negative.';
    if ($reason === '')                 $errors[] = 'Please explain why this account is being reassigned — this is recorded permanently in the change log.';
    if ($do_transfer) {
        if (!can_access('student-transfer', 'can_create')) {
            $errors[] = 'You do not have permission to also transfer this student\'s department.';
        } elseif ($to_dept_id <= 0) {
            $errors[] = 'Please choose the department to transfer to.';
        }
    }

    // Preview-only figures (computed either way, shown on the page).
    if ($f['total_semesters'] < (int)$pkg['total_semesters']) {
        $ph_stmt = db()->prepare(
            "SELECT COALESCE(SUM(sp.amount), 0)
               FROM sfp_payments sp
               JOIN acc_vouchers v ON v.id = sp.voucher_id
               JOIN sfp_semester_fees sf ON sf.id = sp.semester_fee_id
              WHERE sf.package_id = ? AND sf.semester_number > ? AND v.is_deleted = 0"
        );
        $ph_stmt->execute([$id, $f['total_semesters']]);
        $orphan_preview = (float)$ph_stmt->fetchColumn();
    }
    if ($do_transfer && $to_dept_id > 0 && isset($departments[$to_dept_id])) {
        $to_prog_name = $to_program_id > 0 ? ($programs[$to_program_id]['program_name'] ?? null) : null;
        $from_dept_name = stt_dept_map()[(int)$student['dept_id']]['name'] ?? '—';
        $from_prog_name = (int)($student['program_id'] ?? 0) > 0 ? ($programs[(int)$student['program_id']]['program_name'] ?? '—') : '— None —';
        $dt_preview = [
            'from_dept' => $from_dept_name, 'to_dept' => $departments[$to_dept_id]['name'],
            'from_prog' => $from_prog_name, 'to_prog' => $to_prog_name ?? '— None —',
        ];
    }

    if (empty($errors) && $action === 'confirm') {
        $dt_result = null;
        if ($do_transfer) {
            $dt_result = stt_create_department_transfer(
                (int)$pkg['student_id'], $to_dept_id, $to_program_id, $id_mode, $manual_sid, $reason, (int)$user['id']
            );
            if (!$dt_result['ok']) {
                $errors[] = 'Department transfer failed, so nothing was changed: ' . $dt_result['message'];
            }
        }

        if (empty($errors)) {
            $result = sfp_reassign_package($id, $f, $reason, (int)$user['id']);
            $message = ($dt_result['ok'] ?? false) ? $dt_result['message'] . ' ' . $result['message'] : $result['message'];
            flash_set('success', $message);
            redirect(APP_URL . '/student-accounts/view.php?id=' . $id);
        }
    }

    $show_preview = empty($errors);
    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-right-left me-2 text-warning"></i>Reassign Student Account</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= $id ?>"><?= h($pkg['student_name']) ?></a></li>
            <li class="breadcrumb-item active">Reassign</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info">
    <div class="row g-3">
        <div class="col-md-6">
            <div class="small text-muted">Student</div>
            <div class="fw-semibold"><?= h($pkg['student_name']) ?> (<?= h($pkg['student_sid']) ?>)</div>
        </div>
        <div class="col-md-3">
            <div class="small text-muted">Current programme</div>
            <div class="fw-semibold"><?= h($pkg['program_name']) ?></div>
        </div>
        <div class="col-md-3">
            <div class="small text-muted">Already paid toward tuition &amp; fees</div>
            <div class="fw-semibold text-success"><?= sfp_money($paid_so_far) ?> <small class="text-muted">(<?= (int)$payment_count ?> payment(s))</small></div>
        </div>
    </div>
    <hr class="my-2">
    <small><i class="fas fa-shield-halved me-1"></i>This amount is never touched by a reassignment — it always carries forward and applies toward the new plan below.</small>
</div>

<form method="post" novalidate id="reassign-form">
    <?= csrf_field() ?>
    <input type="hidden" name="package_id" value="<?= $id ?>">

    <!-- ── Also transfer department/program ── -->
    <?php if (can_access('student-transfer', 'can_create')): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-semibold py-3">
            <i class="fas fa-building me-2"></i>Department / Program
        </div>
        <div class="card-body">
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="do_transfer" id="do_transfer" value="1"
                       <?= old('do_transfer') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="do_transfer">
                    Also transfer this student to a new department/program (records a proper Department Transfer)
                </label>
            </div>
            <div id="transfer-fields" class="row g-3 <?= old('do_transfer') === '1' ? '' : 'd-none' ?>">
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Currently: <strong><?= h(stt_dept_map()[(int)$student['dept_id']]['name'] ?? '—') ?></strong>
                        <?php if ((int)($student['program_id'] ?? 0) > 0): ?> / <?= h($programs[(int)$student['program_id']]['program_name'] ?? '—') ?><?php endif; ?>
                    </div>
                    <label class="form-label fw-semibold">To Department</label>
                    <select name="to_dept_id" id="to_dept_id" class="form-select">
                        <option value="">— Select department —</option>
                        <?php foreach ($departments as $dep): ?>
                        <option value="<?= (int)$dep['id'] ?>" <?= old('to_dept_id') === (string)$dep['id'] ? 'selected' : '' ?>>
                            <?= h($dep['name']) ?> (<?= h($dep['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">To Program</label>
                    <select name="to_program_id" id="to_program_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['dept_id'] ?>"
                                <?= old('to_program_id') === (string)$p['id'] ? 'selected' : '' ?>>
                            <?= h($p['program_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Student ID</label>
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="rid_keep" value="keep" <?= old('id_mode', 'keep') === 'keep' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rid_keep">Keep current ID</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="rid_auto" value="auto" <?= old('id_mode') === 'auto' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rid_auto">Auto-generate for new department/program</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="rid_manual" value="manual" <?= old('id_mode') === 'manual' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rid_manual">Type a new ID</label>
                        </div>
                        <input type="text" name="manual_student_id" id="manual_student_id" class="form-control d-none" style="max-width:220px;"
                               value="<?= h(old('manual_student_id', '')) ?>" placeholder="New Student ID" maxlength="20">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Programme / Fee Constants ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-success text-white fw-semibold py-3">
            <i class="fas fa-calculator me-2"></i>New Programme &amp; Fee Constants
            <span class="fw-normal ms-2 opacity-75" style="font-size:.8rem;">
                Pre-filled with the current plan — change what's different.
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Load from Course Fee Structure</label>
                    <select id="cf-program-select" class="form-select">
                        <option value="">— Select to auto-fill —</option>
                        <?php
                        $current_dtype = '';
                        foreach ($cf_programs as $prog):
                            if ($prog['degree_type_name'] !== $current_dtype) {
                                if ($current_dtype !== '') echo '</optgroup>';
                                echo '<optgroup label="' . h($prog['degree_type_name']) . '">';
                                $current_dtype = $prog['degree_type_name'];
                            }
                        ?>
                        <option value="<?= $prog['id'] ?>" <?= old('cf_program_id') === (string)$prog['id'] ? 'selected' : '' ?>><?= h($prog['program_name']) ?></option>
                        <?php endforeach; if ($current_dtype !== '') echo '</optgroup>'; ?>
                    </select>
                    <input type="hidden" name="cf_program_id" id="cf-program-id-hidden" value="<?= h(old('cf_program_id', (string)($pkg['cf_program_id'] ?? ''))) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Programme Name <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" required
                           value="<?= h(old('program_name', $pkg['program_name'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Total Semesters <span class="text-danger">*</span></label>
                    <input type="number" name="total_semesters" id="f-total-semesters" class="form-control" min="1" required
                           value="<?= h(old('total_semesters', (string)$pkg['total_semesters'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Total Months <span class="text-danger">*</span></label>
                    <input type="number" name="total_months" id="f-total-months" class="form-control" min="1" required
                           value="<?= h(old('total_months', (string)$pkg['total_months'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Months / Semester</label>
                    <input type="text" id="f-months-per-sem" class="form-control bg-light" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">New Total Monthly Fee</label>
                    <input type="text" id="f-monthly-total" class="form-control bg-light fw-bold text-success" readonly>
                    <div class="form-text">Tuition ÷ months/semester + fixed + English, per month.</div>
                </div>
            </div>

            <hr class="my-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Standard Tuition (Full)</label>
                    <input type="number" name="standard_tuition_full" id="f-std-tuition" class="form-control" min="0"
                           value="<?= h(old('standard_tuition_full', (string)$pkg['standard_tuition_full'])) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tuition Per Semester <span class="text-danger">*</span></label>
                    <input type="number" name="tuition_per_semester" id="f-tuition-sem" class="form-control" min="0" step="0.01" required
                           value="<?= h(old('tuition_per_semester', (string)$pkg['tuition_per_semester'])) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Admission Fees</label>
                    <input type="number" name="admission_fees" id="f-admission" class="form-control" min="0"
                           value="<?= h(old('admission_fees', (string)$pkg['admission_fees'])) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Registration Fee / Semester</label>
                    <input type="number" name="reg_fee_per_semester" id="f-reg-fee" class="form-control" min="0" step="0.01"
                           value="<?= h(old('reg_fee_per_semester', (string)$pkg['reg_fee_per_semester'])) ?>">
                </div>
                <input type="hidden" name="form_id_fee" id="f-form-id-fee" value="<?= h(old('form_id_fee', (string)$pkg['form_id_fee'])) ?>">
            </div>

            <hr class="my-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Fixed Institutional Fees (total)</label>
                    <input type="number" name="fixed_institutional_fees" id="f-fixed-inst" class="form-control" min="0"
                           value="<?= h(old('fixed_institutional_fees', (string)$pkg['fixed_institutional_fees'])) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">English Course Fee (total)</label>
                    <input type="number" name="english_course_fee" id="f-english" class="form-control" min="0"
                           value="<?= h(old('english_course_fee', (string)$pkg['english_course_fee'])) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Bi-Sem Start Month</label>
                    <select name="bi_semester_start_month" id="f-bi-start-month" class="form-select">
                        <option value="0">—</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= old('bi_semester_start_month', (string)$pkg['bi_semester_start_month']) === (string)$m ? 'selected' : '' ?>><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Tri-Sem Start Month</label>
                    <select name="tri_semester_start_month" id="f-tri-start-month" class="form-select">
                        <option value="0">—</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= old('tri_semester_start_month', (string)$pkg['tri_semester_start_month']) === (string)$m ? 'selected' : '' ?>><?= date('M', mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <hr class="my-3">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net Cap</label>
                    <input type="number" name="safety_net_cap" id="f-snc" class="form-control" min="0"
                           value="<?= h(old('safety_net_cap', (string)$pkg['safety_net_cap'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net / Semester</label>
                    <input type="number" name="safety_net_per_semester" id="f-sns" class="form-control" min="0" step="0.01"
                           value="<?= h(old('safety_net_per_semester', (string)$pkg['safety_net_per_semester'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Attendance Requirement</label>
                    <input type="number" name="attendance_requirement" id="f-att" class="form-control" min="0" max="100"
                           value="<?= h(old('attendance_requirement', (string)$pkg['attendance_requirement'])) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net GPA Threshold</label>
                    <input type="number" name="safety_net_gpa_threshold" id="f-gpa-thr" class="form-control" min="0" max="4" step="0.01"
                           value="<?= h(old('safety_net_gpa_threshold', (string)$pkg['safety_net_gpa_threshold'])) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Note & Reason ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label fw-semibold">Note <span class="text-muted">(optional, shown on this account)</span></label>
                <textarea name="note" class="form-control" rows="2"><?= h(old('note', (string)$pkg['note'])) ?></textarea>
            </div>
            <div>
                <label class="form-label fw-semibold">Reason for Reassignment <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="2" required
                          placeholder="Why is this account being reassigned to new terms?"><?= h(old('reason', '')) ?></textarea>
                <div class="form-text">Recorded permanently in the change log.</div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" name="action" value="preview" class="btn btn-warning btn-lg">
            <i class="fas fa-eye me-1"></i> Preview Changes
        </button>
        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-lg">Cancel</a>
    </div>
</form>

<?php if ($show_preview): ?>
<div class="card border-warning mt-4">
    <div class="card-header bg-warning-subtle fw-semibold">
        <i class="fas fa-triangle-exclamation me-2 text-warning"></i>Review Before Saving
    </div>
    <div class="card-body">
        <table class="table table-sm mb-3">
            <thead><tr><th></th><th>Current</th><th>New</th></tr></thead>
            <tbody>
                <tr><td class="text-muted">Programme</td><td><?= h($pkg['program_name']) ?></td><td class="fw-semibold"><?= h($_POST['program_name']) ?></td></tr>
                <tr><td class="text-muted">Total Semesters</td><td><?= (int)$pkg['total_semesters'] ?></td><td class="fw-semibold"><?= (int)$_POST['total_semesters'] ?></td></tr>
                <tr><td class="text-muted">Tuition / Semester</td><td><?= sfp_money((float)$pkg['tuition_per_semester']) ?></td><td class="fw-semibold"><?= sfp_money((float)$_POST['tuition_per_semester']) ?></td></tr>
            </tbody>
        </table>
        <?php if ($dt_preview): ?>
        <div class="alert alert-primary py-2 small mb-3">
            <i class="fas fa-building me-1"></i>Department transfer: <?= h($dt_preview['from_dept']) ?> → <strong><?= h($dt_preview['to_dept']) ?></strong>,
            Programme: <?= h($dt_preview['from_prog']) ?> → <strong><?= h($dt_preview['to_prog']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="alert alert-success py-2 small mb-3">
            <i class="fas fa-shield-halved me-1"></i><?= sfp_money($paid_so_far) ?> already paid carries forward automatically — nothing is deleted.
        </div>
        <?php if ($orphan_preview > 0): ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="fas fa-triangle-exclamation me-1"></i><?= sfp_money($orphan_preview) ?> of that was linked to semester(s) beyond the new total of
            <?= (int)$_POST['total_semesters'] ?> — it still counts toward the total paid, but will no longer show against a specific semester/month.
        </div>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Save this reassignment? This cannot be undone automatically.');">
            <?= csrf_field() ?>
            <?php foreach ($_POST as $k => $v): if ($k === 'action' || $k === CSRF_TOKEN_NAME) continue; ?>
                <?php if (is_array($v)): foreach ($v as $vv): ?>
                <input type="hidden" name="<?= h($k) ?>[]" value="<?= h($vv) ?>">
                <?php endforeach; else: ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-check me-1"></i> Confirm &amp; Save Reassignment
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
var programsData = <?= json_encode($programs_map) ?>;

function fmt(n) { return parseFloat(n).toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function recalcMonthly() {
    var tm = parseFloat(document.getElementById('f-total-months').value)    || 0;
    var ts = parseFloat(document.getElementById('f-total-semesters').value) || 0;
    var ts_fee = parseFloat(document.getElementById('f-tuition-sem').value) || 0;
    var fi = parseFloat(document.getElementById('f-fixed-inst').value)      || 0;
    var ec = parseFloat(document.getElementById('f-english').value)         || 0;
    var mps = (tm > 0 && ts > 0) ? (tm / ts) : 0;

    document.getElementById('f-months-per-sem').value = mps ? fmt(mps) : '';

    var monthlyFixed   = tm > 0 ? (fi / tm) : 0;
    var monthlyEnglish = tm > 0 ? (ec / tm) : 0;
    var monthlyTuition = mps > 0 ? (ts_fee / mps) : 0;
    document.getElementById('f-monthly-total').value = tm > 0 ? (fmt(monthlyTuition + monthlyFixed + monthlyEnglish) + ' BDT / month') : '';
}
['f-total-months','f-total-semesters','f-tuition-sem','f-fixed-inst','f-english'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', recalcMonthly);
});
recalcMonthly();

document.getElementById('cf-program-select').addEventListener('change', function () {
    var pid = this.value;
    document.getElementById('cf-program-id-hidden').value = pid;
    if (!pid || !programsData[pid]) return;
    var p = programsData[pid];
    document.querySelector('[name=program_name]').value = p.program_name;
    document.getElementById('f-total-semesters').value  = p.total_semesters;
    document.getElementById('f-total-months').value      = p.total_months;
    document.getElementById('f-std-tuition').value       = p.standard_tuition_full;
    document.getElementById('f-tuition-sem').value       = p.tuition_per_semester;
    document.getElementById('f-admission').value         = p.admission_fees || 0;
    document.getElementById('f-reg-fee').value            = p.reg_fee_per_semester || 0;
    document.getElementById('f-form-id-fee').value        = p.form_id_fee || 0;
    document.getElementById('f-bi-start-month').value     = p.bi_semester_start_month || 0;
    document.getElementById('f-tri-start-month').value    = p.tri_semester_start_month || 0;
    document.getElementById('f-fixed-inst').value         = p.fixed_institutional_fees;
    document.getElementById('f-english').value            = p.english_course_fee;
    document.getElementById('f-snc').value                = p.safety_net_cap;
    document.getElementById('f-sns').value                = p.safety_net_per_semester;
    document.getElementById('f-att').value                = p.attendance_requirement;
    document.getElementById('f-gpa-thr').value             = p.safety_net_gpa_threshold;
    recalcMonthly();
});

// ── Transfer toggle ──────────────────────────────────────────────────────────
var doTransfer   = document.getElementById('do_transfer');
var transferBox  = document.getElementById('transfer-fields');
if (doTransfer) {
    doTransfer.addEventListener('change', function () {
        transferBox.classList.toggle('d-none', !this.checked);
    });
}
var deptEl = document.getElementById('to_dept_id');
var progEl = document.getElementById('to_program_id');
function filterPrograms() {
    if (!deptEl || !progEl) return;
    var dept = deptEl.value;
    var opts = progEl.querySelectorAll('option[data-dept]');
    var stillValid = false;
    opts.forEach(function (opt) {
        var match = (opt.getAttribute('data-dept') === dept);
        opt.hidden = !match;
        if (match && opt.selected) stillValid = true;
    });
    if (!stillValid) progEl.value = '';
}
if (deptEl) { deptEl.addEventListener('change', filterPrograms); filterPrograms(); }

var manualInput = document.getElementById('manual_student_id');
document.querySelectorAll('input[name="id_mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var isManual = document.getElementById('rid_manual').checked;
        manualInput.classList.toggle('d-none', !isManual);
    });
});
if (manualInput && document.getElementById('rid_manual').checked) {
    manualInput.classList.remove('d-none');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
