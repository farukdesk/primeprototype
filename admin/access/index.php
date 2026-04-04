<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_access('access');

$page_title = 'Module Access';

$db = db();

// ── Determine mode: 'group' or 'user' ─────────────────────────────────────
$mode     = in_array($_GET['mode'] ?? '', ['group', 'user'], true) ? ($_GET['mode'] ?? 'group') : 'group';
$group_id = (int)($_GET['group_id'] ?? 0);
$user_id  = (int)($_GET['user_id']  ?? 0);

$groups  = $db->query('SELECT * FROM user_groups ORDER BY is_super DESC, name')->fetchAll();
$modules = $db->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
$users   = $db->query('SELECT u.id, u.full_name, u.username, g.name AS group_name FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.is_active = 1 ORDER BY u.full_name')->fetchAll();

$selected_group = null;
$selected_user  = null;
$access_map     = [];

// ── Load existing access map ───────────────────────────────────────────────
if ($mode === 'group' && $group_id) {
    $sg = $db->prepare('SELECT * FROM user_groups WHERE id = ?');
    $sg->execute([$group_id]);
    $selected_group = $sg->fetch();

    if ($selected_group) {
        $rows = $db->prepare('SELECT * FROM group_module_access WHERE group_id = ?');
        $rows->execute([$group_id]);
        foreach ($rows->fetchAll() as $r) {
            $access_map[$r['module_id']] = $r;
        }
    }
} elseif ($mode === 'user' && $user_id) {
    $su = $db->prepare(
        'SELECT u.*, g.name AS group_name FROM users u
         JOIN user_groups g ON g.id = u.group_id
         WHERE u.id = ? AND u.is_active = 1'
    );
    $su->execute([$user_id]);
    $selected_user = $su->fetch();

    if ($selected_user) {
        $rows = $db->prepare('SELECT * FROM user_module_access WHERE user_id = ?');
        $rows->execute([$user_id]);
        foreach ($rows->fetchAll() as $r) {
            $access_map[$r['module_id']] = $r;
        }
    }
}

// ── Handle save ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
    csrf_check();
    require_access('access', 'can_edit');

    $post_mode = $_POST['mode'] ?? 'group';

    // ── Save GROUP access ──────────────────────────────────────────────────
    if ($post_mode === 'group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $sg2 = $db->prepare('SELECT * FROM user_groups WHERE id = ?');
        $sg2->execute([$gid]);
        $grp = $sg2->fetch();

        if (!$grp) {
            flash_set('error', 'Invalid group.');
            redirect(APP_URL . '/access/index.php?mode=group');
        }
        if ($grp['is_super']) {
            flash_set('info', 'Super Admin group always has full access – no explicit permissions needed.');
            redirect(APP_URL . '/access/index.php?mode=group&group_id=' . $gid);
        }

        // Fetch old access for change log comparison
        $old_rows = $db->prepare('SELECT gma.*, m.slug FROM group_module_access gma JOIN modules m ON m.id = gma.module_id WHERE gma.group_id = ?');
        $old_rows->execute([$gid]);
        $old_map = [];
        foreach ($old_rows->fetchAll() as $r) {
            $old_map[$r['module_id']] = $r;
        }

        $db->prepare('DELETE FROM group_module_access WHERE group_id = ?')->execute([$gid]);

        $modules_all = $db->query('SELECT * FROM modules WHERE is_active = 1')->fetchAll();
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

            // Log change if permissions differ
            $old = $old_map[$mid] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            $old_str = "view:{$old['can_view']} create:{$old['can_create']} edit:{$old['can_edit']} delete:{$old['can_delete']}";
            $new_str = "view:$can_view create:$can_create edit:$can_edit delete:$can_delete";
            if ($old_str !== $new_str) {
                log_change(
                    'access',
                    'UPDATE',
                    $gid,
                    $grp['name'],
                    $mod['name'],
                    $old_str,
                    $new_str,
                    "Group access updated for module '{$mod['name']}'"
                );
            }
        }

        flash_set('success', "Group permissions saved for <strong>" . h($grp['name']) . "</strong>.");
        redirect(APP_URL . '/access/index.php?mode=group&group_id=' . $gid);

    // ── Save USER access ───────────────────────────────────────────────────
    } else {
        $uid = (int)($_POST['user_id'] ?? 0);
        $su2 = $db->prepare(
            'SELECT u.*, g.name AS group_name FROM users u
             JOIN user_groups g ON g.id = u.group_id
             WHERE u.id = ? AND u.is_active = 1'
        );
        $su2->execute([$uid]);
        $usr = $su2->fetch();

        if (!$usr) {
            flash_set('error', 'Invalid user.');
            redirect(APP_URL . '/access/index.php?mode=user');
        }

        // Fetch old access for change log comparison
        $old_rows = $db->prepare('SELECT uma.*, m.slug FROM user_module_access uma JOIN modules m ON m.id = uma.module_id WHERE uma.user_id = ?');
        $old_rows->execute([$uid]);
        $old_map = [];
        foreach ($old_rows->fetchAll() as $r) {
            $old_map[$r['module_id']] = $r;
        }

        $db->prepare('DELETE FROM user_module_access WHERE user_id = ?')->execute([$uid]);

        $modules_all = $db->query('SELECT * FROM modules WHERE is_active = 1')->fetchAll();
        $insert = $db->prepare(
            'INSERT INTO user_module_access (user_id, module_id, can_view, can_create, can_edit, can_delete)
             VALUES (?,?,?,?,?,?)'
        );

        foreach ($modules_all as $mod) {
            $mid        = $mod['id'];
            $can_view   = isset($_POST['modules'][$mid]['view'])   ? 1 : 0;
            $can_create = isset($_POST['modules'][$mid]['create']) ? 1 : 0;
            $can_edit   = isset($_POST['modules'][$mid]['edit'])   ? 1 : 0;
            $can_delete = isset($_POST['modules'][$mid]['delete']) ? 1 : 0;

            if ($can_view || $can_create || $can_edit || $can_delete) {
                $insert->execute([$uid, $mid, $can_view, $can_create, $can_edit, $can_delete]);
            }

            // Log change if permissions differ
            $old = $old_map[$mid] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            $old_str = "view:{$old['can_view']} create:{$old['can_create']} edit:{$old['can_edit']} delete:{$old['can_delete']}";
            $new_str = "view:$can_view create:$can_create edit:$can_edit delete:$can_delete";
            if ($old_str !== $new_str) {
                log_change(
                    'access',
                    'UPDATE',
                    $uid,
                    $usr['full_name'] . ' (' . $usr['username'] . ')',
                    $mod['name'],
                    $old_str,
                    $new_str,
                    "User access updated for module '{$mod['name']}'"
                );
            }
        }

        flash_set('success', "User permissions saved for <strong>" . h($usr['full_name']) . "</strong>.");
        redirect(APP_URL . '/access/index.php?mode=user&user_id=' . $uid);
    }
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

<!-- Mode tabs -->
<ul class="nav nav-tabs mb-4" id="accessTabs">
    <li class="nav-item">
        <a class="nav-link <?= $mode === 'group' ? 'active' : '' ?>"
           href="<?= APP_URL ?>/access/index.php?mode=group<?= $group_id ? '&group_id=' . $group_id : '' ?>">
            <i class="fas fa-layer-group me-1"></i> Group Access
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $mode === 'user' ? 'active' : '' ?>"
           href="<?= APP_URL ?>/access/index.php?mode=user<?= $user_id ? '&user_id=' . $user_id : '' ?>">
            <i class="fas fa-user-shield me-1"></i> User Access
            <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Override</span>
        </a>
    </li>
</ul>

<?php if ($mode === 'group'): ?>
<!-- ── GROUP SELECTOR ──────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <input type="hidden" name="mode" value="group">
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
    <?= renderAccessForm('group', $selected_group['id'], $selected_group['name'], $modules, $access_map) ?>
    <?php endif; ?>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-shield-alt fa-3x mb-3" style="opacity:.3"></i>
        <p>Select a user group above to manage its module access permissions.</p>
    </div>
<?php endif; ?>

<?php else: ?>
<!-- ── USER SELECTOR ───────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <input type="hidden" name="mode" value="user">
            <label class="fw-medium mb-0" style="white-space:nowrap;">Select User:</label>
            <select name="user_id" class="form-select" style="max-width:360px;border-radius:10px;" onchange="this.form.submit()">
                <option value="">-- Choose a user --</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $user_id == $u['id'] ? 'selected' : '' ?>>
                    <?= h($u['full_name']) ?> (<?= h($u['username']) ?>) — <?= h($u['group_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($selected_user): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-4" style="border-radius:10px;">
    <i class="fas fa-info-circle fa-lg"></i>
    <div>
        User-level permissions <strong>override</strong> group permissions for the selected user.
        Only modules explicitly set here are overridden — all other modules still follow the
        <strong><?= h($selected_user['group_name']) ?></strong> group rules.
        To remove all user-specific overrides, clear all checkboxes and save.
    </div>
</div>
<?= renderAccessForm('user', $selected_user['id'], $selected_user['full_name'] . ' (' . $selected_user['username'] . ')', $modules, $access_map) ?>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-user-shield fa-3x mb-3" style="opacity:.3"></i>
        <p>Select a user above to manage individual module access overrides.</p>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php

/**
 * Render the permissions table form (shared by group and user modes).
 */
function renderAccessForm(string $mode, int $target_id, string $target_label, array $modules, array $access_map): void {
    global $APP_URL;
    $app_url = defined('APP_URL') ? APP_URL : '';
    ?>
    <div class="card">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-shield-alt me-2 text-muted"></i>
                Permissions for: <span class="text-primary"><?= h($target_label) ?></span>
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
                <input type="hidden" name="mode"     value="<?= h($mode) ?>">
                <input type="hidden" name="<?= $mode === 'group' ? 'group_id' : 'user_id' ?>" value="<?= $target_id ?>">
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
                                    <?php if (!empty($m['description'])): ?>
                                    <br><small class="text-muted" style="font-size:.72rem;"><?= h($m['description']) ?></small>
                                    <?php endif; ?>
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
                    <a href="<?= $app_url ?>/access/index.php?mode=<?= h($mode) ?>" class="btn btn-light ms-2" style="border-radius:10px;">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php
}
?>

<script>
function toggleAll(state) {
    document.querySelectorAll('.access-cb').forEach(function(cb) { cb.checked = state; });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

