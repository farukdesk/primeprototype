<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('policy-procedure');
require_once __DIR__ . '/helpers.php';
if (!pp_can_create()) { flash('error', 'You do not have permission to create sections.'); header('Location: ' . APP_URL . '/policy-procedure/index.php'); exit; }

$page_title  = 'New Policy Section';
$errors      = [];
$title       = '';
$content     = '';
$sort_order  = 0;
$is_active   = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title      = trim($_POST['title'] ?? '');
    $content    = $_POST['content'] ?? '';
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') $errors[] = 'Section title is required.';
    if ($content === '') $errors[] = 'Section content is required.';

    if (empty($errors)) {
        $stmt = db()->prepare(
            'INSERT INTO policy_procedure_sections (title, content, sort_order, is_active, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $content, $sort_order, $is_active, $_SESSION['user_id']]);
        log_change($_SESSION['user_id'], 'create', 'Created policy section: ' . $title);
        flash('success', 'Section "' . $title . '" created successfully.');
        header('Location: ' . APP_URL . '/policy-procedure/index.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/policy-procedure/index.php">Policy &amp; Procedure</a></li>
            <li class="breadcrumb-item active">New Section</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/policy-procedure/index.php" class="btn btn-outline-secondary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3"><strong><i class="fas fa-plus me-2 text-primary"></i>New Policy Section</strong></div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Section Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" value="<?= h($title) ?>" placeholder="e.g. Attendance Requirement" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Content <span class="text-danger">*</span></label>
                <textarea name="content" id="pp-content" class="form-control" rows="12"><?= h($content) ?></textarea>
            </div>
            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label fw-semibold">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?= (int)$sort_order ?>" min="0" max="9999">
                    <div class="form-text">Lower numbers appear first.</div>
                </div>
                <div class="col-sm-4 d-flex align-items-center mt-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $is_active ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active (visible on public page)</label>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;">
                <i class="fas fa-save me-1"></i> Create Section
            </button>
            <a href="<?= APP_URL ?>/policy-procedure/index.php" class="btn btn-outline-secondary ms-2" style="border-radius:10px;">Cancel</a>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@5.10.9/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#pp-content',
    plugins: 'lists table link',
    toolbar: 'undo redo | formatselect | bold italic underline | bullist numlist | table | link | removeformat',
    menubar: false,
    height: 400,
    skin: 'oxide',
    content_css: 'default',
    content_style: 'body { font-family: Inter, sans-serif; font-size: 15px; }'
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
