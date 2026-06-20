<?php
/**
 * Public Result Print / PDF Page
 * Opened in a new tab from the certificate-verification page.
 * User can File → Print → Save as PDF.
 */

require_once __DIR__ . '/includes/config.php';

// ── Input validation ──────────────────────────────────────────────────────────
$sid = trim($_GET['sid'] ?? '');
if ($sid === '' || !preg_match('/\A[A-Za-z0-9\-]{1,30}\z/', $sid)) {
    http_response_code(400);
    exit('Invalid or missing student ID.');
}

// ── Database lookup ───────────────────────────────────────────────────────────
$student     = null;
$result_info = null;

$db = front_db();
if ($db) {
    try {
        $stmt = $db->prepare(
            'SELECT s.id, s.student_id, s.full_name, s.batch,
                    s.admitted_semester, s.status, s.photo,
                    d.name  AS dept_name,
                    p.program_name
             FROM   students s
             JOIN   dept_departments d ON d.id = s.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = s.program_id
             WHERE  s.student_id = ?
             LIMIT  1'
        );
        $stmt->execute([$sid]);
        $student = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $student = null;
    }

    if ($student) {
        // Most recent published exam
        try {
            $res_stmt = $db->prepare(
                'SELECT re.completion_semester, re.updated_at
                 FROM   result_grades rg
                 JOIN   result_exams re ON re.id = rg.exam_id
                 WHERE  rg.student_sid = ?
                   AND  re.is_published = 1
                 ORDER  BY re.updated_at DESC
                 LIMIT  1'
            );
            $res_stmt->execute([$student['student_id']]);
            $exam_row = $res_stmt->fetch();
        } catch (Throwable $e) {
            $exam_row = null;
        }

        // Fallback to student_results
        if (!$exam_row) {
            try {
                $sr_stmt = $db->prepare(
                    'SELECT semester, semester_year, recorded_date
                     FROM   student_results
                     WHERE  student_id = ?
                     ORDER  BY recorded_date DESC, semester_year DESC
                     LIMIT  1'
                );
                $sr_stmt->execute([$student['id']]);
                $sr_row = $sr_stmt->fetch();
                if ($sr_row) {
                    $parts = array_filter([$sr_row['semester'] ?? '', $sr_row['semester_year'] ?? '']);
                    $result_info = [
                        'ending_semester' => $parts ? implode(' ', $parts) : null,
                        'publish_date'    => $sr_row['recorded_date'] ? date('d M Y', strtotime($sr_row['recorded_date'])) : null,
                        'final_cgpa'      => null,
                    ];
                }
            } catch (Throwable $e) {}
        } else {
            $result_info = [
                'ending_semester' => $exam_row['completion_semester'] ?? null,
                'publish_date'    => $exam_row['updated_at'] ? date('d M Y', strtotime($exam_row['updated_at'])) : null,
                'final_cgpa'      => null,
            ];
        }

        // Compute Final CGPA
        try {
            $cgpa_stmt = $db->prepare(
                'SELECT ROUND(
                     SUM(rg.grade_point * COALESCE(rs.credits, 3)) /
                     NULLIF(SUM(COALESCE(rs.credits, 3)), 0), 2
                 ) AS cgpa
                 FROM   result_grades   rg
                 JOIN   result_exams    re ON re.id = rg.exam_id
                 JOIN   result_subjects rs ON rs.id = rg.subject_id
                 WHERE  rg.student_sid     = ?
                   AND  re.is_published    = 1
                   AND  rg.grade_point     IS NOT NULL
                   AND  COALESCE(rs.credits, 3) > 0'
            );
            $cgpa_stmt->execute([$student['student_id']]);
            $cgpa_val = $cgpa_stmt->fetchColumn();
            if ($cgpa_val !== null && $cgpa_val !== false) {
                if ($result_info === null) {
                    $result_info = ['ending_semester' => null, 'publish_date' => null, 'final_cgpa' => null];
                }
                $result_info['final_cgpa'] = number_format((float)$cgpa_val, 2);
            }
        } catch (Throwable $e) {}

        // Fallback CGPA from student_results
        if ($result_info === null || $result_info['final_cgpa'] === null) {
            try {
                $sr_cgpa_stmt = $db->prepare(
                    'SELECT MAX(CAST(cgpa AS DECIMAL(5,2))) AS final_cgpa
                     FROM   student_results
                     WHERE  student_id = ?
                       AND  cgpa IS NOT NULL
                       AND  TRIM(cgpa) != \'\''
                );
                $sr_cgpa_stmt->execute([$student['id']]);
                $sr_cgpa = $sr_cgpa_stmt->fetchColumn();
                if ($sr_cgpa !== null && (float)$sr_cgpa > 0) {
                    if ($result_info === null) {
                        $result_info = ['ending_semester' => null, 'publish_date' => null, 'final_cgpa' => null];
                    }
                    $result_info['final_cgpa'] = number_format((float)$sr_cgpa, 2);
                }
            } catch (Throwable $e) {}
        }
    }
}

if (!$student) {
    http_response_code(404);
    exit('Student record not found.');
}

// ── Photo URL ─────────────────────────────────────────────────────────────────
function rp_photo_url(?string $photo): string
{
    if (!$photo) return '';
    if (!preg_match('/\A[A-Za-z0-9_\-]+\.[a-z]{2,5}\z/', $photo)) return '';
    $new_path = __DIR__ . '/admin/uploads/students/photos/' . $photo;
    if (is_file($new_path)) {
        return ADMIN_UPLOAD_URL . '/students/photos/' . rawurlencode($photo);
    }
    return SITE_URL . '/upload_spic/' . rawurlencode($photo);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$print_date = date('d F Y');
$print_time = date('H:i');
$ref_no     = 'PU-RES-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $student['student_id']));

$st_status  = $student['status'] ?? '';
$is_grad    = ($st_status === 'Graduated');

$logo_url  = SITE_URL . '/assets/img/logo/logo-black.png';
$photo_url = rp_photo_url($student['photo']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Academic Result – <?= fh($student['full_name']) ?> | Prime University</title>
    <style>
        /* ── Reset & base ─────────────────────────────────────────────────── */
        @page { size: A4 portrait; margin: 15mm 18mm; }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #1a1a2e;
            margin: 0;
            background: #f0f4f8;
        }

        /* ── Screen toolbar ───────────────────────────────────────────────── */
        .toolbar {
            background: #1a2e5a;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .toolbar-title {
            color: #fff;
            font-size: .95rem;
            font-weight: 600;
        }
        .toolbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: .88rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: opacity .2s, transform .2s;
        }
        .btn-print:hover { opacity: .88; transform: translateY(-2px); color: #fff; }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.12);
            color: rgba(255,255,255,.9);
            border: 1px solid rgba(255,255,255,.25);
            border-radius: 8px;
            padding: 10px 20px;
            font-size: .88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .2s;
        }
        .btn-back:hover { background: rgba(255,255,255,.2); color: #fff; }

        /* ── Document wrapper ─────────────────────────────────────────────── */
        .doc-outer {
            max-width: 780px;
            margin: 24px auto 40px;
            padding: 0 16px;
        }
        .document {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 60px rgba(0,0,0,.13);
            overflow: hidden;
        }

        /* ── Decorative top band ──────────────────────────────────────────── */
        .doc-topband {
            height: 7px;
            background: linear-gradient(90deg, #1a2e5a 0%, #2563eb 50%, #10b981 100%);
        }

        /* ── University header ────────────────────────────────────────────── */
        .doc-header {
            padding: 28px 40px 22px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
            border-bottom: 2px solid #e8edf5;
            position: relative;
        }
        .doc-header-info { width: 100%; }
        .doc-header-info .uni-name {
            font-size: 17pt;
            font-weight: 800;
            color: #1a2e5a;
            letter-spacing: -.01em;
            margin: 0 0 4px;
            line-height: 1.2;
        }
        .doc-header-info .uni-tagline {
            font-size: 8.5pt;
            color: #2563eb;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin: 0 0 8px;
        }
        .doc-header-info .uni-contact {
            font-size: 8.5pt;
            color: #4b5563;
            line-height: 2;
            margin: 0;
        }
        .doc-header-info .uni-contact .contact-line {
            display: block;
        }
        .doc-header-info .uni-contact i { margin-right: 4px; color: #2563eb; }
        .doc-header-watermark {
            position: absolute;
            right: 36px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 58pt;
            font-weight: 900;
            color: rgba(26,46,90,.035);
            letter-spacing: -.05em;
            pointer-events: none;
            user-select: none;
            white-space: nowrap;
        }

        /* ── Document title bar ───────────────────────────────────────────── */
        .doc-titlebar {
            background: linear-gradient(135deg, #1a2e5a 0%, #1e3a8a 100%);
            padding: 16px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .doc-titlebar .doc-title {
            font-size: 13pt;
            font-weight: 800;
            color: #fff;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin: 0;
        }
        .doc-titlebar .doc-ref {
            font-size: 8pt;
            color: rgba(255,255,255,.7);
            font-family: 'Courier New', monospace;
            letter-spacing: .04em;
        }

        /* ── Body ─────────────────────────────────────────────────────────── */
        .doc-body { padding: 30px 40px 36px; }

        /* ── Student snapshot ─────────────────────────────────────────────── */
        .student-snapshot {
            display: flex;
            gap: 22px;
            align-items: flex-start;
            background: #f8faff;
            border: 1.5px solid #dbe4f3;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        .student-photo {
            width: 100px;
            height: 120px;
            object-fit: cover;
            border-radius: 10px;
            border: 2.5px solid #cbd5e1;
            flex-shrink: 0;
        }
        .student-photo-placeholder {
            width: 100px;
            height: 120px;
            border-radius: 10px;
            background: linear-gradient(135deg, #dbeafe, #e0f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.6rem;
            color: #93c5fd;
            flex-shrink: 0;
            border: 2.5px solid #cbd5e1;
        }
        .student-info { flex: 1; min-width: 0; }
        .student-name {
            font-size: 15pt;
            font-weight: 800;
            color: #1a2e5a;
            margin: 0 0 6px;
            word-break: break-word;
        }
        .student-id-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #1a2e5a;
            color: #fff;
            border-radius: 6px;
            padding: 4px 12px;
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: .05em;
            margin-bottom: 10px;
        }
        .student-meta { font-size: 9pt; color: #4b5563; line-height: 1.9; margin: 0; }
        .student-meta strong { color: #1a2e5a; }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 13px;
            border-radius: 50px;
            font-size: 8.5pt;
            font-weight: 700;
            margin-top: 8px;
        }
        .status-pill.graduated { background: #d1fae5; color: #065f46; }
        .status-pill.active    { background: #dbeafe; color: #1d4ed8; }
        .status-pill.dropped   { background: #fee2e2; color: #b91c1c; }
        .status-pill.inactive  { background: #f3f4f6; color: #6b7280; }

        /* ── Section heading ──────────────────────────────────────────────── */
        .section-heading {
            font-size: 9.5pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #2563eb;
            margin: 0 0 12px;
            padding-bottom: 7px;
            border-bottom: 1.5px solid #dbe4f3;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-heading::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 16px;
            background: linear-gradient(180deg, #2563eb, #10b981);
            border-radius: 3px;
            flex-shrink: 0;
        }

        /* ── Info grid ────────────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .info-cell {
            background: #f8fafc;
            border: 1px solid #e8edf5;
            border-radius: 10px;
            padding: 13px 15px;
        }
        .info-cell .ic-label {
            font-size: 7.5pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .info-cell .ic-value {
            font-size: 10pt;
            font-weight: 700;
            color: #1a2e5a;
            word-break: break-word;
        }
        .info-cell.highlight {
            background: linear-gradient(135deg, #1a2e5a, #1e3a8a);
            border-color: transparent;
        }
        .info-cell.highlight .ic-label { color: rgba(255,255,255,.65); }
        .info-cell.highlight .ic-value { color: #fff; font-size: 12pt; }

        /* ── CGPA highlight ───────────────────────────────────────────────── */
        .cgpa-block {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border: 1.5px solid #86efac;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .cgpa-circle {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, #059669, #10b981);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(16,185,129,.3);
        }
        .cgpa-circle .cgpa-num {
            font-size: 15pt;
            font-weight: 900;
            color: #fff;
            line-height: 1;
        }
        .cgpa-circle .cgpa-denom {
            font-size: 7pt;
            color: rgba(255,255,255,.8);
            margin-top: 1px;
        }
        .cgpa-text .cgpa-title {
            font-size: 9pt;
            font-weight: 800;
            color: #065f46;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin: 0 0 4px;
        }
        .cgpa-text .cgpa-desc {
            font-size: 8.5pt;
            color: #374151;
            margin: 0;
            line-height: 1.5;
        }

        /* ── Verification seal ────────────────────────────────────────────── */
        .seal-row {
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border: 1.5px solid #6ee7b7;
            border-radius: 12px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 24px;
        }
        .seal-icon {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, #059669, #047857);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #fff;
            flex-shrink: 0;
        }
        .seal-text { flex: 1; }
        .seal-text .seal-title {
            font-size: 10pt;
            font-weight: 800;
            color: #065f46;
            margin: 0 0 3px;
        }
        .seal-text .seal-sub {
            font-size: 8pt;
            color: #4b5563;
            margin: 0;
        }
        .seal-date {
            font-size: 8pt;
            color: #4b5563;
            text-align: right;
            white-space: nowrap;
        }
        .seal-date strong { display: block; color: #1a2e5a; font-size: 9pt; }

        /* ── Footer ───────────────────────────────────────────────────────── */
        .doc-footer {
            background: #f8fafc;
            border-top: 1.5px solid #e8edf5;
            padding: 14px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 4px;
        }
        .doc-footer .footer-note {
            font-size: 7.5pt;
            color: #9ca3af;
            line-height: 1.8;
        }
        .doc-footer .footer-ref {
            font-size: 7.5pt;
            color: #9ca3af;
            font-family: 'Courier New', monospace;
            text-align: center;
        }

        /* ── Print overrides ─────────────────────────────────────────────── */
        @media print {
            @page { size: A4 portrait; margin: 8mm 10mm; }
            body { background: #fff; font-size: 9.5pt; }
            .toolbar { display: none !important; }
            .doc-outer { max-width: 100%; margin: 0; padding: 0; }
            .document {
                border-radius: 0;
                box-shadow: none;
            }
            .doc-topband { -webkit-print-color-adjust: exact; print-color-adjust: exact; height: 5px; }
            .doc-header, .doc-titlebar, .doc-body, .doc-footer {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .info-cell, .cgpa-block, .seal-row, .student-snapshot {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .doc-header { padding: 12px 24px 10px; gap: 6px; }
            .doc-header-info .uni-name { font-size: 14pt; }
            .doc-header-info .uni-tagline { font-size: 7.5pt; margin-bottom: 4px; }
            .doc-header-info .uni-contact { font-size: 7.5pt; line-height: 1.7; }
            .doc-titlebar { padding: 8px 24px; }
            .doc-titlebar .doc-title { font-size: 11pt; }
            .doc-body { padding: 12px 24px 14px; }
            .student-snapshot { padding: 12px 16px; margin-bottom: 12px; gap: 14px; }
            .student-photo,
            .student-photo-placeholder { width: 76px; height: 92px; }
            .student-name { font-size: 12pt; margin-bottom: 4px; }
            .student-meta { line-height: 1.6; }
            .section-heading { margin: 0 0 8px; padding-bottom: 5px; }
            .info-grid { gap: 8px; margin-bottom: 12px; }
            .info-cell { padding: 9px 12px; }
            .cgpa-block { padding: 10px 16px; margin-bottom: 12px; gap: 12px; }
            .cgpa-circle { width: 58px; height: 58px; }
            .cgpa-circle .cgpa-num { font-size: 13pt; }
            .seal-row { padding: 8px 14px; margin-bottom: 12px; gap: 10px; }
            .seal-icon { width: 38px; height: 38px; font-size: 1.1rem; }
            .doc-footer { padding: 8px 24px; gap: 2px; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar (hidden on print) ──────────────────────────────────── -->
<div class="toolbar">
    <div class="toolbar-title">
        &#128247; Academic Result — <?= fh($student['full_name']) ?>
    </div>
    <div class="toolbar-actions">
        <button class="btn-print" onclick="window.print()">
            &#128438; Download / Print PDF
        </button>
        <a href="<?= fh(SITE_URL) ?>/certificate-verification.php" class="btn-back">
            &#8592; Back to Verification
        </a>
    </div>
</div>

<!-- ── Document ──────────────────────────────────────────────────────────── -->
<div class="doc-outer">
<div class="document">

    <!-- Top colour band -->
    <div class="doc-topband"></div>

    <!-- University header -->
    <div class="doc-header">
        <img src="<?= fh($logo_url) ?>"
             alt="Prime University"
             class="doc-header-logo"
             onerror="this.style.display='none'">
        <div class="doc-header-info">
            <div class="uni-tagline">Bangladesh — UGC Approved</div>
            <div class="uni-contact">
                <span class="contact-line">&#128205; 114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh</span>
                <span class="contact-line">&#128222; PABX: +88-02-41002432 &nbsp;|&nbsp; +88-02-41002435 &nbsp;|&nbsp; 01969-955566</span>
                <span class="contact-line">&#127760; www.primeuniversity.ac.bd</span>
            </div>
        </div>
        <div class="doc-header-watermark">PU</div>
    </div>

    <!-- Title bar -->
    <div class="doc-titlebar">
        <div class="doc-title">&#127891; Official Academic Result</div>
        <div class="doc-ref">Ref: <?= fh($ref_no) ?></div>
    </div>

    <!-- Body -->
    <div class="doc-body">

        <!-- Student snapshot -->
        <div class="student-snapshot">
            <?php if ($photo_url): ?>
            <img src="<?= fh($photo_url) ?>"
                 alt="Photo of <?= fh($student['full_name']) ?>"
                 class="student-photo">
            <?php else: ?>
            <div class="student-photo-placeholder">&#127891;</div>
            <?php endif; ?>

            <div class="student-info">
                <div class="student-name"><?= fh($student['full_name']) ?></div>
                <div class="student-id-tag">
                    &#128196; <?= fh($student['student_id']) ?>
                </div>
                <div class="student-meta">
                    <strong>Department:</strong> <?= fh($student['dept_name']) ?><br>
                    <?php if ($student['program_name']): ?>
                    <strong>Program:</strong> <?= fh($student['program_name']) ?><br>
                    <?php endif; ?>
                    <strong>Batch:</strong> <?= $student['batch'] ? fh($student['batch']) : '—' ?> &nbsp;&nbsp;
                    <strong>Admitted:</strong> <?= fh($student['admitted_semester']) ?>
                </div>
                <?php
                $badge_cls  = match($st_status) { 'Graduated' => 'graduated', 'Active' => 'active', 'Dropped' => 'dropped', default => 'inactive' };
                $badge_icon = match($st_status) { 'Graduated' => '&#127891;', 'Active' => '&#9989;', 'Dropped' => '&#10060;', default => '&#9899;' };
                ?>
                <span class="status-pill <?= $badge_cls ?>">
                    <?= $badge_icon ?> <?= fh($st_status) ?>
                </span>
            </div>
        </div>

        <!-- CGPA block (if available) -->
        <?php if (!empty($result_info['final_cgpa'])): ?>
        <div class="cgpa-block">
            <div class="cgpa-circle">
                <span class="cgpa-num"><?= fh($result_info['final_cgpa']) ?></span>
                <span class="cgpa-denom">/ 4.00</span>
            </div>
            <div class="cgpa-text">
                <div class="cgpa-title">&#127942; Final Cumulative GPA (CGPA)</div>
                <div class="cgpa-desc">
                    Computed from all published examination results on record.<br>
                    <?php if (!empty($result_info['ending_semester'])): ?>
                    Ending Semester: <strong><?= fh($result_info['ending_semester']) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Academic details section -->
        <div class="section-heading">Academic Details</div>
        <div class="info-grid">
            <div class="info-cell">
                <div class="ic-label">Student ID</div>
                <div class="ic-value"><?= fh($student['student_id']) ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Full Name</div>
                <div class="ic-value"><?= fh($student['full_name']) ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Status</div>
                <div class="ic-value"><?= fh($st_status ?: '—') ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Department</div>
                <div class="ic-value"><?= fh($student['dept_name']) ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Program / Degree</div>
                <div class="ic-value"><?= $student['program_name'] ? fh($student['program_name']) : '—' ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Batch</div>
                <div class="ic-value"><?= $student['batch'] ? fh($student['batch']) : '—' ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Enrolled Semester</div>
                <div class="ic-value"><?= fh($student['admitted_semester']) ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Ending Semester</div>
                <div class="ic-value"><?= !empty($result_info['ending_semester']) ? fh($result_info['ending_semester']) : '—' ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Result Published</div>
                <div class="ic-value"><?= !empty($result_info['publish_date']) ? fh($result_info['publish_date']) : '—' ?></div>
            </div>
            <div class="info-cell">
                <div class="ic-label">Graduated</div>
                <div class="ic-value"><?= $is_grad ? '&#10003; Yes' : 'No' ?></div>
            </div>
            <?php if (!empty($result_info['final_cgpa'])): ?>
            <div class="info-cell highlight">
                <div class="ic-label">Final CGPA</div>
                <div class="ic-value"><?= fh($result_info['final_cgpa']) ?> / 4.00</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Verification seal -->
        <div class="seal-row">
            <div class="seal-icon">&#9989;</div>
            <div class="seal-text">
                <div class="seal-title">Digitally Verified by Prime University</div>
                <div class="seal-sub">
                    This document was generated from official Prime University student records.
                    Verify online at: <strong>primeuniversity.ac.bd/certificate-verification</strong>
                </div>
            </div>
            <div class="seal-date">
                <strong>Printed On</strong>
                <?= fh($print_date) ?><br>
                <?= fh($print_time) ?> (BST)
            </div>
        </div>

    </div>
    <!-- Body end -->

    <!-- Footer -->
    <div class="doc-footer">
        <div class="footer-note">
            &#128205; 114/116 Mazar Road, Mirpur-1, Dhaka 1216, Bangladesh &nbsp;|&nbsp;
            PABX: +88-02-41002432 &nbsp;/&nbsp; +88-02-41002435 &nbsp;|&nbsp; 01969-955566<br>
            This is a computer-generated document. For certified copies contact verification@primeuniversity.ac.bd
        </div>
        <div class="footer-ref">
            <?= fh($ref_no) ?> &nbsp;|&nbsp; Generated: <?= fh($print_date) ?>
        </div>
    </div>

</div><!-- .document -->
</div><!-- .doc-outer -->

</body>
</html>
