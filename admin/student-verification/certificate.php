<?php
/**
 * Student Verification Certificate – Printable / PDF-ready
 * Opened in a new tab; user can File → Print (Save as PDF).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-verification');

$id   = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT sv.*,
            s.student_id AS s_student_id, s.full_name AS s_full_name,
            s.email AS s_email, s.phone AS s_phone,
            d.name AS dept_name,
            p.program_name,
            s.admitted_semester,
            s.batch,
            s.status AS s_status,
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

$verified  = $rec['overall_status'] === 'Verified';
$date_str  = date('d F Y', strtotime($rec['created_at']));
$time_str  = date('H:i', strtotime($rec['created_at']));
$ref_no    = 'PU-VER-' . str_pad($id, 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verification Certificate – <?= h($rec['s_full_name']) ?></title>
    <style>
        @page { size: A4; margin: 20mm; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Arial', sans-serif;
            font-size: 11pt;
            color: #222;
            margin: 0;
            background: #fff;
        }
        .cert-wrap {
            max-width: 720px;
            margin: 0 auto;
            border: 2px solid #002147;
            border-radius: 4px;
            padding: 32px 40px;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            border-bottom: 2px solid #002147;
            padding-bottom: 18px;
            margin-bottom: 22px;
        }
        .header img { width: 70px; }
        .header-text h1 {
            margin: 0;
            font-size: 16pt;
            color: #002147;
            font-weight: 700;
        }
        .header-text p { margin: 2px 0 0; font-size: 9pt; color: #555; }
        .cert-title {
            text-align: center;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: #002147;
            margin-bottom: 20px;
        }
        .ref-row {
            display: flex;
            justify-content: space-between;
            font-size: 9pt;
            color: #666;
            margin-bottom: 18px;
        }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.info td { padding: 6px 10px; border: 1px solid #ddd; font-size: 10pt; }
        table.info td:first-child { background: #f5f7fa; font-weight: 600; width: 38%; }
        .checks-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .checks-table th { background: #002147; color: #fff; padding: 7px 10px; font-size: 10pt; text-align: left; }
        .checks-table td { padding: 7px 10px; border: 1px solid #ddd; font-size: 10pt; }
        .check-yes { color: #155724; font-weight: 600; }
        .check-no  { color: #721c24; font-weight: 600; }
        .status-box {
            text-align: center;
            padding: 14px;
            border-radius: 6px;
            font-size: 14pt;
            font-weight: 700;
            margin: 20px 0;
        }
        .status-verified { background: #d4edda; color: #155724; border: 1.5px solid #c3e6cb; }
        .status-failed   { background: #f8d7da; color: #721c24; border: 1.5px solid #f5c6cb; }
        .failed-note { font-size: 9pt; color: #555; margin-top: 10px; padding: 10px 14px; background: #fff3cd; border-left: 3px solid #ffc107; border-radius: 3px; }
        .signature-area {
            display: flex;
            justify-content: space-between;
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #ddd;
        }
        .sig-block { text-align: center; width: 45%; }
        .sig-line { border-bottom: 1.5px solid #555; height: 40px; margin-bottom: 6px; }
        .sig-label { font-size: 9pt; color: #555; }
        .footer-note {
            text-align: center;
            font-size: 8pt;
            color: #888;
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
        @media print {
            .no-print { display: none !important; }
            .cert-wrap { border: none; padding: 0; }
        }
    </style>
</head>
<body>

<!-- Print button (hidden in print) -->
<div class="no-print" style="text-align:center;padding:16px 0;background:#f4f6fb;">
    <button onclick="window.print()" style="padding:9px 24px;background:#002147;color:#fff;border:none;border-radius:8px;font-size:13px;cursor:pointer;">
        🖨 Print / Save as PDF
    </button>
    <a href="<?= APP_URL ?>/student-verification/view.php?id=<?= $id ?>"
       style="margin-left:12px;color:#555;font-size:13px;">← Back</a>
</div>

<div class="cert-wrap">
    <!-- Header -->
    <div class="header">
        <img src="<?= defined('LOGO_URL') ? LOGO_URL : SITE_URL . '/assets/img/logo/logo-black.png' ?>" alt="Prime University"
             onerror="this.style.display='none'">
        <div class="header-text">
            <h1>Prime University Bangladesh</h1>
            <p>House 28, Road 11, Sector 06, Uttara, Dhaka-1230</p>
            <p>www.primeuniversity.ac.bd &nbsp;|&nbsp; verification@primeuniversity.ac.bd</p>
        </div>
    </div>

    <div class="cert-title">Student Verification Certificate</div>

    <div class="ref-row">
        <span><strong>Ref:</strong> <?= h($ref_no) ?></span>
        <span><strong>Date:</strong> <?= h($date_str) ?> at <?= h($time_str) ?></span>
    </div>

    <!-- Student info -->
    <table class="info">
        <tr><td>Student Full Name</td><td><strong><?= h($rec['s_full_name']) ?></strong></td></tr>
        <tr><td>Student ID</td><td><?= h($rec['s_student_id']) ?></td></tr>
        <tr><td>Department</td><td><?= h($rec['dept_name']) ?></td></tr>
        <?php if ($rec['program_name']): ?>
        <tr><td>Obtained Degree</td><td><?= h($rec['program_name']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Enrolled Semester</td><td><?= h($rec['admitted_semester']) ?></td></tr>
        <tr><td>Ending Semester</td><td><?= $cert_ending_sem ? h($cert_ending_sem) : '—' ?></td></tr>
        <?php if ($rec['batch']): ?>
        <tr><td>Batch</td><td><?= h($rec['batch']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Graduated</td><td><?= (($rec['s_status'] ?? '') === 'Graduated') ? '<strong style="color:#155724;">Yes</strong>' : 'No' ?></td></tr>
        <?php if ($cert_cgpa): ?>
        <tr><td>Final CGPA</td><td><strong><?= h($cert_cgpa) ?></strong></td></tr>
        <?php endif; ?>
        <tr><td>Verified By</td><td><?= h($rec['verifier_name']) ?></td></tr>
    </table>

    <!-- Checks -->
    <table class="checks-table">
        <thead>
            <tr>
                <th style="width:60%;">Verification Check</th>
                <th style="width:15%;">Result</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Certificate &amp; Transcript – Visual Security Measures</td>
                <td class="<?= $rec['cert_transcript_ok'] ? 'check-yes' : 'check-no' ?>">
                    <?= $rec['cert_transcript_ok'] ? '✔ Pass' : '✘ Fail' ?>
                </td>
                <td><?= $rec['cert_transcript_ok'] ? 'Verified' : h($rec['cert_transcript_issues'] ?? '') ?></td>
            </tr>
            <tr>
                <td>Admission Form Check (Scanned Document)</td>
                <td class="<?= $rec['admission_form_ok'] ? 'check-yes' : 'check-no' ?>">
                    <?= $rec['admission_form_ok'] ? '✔ Pass' : '✘ Fail' ?>
                </td>
                <td><?= $rec['admission_form_ok'] ? 'Verified' : h($rec['admission_form_issues'] ?? '') ?></td>
            </tr>
            <tr>
                <td>Final Result Tabulation Check</td>
                <td class="<?= $rec['tabulation_ok'] ? 'check-yes' : 'check-no' ?>">
                    <?= $rec['tabulation_ok'] ? '✔ Pass' : '✘ Fail' ?>
                </td>
                <td><?= $rec['tabulation_ok'] ? 'Verified' : h($rec['tabulation_issues'] ?? '') ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Status -->
    <div class="status-box <?= $verified ? 'status-verified' : 'status-failed' ?>">
        <?= $verified ? '✔ VERIFIED' : '✘ VERIFICATION FAILED' ?>
    </div>

    <?php if (!$verified): ?>
    <div class="failed-note">
        <strong>Note:</strong> This student's credentials could not be fully verified. If you have questions,
        please visit Prime University Bangladesh at House 28, Road 11, Sector 06, Uttara, Dhaka-1230
        for further assistance.
    </div>
    <?php endif; ?>

    <!-- Signature area -->
    <div class="signature-area">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Authorised Signatory<br>Prime University Bangladesh</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-label">Registrar / Controller of Examinations<br>Prime University Bangladesh</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer-note">
        This certificate was generated by the Prime University Bangladesh Verification System.
        Reference: <?= h($ref_no) ?> &nbsp;|&nbsp; Verified on: <?= h($date_str) ?>
    </div>
</div>

</body>
</html>
