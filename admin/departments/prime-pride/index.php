<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$pride_list = db()->prepare(
    'SELECT * FROM dept_prime_pride WHERE dept_id = ? ORDER BY sort_order ASC, id ASC'
);
$pride_list->execute([$dept_id]);
$pride_list = $pride_list->fetchAll();

$page_title = 'Prime Pride – ' . $dept['name'];
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Prime Pride</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/prime-pride/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Entry
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-star me-2 text-muted"></i>Prime Pride</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($pride_list) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title</th>
                        <th>Student Name</th>
                        <th>Position</th>
                        <th>Batch Year</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($pride_list)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No prime pride entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($pride_list as $i => $pp): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($pp['image']): ?>
                                <img src="<?= UPLOAD_URL ?>/departments/<?= h($pp['image']) ?>"
                                     alt="" style="width:34px;height:34px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                                <?php endif; ?>
                                <?= h($pp['title']) ?>
                            </div>
                        </td>
                        <td><?= h($pp['student_name'] ?? '—') ?></td>
                        <td><?= h($pp['position'] ?? '—') ?></td>
                        <td><?= h($pp['batch_year'] ?? '—') ?></td>
                        <td><?= (int)$pp['sort_order'] ?></td>
                        <td>
                            <span class="badge <?= $pp['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $pp['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/prime-pride/edit.php?id=<?= $pp['id'] ?>&dept_id=<?= $dept_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/prime-pride/delete.php"
                                      onsubmit="return confirm('Delete entry &quot;<?= h(addslashes($pp['title'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $pp['id'] ?>">
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
