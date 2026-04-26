<?php
/**
 * Student Verification Certificate – Printable / PDF-ready
 * ?mode=digital  → Digital Signed version (no physical signature lines)
 * ?mode=hand     → Hand Signed version (blank signature lines)
 * (no mode)      → version selection page
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification');

$id   = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? '';

$stmt = db()->prepare(
    'SELECT sv.*,
            s.student_id AS s_student_id, s.full_name AS s_full_name,
            s.email AS s_email, s.phone AS s_phone,
            d.name AS dept_name,
            p.program_name,
            s.admitted_semester,
            s.batch,
            s.status AS s_status,
            s.photo,
            u.full_name AS verifier_name
     FROM student_verifications sv
     JOIN students s ON s.id = sv.student_id
     JOIN dept_departments d ON d.id = s.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = s.program_id
     JOIN users u ON u.id = sv.verified_by
     WHERE sv.id = ?'
);
$stmt->execute([$id]);
$rec = $stmt->fetch();

if (!$rec) {
    die('Record not found.');
}

// Compute Final CGPA
$cert_cgpa = null;
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
    $cq->execute([$rec['s_student_id']]);
    $cv = $cq->fetchColumn();
    if ($cv !== null && $cv !== false) $cert_cgpa = number_format((float)$cv, 2);
} catch (Throwable $e) {}
if ($cert_cgpa === null) {
    try {
        $sr = db()->prepare(
            'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) FROM student_results
             WHERE student_id=? AND cgpa IS NOT NULL AND TRIM(cgpa)!=""'
        );
        $sr->execute([$rec['student_id']]);
        $sv2 = $sr->fetchColumn();
        if ($sv2 !== null && (float)$sv2 > 0) $cert_cgpa = number_format((float)$sv2, 2);
    } catch (Throwable $e) {}
}

// Fetch Ending Semester
$cert_ending_sem = null;
try {
    $eq = db()->prepare(
        'SELECT re.completion_semester
         FROM result_grades rg
         JOIN result_exams re ON re.id = rg.exam_id
         WHERE rg.student_sid = ? AND re.is_published = 1
           AND re.completion_semester IS NOT NULL
         ORDER BY re.updated_at DESC LIMIT 1'
    );
    $eq->execute([$rec['s_student_id']]);
    $erow = $eq->fetchColumn();
    if ($erow) $cert_ending_sem = $erow;
} catch (Throwable $e) {}

// Student data check column
$has_sdo = (bool)db()->query("SHOW COLUMNS FROM student_verifications LIKE 'student_data_ok'")->fetchColumn();
$online_record_ok = $has_sdo ? (bool)($rec['student_data_ok'] ?? 1) : true;

$verified  = $rec['overall_status'] === 'Verified';
$date_str  = date('d F Y', strtotime($rec['created_at']));
$time_str  = date('H:i', strtotime($rec['created_at']));
$ref_no    = 'PU-VER-' . str_pad($id, 6, '0', STR_PAD_LEFT);

// Photo URL
function cert_photo_url(?string $photo): string {
    if (!$photo) return '';
    if (!preg_match('/\A[A-Za-z0-9_\-]+\.[a-z]{2,5}\z/', $photo)) return '';
    $p = UPLOAD_DIR . '/students/photos/' . $photo;
    return is_file($p)
        ? UPLOAD_URL . '/students/photos/' . rawurlencode($photo)
        : SITE_URL   . '/upload_spic/'     . rawurlencode($photo);
}
$photo_url = cert_photo_url($rec['photo'] ?? null);

$is_digital = ($mode === 'digital');
$is_hand    = ($mode === 'hand');
$show_cert  = ($is_digital || $is_hand);

$version_label = $is_digital ? 'Digital Signed' : ($is_hand ? 'Hand Signed' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verification Certificate – <?= h($rec['s_full_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page { size: A4 portrait; margin: 12mm 14mm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1a2035;
            background: #eef2f7;
        }
        /* ── Version Selector ─── */
        .sel-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .sel-logo { font-size: 1.5rem; font-weight: 800; color: #1a2e5a; margin-bottom: 6px; }
        .sel-sub  { font-size: .88rem; color: #6b7280; margin-bottom: 36px; }
        .sel-cards {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .sel-card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 36px rgba(26,46,90,.13);
            padding: 36px 32px;
            width: 280px;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            border: 2.5px solid transparent;
            transition: border-color .2s, transform .2s, box-shadow .2s;
        }
        .sel-card:hover {
            border-color: #2563eb;
            transform: translateY(-4px);
            box-shadow: 0 14px 44px rgba(37,99,235,.2);
        }
        .sel-icon { font-size: 2.8rem; margin-bottom: 16px; }
        .sel-card h3 { font-size: 1.05rem; font-weight: 800; color: #1a2e5a; margin-bottom: 8px; }
        .sel-card p  { font-size: .82rem; color: #6b7280; line-height: 1.5; }
        .sel-badge { display: inline-block; padding: 3px 12px; border-radius: 50px; font-size: .73rem; font-weight: 700; margin-top: 14px; }
        .sel-badge.digital { background: #dbeafe; color: #1d4ed8; }
        .sel-badge.hand    { background: #f0fdf4; color: #15803d; }

        /* ── Certificate Wrapper ─── */
        .cert-page {
            max-width: 780px;
            margin: 0 auto;
        }
        .cert-doc {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 12px 48px rgba(0,0,0,.13);
            border: 1.5px solid #e2e8f0;
        }
        /* Rainbow band */
        .cert-band {
            height: 7px;
            background: linear-gradient(90deg, #1a2e5a 0%, #2563eb 50%, #10b981 100%);
        }
        /* Header */
        .cert-hdr {
            padding: 22px 36px 18px;
            display: flex;
            align-items: center;
            gap: 18px;
            border-bottom: 2px solid #e8edf5;
            position: relative;
        }
        .cert-hdr-logo img { width: 68px; }
        .cert-hdr-logo .logo-ph {
            width: 68px; height: 68px;
            background: #f0f4ff; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; color: #2563eb; font-weight: 900;
        }
        .cert-hdr-text { flex: 1; }
        .cert-hdr-text h1 { font-size: 14pt; font-weight: 800; color: #1a2e5a; line-height: 1.2; margin-bottom: 3px; }
        .cert-hdr-text .addr { font-size: 7.5pt; color: #4b5563; line-height: 1.8; }
        .cert-hdr-watermark {
            position: absolute; right: 36px; top: 50%; transform: translateY(-50%);
            font-size: 52pt; font-weight: 900; color: rgba(26,46,90,.03);
            pointer-events: none; user-select: none;
        }
        /* Title bar */
        .cert-titlebar {
            background: linear-gradient(135deg, #1a2e5a 0%, #1e3a8a 100%);
            padding: 13px 36px;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;
        }
        .cert-titlebar-left {
            font-size: 10.5pt; font-weight: 800; color: #fff;
            letter-spacing: .04em; text-transform: uppercase;
        }
        .cert-titlebar-right { font-size: 7.5pt; color: rgba(255,255,255,.65); font-family: monospace; letter-spacing: .04em; }
        /* Body */
        .cert-body { padding: 26px 36px 30px; }
        /* Student card */
        .stu-card {
            display: flex; gap: 18px; align-items: flex-start;
            background: #f8faff; border: 1.5px solid #dbe4f3;
            border-radius: 14px; padding: 18px 22px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .stu-photo {
            width: 88px; height: 108px; object-fit: cover;
            border-radius: 10px; border: 2.5px solid #cbd5e1; flex-shrink: 0;
        }
        .stu-photo-ph {
            width: 88px; height: 108px; border-radius: 10px; border: 2.5px solid #cbd5e1;
            background: linear-gradient(135deg, #dbeafe, #e0f2fe);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 2.4rem; color: #93c5fd;
        }
        .stu-info { flex: 1; min-width: 200px; }
        .stu-name { font-size: 13pt; font-weight: 800; color: #1a2e5a; margin-bottom: 7px; }
        .stu-id-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #1a2e5a; color: #fff; border-radius: 5px;
            padding: 3px 10px; font-size: 8pt; font-weight: 700; letter-spacing: .05em; margin-bottom: 4px;
        }
        .stu-status-badge {
            display: inline-flex; align-items: center; padding: 3px 11px;
            border-radius: 50px; font-size: 8pt; font-weight: 700; margin-left: 7px;
        }
        .stu-status-graduated { background: #d1fae5; color: #065f46; }
        .stu-status-active    { background: #dbeafe; color: #1d4ed8; }
        .stu-status-other     { background: #f3f4f6; color: #6b7280; }
        .stu-table { border-collapse: collapse; width: 100%; max-width: 420px; margin-top: 10px; }
        .stu-table td { padding: 3px 0; vertical-align: top; }
        .stu-table .lbl { color: #9ca3af; font-size: 7.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; width: 44%; }
        .stu-table .val { font-size: 9.5pt; font-weight: 700; color: #1a2e5a; }
        /* Status banner */
        .status-banner {
            padding: 14px 18px; border-radius: 12px; margin-bottom: 22px;
            display: flex; align-items: center; gap: 14px;
        }
        .status-verified { background: #d1fae5; border: 2px solid #6ee7b7; }
        .status-failed   { background: #fee2e2; border: 2px solid #fca5a5; }
        .status-icon { font-size: 1.5rem; flex-shrink: 0; }
        .status-title { font-size: 10pt; font-weight: 800; }
        .status-title.verified { color: #065f46; }
        .status-title.failed   { color: #991b1b; }
        .status-sub   { font-size: 8pt; opacity: .85; margin-top: 3px; }
        .status-sub.verified { color: #065f46; }
        .status-sub.failed   { color: #991b1b; }
        /* Checks */
        .checks-heading {
            font-size: 8pt; font-weight: 800; color: #2563eb;
            text-transform: uppercase; letter-spacing: .07em;
            border-bottom: 1.5px solid #dbe4f3; padding-bottom: 7px; margin-bottom: 12px;
        }
        .chk-row {
            display: flex; align-items: flex-start; gap: 11px;
            padding: 10px 14px; border-radius: 9px; margin-bottom: 8px;
            border: 1.5px solid;
        }
        .chk-ok   { background: #d1fae5; border-color: #6ee7b7; }
        .chk-fail { background: #fee2e2; border-color: #fca5a5; }
        .chk-icon { font-size: 1rem; flex-shrink: 0; margin-top: 2px; }
        .chk-ok   .chk-icon { color: #059669; }
        .chk-fail .chk-icon { color: #dc2626; }
        .chk-label { font-size: 8.5pt; font-weight: 700; }
        .chk-ok   .chk-label { color: #065f46; }
        .chk-fail .chk-label { color: #991b1b; }
        .chk-issue { font-size: 7.5pt; color: #7f1d1d; margin-top: 2px; }
        /* Meta footer */
        .cert-meta {
            border-top: 1.5px solid #e8edf5; padding-top: 16px;
            display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;
            font-size: 8pt; color: #6b7280; margin-top: 22px;
        }
        .cert-meta .left .name  { font-weight: 700; color: #1a2e5a; margin-bottom: 2px; }
        .cert-meta .right { text-align: right; }
        .cert-meta .right .uni  { font-weight: 700; color: #1a2e5a; }
        /* Signature area (hand only) */
        .sig-area {
            display: flex; justify-content: space-between; gap: 20px;
            margin-top: 30px; padding-top: 18px; border-top: 1.5px solid #e8edf5;
            flex-wrap: wrap;
        }
        .sig-block { flex: 1; min-width: 180px; text-align: center; }
        .sig-line  { border-bottom: 1.5px solid #555; height: 48px; margin-bottom: 6px; }
        .sig-lbl   { font-size: 8pt; color: #555; line-height: 1.4; }
        /* Digital signature badge */
        .dig-sig-area {
            display: flex; align-items: center; gap: 14px;
            background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
            border: 1.5px solid #6ee7b7; border-radius: 12px;
            padding: 14px 18px; margin-top: 22px;
        }
        .dig-sig-icon { font-size: 2rem; color: #059669; flex-shrink: 0; }
        .dig-sig-title { font-size: 9pt; font-weight: 800; color: #065f46; margin-bottom: 3px; }
        .dig-sig-sub   { font-size: 7.5pt; color: #059669; line-height: 1.4; }
        /* Document footer */
        .cert-doc-footer {
            background: #f8fafc; border-top: 1.5px solid #e8edf5;
            padding: 10px 36px; text-align: center;
            font-size: 7pt; color: #9ca3af;
        }
        /* Failed note */
        .failed-note {
            font-size: 8.5pt; color: #555; padding: 10px 16px;
            background: #fff3cd; border-left: 3.5px solid #ffc107;
            border-radius: 5px; margin-top: 14px;
        }
        /* Print controls */
        .print-bar {
            background: #1a2e5a;
            padding: 14px 24px;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .print-bar-left { color: #fff; font-size: .88rem; font-weight: 600; }
        .print-bar-version {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 14px; border-radius: 50px; font-size: .78rem; font-weight: 700; margin-left: 10px;
        }
        .pv-digital { background: #dbeafe; color: #1d4ed8; }
        .pv-hand    { background: #d1fae5; color: #065f46; }
        .print-bar-btns { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn-print  { padding: 8px 20px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: .85rem; font-weight: 700; cursor: pointer; }
        .btn-switch { padding: 8px 16px; background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.3); border-radius: 8px; font-size: .83rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn-back   { padding: 8px 16px; background: rgba(255,255,255,.1); color: rgba(255,255,255,.8); border: 1px solid rgba(255,255,255,.2); border-radius: 8px; font-size: .83rem; cursor: pointer; text-decoration: none; }
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .cert-page { max-width: 100%; }
            .cert-doc  { border: none; border-radius: 0; box-shadow: none; }
        }
    </style>
</head>
<body>

<?php if (!$show_cert): ?>
<!-- ── Version Selection Page ─────────────────────────────────────────── -->
<div class="sel-wrap">
    <div class="sel-logo">Prime University Bangladesh</div>
    <div class="sel-sub">Student Verification Certificate — Choose a version to print</div>
    <div class="sel-cards">
        <a href="?id=<?= $id ?>&mode=digital" class="sel-card">
            <div class="sel-icon">🔏</div>
            <h3>Digital Signed Version</h3>
            <p>Displays a digital authentication badge. No physical signature is required. Ideal for email and digital records.</p>
            <span class="sel-badge digital"><i class="fas fa-shield-alt me-1"></i>Digitally Authenticated</span>
        </a>
        <a href="?id=<?= $id ?>&mode=hand" class="sel-card">
            <div class="sel-icon">✍️</div>
            <h3>Hand Signed Version</h3>
            <p>Includes blank signature lines for authorised signatories. Print, sign physically, then scan or distribute.</p>
            <span class="sel-badge hand"><i class="fas fa-pen-nib me-1"></i>Physical Signature</span>
        </a>
    </div>
    <div style="margin-top:28px;">
        <a href="<?= APP_URL ?>/student-verification/view.php?id=<?= $id ?>"
           style="color:#6b7280;font-size:.85rem;text-decoration:none;">
            ← Back to Verification Record
        </a>
    </div>
</div>

<?php else: ?>
<!-- ── Certificate Print Bar ──────────────────────────────────────────── -->
<div class="print-bar no-print">
    <div class="print-bar-left">
        <i class="fas fa-file-certificate me-2"></i>
        Verification Certificate
        <span class="print-bar-version <?= $is_digital ? 'pv-digital' : 'pv-hand' ?>">
            <i class="fas <?= $is_digital ? 'fa-shield-alt' : 'fa-pen-nib' ?> me-1"></i>
            <?= $is_digital ? 'Digital Signed' : 'Hand Signed' ?>
        </span>
    </div>
    <div class="print-bar-btns">
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print me-1"></i> Print / Save as PDF
        </button>
        <a href="?id=<?= $id ?>&mode=<?= $is_digital ? 'hand' : 'digital' ?>" class="btn-switch">
            <i class="fas fa-exchange-alt me-1"></i>
            Switch to <?= $is_digital ? 'Hand Signed' : 'Digital Signed' ?>
        </a>
        <a href="?id=<?= $id ?>" class="btn-back">← Change Version</a>
        <a href="<?= APP_URL ?>/student-verification/view.php?id=<?= $id ?>" class="btn-back">← Record</a>
    </div>
</div>

<!-- ── Certificate Document ───────────────────────────────────────────── -->
<div class="cert-page" style="padding: 20px 16px 40px;">
<div class="cert-doc">

    <!-- Rainbow band -->
    <div class="cert-band"></div>

    <!-- University Header -->
    <div class="cert-hdr">
        <?php $logo_src = defined('LOGO_URL') ? LOGO_URL : SITE_URL . '/assets/img/logo/logo-black.png'; ?>
        <div class="cert-hdr-logo">
            <img src="<?= h($logo_src) ?>" alt="Prime University" onerror="this.parentNode.innerHTML='<div class=\'logo-ph\'>PU</div>'">
        </div>
        <div class="cert-hdr-text">
            <h1>Prime University Bangladesh</h1>
            <div class="addr">
                114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh<br>
                PABX: +88-02-41002432 &nbsp;|&nbsp; +88-02-41002435 &nbsp;|&nbsp; 01969-955566<br>
                www.primeuniversity.ac.bd &nbsp;|&nbsp; verification@primeuniversity.ac.bd
            </div>
        </div>
        <div class="cert-hdr-watermark" aria-hidden="true">PU</div>
    </div>

    <!-- Title Bar -->
    <div class="cert-titlebar">
        <div class="cert-titlebar-left">
            <i class="fas fa-shield-alt" style="margin-right:7px;opacity:.85;"></i>
            Student Verification Certificate
        </div>
        <div class="cert-titlebar-right"><?= h($ref_no) ?></div>
    </div>

    <!-- Body -->
    <div class="cert-body">

        <!-- Student Card -->
        <div class="stu-card">
            <?php if ($photo_url): ?>
                <img src="<?= h($photo_url) ?>" class="stu-photo" alt="<?= h($rec['s_full_name']) ?>"
                     onerror="this.outerHTML='<div class=\'stu-photo-ph\'><i class=\'fas fa-user-graduate\'></i></div>'">
            <?php else: ?>
                <div class="stu-photo-ph"><i class="fas fa-user-graduate"></i></div>
            <?php endif; ?>
            <div class="stu-info">
                <div class="stu-name"><?= h($rec['s_full_name']) ?></div>
                <div>
                    <span class="stu-id-badge"><i class="fas fa-id-card" style="font-size:.75rem;"></i>&nbsp;<?= h($rec['s_student_id']) ?></span>
                    <?php
                    $s_status = $rec['s_status'] ?? '';
                    $status_cls = $s_status === 'Graduated' ? 'stu-status-graduated' : ($s_status === 'Active' ? 'stu-status-active' : 'stu-status-other');
                    ?>
                    <span class="stu-status-badge <?= $status_cls ?>"><?= h($s_status ?: 'Active') ?></span>
                </div>
                <table class="stu-table">
                    <tr><td class="lbl">Department</td><td class="val"><?= h($rec['dept_name']) ?></td></tr>
                    <?php if ($rec['program_name']): ?>
                    <tr><td class="lbl">Obtained Degree</td><td class="val"><?= h($rec['program_name']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($rec['admitted_semester']): ?>
                    <tr><td class="lbl">Enrolled Semester</td><td class="val"><?= h($rec['admitted_semester']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="lbl">Ending Semester</td><td class="val"><?= $cert_ending_sem ? h($cert_ending_sem) : '—' ?></td></tr>
                    <?php if ($rec['batch']): ?>
                    <tr><td class="lbl">Batch</td><td class="val"><?= h($rec['batch']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td class="lbl">Graduated</td>
                        <td class="val" style="<?= $s_status === 'Graduated' ? 'color:#059669;' : 'color:#6b7280;font-weight:500;' ?>">
                            <?= $s_status === 'Graduated' ? '✔ Yes' : 'No' ?>
                        </td>
                    </tr>
                    <?php if ($cert_cgpa): ?>
                    <tr><td class="lbl">Final CGPA</td><td class="val" style="font-size:11pt;font-weight:900;"><?= h($cert_cgpa) ?> / 4.00</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Verification Status Banner -->
        <div class="status-banner <?= $verified ? 'status-verified' : 'status-failed' ?>">
            <i class="fas <?= $verified ? 'fa-shield-alt' : 'fa-times-circle' ?> status-icon"
               style="color:<?= $verified ? '#059669' : '#dc2626' ?>;"></i>
            <div>
                <div class="status-title <?= $verified ? 'verified' : 'failed' ?>">
                    <?= $verified ? '✔ VERIFIED – GENUINE &amp; AUTHENTIC' : '✘ VERIFICATION FAILED' ?>
                </div>
                <div class="status-sub <?= $verified ? 'verified' : 'failed' ?>">
                    <?= $verified
                        ? 'This certificate confirms the above student\'s credentials have been verified and found genuine.'
                        : 'One or more verification checks did not pass. See details below.' ?>
                </div>
            </div>
        </div>

        <!-- Verification Checklist -->
        <div class="checks-heading">✓ Verification Checklist</div>

        <?php if ($has_sdo): ?>
        <div class="chk-row <?= $online_record_ok ? 'chk-ok' : 'chk-fail' ?>">
            <i class="fas <?= $online_record_ok ? 'fa-check-circle' : 'fa-times-circle' ?> chk-icon"></i>
            <div>
                <div class="chk-label">Online Record</div>
                <?php if (!$online_record_ok && !empty($rec['student_data_issues'])): ?>
                <div class="chk-issue"><?= h($rec['student_data_issues']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="chk-row <?= $rec['cert_transcript_ok'] ? 'chk-ok' : 'chk-fail' ?>">
            <i class="fas <?= $rec['cert_transcript_ok'] ? 'fa-check-circle' : 'fa-times-circle' ?> chk-icon"></i>
            <div>
                <div class="chk-label">Certificate &amp; Transcript – Visual Security Measures</div>
                <?php if (!$rec['cert_transcript_ok'] && !empty($rec['cert_transcript_issues'])): ?>
                <div class="chk-issue"><?= h($rec['cert_transcript_issues']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="chk-row <?= $rec['admission_form_ok'] ? 'chk-ok' : 'chk-fail' ?>">
            <i class="fas <?= $rec['admission_form_ok'] ? 'fa-check-circle' : 'fa-times-circle' ?> chk-icon"></i>
            <div>
                <div class="chk-label">Admission Form (Hard Copy)</div>
                <?php if (!$rec['admission_form_ok'] && !empty($rec['admission_form_issues'])): ?>
                <div class="chk-issue"><?= h($rec['admission_form_issues']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="chk-row <?= $rec['tabulation_ok'] ? 'chk-ok' : 'chk-fail' ?>">
            <i class="fas <?= $rec['tabulation_ok'] ? 'fa-check-circle' : 'fa-times-circle' ?> chk-icon"></i>
            <div>
                <div class="chk-label">Final Result Tabulation (Hard Copy)</div>
                <?php if (!$rec['tabulation_ok'] && !empty($rec['tabulation_issues'])): ?>
                <div class="chk-issue"><?= h($rec['tabulation_issues']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$verified): ?>
        <div class="failed-note">
            <strong>Note:</strong> This student's credentials could not be fully verified.
            For further assistance please visit Prime University Bangladesh at
            114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh.
        </div>
        <?php endif; ?>

        <!-- Signature / Auth area -->
        <?php if ($is_digital): ?>
        <div class="dig-sig-area">
            <i class="fas fa-shield-alt dig-sig-icon"></i>
            <div>
                <div class="dig-sig-title">Digitally Authenticated by Prime University Bangladesh</div>
                <div class="dig-sig-sub">
                    This certificate has been digitally generated and authenticated by the Prime University Verification System.<br>
                    Verified by: <strong><?= h($rec['verifier_name']) ?></strong> &nbsp;|&nbsp;
                    Date: <?= h($date_str) ?> at <?= h($time_str) ?> &nbsp;|&nbsp;
                    Ref: <?= h($ref_no) ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="sig-area">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-lbl">Authorised Signatory<br>Prime University Bangladesh</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-lbl">Registrar / Controller of Examinations<br>Prime University Bangladesh</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Cert Meta -->
        <div class="cert-meta">
            <div class="left">
                <?php if ($is_hand): ?>
                <div class="name">Verified by: <?= h($rec['verifier_name']) ?></div>
                <div>Date: <?= h($date_str) ?> at <?= h($time_str) ?></div>
                <?php else: ?>
                <div>Ref: <?= h($ref_no) ?> &nbsp;|&nbsp; Date: <?= h($date_str) ?></div>
                <?php endif; ?>
            </div>
            <div class="right">
                <div class="uni">Prime University Bangladesh</div>
                <div>114/116 Mazar Road, Mirpur-1, Dhaka 1216</div>
            </div>
        </div>

    </div><!-- /cert-body -->

    <!-- Document Footer -->
    <div class="cert-doc-footer">
        This certificate was generated by the Prime University Bangladesh Verification System.
        Reference: <?= h($ref_no) ?> &nbsp;|&nbsp;
        <?= $is_digital ? 'Digital Signed Version' : 'Hand Signed Version' ?> &nbsp;|&nbsp;
        Generated: <?= h($date_str) ?>
    </div>

</div><!-- /cert-doc -->
</div><!-- /cert-page -->
<?php endif; ?>

</body>
</html>
