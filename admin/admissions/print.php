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
$all_fields   = adm_get_all_fields();

$tpl1     = adm_get_template(1);
$tpl2     = adm_get_template(2);
$map1     = adm_get_mappings(1);
$map2     = adm_get_mappings(2);

$has_templates = $tpl1 || $tpl2;

$tpl_base_url = UPLOAD_URL . '/' . ADM_TPL_SUBDIR . '/';

// Build a keyed array: field_key => field_label
$field_labels = [];
foreach ($all_fields as $f) {
    $field_labels[$f['field_key']] = $f['field_label'];
}
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

        /* Template overlay pages */
        .template-page {
            position: relative;
            display: inline-block;
            margin-bottom: 30px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
            page-break-after: always;
        }
        .template-page img.tpl-bg {
            display: block;
            width: 794px;
            height: auto;
        }
        .field-overlay {
            position: absolute;
            white-space: nowrap;
            line-height: 1;
            pointer-events: none;
            color: #000;
        }

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

        @media print {
            .screen-controls { display: none !important; }
            body { background: #fff; }
            .print-wrapper { padding: 0; }
            .template-page, .clean-page { box-shadow: none; }
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

<?php if ($has_templates): ?>
    <!-- ── Template overlay mode ── -->

    <?php foreach ([1, 2] as $page_num):
        $tpl = ($page_num === 1) ? $tpl1 : $tpl2;
        $map = ($page_num === 1) ? $map1 : $map2;
        if (!$tpl) continue;

        $img_url   = $tpl_base_url . h($tpl['stored_file']);
        $is_pdf    = $tpl['file_type'] === 'pdf';
    ?>
    <div style="text-align:center">
        <div class="template-page">
            <?php if ($is_pdf): ?>
            <p style="padding:20px;color:#666">PDF template (Page <?= $page_num ?>): <?= h($tpl['original_name']) ?><br>
            Overlay fields shown below.</p>
            <?php else: ?>
            <img class="tpl-bg" src="<?= $img_url ?>" alt="Page <?= $page_num ?> template">
            <?php endif; ?>

            <?php
            // Build 0-based indexed academic records for qual_ field resolution
            $acad_indexed = array_values($acad_records);
            foreach ($map as $field_key => $mapping):
                $value     = adm_field_value($app, $field_key, $acad_indexed);
                $font_size = (int)($mapping['font_size'] ?? 10);
                $x         = (float)$mapping['x_percent'];
                $y         = (float)$mapping['y_percent'];
            ?>
            <?php if ($field_key === 'photo' && $value !== ''): ?>
            <img src="<?= UPLOAD_URL . '/' . ADM_PHOTO_SUBDIR . '/' . h($value) ?>"
                 class="field-overlay"
                 style="left:<?= $x ?>%;top:<?= $y ?>%;width:80px;height:100px;object-fit:cover;border:1px solid #ccc;pointer-events:none;"
                 alt="Photo">
            <?php elseif ($field_key !== 'photo' && $value !== ''): ?>
            <div class="field-overlay" style="left:<?= $x ?>%;top:<?= $y ?>%;font-size:<?= $font_size ?>pt">
                <?= h($value) ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Academic records appear as a separate section below templates -->
    <?php if ($acad_records): ?>
    <div style="width:794px;margin:0 auto 20px;background:#fff;padding:20px 30px;box-shadow:0 1px 6px rgba(0,0,0,.1)">
        <h3 style="font-size:13px;margin-bottom:8px;border-bottom:1px solid #ccc;padding-bottom:4px">Academic Qualifications</h3>
        <table class="acad-table">
            <thead>
                <tr>
                    <th>Exam</th><th>Session</th><th>Group</th>
                    <th>Board/University</th><th>Year</th><th>Division/Grade</th><th>Marks/CGPA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acad_records as $ar): ?>
                <tr>
                    <td><?= h($ar['exam_name'] ?? '') ?></td>
                    <td><?= h($ar['session'] ?? '') ?></td>
                    <td><?= h($ar['group_name'] ?? '') ?></td>
                    <td><?= h($ar['board_university'] ?? '') ?></td>
                    <td><?= h($ar['year_of_passing'] ?? '') ?></td>
                    <td><?= h($ar['division_grade'] ?? '') ?></td>
                    <td><?= h($ar['total_marks_cgpa'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ── Clean HTML print layout (no templates uploaded) ── -->

    <!-- Page 1 -->
    <div class="clean-page">
        <?php if ($app['photo']): ?>
        <div class="photo-box">
            <img src="<?= UPLOAD_URL . '/' . ADM_PHOTO_SUBDIR . '/' . h($app['photo']) ?>" alt="Photo">
        </div>
        <?php endif; ?>

        <h2>Prime University</h2>
        <h2 style="margin-bottom:12px">Application for Admission</h2>

        <div style="display:flex;gap:12px;margin-bottom:4px">
            <span><strong>Application No:</strong> <?= h($app['app_number']) ?></span>
            <span><strong>Status:</strong> <?= h(ucfirst($app['status'])) ?></span>
        </div>
        <div class="info-row" style="margin-bottom:12px">
            <strong>Department:</strong>&nbsp;<?= h($app['dept_name'] ?? '—') ?>&nbsp;&nbsp;
            <strong>Program:</strong>&nbsp;<?= h($app['program_name'] ?? '—') ?>&nbsp;&nbsp;
            <strong>Year:</strong>&nbsp;<?= h($app['year'] ?? '—') ?>&nbsp;&nbsp;
            <strong>Semester:</strong>&nbsp;<?= h($app['semester'] ?? '—') ?>
        </div>

        <h3>Personal Information</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Student Name</span><span class="info-value"><?= h($app['student_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Father's Name</span><span class="info-value"><?= h($app['father_name'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Mother's Name</span><span class="info-value"><?= h($app['mother_name'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Sex</span><span class="info-value"><?= h($app['sex'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Date of Birth</span><span class="info-value"><?= $app['date_of_birth'] ? h(date('d/m/Y', strtotime($app['date_of_birth']))) : '—' ?></span></div>
            <div class="info-row"><span class="info-label">Nationality</span><span class="info-value"><?= h($app['nationality'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Place of Birth</span><span class="info-value"><?= h($app['place_of_birth'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Religion</span><span class="info-value"><?= h($app['religion'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">NID / Birth Cert No</span><span class="info-value"><?= h($app['nid_birth_cert'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Blood Group</span><span class="info-value"><?= h($app['blood_group'] ?? '—') ?></span></div>
        </div>

        <h3>Present Address</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Line 1</span><span class="info-value"><?= h($app['present_address_1'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Line 2</span><span class="info-value"><?= h($app['present_address_2'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Contact</span><span class="info-value"><?= h($app['present_contact'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= h($app['present_email'] ?? '—') ?></span></div>
        </div>

        <h3>Permanent Address</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Line 1</span><span class="info-value"><?= h($app['permanent_address_1'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Line 2</span><span class="info-value"><?= h($app['permanent_address_2'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Contact</span><span class="info-value"><?= h($app['permanent_contact'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= h($app['permanent_email'] ?? '—') ?></span></div>
        </div>

        <h3>Academic Qualifications</h3>
        <?php if ($acad_records): ?>
        <table class="acad-table">
            <thead>
                <tr>
                    <th>Exam</th><th>Session</th><th>Group</th>
                    <th>Board/University</th><th>Year</th><th>Division/Grade</th><th>Marks/CGPA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acad_records as $ar): ?>
                <tr>
                    <td><?= h($ar['exam_name'] ?? '') ?></td>
                    <td><?= h($ar['session'] ?? '') ?></td>
                    <td><?= h($ar['group_name'] ?? '') ?></td>
                    <td><?= h($ar['board_university'] ?? '') ?></td>
                    <td><?= h($ar['year_of_passing'] ?? '') ?></td>
                    <td><?= h($ar['division_grade'] ?? '') ?></td>
                    <td><?= h($ar['total_marks_cgpa'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:#999;font-size:10px">No academic records.</p>
        <?php endif; ?>

        <?php if ($app['experience']): ?>
        <h3>Experience</h3>
        <p style="font-size:11px"><?= nl2br(h($app['experience'])) ?></p>
        <?php endif; ?>
    </div>

    <!-- Page 2 -->
    <div class="clean-page">
        <h2 style="margin-bottom:12px">Application (continued) – <?= h($app['app_number']) ?></h2>

        <h3>Guardian Particulars</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?= h($app['guardian_name'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Profession</span><span class="info-value"><?= h($app['guardian_profession'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 1</span><span class="info-value"><?= h($app['guardian_address_1'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 2</span><span class="info-value"><?= h($app['guardian_address_2'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= h($app['guardian_phone'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= h($app['guardian_email'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Relationship</span><span class="info-value"><?= h($app['guardian_relationship'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Monthly Income</span><span class="info-value"><?= h($app['guardian_monthly_income'] ?? '—') ?></span></div>
        </div>

        <h3>Local Guardian</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?= h($app['local_guardian_name'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Contact</span><span class="info-value"><?= h($app['local_guardian_contact'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 1</span><span class="info-value"><?= h($app['local_guardian_address_1'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 2</span><span class="info-value"><?= h($app['local_guardian_address_2'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 3</span><span class="info-value"><?= h($app['local_guardian_address_3'] ?? '—') ?></span></div>
        </div>

        <h3>Reference</h3>
        <div class="info-grid">
            <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?= h($app['reference_name'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Contact</span><span class="info-value"><?= h($app['reference_contact'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 1</span><span class="info-value"><?= h($app['reference_address_1'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 2</span><span class="info-value"><?= h($app['reference_address_2'] ?? '—') ?></span></div>
            <div class="info-row"><span class="info-label">Address Line 3</span><span class="info-value"><?= h($app['reference_address_3'] ?? '—') ?></span></div>
        </div>

        <h3>Additional Questions</h3>
        <div class="info-row">
            <span class="info-label">Expelled from any institution?</span>
            <span class="info-value"><?= h($app['expelled_answer'] ?? 'No') ?></span>
        </div>
        <?php if (($app['expelled_answer'] ?? '') === 'Yes'): ?>
        <div class="info-row">
            <span class="info-label">Details</span>
            <span class="info-value"><?= h($app['expelled_detail'] ?? '') ?></span>
        </div>
        <?php endif; ?>

        <div class="office-section" style="margin-top:30px">
            <h4>For Office Use Only</h4>
            <div class="info-grid">
                <div class="info-row"><span class="info-label">Program</span><span class="info-value"><?= h($app['office_program'] ?? '') ?></span></div>
                <div class="info-row"><span class="info-label">Student ID No</span><span class="info-value"><?= h($app['office_student_id'] ?? '') ?></span></div>
                <div class="info-row"><span class="info-label">Batch No</span><span class="info-value"><?= h($app['office_batch_no'] ?? '') ?></span></div>
                <div class="info-row"><span class="info-label">Decision</span><span class="info-value"><?= h($app['office_decision'] ?? '') ?></span></div>
                <div class="info-row"><span class="info-label">Checked By</span><span class="info-value"><?= h($app['office_checked_by'] ?? '') ?></span></div>
            </div>
        </div>
    </div>

<?php endif; ?>

</div><!-- /print-wrapper -->
</body>
</html>
