<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-transfer', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Batch Transfer';
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

    $to_batch_id = (int)($_POST['to_batch_id'] ?? 0);
    $reason      = trim($_POST['reason'] ?? '');

    $result = stt_create_batch_transfer($student_id, $to_batch_id, $reason !== '' ? $reason : null, (int)$me['id']);

    if ($result['ok']) {
        flash_set('success', $result['message']);
        redirect(APP_URL . '/student-transfer/view.php?id=' . $result['transfer_id']);
    }
    flash_set('error', $result['message']);
    redirect(APP_URL . '/student-transfer/batch.php?student_id=' . $student_id);
}

$batches = stt_batch_map();
$current_batch_name = ($student && (int)($student['batch_id'] ?? 0) > 0)
    ? ($batches[(int)$student['batch_id']]['name'] ?? ($student['batch'] ?: '— unknown —'))
    : ($student['batch'] ?? '— None —');

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-transfer/index.php">Student Transfer</a></li>
        <li class="breadcrumb-item active">Batch Transfer</li>
    </ol>
</nav>

<h1 class="h3 mb-4"><i class="fas fa-users me-2 text-primary"></i>Batch Transfer</h1>

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
                    <div class="small mt-1">Currently in batch: <strong><?= h($current_batch_name) ?></strong></div>
                </div>
                <a href="<?= APP_URL ?>/student-transfer/batch.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-search me-1"></i>Change Student
                </a>
            </div>
        </div>

        <!-- ── Step 2: transfer form ── -->
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold">To Batch <span class="text-danger">*</span></label>
                    <select name="to_batch_id" class="form-select" required>
                        <option value="">— Select batch —</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)($student['batch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= h($b['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Reason <span class="text-muted">(optional)</span></label>
                    <textarea name="reason" class="form-control" rows="2"
                              placeholder="Why is this student being moved to another batch?"></textarea>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-exchange-alt me-1"></i>Transfer Batch</button>
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
                    <li>This is a real, one-way move of the student's actual batch.</li>
                    <li>This is separate from the existing "transferred into another batch" feature on the student profile, which lets a student belong to two batches at once (e.g. for a retake) without changing their real batch — that feature is unaffected.</li>
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
    if (!input) return;

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
                            window.location = 'batch.php?student_id=' + encodeURIComponent(s.id);
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
}());
</script>
