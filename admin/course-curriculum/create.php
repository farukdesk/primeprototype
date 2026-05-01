<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';
if (!cc_is_staff()) { redirect(APP_URL . '/course-curriculum/index.php'); }

// Resolve context from GET / POST
$dept_id    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$program_id = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$def_sem    = max(1, min(12, (int)($_GET['semester'] ?? 1)));

$page_title = 'Add Subject';
$errors     = [];
clear_old();

// Verify program exists and belongs to dept
$program_row = null;
if ($program_id > 0 && $dept_id > 0) {
    $st = db()->prepare(
        "SELECT p.*, d.name AS dept_name
           FROM dept_academic_programs p
           JOIN dept_departments d ON d.id = p.dept_id
          WHERE p.id = ? AND p.dept_id = ?
          LIMIT 1"
    );
    $st->execute([$program_id, $dept_id]);
    $program_row = $st->fetch() ?: null;
}
if (!$program_row) {
    flash_set('danger', 'Invalid department or program selection.');
    redirect(APP_URL . '/course-curriculum/index.php');
}

// Load faculty for this department
$dept_faculty = cc_get_dept_faculty($dept_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $semester              = max(0, min(12, (int)($_POST['semester'] ?? 0)));
    $sl_no                 = max(1, (int)($_POST['sl_no'] ?? 1));
    $bnqf_code             = trim($_POST['bnqf_code']             ?? '');
    $course_code           = trim($_POST['course_code']           ?? '');
    $course_name           = trim($_POST['course_name']           ?? '');
    $credit_raw            = trim($_POST['credit']                ?? '');
    $sort_order            = max(0, (int)($_POST['sort_order']    ?? 0));
    $assigned_faculty_id   = (int)($_POST['assigned_faculty_id']  ?? 0) ?: null;

    if ($course_name === '')            $errors[] = 'Subject Title is required.';
    if (mb_strlen($course_name) > 300) $errors[] = 'Subject Title must be 300 characters or less.';

    $credit = null;
    if ($credit_raw !== '') {
        if (!is_numeric($credit_raw) || (float)$credit_raw < 0) {
            $errors[] = 'Credit must be a non-negative number.';
        } else {
            $credit = (float)$credit_raw;
        }
    }

    // Validate assigned_faculty_id belongs to this dept
    if ($assigned_faculty_id !== null) {
        $fv = db()->prepare(
            "SELECT id FROM dept_faculty WHERE id = ? AND dept_id = ? LIMIT 1"
        );
        $fv->execute([$assigned_faculty_id, $dept_id]);
        if (!$fv->fetch()) {
            $assigned_faculty_id = null; // silently ignore invalid faculty
        }
    }

    // Marking distribution entries
    $dist_names  = (array)($_POST['dist_name']  ?? []);
    $dist_marks  = (array)($_POST['dist_marks'] ?? []);
    $dist_orders = (array)($_POST['dist_order'] ?? []);

    $valid_dists = [];
    $dist_total  = 0;
    foreach ($dist_names as $di => $dname) {
        $dname  = trim($dname);
        $dmarks = isset($dist_marks[$di]) ? (float)$dist_marks[$di] : 0;
        if ($dname === '') continue;
        if ($dmarks <= 0) { $errors[] = 'Each distribution entry must have max marks greater than 0.'; break; }
        $dist_total += $dmarks;
        $valid_dists[] = ['name' => $dname, 'marks' => $dmarks, 'order' => (int)($dist_orders[$di] ?? $di)];
    }
    if (!empty($valid_dists) && abs($dist_total - 100) > 0.01) {
        $errors[] = 'Marking distribution totals must add up to 100 (currently ' . number_format($dist_total, 2) . ').';
    }

    if (empty($errors)) {
        db()->prepare(
            "INSERT INTO course_curriculum
               (program_id, semester, sl_no, bnqf_code, course_code, course_name, credit, assigned_faculty_id, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $program_id, $semester, $sl_no,
            $bnqf_code   ?: null,
            $course_code ?: null,
            $course_name,
            $credit,
            $assigned_faculty_id,
            $sort_order,
        ]);
        $new_id = (int)db()->lastInsertId();

        // Save marking distributions
        if (!empty($valid_dists)) {
            $dist_stmt = db()->prepare(
                "INSERT INTO cc_mark_distributions (curriculum_id, distribution_name, max_marks, sort_order)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($valid_dists as $dist) {
                $dist_stmt->execute([$new_id, $dist['name'], $dist['marks'], $dist['order']]);
            }
        }

        log_change(
            'course-curriculum',
            'CREATE',
            $new_id,
            $course_name,
            null, null, null,
            'Subject "' . $course_name . '" added to program #' . $program_id
        );

        flash_set('success', 'Subject <strong>' . h($course_name) . '</strong> added.');
        redirect(APP_URL . '/course-curriculum/index.php?dept_id=' . $dept_id . '&program_id=' . $program_id);
    }

    save_old(compact('semester','sl_no','bnqf_code','course_code','course_name','credit_raw','sort_order','assigned_faculty_id') + [
        'dist_name'  => $dist_names,
        'dist_marks' => $dist_marks,
        'dist_order' => $dist_orders,
    ]);
}

$semester_labels = cc_semester_labels();

require_once __DIR__ . '/../includes/header.php';
// Tom Select for searchable faculty dropdown
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">';
echo '<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>">
                    Course Curriculum
                </a>
            </li>
            <li class="breadcrumb-item active">Add Subject</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Context -->
<div class="alert alert-light border mb-4 small py-2 px-3">
    <i class="fas fa-building me-1 text-muted"></i><?= h($program_row['dept_name']) ?>
    &nbsp;→&nbsp;
    <strong><?= h($program_row['program_name']) ?></strong>
</div>

<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="dept_id"    value="<?= $dept_id ?>">
    <input type="hidden" name="program_id" value="<?= $program_id ?>">

    <div class="row g-4">

        <!-- ── Left column ──────────────────────────────────────────────────── -->
        <div class="col-lg-8">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-book me-2 text-muted"></i>Subject Details
                    </h6>
                </div>
                <div class="card-body p-4">

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Subject Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="course_name" class="form-control"
                               value="<?= old('course_name') ?>" maxlength="300"
                               placeholder="e.g. Introduction to Computer Science" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Subject Code</label>
                            <input type="text" name="course_code" class="form-control"
                                   value="<?= old('course_code') ?>" maxlength="50"
                                   placeholder="e.g. CSE 101">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Credit</label>
                            <input type="number" name="credit" class="form-control"
                                   value="<?= old('credit_raw') ?>" step="0.01" min="0"
                                   placeholder="e.g. 3.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">
                            Course Teacher
                        </label>
                        <select name="assigned_faculty_id" id="faculty_select" class="form-select">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ($dept_faculty as $f): ?>
                            <option value="<?= $f['id'] ?>"
                                <?= (int)old('assigned_faculty_id', 0) == $f['id'] ? 'selected' : '' ?>>
                                <?= h($f['name']) ?><?= $f['designation'] ? ' — ' . h($f['designation']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Type to search by name or designation.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">BNQF Code</label>
                            <input type="text" name="bnqf_code" class="form-control"
                                   value="<?= old('bnqf_code') ?>" maxlength="50"
                                   placeholder="e.g. BNQ-1234">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">SL No.</label>
                            <input type="number" name="sl_no" class="form-control"
                                   value="<?= old('sl_no', 1) ?>" min="1" max="999">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="<?= old('sort_order', 0) ?>" min="0">
                            <div class="form-text">Lower = shown first.</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Right column ─────────────────────────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card" style="border-radius:12px;">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold">
                        <i class="fas fa-calendar-alt me-2 text-muted"></i>Semester
                    </h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-medium">
                            Semester <span class="text-danger">*</span>
                        </label>
                        <select name="semester" class="form-select">
                            <option value="0" <?= (int)old('semester', 0) === 0 ? 'selected' : '' ?>>— Not Assigned —</option>
                            <?php foreach ($semester_labels as $n => $lbl): ?>
                            <option value="<?= $n ?>"
                                <?= (int)old('semester', 0) === $n ? 'selected' : '' ?>>
                                <?= h($lbl) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Courses not assigned to a semester will not appear on the public program page.</div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save Subject
                        </button>
                        <a href="<?= APP_URL ?>/course-curriculum/index.php?dept_id=<?= $dept_id ?>&program_id=<?= $program_id ?>"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Marking Distribution ─────────────────────────────────────────────── -->
    <div class="card mt-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-chart-pie me-2 text-muted"></i>Marking Distribution
                <small class="text-muted fw-normal ms-2">(optional — if entered, total must equal 100)</small>
            </h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addDistRow()">
                <i class="fas fa-plus me-1"></i> Add Entry
            </button>
        </div>
        <div class="card-body p-4">
            <div id="dist-rows"></div>
            <p class="text-muted small mb-0" id="dist-empty-msg">No marking distribution entries yet. Click <strong>Add Entry</strong> to add one.</p>
            <div id="dist-total-wrap" class="mt-3 pt-3 border-top" style="display:none;">
                <span class="small">Total marks: <strong id="dist-total">0.00</strong> / 100</span>
                <span class="text-danger ms-2 small" id="dist-total-warn" style="display:none;">
                    <i class="fas fa-exclamation-triangle me-1"></i>Must equal 100
                </span>
            </div>
        </div>
    </div>

</form>

<script>
new TomSelect('#faculty_select', {
    placeholder: '— Not Assigned —',
    allowEmptyOption: true,
    sortField: 'text',
});

// ── Marking Distribution dynamic rows ────────────────────────────────────────
var distRowIndex = 0;
var preloadDists = <?= json_encode(array_map(null, old_array('dist_name'), old_array('dist_marks'), old_array('dist_order')), JSON_HEX_TAG) ?>;

function addDistRow(name, marks, order) {
    var idx = distRowIndex++;
    var n   = name  ?? '';
    var m   = marks ?? '';
    var o   = order ?? idx;
    var row = document.createElement('div');
    row.className = 'row g-2 align-items-end mb-2 dist-row';
    row.dataset.idx = idx;
    row.innerHTML =
        '<div class="col">' +
            '<label class="form-label small fw-medium mb-1">Distribution Name</label>' +
            '<input type="text" name="dist_name[]" class="form-control form-control-sm dist-name" value="' + escHtml(n) + '" placeholder="e.g. Attendance, Mid Term, Final" maxlength="100">' +
        '</div>' +
        '<div class="col-auto" style="min-width:110px;">' +
            '<label class="form-label small fw-medium mb-1">Max Marks</label>' +
            '<input type="number" name="dist_marks[]" class="form-control form-control-sm dist-marks" value="' + escHtml(String(m)) + '" step="0.01" min="0.01" max="100" placeholder="e.g. 30">' +
        '</div>' +
        '<input type="hidden" name="dist_order[]" value="' + o + '">' +
        '<div class="col-auto">' +
            '<label class="form-label small d-block" style="opacity:0;">.</label>' +
            '<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDistRow(this)" title="Remove"><i class="fas fa-times"></i></button>' +
        '</div>';
    row.querySelectorAll('.dist-name,.dist-marks').forEach(function(el) {
        el.addEventListener('input', updateDistTotal);
    });
    document.getElementById('dist-rows').appendChild(row);
    updateDistVisibility();
    updateDistTotal();
}

function removeDistRow(btn) {
    btn.closest('.dist-row').remove();
    updateDistVisibility();
    updateDistTotal();
}

function updateDistVisibility() {
    var rows = document.querySelectorAll('.dist-row');
    document.getElementById('dist-empty-msg').style.display  = rows.length === 0 ? '' : 'none';
    document.getElementById('dist-total-wrap').style.display = rows.length === 0 ? 'none' : '';
}

function updateDistTotal() {
    var total = 0;
    document.querySelectorAll('.dist-marks').forEach(function(el) {
        var v = parseFloat(el.value);
        if (!isNaN(v)) total += v;
    });
    document.getElementById('dist-total').textContent = total.toFixed(2);
    var warn = document.getElementById('dist-total-warn');
    var hasRows = document.querySelectorAll('.dist-row').length > 0;
    warn.style.display = (hasRows && Math.abs(total - 100) > 0.01) ? '' : 'none';
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Pre-populate from old() data on validation failure
preloadDists.forEach(function(entry) {
    if (entry[0] !== undefined && entry[0] !== null && entry[0] !== '') {
        addDistRow(entry[0], entry[1], entry[2]);
    }
});
updateDistVisibility();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
