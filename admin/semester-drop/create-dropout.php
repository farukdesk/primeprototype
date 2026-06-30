<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Dropout';
$db         = db();

$is_super = is_super_admin();
$me       = auth_user();

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id     = (int)($_POST['student_id'] ?? 0);
    $effective_date = trim($_POST['effective_date'] ?? '');
    $reason         = trim($_POST['reason'] ?? '');

    $errors = [];

    // Validate student
    $student = null;
    if ($student_id > 0) {
        $st = $db->prepare('SELECT id, full_name, student_id, status FROM students WHERE id = ?');
        $st->execute([$student_id]);
        $student = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$student) {
        $errors[] = 'Please select a valid student.';
    }

    // Validate effective date
    $eff_dt = false;
    if ($effective_date !== '') {
        $eff_dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $effective_date);
    }
    if (!($eff_dt instanceof \DateTimeImmutable) || $eff_dt->format('Y-m-d') !== $effective_date) {
        $errors[] = 'Please provide a valid dropout effective date.';
        $eff_dt = false;
    }

    // Already dropped out?
    if ($student && sd_student_dropped_out((int)$student['id'])) {
        $errors[] = 'This student is already recorded as an official dropout.';
    }

    // Evidence: required unless the creator is a super admin
    $evidence_file_id = null;
    $has_upload = isset($_FILES['evidence']) && ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if (!$is_super && !$has_upload) {
        $errors[] = 'Evidence is required. Please upload a supporting document.';
    }

    if (empty($errors) && $has_upload && $student) {
        $evidence_file_id = sd_store_evidence($_FILES['evidence'], (int)$student['id'], (int)$me['id']);
        if ($evidence_file_id === null) {
            $errors[] = 'Could not upload evidence. Allowed: images, PDF or Word documents up to 20 MB.';
        }
    }

    if (empty($errors) && $student) {
        $new_id = sd_create_dropout(
            (int)$student['id'],
            $effective_date,
            $reason !== '' ? $reason : null,
            $evidence_file_id,
            (int)$me['id']
        );
        flash_set('success', h($student['full_name']) . ' has been recorded as an official dropout. Their account is now frozen.');
        clear_old();
        redirect(APP_URL . '/semester-drop/view.php?id=' . $new_id);
    }

    foreach ($errors as $e) {
        flash_set('error', $e);
    }
    save_old([
        'student_id'     => $student_id,
        'student_label'  => trim($_POST['student_label'] ?? ''),
        'effective_date' => $effective_date,
        'reason'         => $reason,
    ]);
    redirect(APP_URL . '/semester-drop/create-dropout.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop / Dropout</a></li>
        <li class="breadcrumb-item active">New Dropout</li>
    </ol>
</nav>

<h1 class="h3 mb-4"><i class="fas fa-user-slash me-2 text-dark"></i>New Dropout</h1>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-8">
        <form method="post" enctype="multipart/form-data" class="card">
            <?= csrf_field() ?>
            <div class="card-body">

                <!-- Student search -->
                <div class="mb-4 position-relative">
                    <label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
                    <input type="hidden" name="student_id" id="student_id" value="<?= old('student_id') ?>">
                    <input type="text" id="student_search" name="student_label" class="form-control"
                           autocomplete="off"
                           placeholder="Search by student name or ID…"
                           value="<?= old('student_label') ?>">
                    <div id="student_results" class="list-group position-absolute w-100 shadow-sm"
                         style="z-index:1000; max-height:260px; overflow:auto;"></div>
                    <small class="text-muted">Start typing to search, then pick the student from the list.</small>
                </div>

                <!-- Effective date -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Dropout Effective Date <span class="text-danger">*</span></label>
                    <input type="date" name="effective_date" class="form-control"
                           value="<?= old('effective_date', date('Y-m-d')) ?>" required>
                    <small class="text-muted">From this date the account is frozen and no longer counted as a due.</small>
                </div>

                <!-- Reason -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Reason <span class="text-muted">(optional)</span></label>
                    <textarea name="reason" class="form-control" rows="2"
                              placeholder="Why is the student dropping out?"><?= old('reason') ?></textarea>
                </div>

                <!-- Evidence -->
                <div class="mb-2">
                    <label class="form-label fw-semibold">
                        Evidence <?= $is_super ? '<span class="text-muted">(optional for Super Admin)</span>' : '<span class="text-danger">*</span>' ?>
                    </label>
                    <input type="file" name="evidence" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx" <?= $is_super ? '' : 'required' ?>>
                    <small class="text-muted">
                        <?php if ($is_super): ?>
                        As a Super Administrator you may record a dropout without evidence.
                        <?php else: ?>
                        A supporting document is required (image, PDF or Word, up to 20 MB).
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-dark"
                        onclick="return confirm('Record this student as an official dropout? Their account will be frozen and their status set to Dropped.');">
                    <i class="fas fa-user-slash me-1"></i>Record Dropout
                </button>
                <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-semibold"><i class="fas fa-info-circle me-1 text-dark"></i>What a dropout does</h6>
                <ul class="small text-muted mb-0 ps-3">
                    <li>The student is officially marked as having <strong>left the university</strong>.</li>
                    <li>Their student <strong>status becomes “Dropped”</strong>.</li>
                    <li>From the effective date the <strong>account is frozen</strong> – it is no longer counted as a due in any financial fact (due reports, outstanding balances, etc.).</li>
                    <li>Re-instating a dropout later requires <strong>evidence and a comment</strong>.</li>
                    <li>Evidence is mandatory unless recorded by a Super Administrator.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var input    = document.getElementById('student_search');
    var hidden   = document.getElementById('student_id');
    var results  = document.getElementById('student_results');
    var timer    = null;

    function clearResults() { results.innerHTML = ''; }

    function selectStudent(id, label) {
        hidden.value = id;
        input.value  = label;
        clearResults();
    }

    input.addEventListener('input', function () {
        hidden.value = '';
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
                            selectStudent(s.id, (s.full_name || '') + ' (' + (s.student_id || '') + ')');
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
