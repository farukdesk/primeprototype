<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('users');

$page_title = 'Users';

// ── Filters ───────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');

// Multi-select group filter. `group_ids[]` holds the chosen groups and
// `group_mode` decides whether they are included or excluded.
// `group_id` (singular) is kept as a legacy fallback for old links
// (e.g. the "N users" badge on the User Groups page).
$selected_group_ids = array_map('intval', (array)($_GET['group_ids'] ?? []));
if (empty($selected_group_ids) && !empty($_GET['group_id'])) {
    $selected_group_ids = [(int)$_GET['group_id']];
}
$selected_group_ids = array_values(array_unique(array_filter($selected_group_ids, static fn($v) => $v > 0)));

$group_mode = ($_GET['group_mode'] ?? 'in') === 'except' ? 'except' : 'in';

$where  = [];
$params = [];

if (!empty($selected_group_ids)) {
    $placeholders = implode(',', array_fill(0, count($selected_group_ids), '?'));
    $where[]  = $group_mode === 'except' ? "u.group_id NOT IN ($placeholders)" : "u.group_id IN ($placeholders)";
    $params   = array_merge($params, $selected_group_ids);
}
if ($search !== '') {
    $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR g.name LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$sql = 'SELECT u.*, g.name AS group_name, g.is_super,
               fp.dept_id, d.name AS dept_name
        FROM users u
        JOIN user_groups g ON g.id = u.group_id
        LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
        LEFT JOIN dept_departments d ON d.id = fp.dept_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY u.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$groups = db()->query('SELECT id, name FROM user_groups ORDER BY name')->fetchAll();

$has_filters = $search !== '' || !empty($selected_group_ids);

// Build the query string used for the "Export CSV" link / pagination-style
// links so exports always honour whatever is currently on screen.
$export_query = $_GET;
unset($export_query['group_id']); // normalise legacy param out of the export link
if (!empty($selected_group_ids)) {
    $export_query['group_ids'] = $selected_group_ids;
    $export_query['group_mode'] = $group_mode;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Users</li>
        </ol>
    </nav>
    <?php if (is_super_admin() || can_access('users', 'can_create')): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/users/bulk-import.php" class="btn btn-outline-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-file-csv me-1"></i> Bulk Import
        </a>
        <a href="<?= APP_URL ?>/users/create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-user-plus me-1"></i> New User
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control" style="max-width:260px;border-radius:10px;"
                   placeholder="Search name, username, email, group…" value="<?= h($search) ?>">

            <!-- Multi-select group filter: choose any number of groups, then
                 decide whether to include only those groups or everyone except them. -->
            <div class="dropdown" id="groupFilterDropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle text-truncate"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside"
                        style="min-width:220px;max-width:280px;border-radius:10px;text-align:left;">
                    <i class="fas fa-layer-group me-1 text-muted"></i>
                    <span id="groupFilterLabel">All Groups</span>
                </button>
                <div class="dropdown-menu p-2 shadow" style="width:300px;border-radius:12px;">
                    <input type="text" id="groupSearchInput" class="form-control form-control-sm mb-2"
                           placeholder="Search groups…" autocomplete="off">

                    <div class="btn-group btn-group-sm w-100 mb-2" role="group">
                        <input type="radio" class="btn-check" name="group_mode" id="groupModeIn" value="in"
                               autocomplete="off" <?= $group_mode !== 'except' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="groupModeIn">Include selected</label>

                        <input type="radio" class="btn-check" name="group_mode" id="groupModeExcept" value="except"
                               autocomplete="off" <?= $group_mode === 'except' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="groupModeExcept">Except selected</label>
                    </div>

                    <div class="d-flex justify-content-between small mb-2">
                        <a href="#" id="groupSelectAll" class="text-decoration-none">Select all (visible)</a>
                        <a href="#" id="groupClearAll" class="text-decoration-none">Clear</a>
                    </div>

                    <div id="groupCheckboxList" style="max-height:220px;overflow-y:auto;">
                        <?php foreach ($groups as $g): ?>
                        <div class="form-check group-option" data-name="<?= h(mb_strtolower($g['name'])) ?>">
                            <input class="form-check-input group-checkbox" type="checkbox" name="group_ids[]"
                                   value="<?= (int)$g['id'] ?>" id="grp<?= (int)$g['id'] ?>"
                                   <?= in_array((int)$g['id'], $selected_group_ids, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="grp<?= (int)$g['id'] ?>"><?= h($g['name']) ?></label>
                        </div>
                        <?php endforeach; ?>
                        <div id="groupNoMatch" class="text-muted small py-2" hidden>No matching groups.</div>
                    </div>
                </div>
            </div>

            <button class="btn btn-outline-primary" style="border-radius:10px;"><i class="fas fa-search me-1"></i>Filter</button>
            <button type="submit" formaction="<?= APP_URL ?>/users/export.php" formtarget="_blank"
                    class="btn btn-outline-success" style="border-radius:10px;">
                <i class="fas fa-file-export me-1"></i>Export CSV
            </button>
            <?php if ($has_filters): ?>
            <a href="<?= APP_URL ?>/users/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
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
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Group</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php $me = auth_user(); ?>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:#4f8ef7;color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:600;flex-shrink:0;">
                                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <?= h($u['full_name']) ?>
                                    <?php if ($u['id'] == $me['id']): ?>
                                    <span class="badge bg-primary ms-1" style="font-size:.65rem;">You</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><?= h($u['email']) ?></td>
                        <td><?= $u['phone'] ? h($u['phone']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($u['is_super']): ?>
                                <span class="badge badge-super"><?= h($u['group_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary"><?= h($u['group_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $u['dept_name'] ? h($u['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (is_super_admin() || can_access('users', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/users/edit.php?id=<?= $u['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (is_super_admin() && $u['id'] != $me['id']): ?>
                                <form method="POST" action="<?= APP_URL ?>/users/reset-password.php"
                                      onsubmit="return confirm('Reset password for ' + <?= json_encode($u['full_name']) ?> + '? A new system-generated password will be emailed to them.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" title="Reset Password" style="border-radius:7px;">
                                        <i class="fas fa-key"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ((is_super_admin() || can_access('users', 'can_delete')) && $u['id'] != $me['id']): ?>
                                <form method="POST" action="<?= APP_URL ?>/users/delete.php"
                                      onsubmit="return confirm('Delete user ' + <?= json_encode($u['full_name']) ?> + '?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
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

<script>
(function () {
    var searchInput = document.getElementById('groupSearchInput');
    var options     = document.querySelectorAll('#groupCheckboxList .group-option');
    var noMatch     = document.getElementById('groupNoMatch');
    var label       = document.getElementById('groupFilterLabel');

    function updateLabel() {
        var checked = document.querySelectorAll('.group-checkbox:checked');
        var modeEl  = document.querySelector('input[name="group_mode"]:checked');
        var mode    = modeEl ? modeEl.value : 'in';

        if (checked.length === 0) {
            label.textContent = 'All Groups';
            return;
        }
        var names = Array.prototype.map.call(checked, function (cb) {
            return cb.closest('.group-option').querySelector('.form-check-label').textContent.trim();
        });
        var prefix = mode === 'except' ? 'Except: ' : '';
        label.textContent = names.length <= 2
            ? prefix + names.join(', ')
            : prefix + names.length + ' groups selected';
    }

    // Predictive search: filter the checkbox list as the admin types.
    searchInput.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        var visibleCount = 0;
        options.forEach(function (opt) {
            var match = opt.getAttribute('data-name').indexOf(q) !== -1;
            opt.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        noMatch.hidden = visibleCount !== 0;
    });

    document.getElementById('groupSelectAll').addEventListener('click', function (e) {
        e.preventDefault();
        options.forEach(function (opt) {
            if (opt.style.display !== 'none') {
                opt.querySelector('.group-checkbox').checked = true;
            }
        });
        updateLabel();
    });

    document.getElementById('groupClearAll').addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.group-checkbox').forEach(function (cb) { cb.checked = false; });
        updateLabel();
    });

    document.querySelectorAll('.group-checkbox, input[name="group_mode"]').forEach(function (el) {
        el.addEventListener('change', updateLabel);
    });

    updateLabel();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
