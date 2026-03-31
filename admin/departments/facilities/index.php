<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$facilities = db()->prepare(
    'SELECT * FROM dept_facilities WHERE dept_id = ? ORDER BY sort_order ASC, id ASC'
);
$facilities->execute([$dept_id]);
$facilities = $facilities->fetchAll();

$page_title = 'Facilities – ' . $dept['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Facilities</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/facilities/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Facility
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-building me-2 text-muted"></i>Facilities</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($facilities) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title</th>
                        <th>Icon</th>
                        <th>Order</th>
                        <th>Image</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($facilities)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No facilities found.</td></tr>
                <?php else: ?>
                    <?php foreach ($facilities as $i => $f): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><?= h($f['title']) ?></td>
                        <td><i class="<?= h($f['icon'] ?? '') ?>"></i> <small class="text-muted"><?= h($f['icon'] ?? '—') ?></small></td>
                        <td><?= (int)$f['sort_order'] ?></td>
                        <td>
                            <?php if ($f['image']): ?>
                            <img src="<?= UPLOAD_URL ?>/departments/<?= h($f['image']) ?>"
                                 alt="" style="width:50px;height:35px;border-radius:6px;object-fit:cover;">
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $f['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $f['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/facilities/edit.php?id=<?= $f['id'] ?>&dept_id=<?= $dept_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/facilities/delete.php"
                                      onsubmit="return confirm('Delete facility &quot;<?= h(addslashes($f['title'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="dept_id" value="<?= $dept_id ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
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
