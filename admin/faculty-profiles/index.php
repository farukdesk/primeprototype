<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/fp-helpers.php';

// Non-super-admin: Register Office can see index; Faculty goes to my-profile
if (!is_super_admin()) {
    if (fp_is_register_office() && can_access('faculty-profile', 'can_view')) {
        // allowed to continue
    } elseif (can_access('faculty-profile', 'can_view')) {
        redirect(APP_URL . '/faculty-profiles/my-profile.php');
    } else {
        require_access('faculty-profile', 'can_view'); // triggers redirect/error
    }
}

$page_title = 'Faculty Profiles';

$faculty = db()->query(
    "SELECT u.id, u.full_name, u.email,
            fp.designation, fp.bio, fp.updated_at
     FROM users u
     JOIN user_groups ug ON ug.id = u.group_id
     LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
     WHERE ug.name = 'Faculty'
     ORDER BY u.full_name ASC"
)->fetchAll();

// Count pending registrations for badge
$pending_count = 0;
if (fp_can_manage_pending()) {
    $pcs = db()->query("SELECT COUNT(*) FROM faculty_registrations WHERE status = 'pending'");
    $pending_count = (int)($pcs ? $pcs->fetchColumn() : 0);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Faculty Profiles</li>
        </ol>
    </nav>
    <?php if (fp_can_manage_pending()): ?>
    <a href="<?= APP_URL ?>/faculty-profiles/pending.php" class="btn btn-outline-warning" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-user-clock me-1"></i>Pending Registrations
        <?php if ($pending_count > 0): ?>
        <span class="badge bg-danger ms-1"><?= $pending_count ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Faculty Profiles</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($faculty) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Designation</th>
                        <th>Profile Status</th>
                        <th>Last Updated</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($faculty)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No faculty users found. Add users to the <strong>Faculty</strong> group first.</td></tr>
                <?php else: ?>
                    <?php foreach ($faculty as $i => $row): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;">
                                    <?= strtoupper(substr($row['full_name'] ?? 'F', 0, 1)) ?>
                                </div>
                                <span class="fw-medium"><?= h($row['full_name']) ?></span>
                            </div>
                        </td>
                        <td><?= h($row['email']) ?></td>
                        <td><?= h($row['designation'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($row['bio'])): ?>
                            <span class="badge bg-success">Complete</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Incomplete</span>
                            <?php endif; ?>
                        </td>
                        <td><?php if ($row['updated_at']): ?><?= h(date('d M Y', strtotime($row['updated_at']))) ?><?php else: ?><span class="text-muted">Never</span><?php endif; ?></td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                            <a href="<?= APP_URL ?>/faculty-profiles/edit.php?user_id=<?= $row['id'] ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit Profile">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="<?= APP_URL ?>/faculty-profiles/files.php?user_id=<?= $row['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="Files">
                                <i class="fas fa-folder-open"></i> Files
                            </a>
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
