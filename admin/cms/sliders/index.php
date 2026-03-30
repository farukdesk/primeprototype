<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Sliders';

$sliders = db()->query(
    'SELECT * FROM cms_sliders ORDER BY sort_order, id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Sliders</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/sliders/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Slide
    </a>
</div>

<?php if (empty($sliders)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-images fa-3x mb-3 d-block" style="opacity:.3"></i>
        No slider images yet.
        <a href="<?= APP_URL ?>/cms/sliders/create.php" class="d-block mt-2">Add the first slide</a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($sliders as $sl): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <!-- Slide thumbnail -->
            <div style="position:relative;background:#e8eaf0;border-radius:12px 12px 0 0;overflow:hidden;padding-top:56%;">
                <img src="<?= UPLOAD_URL ?>/sliders/<?= h($sl['image']) ?>"
                     alt="<?= h($sl['title'] ?? '') ?>"
                     style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;"
                     onerror="this.parentElement.style.background='#d1d5db'">
                <!-- Status badge -->
                <span style="position:absolute;top:10px;right:10px;"
                      class="badge <?= $sl['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $sl['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
                <!-- Order badge -->
                <span style="position:absolute;top:10px;left:10px;background:rgba(0,0,0,.55);"
                      class="badge text-white">
                    #<?= (int)$sl['sort_order'] ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($sl['title']): ?>
                <h6 class="mb-1 fw-semibold"><?= h($sl['title']) ?></h6>
                <?php endif; ?>
                <?php if ($sl['subtitle']): ?>
                <p class="text-muted mb-1" style="font-size:.83rem;"><?= h($sl['subtitle']) ?></p>
                <?php endif; ?>
                <?php if ($sl['link_url']): ?>
                <a href="<?= h($sl['link_url']) ?>" target="_blank" rel="noopener"
                   class="text-primary" style="font-size:.78rem;word-break:break-all;">
                    <?= h($sl['link_url']) ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <a href="<?= APP_URL ?>/cms/sliders/edit.php?id=<?= $sl['id'] ?>"
                   class="btn btn-sm btn-outline-primary flex-fill" style="border-radius:7px;">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <form method="POST" action="<?= APP_URL ?>/cms/sliders/delete.php"
                      onsubmit="return confirm('Delete this slide?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $sl['id'] ?>">
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
