<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-clubs');

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$clubs = db()->prepare(
    'SELECT * FROM dept_clubs WHERE dept_id = ? ORDER BY sort_order ASC, id ASC'
);
$clubs->execute([$dept_id]);
$clubs = $clubs->fetchAll();

$page_title = 'Clubs – ' . $dept['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Clubs</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/clubs/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Club
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Clubs</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($clubs) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>President</th>
                        <th>Email</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($clubs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No clubs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($clubs as $i => $c): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($c['logo']): ?>
                                <img src="<?= UPLOAD_URL ?>/departments/<?= h($c['logo']) ?>"
                                     alt="" style="width:34px;height:34px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                                <?php endif; ?>
                                <?= h($c['name']) ?>
                            </div>
                        </td>
                        <td><?= h($c['president_name'] ?? '—') ?></td>
                        <td><?= h($c['email'] ?? '—') ?></td>
                        <td><?= (int)$c['sort_order'] ?></td>
                        <td>
                            <span class="badge <?= $c['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/clubs/edit.php?id=<?= $c['id'] ?>&dept_id=<?= $dept_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/clubs/delete.php"
                                      onsubmit="return confirm('Delete club &quot;<?= h(addslashes($c['name'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
