<?php
/**
 * ID Card – Print Preview (front + back)
 *
 * Renders the SVG design (from /ID Card SVG/) as the card background and
 * overlays the dynamic data (photo, name, ID, program, validity, QR …)
 * with absolutely-positioned elements.
 *
 * >>> FINE-TUNING <<<
 * All overlay positions live in the $layout array below (values are px
 * inside the native 331.2 × 212.16 px card canvas of the SVG).
 * Adjust them until the text sits exactly in your design's blank areas.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card');
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$card = idc_get_card($id);
if (!$card) {
    flash_set('danger', 'ID card not found.');
    redirect(APP_URL . '/id-card/index.php');
}

$front_bg = idc_template_url($card['card_type'], 'front');
$back_bg  = idc_template_url($card['card_type'], 'back');
$photo    = idc_photo_url($card['photo']);
$qr_src   = APP_URL . '/id-card/qr.php?card_id=' . $id;

// ── Overlay layout (px, native card canvas 331.2 × 212.16) ──────────────────
$layout = [
    'front' => [
        'photo'    => ['left' => 22,  'top' => 60,  'width' => 84, 'height' => 104],
        'name'     => ['left' => 118, 'top' => 76,  'size' => 13],
        'id'       => ['left' => 118, 'top' => 97,  'size' => 11],
        'line1'    => ['left' => 118, 'top' => 115, 'size' => 9],   // program / designation
        'line2'    => ['left' => 118, 'top' => 131, 'size' => 9],   // department
        'validity' => ['left' => 118, 'top' => 149, 'size' => 8],
    ],
    'back' => [
        'blood'   => ['left' => 20, 'top' => 42,  'size' => 10],
        'phone'   => ['left' => 20, 'top' => 62,  'size' => 9],
        'address' => ['left' => 20, 'top' => 82,  'size' => 8, 'width' => 200],
        'qr'      => ['right' => 16, 'bottom' => 16, 'size' => 66],
    ],
];

$line1 = $card['card_type'] === 'student' ? (string)$card['program_name'] : (string)$card['designation'];
$line2 = (string)$card['dept_name'];
$validity = trim(idc_fmt_date($card['issue_date']) . ' – ' . idc_fmt_date($card['expiry_date']), ' –');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ID Card – <?= h($card['full_name']) ?></title>
<style>
    :root { --card-w: 331.2px; --card-h: 212.16px; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; background: #e9ecef; padding: 24px; }

    .toolbar { max-width: 760px; margin: 0 auto 20px; display: flex; gap: 10px; justify-content: center; }
    .toolbar a, .toolbar button {
        padding: 8px 18px; border-radius: 6px; border: 1px solid #0d6efd;
        background: #0d6efd; color: #fff; cursor: pointer; text-decoration: none; font-size: 14px;
    }
    .toolbar a.secondary { background: #fff; color: #0d6efd; }

    .cards { display: flex; gap: 24px; justify-content: center; flex-wrap: wrap; }
    .id-card {
        position: relative; width: var(--card-w); height: var(--card-h);
        border-radius: 10px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.25);
        background: #fff url('') center/cover no-repeat;
    }
    .id-card .bg { position: absolute; inset: 0; width: 100%; height: 100%; }
    .ov { position: absolute; color: #fff; line-height: 1.25; white-space: nowrap; }
    .ov.dark { color: #1a1a2e; }
    .ov.wrap { white-space: normal; }
    .photo { position: absolute; object-fit: cover; border: 2px solid #fff; border-radius: 4px; background: #ddd; }
    .qr { position: absolute; background: #fff; padding: 3px; border-radius: 4px; }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none; }
        .cards { gap: 8mm; }
        .id-card { box-shadow: none; border: 1px dashed #bbb; break-inside: avoid; }
        @page { margin: 8mm; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a class="secondary" href="<?= APP_URL ?>/id-card/index.php">&larr; Back to list</a>
    <button onclick="window.print()">&#128424; Print Card</button>
</div>

<div class="cards">

    <!-- FRONT -->
    <div class="id-card">
        <img class="bg" src="<?= h($front_bg) ?>" alt="">
        <?php $L = $layout['front']; ?>
        <?php if ($photo !== ''): ?>
        <img class="photo" src="<?= h($photo) ?>" alt=""
             style="left:<?= $L['photo']['left'] ?>px; top:<?= $L['photo']['top'] ?>px;
                    width:<?= $L['photo']['width'] ?>px; height:<?= $L['photo']['height'] ?>px;">
        <?php else: ?>
        <div class="photo" style="left:<?= $L['photo']['left'] ?>px; top:<?= $L['photo']['top'] ?>px;
                    width:<?= $L['photo']['width'] ?>px; height:<?= $L['photo']['height'] ?>px;"></div>
        <?php endif; ?>
        <div class="ov" style="left:<?= $L['name']['left'] ?>px; top:<?= $L['name']['top'] ?>px; font-size:<?= $L['name']['size'] ?>px; font-weight:700;"><?= h($card['full_name']) ?></div>
        <div class="ov" style="left:<?= $L['id']['left'] ?>px; top:<?= $L['id']['top'] ?>px; font-size:<?= $L['id']['size'] ?>px; font-weight:600;">ID: <?= h($card['id_number']) ?></div>
        <?php if ($line1 !== ''): ?>
        <div class="ov" style="left:<?= $L['line1']['left'] ?>px; top:<?= $L['line1']['top'] ?>px; font-size:<?= $L['line1']['size'] ?>px;"><?= h($line1) ?></div>
        <?php endif; ?>
        <?php if ($line2 !== ''): ?>
        <div class="ov" style="left:<?= $L['line2']['left'] ?>px; top:<?= $L['line2']['top'] ?>px; font-size:<?= $L['line2']['size'] ?>px;"><?= h($line2) ?></div>
        <?php endif; ?>
        <?php if ($validity !== ''): ?>
        <div class="ov" style="left:<?= $L['validity']['left'] ?>px; top:<?= $L['validity']['top'] ?>px; font-size:<?= $L['validity']['size'] ?>px;">Valid: <?= h($validity) ?></div>
        <?php endif; ?>
    </div>

    <!-- BACK -->
    <div class="id-card">
        <img class="bg" src="<?= h($back_bg) ?>" alt="">
        <?php $L = $layout['back']; ?>
        <?php if ((string)$card['blood_group'] !== ''): ?>
        <div class="ov dark" style="left:<?= $L['blood']['left'] ?>px; top:<?= $L['blood']['top'] ?>px; font-size:<?= $L['blood']['size'] ?>px; font-weight:700;">Blood Group: <?= h($card['blood_group']) ?></div>
        <?php endif; ?>
        <?php if ((string)$card['phone'] !== ''): ?>
        <div class="ov dark" style="left:<?= $L['phone']['left'] ?>px; top:<?= $L['phone']['top'] ?>px; font-size:<?= $L['phone']['size'] ?>px;">Phone: <?= h($card['phone']) ?></div>
        <?php endif; ?>
        <?php if ((string)$card['address'] !== ''): ?>
        <div class="ov dark wrap" style="left:<?= $L['address']['left'] ?>px; top:<?= $L['address']['top'] ?>px; font-size:<?= $L['address']['size'] ?>px; width:<?= $L['address']['width'] ?>px;"><?= h($card['address']) ?></div>
        <?php endif; ?>
        <img class="qr" src="<?= h($qr_src) ?>" alt="QR"
             style="right:<?= $L['qr']['right'] ?>px; bottom:<?= $L['qr']['bottom'] ?>px; width:<?= $L['qr']['size'] ?>px; height:<?= $L['qr']['size'] ?>px;">
    </div>

</div>

</body>
</html>
