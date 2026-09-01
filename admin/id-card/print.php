<?php
/**
 * ID Card – Print Preview (front + back)
 *
 * The front side is generated server-side from the SVG design in
 * "/ID Card SVG/": every piece of sample data baked into the template
 * (photo, name, ID, program/batch, blood group, issue/expiry dates and
 * the barcode) is replaced with the generated card's data.
 * The barcode is a real Code 39 barcode that encodes the ID number.
 * The back side is also rendered server-side: the university information
 * (address, phone numbers, website, email, facebook) comes from Settings.
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

// Always use the CURRENT photo from the student profile when the card is
// linked to a student record (falls back to the photo stored on the card).
if (!empty($card['student_ref_id'])) {
    try {
        $st = db()->prepare('SELECT photo FROM students WHERE id = ?');
        $st->execute([(int)$card['student_ref_id']]);
        $live_photo = trim((string)$st->fetchColumn());
        if ($live_photo !== '') $card['photo'] = $live_photo;
    } catch (Throwable $e) {
        // keep the stored photo
    }
}

$front_svg = idc_render_front_svg($card);
$back_svg  = idc_render_back_svg($card['card_type']);
$back_url  = idc_template_url($card['card_type'], 'back');

if ($front_svg === '') {
    flash_set('danger', 'Could not load the ID card SVG template (ID Card SVG/Student_ID_Front.svg).');
    redirect(APP_URL . '/id-card/index.php');
}

// Strip the XML prolog so the SVGs can be inlined into the HTML page
$front_svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $front_svg);
if ($back_svg !== '') {
    $back_svg = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $back_svg);
}
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
        background: #fff;
    }
    .id-card svg, .id-card img { display: block; width: 100%; height: 100%; }

    @media print {
        body { background: #fff; padding: 0; }
        .toolbar { display: none; }
        .cards { gap: 8mm; }
        .id-card { box-shadow: none; break-inside: avoid; border-radius: 0; }
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

    <!-- FRONT: SVG template with all sample data replaced by generated info -->
    <div class="id-card">
        <?= $front_svg ?>
    </div>

    <!-- BACK: university information from the design; texts editable in Settings -->
    <div class="id-card">
        <?php if ($back_svg !== ''): ?>
            <?= $back_svg ?>
        <?php else: ?>
            <img src="<?= h($back_url) ?>" alt="ID Card Back">
        <?php endif; ?>
    </div>

</div>

</body>
</html>
