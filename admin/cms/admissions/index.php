<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Admissions Section';
$errors     = [];

// Load current settings
$rows = db()->query('SELECT setting_key, setting_value FROM cms_admission_settings')->fetchAll();
$settings = [];
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $fields = [
        'badge_text', 'title', 'title_accent', 'description',
        'btn1_text', 'btn1_url', 'btn2_text', 'btn2_url',
        'info_1_icon', 'info_1_title', 'info_1_text',
        'info_2_icon', 'info_2_title', 'info_2_text',
        'info_3_icon', 'info_3_title', 'info_3_text',
    ];

    $stmt = db()->prepare(
        'INSERT INTO cms_admission_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($fields as $key) {
        $value = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $value ?: null]);
        $settings[$key] = $value;
    }

    flash_set('success', 'Admissions section settings saved.');
    redirect(APP_URL . '/cms/admissions/index.php');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Admissions Section</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-9">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-graduation-cap me-2 text-muted"></i>Admissions Section Settings</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <h6 class="fw-semibold mb-3 text-muted border-bottom pb-2">Section Heading</h6>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Badge Text</label>
                    <input type="text" name="badge_text" class="form-control"
                           value="<?= h($settings['badge_text'] ?? 'Admissions Open') ?>" maxlength="100">
                    <div class="form-text">e.g. "Admissions Open"</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Title</label>
                    <input type="text" name="title" class="form-control"
                           value="<?= h($settings['title'] ?? 'Begin Your Journey at') ?>" maxlength="255">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Title Accent (highlighted word)</label>
                <input type="text" name="title_accent" class="form-control"
                       value="<?= h($settings['title_accent'] ?? 'Prime University') ?>" maxlength="255">
                <div class="form-text">This portion of the title is rendered in the accent colour.</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= h($settings['description'] ?? '') ?></textarea>
            </div>

            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Buttons</h6>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label fw-medium">Button 1 Text</label>
                    <input type="text" name="btn1_text" class="form-control"
                           value="<?= h($settings['btn1_text'] ?? 'Apply Now') ?>" maxlength="100">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Button 1 URL</label>
                    <input type="text" name="btn1_url" class="form-control"
                           value="<?= h($settings['btn1_url'] ?? 'admission.php') ?>" maxlength="500">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Button 2 Text</label>
                    <input type="text" name="btn2_text" class="form-control"
                           value="<?= h($settings['btn2_text'] ?? 'Scholarships') ?>" maxlength="100">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Button 2 URL</label>
                    <input type="text" name="btn2_url" class="form-control"
                           value="<?= h($settings['btn2_url'] ?? 'scholarships-waivers.php') ?>" maxlength="500">
                </div>
            </div>

            <?php for ($n = 1; $n <= 3; $n++): ?>
            <h6 class="fw-semibold mb-3 mt-4 text-muted border-bottom pb-2">Info Card <?= $n ?></h6>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Icon</label>
                    <div class="input-group">
                        <span class="input-group-text" style="min-width:40px;">
                            <i id="info<?= $n ?>IconPreview" class="<?= h($settings["info_{$n}_icon"] ?? 'fas fa-star') ?>"></i>
                        </span>
                        <input type="text" name="info_<?= $n ?>_icon" id="info<?= $n ?>IconInput"
                               class="form-control"
                               value="<?= h($settings["info_{$n}_icon"] ?? '') ?>"
                               placeholder="fas fa-calendar-alt" maxlength="100">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Title</label>
                    <input type="text" name="info_<?= $n ?>_title" class="form-control"
                           value="<?= h($settings["info_{$n}_title"] ?? '') ?>" maxlength="150">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Text / Sub-label</label>
                    <input type="text" name="info_<?= $n ?>_text" class="form-control"
                           value="<?= h($settings["info_{$n}_text"] ?? '') ?>" maxlength="255">
                </div>
            </div>
            <?php endfor; ?>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<script>
(function () {
    [1, 2, 3].forEach(function (n) {
        var input   = document.getElementById('info' + n + 'IconInput');
        var preview = document.getElementById('info' + n + 'IconPreview');
        if (!input || !preview) return;
        input.addEventListener('input', function () {
            preview.className = this.value.trim() || 'fas fa-star';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
