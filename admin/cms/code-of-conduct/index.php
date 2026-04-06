<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Code of Conduct';

$sections = db()->query(
    'SELECT s.*, (SELECT COUNT(*) FROM cms_coc_items i WHERE i.section_id = s.id AND i.is_active = 1) AS item_count
     FROM cms_coc_sections s ORDER BY s.sort_order, s.id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Code of Conduct</li>
        </ol>
    </nav>
    <a href="<?= SITE_URL ?>/code-of-conduct.php" target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> View Public Page
    </a>
</div>

<?php flash_show(); ?>

<?php foreach ($sections as $sec): ?>
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:1.4rem;color:#4f8ef7;"><i class="<?= h($sec['icon']) ?>"></i></span>
            <div>
                <h6 class="mb-0 fw-semibold"><?= h($sec['title']) ?></h6>
                <?php if ($sec['subtitle']): ?>
                <small class="text-muted"><?= h($sec['subtitle']) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-secondary border"><?= $sec['item_count'] ?> items</span>
            <?php if ($sec['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
            <?php endif; ?>
            <a href="section-edit.php?id=<?= $sec['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:8px;">
                <i class="fas fa-edit me-1"></i>Edit Section
            </a>
            <a href="item-create.php?section_id=<?= $sec['id'] ?>" class="btn btn-sm btn-primary" style="border-radius:8px;">
                <i class="fas fa-plus me-1"></i>Add Item
            </a>
        </div>
    </div>

    <?php
    $items = db()->prepare('SELECT * FROM cms_coc_items WHERE section_id = ? ORDER BY sort_order, id');
    $items->execute([$sec['id']]);
    $items = $items->fetchAll();
    ?>

    <?php if ($items): ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:60px;">#</th>
                    <th>Conduct Item</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="text-muted"><?= (int)$item['sort_order'] ?></td>
                    <td style="font-size:.875rem;"><?= h($item['item_text']) ?></td>
                    <td>
                        <?php if ($item['is_active']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                        <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="item-edit.php?id=<?= $item['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit" style="border-radius:6px;padding:3px 8px;">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                            <form method="POST" action="item-delete.php" onsubmit="return confirm('Delete this conduct item?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete" style="border-radius:6px;padding:3px 8px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="fas fa-list-ul fa-2x mb-2 d-block opacity-25"></i>
        No items yet.
        <a href="item-create.php?section_id=<?= $sec['id'] ?>">Add the first item</a>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
