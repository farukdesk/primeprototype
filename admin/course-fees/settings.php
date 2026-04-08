<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

// Only super admin or module editor can access settings
if (!is_super_admin() && !cf_can_edit()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/course-fees/index.php');
}

$page_title = 'Course Fees – Settings';
$errors     = [];
$db         = db();
$settings   = cf_get_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $page_title_val  = trim($_POST['page_title']    ?? '');
    $page_subtitle   = trim($_POST['page_subtitle'] ?? '');
    $note_text       = trim($_POST['note_text']     ?? '');
    $currency        = trim($_POST['currency']      ?? 'BDT');
    $is_published    = isset($_POST['is_published']) ? 1 : 0;

    if ($page_title_val === '') $errors[] = 'Page title is required.';
    if ($currency === '')       $errors[] = 'Currency is required.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO cf_settings (id, page_title, page_subtitle, note_text, currency, is_published)
             VALUES (1, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               page_title    = VALUES(page_title),
               page_subtitle = VALUES(page_subtitle),
               note_text     = VALUES(note_text),
               currency      = VALUES(currency),
               is_published  = VALUES(is_published)'
        )->execute([$page_title_val, $page_subtitle ?: null, $note_text ?: null, $currency, $is_published]);

        flash_set('success', 'Settings saved successfully.');
        redirect(APP_URL . '/course-fees/settings.php');
    }

    save_old($_POST);
}

$fv = [
    'page_title'   => old('page_title',   $settings['page_title']   ?? ''),
    'page_subtitle'=> old('page_subtitle',$settings['page_subtitle'] ?? ''),
    'note_text'    => old('note_text',    $settings['note_text']     ?? ''),
    'currency'     => old('currency',     $settings['currency']      ?? 'BDT'),
    'is_published' => old('is_published', $settings['is_published']  ?? 1),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Calculator Settings</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <form method="post">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-globe me-2 text-primary"></i>Public Page Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page Title <span class="text-danger">*</span></label>
                        <input type="text" name="page_title" class="form-control" value="<?= h($fv['page_title']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page Subtitle / Description</label>
                        <textarea name="page_subtitle" class="form-control" rows="2"><?= h($fv['page_subtitle']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Disclaimer / Note</label>
                        <textarea name="note_text" class="form-control" rows="4"><?= h($fv['note_text']) ?></textarea>
                        <div class="form-text">Shown below the calculator on the public page.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Currency Label</label>
                            <input type="text" name="currency" class="form-control" value="<?= h($fv['currency']) ?>" maxlength="10" required>
                            <div class="form-text">e.g. BDT, USD</div>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1"
                                       <?= $fv['is_published'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="is_published">
                                    Publish calculator page to public
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank" class="btn btn-outline-warning">
                    <i class="fas fa-external-link-alt me-1"></i> Preview Page
                </a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
