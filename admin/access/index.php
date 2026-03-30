<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('access');

$page_title = 'Module Access';

$db = db();

// Filter by group
$group_id = (int)($_GET['group_id'] ?? 0);
$groups   = $db->query('SELECT * FROM user_groups ORDER BY is_super DESC, name')->fetchAll();
$modules  = $db->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();

$selected_group = null;
$access_map     = [];

if ($group_id) {
    $sg = $db->prepare('SELECT * FROM user_groups WHERE id = ?');
    $sg->execute([$group_id]);
    $selected_group = $sg->fetch();

    if ($selected_group) {
        $rows = $db->prepare(
            'SELECT * FROM group_module_access WHERE group_id = ?'
        );
        $rows->execute([$group_id]);
        foreach ($rows->fetchAll() as $r) {
            $access_map[$r['module_id']] = $r;
        }
    }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
    csrf_check();
    require_access('access', 'can_edit');

    $gid = (int)($_POST['group_id'] ?? 0);
    $sg2 = $db->prepare('SELECT * FROM user_groups WHERE id = ?');
    $sg2->execute([$gid]);
    $grp = $sg2->fetch();

    if (!$grp) {
        flash_set('error', 'Invalid group.');
        redirect(APP_URL . '/access/index.php');
    }

    if ($grp['is_super']) {
        flash_set('info', 'Super Admin group always has full access – no explicit permissions needed.');
        redirect(APP_URL . '/access/index.php?group_id=' . $gid);
    }

    // Rebuild access for this group
    $db->prepare('DELETE FROM group_module_access WHERE group_id = ?')->execute([$gid]);

    $modules_all = $db->query('SELECT id FROM modules WHERE is_active = 1')->fetchAll();
    $insert = $db->prepare(
        'INSERT INTO group_module_access (group_id, module_id, can_view, can_create, can_edit, can_delete)
         VALUES (?,?,?,?,?,?)'
    );

    foreach ($modules_all as $mod) {
        $mid        = $mod['id'];
        $can_view   = isset($_POST['modules'][$mid]['view'])   ? 1 : 0;
        $can_create = isset($_POST['modules'][$mid]['create']) ? 1 : 0;
        $can_edit   = isset($_POST['modules'][$mid]['edit'])   ? 1 : 0;
        $can_delete = isset($_POST['modules'][$mid]['delete']) ? 1 : 0;

        if ($can_view || $can_create || $can_edit || $can_delete) {
            $insert->execute([$gid, $mid, $can_view, $can_create, $can_edit, $can_delete]);
        }
    }

    flash_set('success', "Access permissions saved for <strong>" . h($grp['name']) . "</strong>.");
    redirect(APP_URL . '/access/index.php?group_id=' . $gid);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Module Access</li>
        </ol>
    </nav>
</div>

<!-- Group selector -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <label class="fw-medium mb-0" style="white-space:nowrap;">Select Group:</label>
            <select name="group_id" class="form-select" style="max-width:300px;border-radius:10px;" onchange="this.form.submit()">
                <option value="">-- Choose a group --</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $group_id == $g['id'] ? 'selected' : '' ?>>
                    <?= h($g['name']) ?><?= $g['is_super'] ? ' ★ Super Admin' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($selected_group): ?>
    <?php if ($selected_group['is_super']): ?>
    <div class="alert alert-warning d-flex align-items-center gap-3">
        <i class="fas fa-star fa-lg"></i>
        <div>
            <strong><?= h($selected_group['name']) ?></strong> is a <strong>Super Admin</strong> group.
            Members automatically have unrestricted access to all modules — no permissions need to be set.
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-shield-alt me-2 text-muted"></i>
                Permissions for: <span class="text-primary"><?= h($selected_group['name']) ?></span>
            </h6>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="toggleAll(true)" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-check-double me-1"></i>Select All
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-times me-1"></i>Clear All
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <form method="POST" id="accessForm">
                <?= csrf_field() ?>
                <input type="hidden" name="group_id" value="<?= $selected_group['id'] ?>">
                <input type="hidden" name="save_access" value="1">

                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4" style="min-width:200px;">Module</th>
                                <th class="text-center" style="width:100px;">
                                    <span class="badge bg-info text-dark">View</span>
                                </th>
                                <th class="text-center" style="width:100px;">
                                    <span class="badge bg-success">Create</span>
                                </th>
                                <th class="text-center" style="width:100px;">
                                    <span class="badge bg-warning text-dark">Edit</span>
                                </th>
                                <th class="text-center" style="width:100px;">
                                    <span class="badge bg-danger">Delete</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($modules as $m): ?>
                            <?php $a = $access_map[$m['id']] ?? []; ?>
                            <tr>
                                <td class="px-4">
                                    <i class="<?= h($m['icon']) ?> me-2 text-muted" style="width:16px;text-align:center;"></i>
                                    <strong><?= h($m['name']) ?></strong>
                                    <br><small class="text-muted"><code><?= h($m['slug']) ?></code></small>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input access-cb"
                                           name="modules[<?= $m['id'] ?>][view]"
                                           <?= !empty($a['can_view']) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input access-cb"
                                           name="modules[<?= $m['id'] ?>][create]"
                                           <?= !empty($a['can_create']) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input access-cb"
                                           name="modules[<?= $m['id'] ?>][edit]"
                                           <?= !empty($a['can_edit']) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input access-cb"
                                           name="modules[<?= $m['id'] ?>][delete]"
                                           <?= !empty($a['can_delete']) ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-top">
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Save Permissions
                    </button>
                    <a href="<?= APP_URL ?>/access/index.php" class="btn btn-light ms-2" style="border-radius:10px;">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleAll(state) {
        document.querySelectorAll('.access-cb').forEach(function(cb) { cb.checked = state; });
    }
    </script>
    <?php endif; ?>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-shield-alt fa-3x mb-3" style="opacity:.3"></i>
        <p>Select a user group above to manage its module access permissions.</p>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
