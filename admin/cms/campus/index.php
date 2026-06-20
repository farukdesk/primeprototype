<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Campus Life';

$items = db()->query(
    'SELECT * FROM cms_campus_items ORDER BY sort_order, id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Campus Life</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/campus/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Campus Item
    </a>
</div>

<?php if (empty($items)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-university fa-3x mb-3 d-block" style="opacity:.3"></i>
        No campus life items yet.
        <a href="<?= APP_URL ?>/cms/campus/create.php" class="d-block mt-2">Add the first item</a>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($items as $item): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div style="position:relative;background:#e8eaf0;border-radius:12px 12px 0 0;overflow:hidden;padding-top:56%;">
                <?php if ($item['image']): ?>
                <img src="<?= UPLOAD_URL ?>/campus/<?= h($item['image']) ?>"
                     alt="<?= h($item['title']) ?>"
                     style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;"
                     onerror="this.parentElement.style.background='#d1d5db'">
                <?php else: ?>
                <div style="position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-image fa-2x text-muted" style="opacity:.4"></i>
                </div>
                <?php endif; ?>
                <span style="position:absolute;top:10px;right:10px;"
                      class="badge <?= $item['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
                <span style="position:absolute;top:10px;left:10px;background:rgba(0,0,0,.55);"
                      class="badge text-white">
                    #<?= (int)$item['sort_order'] ?>
                </span>
            </div>
            <div class="card-body">
                <h6 class="mb-1 fw-semibold"><?= h($item['title']) ?></h6>
                <?php if ($item['link_url']): ?>
                <a href="<?= h($item['link_url']) ?>" target="_blank" rel="noopener"
                   class="text-primary" style="font-size:.78rem;word-break:break-all;">
                    <?= h($item['link_url']) ?>
                </a>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <a href="<?= APP_URL ?>/cms/campus/edit.php?id=<?= $item['id'] ?>"
                   class="btn btn-sm btn-outline-primary flex-fill" style="border-radius:7px;">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <form method="POST" action="<?= APP_URL ?>/cms/campus/delete.php"
                      onsubmit="return confirm('Delete this campus item?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
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
