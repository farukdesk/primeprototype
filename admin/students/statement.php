<?php
/**
 * Student Enrollment Statement – Standalone print page (no admin layout).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('students');

$id      = (int)($_GET['id'] ?? 0);
$student = sm_get_student($id);

// Academic qualifications
$quals_stmt = db()->prepare(
    'SELECT q.*,
            et.name  AS exam_title_name,
            b.name   AS board_name,
            g.name   AS group_ref_name
     FROM student_academic_qualifications q
     LEFT JOIN student_exam_titles et ON et.id = q.exam_title_id
     LEFT JOIN student_boards b ON b.id = q.board_id
     LEFT JOIN student_groups g ON g.id = q.group_id
     WHERE q.student_id = ? ORDER BY q.sort_order ASC, q.id ASC'
);
$quals_stmt->execute([$id]);
$qualifications = $quals_stmt->fetchAll();

$page_title = 'Statement – ' . $student['full_name'];
$date_today = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; background: #f0f2f5; color: #222; }

        /* ── Screen toolbar ── */
        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #1e3a5f; color: #fff; padding: 10px 20px;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
        }
        .screen-controls button, .screen-controls a {
            background: #2563eb; color: #fff; border: none;
            padding: 6px 18px; border-radius: 5px; cursor: pointer;
            font-size: 13px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .screen-controls a.back-btn { background: #64748b; }
        .screen-controls span { font-size: 13px; opacity: 0.85; }

        .print-wrapper { padding: 70px 20px 40px; }

        /* ── Statement page ── */
        .statement-page {
            background: #fff;
            width: 794px;
            min-height: 1123px;
            padding: 36px 48px 40px;
            margin: 0 auto 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }

        /* ── University header ── */
        .univ-header {
            text-align: center;
            border-bottom: 2px solid #1e3a5f;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .univ-header img.logo {
            height: 52px; margin-bottom: 4px; display: block; margin-left: auto; margin-right: auto;
        }
        .univ-name {
            font-size: 17px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #1e3a5f;
        }
        .univ-sub {
            font-size: 10px; color: #555; margin-top: 2px;
        }
        .doc-title {
            text-align: center; font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            background: #1e3a5f; color: #fff;
            padding: 6px 0; margin-bottom: 14px;
        }

        /* ── Photo & basic info row ── */
        .top-row { display: flex; gap: 18px; margin-bottom: 14px; }
        .photo-box {
            flex-shrink: 0;
            width: 90px; height: 110px;
            border: 1px solid #bbb; overflow: hidden;
        }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }
        .photo-placeholder {
            width: 90px; height: 110px;
            border: 1px solid #bbb;
            display: flex; align-items: center; justify-content: center;
            color: #aaa; font-size: 28px; flex-shrink: 0;
        }
        .basic-info { flex: 1; }
        .basic-info table { width: 100%; border-collapse: collapse; }
        .basic-info table td { padding: 3px 6px; font-size: 11px; vertical-align: top; }
        .basic-info table td:first-child { color: #555; width: 38%; font-weight: 600; }
        .basic-info table td:last-child { font-weight: 500; }

        /* ── Section heading ── */
        .sec-heading {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; background: #eef2f8; color: #1e3a5f;
            padding: 4px 8px; margin: 12px 0 6px;
            border-left: 3px solid #2563eb;
        }

        /* ── Info grid ── */
        .info-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 2px 16px;
        }
        .info-row {
            display: flex; gap: 6px; font-size: 11px;
            padding: 2px 4px; border-bottom: 1px dotted #e0e0e0;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #555; min-width: 120px; flex-shrink: 0; font-weight: 600; }
        .info-value { font-weight: 500; color: #111; }

        /* ── Fee table ── */
        .fee-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 6px; }
        .fee-table th { background: #eef2f8; color: #1e3a5f; padding: 5px 8px; text-align: left; border: 1px solid #ccc; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }
        .fee-table td { border: 1px solid #ddd; padding: 5px 8px; vertical-align: middle; }
        .fee-table tr.total-row td { background: #f0f4ff; font-weight: 700; }
        .fee-table tr.payable-row td { background: #1e3a5f; color: #fff; font-weight: 700; font-size: 12px; }
        .fee-table td.amt { text-align: right; }
        .fee-table td.desc { color: #444; font-size: 10px; padding-top: 1px; }

        /* ── Qual table ── */
        .qual-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-top: 6px; }
        .qual-table th { background: #eef2f8; color: #1e3a5f; padding: 4px 6px; border: 1px solid #ccc; text-align: left; font-size: 10px; }
        .qual-table td { border: 1px solid #ddd; padding: 4px 6px; vertical-align: top; }

        /* ── Footer ── */
        .stmt-footer {
            margin-top: 30px;
            display: flex; justify-content: space-between; align-items: flex-end;
        }
        .stmt-footer .sig-block { text-align: center; }
        .stmt-footer .sig-line { border-top: 1px solid #555; margin-top: 40px; padding-top: 4px; font-size: 10px; color: #555; min-width: 140px; }
        .date-issued { font-size: 10px; color: #666; }

        /* ── Notice box ── */
        .notice-box {
            border: 1px solid #ccc; padding: 8px 12px; margin-top: 14px;
            font-size: 10px; color: #555; background: #fafafa;
        }

        @media print {
            .screen-controls { display: none !important; }
            body { background: #fff; }
            .print-wrapper { padding: 0; }
            .statement-page { box-shadow: none; margin: 0; min-height: unset; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ── -->
<div class="screen-controls">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= APP_URL ?>/students/view.php?id=<?= $id ?>" class="back-btn">← Back to Profile</a>
    <span><?= h($student['student_id']) ?> — <?= h($student['full_name']) ?></span>
</div>

<div class="print-wrapper">
<div class="statement-page">

    <!-- ── University Header ── -->
    <div class="univ-header">
        <img src="<?= LOGO_URL ?>" alt="Prime University Logo" class="logo"
             onerror="this.style.display='none'">
        <div class="univ-name">Prime University</div>
        <div class="univ-sub">House 28, Road 6, Mirpur-2, Dhaka-1216 | primeuniversity.ac.bd</div>
    </div>

    <div class="doc-title">Student Enrollment Statement</div>

    <!-- ── Photo + Basic Info ── -->
    <div class="top-row">
        <?php if ($student['photo']): ?>
        <div class="photo-box">
            <img src="<?= sm_photo_url($student['photo']) ?>" alt="Photo">
        </div>
        <?php else: ?>
        <div class="photo-placeholder">👤</div>
        <?php endif; ?>

        <div class="basic-info">
            <table>
                <tr>
                    <td>Student ID</td>
                    <td><strong><?= h($student['student_id']) ?></strong></td>
                </tr>
                <tr>
                    <td>Student Name</td>
                    <td><strong><?= h($student['full_name']) ?></strong></td>
                </tr>
                <tr>
                    <td>Department</td>
                    <td><?= h($student['dept_name']) ?></td>
                </tr>
                <?php if ($student['program_name']): ?>
                <tr>
                    <td>Program</td>
                    <td><?= h($student['program_name']) ?><?= $student['program_type'] ? ' (' . h($student['program_type']) . ')' : '' ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Admitted Semester</td>
                    <td><?= h($student['admitted_semester']) ?></td>
                </tr>
                <?php if ($student['batch_name'] ?? $student['batch']): ?>
                <tr>
                    <td>Batch</td>
                    <td><?= h($student['batch_name'] ?? $student['batch']) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($student['shift']): ?>
                <tr>
                    <td>Shift</td>
                    <td><?= h($student['shift']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Status</td>
                    <td><?= h($student['status']) ?></td>
                </tr>
                <?php if ($student['ref_number']): ?>
                <tr>
                    <td>Reference No</td>
                    <td><?= h($student['ref_number']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ── Personal Information ── -->
    <div class="sec-heading">Personal Information</div>
    <div class="info-grid">
        <?php
        $personal = [
            'Date of Birth'    => $student['dob'] ? date('d/m/Y', strtotime($student['dob'])) : null,
            'Place of Birth'   => $student['place_of_birth'] ?? null,
            'Sex'              => $student['sex'] ?? null,
            'Blood Group'      => $student['blood_group'] ?? null,
            'NID'              => $student['nid'] ?? null,
            'Religion'         => $student['religion'] ?? null,
            'Nationality'      => $student['nationality'] ?? null,
            'Country'          => (!empty($student['country']) && $student['country'] !== 'Bangladesh') ? $student['country'] : null,
            'District'         => $student['district_name'] ?? null,
            'Thana / Upazila'  => $student['thana_name'] ?? null,
            'Phone'            => $student['phone'] ?? null,
            'Email'            => $student['email'] ?? null,
        ];
        foreach ($personal as $lbl => $val):
            if (!$val) continue;
        ?>
        <div class="info-row">
            <span class="info-label"><?= h($lbl) ?></span>
            <span class="info-value"><?= h($val) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($student['present_address']): ?>
        <div class="info-row" style="grid-column:1/-1;">
            <span class="info-label">Present Address</span>
            <span class="info-value"><?= h($student['present_address']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['permanent_address']): ?>
        <div class="info-row" style="grid-column:1/-1;">
            <span class="info-label">Permanent Address</span>
            <span class="info-value"><?= h($student['permanent_address']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Parents' Information ── -->
    <?php
    $hasFather = ($student['father_name'] || $student['father_phone'] || $student['father_occupation']);
    $hasMother = ($student['mother_name'] || $student['mother_phone'] || $student['mother_occupation']);
    if ($hasFather || $hasMother):
    ?>
    <div class="sec-heading">Parents' Information</div>
    <div class="info-grid">
        <?php if ($student['father_name']): ?>
        <div class="info-row">
            <span class="info-label">Father's Name</span>
            <span class="info-value"><?= h($student['father_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['mother_name']): ?>
        <div class="info-row">
            <span class="info-label">Mother's Name</span>
            <span class="info-value"><?= h($student['mother_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['father_phone']): ?>
        <div class="info-row">
            <span class="info-label">Father's Phone</span>
            <span class="info-value"><?= h($student['father_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['mother_phone']): ?>
        <div class="info-row">
            <span class="info-label">Mother's Phone</span>
            <span class="info-value"><?= h($student['mother_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['father_occupation']): ?>
        <div class="info-row">
            <span class="info-label">Father's Occupation</span>
            <span class="info-value"><?= h($student['father_occupation']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['mother_occupation']): ?>
        <div class="info-row">
            <span class="info-label">Mother's Occupation</span>
            <span class="info-value"><?= h($student['mother_occupation']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['father_yearly_income']): ?>
        <div class="info-row">
            <span class="info-label">Father's Yearly Income</span>
            <span class="info-value">BDT <?= number_format((float)$student['father_yearly_income'], 2) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($student['mother_yearly_income']): ?>
        <div class="info-row">
            <span class="info-label">Mother's Yearly Income</span>
            <span class="info-value">BDT <?= number_format((float)$student['mother_yearly_income'], 2) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Fee Structure ── -->
    <?php
    $hasFees = ($student['form_fee'] || $student['regi_fee'] || $student['tuition_fee']
             || $student['misc_fee']  || $student['project_fee'] || $student['total_fee']
             || $student['total_payable']);
    if ($hasFees):
    ?>
    <div class="sec-heading">Fee Structure</div>
    <table class="fee-table">
        <thead>
            <tr>
                <th style="width:60%">Description</th>
                <th class="amt">Amount (BDT)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($student['form_fee']): ?>
            <tr>
                <td>Form / Admission Fee</td>
                <td class="amt"><?= number_format((float)$student['form_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['regi_fee']): ?>
            <tr>
                <td>Registration Fee</td>
                <td class="amt"><?= number_format((float)$student['regi_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['tuition_fee']): ?>
            <tr>
                <td>Tuition Fee</td>
                <td class="amt"><?= number_format((float)$student['tuition_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['misc_fee']): ?>
            <tr>
                <td>Miscellaneous Fee</td>
                <td class="amt"><?= number_format((float)$student['misc_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['project_fee']): ?>
            <tr>
                <td>Project / Lab Fee</td>
                <td class="amt"><?= number_format((float)$student['project_fee']) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['total_fee']): ?>
            <tr class="total-row">
                <td><strong>Total Fee</strong></td>
                <td class="amt"><strong><?= number_format((float)$student['total_fee']) ?></strong></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['waiver_amount'] || $student['waiver_percent']): ?>
            <tr>
                <td>
                    Waiver / Scholarship
                    <?php if ($student['waiver_percent']): ?>
                    <span class="desc">(<?= h($student['waiver_percent']) ?>%
                    <?php if ($student['poor_meritorious']): ?>– Poor &amp; Meritorious<?php endif; ?>
                    <?php if ($student['freedom_fighter']): ?>– Freedom Fighter Quota<?php endif; ?>
                    )</span>
                    <?php endif; ?>
                </td>
                <td class="amt"><?= $student['waiver_amount'] ? '− ' . number_format((float)$student['waiver_amount']) : '—' ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['total_payable']): ?>
            <tr class="payable-row">
                <td><strong>Total Payable</strong></td>
                <td class="amt"><strong><?= number_format((float)$student['total_payable']) ?></strong></td>
            </tr>
            <?php endif; ?>
            <?php if ($student['monthly_installment']): ?>
            <tr>
                <td>Monthly Installment</td>
                <td class="amt"><?= number_format((float)$student['monthly_installment']) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Academic Qualifications ── -->
    <?php if (!empty($qualifications)): ?>
    <div class="sec-heading">Academic Qualifications</div>
    <table class="qual-table">
        <thead>
            <tr>
                <th>Exam</th>
                <th>Session</th>
                <th>Group</th>
                <th>Board / University</th>
                <th>Year</th>
                <th>Grade / Division</th>
                <th>Marks / GPA</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($qualifications as $q):
            $examLabel  = !empty($q['exam_title_id'])
                ? ($q['exam_title_name'] ?? $q['exam_name'] ?? '—')
                : ($q['exam_name'] ?? '—');
            $boardLabel = !empty($q['board_id'])
                ? ($q['board_name'] ?? $q['board_university'] ?? '—')
                : ($q['board_university'] ?? '—');
            $groupLabel = !empty($q['group_id'])
                ? ($q['group_ref_name'] ?? $q['group_name'] ?? '—')
                : ($q['group_name'] ?? '—');
        ?>
        <tr>
            <td><?= h($examLabel ?: '—') ?></td>
            <td><?= h($q['session'] ?? '—') ?></td>
            <td><?= h($groupLabel ?: '—') ?></td>
            <td><?= h($boardLabel ?: '—') ?></td>
            <td><?= h($q['passing_year'] ?? '—') ?></td>
            <td><?= h($q['division_class_grade'] ?? '—') ?></td>
            <td><?= h($q['obtained_marks_gpa'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Notice ── -->
    <div class="notice-box">
        This is a computer-generated statement and does not require a physical signature. For any discrepancy, please contact the Registrar's Office.
    </div>

    <!-- ── Footer ── -->
    <div class="stmt-footer">
        <div class="date-issued">Date of Issue: <?= $date_today ?></div>
        <div class="sig-block">
            <div class="sig-line">Authorized Signatory</div>
        </div>
    </div>

</div>
</div>

</body>
</html>
