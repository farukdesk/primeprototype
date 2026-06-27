<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'New Semester Drop';
$db         = db();

$is_super = is_super_admin();
$me       = auth_user();

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_id = (int)($_POST['student_id'] ?? 0);
    $type       = ($_POST['semester_type'] ?? '') === 'tri' ? 'tri' : 'bi';
    $drop_start = trim($_POST['drop_start'] ?? '');
    $reason     = trim($_POST['reason'] ?? '');

    $errors = [];

    // Validate student
    $student = null;
    if ($student_id > 0) {
        $st = $db->prepare('SELECT id, full_name, student_id FROM students WHERE id = ?');
        $st->execute([$student_id]);
        $student = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$student) {
        $errors[] = 'Please select a valid student.';
    }

    // Validate drop start date
    $start_dt = $drop_start !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $drop_start) : false;
    if (!$start_dt || $start_dt->format('Y-m-d') !== $drop_start) {
        $errors[] = 'Please provide a valid drop start date.';
    }

    // Prevent overlapping active drops for the same student
    if ($student && $start_dt) {
        $new_end = sd_compute_end($drop_start, $type);
        $ov = $db->prepare(
            'SELECT COUNT(*) FROM semester_drops
              WHERE student_id = ? AND status = \'active\'
                AND drop_start <= ? AND drop_end >= ?'
        );
        $ov->execute([$student_id, $new_end, $drop_start]);
        if ((int)$ov->fetchColumn() > 0) {
            $errors[] = 'This student already has an active semester drop overlapping this period.';
        }
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
        $new_id = sd_create_drop(
            (int)$student['id'],
            $type,
            $drop_start,
            $reason !== '' ? $reason : null,
            $evidence_file_id,
            (int)$me['id']
        );
        flash_set('success', 'Semester drop recorded for ' . h($student['full_name']) . '.');
        clear_old();
        redirect(APP_URL . '/semester-drop/view.php?id=' . $new_id);
    }

    foreach ($errors as $e) {
        flash_set('error', $e);
    }
    save_old([
        'student_id'    => $student_id,
        'student_label' => trim($_POST['student_label'] ?? ''),
        'semester_type' => $type,
        'drop_start'    => $drop_start,
        'reason'        => $reason,
    ]);
    redirect(APP_URL . '/semester-drop/create.php');
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop</a></li>
        <li class="breadcrumb-item active">New</li>
    </ol>
</nav>

<h1 class="h3 mb-4"><i class="fas fa-pause-circle me-2 text-warning"></i>New Semester Drop</h1>

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

                <!-- Semester type -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Semester Type <span class="text-danger">*</span></label>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <input type="radio" class="btn-check" name="semester_type" id="type_bi" value="bi"
                                   <?= old('semester_type', 'bi') === 'tri' ? '' : 'checked' ?>>
                            <label class="btn btn-outline-warning w-100 text-start p-3" for="type_bi">
                                <span class="fw-bold d-block">Bi-semester</span>
                                <small class="text-muted">Blocks <strong>6 months</strong></small>
                            </label>
                        </div>
                        <div class="col-sm-6">
                            <input type="radio" class="btn-check" name="semester_type" id="type_tri" value="tri"
                                   <?= old('semester_type') === 'tri' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-warning w-100 text-start p-3" for="type_tri">
                                <span class="fw-bold d-block">Tri-semester</span>
                                <small class="text-muted">Blocks <strong>4 months</strong></small>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Drop window -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Drop Start <span class="text-danger">*</span></label>
                        <input type="date" name="drop_start" id="drop_start" class="form-control"
                               value="<?= old('drop_start', date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Drop End <span class="text-muted">(auto)</span></label>
                        <input type="text" id="drop_end_preview" class="form-control" readonly
                               placeholder="Calculated from type & start">
                    </div>
                </div>

                <!-- Reason -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Reason <span class="text-muted">(optional)</span></label>
                    <textarea name="reason" class="form-control" rows="2"
                              placeholder="Why is the student taking a break?"><?= old('reason') ?></textarea>
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
                        As a Super Administrator you may record a drop without evidence.
                        <?php else: ?>
                        A supporting document is required (image, PDF or Word, up to 20 MB).
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Save Semester Drop</button>
                <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-semibold"><i class="fas fa-info-circle me-1 text-warning"></i>How it works</h6>
                <ul class="small text-muted mb-0 ps-3">
                    <li>A <strong>Bi-semester</strong> drop blocks <strong>6 months</strong>.</li>
                    <li>A <strong>Tri-semester</strong> drop blocks <strong>4 months</strong>.</li>
                    <li>During the blocked window the student's monthly tuition is <strong>not counted as due</strong> – it shows as <em>Semester Drop</em> in Accounts, Collect Payment and the student profile.</li>
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
    var typeBi   = document.getElementById('type_bi');
    var typeTri  = document.getElementById('type_tri');
    var startEl  = document.getElementById('drop_start');
    var endEl    = document.getElementById('drop_end_preview');
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

    // ── Drop end preview ──────────────────────────────────────────────────
    function monthsForType() {
        return (typeTri && typeTri.checked) ? 4 : 6;
    }
    function updateEnd() {
        if (!startEl.value) { endEl.value = ''; return; }
        var parts = startEl.value.split('-');
        if (parts.length !== 3) { endEl.value = ''; return; }
        var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
        d.setUTCMonth(d.getUTCMonth() + monthsForType());
        d.setUTCDate(d.getUTCDate() - 1);
        var opts = { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' };
        endEl.value = d.toLocaleDateString('en-GB', opts) + '  (' + monthsForType() + ' months)';
    }
    [typeBi, typeTri, startEl].forEach(function (el) {
        if (el) el.addEventListener('change', updateEnd);
    });
    if (startEl) startEl.addEventListener('input', updateEnd);
    updateEnd();
}());
</script>
