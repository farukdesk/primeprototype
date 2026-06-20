<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Why Choose Us';

$items = db()->query(
    'SELECT * FROM cms_features ORDER BY sort_order, id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Why Choose Us</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/why-choose-us/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add New Feature
    </a>
</div>

<?php if (empty($items)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-star fa-3x mb-3 d-block" style="opacity:.3"></i>
        No feature cards yet.
        <a href="<?= APP_URL ?>/cms/why-choose-us/create.php" class="d-block mt-2">Add the first card</a>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Feature Cards</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:70px;">Order</th>
                    <th style="width:60px;">Icon</th>
                    <th>Title</th>
                    <th>Description</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td class="text-center text-muted fw-medium"><?= (int)$item['sort_order'] ?></td>
                    <td class="text-center fs-4 text-primary">
                        <i class="<?= h($item['icon']) ?>"></i>
                    </td>
                    <td class="fw-medium"><?= h($item['title']) ?></td>
                    <td class="text-muted" style="max-width:360px;">
                        <?= h(rtrim(mb_substr($item['description'] ?? '', 0, 100)) . (mb_strlen($item['description'] ?? '') > 100 ? '…' : '')) ?>
                    </td>
                    <td>
                        <span class="badge <?= $item['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="<?= APP_URL ?>/cms/why-choose-us/edit.php?id=<?= $item['id'] ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form method="POST" action="<?= APP_URL ?>/cms/why-choose-us/delete.php"
                                  onsubmit="return confirm('Delete this feature card?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
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
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
