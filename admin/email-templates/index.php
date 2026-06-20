<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('email-templates');

$page_title = 'Email Templates';

$search = trim($_GET['search'] ?? '');
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(name LIKE ? OR action LIKE ? OR subject LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = [$like, $like, $like];
}

$sql = 'SELECT * FROM email_templates'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY name ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$templates = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Email Templates</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('email-templates', 'can_create')): ?>
    <a href="<?= APP_URL ?>/email-templates/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> New Template
    </a>
    <?php endif; ?>
</div>

<!-- Search -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:320px;border-radius:10px;"
                   placeholder="Search name, action, subject…" value="<?= h($search) ?>">
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search): ?>
            <a href="<?= APP_URL ?>/email-templates/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Action / Trigger</th>
                        <th>Subject</th>
                        <th>Variables</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($templates)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No email templates found.</td></tr>
                <?php else: ?>
                    <?php foreach ($templates as $i => $tpl): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-envelope fa-sm"></i>
                                </div>
                                <strong><?= h($tpl['name']) ?></strong>
                            </div>
                        </td>
                        <td><code><?= h($tpl['action']) ?></code></td>
                        <td><?= h($tpl['subject']) ?></td>
                        <td>
                            <?php if ($tpl['variables']): ?>
                                <?php foreach (explode(',', $tpl['variables']) as $v): ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary me-1" style="font-size:.72rem;"><?= h(trim($v)) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tpl['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $tpl['updated_at']
                                ? date('M d, Y H:i', strtotime($tpl['updated_at']))
                                : date('M d, Y H:i', strtotime($tpl['created_at'])) ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (is_super_admin() || can_access('email-templates', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/email-templates/edit.php?id=<?= $tpl['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() || can_access('email-templates', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/email-templates/delete.php"
                                      onsubmit="return confirm('Delete template ' + <?= json_encode($tpl['name']) ?> + '?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
