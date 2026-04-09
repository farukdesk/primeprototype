<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('scholarship', 'can_create');

$page_title = 'Award Scholarship';
$errors     = [];
$db         = db();

$policies = $db->query(
    'SELECT id, name, type FROM sc_policies WHERE is_active = 1 ORDER BY sort_order, name'
)->fetchAll();

$tiers_by_policy = [];
foreach ($policies as $pol) {
    $tiers_by_policy[$pol['id']] = sc_get_tiers((int)$pol['id']);
}

$settings = sc_get_settings();

$current_semester = (function () {
    $m = (int)date('n');
    $y = date('Y');
    if ($m >= 1 && $m <= 4)  return 'Spring ' . $y;
    if ($m >= 5 && $m <= 8)  return 'Summer ' . $y;
    return 'Fall ' . $y;
})();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_search = trim($_POST['student_search'] ?? '');
    $student_id     = (int)($_POST['student_id']    ?? 0);
    $policy_id      = (int)($_POST['policy_id']     ?? 0);
    $tier_id        = (int)($_POST['tier_id']        ?? 0) ?: null;
    $semester       = trim($_POST['semester']        ?? '');
    $gpa_used       = trim($_POST['gpa_used']        ?? '');
    $discount       = trim($_POST['discount_percent'] ?? '');
    $note           = trim($_POST['note']            ?? '');
    $force          = isset($_POST['force_duplicate']);

    if ($student_id <= 0)     $errors[] = 'Please select a valid student.';
    if ($policy_id <= 0)      $errors[] = 'Please select a policy.';
    if ($semester === '')     $errors[] = 'Semester is required.';
    if (!is_numeric($discount) || (float)$discount < 0 || (float)$discount > 100) $errors[] = 'Discount percent must be between 0 and 100.';

    $student = null;
    $policy  = null;

    if ($student_id > 0) {
        $stmt = $db->prepare('SELECT * FROM students WHERE id = ?');
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();
        if (!$student) $errors[] = 'Student not found.';
        elseif ($student['status'] !== 'Active') $errors[] = 'Student is not Active (current status: ' . h($student['status']) . ').';
    }

    if ($policy_id > 0) {
        $policy = sc_get_policy($policy_id);
        if (!$policy || !$policy['is_active']) $errors[] = 'Selected policy is not active.';
    }

    $duplicate_warning = false;
    if (empty($errors) && $student && $policy) {
        if ($policy['type'] === 'gpa_based' && $student['admitted_semester'] !== $semester) {
            $errors[] = 'GPA-based scholarships can only be awarded for the student\'s first semester (<strong>' . h($student['admitted_semester']) . '</strong>).';
        }

        $dup_stmt = $db->prepare(
            'SELECT COUNT(*) FROM sc_awards a
             JOIN sc_policies p ON p.id = a.policy_id
             WHERE a.student_id = ? AND a.semester = ? AND p.type = ? AND a.status = \'active\''
        );
        $dup_stmt->execute([$student_id, $semester, $policy['type']]);
        if ((int)$dup_stmt->fetchColumn() > 0 && !$force) {
            $duplicate_warning = true;
            $errors[] = 'This student already has an active ' . $policy['type'] . ' award for semester <strong>' . h($semester) . '</strong>. Check "Allow duplicate" to override.';
        }
    }

    if (empty($errors)) {
        $user = auth_user();

        $db->prepare(
            'INSERT INTO sc_awards (student_id, policy_id, tier_id, semester, gpa_used, discount_percent, status, note, awarded_by)
             VALUES (?,?,?,?,?,?,\'active\',?,?)'
        )->execute([
            $student_id,
            $policy_id,
            $tier_id ?: null,
            $semester,
            $gpa_used !== '' && is_numeric($gpa_used) ? (float)$gpa_used : null,
            (float)$discount,
            $note ?: null,
            $user['id'],
        ]);

        $award_id = (int)$db->lastInsertId();
        $label    = ($student['full_name'] ?? '') . ' – ' . ($policy['name'] ?? '') . ' (' . $semester . ')';
        log_change('scholarship', 'CREATE', $award_id, $label, null, null, null, 'Award created manually.');

        flash_set('success', 'Scholarship awarded to <strong>' . h($student['full_name']) . '</strong> successfully.');
        redirect(APP_URL . '/scholarship/index.php');
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';

$tiers_json = json_encode($tiers_by_policy);
$policies_json = json_encode(array_column($policies, null, 'id'));
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-award me-2 text-warning"></i>Award Scholarship</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item active">Award</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <form method="post" novalidate id="award-form">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-warning text-dark fw-semibold py-3">
                    <i class="fas fa-user-graduate me-2"></i>Student
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Search Student (Name or Student ID)</label>
                            <input type="text" id="student-search-input" class="form-control" placeholder="Type name or student ID…"
                                   value="<?= h(old('student_search', '')) ?>">
                            <div id="student-suggestions" class="list-group mt-1 position-absolute z-3" style="max-width:400px;display:none;"></div>
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

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3">
                    <i class="fas fa-graduation-cap me-2"></i>Award Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Policy <span class="text-danger">*</span></label>
                            <select name="policy_id" id="policy-select" class="form-select" required>
                                <option value="">— Select Policy —</option>
                                <?php foreach ($policies as $pol): ?>
                                <option value="<?= $pol['id'] ?>" data-type="<?= h($pol['type']) ?>"
                                        <?= old('policy_id') == $pol['id'] ? 'selected' : '' ?>>
                                    <?= h($pol['name']) ?>
                                    (<?= $pol['type'] === 'gpa_based' ? 'GPA-Based' : 'Merit-Based' ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tier</label>
                            <select name="tier_id" id="tier-select" class="form-select">
                                <option value="">— Select Tier —</option>
                            </select>
                            <div id="tier-info" class="form-text"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Semester <span class="text-danger">*</span></label>
                            <input type="text" name="semester" class="form-control" required
                                   value="<?= h(old('semester', $current_semester)) ?>" placeholder="e.g. Fall 2025">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">GPA Used</label>
                            <input type="number" name="gpa_used" id="gpa-used-input" class="form-control" step="0.01" min="0"
                                   value="<?= h(old('gpa_used', '')) ?>" placeholder="e.g. 9.50">
                            <div class="form-text" id="gpa-label-text"><?= h($settings['gpa_label'] ?? 'Combined GPA') ?></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Discount % <span class="text-danger">*</span></label>
                            <input type="number" name="discount_percent" id="discount-input" class="form-control" step="0.01" min="0" max="100" required
                                   value="<?= h(old('discount_percent', '')) ?>" placeholder="e.g. 50.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Note</label>
                            <textarea name="note" class="form-control" rows="2"><?= h(old('note', '')) ?></textarea>
                        </div>
                        <?php if (isset($duplicate_warning) && $duplicate_warning): ?>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="force_duplicate" id="force_duplicate" value="1">
                                <label class="form-check-label text-danger fw-semibold" for="force_duplicate">
                                    Allow duplicate award for this semester
                                </label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-award me-1"></i> Award Scholarship</button>
                <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
var tiersData    = <?= $tiers_json ?>;
var policiesData = <?= $policies_json ?>;

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
        fetch('<?= APP_URL ?>/scholarship/student-search.php?q=' + encodeURIComponent(q))
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

// ── Policy → tier select ──────────────────────────────────────────────────────
var policySelect  = document.getElementById('policy-select');
var tierSelect    = document.getElementById('tier-select');
var discountInput = document.getElementById('discount-input');
var tierInfo      = document.getElementById('tier-info');
var gpaInput      = document.getElementById('gpa-used-input');

function populateTiers(policyId) {
    tierSelect.innerHTML = '<option value="">— Select Tier —</option>';
    tierInfo.textContent = '';
    if (!policyId || !tiersData[policyId]) return;
    tiersData[policyId].forEach(function(t) {
        var opt = document.createElement('option');
        opt.value = t.id;
        var lbl = t.label ? t.label + ': ' : '';
        opt.textContent = lbl + t.min_gpa + '–' + t.max_gpa + ' GPA → ' + t.discount_percent + '% off';
        opt.dataset.discount = t.discount_percent;
        tierSelect.appendChild(opt);
    });
}

policySelect.addEventListener('change', function () {
    populateTiers(this.value);
});

tierSelect.addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.discount !== undefined) {
        discountInput.value = opt.dataset.discount;
        tierInfo.textContent = 'Discount auto-filled from tier. You may adjust manually.';
    } else {
        tierInfo.textContent = '';
    }
});

// Auto-fill tier when GPA is entered
gpaInput.addEventListener('change', function () {
    var policyId = policySelect.value;
    if (!policyId || !tiersData[policyId]) return;
    var gpa = parseFloat(this.value);
    if (isNaN(gpa)) return;
    var matched = null;
    tiersData[policyId].forEach(function(t) {
        if (gpa >= parseFloat(t.min_gpa) && gpa <= parseFloat(t.max_gpa)) matched = t;
    });
    if (matched) {
        tierSelect.value      = matched.id;
        discountInput.value   = matched.discount_percent;
        tierInfo.textContent  = 'Tier auto-matched by GPA. Discount auto-filled.';
    }
});

// Init on page load (for form repopulation)
(function() {
    var pId = policySelect.value;
    if (pId) {
        populateTiers(pId);
        var savedTier = '<?= h(old('tier_id', '')) ?>';
        if (savedTier) tierSelect.value = savedTier;
        var savedDisc = '<?= h(old('discount_percent', '')) ?>';
        if (savedDisc) discountInput.value = savedDisc;
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
