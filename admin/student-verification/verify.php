<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification', 'can_create');
require_once __DIR__ . '/../students/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'New Student Verification';
$user       = auth_user();

// ── Helper: match admission / tabulation files ─────────────────────────────
function sv_find_files(array $files): array
{
    $adm = null;
    $tab = null;
    $adm_kw = ['admission form', 'admission info', 'admisison', 'scanned file', 'scanned', 'admission'];
    $tab_kw = ['final result tabulation', 'tabulation', 'final result'];
    foreach ($files as $f) {
        $fn = strtolower($f['file_name'] ?? '');
        if ($adm === null) {
            foreach ($adm_kw as $kw) {
                if (str_contains($fn, $kw)) { $adm = $f; break; }
            }
        }
        if ($tab === null) {
            foreach ($tab_kw as $kw) {
                if (str_contains($fn, $kw)) { $tab = $f; break; }
            }
        }
    }
    return [$adm, $tab];
}

// ── Helper: file viewer ────────────────────────────────────────────────────
function sv_file_viewer_html(?array $file, string $label): string
{
    if (!$file) {
        return '<div class="alert alert-secondary py-2 px-3 mb-0" style="font-size:.85rem;">'
             . '<i class="fas fa-folder-open me-1"></i><strong>' . h($label) . ':</strong> '
             . 'No file available for this student.</div>';
    }
    $url  = UPLOAD_URL . '/students/files/' . rawurlencode($file['stored_name']);
    $mime = $file['mime_type'] ?? '';
    $name = $file['file_name'] ?? '';
    $orig = $file['original_name'] ?? '';
    $dn   = h($name) . ($orig && $orig !== $name ? ' (' . h($orig) . ')' : '');
    $hdr  = '<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">'
          . '<i class="fas fa-file-pdf text-danger"></i>'
          . '<strong style="font-size:.9rem;">' . h($label) . '</strong>'
          . '<code class="text-muted" style="font-size:.75rem;">' . $dn . '</code>'
          . '<a href="' . h($url) . '" target="_blank" class="btn btn-sm btn-outline-primary ms-auto" style="border-radius:6px;font-size:.78rem;">'
          . '<i class="fas fa-external-link-alt me-1"></i>Open in New Tab</a>'
          . '</div>';
    if ($mime === 'application/pdf') {
        $body = '<iframe src="' . h($url) . '" style="width:100%;height:500px;border:1px solid #dee2e6;border-radius:8px;" title="' . h($name) . '"></iframe>';
    } elseif (str_starts_with($mime, 'image/')) {
        $body = '<div class="text-center border rounded p-2 bg-white">'
              . '<img src="' . h($url) . '" class="img-fluid rounded" style="max-height:460px;" alt="' . h($name) . '">'
              . '</div>';
    } else {
        $body = '<div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;">'
              . '<i class="fas fa-file me-1"></i>Use the <strong>Open in New Tab</strong> button above to view this file.</div>';
    }
    return '<div class="p-3 bg-white border rounded-3 shadow-sm">' . $hdr . $body . '</div>';
}

// ── Photo URL helper ───────────────────────────────────────────────────────
function sv_photo_url(?string $photo): string
{
    if (!$photo) return '';
    if (!preg_match('/\A[A-Za-z0-9_\-]+\.[a-z]{2,5}\z/', $photo)) return '';
    $p = UPLOAD_DIR . '/students/photos/' . $photo;
    return is_file($p)
        ? UPLOAD_URL . '/students/photos/' . rawurlencode($photo)
        : SITE_URL   . '/upload_spic/'     . rawurlencode($photo);
}

// ── Priority select helper (for IT ticket panels) ──────────────────────────
function sv_prio_select(string $id): string
{
    return '<select id="' . h($id) . '" class="form-select form-select-sm">'
         . '<option value="Medium">Medium</option>'
         . '<option value="High" selected>High</option>'
         . '<option value="Critical">Critical</option>'
         . '<option value="Low">Low</option>'
         . '</select>';
}

// ── AJAX: Create IT Support Ticket ────────────────────────────────────────
if (isset($_POST['ajax_create_ticket'])) {
    header('Content-Type: application/json');
    csrf_check();
    require_once __DIR__ . '/../support-tickets/helpers.php';
    $title = trim($_POST['tc_title'] ?? '');
    $desc  = trim($_POST['tc_desc']  ?? '');
    $prio  = in_array($_POST['tc_priority'] ?? '', ['Low','Medium','High','Critical'], true)
             ? $_POST['tc_priority'] : 'High';
    if ($title === '' || $desc === '') {
        echo json_encode(['ok' => false, 'error' => 'Title and description are required.']);
        exit;
    }
    $tn  = st_generate_ticket_number();
    $ddl = st_compute_deadline($prio);
    db()->prepare(
        "INSERT INTO support_tickets
           (ticket_number, title, description, category, priority, status, deadline, created_by)
         VALUES (?,?,?,'Other',?,'Open',?,?)"
    )->execute([$tn, $title, $desc, $prio, $ddl, $user['id']]);
    $tid = (int)db()->lastInsertId();
    log_change('support-tickets', 'CREATE', $tid, $title, null, null, null,
        'IT ticket created from student verification by ' . $user['full_name']);
    echo json_encode(['ok' => true, 'ticket_number' => $tn, 'id' => $tid]);
    exit;
}

// ── AJAX: Student search ───────────────────────────────────────────────────
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $st = db()->prepare(
            'SELECT s.id, s.student_id, s.full_name, d.name AS dept_name
             FROM students s JOIN dept_departments d ON d.id = s.dept_id
             WHERE (s.student_id LIKE ? OR s.full_name LIKE ?)
             ORDER BY s.student_id ASC LIMIT 10'
        );
        $st->execute([$like, $like]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($rows);
    exit;
}

// ── AJAX: Rich student card + file HTML ────────────────────────────────────
if (isset($_GET['ajax_student_card'])) {
    header('Content-Type: application/json');
    $sid = (int)($_GET['id'] ?? 0);
    if ($sid <= 0) { echo json_encode(['ok' => false]); exit; }
    $ss = db()->prepare(
        'SELECT s.*, d.name AS dept_name, d.code AS dept_code, p.program_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         WHERE s.id = ?'
    );
    $ss->execute([$sid]);
    $sc = $ss->fetch();
    if (!$sc) { echo json_encode(['ok' => false]); exit; }

    $sfst = db()->prepare(
        "SELECT id, file_name, original_name, mime_type, stored_name
         FROM student_files WHERE student_id = ? ORDER BY created_at DESC"
    );
    $sfst->execute([$sid]);
    $sf = $sfst->fetchAll();
    [$adm_f, $tab_f] = sv_find_files($sf);

    $cgpa = null;
    try {
        $cq = db()->prepare(
            'SELECT ROUND(SUM(rg.grade_point * COALESCE(rs.credits,3)) /
                 NULLIF(SUM(COALESCE(rs.credits,3)),0), 2) AS cgpa
             FROM result_grades rg
             JOIN result_exams re ON re.id = rg.exam_id
             JOIN result_subjects rs ON rs.id = rg.subject_id
             WHERE rg.student_sid=? AND re.is_published=1
               AND rg.grade_point IS NOT NULL AND COALESCE(rs.credits,3)>0'
        );
        $cq->execute([$sc['student_id']]);
        $cv = $cq->fetchColumn();
        if ($cv !== null && $cv !== false) $cgpa = number_format((float)$cv, 2);
    } catch (Throwable $e) {}
    if ($cgpa === null) {
        try {
            $sr = db()->prepare(
                'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) FROM student_results
                 WHERE student_id=? AND cgpa IS NOT NULL AND TRIM(cgpa)!=""'
            );
            $sr->execute([$sid]);
            $sv2 = $sr->fetchColumn();
            if ($sv2 !== null && (float)$sv2 > 0) $cgpa = number_format((float)$sv2, 2);
        } catch (Throwable $e) {}
    }
    echo json_encode([
        'ok'      => true,
        'student' => [
            'id'                => (int)$sc['id'],
            'student_id'        => $sc['student_id'],
            'full_name'         => $sc['full_name'],
            'dept_name'         => $sc['dept_name'],
            'dept_code'         => $sc['dept_code'] ?? '',
            'program_name'      => $sc['program_name'] ?? '',
            'admitted_semester' => $sc['admitted_semester'] ?? '',
            'batch'             => $sc['batch'] ?? '',
            'status'            => $sc['status'] ?? '',
            'email'             => $sc['email'] ?? '',
            'phone'             => $sc['phone'] ?? '',
            'photo_url'         => sv_photo_url($sc['photo'] ?? null),
            'cgpa'              => $cgpa,
        ],
        'adm_html' => sv_file_viewer_html($adm_f, 'Admission Form'),
        'tab_html' => sv_file_viewer_html($tab_f, 'Final Result Tabulation'),
    ]);
    exit;
}

// ── Form POST handler ──────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_create_ticket'])) {
    csrf_check();
    $s_id    = (int)($_POST['student_db_id']         ?? 0);
    $sdo     = (int)(($_POST['student_data_ok']      ?? '') === 'yes');
    $sdoiss  = trim($_POST['student_data_issues']     ?? '');
    $cok     = (int)(($_POST['cert_transcript_ok']   ?? '') === 'yes');
    $ciss    = trim($_POST['cert_transcript_issues']  ?? '');
    $aok     = (int)(($_POST['admission_form_ok']    ?? '') === 'yes');
    $aiss    = trim($_POST['admission_form_issues']   ?? '');
    $tok     = (int)(($_POST['tabulation_ok']        ?? '') === 'yes');
    $tiss    = trim($_POST['tabulation_issues']       ?? '');
    $vemail  = trim($_POST['verifier_email']          ?? '');

    if ($s_id <= 0)
        $errors[] = 'Please select a valid student.';
    if (!in_array($_POST['student_data_ok'] ?? '', ['yes','no'], true))
        $errors[] = 'Please confirm the student data check in Step 1.';
    if (!$sdo && $sdoiss === '')
        $errors[] = 'Please describe the student data mismatch (Step 1).';
    if (!in_array($_POST['cert_transcript_ok'] ?? '', ['yes','no'], true))
        $errors[] = 'Please answer the Certificate &amp; Transcript check (Step 2).';
    if (!$cok && $ciss === '')
        $errors[] = 'Please describe Certificate &amp; Transcript issues (Step 2).';
    if (!in_array($_POST['admission_form_ok'] ?? '', ['yes','no'], true))
        $errors[] = 'Please answer the Admission Form check (Step 3).';
    if (!$aok && $aiss === '')
        $errors[] = 'Please describe Admission Form issues (Step 3).';
    if (!in_array($_POST['tabulation_ok'] ?? '', ['yes','no'], true))
        $errors[] = 'Please answer the Final Result Tabulation check (Step 4).';
    if (!$tok && $tiss === '')
        $errors[] = 'Please describe Tabulation issues (Step 4).';
    if ($vemail !== '' && !filter_var($vemail, FILTER_VALIDATE_EMAIL))
        $errors[] = 'The verifier email address is not valid.';

    if (empty($errors)) {
        $qs = db()->prepare('SELECT * FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
        $qs->execute([$s_id]);
        $student = $qs->fetch();
        if (!$student) $errors[] = 'Student not found.';
    }
    if (empty($errors)) {
        $qf = db()->prepare("SELECT id, file_name FROM student_files WHERE student_id=? ORDER BY created_at DESC");
        $qf->execute([$s_id]);
        [$adm_f2, $tab_f2] = sv_find_files($qf->fetchAll());
        $adm_fid = $adm_f2 ? (int)$adm_f2['id'] : null;
        $tab_fid = $tab_f2 ? (int)$tab_f2['id'] : null;
        $overall = ($sdo && $cok && $aok && $tok) ? 'Verified' : 'Failed';

        $has_sdo = (bool)db()->query("SHOW COLUMNS FROM student_verifications LIKE 'student_data_ok'")->fetchColumn();
        if ($has_sdo) {
            db()->prepare(
                'INSERT INTO student_verifications
                   (student_id, verified_by,
                    student_data_ok, student_data_issues,
                    cert_transcript_ok, cert_transcript_issues,
                    admission_form_ok, admission_form_issues, admission_form_file_id,
                    tabulation_ok, tabulation_issues, tabulation_file_id,
                    overall_status, verifier_email)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $s_id, $user['id'],
                $sdo,  $sdo  ? null : $sdoiss,
                $cok,  $cok  ? null : $ciss,
                $aok,  $aok  ? null : $aiss, $adm_fid,
                $tok,  $tok  ? null : $tiss,  $tab_fid,
                $overall, $vemail ?: null,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO student_verifications
                   (student_id, verified_by,
                    cert_transcript_ok, cert_transcript_issues,
                    admission_form_ok, admission_form_issues, admission_form_file_id,
                    tabulation_ok, tabulation_issues, tabulation_file_id,
                    overall_status, verifier_email)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $s_id, $user['id'],
                $cok, $cok ? null : $ciss,
                $aok, $aok ? null : $aiss, $adm_fid,
                $tok, $tok ? null : $tiss,  $tab_fid,
                $overall, $vemail ?: null,
            ]);
        }
        $new_id = (int)db()->lastInsertId();
        log_change('student-verification', 'CREATE', $new_id,
            $student['full_name'] . ' (' . $student['student_id'] . ')',
            null, null, $overall,
            'Verification performed by ' . $user['full_name']);
        redirect(APP_URL . '/student-verification/view.php?id=' . $new_id);
    }
    if ($s_id > 0) {
        $qs2 = db()->prepare('SELECT s.*, d.name AS dept_name FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
        $qs2->execute([$s_id]);
        $pre_student = $qs2->fetch() ?: null;
    }
}

$pre_student = $pre_student ?? null;
$get_sid = (int)($_GET['student_id'] ?? 0);
if ($get_sid > 0 && !$pre_student) {
    $gs = db()->prepare('SELECT s.*, d.name AS dept_name FROM students s JOIN dept_departments d ON d.id=s.dept_id WHERE s.id=?');
    $gs->execute([$get_sid]);
    $pre_student = $gs->fetch() ?: null;
}
$pre_files = [];
if ($pre_student) {
    $pf = db()->prepare("SELECT id, file_name, original_name, mime_type, stored_name FROM student_files WHERE student_id=? ORDER BY created_at DESC");
    $pf->execute([$pre_student['id']]);
    $pre_files = $pf->fetchAll();
}
$has_pre = (bool)$pre_student;
[$pre_adm_f, $pre_tab_f] = $has_pre ? sv_find_files($pre_files) : [null, null];

// Pre-calculate CGPA for pre-loaded student (matches AJAX endpoint logic)
$pre_cgpa = null;
if ($has_pre) {
    try {
        $cq = db()->prepare(
            'SELECT ROUND(SUM(rg.grade_point * COALESCE(rs.credits,3)) /
                 NULLIF(SUM(COALESCE(rs.credits,3)),0), 2) AS cgpa
             FROM result_grades rg
             JOIN result_exams re ON re.id = rg.exam_id
             JOIN result_subjects rs ON rs.id = rg.subject_id
             WHERE rg.student_sid=? AND re.is_published=1
               AND rg.grade_point IS NOT NULL AND COALESCE(rs.credits,3)>0'
        );
        $cq->execute([$pre_student['student_id']]);
        $cv = $cq->fetchColumn();
        if ($cv !== null && $cv !== false) $pre_cgpa = number_format((float)$cv, 2);
    } catch (Throwable $e) {}
    if ($pre_cgpa === null) {
        try {
            $sr = db()->prepare(
                'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) FROM student_results
                 WHERE student_id=? AND cgpa IS NOT NULL AND TRIM(cgpa)!=""'
            );
            $sr->execute([$pre_student['id']]);
            $sv2 = $sr->fetchColumn();
            if ($sv2 !== null && (float)$sv2 > 0) $pre_cgpa = number_format((float)$sv2, 2);
        } catch (Throwable $e) {}
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.sv-steps{display:flex;align-items:flex-start;justify-content:center;margin-bottom:2rem;}
.sv-step{display:flex;flex-direction:column;align-items:center;flex:1;max-width:160px;position:relative;}
.sv-step:not(:last-child)::after{content:'';position:absolute;top:16px;left:50%;width:100%;height:2px;background:#dee2e6;z-index:0;}
.sv-step.completed:not(:last-child)::after{background:#198754;}
.sv-dot{width:32px;height:32px;border-radius:50%;background:#e9ecef;color:#6c757d;font-weight:700;font-size:.82rem;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;border:2px solid #dee2e6;transition:all .25s;}
.sv-step.active .sv-dot{background:#0d6efd;color:#fff;border-color:#0d6efd;box-shadow:0 0 0 4px rgba(13,110,253,.18);}
.sv-step.completed .sv-dot{background:#198754;color:#fff;border-color:#198754;}
.sv-label{font-size:.7rem;color:#6c757d;text-align:center;margin-top:5px;font-weight:500;line-height:1.3;max-width:78px;}
.sv-step.active .sv-label{color:#0d6efd;font-weight:700;}
.sv-step.completed .sv-label{color:#198754;}
.sv-card{display:none;}
.sv-card.sv-on{display:block;}
.sv-dec{display:flex;flex-direction:column;align-items:center;gap:6px;padding:18px 14px;border-radius:12px;border:2.5px solid #dee2e6;background:#fff;cursor:pointer;transition:all .2s;text-align:center;min-width:160px;flex:1;max-width:240px;}
.sv-dec:hover{box-shadow:0 4px 18px rgba(0,0,0,.1);transform:translateY(-2px);}
.sv-dec.ok{border-color:#198754;background:#d1e7dd;box-shadow:0 0 0 3px rgba(25,135,84,.15);}
.sv-dec.fail{border-color:#dc3545;background:#f8d7da;box-shadow:0 0 0 3px rgba(220,53,69,.15);}
.sv-scard{background:linear-gradient(135deg,#f0f8ff,#e8f5e9);border:2px solid #c3e6cb;border-radius:14px;padding:16px 20px;}
.sv-ph{width:86px;height:106px;object-fit:cover;border-radius:8px;border:2px solid #ced4da;flex-shrink:0;}
.sv-ph-ph{width:86px;height:106px;border-radius:8px;border:2px solid #ced4da;background:#e9ecef;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#adb5bd;font-size:2.2rem;}
.sv-tcp{background:#fff8e1;border:1.5px solid #ffc107;border-radius:10px;padding:14px 16px;}
.sv-cert{background:#fff;border:2px solid #dee2e6;border-radius:14px;overflow:hidden;box-shadow:0 6px 32px rgba(0,0,0,.1);}
.sv-band{height:7px;background:linear-gradient(90deg,#1a2e5a,#2563eb 50%,#10b981);}
.sv-chd{background:#1a2e5a;padding:16px 26px;display:flex;align-items:center;gap:14px;}
.sv-chd-t h6{margin:0 0 3px;font-size:1rem;font-weight:800;color:#fff;}
.sv-chd-t p{margin:0;font-size:.77rem;opacity:.7;color:#fff;}
.sv-cb{padding:20px 26px 24px;}
.sv-chk{display:flex;align-items:flex-start;gap:10px;padding:9px 12px;border-radius:7px;background:#f8f9fa;border:1.5px solid #dee2e6;margin-bottom:7px;}
.sv-chk.ok{background:#d1e7dd;border-color:#a3cfbb;}
.sv-chk.fail{background:#f8d7da;border-color:#f1aeb5;}
@media print{body>*{display:none!important;}#sv-pa{display:block!important;}}
#sv-pa{display:none;}
</style>

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
<div class="alert alert-danger mb-4">
    <strong><i class="fas fa-exclamation-circle me-1"></i>Please fix the following:</strong>
    <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="sv-steps">
    <div class="sv-step active" data-s="1"><div class="sv-dot">1</div><div class="sv-label">Find Student</div></div>
    <div class="sv-step" data-s="2"><div class="sv-dot">2</div><div class="sv-label">Visual Security</div></div>
    <div class="sv-step" data-s="3"><div class="sv-dot">3</div><div class="sv-label">Admission Form</div></div>
    <div class="sv-step" data-s="4"><div class="sv-dot">4</div><div class="sv-label">Tabulation</div></div>
    <div class="sv-step" data-s="5"><div class="sv-dot">5</div><div class="sv-label">Final Review</div></div>
</div>

<form method="POST" id="sv-form">
<?= csrf_field() ?>
<input type="hidden" name="student_db_id"         id="fld-sid">
<input type="hidden" name="student_data_ok"        id="fld-sdo"  value="<?= h($_POST['student_data_ok']        ?? '') ?>">
<input type="hidden" name="student_data_issues"    id="fld-sdiss" value="<?= h($_POST['student_data_issues']   ?? '') ?>">
<input type="hidden" name="cert_transcript_ok"     id="fld-cok"  value="<?= h($_POST['cert_transcript_ok']    ?? '') ?>">
<input type="hidden" name="cert_transcript_issues" id="fld-ciss" value="<?= h($_POST['cert_transcript_issues'] ?? '') ?>">
<input type="hidden" name="admission_form_ok"      id="fld-aok"  value="<?= h($_POST['admission_form_ok']     ?? '') ?>">
<input type="hidden" name="admission_form_issues"  id="fld-aiss" value="<?= h($_POST['admission_form_issues']  ?? '') ?>">
<input type="hidden" name="tabulation_ok"          id="fld-tok"  value="<?= h($_POST['tabulation_ok']         ?? '') ?>">
<input type="hidden" name="tabulation_issues"      id="fld-tiss" value="<?= h($_POST['tabulation_issues']      ?? '') ?>">

<!-- ===== STEP 1 – Find Student ============================================ -->
<div class="sv-card sv-on" data-c="1">
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-primary" style="width:28px;height:28px;line-height:28px;text-align:center;">1</span>
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Find Student</h6>
    </div>
    <div class="card-body px-4 py-4">
        <label class="form-label fw-semibold">Search by Student ID or Name</label>
        <p class="text-muted mb-3" style="font-size:.85rem;"><i class="fas fa-info-circle me-1 text-primary"></i>Type the student's ID number or full name to locate their record.</p>
        <div class="row g-2">
            <div class="col-12 col-md-7 col-lg-5">
                <div class="position-relative">
                    <span class="position-absolute" style="left:11px;top:50%;transform:translateY(-50%);color:#6c757d;pointer-events:none;"><i class="fas fa-search"></i></span>
                    <input type="text" id="sv-srch" class="form-control ps-5" placeholder="Enter Student ID or name…"
                           autocomplete="off" style="font-size:1rem;"
                           value="<?= $has_pre ? h($pre_student['student_id'].' – '.$pre_student['full_name']) : '' ?>">
                    <div id="sv-drop" class="list-group position-absolute w-100 shadow" style="z-index:1050;display:none;top:100%;max-height:230px;overflow-y:auto;"></div>
                </div>
                <div class="form-text">Type at least 2 characters.</div>
            </div>
        </div>

        <div id="sv-sbox" class="mt-4" style="<?= $has_pre?'':'display:none' ?>">
        <?php if ($has_pre):
            $pp = sv_photo_url($pre_student['photo'] ?? null); ?>
        <div class="sv-scard">
            <div class="d-flex gap-3 flex-wrap align-items-start">
                <?php if ($pp): ?><img src="<?= h($pp) ?>" class="sv-ph" alt="<?= h($pre_student['full_name']) ?>"><?php
                else: ?><div class="sv-ph-ph"><i class="fas fa-user-graduate"></i></div><?php endif; ?>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                        <h5 class="mb-0 fw-bold"><?= h($pre_student['full_name']) ?></h5>
                        <span class="badge <?= ($pre_student['status']??'')==='Graduated'?'bg-success':'bg-primary' ?>"><?= h($pre_student['status']??'Active') ?></span>
                    </div>
                    <code class="bg-white px-2 py-1 rounded border mb-2 d-inline-block" style="font-size:.88rem;"><?= h($pre_student['student_id']) ?></code>
                    <table class="table table-sm mb-0 mt-1" style="font-size:.84rem;max-width:380px;">
                        <tr><th class="text-muted fw-normal" style="width:38%;">Department</th><td class="fw-medium"><?= h($pre_student['dept_name']) ?></td></tr>
                        <?php if (!empty($pre_student['program_name'])): ?><tr><th class="text-muted fw-normal">Program</th><td><?= h($pre_student['program_name']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($pre_student['admitted_semester'])): ?><tr><th class="text-muted fw-normal">Admitted</th><td><?= h($pre_student['admitted_semester']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($pre_student['batch'])): ?><tr><th class="text-muted fw-normal">Batch</th><td><?= h($pre_student['batch']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($pre_student['email'])): ?><tr><th class="text-muted fw-normal">Email</th><td><?= h($pre_student['email']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($pre_student['phone'])): ?><tr><th class="text-muted fw-normal">Phone</th><td><?= h($pre_student['phone']) ?></td></tr><?php endif; ?>
                    </table>
                </div>
                <span class="badge bg-success p-2 align-self-start"><i class="fas fa-check-circle me-1"></i>Found</span>
            </div>
        </div>
        <?php endif; ?>
        </div>

        <div id="sv-dec1" class="mt-4" style="<?= $has_pre?'':'display:none' ?>">
            <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.875rem;">
                <i class="fas fa-info-circle me-1"></i>
                Do the student details above match the physical documents presented to you?
            </div>
            <div class="d-flex gap-3 flex-wrap">
                <div class="sv-dec" id="d1-ok" tabindex="0" role="button">
                    <i class="fas fa-check-circle text-success" style="font-size:1.7rem;"></i>
                    <strong class="text-success">I Found Details Correct</strong>
                    <small class="text-muted">Information matches presented documents</small>
                </div>
                <div class="sv-dec" id="d1-mm" tabindex="0" role="button">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size:1.7rem;"></i>
                    <strong class="text-danger">Student Data Mismatch</strong>
                    <small class="text-muted">Information does NOT match documents</small>
                </div>
            </div>
            <div id="sv-mm" class="d-none mt-3">
                <div class="alert alert-danger p-3">
                    <label class="fw-semibold text-danger mb-2 d-block"><i class="fas fa-exclamation-triangle me-1"></i>Describe the mismatch:</label>
                    <textarea id="sv-mm-txt" class="form-control border-danger" rows="3" placeholder="e.g. Name spelling differs, photo mismatch…"></textarea>
                </div>
                <div class="sv-tcp mb-3">
                    <div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-ticket-alt text-warning"></i><strong style="font-size:.87rem;">Report to IT Support</strong><span class="text-muted ms-1" style="font-size:.77rem;">(optional)</span></div>
                    <p class="text-muted mb-2" style="font-size:.8rem;">Create an IT ticket to flag this data mismatch for investigation.</p>
                    <div class="row g-2">
                        <div class="col-12 col-md-8"><input type="text" id="tc1-t" class="form-control form-control-sm" placeholder="Ticket title…"></div>
                        <div class="col-6 col-md-2"><?= sv_prio_select('tc1-p') ?></div>
                        <div class="col-6 col-md-2"><button type="button" class="btn btn-warning btn-sm w-100 sv-tc" data-step="1" style="border-radius:6px;"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
                    </div>
                    <div id="tc1-r" class="d-none mt-2"></div>
                </div>
            </div>
            <div id="d1-cont" class="mt-3" style="display:none;">
                <button type="button" class="btn btn-primary px-4 sv-nxt" data-to="2" style="border-radius:9px;"><i class="fas fa-arrow-right me-1"></i>Continue to Visual Security Check</button>
            </div>
        </div>
    </div>
</div>
</div><!-- /step 1 -->

<!-- ===== STEP 2 – Visual Security Measures ================================ -->
<div class="sv-card" data-c="2">
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="width:28px;height:28px;line-height:28px;text-align:center;">2</span>
        <h6 class="mb-0 fw-semibold"><i class="fas fa-certificate me-2 text-warning"></i>Certificate &amp; Transcript – Visual Security Measures</h6>
    </div>
    <div class="card-body px-4 py-4">
        <div class="alert alert-light border mb-4" style="font-size:.875rem;">
            <i class="fas fa-info-circle me-1 text-primary"></i>Physically examine the presented certificate and transcript. Check for:
            <ul class="mb-0 mt-1"><li>Watermarks &amp; embossed university seal</li><li>Security hologram or special print features</li><li>Authorized signatures and serial number</li><li>University logo authenticity and paper quality</li></ul>
        </div>
        <label class="form-label fw-semibold">Did you find the visual security measures <strong>correct</strong>?</label>
        <div class="d-flex gap-3 flex-wrap mt-3 mb-3">
            <div class="sv-dec" id="c-yes" tabindex="0" role="button"><i class="fas fa-check-circle text-success" style="font-size:1.6rem;"></i><strong class="text-success">Yes — All Correct</strong><small class="text-muted">Security features verified</small></div>
            <div class="sv-dec" id="c-no" tabindex="0" role="button"><i class="fas fa-times-circle text-danger" style="font-size:1.6rem;"></i><strong class="text-danger">No — Issues Found</strong><small class="text-muted">Security features missing/incorrect</small></div>
        </div>
        <div id="c-pan" class="d-none">
            <div class="alert alert-danger p-3"><label class="fw-semibold text-danger mb-2 d-block"><i class="fas fa-exclamation-triangle me-1"></i>Describe the issues:</label><textarea id="c-txt" class="form-control border-danger" rows="3" placeholder="Describe any missing or incorrect security features…"></textarea></div>
            <div class="sv-tcp mb-3">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-ticket-alt text-warning"></i><strong style="font-size:.87rem;">Report to IT Support</strong><span class="text-muted ms-1" style="font-size:.77rem;">(optional)</span></div>
                <div class="row g-2">
                    <div class="col-12 col-md-8"><input type="text" id="tc2-t" class="form-control form-control-sm" placeholder="Ticket title…"></div>
                    <div class="col-6 col-md-2"><?= sv_prio_select('tc2-p') ?></div>
                    <div class="col-6 col-md-2"><button type="button" class="btn btn-warning btn-sm w-100 sv-tc" data-step="2" style="border-radius:6px;"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
                </div>
                <div id="tc2-r" class="d-none mt-2"></div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary sv-bk" data-to="1" style="border-radius:9px;"><i class="fas fa-arrow-left me-1"></i>Back</button>
            <button type="button" class="btn btn-primary sv-nxt d-none" id="c-cont" data-to="3" style="border-radius:9px;"><i class="fas fa-arrow-right me-1"></i>Continue to Admission Form</button>
        </div>
    </div>
</div>
</div><!-- /step 2 -->

<!-- ===== STEP 3 – Admission Form ========================================== -->
<div class="sv-card" data-c="3">
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="width:28px;height:28px;line-height:28px;text-align:center;">3</span>
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-alt me-2 text-primary"></i>Admission Form</h6>
    </div>
    <div class="card-body px-4 py-4">
        <p class="text-muted mb-4" style="font-size:.875rem;"><i class="fas fa-info-circle me-1 text-primary"></i>Review the student's scanned <strong>Admission Form</strong>. Confirm that the name, photo, program, and other details match the physical documents.</p>
        <div class="mb-4">
            <div class="fw-semibold mb-2" style="font-size:.87rem;color:#495057;"><i class="fas fa-eye me-1 text-primary"></i> Admission Form Preview</div>
            <div id="sv-af"><?php if ($has_pre): echo sv_file_viewer_html($pre_adm_f, 'Admission Form'); endif; ?></div>
        </div>
        <label class="form-label fw-semibold">Does the Admission Form information match?</label>
        <div class="d-flex gap-3 flex-wrap mt-3 mb-3">
            <div class="sv-dec" id="a-yes" tabindex="0" role="button"><i class="fas fa-check-circle text-success" style="font-size:1.6rem;"></i><strong class="text-success">Yes — Information Correct</strong><small class="text-muted">Admission form matches records</small></div>
            <div class="sv-dec" id="a-no" tabindex="0" role="button"><i class="fas fa-times-circle text-danger" style="font-size:1.6rem;"></i><strong class="text-danger">No — Issues Found</strong><small class="text-muted">Discrepancies found</small></div>
        </div>
        <div id="a-pan" class="d-none">
            <div class="alert alert-danger p-3"><label class="fw-semibold text-danger mb-2 d-block"><i class="fas fa-exclamation-triangle me-1"></i>Describe the issues:</label><textarea id="a-txt" class="form-control border-danger" rows="3" placeholder="Describe discrepancies in the admission form…"></textarea></div>
            <div class="sv-tcp mb-3">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-ticket-alt text-warning"></i><strong style="font-size:.87rem;">Report to IT Support</strong><span class="text-muted ms-1" style="font-size:.77rem;">(optional)</span></div>
                <div class="row g-2">
                    <div class="col-12 col-md-8"><input type="text" id="tc3-t" class="form-control form-control-sm" placeholder="Ticket title…"></div>
                    <div class="col-6 col-md-2"><?= sv_prio_select('tc3-p') ?></div>
                    <div class="col-6 col-md-2"><button type="button" class="btn btn-warning btn-sm w-100 sv-tc" data-step="3" style="border-radius:6px;"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
                </div>
                <div id="tc3-r" class="d-none mt-2"></div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary sv-bk" data-to="2" style="border-radius:9px;"><i class="fas fa-arrow-left me-1"></i>Back</button>
            <button type="button" class="btn btn-primary sv-nxt d-none" id="a-cont" data-to="4" style="border-radius:9px;"><i class="fas fa-arrow-right me-1"></i>Continue to Tabulation</button>
        </div>
    </div>
</div>
</div><!-- /step 3 -->

<!-- ===== STEP 4 – Final Result Tabulation ================================= -->
<div class="sv-card" data-c="4">
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-warning text-dark" style="width:28px;height:28px;line-height:28px;text-align:center;">4</span>
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-pdf me-2 text-danger"></i>Final Result Tabulation</h6>
    </div>
    <div class="card-body px-4 py-4">
        <p class="text-muted mb-4" style="font-size:.875rem;"><i class="fas fa-info-circle me-1 text-primary"></i>Review the <strong>Final Result Tabulation</strong> PDF. Verify the student's name and ID appear correctly in the official tabulation document.</p>
        <div class="mb-4">
            <div class="fw-semibold mb-2" style="font-size:.87rem;color:#495057;"><i class="fas fa-eye me-1 text-danger"></i> Final Result Tabulation Preview</div>
            <div id="sv-tf"><?php if ($has_pre): echo sv_file_viewer_html($pre_tab_f, 'Final Result Tabulation'); endif; ?></div>
        </div>
        <label class="form-label fw-semibold">Was the student found in the tabulation with correct information?</label>
        <div class="d-flex gap-3 flex-wrap mt-3 mb-3">
            <div class="sv-dec" id="t-yes" tabindex="0" role="button"><i class="fas fa-check-circle text-success" style="font-size:1.6rem;"></i><strong class="text-success">Yes — Found &amp; Correct</strong><small class="text-muted">Student found in tabulation</small></div>
            <div class="sv-dec" id="t-no" tabindex="0" role="button"><i class="fas fa-times-circle text-danger" style="font-size:1.6rem;"></i><strong class="text-danger">No — Not Found / Issues</strong><small class="text-muted">Student not found or data incorrect</small></div>
        </div>
        <div id="t-pan" class="d-none">
            <div class="alert alert-danger p-3"><label class="fw-semibold text-danger mb-2 d-block"><i class="fas fa-exclamation-triangle me-1"></i>Describe the reason / issues:</label><textarea id="t-txt" class="form-control border-danger" rows="3" placeholder="Describe why the student was not found or any tabulation issues…"></textarea></div>
            <div class="sv-tcp mb-3">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="fas fa-ticket-alt text-warning"></i><strong style="font-size:.87rem;">Report to IT Support</strong><span class="text-muted ms-1" style="font-size:.77rem;">(optional)</span></div>
                <div class="row g-2">
                    <div class="col-12 col-md-8"><input type="text" id="tc4-t" class="form-control form-control-sm" placeholder="Ticket title…"></div>
                    <div class="col-6 col-md-2"><?= sv_prio_select('tc4-p') ?></div>
                    <div class="col-6 col-md-2"><button type="button" class="btn btn-warning btn-sm w-100 sv-tc" data-step="4" style="border-radius:6px;"><i class="fas fa-paper-plane me-1"></i>Send</button></div>
                </div>
                <div id="tc4-r" class="d-none mt-2"></div>
            </div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary sv-bk" data-to="3" style="border-radius:9px;"><i class="fas fa-arrow-left me-1"></i>Back</button>
            <button type="button" class="btn btn-primary sv-nxt d-none" id="t-cont" data-to="5" style="border-radius:9px;"><i class="fas fa-arrow-right me-1"></i>Continue to Final Review</button>
        </div>
    </div>
</div>
</div><!-- /step 4 -->

<!-- ===== STEP 5 – Final Review & Submit =================================== -->
<div class="sv-card" data-c="5">
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center gap-2">
        <span class="badge rounded-pill bg-success" style="width:28px;height:28px;line-height:28px;text-align:center;">5</span>
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clipboard-check me-2 text-success"></i>Final Review &amp; Submit</h6>
    </div>
    <div class="card-body px-4 py-4">
        <p class="text-muted mb-4" style="font-size:.875rem;"><i class="fas fa-info-circle me-1 text-primary"></i>Review the internal verification certificate below, enter the verifier's email (optional), then submit.</p>
        <div id="sv-prev" class="mb-4"></div>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Verifier Email Address <span class="text-muted fw-normal">(optional)</span></label>
                <input type="email" name="verifier_email" class="form-control" placeholder="verifier@example.com" value="<?= h($_POST['verifier_email'] ?? '') ?>">
                <div class="form-text">If provided, you can email the result from the verification record page.</div>
            </div>
        </div>
        <div class="d-flex gap-3 flex-wrap align-items-center">
            <button type="button" class="btn btn-outline-secondary sv-bk" data-to="4" style="border-radius:9px;"><i class="fas fa-arrow-left me-1"></i>Back</button>
            <button type="submit" class="btn btn-success px-4" style="border-radius:9px;"><i class="fas fa-shield-alt me-1"></i>Submit Verification</button>
            <button type="button" id="sv-prn" class="btn btn-outline-dark" style="border-radius:9px;"><i class="fas fa-print me-1"></i>Print Preview</button>
            <a href="<?= APP_URL ?>/student-verification/index.php" class="btn btn-outline-secondary" style="border-radius:9px;">Cancel</a>
        </div>
    </div>
</div>
</div><!-- /step 5 -->
</form>
<div id="sv-pa"></div>

<script>
(function(){
'use strict';
let stu=<?= $has_pre ? json_encode([
    'id'                => (int)$pre_student['id'],
    'student_id'        => $pre_student['student_id'],
    'full_name'         => $pre_student['full_name'],
    'dept_name'         => $pre_student['dept_name'],
    'program_name'      => $pre_student['program_name'] ?? '',
    'admitted_semester' => $pre_student['admitted_semester'] ?? '',
    'batch'             => $pre_student['batch'] ?? '',
    'status'            => $pre_student['status'] ?? '',
    'photo_url'         => sv_photo_url($pre_student['photo'] ?? null),
    'cgpa'              => $pre_cgpa,
]) : 'null' ?>;
const ch={1:{ok:null,iss:''},2:{ok:null,iss:''},3:{ok:null,iss:''},4:{ok:null,iss:''}};
const csrf=(document.querySelector('input[name="_csrf_token"]')||{}).value||'';

// ── Wizard nav ─────────────────────────────────────────────────────────────
function goTo(n){
    document.querySelectorAll('.sv-card').forEach(c=>c.classList.toggle('sv-on',+c.dataset.c===n));
    document.querySelectorAll('.sv-step').forEach(s=>{
        const sn=+s.dataset.s;
        s.classList.remove('active','completed');
        if(sn<n) s.classList.add('completed');
        else if(sn===n) s.classList.add('active');
    });
    if(n===5) buildPrev();
    window.scrollTo({top:0,behavior:'smooth'});
}
document.querySelectorAll('.sv-nxt').forEach(b=>b.addEventListener('click',()=>goTo(+b.dataset.to)));
document.querySelectorAll('.sv-bk').forEach(b=>b.addEventListener('click',()=>goTo(+b.dataset.to)));

// ── Search ─────────────────────────────────────────────────────────────────
const si=document.getElementById('sv-srch'),sd=document.getElementById('sv-drop');
let dt;
si.addEventListener('input',function(){
    clearTimeout(dt);const q=this.value.trim();
    if(q.length<2){sd.style.display='none';return;}
    dt=setTimeout(()=>{
        fetch('?ajax_search=1&q='+encodeURIComponent(q)).then(r=>r.json()).then(rows=>{
            sd.innerHTML='';
            if(!rows.length){sd.innerHTML='<div class="list-group-item text-muted" style="font-size:.85rem;">No students found</div>';}
            else rows.forEach(s=>{
                const b=document.createElement('button');b.type='button';
                b.className='list-group-item list-group-item-action';b.style.fontSize='.85rem';
                b.innerHTML=`<strong>${ex(s.student_id)}</strong> – ${ex(s.full_name)} <small class="text-muted ms-1">${ex(s.dept_name)}</small>`;
                b.addEventListener('click',()=>loadStu(s.id,s.student_id,s.full_name));
                sd.appendChild(b);
            });
            sd.style.display='block';
        }).catch(()=>{});
    },250);
});
document.addEventListener('click',e=>{if(!sd.contains(e.target)&&e.target!==si) sd.style.display='none';});

function loadStu(id,sid,name){
    si.value=sid+' – '+name;sd.style.display='none';
    document.getElementById('fld-sid').value=id;
    resetD1();
    const bx=document.getElementById('sv-sbox');
    bx.innerHTML='<div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin me-1"></i> Loading…</div>';
    bx.style.display='block';document.getElementById('sv-dec1').style.display='none';
    fetch('?ajax_student_card=1&id='+id).then(r=>r.json()).then(d=>{
        if(!d.ok){bx.innerHTML='<div class="alert alert-danger">Failed to load student.</div>';return;}
        stu=d.student;
        document.getElementById('sv-af').innerHTML=d.adm_html;
        document.getElementById('sv-tf').innerHTML=d.tab_html;
        bx.innerHTML=stuCard(d.student);
        document.getElementById('sv-dec1').style.display='';
        document.getElementById('d1-cont').style.display='none';
        document.getElementById('sv-mm').classList.add('d-none');
    }).catch(()=>bx.innerHTML='<div class="alert alert-danger">Error loading student.</div>');
}

function stuCard(s){
    const ph=s.photo_url?`<img src="${ex(s.photo_url)}" class="sv-ph" alt="${ex(s.full_name)}">`:`<div class="sv-ph-ph"><i class="fas fa-user-graduate"></i></div>`;
    const sb=`<span class="badge ${s.status==='Graduated'?'bg-success':'bg-primary'}">${ex(s.status||'Active')}</span>`;
    let rows=`<tr><th class="text-muted fw-normal" style="width:38%;">Department</th><td class="fw-medium">${ex(s.dept_name)}</td></tr>`;
    if(s.program_name)      rows+=`<tr><th class="text-muted fw-normal">Program</th><td>${ex(s.program_name)}</td></tr>`;
    if(s.admitted_semester) rows+=`<tr><th class="text-muted fw-normal">Admitted</th><td>${ex(s.admitted_semester)}</td></tr>`;
    if(s.batch)             rows+=`<tr><th class="text-muted fw-normal">Batch</th><td>${ex(s.batch)}</td></tr>`;
    if(s.cgpa)              rows+=`<tr><th class="text-muted fw-normal">CGPA</th><td><strong>${ex(s.cgpa)}</strong></td></tr>`;
    if(s.email)             rows+=`<tr><th class="text-muted fw-normal">Email</th><td>${ex(s.email)}</td></tr>`;
    if(s.phone)             rows+=`<tr><th class="text-muted fw-normal">Phone</th><td>${ex(s.phone)}</td></tr>`;
    return`<div class="sv-scard"><div class="d-flex gap-3 flex-wrap align-items-start">${ph}<div class="flex-grow-1"><div class="d-flex align-items-center gap-2 flex-wrap mb-2"><h5 class="mb-0 fw-bold">${ex(s.full_name)}</h5>${sb}</div><code class="bg-white px-2 py-1 rounded border mb-2 d-inline-block" style="font-size:.88rem;">${ex(s.student_id)}</code><table class="table table-sm mb-0 mt-1" style="font-size:.84rem;max-width:380px;">${rows}</table></div><span class="badge bg-success p-2 align-self-start"><i class="fas fa-check-circle me-1"></i>Found</span></div></div>`;
}

function resetD1(){
    ch[1]={ok:null,iss:''};
    ['d1-ok','d1-mm'].forEach(id=>document.getElementById(id).classList.remove('ok','fail'));
    document.getElementById('sv-mm').classList.add('d-none');
    document.getElementById('d1-cont').style.display='none';
    document.getElementById('fld-sdo').value='';
    document.getElementById('fld-sdiss').value='';
    document.getElementById('sv-mm-txt').value='';
}

// ── Step 1 decisions ───────────────────────────────────────────────────────
document.getElementById('d1-ok').addEventListener('click',()=>{
    ch[1].ok=true;
    document.getElementById('d1-ok').classList.add('ok');
    document.getElementById('d1-mm').classList.remove('fail');
    document.getElementById('sv-mm').classList.add('d-none');
    document.getElementById('d1-cont').style.display='';
    document.getElementById('fld-sdo').value='yes';
    document.getElementById('fld-sdiss').value='';
});
document.getElementById('d1-mm').addEventListener('click',()=>{
    ch[1].ok=false;
    document.getElementById('d1-mm').classList.add('fail');
    document.getElementById('d1-ok').classList.remove('ok');
    document.getElementById('sv-mm').classList.remove('d-none');
    document.getElementById('d1-cont').style.display='';
    document.getElementById('fld-sdo').value='no';
    if(stu) document.getElementById('tc1-t').value='Student data mismatch – '+stu.student_id+' ('+stu.full_name+')';
});
document.getElementById('sv-mm-txt').addEventListener('input',function(){
    ch[1].iss=this.value; document.getElementById('fld-sdiss').value=this.value;
});

// ── Steps 2-4 binary decisions ─────────────────────────────────────────────
mkBin('c-yes','c-no','c-pan','c-cont','fld-cok','fld-ciss','c-txt',2,()=>stu?'Certificate security issue – '+stu.student_id:'','tc2-t');
mkBin('a-yes','a-no','a-pan','a-cont','fld-aok','fld-aiss','a-txt',3,()=>stu?'Admission form discrepancy – '+stu.student_id:'','tc3-t');
mkBin('t-yes','t-no','t-pan','t-cont','fld-tok','fld-tiss','t-txt',4,()=>stu?'Result tabulation issue – '+stu.student_id:'','tc4-t');

function mkBin(yId,nId,panId,contId,hidOk,hidIss,taId,step,titleFn,tcTId){
    const y=document.getElementById(yId),n=document.getElementById(nId),
          pan=document.getElementById(panId),cont=document.getElementById(contId),
          ho=document.getElementById(hidOk),hi=document.getElementById(hidIss),
          ta=document.getElementById(taId);
    y.addEventListener('click',()=>{
        ch[step].ok=true; y.classList.add('ok'); n.classList.remove('fail');
        pan.classList.add('d-none'); cont.classList.remove('d-none');
        ho.value='yes'; hi.value='';
    });
    n.addEventListener('click',()=>{
        ch[step].ok=false; n.classList.add('fail'); y.classList.remove('ok');
        pan.classList.remove('d-none'); cont.classList.remove('d-none');
        ho.value='no';
        const t=document.getElementById(tcTId); if(t) t.value=titleFn();
    });
    ta.addEventListener('input',function(){ ch[step].iss=this.value; hi.value=this.value; });
}

// Keyboard accessibility
document.querySelectorAll('.sv-dec[tabindex="0"]').forEach(el=>{
    el.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){e.preventDefault();el.click();} });
});

// ── IT Ticket AJAX ─────────────────────────────────────────────────────────
const tcCfg={
    1:{t:'tc1-t',p:'tc1-p',r:'tc1-r',d:()=>document.getElementById('sv-mm-txt').value},
    2:{t:'tc2-t',p:'tc2-p',r:'tc2-r',d:()=>document.getElementById('c-txt').value},
    3:{t:'tc3-t',p:'tc3-p',r:'tc3-r',d:()=>document.getElementById('a-txt').value},
    4:{t:'tc4-t',p:'tc4-p',r:'tc4-r',d:()=>document.getElementById('t-txt').value},
};
document.querySelectorAll('.sv-tc').forEach(btn=>{
    btn.addEventListener('click',function(){
        const step=+this.dataset.step,cfg=tcCfg[step];
        const title=document.getElementById(cfg.t).value.trim();
        const prio=document.getElementById(cfg.p).value;
        const desc=cfg.d().trim();
        const res=document.getElementById(cfg.r);
        if(!title){alert('Please enter a ticket title.');return;}
        let fd2=desc;
        if(stu) fd2='Student: '+stu.full_name+' ('+stu.student_id+')\nDepartment: '+stu.dept_name+'\n\n'+(desc||'No additional details provided.');
        this.disabled=true;const orig=this.innerHTML;
        this.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
        const fd=new FormData();
        fd.append('ajax_create_ticket','1');fd.append('_csrf_token',csrf);
        fd.append('tc_title',title);fd.append('tc_desc',fd2);fd.append('tc_priority',prio);
        fetch(window.location.pathname,{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
            res.classList.remove('d-none');
            if(d.ok){
                res.innerHTML=`<div class="alert alert-success py-2 px-3 mb-0" style="font-size:.82rem;"><i class="fas fa-check-circle me-1"></i>Ticket <strong>${ex(d.ticket_number)}</strong> created. <a href="<?= APP_URL ?>/support-tickets/view.php?id=${parseInt(d.id,10)}" target="_blank">View</a></div>`;
                this.innerHTML='<i class="fas fa-check me-1"></i>Sent';this.classList.replace('btn-warning','btn-success');
            }else{
                res.innerHTML=`<div class="alert alert-danger py-2 px-3 mb-0" style="font-size:.82rem;">${ex(d.error||'Error')}</div>`;
                this.disabled=false;this.innerHTML=orig;
            }
        }).catch(()=>{res.classList.remove('d-none');res.innerHTML='<div class="alert alert-danger py-2 px-3 mb-0" style="font-size:.82rem;">Network error.</div>';this.disabled=false;this.innerHTML=orig;});
    });
});

// ── Step 5: Build certificate preview ─────────────────────────────────────
function buildPrev(){
    const area=document.getElementById('sv-prev');
    if(!stu){area.innerHTML='<div class="alert alert-warning">No student selected.</div>';return;}
    const now=new Date();
    const ds=now.toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'});
    const ts=now.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
    const c1=ch[1].ok!==false,c2=ch[2].ok!==false,c3=ch[3].ok!==false,c4=ch[4].ok!==false;
    const allOk=c1&&c2&&c3&&c4;
    const sc=allOk?'#198754':'#dc3545',sb=allOk?'#d1e7dd':'#f8d7da';
    const stxt=allOk?'&#10003;&nbsp;VERIFIED – GENUINE &amp; AUTHENTIC':'&#10007;&nbsp;VERIFICATION INCOMPLETE / FAILED';
    const sicon=allOk?'fa-shield-alt':'fa-times-circle';
    const ph=stu.photo_url?`<img src="${ex(stu.photo_url)}" style="width:82px;height:100px;object-fit:cover;border-radius:8px;border:2px solid #ced4da;flex-shrink:0;" alt="">`:`<div style="width:82px;height:100px;border-radius:8px;border:2px solid #ced4da;background:#e9ecef;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-user-graduate" style="color:#adb5bd;font-size:2rem;"></i></div>`;
    let sr=`<tr><td style="color:#6c757d;padding:2px 0;width:38%;font-size:.83rem;">Department</td><td style="font-weight:600;font-size:.83rem;">${ex(stu.dept_name)}</td></tr>`;
    if(stu.program_name)      sr+=`<tr><td style="color:#6c757d;padding:2px 0;font-size:.83rem;">Program</td><td style="font-weight:600;font-size:.83rem;">${ex(stu.program_name)}</td></tr>`;
    if(stu.admitted_semester) sr+=`<tr><td style="color:#6c757d;padding:2px 0;font-size:.83rem;">Admitted</td><td style="font-size:.83rem;">${ex(stu.admitted_semester)}</td></tr>`;
    if(stu.cgpa)              sr+=`<tr><td style="color:#6c757d;padding:2px 0;font-size:.83rem;">CGPA</td><td style="font-size:.83rem;"><strong>${ex(stu.cgpa)}</strong></td></tr>`;
    const chk=(ok,lbl,iss)=>`<div class="sv-chk ${ok?'ok':'fail'}"><i class="fas ${ok?'fa-check-circle text-success':'fa-times-circle text-danger'}" style="font-size:1rem;flex-shrink:0;margin-top:1px;"></i><div><strong style="font-size:.86rem;">${lbl}</strong>${!ok&&iss?`<div style="font-size:.78rem;color:#842029;margin-top:2px;">${ex(iss)}</div>`:''}</div></div>`;
    const vn=<?= json_encode($user['full_name']) ?>;
    const ref='PU-IV-'+(stu.student_id||'').replace(/[^A-Za-z0-9]/g,'').toUpperCase();
    area.innerHTML=`<div class="sv-cert">
<div class="sv-band"></div>
<div class="sv-chd">
    <div style="width:44px;height:44px;background:rgba(255,255,255,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-university" style="color:#fff;font-size:1.25rem;"></i></div>
    <div class="sv-chd-t"><h6>Prime University Bangladesh</h6><p>Internal Verification Certificate &nbsp;·&nbsp; <code style="color:rgba(255,255,255,.55);font-size:.7rem;">${ex(ref)}</code></p></div>
    <div class="ms-auto text-end" style="color:rgba(255,255,255,.55);font-size:.72rem;">${ex(ds)}<br>${ex(ts)}</div>
</div>
<div class="sv-cb">
    <div style="display:flex;gap:14px;align-items:flex-start;background:#f8faff;border:1.5px solid #dbe4f3;border-radius:10px;padding:13px 16px;margin-bottom:16px;flex-wrap:wrap;">
        ${ph}
        <div style="flex:1;min-width:180px;">
            <div style="font-size:1.05rem;font-weight:800;color:#1a2e5a;margin-bottom:4px;">${ex(stu.full_name)}</div>
            <code style="background:#fff;border:1px solid #dee2e6;padding:2px 6px;border-radius:4px;font-size:.83rem;">${ex(stu.student_id)}</code>
            <table style="margin-top:7px;border-collapse:collapse;width:100%;max-width:340px;">${sr}</table>
        </div>
    </div>
    <div style="padding:11px 14px;border-radius:9px;background:${sb};border:1.5px solid ${sc};margin-bottom:16px;display:flex;align-items:center;gap:10px;">
        <i class="fas ${sicon}" style="color:${sc};font-size:1.35rem;flex-shrink:0;"></i>
        <div>
            <div style="font-size:.92rem;font-weight:800;color:${sc};">${stxt}</div>
            <div style="font-size:.82rem;color:${allOk?'#0f5132':'#842029'};margin-top:2px;">${allOk?'This is an internal verification and we found this student is genuine/authentic.':'One or more verification checks did not pass. See details below.'}</div>
        </div>
    </div>
    <div style="margin-bottom:16px;">
        <div style="font-weight:700;font-size:.86rem;color:#495057;margin-bottom:7px;"><i class="fas fa-tasks me-1"></i>We have checked:</div>
        ${chk(c1,'Student record details match presented documents',ch[1].iss)}
        ${chk(c2,'Certificate &amp; Transcript – Visual security measures verified correct',ch[2].iss)}
        ${chk(c3,'Hard copy student files (Admission Form) checked and found correct',ch[3].iss)}
        ${chk(c4,'Hard copy student result tabulation checked and found correct',ch[4].iss)}
    </div>
    <div style="border-top:1.5px solid #e9ecef;padding-top:11px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;font-size:.79rem;color:#6c757d;">
        <div><div><strong>Verified by:</strong> ${ex(vn)}</div><div><strong>Date:</strong> ${ex(ds)} at ${ex(ts)}</div></div>
        <div style="text-align:right;"><div>Prime University Bangladesh</div><div>House 28/A, Iqbal Road, Mohammadpur, Dhaka-1207</div></div>
    </div>
</div></div>`;
}

// ── Print ──────────────────────────────────────────────────────────────────
document.getElementById('sv-prn').addEventListener('click',()=>{
    const pa=document.getElementById('sv-pa');
    pa.innerHTML='<style>body{font-family:Segoe UI,Arial,sans-serif;margin:0;}.sv-cert{max-width:710px;margin:20px auto;border:2px solid #dee2e6;border-radius:14px;overflow:hidden;}'
        +'.sv-band{height:7px;background:linear-gradient(90deg,#1a2e5a,#2563eb 50%,#10b981);}'
        +'.sv-chd{background:#1a2e5a;padding:16px 26px;display:flex;align-items:center;gap:14px;}'
        +'.sv-chd-t h6{margin:0 0 3px;font-size:1rem;font-weight:800;color:#fff;}'
        +'.sv-chd-t p{margin:0;font-size:.77rem;opacity:.7;color:#fff;}'
        +'.sv-cb{padding:20px 26px;}'
        +'.sv-chk{display:flex;align-items:flex-start;gap:10px;padding:9px 12px;border-radius:7px;background:#f8f9fa;border:1.5px solid #dee2e6;margin-bottom:7px;}'
        +'.sv-chk.ok{background:#d1e7dd;border-color:#a3cfbb;}'
        +'.sv-chk.fail{background:#f8d7da;border-color:#f1aeb5;}'
        +'</style>'
        +document.getElementById('sv-prev').innerHTML;
    window.print();
});

// ── Escape ─────────────────────────────────────────────────────────────────
function ex(s){if(s==null)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}

// ── Init ───────────────────────────────────────────────────────────────────
<?php if ($has_pre): ?>
document.getElementById('fld-sid').value=<?= (int)$pre_student['id'] ?>;
document.getElementById('sv-dec1').style.display='';
<?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
