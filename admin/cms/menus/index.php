<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Navigation Menus';

// Fetch all menu items ordered so parents come before children
$items = db()->query(
    'SELECT m.*, p.label AS parent_label
     FROM cms_menus m
     LEFT JOIN cms_menus p ON p.id = m.parent_id
     ORDER BY COALESCE(m.parent_id, m.id), m.sort_order, m.id'
)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Navigation Menus</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/cms/menus/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Menu Item
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Label</th>
                        <th>URL</th>
                        <th>Type</th>
                        <th>Parent</th>
                        <th>Target</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No menu items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <?php if ($item['parent_id']): ?>
                                <span class="text-muted me-1" style="font-size:.75rem;">└</span>
                            <?php endif; ?>
                            <?php if ($item['icon']): ?>
                                <i class="<?= h($item['icon']) ?> me-1 text-muted"></i>
                            <?php endif; ?>
                            <strong><?= h($item['label']) ?></strong>
                        </td>
                        <td>
                            <code style="font-size:.78rem;"><?= h($item['url']) ?></code>
                        </td>
                        <td>
                            <?php
                            $type_badges = [
                                'link'      => ['bg-secondary', 'Link'],
                                'dropdown'  => ['bg-info text-dark', 'Dropdown'],
                                'megamenu'  => ['bg-warning text-dark', 'Megamenu'],
                            ];
                            [$cls, $lbl] = $type_badges[$item['type']] ?? ['bg-secondary', $item['type']];
                            ?>
                            <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                        </td>
                        <td><?= $item['parent_label'] ? h($item['parent_label']) : '<span class="text-muted">—</span>' ?></td>
                        <td><code><?= h($item['target']) ?></code></td>
                        <td><?= (int)$item['sort_order'] ?></td>
                        <td>
                            <?= $item['is_active']
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/menus/edit.php?id=<?= $item['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/cms/menus/delete.php"
                                      onsubmit="return confirm('Delete \"<?= h(addslashes($item['label'])) ?>\"?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
