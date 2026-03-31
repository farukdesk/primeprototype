<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$notices = db()->prepare(
    'SELECT * FROM dept_notices WHERE dept_id = ? ORDER BY notice_date DESC, id DESC'
);
$notices->execute([$dept_id]);
$notices = $notices->fetchAll();

$page_title = 'Notices – ' . $dept['name'];
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Notices</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/notices/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Notice
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-bell me-2 text-muted"></i>Notices</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($notices) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Title</th>
                        <th>Date</th>
                        <th>Attachment</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($notices)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No notices found.</td></tr>
                <?php else: ?>
                    <?php foreach ($notices as $i => $n): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><?= h($n['title']) ?></td>
                        <td><?= $n['notice_date'] ? date('M d, Y', strtotime($n['notice_date'])) : '—' ?></td>
                        <td>
                            <?php if ($n['attachment']): ?>
                            <span class="badge bg-info text-dark"><i class="fas fa-paperclip me-1"></i>Attached</span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $n['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $n['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/notices/edit.php?id=<?= $n['id'] ?>&dept_id=<?= $dept_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/notices/delete.php"
                                      onsubmit="return confirm('Delete notice &quot;<?= h(addslashes($n['title'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
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
