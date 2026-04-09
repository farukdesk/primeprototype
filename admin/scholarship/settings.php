<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

if (!is_super_admin() && !sc_can_edit()) {
    flash_set('error', 'Access denied.');
    redirect(APP_URL . '/scholarship/index.php');
}

$page_title = 'Scholarship Settings';
$errors     = [];
$db         = db();
$settings   = sc_get_settings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $gpa_label       = trim($_POST['gpa_label']       ?? '');
    $max_combined_gpa = trim($_POST['max_combined_gpa'] ?? '');

    if ($gpa_label === '') $errors[] = 'GPA label is required.';
    if (!is_numeric($max_combined_gpa) || (float)$max_combined_gpa <= 0) $errors[] = 'Max combined GPA must be a positive number greater than 0.';

    if (empty($errors)) {
        $db->prepare(
            'INSERT INTO sc_settings (id, gpa_label, max_combined_gpa)
             VALUES (1, ?, ?)
             ON DUPLICATE KEY UPDATE gpa_label = VALUES(gpa_label), max_combined_gpa = VALUES(max_combined_gpa)'
        )->execute([$gpa_label, (float)$max_combined_gpa]);

        flash_set('success', 'Scholarship settings saved.');
        redirect(APP_URL . '/scholarship/settings.php');
    }

    save_old($_POST);
}

$fv = [
    'gpa_label'        => old('gpa_label',        $settings['gpa_label']        ?? 'SSC+HSC Combined GPA'),
    'max_combined_gpa' => old('max_combined_gpa',  $settings['max_combined_gpa'] ?? '10.00'),
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Scholarship Settings</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/scholarship/index.php">Scholarships</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/scholarship/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <form method="post">
            <?= csrf_field() ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header fw-semibold py-3"><i class="fas fa-sliders-h me-2 text-primary"></i>GPA Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">GPA Label <span class="text-danger">*</span></label>
                        <input type="text" name="gpa_label" class="form-control" value="<?= h($fv['gpa_label']) ?>" required
                               placeholder="e.g. SSC+HSC Combined GPA">
                        <div class="form-text">Label shown on award forms for GPA-based scholarships.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Max Combined GPA <span class="text-danger">*</span></label>
                        <input type="number" name="max_combined_gpa" class="form-control" step="0.01" min="0.01"
                               value="<?= h($fv['max_combined_gpa']) ?>" required placeholder="e.g. 10.00">
                        <div class="form-text">Maximum possible combined GPA (e.g. SSC 5.0 + HSC 5.0 = 10.0).</div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
