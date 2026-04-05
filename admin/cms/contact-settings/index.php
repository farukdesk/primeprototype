<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Contact Section Settings';
$errors     = [];

// Load current settings
$rows = db()->query('SELECT setting_key, setting_value FROM cms_contact_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'section_subtitle', 'section_title', 'section_description',
        'card_1_icon', 'card_1_title', 'card_1_value', 'card_1_href', 'card_1_sub',
        'card_2_icon', 'card_2_title', 'card_2_value', 'card_2_href', 'card_2_sub',
        'card_3_icon', 'card_3_title', 'card_3_value', 'card_3_href', 'card_3_sub',
        'card_4_icon', 'card_4_title', 'card_4_value', 'card_4_href', 'card_4_sub',
        'btn1_text', 'btn1_url', 'btn2_text', 'btn2_url',
    ];

    $stmt = db()->prepare(
        'INSERT INTO cms_contact_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $value ?: null]);
        $settings[$key] = $value;
    }

    flash_set('success', 'Contact section settings saved.');
    redirect(APP_URL . '/cms/contact-settings/index.php');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Contact Section Settings</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-10">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-phone-alt me-2 text-muted"></i>Contact Section Settings</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Section Heading</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Subtitle Label</label>
                    <input type="text" name="section_subtitle" class="form-control"
                           value="<?= h($settings['section_subtitle'] ?? 'Get In Touch') ?>" maxlength="100">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Main Title</label>
                    <input type="text" name="section_title" class="form-control"
                           value="<?= h($settings['section_title'] ?? "We're Here to Help You") ?>" maxlength="255">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium">Description</label>
                <textarea name="section_description" class="form-control" rows="2"><?= h($settings['section_description'] ?? '') ?></textarea>
            </div>

            <?php
            $cardDefaults = [
                1 => ['icon' => 'fas fa-phone-alt',     'title' => 'Call Us',        'value' => '01969-955566',             'href' => 'tel:+8801969955566',                                  'sub' => 'Mon – Fri, 9am – 5pm'],
                2 => ['icon' => 'fas fa-envelope',       'title' => 'Email Us',       'value' => 'info@primeuniversity.ac.bd',   'href' => 'mailto:info@primeuniversity.ac.bd',                   'sub' => 'We reply within 24 hours'],
                3 => ['icon' => 'fas fa-map-marker-alt', 'title' => 'Visit Campus',   'value' => '114/116, Mazar Rd, Dhaka-1216',     'href' => 'https://maps.google.com/?q=Prime+University+Dhaka',    'sub' => 'View on Google Maps'],
                4 => ['icon' => 'fas fa-clock',          'title' => 'Office Hours',   'value' => 'Sunday – Thursday',            'href' => '#',                                                    'sub' => '9:00 AM – 5:00 PM'],
            ];
            for ($n = 1; $n <= 4; $n++):
                $d = $cardDefaults[$n];
            ?>
            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Contact Card <?= $n ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label fw-medium">Icon</label>
                    <div class="input-group">
                        <span class="input-group-text" style="min-width:40px;">
                            <i id="card<?= $n ?>IconPreview" class="<?= h($settings["card_{$n}_icon"] ?? $d['icon']) ?>"></i>
                        </span>
                        <input type="text" name="card_<?= $n ?>_icon" id="card<?= $n ?>IconInput"
                               class="form-control"
                               value="<?= h($settings["card_{$n}_icon"] ?? $d['icon']) ?>"
                               placeholder="<?= h($d['icon']) ?>" maxlength="100">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Title</label>
                    <input type="text" name="card_<?= $n ?>_title" class="form-control"
                           value="<?= h($settings["card_{$n}_title"] ?? $d['title']) ?>" maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Display Value</label>
                    <input type="text" name="card_<?= $n ?>_value" class="form-control"
                           value="<?= h($settings["card_{$n}_value"] ?? $d['value']) ?>" maxlength="255">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Link (href)</label>
                    <input type="text" name="card_<?= $n ?>_href" class="form-control"
                           value="<?= h($settings["card_{$n}_href"] ?? $d['href']) ?>"
                           placeholder="<?= h($d['href']) ?>" maxlength="500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sub-label</label>
                    <input type="text" name="card_<?= $n ?>_sub" class="form-control"
                           value="<?= h($settings["card_{$n}_sub"] ?? $d['sub']) ?>" maxlength="255">
                </div>
            </div>
            <?php endfor; ?>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Buttons</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-medium">Button 1 Text</label>
                    <input type="text" name="btn1_text" class="form-control"
                           value="<?= h($settings['btn1_text'] ?? 'Send a Message') ?>" maxlength="100">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Button 1 URL</label>
                    <input type="text" name="btn1_url" class="form-control"
                           value="<?= h($settings['btn1_url'] ?? 'contact.php') ?>" maxlength="500">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Button 2 Text</label>
                    <input type="text" name="btn2_text" class="form-control"
                           value="<?= h($settings['btn2_text'] ?? 'Apply Online') ?>" maxlength="100">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Button 2 URL</label>
                    <input type="text" name="btn2_url" class="form-control"
                           value="<?= h($settings['btn2_url'] ?? 'admission.php') ?>" maxlength="500">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Save Settings
            </button>
        </form>
    </div>
</div>
</div>
</div>

<script>
(function () {
    [1, 2, 3, 4].forEach(function (n) {
        var input   = document.getElementById('card' + n + 'IconInput');
        var preview = document.getElementById('card' + n + 'IconPreview');
        if (!input || !preview) return;
        input.addEventListener('input', function () {
            preview.className = this.value.trim() || 'fas fa-star';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
