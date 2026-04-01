<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Notable Alumni';

$alumni = db()->query(
    'SELECT * FROM cms_alumni ORDER BY sort_order, id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Notable Alumni</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/alumni/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Alumni
    </a>
</div>

<?php if (empty($alumni)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-user-graduate fa-3x mb-3 d-block" style="opacity:.3"></i>
        No alumni added yet.
        <a href="<?= APP_URL ?>/cms/alumni/create.php" class="d-block mt-2">Add the first alumni</a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($alumni as $al): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <div style="width:90px;height:90px;border-radius:50%;overflow:hidden;border:3px solid #002147;margin:0 auto 16px;">
                    <?php if ($al['photo']): ?>
                    <img src="<?= UPLOAD_URL ?>/alumni/<?= h($al['photo']) ?>"
                         alt="<?= h($al['name']) ?>"
                         style="width:100%;height:100%;object-fit:cover;"
                         onerror="this.style.opacity='.3'">
                    <?php else: ?>
                    <div style="width:100%;height:100%;background:#e8eaf0;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user fa-2x text-muted" style="opacity:.4"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <h6 class="fw-bold mb-1" style="color:#002147;"><?= h($al['name']) ?></h6>
                <?php if ($al['designation']): ?>
                <p class="mb-1" style="color:#D21034;font-size:.85rem;font-weight:600;"><?= h($al['designation']) ?></p>
                <?php endif; ?>
                <?php if ($al['organization']): ?>
                <p class="mb-0 text-muted" style="font-size:.8rem;"><?= h($al['organization']) ?></p>
                <?php endif; ?>
                <div class="mt-2">
                    <?= $al['is_active']
                        ? '<span class="badge bg-success">Active</span>'
                        : '<span class="badge bg-secondary">Inactive</span>' ?>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <a href="<?= APP_URL ?>/cms/alumni/edit.php?id=<?= $al['id'] ?>"
                   class="btn btn-sm btn-outline-primary flex-fill" style="border-radius:7px;">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <form method="POST" action="<?= APP_URL ?>/cms/alumni/delete.php"
                      onsubmit="return confirm('Delete this alumni record?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $al['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
