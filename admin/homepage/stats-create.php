<?php
require_once __DIR__ . '/helpers.php';
auth_check();
require_access('homepage', 'can_edit');

$page_title = 'Add Stat';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $icon       = trim($_POST['icon']       ?? 'fas fa-star');
    $value      = trim($_POST['value']      ?? '');
    $label      = trim($_POST['label']      ?? '');
    $suffix     = trim($_POST['suffix']     ?? '+');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($value === '') $errors[] = 'Value is required.';
    if ($label === '') $errors[] = 'Label is required.';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO homepage_stats (icon, value, label, suffix, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$icon, $value, $label, $suffix ?: '+', $sort_order, $is_active]);

        flash_set('success', 'Stat added successfully.');
        redirect(APP_URL . '/homepage/index.php');
    }

    save_old(compact('icon', 'value', 'label', 'suffix', 'sort_order'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/homepage/index.php">Homepage</a></li>
            <li class="breadcrumb-item active">Add Stat</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-6">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-muted"></i>New Stat Counter</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-medium">Font Awesome Icon Class</label>
                <input type="text" name="icon" class="form-control" style="border-radius:10px;"
                       placeholder="e.g. fas fa-user-graduate"
                       value="<?= h(old('icon', 'fas fa-star')) ?>">
                <div class="form-text">Browse icons at <a href="https://fontawesome.com/icons" target="_blank" rel="noopener noreferrer">fontawesome.com/icons</a></div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-medium">Value <span class="text-danger">*</span></label>
                    <input type="text" name="value" class="form-control" style="border-radius:10px;"
                           placeholder="e.g. 15000"
                           value="<?= h(old('value', '')) ?>" required>
                    <div class="form-text">Numeric value for the animated counter.</div>
                </div>
                <div class="col-4">
                    <label class="form-label fw-medium">Suffix</label>
                    <input type="text" name="suffix" class="form-control" style="border-radius:10px;"
                           placeholder="e.g. +"
                           value="<?= h(old('suffix', '+')) ?>" maxlength="10">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Label <span class="text-danger">*</span></label>
                <input type="text" name="label" class="form-control" style="border-radius:10px;"
                       placeholder="e.g. Students Enrolled"
                       value="<?= h(old('label', '')) ?>" required>
            </div>

            <div class="row g-3 mb-4">
                <div class="col">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)old('sort_order', 0) ?>" min="0">
                </div>
                <div class="col-auto d-flex align-items-end pb-1">
                    <div class="form-check form-switch ms-2">
                        <input class="form-check-input" type="checkbox" name="is_active"
                               id="is_active" <?= old('is_active', '1') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Stat
                </button>
                <a href="<?= APP_URL ?>/homepage/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>

<div class="col-lg-6">
    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-lightbulb me-2 text-muted"></i>Helpful Icon Classes</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-2">
                <?php
                $suggestions = [
                    ['fas fa-user-graduate',       'Students'],
                    ['fas fa-chalkboard-teacher',   'Faculty'],
                    ['fas fa-book-open',            'Programs'],
                    ['fas fa-award',                'Excellence'],
                    ['fas fa-building',             'Campus'],
                    ['fas fa-flask',                'Research'],
                    ['fas fa-briefcase',            'Placement'],
                    ['fas fa-globe',                'Global'],
                    ['fas fa-trophy',               'Awards'],
                    ['fas fa-users',                'Community'],
                    ['fas fa-graduation-cap',       'Degrees'],
                    ['fas fa-star',                 'Rating'],
                ];
                foreach ($suggestions as [$cls, $lbl]):
                ?>
                <div class="col-6">
                    <button type="button" class="btn btn-light w-100 text-start"
                            style="border-radius:8px;font-size:.8rem;"
                            onclick="document.querySelector('[name=icon]').value='<?= $cls ?>'">
                        <i class="<?= $cls ?> me-2" style="color:#1a3a5c;width:18px;text-align:center;"></i>
                        <?= $lbl ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
