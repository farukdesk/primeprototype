<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-vc');

$page_title = 'Former Vice Chancellors';

$former_vcs = [];
try {
    $former_vcs = db()->query(
        'SELECT * FROM vc_former_vcs ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-vc/index.php">Office of VC</a></li>
            <li class="breadcrumb-item active">Former Vice Chancellors</li>
        </ol>
    </nav>
    <?php if (can_access('office-of-vc', 'can_create')): ?>
    <a href="<?= APP_URL ?>/office-of-vc/former-vc-create.php"
       class="btn btn-primary btn-sm" style="border-radius:8px;">
        <i class="fas fa-plus me-1"></i> Add Former VC
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if (empty($former_vcs)): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body text-center py-5">
        <div style="width:72px;height:72px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="fas fa-user-graduate" style="font-size:1.8rem;color:#94a3b8;"></i>
        </div>
        <h6 class="fw-semibold text-muted mb-2">No former VCs yet</h6>
        <p class="text-muted mb-4" style="font-size:.88rem;">Add former Vice Chancellors to display them on the public page.</p>
        <?php if (can_access('office-of-vc', 'can_create')): ?>
        <a href="<?= APP_URL ?>/office-of-vc/former-vc-create.php"
           class="btn btn-primary btn-sm" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add Former VC
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
                        <th class="px-3 py-3">Title</th>
                        <th class="px-3 py-3">Tenure</th>
                        <th class="px-3 py-3" style="width:80px;">Order</th>
                        <th class="px-3 py-3" style="width:80px;">Status</th>
                        <th class="px-4 py-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($former_vcs as $i => $vc): ?>
                    <tr>
                        <td class="px-4 py-3 text-muted"><?= $i + 1 ?></td>
                        <td class="px-3 py-3">
                            <?php if ($vc['photo']): ?>
                            <img src="<?= UPLOAD_URL ?>/office-of-vc/<?= h($vc['photo']) ?>"
                                 style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;" alt="">
                            <?php else: ?>
                            <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#002147,#1a4faf);display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-user-tie" style="color:#fff;font-size:.85rem;"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 fw-medium"><?= h($vc['name']) ?></td>
                        <td class="px-3 py-3 text-muted"><?= h($vc['title']) ?></td>
                        <td class="px-3 py-3 text-muted"><?= h($vc['tenure']) ?></td>
                        <td class="px-3 py-3 text-muted"><?= (int)$vc['sort_order'] ?></td>
                        <td class="px-3 py-3">
                            <span class="badge" style="border-radius:20px;background:<?= $vc['is_active'] ? '#dcfce7' : '#fee2e2' ?>;color:<?= $vc['is_active'] ? '#166534' : '#991b1b' ?>;font-size:.73rem;">
                                <?= $vc['is_active'] ? 'Active' : 'Hidden' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <?php if (can_access('office-of-vc', 'can_edit')): ?>
                            <a href="<?= APP_URL ?>/office-of-vc/former-vc-edit.php?id=<?= $vc['id'] ?>"
                               class="btn btn-sm btn-outline-primary me-1" style="border-radius:6px;">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (can_access('office-of-vc', 'can_delete')): ?>
                            <form method="POST" action="<?= APP_URL ?>/office-of-vc/former-vc-delete.php"
                                  class="d-inline"
                                  onsubmit="return confirm('Remove <?= h(addslashes($vc['name'])) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $vc['id'] ?>">
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
