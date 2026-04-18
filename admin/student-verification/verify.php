<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification', 'can_create');
require_once __DIR__ . '/../students/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'New Student Verification';
$user       = auth_user();

// ── Helper: upload verified PDF copy ─────────────────────────────────────────
function sv_upload_pdf(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > 20 * 1024 * 1024) return false;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'application/pdf') return false;

    $dir = UPLOAD_DIR . '/student-verification';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(12)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) return false;
    return $stored;
}

// ── Handle student search (AJAX or GET) ──────────────────────────────────────
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $q    = trim($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $stmt = db()->prepare(
            'SELECT s.id, s.student_id, s.full_name, d.name AS dept_name
             FROM students s
             JOIN dept_departments d ON d.id = s.dept_id
             WHERE (s.student_id LIKE ? OR s.full_name LIKE ?)
             ORDER BY s.student_id ASC
             LIMIT 10'
        );
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($rows);
    exit;
}

// ── Handle form submission ────────────────────────────────────────────────────
$errors  = [];
$success = false;
$new_id  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $s_id               = (int)($_POST['student_db_id'] ?? 0);
    $cert_ok            = (int)(($_POST['cert_transcript_ok'] ?? '') === 'yes');
    $cert_issues        = trim($_POST['cert_transcript_issues'] ?? '');
    $admission_ok       = (int)(($_POST['admission_form_ok'] ?? '') === 'yes');
    $admission_issues   = trim($_POST['admission_form_issues'] ?? '');
    $tabulation_ok      = (int)(($_POST['tabulation_ok'] ?? '') === 'yes');
    $tabulation_issues  = trim($_POST['tabulation_issues'] ?? '');
    $verifier_email     = trim($_POST['verifier_email'] ?? '');

    // Validate
    if ($s_id <= 0) {
        $errors[] = 'Please select a valid student.';
    }
    if (!in_array($_POST['cert_transcript_ok'] ?? '', ['yes', 'no'], true)) {
        $errors[] = 'Please answer the Certificate &amp; Transcript check.';
    }
    if (!$cert_ok && $cert_issues === '') {
        $errors[] = 'Please describe the issues found with the Certificate &amp; Transcript.';
    }
    if (!in_array($_POST['admission_form_ok'] ?? '', ['yes', 'no'], true)) {
        $errors[] = 'Please answer the Admission Form check.';
    }
    if (!$admission_ok && $admission_issues === '') {
        $errors[] = 'Please describe the issues found with the Admission Form.';
    }
    if (!in_array($_POST['tabulation_ok'] ?? '', ['yes', 'no'], true)) {
        $errors[] = 'Please answer the Final Result Tabulation check.';
    }
    if (!$tabulation_ok && $tabulation_issues === '') {
        $errors[] = 'Please describe the issues found with the Tabulation.';
    }
    if ($verifier_email !== '' && !filter_var($verifier_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'The verifier email address is not valid.';
    }

    if (empty($errors)) {
        // Fetch student to confirm existence
        $sstmt = db()->prepare('SELECT * FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
        $sstmt->execute([$s_id]);
        $student = $sstmt->fetch();
        if (!$student) {
            $errors[] = 'Student not found.';
        }
    }

    if (empty($errors)) {
        // Find referenced file IDs from student_files
        $adm_file_id = null;
        $tab_file_id = null;

        $f_stmt = db()->prepare(
            "SELECT id, file_name FROM student_files WHERE student_id = ? ORDER BY created_at DESC"
        );
        $f_stmt->execute([$s_id]);
        foreach ($f_stmt->fetchAll() as $f) {
            $fn = strtolower($f['file_name']);
            if ($adm_file_id === null && (str_contains($fn, 'admission') || str_contains($fn, 'admission form'))) {
                $adm_file_id = (int)$f['id'];
            }
            if ($tab_file_id === null && (str_contains($fn, 'tabulation') || str_contains($fn, 'final result'))) {
                $tab_file_id = (int)$f['id'];
            }
        }

        $overall = ($cert_ok && $admission_ok && $tabulation_ok) ? 'Verified' : 'Failed';

        db()->prepare(
            'INSERT INTO student_verifications
               (student_id, verified_by,
                cert_transcript_ok, cert_transcript_issues,
                admission_form_ok,  admission_form_issues,  admission_form_file_id,
                tabulation_ok,      tabulation_issues,      tabulation_file_id,
                overall_status, verifier_email)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $s_id, $user['id'],
            $cert_ok,       $cert_ok       ? null : $cert_issues,
            $admission_ok,  $admission_ok  ? null : $admission_issues, $adm_file_id,
            $tabulation_ok, $tabulation_ok ? null : $tabulation_issues, $tab_file_id,
            $overall, $verifier_email ?: null,
        ]);

        $new_id = (int)db()->lastInsertId();

        log_change('student-verification', 'CREATE', $new_id,
            $student['full_name'] . ' (' . $student['student_id'] . ')',
            null, null, $overall,
            'Verification performed by ' . $user['full_name']);

        redirect(APP_URL . '/student-verification/view.php?id=' . $new_id);
    }

    // Re-populate student if validation failed
    if ($s_id > 0) {
        $sstmt2 = db()->prepare('SELECT s.*, d.name AS dept_name FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
        $sstmt2->execute([$s_id]);
        $pre_student = $sstmt2->fetch() ?: null;
    }
}

$pre_student = $pre_student ?? null;

// If student_id passed via GET (coming from student view page), pre-load it
$get_sid = (int)($_GET['student_id'] ?? 0);
if ($get_sid > 0 && !$pre_student) {
    $gs = db()->prepare('SELECT s.*, d.name AS dept_name FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
    $gs->execute([$get_sid]);
    $pre_student = $gs->fetch() ?: null;
}

// Pre-load files for the pre-selected student
$pre_files = [];
if ($pre_student) {
    $pf = db()->prepare("SELECT id, file_name, original_name, mime_type, stored_name FROM student_files WHERE student_id = ? ORDER BY created_at DESC");
    $pf->execute([$pre_student['id']]);
    $pre_files = $pf->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/student-verification/index.php">Student Verification</a></li>
            <li class="breadcrumb-item active">New Verification</li>
        </ol>
    </nav>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong><i class="fas fa-exclamation-circle me-1"></i>Please fix the following:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
        <li><?= $e ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" id="verify-form">
<?= csrf_field() ?>
<input type="hidden" name="student_db_id" id="student_db_id" value="<?= $pre_student ? (int)$pre_student['id'] : '' ?>">

<!-- ══════════════════════════════════════════════════════════
     STEP 1 – Find Student
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4" id="step-1-card">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-primary" style="font-size:.9rem;width:28px;height:28px;line-height:28px;text-align:center;">1</span>
        <h6 class="mb-0 fw-semibold">Find Student</h6>
    </div>
    <div class="card-body px-4 py-4">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Search Student by ID or Name</label>
                <div class="position-relative">
                    <input type="text" id="student_search" class="form-control"
                           placeholder="Type student ID or name…"
                           autocomplete="off"
                           value="<?= $pre_student ? h($pre_student['student_id'] . ' – ' . $pre_student['full_name']) : '' ?>">
                    <div id="search_dropdown" class="list-group position-absolute w-100 shadow-sm" style="z-index:1050;display:none;top:100%;max-height:220px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>

        <!-- Selected student card -->
        <div id="student_info_box" class="mt-3" style="<?= $pre_student ? '' : 'display:none' ?>">
            <?php if ($pre_student): ?>
            <?php sv_render_student_card($pre_student, $pre_files); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STEP 2 – Check 1: Certificate & Transcript
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4 verification-step" id="step-2-card" <?= $pre_student ? '' : 'style="display:none"' ?>>
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="font-size:.9rem;width:28px;height:28px;line-height:28px;text-align:center;">2</span>
        <h6 class="mb-0 fw-semibold">Certificate &amp; Transcript – Visual Security Measures</h6>
    </div>
    <div class="card-body px-4 py-3">
        <p class="text-muted mb-3" style="font-size:.9rem;">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            Physically examine the student's certificate and transcript. Check for watermarks, embossed seals,
            university logo authenticity, serial number, hologram/security features, and signature.
        </p>
        <div class="mb-3">
            <label class="form-label fw-semibold">Did you find the Certificate &amp; Transcript visual security measures correct?</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="cert_transcript_ok"
                           id="cert_yes" value="yes"
                           <?= (($_POST['cert_transcript_ok'] ?? '') === 'yes') ? 'checked' : '' ?>
                           data-issues="cert_issues_box">
                    <label class="form-check-label text-success fw-semibold" for="cert_yes">
                        <i class="fas fa-check me-1"></i> Yes
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="cert_transcript_ok"
                           id="cert_no" value="no"
                           <?= (($_POST['cert_transcript_ok'] ?? '') === 'no') ? 'checked' : '' ?>
                           data-issues="cert_issues_box">
                    <label class="form-check-label text-danger fw-semibold" for="cert_no">
                        <i class="fas fa-times me-1"></i> No
                    </label>
                </div>
            </div>
        </div>
        <div id="cert_issues_box" class="<?= (($_POST['cert_transcript_ok'] ?? '') === 'no') ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold text-danger">Describe the issues found:</label>
            <textarea class="form-control" name="cert_transcript_issues" rows="3"
                      placeholder="Describe any discrepancies or missing security features…"><?= h($_POST['cert_transcript_issues'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STEP 3 – Check 2: Admission Form
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4 verification-step" id="step-3-card" <?= $pre_student ? '' : 'style="display:none"' ?>>
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="font-size:.9rem;width:28px;height:28px;line-height:28px;text-align:center;">3</span>
        <h6 class="mb-0 fw-semibold">Admission Form Check</h6>
    </div>
    <div class="card-body px-4 py-3">
        <p class="text-muted mb-3" style="font-size:.9rem;">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            The system will show the student's scanned Admission Form document (file titled <strong>"Admission forms and info"</strong>).
            Check that the scanned document matches the student's records.
        </p>

        <!-- Admission form file from student files -->
        <div id="admission_file_area" class="mb-3"></div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Does the Admission Form scanned document match the student's records?</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="admission_form_ok"
                           id="adm_yes" value="yes"
                           <?= (($_POST['admission_form_ok'] ?? '') === 'yes') ? 'checked' : '' ?>
                           data-issues="adm_issues_box">
                    <label class="form-check-label text-success fw-semibold" for="adm_yes">
                        <i class="fas fa-check me-1"></i> Yes
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="admission_form_ok"
                           id="adm_no" value="no"
                           <?= (($_POST['admission_form_ok'] ?? '') === 'no') ? 'checked' : '' ?>
                           data-issues="adm_issues_box">
                    <label class="form-check-label text-danger fw-semibold" for="adm_no">
                        <i class="fas fa-times me-1"></i> No
                    </label>
                </div>
            </div>
        </div>
        <div id="adm_issues_box" class="<?= (($_POST['admission_form_ok'] ?? '') === 'no') ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold text-danger">Describe the issues found:</label>
            <textarea class="form-control" name="admission_form_issues" rows="3"
                      placeholder="Describe any discrepancies found in the admission form…"><?= h($_POST['admission_form_issues'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STEP 4 – Check 3: Final Result Tabulation
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4 verification-step" id="step-4-card" <?= $pre_student ? '' : 'style="display:none"' ?>>
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="font-size:.9rem;width:28px;height:28px;line-height:28px;text-align:center;">4</span>
        <h6 class="mb-0 fw-semibold">Final Result Tabulation Check</h6>
    </div>
    <div class="card-body px-4 py-3">
        <p class="text-muted mb-3" style="font-size:.9rem;">
            <i class="fas fa-info-circle me-1 text-primary"></i>
            The system will show the <strong>"Final Result Tabulation"</strong> PDF from the student's file.
            Verify that the student's name and ID appear in the tabulation.
        </p>

        <!-- Tabulation file from student files -->
        <div id="tabulation_file_area" class="mb-3"></div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Was the student found in the Final Result Tabulation?</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="tabulation_ok"
                           id="tab_yes" value="yes"
                           <?= (($_POST['tabulation_ok'] ?? '') === 'yes') ? 'checked' : '' ?>
                           data-issues="tab_issues_box">
                    <label class="form-check-label text-success fw-semibold" for="tab_yes">
                        <i class="fas fa-check me-1"></i> Yes
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input check-radio" type="radio" name="tabulation_ok"
                           id="tab_no" value="no"
                           <?= (($_POST['tabulation_ok'] ?? '') === 'no') ? 'checked' : '' ?>
                           data-issues="tab_issues_box">
                    <label class="form-check-label text-danger fw-semibold" for="tab_no">
                        <i class="fas fa-times me-1"></i> No
                    </label>
                </div>
            </div>
        </div>
        <div id="tab_issues_box" class="<?= (($_POST['tabulation_ok'] ?? '') === 'no') ? '' : 'd-none' ?>">
            <label class="form-label fw-semibold text-danger">Reason not found / issues:</label>
            <textarea class="form-control" name="tabulation_issues" rows="3"
                      placeholder="Describe the reason the student was not found or any issues…"><?= h($_POST['tabulation_issues'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STEP 5 – Verifier Email & Submit
═══════════════════════════════════════════════════════════ -->
<div class="card mb-4 verification-step" id="step-5-card" <?= $pre_student ? '' : 'style="display:none"' ?>>
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-secondary" style="font-size:.9rem;width:28px;height:28px;line-height:28px;text-align:center;">5</span>
        <h6 class="mb-0 fw-semibold">Verifier Contact &amp; Submit</h6>
    </div>
    <div class="card-body px-4 py-3">
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Verifier Email Address <span class="text-muted fw-normal">(optional – for sending results)</span></label>
                <input type="email" name="verifier_email" class="form-control"
                       placeholder="verifier@example.com"
                       value="<?= h($_POST['verifier_email'] ?? '') ?>">
                <div class="form-text">If provided, verification results will be emailed from <strong>verification@primeuniversity.ac.bd</strong>.</div>
            </div>
        </div>
        <div class="mt-4 d-flex gap-3 flex-wrap">
            <button type="submit" class="btn btn-primary px-4" style="border-radius:9px;">
                <i class="fas fa-clipboard-check me-1"></i> Submit Verification
            </button>
            <a href="<?= APP_URL ?>/student-verification/index.php" class="btn btn-outline-secondary" style="border-radius:9px;">
                Cancel
            </a>
        </div>
    </div>
</div>

</form>

<?php
// Helper to render student info card
function sv_render_student_card(array $s, array $files = []): void
{
    // Find relevant files
    $adm_file = null;
    $tab_file = null;
    foreach ($files as $f) {
        $fn = strtolower($f['file_name']);
        if ($adm_file === null && (str_contains($fn, 'admission'))) {
            $adm_file = $f;
        }
        if ($tab_file === null && (str_contains($fn, 'tabulation') || str_contains($fn, 'final result'))) {
            $tab_file = $f;
        }
    }
    ?>
    <div class="p-3 rounded-3 border" style="background:#f8f9ff;">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <div class="fw-bold fs-6"><?= h($s['full_name']) ?></div>
                <div class="d-flex gap-2 mt-1">
                    <code class="bg-white px-2 py-1 rounded border" style="font-size:.85rem;"><?= h($s['student_id']) ?></code>
                    <span class="badge bg-primary bg-opacity-10 text-primary align-self-center"><?= h($s['dept_name']) ?></span>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary align-self-center"><?= h($s['admitted_semester'] ?? '') ?></span>
                </div>
            </div>
            <div class="ms-auto text-success fw-semibold" style="font-size:.85rem;">
                <i class="fas fa-check-circle me-1"></i>Student found
            </div>
        </div>
        <?php if ($adm_file || $tab_file): ?>
        <hr class="my-2">
        <div class="d-flex gap-3 flex-wrap" style="font-size:.82rem;">
            <?php if ($adm_file): ?>
            <a href="<?= APP_URL ?>/../admin/uploads/students/files/<?= h($adm_file['stored_name']) ?>"
               target="_blank" class="text-primary text-decoration-none">
                <i class="fas fa-file-alt me-1"></i><?= h($adm_file['file_name']) ?>
            </a>
            <?php else: ?>
            <span class="text-muted"><i class="fas fa-file-alt me-1"></i>Admission Form: <em>not uploaded</em></span>
            <?php endif; ?>
            <?php if ($tab_file): ?>
            <a href="<?= APP_URL ?>/../admin/uploads/students/files/<?= h($tab_file['stored_name']) ?>"
               target="_blank" class="text-danger text-decoration-none ms-3">
                <i class="fas fa-file-pdf me-1"></i><?= h($tab_file['file_name']) ?>
            </a>
            <?php else: ?>
            <span class="text-muted ms-3"><i class="fas fa-file-pdf me-1"></i>Final Result Tabulation: <em>not uploaded</em></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<script>
(function () {
    'use strict';

    const searchInput    = document.getElementById('student_search');
    const dropdown       = document.getElementById('search_dropdown');
    const hiddenId       = document.getElementById('student_db_id');
    const infoBox        = document.getElementById('student_info_box');
    const verifySteps    = document.querySelectorAll('.verification-step');

    let debounce;

    searchInput.addEventListener('input', function () {
        clearTimeout(debounce);
        const q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        debounce = setTimeout(() => {
            fetch('?ajax_search=1&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(rows => {
                    dropdown.innerHTML = '';
                    if (!rows.length) {
                        dropdown.innerHTML = '<div class="list-group-item text-muted" style="font-size:.85rem;">No students found</div>';
                    } else {
                        rows.forEach(s => {
                            const a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action';
                            a.style.fontSize = '.85rem';
                            a.innerHTML = `<strong>${s.student_id}</strong> – ${s.full_name} <small class="text-muted ms-1">${s.dept_name}</small>`;
                            a.addEventListener('click', () => selectStudent(s));
                            dropdown.appendChild(a);
                        });
                    }
                    dropdown.style.display = 'block';
                })
                .catch(() => {});
        }, 250);
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && e.target !== searchInput) {
            dropdown.style.display = 'none';
        }
    });

    function selectStudent(s) {
        searchInput.value = s.student_id + ' – ' + s.full_name;
        hiddenId.value    = s.id;
        dropdown.style.display = 'none';

        // Load student info card via AJAX
        fetch('?ajax_student_card=1&id=' + s.id)
            .then(r => r.text())
            .then(html => {
                infoBox.innerHTML = html;
                infoBox.style.display = 'block';
                showVerifySteps();
            })
            .catch(() => {});
    }

    function showVerifySteps() {
        verifySteps.forEach(el => el.style.display = '');
    }

    // Y/N radio toggle for issues textareas
    document.querySelectorAll('.check-radio').forEach(radio => {
        radio.addEventListener('change', function () {
            const boxId  = this.dataset.issues;
            const box    = document.getElementById(boxId);
            const isNo   = this.value === 'no';
            if (box) {
                box.classList.toggle('d-none', !isNo);
                if (isNo) box.querySelector('textarea')?.focus();
            }
        });
    });

    // Pre-populate admission/tabulation file links if student already selected
    <?php if ($pre_student && !empty($pre_files)): ?>
    (function() {
        const admArea = document.getElementById('admission_file_area');
        const tabArea = document.getElementById('tabulation_file_area');
        <?php
        $adm_f = null; $tab_f = null;
        foreach ($pre_files as $f) {
            $fn = strtolower($f['file_name']);
            if (!$adm_f && str_contains($fn, 'admission')) $adm_f = $f;
            if (!$tab_f && (str_contains($fn, 'tabulation') || str_contains($fn, 'final result'))) $tab_f = $f;
        }
        ?>
        <?php if ($adm_f): ?>
        admArea.innerHTML = `<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;">
            <i class="fas fa-file-alt me-1"></i>
            Admission Form on file:
            <a href="<?= UPLOAD_URL ?>/students/files/<?= h($adm_f['stored_name']) ?>" target="_blank" class="alert-link">
                <?= h($adm_f['file_name']) ?> (<?= h($adm_f['original_name']) ?>)
            </a>
            <span class="text-muted ms-2">&mdash; open in new tab to review</span>
        </div>`;
        <?php else: ?>
        admArea.innerHTML = '<div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>No Admission Form file found for this student. Please ask the student for the document.</div>';
        <?php endif; ?>
        <?php if ($tab_f): ?>
        tabArea.innerHTML = `<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;">
            <i class="fas fa-file-pdf me-1"></i>
            Final Result Tabulation on file:
            <a href="<?= UPLOAD_URL ?>/students/files/<?= h($tab_f['stored_name']) ?>" target="_blank" class="alert-link">
                <?= h($tab_f['file_name']) ?> (<?= h($tab_f['original_name']) ?>)
            </a>
            <span class="text-muted ms-2">&mdash; open in new tab to review</span>
        </div>`;
        <?php else: ?>
        tabArea.innerHTML = '<div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>No Final Result Tabulation file found for this student. Please ask the student for the document.</div>';
        <?php endif; ?>
    })();
    <?php endif; ?>
})();
</script>

<?php
// AJAX: return student info card HTML
if (isset($_GET['ajax_student_card'])) {
    $sid  = (int)($_GET['id'] ?? 0);
    $ss   = db()->prepare('SELECT s.*, d.name AS dept_name FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
    $ss->execute([$sid]);
    $sc = $ss->fetch();
    $sf = [];
    if ($sc) {
        $sfst = db()->prepare("SELECT id, file_name, original_name, mime_type, stored_name FROM student_files WHERE student_id = ? ORDER BY created_at DESC");
        $sfst->execute([$sid]);
        $sf = $sfst->fetchAll();
    }
    if ($sc) {
        ob_start();
        sv_render_student_card($sc, $sf);
        $card_html = ob_get_clean();

        // Also build JS to populate file areas
        $adm_f = null; $tab_f = null;
        foreach ($sf as $f) {
            $fn = strtolower($f['file_name']);
            if (!$adm_f && str_contains($fn, 'admission')) $adm_f = $f;
            if (!$tab_f && (str_contains($fn, 'tabulation') || str_contains($fn, 'final result'))) $tab_f = $f;
        }

        $adm_html = $adm_f
            ? '<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-file-alt me-1"></i>Admission Form on file: <a href="' . UPLOAD_URL . '/students/files/' . h($adm_f['stored_name']) . '" target="_blank" class="alert-link">' . h($adm_f['file_name']) . ' (' . h($adm_f['original_name']) . ')</a> <span class="text-muted ms-2">&mdash; open in new tab to review</span></div>'
            : '<div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>No Admission Form file found for this student.</div>';

        $tab_html = $tab_f
            ? '<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-file-pdf me-1"></i>Final Result Tabulation on file: <a href="' . UPLOAD_URL . '/students/files/' . h($tab_f['stored_name']) . '" target="_blank" class="alert-link">' . h($tab_f['file_name']) . ' (' . h($tab_f['original_name']) . ')</a> <span class="text-muted ms-2">&mdash; open in new tab to review</span></div>'
            : '<div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.85rem;"><i class="fas fa-exclamation-triangle me-1"></i>No Final Result Tabulation file found for this student.</div>';

        echo $card_html . '<script>
document.getElementById("admission_file_area").innerHTML = ' . json_encode($adm_html) . ';
document.getElementById("tabulation_file_area").innerHTML = ' . json_encode($tab_html) . ';
</script>';
    }
    exit;
}
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
