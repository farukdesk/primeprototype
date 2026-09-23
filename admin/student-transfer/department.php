<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-transfer', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Department Transfer';
$db = db();
$me = auth_user();

// ── Step 1: resolve which student we're transferring ─────────────────────────
$student_id = (int)($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$student    = $student_id > 0 ? stt_get_student($student_id) : null;

if ($student && !can_access_dept((int)$student['dept_id'])) {
    flash_set('error', 'You do not have permission to transfer this student.');
    $student    = null;
    $student_id = 0;
}

// ── Handle POST (step 2: submit the transfer) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $student) {
    csrf_check();

    $to_dept_id    = (int)($_POST['to_dept_id'] ?? 0);
    $to_program_id = (int)($_POST['to_program_id'] ?? 0);
    $id_mode_in    = (string)($_POST['id_mode'] ?? 'keep');
    $id_mode       = in_array($id_mode_in, ['auto', 'manual'], true) ? $id_mode_in : 'keep';
    $manual_sid    = trim($_POST['manual_student_id'] ?? '');
    $reason        = trim($_POST['reason'] ?? '');

    $result = stt_create_department_transfer(
        $student_id,
        $to_dept_id,
        $to_program_id,
        $id_mode,
        $manual_sid,
        $reason !== '' ? $reason : null,
        (int)$me['id']
    );

    if ($result['ok']) {
        flash_set('success', $result['message']);
        redirect(APP_URL . '/student-transfer/view.php?id=' . $result['transfer_id']);
    }
    flash_set('error', $result['message']);
    redirect(APP_URL . '/student-transfer/department.php?student_id=' . $student_id);
}

$departments = stt_allowed_depts();
$programs    = stt_program_map();
$dept_map    = stt_dept_map();

$current_dept_name    = $student ? ($dept_map[(int)$student['dept_id']]['name'] ?? '— unknown —') : '';
$current_program_name = ($student && (int)($student['program_id'] ?? 0) > 0)
    ? ($programs[(int)$student['program_id']]['program_name'] ?? '— unknown —')
    : '— None —';

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-transfer/index.php">Student Transfer</a></li>
        <li class="breadcrumb-item active">Department Transfer</li>
    </ol>
</nav>

<h1 class="h3 mb-4"><i class="fas fa-building me-2 text-primary"></i>Department Transfer</h1>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-8">

        <?php if (!$student): ?>
        <!-- ── Step 1: find the student ── -->
        <div class="card">
            <div class="card-body position-relative">
                <label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
                <input type="text" id="student_search" class="form-control" autocomplete="off"
                       placeholder="Search by student name or ID…">
                <div id="student_results" class="list-group position-absolute shadow-sm"
                     style="z-index:1000; max-height:260px; overflow:auto; width:calc(100% - 3rem);"></div>
                <small class="text-muted">Start typing to search, then pick the student from the list.</small>
            </div>
        </div>

        <?php else: ?>
        <!-- ── Student summary ── -->
        <div class="card mb-3">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="fw-semibold fs-5"><?= h($student['full_name']) ?></div>
                    <div class="text-muted small">ID: <?= h($student['student_id']) ?></div>
                    <div class="small mt-1">
                        Currently: <strong><?= h($current_dept_name) ?></strong>
                        <?php if ($current_program_name !== '— None —'): ?> / <?= h($current_program_name) ?><?php endif; ?>
                    </div>
                </div>
                <a href="<?= APP_URL ?>/student-transfer/department.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-search me-1"></i>Change Student
                </a>
            </div>
        </div>

        <!-- ── Step 2: transfer form ── -->
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
            <div class="card-body">

                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">To Department <span class="text-danger">*</span></label>
                        <select name="to_dept_id" id="to_dept_id" class="form-select" required>
                            <option value="">— Select department —</option>
                            <?php foreach ($departments as $dep): ?>
                            <option value="<?= (int)$dep['id'] ?>" <?= (int)$student['dept_id'] === (int)$dep['id'] ? 'selected' : '' ?>>
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
                                    <?= (int)($student['program_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= h($p['program_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only programs belonging to the selected department are shown.</small>
                    </div>
                </div>

                <!-- Student ID -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Student ID</label>
                    <div class="d-flex flex-column gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="id_keep" value="keep" checked>
                            <label class="form-check-label" for="id_keep">
                                Keep the current ID (<strong><?= h($student['student_id']) ?></strong>)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="id_auto" value="auto">
                            <label class="form-check-label" for="id_auto">
                                Auto-generate a new ID for the target department/program's numbering
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="id_mode" id="id_manual" value="manual">
                            <label class="form-check-label" for="id_manual">Type a new ID</label>
                        </div>
                        <input type="text" name="manual_student_id" id="manual_student_id" class="form-control d-none"
                               style="max-width:280px;" placeholder="New Student ID" disabled maxlength="20">
                    </div>
                </div>

                <!-- Reason -->
                <div class="mb-2">
                    <label class="form-label fw-semibold">Reason <span class="text-muted">(optional)</span></label>
                    <textarea name="reason" class="form-control" rows="2"
                              placeholder="Why is this student being transferred?"></textarea>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt me-1"></i>Transfer Department</button>
                <a href="<?= APP_URL ?>/student-transfer/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-semibold"><i class="fas fa-info-circle me-1 text-primary"></i>How it works</h6>
                <ul class="small text-muted mb-0 ps-3">
                    <li>This is a real, one-way move: the student's department (and program, if you choose one) changes immediately.</li>
                    <li>The Student ID keeps its numbering convention unless you choose to auto-generate or type a new one.</li>
                    <li>A fee package (Student Accounts) is <strong>not</strong> tied to department/program in this system, so it is never changed automatically — after saving, you'll be shown the student's current package (if any) and asked what to do with it.</li>
                    <li>Every transfer is permanently logged. To undo one, record another transfer back.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var input   = document.getElementById('student_search');
    var results = document.getElementById('student_results');
    var timer   = null;

    if (input) {
        function clearResults() { results.innerHTML = ''; }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            if (timer) clearTimeout(timer);
            if (q.length < 2) { clearResults(); return; }
            timer = setTimeout(function () {
                fetch('<?= APP_URL ?>/student-accounts/student-search.php?q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (rows) {
                        clearResults();
                        rows.forEach(function (s) {
                            var a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action py-2';
                            a.innerHTML = '<strong>' + (s.full_name || '') + '</strong> '
                                + '<span class="text-muted small">' + (s.student_id || '') + '</span>';
                            a.addEventListener('click', function () {
                                window.location = 'department.php?student_id=' + encodeURIComponent(s.id);
                            });
                            results.appendChild(a);
                        });
                    })
                    .catch(function () { clearResults(); });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!results.contains(e.target) && e.target !== input) clearResults();
        });
    }

    // ── Filter "To Program" options to the chosen department ───────────────
    var deptEl = document.getElementById('to_dept_id');
    var progEl = document.getElementById('to_program_id');
    function filterPrograms() {
        if (!deptEl || !progEl) return;
        var dept = deptEl.value;
        var opts = progEl.querySelectorAll('option[data-dept]');
        var selectedStillValid = false;
        opts.forEach(function (opt) {
            var match = (opt.getAttribute('data-dept') === dept);
            opt.hidden = !match;
            if (match && opt.selected) selectedStillValid = true;
        });
        if (!selectedStillValid) progEl.value = '';
    }
    if (deptEl) {
        deptEl.addEventListener('change', filterPrograms);
        filterPrograms();
    }

    // ── Toggle the manual Student ID input ──────────────────────────────────
    var manualInput = document.getElementById('manual_student_id');
    var idRadios = document.querySelectorAll('input[name="id_mode"]');
    function updateIdMode() {
        var manual = document.getElementById('id_manual');
        var isManual = !!(manual && manual.checked);
        if (manualInput) {
            manualInput.classList.toggle('d-none', !isManual);
            manualInput.disabled = !isManual;
            manualInput.required = isManual;
        }
    }
    idRadios.forEach(function (r) { r.addEventListener('change', updateIdMode); });
    updateIdMode();
}());
</script>
