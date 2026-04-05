<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_access('access');

$page_title = 'Module Access';
$db = db();

// Determine mode: 'group' or 'user'
$mode     = in_array($_GET['mode'] ?? '', ['group', 'user'], true) ? ($_GET['mode'] ?? 'group') : 'group';
$group_id = (int)($_GET['group_id'] ?? 0);
$user_id  = (int)($_GET['user_id']  ?? 0);

$groups  = $db->query('SELECT * FROM user_groups ORDER BY is_super DESC, name')->fetchAll();
$users   = $db->query(
    'SELECT u.id, u.full_name, u.username, g.name AS group_name
     FROM users u
     JOIN user_groups g ON g.id = u.group_id
     WHERE u.is_active = 1
     ORDER BY u.full_name'
)->fetchAll();
$departments = $db->query('SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name')->fetchAll();

// Load all modules, build hierarchy
$all_modules    = $db->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
$parent_modules = [];
$child_map      = [];
foreach ($all_modules as $m) {
    if ($m['parent_id']) {
        $child_map[$m['parent_id']][] = $m;
    } else {
        $parent_modules[] = $m;
    }
}

$selected_group  = null;
$selected_user   = null;
$access_map      = [];
$dept_scope_ids  = [];
$has_any_scope   = false;

// Load existing access map and dept scope
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
        $ds = $db->prepare('SELECT dept_id FROM group_dept_scope WHERE group_id = ?');
        $ds->execute([$group_id]);
        $scope_rows    = $ds->fetchAll(PDO::FETCH_COLUMN);
        $has_any_scope = count($scope_rows) > 0;
        $dept_scope_ids = array_map(fn($d) => $d === null ? 'all' : (int)$d, $scope_rows);
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
        $ds = $db->prepare('SELECT dept_id FROM user_dept_scope WHERE user_id = ?');
        $ds->execute([$user_id]);
        $scope_rows    = $ds->fetchAll(PDO::FETCH_COLUMN);
        $has_any_scope = count($scope_rows) > 0;
        $dept_scope_ids = array_map(fn($d) => $d === null ? 'all' : (int)$d, $scope_rows);
    }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access'])) {
    csrf_check();
    require_access('access', 'can_edit');

    $post_mode = $_POST['mode'] ?? 'group';

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

        // Save module access
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

            $old = $old_map[$mid] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            $old_str = "view:{$old['can_view']} create:{$old['can_create']} edit:{$old['can_edit']} delete:{$old['can_delete']}";
            $new_str = "view:$can_view create:$can_create edit:$can_edit delete:$can_delete";
            if ($old_str !== $new_str) {
                log_change('access', 'UPDATE', $gid, $grp['name'], $mod['name'], $old_str, $new_str,
                    "Group access updated for module '{$mod['name']}'");
            }
        }

        // Save dept scope
        $old_ds = $db->prepare('SELECT dept_id FROM group_dept_scope WHERE group_id = ?');
        $old_ds->execute([$gid]);
        $old_scope = $old_ds->fetchAll(PDO::FETCH_COLUMN);
        $old_scope_str = empty($old_scope) ? 'none'
            : (in_array(null, $old_scope, true) ? 'All Departments' : implode(',', array_filter($old_scope)));

        $db->prepare('DELETE FROM group_dept_scope WHERE group_id = ?')->execute([$gid]);
        $dept_scope_val = $_POST['dept_scope'] ?? 'none';
        $new_scope_str  = 'none';

        if ($dept_scope_val === 'all') {
            $db->prepare('INSERT IGNORE INTO group_dept_scope (group_id, dept_id) VALUES (?, NULL)')->execute([$gid]);
            $new_scope_str = 'All Departments';
        } elseif ($dept_scope_val === 'specific') {
            $chosen_depts = array_unique(array_filter(array_map('intval', (array)($_POST['dept_ids'] ?? []))));
            if (!empty($chosen_depts)) {
                $ins_ds = $db->prepare('INSERT IGNORE INTO group_dept_scope (group_id, dept_id) VALUES (?,?)');
                $dept_names = [];
                foreach ($chosen_depts as $did) {
                    $ins_ds->execute([$gid, $did]);
                    $dn = $db->prepare('SELECT name FROM dept_departments WHERE id = ?');
                    $dn->execute([$did]);
                    $dr = $dn->fetch();
                    if ($dr) $dept_names[] = $dr['name'];
                }
                $new_scope_str = implode(', ', $dept_names);
            }
        }

        if ($old_scope_str !== $new_scope_str) {
            log_change('access', 'UPDATE', $gid, $grp['name'], 'Department Scope',
                $old_scope_str, $new_scope_str, "Group department scope updated");
        }

        flash_set('success', "Group permissions saved for <strong>" . h($grp['name']) . "</strong>.");
        redirect(APP_URL . '/access/index.php?mode=group&group_id=' . $gid);

    } else {
        // User access save
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

            $old = $old_map[$mid] ?? ['can_view' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0];
            $old_str = "view:{$old['can_view']} create:{$old['can_create']} edit:{$old['can_edit']} delete:{$old['can_delete']}";
            $new_str = "view:$can_view create:$can_create edit:$can_edit delete:$can_delete";
            if ($old_str !== $new_str) {
                log_change('access', 'UPDATE', $uid, $usr['full_name'] . ' (' . $usr['username'] . ')',
                    $mod['name'], $old_str, $new_str, "User access updated for module '{$mod['name']}'");
            }
        }

        // Save user dept scope
        $old_ds = $db->prepare('SELECT dept_id FROM user_dept_scope WHERE user_id = ?');
        $old_ds->execute([$uid]);
        $old_scope = $old_ds->fetchAll(PDO::FETCH_COLUMN);
        $old_scope_str = empty($old_scope) ? 'none'
            : (in_array(null, $old_scope, true) ? 'All Departments' : implode(',', array_filter($old_scope)));

        $db->prepare('DELETE FROM user_dept_scope WHERE user_id = ?')->execute([$uid]);
        $dept_scope_val = $_POST['dept_scope'] ?? 'none';
        $new_scope_str  = 'none';

        if ($dept_scope_val === 'all') {
            $db->prepare('INSERT IGNORE INTO user_dept_scope (user_id, dept_id) VALUES (?, NULL)')->execute([$uid]);
            $new_scope_str = 'All Departments';
        } elseif ($dept_scope_val === 'specific') {
            $chosen_depts = array_unique(array_filter(array_map('intval', (array)($_POST['dept_ids'] ?? []))));
            if (!empty($chosen_depts)) {
                $ins_ds = $db->prepare('INSERT IGNORE INTO user_dept_scope (user_id, dept_id) VALUES (?,?)');
                $dept_names = [];
                foreach ($chosen_depts as $did) {
                    $ins_ds->execute([$uid, $did]);
                    $dn = $db->prepare('SELECT name FROM dept_departments WHERE id = ?');
                    $dn->execute([$did]);
                    $dr = $dn->fetch();
                    if ($dr) $dept_names[] = $dr['name'];
                }
                $new_scope_str = implode(', ', $dept_names);
            }
        }

        if ($old_scope_str !== $new_scope_str) {
            log_change('access', 'UPDATE', $uid, $usr['full_name'] . ' (' . $usr['username'] . ')',
                'Department Scope', $old_scope_str, $new_scope_str, "User department scope updated");
        }

        flash_set('success', "User permissions saved for <strong>" . h($usr['full_name']) . "</strong>.");
        redirect(APP_URL . '/access/index.php?mode=user&user_id=' . $uid);
    }
}

// Determine initial dept scope radio for display
$dept_scope_radio = 'none';
if ($has_any_scope) {
    $dept_scope_radio = in_array('all', $dept_scope_ids, true) ? 'all' : 'specific';
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
    <?= renderAccessForm('group', $selected_group['id'], $selected_group['name'], $parent_modules, $child_map, $access_map, $departments, $dept_scope_radio, $dept_scope_ids) ?>
    <?php endif; ?>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-shield-alt fa-3x mb-3" style="opacity:.3"></i>
        <p>Select a user group above to manage its module access permissions.</p>
    </div>
<?php endif; ?>

<?php else: ?>
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
<?= renderAccessForm('user', $selected_user['id'], $selected_user['full_name'] . ' (' . $selected_user['username'] . ')', $parent_modules, $child_map, $access_map, $departments, $dept_scope_radio, $dept_scope_ids) ?>
<?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-user-shield fa-3x mb-3" style="opacity:.3"></i>
        <p>Select a user above to manage individual module access overrides.</p>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php

function renderAccessForm(
    string $mode,
    int    $target_id,
    string $target_label,
    array  $parent_modules,
    array  $child_map,
    array  $access_map,
    array  $departments,
    string $dept_scope_radio,
    array  $dept_scope_ids
): void {
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
                                <th class="px-4" style="min-width:220px;">Module</th>
                                <th class="text-center" style="width:90px;"><span class="badge bg-info text-dark">View</span></th>
                                <th class="text-center" style="width:90px;"><span class="badge bg-success">Create</span></th>
                                <th class="text-center" style="width:90px;"><span class="badge bg-warning text-dark">Edit</span></th>
                                <th class="text-center" style="width:90px;"><span class="badge bg-danger">Delete</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($parent_modules as $m): ?>
                            <?php $a = $access_map[$m['id']] ?? []; ?>
                            <tr class="table-light">
                                <td class="px-4 fw-semibold">
                                    <i class="<?= h($m['icon']) ?> me-2 text-muted" style="width:16px;text-align:center;"></i>
                                    <?= h($m['name']) ?>
                                    <br><small class="text-muted fw-normal"><code><?= h($m['slug']) ?></code></small>
                                    <?php if (!empty($m['description'])): ?>
                                    <br><small class="text-muted fw-normal" style="font-size:.72rem;"><?= h($m['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $m['id'] ?>][view]"   <?= !empty($a['can_view'])   ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $m['id'] ?>][create]" <?= !empty($a['can_create']) ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $m['id'] ?>][edit]"   <?= !empty($a['can_edit'])   ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $m['id'] ?>][delete]" <?= !empty($a['can_delete']) ? 'checked' : '' ?>></td>
                            </tr>
                            <?php foreach (($child_map[$m['id']] ?? []) as $child): ?>
                            <?php $ca = $access_map[$child['id']] ?? []; ?>
                            <tr>
                                <td class="px-4" style="padding-left:2.5rem!important;">
                                    <span class="text-muted me-1" style="font-size:.75rem;">&#x2514;</span>
                                    <i class="<?= h($child['icon']) ?> me-1 text-muted" style="width:14px;text-align:center;font-size:.85em;"></i>
                                    <span style="font-size:.9em;"><?= h($child['name']) ?></span>
                                    <br><small class="text-muted" style="padding-left:1.4rem;"><code><?= h($child['slug']) ?></code></small>
                                </td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $child['id'] ?>][view]"   <?= !empty($ca['can_view'])   ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $child['id'] ?>][create]" <?= !empty($ca['can_create']) ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $child['id'] ?>][edit]"   <?= !empty($ca['can_edit'])   ? 'checked' : '' ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input access-cb" name="modules[<?= $child['id'] ?>][delete]" <?= !empty($ca['can_delete']) ? 'checked' : '' ?>></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Department Scope -->
                <div class="p-4 border-top">
                    <h6 class="fw-semibold mb-3"><i class="fas fa-building-columns me-2 text-muted"></i>Department Scope</h6>
                    <p class="text-muted small mb-3">
                        When access to <strong>Departments</strong> or any department sub-module is granted,
                        restrict which departments this <?= $mode === 'group' ? 'group' : 'user override' ?> can manage.
                    </p>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="dept_scope" id="ds_none"
                                   value="none" <?= $dept_scope_radio === 'none' ? 'checked' : '' ?>
                                   onchange="toggleDeptPicker(this.value)">
                            <label class="form-check-label" for="ds_none">
                                <strong>No restriction</strong>
                                <span class="text-muted small ms-1">(defaults to all departments)</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="dept_scope" id="ds_all"
                                   value="all" <?= $dept_scope_radio === 'all' ? 'checked' : '' ?>
                                   onchange="toggleDeptPicker(this.value)">
                            <label class="form-check-label" for="ds_all">
                                <strong>All Departments</strong>
                                <span class="text-muted small ms-1">(explicit grant)</span>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="dept_scope" id="ds_specific"
                                   value="specific" <?= $dept_scope_radio === 'specific' ? 'checked' : '' ?>
                                   onchange="toggleDeptPicker(this.value)">
                            <label class="form-check-label" for="ds_specific">
                                <strong>Specific Departments only</strong>
                            </label>
                        </div>
                    </div>

                    <div id="deptPicker" style="display:<?= $dept_scope_radio === 'specific' ? 'block' : 'none' ?>;">
                        <div class="border rounded p-3" style="max-height:200px;overflow-y:auto;border-radius:10px!important;">
                            <?php if (empty($departments)): ?>
                            <p class="text-muted small mb-0">No active departments found.</p>
                            <?php else: ?>
                            <?php foreach ($departments as $dept): ?>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox"
                                       name="dept_ids[]" value="<?= $dept['id'] ?>"
                                       id="dept_<?= $dept['id'] ?>"
                                       <?= in_array((int)$dept['id'], $dept_scope_ids, false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dept_<?= $dept['id'] ?>">
                                    <?= h($dept['name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
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
function toggleDeptPicker(val) {
    document.getElementById('deptPicker').style.display = (val === 'specific') ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
