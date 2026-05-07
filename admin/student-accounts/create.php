<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Assign Student Account';
$errors     = [];
$db         = db();

$cf_programs = sfp_get_cf_programs();

// Build a JS-friendly map of program fee constants
$programs_map = [];
foreach ($cf_programs as $prog) {
    $months = (float)$prog['total_months'];
    $programs_map[$prog['id']] = [
        'program_name'             => $prog['program_name'],
        'total_semesters'          => (int)$prog['total_semesters'],
        'total_months'             => (int)$prog['total_months'],
        'months_per_semester'      => $months > 0 ? round($months / (float)max($prog['total_semesters'], 1), 2) : 0,
        'standard_tuition_full'    => (int)$prog['standard_tuition_full'],
        'tuition_per_semester'     => (float)$prog['tuition_per_semester'],
        'admission_fees'           => (int)($prog['admission_fees'] ?? 0),
        'admission_fee_m'          => (int)($prog['admission_fee_m'] ?? 0),
        'fixed_institutional_fees' => (int)$prog['fixed_institutional_fees'],
        'english_course_fee'       => (int)$prog['english_course_fee'],
        'safety_net_cap'           => (int)$prog['safety_net_cap'],
        'safety_net_per_semester'  => (float)$prog['safety_net_per_semester'],
        'attendance_requirement'   => (int)$prog['attendance_requirement'],
        'safety_net_gpa_threshold' => (float)$prog['safety_net_gpa_threshold'],
        'monthly_fixed_fee'        => $months > 0 ? round((float)$prog['fixed_institutional_fees'] / $months, 2) : 0,
        'monthly_english_fee'      => $months > 0 ? round((float)$prog['english_course_fee'] / $months, 2) : 0,
        // Per-program fee constants (moved from global settings)
        'reg_fee_per_semester'     => (int)($prog['reg_fee_per_semester'] ?? 0),
        'form_id_fee'              => (int)($prog['form_id_fee'] ?? 0),
    ];
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id    = (int)($_POST['student_id']    ?? 0);
    $cf_program_id = (int)($_POST['cf_program_id'] ?? 0);
    $note          = trim($_POST['note']           ?? '');

    // Fee constants – allow manual override
    $program_name             = trim($_POST['program_name']             ?? '');
    $total_semesters          = (int)($_POST['total_semesters']         ?? 0);
    $total_months             = (int)($_POST['total_months']            ?? 0);
    $standard_tuition_full    = (int)($_POST['standard_tuition_full']   ?? 0);
    $tuition_per_semester     = (float)($_POST['tuition_per_semester']  ?? 0);
    $admission_fees           = (int)($_POST['admission_fees']          ?? 0);
    $fixed_institutional_fees = (int)($_POST['fixed_institutional_fees'] ?? 0);
    $english_course_fee       = (int)($_POST['english_course_fee']      ?? 0);
    $safety_net_cap           = (int)($_POST['safety_net_cap']          ?? 0);
    $safety_net_per_semester  = (float)($_POST['safety_net_per_semester'] ?? 0);
    $attendance_requirement   = (int)($_POST['attendance_requirement']  ?? 70);
    $safety_net_gpa_threshold = (float)($_POST['safety_net_gpa_threshold'] ?? 3.00);
    
    // Snapshot registration and form fees from POST (populated from program data via JS)
    $reg_fee_per_semester  = (float)($_POST['reg_fee_per_semester'] ?? 0);
    $form_id_fee           = (float)($_POST['form_id_fee']          ?? 0);

    // Validate
    if ($student_id <= 0)      $errors[] = 'Please select a valid student.';
    if ($program_name === '')   $errors[] = 'Programme name is required.';
    if ($total_semesters <= 0) $errors[] = 'Total semesters must be greater than 0.';
    if ($total_months <= 0)    $errors[] = 'Total months must be greater than 0.';

    $student = null;
    if ($student_id > 0) {
        $s = $db->prepare('SELECT * FROM students WHERE id = ?');
        $s->execute([$student_id]);
        $student = $s->fetch();
        if (!$student) {
            $errors[] = 'Student not found.';
        } elseif ($student['status'] !== 'Active') {
            $errors[] = 'Student is not Active (current status: ' . h($student['status']) . ').';
        }
    }

    // Check for existing package
    if ($student_id > 0 && empty($errors)) {
        $dup = $db->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
        $dup->execute([$student_id]);
        if ($dup->fetchColumn()) {
            $errors[] = 'This student already has a student account assigned. Delete the existing package first.';
        }
    }

    if (empty($errors)) {
        $months_per_semester = $total_months > 0 ? round($total_months / $total_semesters, 2) : 0;
        $monthly_fixed_fee   = $total_months > 0 ? round($fixed_institutional_fees / $total_months, 4) : 0;
        $monthly_english_fee = $total_months > 0 ? round($english_course_fee / $total_months, 4) : 0;

        $user = auth_user();

        $db->prepare(
            'INSERT INTO sfp_packages
               (student_id, cf_program_id, program_name,
                total_semesters, total_months, months_per_semester,
                standard_tuition_full, tuition_per_semester, admission_fees,
                fixed_institutional_fees, english_course_fee,
                reg_fee_per_semester, form_id_fee,
                safety_net_cap, safety_net_per_semester,
                attendance_requirement, safety_net_gpa_threshold,
                monthly_fixed_fee, monthly_english_fee,
                note, assigned_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $student_id,
            $cf_program_id > 0 ? $cf_program_id : null,
            $program_name,
            $total_semesters,
            $total_months,
            $months_per_semester,
            $standard_tuition_full,
            $tuition_per_semester,
            $admission_fees,
            $fixed_institutional_fees,
            $english_course_fee,
            $reg_fee_per_semester,
            $form_id_fee,
            $safety_net_cap > 0  ? $safety_net_cap            : null,
            $safety_net_per_semester > 0 ? $safety_net_per_semester : null,
            $attendance_requirement,
            $safety_net_gpa_threshold,
            $monthly_fixed_fee,
            $monthly_english_fee,
            $note ?: null,
            $user['id'],
        ]);

        $package_id = (int)$db->lastInsertId();

        // Auto-generate per-semester fee rows
        sfp_generate_semester_fees($package_id, $total_semesters, $tuition_per_semester);

        $label = ($student['full_name'] ?? '') . ' – ' . $program_name;
        log_change('student-accounts', 'CREATE', $package_id, $label, null, null, null, 'Student account assigned.');

        flash_set('success', 'Student account assigned to <strong>' . h($student['full_name']) . '</strong> successfully.');
        redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-plus-circle me-2 text-success"></i>Assign Student Account</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-accounts/index.php">Student Accounts</a></li>
            <li class="breadcrumb-item active">Assign</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" novalidate id="assign-form">
    <?= csrf_field() ?>

    <!-- ── Student ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-semibold py-3">
            <i class="fas fa-user-graduate me-2"></i>Student
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Search Student <span class="text-danger">*</span></label>
                    <input type="text" id="student-search-input" class="form-control"
                           placeholder="Type name or student ID…"
                           value="<?= h(old('student_search', '')) ?>">
                    <div id="student-suggestions"
                         class="list-group mt-1 position-absolute z-3"
                         style="max-width:420px;display:none;"></div>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Selected Student</label>
                    <input type="hidden" name="student_id" id="student-id-input" value="<?= h(old('student_id', '')) ?>">
                    <input type="hidden" name="student_search" id="student-search-hidden" value="<?= h(old('student_search', '')) ?>">
                    <div id="student-display" class="form-control bg-light text-muted" style="min-height:38px;">
                        <?php
                        $old_sid = (int)(old('student_id', ''));
                        if ($old_sid > 0) {
                            $s_row = $db->prepare('SELECT full_name, student_id FROM students WHERE id = ?');
                            $s_row->execute([$old_sid]);
                            $s_row = $s_row->fetch();
                            echo $s_row ? h($s_row['full_name'] . ' (' . $s_row['student_id'] . ')') : 'Not found';
                        } else {
                            echo 'None selected';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Programme / Fee Constants ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-success text-white fw-semibold py-3">
            <i class="fas fa-calculator me-2"></i>Programme &amp; Fee Constants
            <span class="fw-normal ms-2 opacity-75" style="font-size:.8rem;">
                Select a programme to auto-fill fee constants before saving.
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <!-- Programme selector -->
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
                        <option value="<?= $prog['id'] ?>"><?= h($prog['program_name']) ?></option>
                        <?php endforeach; if ($current_dtype !== '') echo '</optgroup>'; ?>
                    </select>
                    <input type="hidden" name="cf_program_id" id="cf-program-id-hidden" value="<?= h(old('cf_program_id', '')) ?>">
                </div>

                <!-- Programme name (editable) -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Programme Name <span class="text-danger">*</span></label>
                    <input type="text" name="program_name" class="form-control" required
                           value="<?= h(old('program_name', '')) ?>" placeholder="e.g. BSc in Computer Science and Engineering (CSE)">
                </div>

                <!-- Duration -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Total Semesters <span class="text-danger">*</span></label>
                    <input type="number" name="total_semesters" id="f-total-semesters" class="form-control" min="1" required
                           value="<?= h(old('total_semesters', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Total Months <span class="text-danger">*</span></label>
                    <input type="number" name="total_months" id="f-total-months" class="form-control" min="1" required
                           value="<?= h(old('total_months', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Months / Semester</label>
                    <input type="text" id="f-months-per-sem" class="form-control bg-light" readonly
                           placeholder="auto-calculated">
                </div>
            </div>

            <hr class="my-3">
            <h6 class="fw-semibold text-muted mb-3" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">
                Tuition Constants
            </h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Standard Tuition (Full)</label>
                    <div class="input-group">
                        <input type="number" name="standard_tuition_full" id="f-std-tuition" class="form-control" min="0"
                               value="<?= h(old('standard_tuition_full', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tuition Per Semester <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="tuition_per_semester" id="f-tuition-sem" class="form-control" min="0" step="0.01" required
                               value="<?= h(old('tuition_per_semester', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Admission Fees</label>
                    <div class="input-group">
                        <input type="number" name="admission_fees" id="f-admission" class="form-control bg-light" min="0" readonly
                               value="<?= h(old('admission_fees', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                    <div class="form-text">Auto-filled from course fee structure</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Registration Fee / Semester</label>
                    <div class="input-group">
                        <input type="number" name="reg_fee_per_semester" id="f-reg-fee" class="form-control bg-light" min="0" readonly
                               value="<?= h(old('reg_fee_per_semester', '0')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                    <div class="form-text">Auto-filled from course fee structure</div>
                </div>
                <input type="hidden" name="form_id_fee" id="f-form-id-fee" value="<?= h(old('form_id_fee', '0')) ?>">
            </div>

            <hr class="my-3">
            <h6 class="fw-semibold text-muted mb-3" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">
                Monthly Fee Constants
            </h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Fixed Institutional Fees (total)</label>
                    <div class="input-group">
                        <input type="number" name="fixed_institutional_fees" id="f-fixed-inst" class="form-control" min="0"
                               value="<?= h(old('fixed_institutional_fees', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                    <div class="form-text">Divided by total months → monthly charge</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Monthly Fixed Fee</label>
                    <div class="input-group">
                        <input type="text" id="f-monthly-fixed" class="form-control bg-light" readonly placeholder="auto">
                        <span class="input-group-text">BDT / month</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">English Course Fee (total)</label>
                    <div class="input-group">
                        <input type="number" name="english_course_fee" id="f-english" class="form-control" min="0"
                               value="<?= h(old('english_course_fee', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                    <div class="form-text">Divided by total months → monthly charge</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Monthly English Fee</label>
                    <div class="input-group">
                        <input type="text" id="f-monthly-english" class="form-control bg-light" readonly placeholder="auto">
                        <span class="input-group-text">BDT / month</span>
                    </div>
                </div>
            </div>

            <hr class="my-3">
            <h6 class="fw-semibold text-muted mb-3" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;">
                Safety Net
            </h6>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net Cap</label>
                    <div class="input-group">
                        <input type="number" name="safety_net_cap" id="f-snc" class="form-control" min="0"
                               value="<?= h(old('safety_net_cap', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net / Semester</label>
                    <div class="input-group">
                        <input type="number" name="safety_net_per_semester" id="f-sns" class="form-control" min="0" step="0.01"
                               value="<?= h(old('safety_net_per_semester', '')) ?>">
                        <span class="input-group-text">BDT</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Attendance Requirement</label>
                    <div class="input-group">
                        <input type="number" name="attendance_requirement" id="f-att" class="form-control" min="0" max="100"
                               value="<?= h(old('attendance_requirement', 70)) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Safety Net GPA Threshold</label>
                    <input type="number" name="safety_net_gpa_threshold" id="f-gpa-thr" class="form-control" min="0" max="4" step="0.01"
                           value="<?= h(old('safety_net_gpa_threshold', '3.00')) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- ── Note ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label fw-semibold">Note</label>
            <textarea name="note" class="form-control" rows="2"><?= h(old('note', '')) ?></textarea>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="fas fa-check me-1"></i> Assign Package &amp; Generate Semester Fees
        </button>
        <a href="<?= APP_URL ?>/student-accounts/index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
    </div>
</form>

<script>
var programsData = <?= json_encode($programs_map) ?>;

// ── Student search ────────────────────────────────────────────────────────────
var searchInput   = document.getElementById('student-search-input');
var suggestions   = document.getElementById('student-suggestions');
var studentIdInp  = document.getElementById('student-id-input');
var studentDisp   = document.getElementById('student-display');
var studentHidden = document.getElementById('student-search-hidden');
var searchTimer   = null;

searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    var q = this.value.trim();
    if (q.length < 2) { suggestions.style.display = 'none'; return; }
    searchTimer = setTimeout(function () {
        fetch('<?= APP_URL ?>/student-accounts/student-search.php?q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(data) {
                suggestions.innerHTML = '';
                if (!data.length) { suggestions.style.display = 'none'; return; }
                data.forEach(function(st) {
                    var a = document.createElement('a');
                    a.href = '#';
                    a.className = 'list-group-item list-group-item-action';
                    a.textContent = st.full_name + ' (' + st.student_id + ')';
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        studentIdInp.value  = st.id;
                        studentHidden.value = st.full_name + ' (' + st.student_id + ')';
                        searchInput.value   = st.full_name + ' (' + st.student_id + ')';
                        studentDisp.textContent = st.full_name + ' (' + st.student_id + ')';
                        studentDisp.classList.remove('text-muted');
                        suggestions.style.display = 'none';
                    });
                    suggestions.appendChild(a);
                });
                suggestions.style.display = 'block';
            });
    }, 300);
});

document.addEventListener('click', function(e) {
    if (!suggestions.contains(e.target) && e.target !== searchInput) {
        suggestions.style.display = 'none';
    }
});

// ── Programme auto-fill ───────────────────────────────────────────────────────
function fmt(n) { return parseFloat(n).toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2}); }

function recalcMonthly() {
    var tm  = parseFloat(document.getElementById('f-total-months').value)    || 0;
    var ts  = parseFloat(document.getElementById('f-total-semesters').value) || 0;
    var fi  = parseFloat(document.getElementById('f-fixed-inst').value)      || 0;
    var ec  = parseFloat(document.getElementById('f-english').value)         || 0;

    document.getElementById('f-months-per-sem').value = (tm > 0 && ts > 0) ? fmt(tm / ts) : '';
    document.getElementById('f-monthly-fixed').value  = tm > 0 ? fmt(fi / tm) : '';
    document.getElementById('f-monthly-english').value = tm > 0 ? fmt(ec / tm) : '';
}

['f-total-months','f-total-semesters','f-fixed-inst','f-english'].forEach(function(id) {
    document.getElementById(id).addEventListener('input', recalcMonthly);
});

document.getElementById('cf-program-select').addEventListener('change', function() {
    var pid = this.value;
    document.getElementById('cf-program-id-hidden').value = pid;
    if (!pid || !programsData[pid]) return;
    var p = programsData[pid];
    document.querySelector('[name=program_name]').value            = p.program_name;
    document.getElementById('f-total-semesters').value            = p.total_semesters;
    document.getElementById('f-total-months').value               = p.total_months;
    document.getElementById('f-std-tuition').value                = p.standard_tuition_full;
    document.getElementById('f-tuition-sem').value                = p.tuition_per_semester;
    document.getElementById('f-admission').value                  = p.admission_fees || p.admission_fee_m || 0;
    document.getElementById('f-reg-fee').value                    = p.reg_fee_per_semester || 0;
    document.getElementById('f-form-id-fee').value                = p.form_id_fee || 0;
    document.getElementById('f-fixed-inst').value                 = p.fixed_institutional_fees;
    document.getElementById('f-english').value                    = p.english_course_fee;
    document.getElementById('f-snc').value                        = p.safety_net_cap;
    document.getElementById('f-sns').value                        = p.safety_net_per_semester;
    document.getElementById('f-att').value                        = p.attendance_requirement;
    document.getElementById('f-gpa-thr').value                    = p.safety_net_gpa_threshold;
    recalcMonthly();
});

// Init calculated fields on page load (for form repopulation)
recalcMonthly();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
