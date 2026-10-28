<?php
/**
 * Blog — manage categories.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_view');

$page_title = 'Blog Categories';
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && blog_can('can_create')) {
        $name       = trim($_POST['name'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if ($name === '') {
            $errors[] = 'Category name is required.';
        } else {
            $slug = blog_unique_slug('blog_categories', blog_slugify($name));
            db()->prepare(
                'INSERT INTO blog_categories (name, slug, description, sort_order) VALUES (?,?,?,?)'
            )->execute([$name, $slug, $desc !== '' ? $desc : null, $sort_order]);
            flash_set('success', 'Category <strong>' . h($name) . '</strong> created.');
            redirect(APP_URL . '/blog/categories.php');
        }
    }

    if ($action === 'update' && blog_can('can_edit')) {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active  = isset($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $name === '') {
            $errors[] = 'Category name is required.';
        } else {
            db()->prepare(
                'UPDATE blog_categories SET name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $desc !== '' ? $desc : null, $sort_order, $is_active, $id]);
            flash_set('success', 'Category updated.');
            redirect(APP_URL . '/blog/categories.php');
        }
    }

    if ($action === 'delete' && blog_can('can_delete')) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Posts keep existing (category_id becomes NULL via FK).
            db()->prepare('DELETE FROM blog_categories WHERE id = ?')->execute([$id]);
            flash_set('success', 'Category deleted. Its posts are now uncategorised.');
        }
        redirect(APP_URL . '/blog/categories.php');
    }
}

$cats = db()->query(
    'SELECT c.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id) AS post_count
     FROM blog_categories c
     ORDER BY c.sort_order, c.name'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/blog/index.php">Blog</a></li>
            <li class="breadcrumb-item active">Categories</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <?php if (blog_can('can_create')): ?>
        <div class="card">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-muted"></i>New Category</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="190">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                    <button class="btn btn-primary w-100" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Create Category
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4" style="width:40px;">#</th>
                                <th>Name</th>
                                <th>Posts</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th style="width:120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($cats)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No categories yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cats as $idx => $c): ?>
                            <tr>
                                <td class="px-4"><?= $idx + 1 ?></td>
                                <td>
                                    <strong><?= h($c['name']) ?></strong>
                                    <div style="font-size:.75rem;color:#9ca3af;"><?= h($c['slug']) ?></div>
                                    <?php if ($c['description']): ?>
                                    <div style="font-size:.75rem;color:#b0b7c9;"><?= h($c['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= (int)$c['post_count'] ?></span></td>
                                <td><?= (int)$c['sort_order'] ?></td>
                                <td>
                                    <?= $c['is_active']
                                        ? '<span class="badge bg-success">Active</span>'
                                        : '<span class="badge bg-secondary">Inactive</span>' ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if (blog_can('can_edit')): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"
                                                data-bs-toggle="modal" data-bs-target="#editCat<?= $c['id'] ?>" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if (blog_can('can_delete')): ?>
                                        <form method="POST"
                                              onsubmit="return confirm('Delete category \"<?= h(addslashes($c['name'])) ?>\"? Its posts become uncategorised.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
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
    </div>
</div>

<!-- Edit modals -->
<?php foreach ($cats as $c): ?>
<div class="modal fade" id="editCat<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <div class="modal-header">
                    <h6 class="modal-title">Edit Category</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="190"
                               value="<?= h($c['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= h($c['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= (int)$c['sort_order'] ?>">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="catActive<?= $c['id'] ?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="catActive<?= $c['id'] ?>">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
