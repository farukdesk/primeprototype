<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-registrar');

$page_title = 'Registrar – Staff Directory';

$staff = [];
try {
    $staff = db()->query('SELECT * FROM reg_staff ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/office-of-registrar/index.php">Office of Registrar</a></li>
            <li class="breadcrumb-item active">Staff Directory</li>
        </ol>
    </nav>
    <?php if (can_access('office-of-registrar', 'can_create')): ?>
    <a href="<?= APP_URL ?>/office-of-registrar/staff-create.php" class="btn btn-sm btn-primary" style="border-radius:8px;">
        <i class="fas fa-plus me-1"></i> Add Staff Member
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Staff Members (<?= count($staff) ?>)</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="width:40px;padding:12px 16px;">#</th>
                    <th style="padding:12px 16px;">Name</th>
                    <th style="padding:12px 16px;">Title</th>
                    <th style="padding:12px 16px;">Email</th>
                    <th style="padding:12px 16px;">Phone</th>
                    <th style="padding:12px 16px;">Status</th>
                    <th style="padding:12px 16px;width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staff)): ?>
                <tr><td colspan="7" class="text-center py-4 text-muted">No staff members found. <a href="<?= APP_URL ?>/office-of-registrar/staff-create.php">Add one.</a></td></tr>
                <?php else: ?>
                <?php foreach ($staff as $i => $st): ?>
                <tr>
                    <td style="padding:12px 16px;color:#94a3b8;font-size:.85rem;"><?= $st['sort_order'] ?: ($i + 1) ?></td>
                    <td style="padding:12px 16px;">
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($st['photo'])): ?>
                            <img src="<?= UPLOAD_URL ?>/office-of-registrar/<?= h($st['photo']) ?>"
                                 style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;" alt="<?= h($st['name']) ?>">
                            <?php else: ?>
                            <div style="width:32px;height:32px;border-radius:50%;background:#e8eaf0;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="fas fa-user" style="color:#94a3b8;font-size:.75rem;"></i></div>
                            <?php endif; ?>
                            <span class="fw-medium"><?= h($st['name']) ?></span>
                        </div>
                    </td>
                    <td style="padding:12px 16px;font-size:.88rem;color:#64748b;"><?= h($st['title']) ?></td>
                    <td style="padding:12px 16px;font-size:.85rem;">
                        <?php if ($st['email_1']): ?><a href="mailto:<?= h($st['email_1']) ?>" style="color:#1a4faf;"><?= h($st['email_1']) ?></a><?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;font-size:.85rem;"><?= $st['phone'] ? h($st['phone']) : '—' ?></td>
                    <td style="padding:12px 16px;">
                        <span class="badge" style="background:<?= $st['is_active'] ? '#dcfce7' : '#fee2e2' ?>;color:<?= $st['is_active'] ? '#166534' : '#991b1b' ?>;font-size:.72rem;border-radius:20px;">
                            <?= $st['is_active'] ? 'Active' : 'Inactive' ?></span>
                    </td>
                    <td style="padding:12px 16px;">
                        <div class="d-flex gap-1">
                            <?php if (can_access('office-of-registrar', 'can_edit')): ?>
                            <a href="<?= APP_URL ?>/office-of-registrar/staff-edit.php?id=<?= $st['id'] ?>"
                               class="btn btn-xs btn-outline-primary" style="padding:3px 8px;font-size:.75rem;border-radius:6px;" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (can_access('office-of-registrar', 'can_delete')): ?>
                            <form method="POST" action="<?= APP_URL ?>/office-of-registrar/staff-delete.php"
                                  onsubmit="return confirm('Delete this staff member?');" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $st['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" style="padding:3px 8px;font-size:.75rem;border-radius:6px;" title="Delete">
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
