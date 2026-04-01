<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_id = (int)($_GET['page_id'] ?? 0);
if (!$page_id) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/pages/index.php'); }

$stmt = db()->prepare('SELECT * FROM pages WHERE id = ? AND category = ?');
$stmt->execute([$page_id, 'profile']);
$parent = $stmt->fetch();
if (!$parent) { flash_set('error', 'Profile page not found.'); redirect(APP_URL . '/pages/index.php'); }

$page_title = 'Profile Members – ' . $parent['title'];

$profiles = db()->prepare(
    'SELECT * FROM page_profiles WHERE page_id = ? ORDER BY sort_order ASC, id ASC'
);
$profiles->execute([$page_id]);
$profiles = $profiles->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/index.php">Pages</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/pages/edit.php?id=<?= $page_id ?>"><?= h($parent['title']) ?></a></li>
            <li class="breadcrumb-item active">Profile Members</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/pages/profiles/create.php?page_id=<?= $page_id ?>"
       class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Member
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Photo</th>
                        <th>Name &amp; Designation</th>
                        <th>Featured</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($profiles)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No members added yet. Click <strong>Add Member</strong> to begin.</td></tr>
                <?php else: ?>
                    <?php foreach ($profiles as $idx => $pr): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <?php if ($pr['photo']): ?>
                            <img src="<?= UPLOAD_URL ?>/pages/profiles/<?= h($pr['photo']) ?>"
                                 style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;"
                                 alt="" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($pr['full_name']) ?>&size=44'">
                            <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:50%;background:#e8eaf0;
                                 display:flex;align-items:center;justify-content:center;color:#aaa;">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= h($pr['full_name']) ?></strong>
                            <?php if ($pr['designation']): ?>
                            <div style="font-size:.78rem;color:#D21034;"><?= h($pr['designation']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $pr['is_featured']
                                ? '<span class="badge bg-warning text-dark"><i class="fas fa-star me-1"></i>Featured</span>'
                                : '<span class="text-muted" style="font-size:.8rem;">—</span>' ?>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= (int)$pr['sort_order'] ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/pages/profiles/edit.php?id=<?= $pr['id'] ?>&page_id=<?= $page_id ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/pages/profiles/delete.php"
                                      onsubmit="return confirm('Remove &quot;<?= h(addslashes($pr['full_name'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id"      value="<?= $pr['id'] ?>">
                                    <input type="hidden" name="page_id" value="<?= $page_id ?>">
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
