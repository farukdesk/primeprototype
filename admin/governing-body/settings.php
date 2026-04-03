<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('governing-body', 'can_edit');

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];
$page_type   = $_GET['page_type'] ?? $_POST['page_type'] ?? '';
if (!in_array($page_type, $valid_types, true)) {
    flash_set('error', 'Invalid page type.');
    redirect(APP_URL . '/governing-body/index.php');
}

$stmt = db()->prepare('SELECT * FROM governing_body_pages WHERE page_type = ? LIMIT 1');
$stmt->execute([$page_type]);
$settings = $stmt->fetch() ?: null;

$page_title = 'Settings – ' . ucwords(str_replace('-', ' ', $page_type));
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title      = trim($_POST['title']            ?? '');
    $subtitle   = trim($_POST['subtitle']         ?? '');
    $hero_intro = trim($_POST['hero_intro']       ?? '');
    $meta_desc  = trim($_POST['meta_description'] ?? '');
    $is_pub     = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        if ($settings) {
            db()->prepare(
                'UPDATE governing_body_pages
                 SET title=?, subtitle=?, hero_intro=?, meta_description=?, is_published=?
                 WHERE page_type=?'
            )->execute([$title, $subtitle, $hero_intro, $meta_desc, $is_pub, $page_type]);
        } else {
            db()->prepare(
                'INSERT INTO governing_body_pages
                 (page_type, title, subtitle, hero_intro, meta_description, is_published)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$page_type, $title, $subtitle, $hero_intro, $meta_desc, $is_pub]);
        }
        flash_set('success', 'Settings saved.');
        redirect(APP_URL . '/governing-body/settings.php?page_type=' . urlencode($page_type));
    }

    // Re-populate on error
    $settings = array_merge($settings ?: [], compact('title','subtitle','hero_intro','meta_desc','is_pub'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/governing-body/index.php">Governing Body</a></li>
            <li class="breadcrumb-item active"><?= h(ucwords(str_replace('-', ' ', $page_type))) ?> – Settings</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($page_type) ?>"
       class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
        <i class="fas fa-users me-1"></i> Manage Members
    </a>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="page_type" value="<?= h($page_type) ?>">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Page Settings</h6>
        </div>
        <div class="card-body p-4">

            <div class="mb-3">
                <label class="form-label fw-medium">Hero Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control"
                       value="<?= h($settings['title'] ?? '') ?>"
                       required maxlength="255" placeholder="e.g. Board of Trustees">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Subtitle</label>
                <input type="text" name="subtitle" class="form-control"
                       value="<?= h($settings['subtitle'] ?? '') ?>"
                       maxlength="255" placeholder="e.g. Governance & Leadership">
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Hero Intro Text</label>
                <textarea name="hero_intro" class="form-control" rows="4"
                          placeholder="Short paragraph displayed below the title in the hero section."><?= h($settings['hero_intro'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Meta Description</label>
                <textarea name="meta_description" class="form-control" rows="2"
                          placeholder="Used for SEO meta description tag."><?= h($settings['meta_description'] ?? '') ?></textarea>
                <div class="form-text">Recommended: 140–160 characters.</div>
            </div>

            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="is_published" name="is_published"
                       value="1" <?= ($settings['is_published'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium" for="is_published">Published (visible on website)</label>
            </div>

        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Settings
        </button>
        <a href="<?= APP_URL ?>/governing-body/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
