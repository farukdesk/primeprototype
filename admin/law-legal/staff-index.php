<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal');
require_once __DIR__ . '/helpers.php';

$page_title = 'Legal Staff';

$staff = [];
try {
    $staff = db()->query(
        'SELECT * FROM ll_staff ORDER BY sort_order ASC, name ASC'
    )->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/law-legal/index.php">Law &amp; Legal Affairs</a></li>
            <li class="breadcrumb-item active">Staff</li>
        </ol>
    </nav>
    <?php if (ll_can_create()): ?>
    <a href="<?= APP_URL ?>/law-legal/staff-create.php"
       class="btn btn-primary btn-sm" style="border-radius:8px;">
        <i class="fas fa-plus me-1"></i> Add Staff
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if (empty($staff)): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center py-5">
        <div style="width:72px;height:72px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-users" style="font-size:1.8rem;color:#94a3b8;"></i>
        </div>
        <h6 class="fw-semibold text-muted mb-2">No staff members yet</h6>
        <p class="text-muted mb-4" style="font-size:.88rem;">Add legal staff members to display them on the public page.</p>
        <?php if (ll_can_create()): ?>
        <a href="<?= APP_URL ?>/law-legal/staff-create.php" class="btn btn-primary btn-sm" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add Staff Member
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.9rem;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="px-4 py-3" style="width:48px;">#</th>
                        <th class="px-3 py-3">Photo</th>
                        <th class="px-3 py-3">Name</th>
                        <th class="px-3 py-3">Designation</th>
                        <th class="px-3 py-3">Email</th>
                        <th class="px-3 py-3">Phone</th>
                        <th class="px-3 py-3" style="width:70px;">Order</th>
                        <th class="px-3 py-3" style="width:80px;">Status</th>
                        <th class="px-4 py-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $i => $m): ?>
                    <tr>
                        <td class="px-4 py-3 text-muted"><?= $i + 1 ?></td>
                        <td class="px-3 py-3">
                            <?php if ($m['photo']): ?>
                            <img src="<?= UPLOAD_URL ?>/law-legal/<?= h($m['photo']) ?>"
                                 style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;" alt="">
                            <?php else: ?>
                            <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#2563eb);display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-user" style="color:#fff;font-size:.85rem;"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 fw-medium"><?= h($m['name']) ?></td>
                        <td class="px-3 py-3 text-muted"><?= h($m['designation'] ?? '—') ?></td>
                        <td class="px-3 py-3">
                            <?php if ($m['email']): ?>
                            <a href="mailto:<?= h($m['email']) ?>" style="font-size:.85rem;"><?= h($m['email']) ?></a>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-muted"><?= h($m['phone'] ?? '—') ?></td>
                        <td class="px-3 py-3 text-muted"><?= (int)$m['sort_order'] ?></td>
                        <td class="px-3 py-3">
                            <span class="badge" style="border-radius:20px;background:<?= $m['is_active'] ? '#dcfce7' : '#fee2e2' ?>;color:<?= $m['is_active'] ? '#166534' : '#991b1b' ?>;font-size:.73rem;">
                                <?= $m['is_active'] ? 'Active' : 'Hidden' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <?php if (ll_can_edit()): ?>
                            <a href="<?= APP_URL ?>/law-legal/staff-edit.php?id=<?= $m['id'] ?>"
                               class="btn btn-sm btn-outline-primary me-1" style="border-radius:6px;">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (ll_can_delete()): ?>
                            <form method="POST" action="<?= APP_URL ?>/law-legal/staff-delete.php"
                                  class="d-inline"
                                  onsubmit="return confirm('Remove ' + <?= json_encode($m['name']) ?> + '?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:6px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
