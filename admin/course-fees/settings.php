<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees', 'can_edit');

$errors   = [];
$settings = cf_get_settings();
$db       = db();

$page_title = 'Course Fees – Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'settings';

    if ($action === 'settings') {
        $page_title_val  = trim($_POST['page_title']    ?? '');
        $session_label   = trim($_POST['session_label'] ?? '');
        $disclaimer      = trim($_POST['disclaimer']    ?? '');
        $is_published    = isset($_POST['is_published']) ? 1 : 0;

        if ($page_title_val === '') $errors[] = 'Page title is required.';
        if ($session_label  === '') $errors[] = 'Session label is required.';

        if (empty($errors)) {
            try {
                $db->prepare(
                    'UPDATE cf_settings SET page_title=?, session_label=?, disclaimer=?, is_published=? WHERE id=1'
                )->execute([$page_title_val, $session_label, $disclaimer ?: null, $is_published]);

                log_change('course-fees', 'UPDATE', 1, 'Settings', null, null, null, 'Global settings updated.');
                flash_set('success', 'Settings saved.');
                redirect(APP_URL . '/course-fees/settings.php');
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }

    } elseif ($action === 'degree_type_toggle') {
        $dt_id     = (int)($_POST['dt_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $db->prepare('UPDATE cf_degree_types SET is_active=? WHERE id=?')->execute([$is_active, $dt_id]);
        flash_set('success', 'Degree type updated.');
        redirect(APP_URL . '/course-fees/settings.php');
    }
}

$degree_types = cf_get_degree_types();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-cog me-2 text-secondary"></i>Course Fees Settings</h1>
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

<div class="row g-4">
    <!-- Global Settings -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-sliders me-2 text-primary"></i>Global Settings
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="settings">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Page Title <span class="text-danger">*</span></label>
                        <input type="text" name="page_title"
                               value="<?= h($settings['page_title'] ?? 'Course Fee Calculator') ?>"
                               class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Session / Semester Label <span class="text-danger">*</span></label>
                        <input type="text" name="session_label"
                               value="<?= h($settings['session_label'] ?? 'Summer 2026') ?>"
                               class="form-control" placeholder="e.g. Summer 2026" required>
                        <div class="form-text">Shown as the badge on the public calculator page.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Disclaimer Text</label>
                        <textarea name="disclaimer" class="form-control" rows="4"><?= h($settings['disclaimer'] ?? '') ?></textarea>
                        <div class="form-text">Shown at the bottom of the public calculator page.</div>
                    </div>
                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_published" id="isPublished"
                                   value="1" <?= ($settings['is_published'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="isPublished">Published (visible to public)</label>
                        </div>
                    </div>
                    <div class="alert alert-info border-start border-info border-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Fee constants are now configured per-program. 
                        Edit each program individually to set admission fees, registration fees, and other fee constants.
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Settings
                        </button>
                        <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank" class="btn btn-outline-warning">
                            <i class="fas fa-external-link-alt me-1"></i> Preview Page
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Degree Type Management -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">
                <i class="fas fa-layer-group me-2 text-info"></i>Degree Type Visibility
            </div>
            <div class="card-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Degree Type</th>
                            <th class="text-center">Visible</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($degree_types as $dt): ?>
                        <tr>
                            <td class="ps-3">
                                <span class="me-1"><?= h($dt['icon'] ?? '') ?></span>
                                <?= h($dt['name']) ?>
                                <div class="small text-muted"><?= h($dt['slug']) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($dt['is_active']): ?>
                                    <span class="badge bg-success">Visible</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Hidden</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-end">
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action"    value="degree_type_toggle">
                                    <input type="hidden" name="dt_id"     value="<?= $dt['id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $dt['is_active'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn btn-sm <?= $dt['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                        <?= $dt['is_active'] ? 'Hide' : 'Show' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
