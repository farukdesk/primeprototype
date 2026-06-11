<?php
/**
 * Admissions Print View – Standalone page (no admin layout).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
require_access('admissions');

$id           = (int)($_GET['id'] ?? 0);
$app          = adm_get($id);
$acad_records = adm_get_academic_records($id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print – <?= h($app['app_number']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 12px; background: #f5f5f5; color: #222; }

        .screen-controls {
            position: fixed; top: 0; left: 0; right: 0; z-index: 999;
            background: #2c3e50; color: #fff; padding: 10px 20px;
            display: flex; align-items: center; gap: 12px;
        }
        .screen-controls button, .screen-controls a {
            background: #27ae60; color: #fff; border: none; padding: 6px 16px;
            border-radius: 4px; cursor: pointer; font-size: 13px; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .screen-controls a.back-btn { background: #7f8c8d; }
        .screen-controls span { font-size: 13px; opacity: 0.85; }

        .print-wrapper { padding: 60px 20px 40px; }

        /* Clean print page (no template) */
        .clean-page {
            background: #fff;
            width: 794px;
            min-height: 1123px;
            padding: 40px 50px;
            margin: 0 auto 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
            page-break-after: always;
        }
        .clean-page h2 { font-size: 16px; text-align: center; margin-bottom: 6px; }
        .clean-page h3 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; color: #333; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 8px; }
        .info-row { display: flex; gap: 6px; font-size: 11px; padding: 2px 0; }
        .info-label { color: #666; min-width: 140px; flex-shrink: 0; }
        .info-value { font-weight: 500; }
        .acad-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-top: 6px; }
        .acad-table th, .acad-table td { border: 1px solid #ccc; padding: 3px 6px; }
        .acad-table th { background: #f0f0f0; }
        .photo-box { float: right; margin-left: 20px; border: 1px solid #ccc; width: 100px; height: 130px; overflow: hidden; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; }
        .office-section { border: 1px solid #888; padding: 10px; margin-top: 20px; }
        .office-section h4 { font-size: 12px; text-transform: uppercase; margin-bottom: 6px; }

        @page {
            size: A4 portrait;
            margin: 12mm 12mm 18mm 12mm;
            @bottom-right {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 10pt;
                font-weight: bold;
                color: #555;
            }
        }
        @media print {
            .screen-controls { display: none !important; }
            body { background: #fff; }
            .print-wrapper { padding: 0; }
            .clean-page, .page-start, .form-page { box-shadow: none !important; }
            .avoid-break { page-break-inside: avoid; break-inside: avoid; }
            .page-start { page-break-before: always; break-before: page; }
        }
    </style>
</head>
<body>

<div class="screen-controls">
    <button onclick="window.print()">🖨 Print</button>
    <a href="javascript:window.close()" class="back-btn">✕ Close</a>
    <span><?= h($app['app_number']) ?> — <?= h($app['student_name']) ?></span>
</div>

<div class="print-wrapper">

    <!-- ── Styled Admission Form Layout ── -->
    <?php
    // Embed logo as base64 data URI
    $logo_file = dirname(__DIR__, 2) . '/Prime_University (3).png';
    $logo_uri  = is_file($logo_file)
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file))
        : SITE_URL . '/assets/img/logo/logo-black-sm.png';

    // Semester flags
    $semesters_active = array_map('trim', explode(',', strtolower($app['semester'] ?? '')));
    $sem_spring = in_array('spring', $semesters_active) ? '✓' : '';
    $sem_summer = in_array('summer', $semesters_active) ? '✓' : '';
    $sem_fall   = in_array('fall',   $semesters_active) ? '✓' : '';

    // Photo URL
    $photo_url = ($app['photo'] ?? '') ? UPLOAD_URL . '/' . ADM_PHOTO_SUBDIR . '/' . rawurlencode($app['photo']) : '';

    // Date of birth formatted
    $dob_formatted = ($app['date_of_birth'] ?? '') ? date('d/m/Y', strtotime($app['date_of_birth'])) : '';

    // Present address combined
    $present_addr1 = trim(implode(', ', array_filter([
        $app['present_address_1'] ?? '',
        $app['present_area']      ?? '',
    ])));
    $present_addr2 = trim(implode(', ', array_filter([
        $app['present_address_2'] ?? '',
    ])));

    // Permanent address combined
    $perm_addr1 = trim(implode(', ', array_filter([
        $app['permanent_address_1'] ?? '',
        $app['permanent_area']      ?? '',
    ])));
    $perm_addr2 = trim(implode(', ', array_filter([
        $app['permanent_address_2'] ?? '',
    ])));

    // Guardian address combined
    $guardian_addr1 = $app['guardian_address_1'] ?? '';
    $guardian_addr2 = $app['guardian_address_2'] ?? '';

    // Local guardian address
    $local_addr1 = $app['local_guardian_address_1'] ?? '';
    $local_addr2 = implode(', ', array_filter([
        $app['local_guardian_address_2'] ?? '',
        $app['local_guardian_address_3'] ?? '',
    ]));

    // Reference address
    $ref_addr1 = $app['reference_address_1'] ?? '';
    $ref_addr2 = implode(', ', array_filter([
        $app['reference_address_2'] ?? '',
        $app['reference_address_3'] ?? '',
    ]));

    // Sex
    $sex = $app['sex'] ?? '';

    // Expelled
    $expelled = ($app['expelled_answer'] ?? 'No');
    ?>

    <!-- Admission Form – Page 1 (personal information) -->
    <div class="form-page" style="max-width:800px;margin:0 auto 40px auto;background:#fff;padding:30px;border:1px solid #bdc3c7;box-sizing:border-box;position:relative;box-shadow:0 4px 15px rgba(0,0,0,.05)">

        <table style="width:100%;border-collapse:collapse;margin-bottom:20px">
            <tr>
                <td style="width:15%;vertical-align:middle">
                    <img src="<?= h($logo_uri) ?>" alt="Prime University" style="width:85px;height:95px;object-fit:contain">
                </td>
                <td style="text-align:center;vertical-align:middle">
                    <h1 style="margin:0;font-family:'Times New Roman',Times,serif;font-size:34px;color:#2b327a;letter-spacing:1px;font-weight:bold">PRIME UNIVERSITY</h1>
                    <p style="margin:3px 0;font-size:13px;color:#d32f2f;font-style:italic;font-weight:bold">... a home for rendering prime knowledge</p>
                    <p style="margin:5px 0 3px 0;font-size:12px;color:#444;font-weight:500">114/116, Mazar Road, Mirpur-1, Dhaka-1216</p>
                    <p style="margin:3px 0;font-size:11px;color:#555">Tel: 48038147, 8031810, 4803488 (Ext.102). Fax: 48038149, Mob: 01712-675595, 01939-425030</p>
                    <p style="margin:3px 0;font-size:11px;color:#555">E-mail: <span style="color:#d32f2f;font-weight:500">infoadmission@primeuniversity.edu.bd</span> | Web: www.primeuniversity.edu.bd</p>
                </td>
                <td style="width:15%;text-align:right;vertical-align:top">
                    <div style="border:2px solid #2b327a;background:#fff;border-radius:50%;width:65px;height:65px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;color:#2b327a;text-align:center;word-break:break-all;padding:4px"><?= h($app['app_number'] ?? '') ?></div>
                </td>
            </tr>
        </table>

        <hr style="border:0;border-top:2px solid #2b327a;margin-bottom:20px">

        <div style="text-align:center;margin-bottom:30px">
            <span style="font-family:Arial,sans-serif;font-size:22px;font-weight:bold;color:#fff;background-color:#2b327a;padding:6px 25px;border-radius:4px">Application Form For Admission</span>
        </div>

        <?php if ($photo_url): ?>
        <div style="position:absolute;right:30px;top:165px;width:115px;height:135px;border:2px solid #2b327a">
            <img src="<?= h($photo_url) ?>" alt="Photo" style="width:100%;height:100%;object-fit:cover">
        </div>
        <?php else: ?>
        <div style="position:absolute;right:30px;top:165px;width:115px;height:135px;border:2px dashed #2b327a;display:flex;align-items:center;justify-content:center;font-size:13px;color:#2b327a;background:#fafbfc;font-weight:500">Affix Photo</div>
        <?php endif; ?>

        <table style="width:78%;border-collapse:collapse;margin-bottom:15px;font-size:14px">
            <tr style="height:38px">
                <td style="width:120px;font-weight:bold;color:#2b327a">Department :</td>
                <td><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:6px 12px;font-weight:bold"><?= h($app['dept_name'] ?? '') ?></div></td>
            </tr>
            <tr style="height:42px">
                <td style="font-weight:bold;color:#2b327a">Program :</td>
                <td><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:6px 12px;font-weight:bold"><?= h($app['program_name'] ?? '') ?></div></td>
            </tr>
        </table>

        <table style="width:100%;border-collapse:collapse;margin-bottom:25px;font-size:14px">
            <tr>
                <td style="width:50px;font-weight:bold;color:#2b327a">Year :</td>
                <td style="width:90px"><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:5px 10px;text-align:center;font-weight:bold"><?= h($app['year'] ?? '') ?></div></td>
                <td style="width:70px;font-weight:bold;color:#2b327a;text-align:center">Spring :</td>
                <td style="width:90px"><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:4px 10px;min-height:28px;text-align:center;font-weight:bold;line-height:20px;overflow:hidden"><?= h($sem_spring) ?></div></td>
                <td style="width:80px;font-weight:bold;color:#2b327a;text-align:center">Summer :</td>
                <td style="width:90px"><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:4px 10px;min-height:28px;text-align:center;font-weight:bold;line-height:20px;overflow:hidden"><?= h($sem_summer) ?></div></td>
                <td style="width:50px;font-weight:bold;color:#2b327a;text-align:center">Fall :</td>
                <td><div style="background:#f0f1fa;border:1px solid #cbd0f5;padding:4px 10px;min-height:28px;width:80px;text-align:center;font-weight:bold;line-height:20px;overflow:hidden"><?= h($sem_fall) ?></div></td>
            </tr>
        </table>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:10px">
            <tr style="height:40px">
                <td style="width:160px;font-weight:500">Name of the Student<br><span style="font-size:12px;color:#666">(Block Letter)</span></td>
                <td style="width:15px;text-align:center;color:#2b327a;font-weight:bold">:</td>
                <td colspan="3"><div style="background:#fffde6;border:1px solid #ffe680;padding:7px 12px;font-weight:bold;text-transform:uppercase"><?= h($app['student_name'] ?? '') ?></div></td>
            </tr>
            <tr style="height:40px">
                <td style="font-weight:500">Father's Name</td>
                <td style="text-align:center;color:#2b327a;font-weight:bold">:</td>
                <td colspan="3"><div style="background:#fafafa;border:1px solid #e0e0e0;padding:7px 12px;color:#444"><?= h($app['father_name'] ?? '') ?></div></td>
            </tr>
            <tr style="height:40px">
                <td style="font-weight:500">Mother's Name</td>
                <td style="text-align:center;color:#2b327a;font-weight:bold">:</td>
                <td colspan="3"><div style="background:#fafafa;border:1px solid #e0e0e0;padding:7px 12px;color:#444"><?= h($app['mother_name'] ?? '') ?></div></td>
            </tr>
            <tr style="height:60px">
                <td style="vertical-align:top;padding-top:10px;font-weight:500">Present Address</td>
                <td style="vertical-align:top;padding-top:10px;text-align:center;color:#2b327a;font-weight:bold">:</td>
                <td colspan="3" style="vertical-align:top;padding-top:8px">
                    <div style="border-bottom:1px dotted #2b327a;padding-bottom:4px;color:#444"><?= h($present_addr1) ?></div>
                    <div style="border-bottom:1px dotted #2b327a;padding-top:8px;padding-bottom:4px;color:#444"><?= h($present_addr2) ?></div>
                </td>
            </tr>
            <tr style="height:38px">
                <td></td><td></td>
                <td style="width:45%;border-bottom:1px dotted #2b327a;color:#444"><span style="color:#2b327a;font-weight:500">Contact No:</span> <?= h($app['present_contact'] ?? '') ?></td>
                <td style="width:10px"></td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><span style="color:#2b327a;font-weight:500">E-mail :</span> <?= h($app['present_email'] ?? '') ?></td>
            </tr>
            <tr style="height:60px">
                <td style="vertical-align:top;padding-top:10px;font-weight:500">Permanent Address</td>
                <td style="vertical-align:top;padding-top:10px;text-align:center;color:#2b327a;font-weight:bold">:</td>
                <td colspan="3" style="vertical-align:top;padding-top:8px">
                    <div style="border-bottom:1px dotted #2b327a;padding-bottom:4px;color:#444"><?= h($perm_addr1) ?></div>
                    <div style="border-bottom:1px dotted #2b327a;padding-top:8px;padding-bottom:4px;color:#444"><?= h($perm_addr2) ?></div>
                </td>
            </tr>
            <tr style="height:38px">
                <td></td><td></td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><span style="color:#2b327a;font-weight:500">Contact No:</span> <?= h($app['permanent_contact'] ?? '') ?></td>
                <td></td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><span style="color:#2b327a;font-weight:500">E-mail :</span> <?= h($app['permanent_email'] ?? '') ?></td>
            </tr>
        </table>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:20px">
            <tr style="height:38px">
                <td style="width:120px;font-weight:500">Nationality</td>
                <td style="width:15px;text-align:center;color:#2b327a">:</td>
                <td style="width:38%;border-bottom:1px dotted #2b327a;color:#444"><?= h($app['nationality'] ?? '') ?></td>
                <td style="width:110px;font-weight:500;padding-left:15px">Date of Birth</td>
                <td style="width:15px;text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($dob_formatted) ?></td>
            </tr>
            <tr style="height:38px">
                <td style="font-weight:500">Place of Birth</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['place_of_birth'] ?? '') ?></td>
                <td style="font-weight:500;padding-left:15px">Religion</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['religion'] ?? '') ?></td>
            </tr>
            <tr style="height:38px">
                <td style="font-weight:500">NID/Birth Cert. No.</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['nid_birth_cert'] ?? '') ?></td>
                <td style="font-weight:500;padding-left:15px">Blood Group</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;font-weight:bold;color:#d32f2f"><?= h($app['blood_group'] ?? '') ?></td>
            </tr>
            <tr style="height:38px">
                <td style="font-weight:500">Sex</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td colspan="4">
                    <span style="border:1px solid #2b327a;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;margin-right:5px;vertical-align:middle;font-weight:bold;font-size:12px"><?= ($sex === 'Male') ? '✓' : '' ?></span> Male
                    <span style="font-weight:bold;color:#2b327a;margin-left:25px;margin-right:25px;vertical-align:middle"><?= ($sex === 'Male') ? 'M' : ($sex === 'Female' ? 'F' : '') ?></span>
                    <span style="border:1px solid #2b327a;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;margin-right:5px;vertical-align:middle;font-weight:bold;font-size:12px"><?= ($sex === 'Female') ? '✓' : '' ?></span> Female
                </td>
            </tr>
        </table>
    </div>

    <!-- Admission Form – Page 2 (academic qualifications) -->
    <div class="page-start form-page" style="max-width:800px;margin:0 auto 40px auto;background:#fff;padding:30px;border:1px solid #bdc3c7;box-sizing:border-box;box-shadow:0 4px 15px rgba(0,0,0,.05)">

        <h2 style="color:#d32f2f;font-size:16px;margin:25px 0 12px 0;border-bottom:2px solid #2b327a;padding-bottom:5px;text-transform:uppercase">Academic Qualifications :</h2>

        <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:center;margin-bottom:30px">
            <thead>
                <tr style="background-color:#2b327a;color:white;height:38px">
                    <th style="border:1px solid #454d9e;padding:4px;width:22%">Name of the Examination</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:13%">Session</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:13%">Group</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:18%">Board/ University</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:11%">Year of Passing</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:11%">Division Class / Grade</th>
                    <th style="border:1px solid #454d9e;padding:4px;width:12%">Obtained Total Marks / CGPA</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $acad_rows_filled = array_values($acad_records);
                // Ensure at least 4 rows
                while (count($acad_rows_filled) < 4) { $acad_rows_filled[] = null; }
                foreach ($acad_rows_filled as $idx => $ar):
                    $bg = ($idx % 2 === 0) ? '#fcfdfe' : '#fff';
                ?>
                <tr style="height:35px;background-color:<?= $bg ?>">
                    <td style="border:1px solid #e2e4f5"><?= h($ar['exam_name'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['session'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['group_name'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['board_university'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['year_of_passing'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['division_grade'] ?? '') ?></td>
                    <td style="border:1px solid #e2e4f5"><?= h($ar['total_marks_cgpa'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="font-size:14px;margin-top:25px;margin-bottom:35px">
            <span style="color:#2b327a;font-weight:bold;margin-right:10px">Experience :</span>
            <div style="display:inline-block;width:85%;border-bottom:1px dotted #2b327a;color:#444;padding-left:5px"><?= h($app['experience'] ?? '') ?></div>
            <div style="border-bottom:1px dotted #2b327a;margin-top:20px;height:15px;width:100%"></div>
        </div>

        <div style="text-align:center;font-size:12px;color:#777;font-style:italic;margin-top:50px;border-top:1px solid #eee;padding-top:10px">
            Please see the overleaf
        </div>
    </div>

    <!-- Admission Form – Page 3 (guardian & office use) -->
    <div class="page-start form-page" style="max-width:800px;margin:0 auto 40px auto;background:#fff;padding:40px 30px 30px 30px;border:1px solid #bdc3c7;box-sizing:border-box;box-shadow:0 4px 15px rgba(0,0,0,.05)">

        <h2 style="color:#d32f2f;font-size:16px;margin:0 0 20px 0;border-bottom:2px solid #2b327a;padding-bottom:5px;text-transform:uppercase">Particulars of Guardian:</h2>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:25px">
            <tr style="height:38px">
                <td style="width:80px;font-weight:500">Name</td>
                <td style="width:15px;text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_name'] ?? '') ?></td>
                <td style="width:90px;font-weight:500;padding-left:20px">Profession</td>
                <td style="width:15px;text-align:center;color:#2b327a">:</td>
                <td style="width:28%;border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_profession'] ?? '') ?></td>
            </tr>
            <tr style="height:55px">
                <td style="vertical-align:top;padding-top:12px;font-weight:500">Address</td>
                <td style="vertical-align:top;padding-top:12px;text-align:center;color:#2b327a">:</td>
                <td colspan="4" style="vertical-align:top;padding-top:8px">
                    <div style="border-bottom:1px dotted #2b327a;padding-bottom:4px;color:#444"><?= h($guardian_addr1) ?></div>
                    <div style="border-bottom:1px dotted #2b327a;padding-top:8px;min-height:16px;color:#444"><?= h($guardian_addr2) ?></div>
                </td>
            </tr>
            <tr style="height:38px">
                <td style="font-weight:500">Phone</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_phone'] ?? '') ?></td>
                <td style="font-weight:500;padding-left:20px">E-mail</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_email'] ?? '') ?></td>
            </tr>
            <tr style="height:38px">
                <td style="font-weight:500">Relationship</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_relationship'] ?? '') ?></td>
                <td style="font-weight:500;padding-left:20px">Monthly Income</td>
                <td style="text-align:center;color:#2b327a">:</td>
                <td style="border-bottom:1px dotted #2b327a;color:#444"><?= h($app['guardian_monthly_income'] ?? '') ?></td>
            </tr>
        </table>

        <div style="text-align:right;margin-bottom:35px;font-size:13px;padding-right:5px">
            <div style="width:220px;display:inline-block;text-align:center">
                <div style="height:55px"></div>
                <div style="border-top:2px solid #444;padding-top:6px;font-weight:bold">Signature of Guardian</div>
            </div>
        </div>

        <div style="display:flex;justify-content:space-between;font-size:14px;margin-bottom:35px">
            <div style="width:48%">
                <h3 style="color:#2b327a;font-size:15px;margin:0 0 15px 0;border-bottom:2px solid #2b327a;padding-bottom:4px;font-weight:bold;text-transform:uppercase">Local Guardian :</h3>
                <table style="width:100%;border-collapse:collapse">
                    <tr style="height:35px">
                        <td style="width:65px;font-weight:500">Name</td>
                        <td style="border-bottom:1px dotted #2b327a;color:#444;padding-left:5px"><?= h($app['local_guardian_name'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td style="vertical-align:top;padding-top:10px;font-weight:500">Address</td>
                        <td style="padding-top:6px">
                            <div style="border-bottom:1px dotted #2b327a;color:#444;min-height:18px"><?= h($local_addr1) ?></div>
                            <div style="border-bottom:1px dotted #2b327a;color:#444;min-height:18px;padding-top:6px"><?= h($local_addr2) ?></div>
                        </td>
                    </tr>
                    <tr style="height:38px">
                        <td style="font-weight:500;padding-top:8px">Contact No:</td>
                        <td style="border-bottom:1px dotted #2b327a;color:#444;padding-top:8px"><?= h($app['local_guardian_contact'] ?? '') ?></td>
                    </tr>
                </table>
            </div>

            <div style="width:48%">
                <h3 style="color:#2b327a;font-size:15px;margin:0 0 15px 0;border-bottom:2px solid #2b327a;padding-bottom:4px;font-weight:bold;text-transform:uppercase">Reference :</h3>
                <table style="width:100%;border-collapse:collapse">
                    <tr style="height:35px">
                        <td style="width:65px;font-weight:500">Name</td>
                        <td style="border-bottom:1px dotted #2b327a;color:#444;padding-left:5px"><?= h($app['reference_name'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <td style="vertical-align:top;padding-top:10px;font-weight:500">Address</td>
                        <td style="padding-top:6px">
                            <div style="border-bottom:1px dotted #2b327a;color:#444;min-height:18px"><?= h($ref_addr1) ?></div>
                            <div style="border-bottom:1px dotted #2b327a;color:#444;min-height:18px;padding-top:6px"><?= h($ref_addr2) ?></div>
                        </td>
                    </tr>
                    <tr style="height:38px">
                        <td style="font-weight:500;padding-top:8px">Contact No:</td>
                        <td style="border-bottom:1px dotted #2b327a;color:#444;padding-top:8px"><?= h($app['reference_contact'] ?? '') ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div style="font-size:13.5px;margin-bottom:30px;background:#fffde6;padding:12px;border:1px dashed #ffcc00;border-radius:4px">
            <p style="margin:0 0 8px 0;font-weight:bold;color:#2b327a">* Have you ever been dismissed from any examination or expelled from any institution of learning?</p>
            <div>
                <span style="border:1px solid #2b327a;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;margin-right:5px;vertical-align:middle;font-weight:bold;font-size:12px"><?= ($expelled === 'No') ? '✓' : '' ?></span>
                <span style="font-weight:bold;margin-right:25px;vertical-align:middle">NO</span>
                <span style="border:1px solid #2b327a;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;margin-right:5px;vertical-align:middle;font-weight:bold;font-size:12px"><?= ($expelled === 'Yes') ? '✓' : '' ?></span>
                <span style="font-weight:bold;vertical-align:middle">Yes</span>
                <?php if ($expelled === 'Yes' && ($app['expelled_detail'] ?? '')): ?>
                <span style="margin-left:10px;font-style:italic;color:#333"><?= h($app['expelled_detail']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-bottom:55px">
            <div style="text-align:center;margin-bottom:15px">
                <span style="font-size:15px;font-weight:bold;color:#fff;background-color:#d32f2f;padding:4px 20px;border-radius:3px;text-transform:uppercase">Undertaking</span>
            </div>
            <p style="font-size:13px;color:#222;text-align:justify;margin:0;line-height:1.6;border:1px solid #e0e0e0;padding:12px;border-radius:4px;background:#fafbfc">
                I do hereby declare that all the information furnished above are true. I undertake to abide by all the rules and regulations of the university and I will be liable to any measure taken against me for violation of any such rules and regulations.
            </p>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:35px">
            <tr style="height:80px">
                <td style="width:40%;vertical-align:bottom"><span style="font-weight:500">Date :</span> <div style="display:inline-block;width:70%;border-bottom:1px solid #2b327a;height:15px"></div></td>
                <td></td>
                <td style="width:38%;text-align:center;vertical-align:bottom"><div style="border-top:1px solid #444;padding-top:6px;width:100%;font-weight:bold">Signature of the Student</div></td>
            </tr>
        </table>

        <div style="font-size:12.5px;line-height:1.6;margin-bottom:35px;background:#f0f1fa;padding:12px;border-left:4px solid #2b327a">
            <span style="color:#d32f2f;font-weight:bold;display:block;margin-bottom:4px">Note :</span>
            <p style="margin:0;text-align:justify"><span style="color:#2b327a;font-weight:bold">* Please submit the following along with this Application Form :</span> a) Four copies of passport size photograph; b) Attested copies of certificates and mark sheets/ grade sheets, c) Testimonial/ Letter of recommendation from institution last attended and d) Birth Certificate or NID Photocopy.</p>
        </div>

        <div class="avoid-break" style="border:2px solid #2b327a;background:#f7f8fc;border-radius:6px;padding:22px 25px;font-size:14px;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact">
            <h3 style="text-align:center;margin:0 auto 22px auto;font-size:16px;color:#2b327a;font-weight:bold;border-bottom:2px solid #2b327a;padding-bottom:4px;text-transform:uppercase">For Office Use Only</h3>
            <table style="width:100%;border-collapse:collapse">
                <tr style="height:40px">
                    <td style="width:95px;font-weight:500">Program :</td>
                    <td style="border-bottom:1px solid #cbd0f5"><?= h($app['program_name'] ?? '') ?></td>
                    <td style="width:115px;font-weight:500;padding-left:30px">Student ID. No :</td>
                    <td style="border-bottom:1px solid #cbd0f5"><?= h($app['office_university_batch'] ?? '') ?></td>
                </tr>
                <tr style="height:40px">
                    <td style="font-weight:500">Batch No. :</td>
                    <td style="border-bottom:1px solid #cbd0f5"><?= h($app['office_dept_batch'] ?? '') ?></td>
                    <td style="padding-left:30px;font-weight:500">Decision :</td>
                    <td style="border-bottom:1px solid #cbd0f5"><?= h($app['office_decision'] ?? '') ?></td>
                </tr>
                <tr style="height:95px">
                    <td style="vertical-align:bottom;padding-bottom:5px;font-weight:500">Checked By :</td>
                    <td style="border-bottom:1px solid #cbd0f5;vertical-align:bottom;width:35%"><?= h($app['office_checked_by'] ?? '') ?></td>
                    <td colspan="2" style="vertical-align:bottom;text-align:right;padding-bottom:5px"><div style="border-top:2px solid #2b327a;width:260px;display:inline-block;text-align:center;padding-top:6px;font-weight:bold;color:#2b327a">Signature of the admission authority</div></td>
                </tr>
            </table>
            <div style="display:flex;justify-content:space-between;margin-top:30px;font-family:'Times New Roman',Times,serif;font-size:15px;font-weight:bold;color:#2b327a;padding:0 20px">
                <div style="text-align:center">
                    <div style="height:50px"></div>
                    <div style="border-top:1px solid #2b327a;width:130px;text-align:center;padding-top:5px">Head</div>
                </div>
                <div style="text-align:center">
                    <div style="height:50px"></div>
                    <div style="border-top:1px solid #2b327a;width:130px;text-align:center;padding-top:5px">Dean</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Code of Conduct – Page 4 -->
    <div class="page-start form-page" style="max-width:800px;margin:0 auto 40px auto;background:#fff;padding:40px 30px;border:1px solid #bdc3c7;box-sizing:border-box;box-shadow:0 4px 15px rgba(0,0,0,.05)">
        <table style="width:100%;border-collapse:collapse;margin-bottom:15px">
            <tr>
                <td style="width:10%"><img src="<?= h($logo_uri) ?>" alt="Prime University" style="width:45px;height:50px;object-fit:contain"></td>
                <td>
                    <h2 style="margin:0;font-family:'Times New Roman',Times,serif;font-size:28px;color:#2b327a;font-weight:bold;text-transform:uppercase">PRIME UNIVERSITY</h2>
                    <p style="margin:2px 0;font-size:11px;color:#d32f2f;font-style:italic">... a home for rendering prime knowledge</p>
                </td>
            </tr>
        </table>

        <div style="text-align:center;margin:25px 0 15px 0">
            <h1 style="font-family:'Times New Roman',Times,serif;font-size:38px;color:#d32f2f;font-style:italic;margin:0;font-weight:normal">Student Code of Conduct</h1>
            <div style="font-weight:bold;font-size:16px;margin-top:5px;border-top:1px solid #2b327a;border-bottom:1px solid #2b327a;padding:4px 0;display:inline-block">Rules &amp; Responsibilities for Students</div>
        </div>

        <p style="font-size:12.5px;text-align:justify;color:#333;line-height:1.5;margin-bottom:15px">
            The Prime University Student Code of Conduct has been formulated with the goal of upholding standard mission of smooth disciplinary activities. It is the responsibility of the Prime University to prepare the "Students Code of Conduct" and make that available to all members to the University community so that in case of violations and subsequent convening of the "Disciplinary Committee" measures and procedures may be clear to all parties concerned. The violations of code of conduct shall invoke disciplinary process as prescribed in these rules. Sanction will be commensurate with the seriousness of the offence and may include suspension or extreme, expulsion from the university. Repeated offences justify increasingly severe sanction.
        </p>

        <div style="background-color:#d32f2f;color:white;padding:6px 15px;font-weight:bold;font-size:14px;margin-bottom:15px;border-radius:2px">
            The following shall be considered as offences
        </div>

        <ol style="font-size:12.5px;color:#222;padding-left:20px;line-height:1.7;margin:0">
            <li style="margin-bottom:6px">Entering the University premise without Identity Cards.</li>
            <li style="margin-bottom:6px">Smoking or taking liquors, drugs, etc. inside the University premises.</li>
            <li style="margin-bottom:6px">Playing cards.</li>
            <li style="margin-bottom:6px">Writing, drawing or painting on any university property.</li>
            <li style="margin-bottom:6px">Putting on attire that is lewd, indecent, or obscene.</li>
            <li style="margin-bottom:6px">Cheating in the Examinations.</li>
            <li style="margin-bottom:6px">Disorderly conduct, including obstructive and disruptive behaviour that interferes with teaching, research, administration, or other university or university-authorized activity.</li>
            <li style="margin-bottom:6px">Failure to comply with the directions of authorized university officials in the performance of their duties, including failure to identify oneself when requested to do so; failure to comply with the terms of a disciplinary sanction; or refusal to vacate a university facility when directed to do so.</li>
            <li style="margin-bottom:6px">Unauthorized entry, use, or occupancy of university facilities.</li>
            <li style="margin-bottom:6px">Interfering with an individual's personal safety, academic efforts, employment or participation in university-sponsored activities; injuring that person or damaging his or her property; or using "fighting words" that are spoken face-to-face as a personal insult.</li>
            <li style="margin-bottom:6px">Intentionally obstructing or blocking access to university facilities, property, or programs.</li>
            <li style="margin-bottom:6px">Engagement, solicitation, initiation, encouragement, abetment, organization, facilitation, or provocation of any sort of political activity inside and in the adjacent area of the university premises.</li>
            <li style="margin-bottom:6px">Dishonest conduct including false accusation of misconduct, forgery, alteration, or misuse of any university document, record, or identification; and giving to a university official information known to be false.</li>
            <li style="margin-bottom:6px">Assuming another person's identity or role through deception or without proper authorization.</li>
            <li style="margin-bottom:6px">Knowingly initiating, transmitting, filing, or circulating a false report or warning concerning an impending bombing, fire, or other emergency or catastrophe.</li>
            <li style="margin-bottom:6px">Unauthorized release or use of any university access codes for computer systems, duplicating systems, and other university equipment.</li>
        </ol>

        <div style="text-align:right;font-size:11px;color:#555;margin-top:30px"></div>
    </div>

    <!-- Student Code of Conduct – Page 5 -->
    <div class="page-start form-page" style="max-width:800px;margin:0 auto;background:#fff;padding:40px 30px;border:1px solid #bdc3c7;box-sizing:border-box;box-shadow:0 4px 15px rgba(0,0,0,.05)">
        <table style="width:100%;border-collapse:collapse;margin-bottom:15px">
            <tr>
                <td style="width:10%"><img src="<?= h($logo_uri) ?>" alt="Prime University" style="width:45px;height:50px;object-fit:contain"></td>
                <td>
                    <h2 style="margin:0;font-family:'Times New Roman',Times,serif;font-size:28px;color:#2b327a;font-weight:bold;text-transform:uppercase">PRIME UNIVERSITY</h2>
                    <p style="margin:2px 0;font-size:11px;color:#d32f2f;font-style:italic">... a home for rendering prime knowledge</p>
                </td>
            </tr>
        </table>

        <div style="text-align:center;margin:25px 0 15px 0">
            <h1 style="font-family:'Times New Roman',Times,serif;font-size:38px;color:#d32f2f;font-style:italic;margin:0;font-weight:normal">Student Code of Conduct</h1>
            <div style="font-weight:bold;font-size:16px;margin-top:5px;border-top:1px solid #2b327a;border-bottom:1px solid #2b327a;padding:4px 0;display:inline-block">Rules &amp; Responsibilities for Students</div>
        </div>

        <ol start="17" style="font-size:12.5px;color:#222;padding-left:20px;line-height:1.7;margin:0">
            <li style="margin-bottom:6px">Actions that endanger one's self, others in the university community, or the academic process.</li>
            <li style="margin-bottom:6px">Unauthorized taking, possession, or use of university property or services, or the property or services of others.</li>
            <li style="margin-bottom:6px">Damage or destruction of university property or the property belonging to others.<br>
            <span style="font-weight:500;display:block;margin-top:3px;color:#444">Unauthorized setting of fires on university property; unauthorized use of or interference with fire equipment and emergency personnel.</span></li>
            <li style="margin-bottom:6px">Unauthorized possession, use, manufacture, distribution, or sale of illegal fireworks, incendiary devices, weapons or other dangerous explosives, drugs.</li>
            <li style="margin-bottom:6px">Acting with violence.</li>
            <li style="margin-bottom:6px">Aiding, encouraging, or participating in a riot.</li>
            <li style="margin-bottom:6px">Harassment of any kind.</li>
            <li style="margin-bottom:6px">Stalking or hazing of any kind whether the behaviour is carried out verbally, physically, electronically, or in written form.</li>
            <li style="margin-bottom:6px">Physical abuse of any person including use of physical force, violence, or threats that endanger health, safety, academic efforts, or participation in university activities.</li>
            <li style="margin-bottom:6px">Sexual assault or sexual contact with another person, including while any party involved is in an impaired state.</li>
            <li style="margin-bottom:6px">Gambling or any other game or activity with the element of betting.<br>
            <span style="display:block;margin-top:2px">Violation of other disseminated university regulations, policies, or rules.</span>
            <span style="display:block;margin-top:2px">A violation of any criminal law.</span></li>
            <li style="margin-bottom:6px">Engaging in or encouraging any behaviour or activity that threatens or intimidates any potential participant in a judicial process.</li>
            <li style="margin-bottom:6px">Possession and distribution of unauthorized printed materials inimical to public interest.</li>
            <li style="margin-bottom:6px">Membership in political subversive organization.</li>
        </ol>

        <div style="margin-top:60px;font-size:14px">
            <div style="width:250px;border-top:2px solid #444;text-align:center;padding-top:6px;font-weight:bold;margin-top:60px">
                Signature of the Student
            </div>
        </div>
    </div>

</div><!-- /print-wrapper -->
</body>
</html>
