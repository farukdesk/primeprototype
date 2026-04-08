<?php
/**
 * Form Sale Invoice Print – Standalone page (no admin layout).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/form-sale-helpers.php';

auth_check();
require_access('admissions');

$id   = (int)($_GET['id'] ?? 0);
$sale = adm_fs_get($id);

$tpl        = adm_fs_get_template();
$mappings   = adm_fs_get_mappings();
$inv_fields = adm_fs_invoice_fields();

$has_template = (bool)$tpl;
$tpl_base_url = UPLOAD_URL . '/' . ADM_FS_TPL_SUBDIR . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice – Form <?= h($sale['form_number']) ?></title>
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

        .template-page {
            position: relative;
            display: inline-block;
            margin-bottom: 30px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
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

        .clean-page {
            background: #fff;
            width: 794px;
            min-height: 400px;
            padding: 40px 50px;
            margin: 0 auto 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
        }
        .clean-page h2 { font-size: 17px; text-align: center; margin-bottom: 4px; }
        .clean-page h3 { font-size: 13px; margin-bottom: 16px; text-align: center; color: #555; }
        .divider { border: none; border-top: 2px solid #333; margin: 14px 0; }
        .info-row { display: flex; align-items: baseline; margin-bottom: 10px; font-size: 13px; }
        .info-label { min-width: 170px; color: #555; flex-shrink: 0; }
        .info-value { font-weight: 600; }
        .price-box { border: 2px solid #222; border-radius: 8px; padding: 14px 20px; margin-top: 20px; display: flex; justify-content: space-between; align-items: center; }
        .price-box .label { font-size: 14px; font-weight: 600; }
        .price-box .amount { font-size: 22px; font-weight: 700; }
        .footer-note { margin-top: 30px; font-size: 10px; color: #888; text-align: center; border-top: 1px solid #ccc; padding-top: 10px; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .status-pending   { background: #fff3cd; color: #856404; }
        .status-used      { background: #d1e7dd; color: #0a3622; }
        .status-cancelled { background: #e2e3e5; color: #41464b; }

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
    <button onclick="window.print()">🖨 Print Invoice</button>
    <a href="javascript:window.close()" class="back-btn">✕ Close</a>
    <span>Form <?= h($sale['form_number']) ?> — <?= h($sale['buyer_name']) ?></span>
</div>

<div class="print-wrapper">

<?php if ($has_template && $tpl['file_type'] !== 'pdf'): ?>
    <!-- ── Template overlay mode ── -->
    <div style="text-align:center">
        <div class="template-page">
            <img class="tpl-bg" src="<?= $tpl_base_url . h($tpl['stored_file']) ?>"
                 alt="Invoice template">

            <?php foreach ($mappings as $field_key => $mapping):
                $value     = adm_fs_field_value($sale, $field_key);
                $font_size = (int)($mapping['font_size'] ?? 10);
                $x         = (float)$mapping['x_percent'];
                $y         = (float)$mapping['y_percent'];
                if ($value === '') continue;
            ?>
            <div class="field-overlay"
                 style="left:<?= $x ?>%;top:<?= $y ?>%;font-size:<?= $font_size ?>pt">
                <?= h($value) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ── Clean HTML invoice layout ── -->
    <div class="clean-page">
        <h2>Prime University</h2>
        <h3>Admission Form Sale Invoice</h3>
        <hr class="divider">

        <div class="info-row">
            <span class="info-label">Form Number</span>
            <span class="info-value"><?= h($sale['form_number']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Status</span>
            <span class="info-value">
                <span class="status-badge status-<?= h($sale['status']) ?>"><?= ucfirst(h($sale['status'])) ?></span>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Buyer Full Name</span>
            <span class="info-value"><?= h($sale['buyer_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Mobile Number</span>
            <span class="info-value"><?= h($sale['buyer_mobile']) ?></span>
        </div>
        <?php if ($sale['buyer_email']): ?>
        <div class="info-row">
            <span class="info-label">Email Address</span>
            <span class="info-value"><?= h($sale['buyer_email']) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="info-label">Date of Sale</span>
            <span class="info-value"><?= h(date('d F Y  H:i', strtotime($sale['sold_at']))) ?></span>
        </div>
        <?php if (!empty($sale['sold_by_name'])): ?>
        <div class="info-row">
            <span class="info-label">Collected By</span>
            <span class="info-value"><?= h($sale['sold_by_name']) ?></span>
        </div>
        <?php endif; ?>

        <div class="price-box">
            <span class="label">Amount Received:</span>
            <span class="amount">৳ <?= number_format((float)$sale['form_price'], 2) ?></span>
        </div>

        <div class="footer-note">
            This is an official receipt for the admission form fee. Please retain this invoice for your records.<br>
            The form number above must be presented at the time of admission processing.
        </div>
    </div>

<?php endif; ?>

</div><!-- /print-wrapper -->
</body>
</html>
