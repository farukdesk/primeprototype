<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$page_title = 'Departments';

// Handle toggle via POST (status toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'toggle') {
    csrf_check();
    $tid = (int)($_POST['id'] ?? 0);
    db()->prepare('UPDATE dept_departments SET is_active = 1 - is_active WHERE id = ?')->execute([$tid]);
    flash_set('success', 'Department status updated.');
    redirect(APP_URL . '/departments/index.php');
}

$departments = db()->query('SELECT * FROM dept_departments ORDER BY id ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Departments</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/departments/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Department
    </a>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-building-columns me-2 text-muted"></i>All Departments</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($departments) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:50px;">#</th>
                        <th style="width:70px;">Image</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Faculty Label</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No departments found. <a href="<?= APP_URL ?>/departments/create.php">Create one now</a>.</td></tr>
                <?php else: ?>
                    <?php foreach ($departments as $i => $dept): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <?php if (!empty($dept['image'])): ?>
                            <img src="<?= UPLOAD_URL ?>/departments/<?= h($dept['image']) ?>"
                                 alt="" style="width:52px;height:40px;border-radius:6px;object-fit:cover;"
                                 onerror="this.style.display='none'">
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-medium"><?= h($dept['name']) ?></div>
                            <small class="text-muted">slug: <?= h($dept['slug']) ?></small>
                        </td>
                        <td><code><?= h($dept['code']) ?></code></td>
                        <td><?= h($dept['faculty_label']) ?></td>
                        <td>
                            <form method="POST" action="<?= APP_URL ?>/departments/index.php"
                                  onsubmit="return confirm('Toggle status?');" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="toggle">
                                <input type="hidden" name="id" value="<?= $dept['id'] ?>">
                                <button type="submit"
                                   class="badge border-0 text-decoration-none <?= $dept['is_active'] ? 'bg-success' : 'bg-secondary' ?>"
                                   style="cursor:pointer;">
                                   <?= $dept['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="Manage" style="border-radius:7px;">
                                    <i class="fas fa-folder-open"></i>
                                </a>
                                <a href="<?= APP_URL ?>/departments/edit.php?id=<?= $dept['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/delete.php"
                                      onsubmit="return confirm('Delete department &quot;<?= h(addslashes($dept['name'])) ?>&quot;? This will delete ALL related data.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $dept['id'] ?>">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
