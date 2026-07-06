<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('user-groups');

$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$group = null;

if ($id) {
    $stmt = db()->prepare('SELECT * FROM user_groups WHERE id = ?');
    $stmt->execute([$id]);
    $group = $stmt->fetch();
}
if (!$group) {
    flash_set('error', 'User group not found.');
    redirect(APP_URL . '/user-groups/index.php');
}

// Who can add/remove members. Modifying the Super Admin group requires super admin.
$can_manage = is_super_admin() || can_access('user-groups', 'can_edit');
if ($group['is_super'] && !is_super_admin()) {
    $can_manage = false;
}

// Membership = users that have an assignment to this group, plus legacy users
// whose primary group is this group but who have no assignment rows at all.
function ug_member_ids_subquery(): string {
    return 'SELECT user_id FROM user_group_assignments WHERE group_id = ?
            UNION
            SELECT id FROM users u2
            WHERE u2.group_id = ?
              AND NOT EXISTS (SELECT 1 FROM user_group_assignments x WHERE x.user_id = u2.id)';
}

// ── Handle add / remove ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (!$can_manage) {
        flash_set('error', 'You do not have permission to manage members of this group.');
        redirect(APP_URL . '/user-groups/members.php?id=' . $id);
    }

    $action = (string)($_POST['action'] ?? '');
    $db     = db();

    if ($action === 'add') {
        $user_ids = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['user_ids'] ?? []))
        )));

        if (empty($user_ids)) {
            flash_set('error', 'Select at least one user to add.');
            redirect(APP_URL . '/user-groups/members.php?id=' . $id);
        }

        // Keep only ids that point to real, active users.
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $valid = $db->prepare("SELECT id FROM users WHERE is_active = 1 AND id IN ($placeholders)");
        $valid->execute($user_ids);
        $valid_ids = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));

        $added = 0;
        if ($valid_ids) {
            $ins = $db->prepare(
                'INSERT IGNORE INTO user_group_assignments (user_id, group_id, is_primary) VALUES (?, ?, 0)'
            );
            foreach ($valid_ids as $uid) {
                $ins->execute([$uid, $id]);
                $added += $ins->rowCount();
            }
        }

        if ($added > 0) {
            flash_set('success', $added . ' user' . ($added === 1 ? '' : 's') . ' added to <strong>' . h($group['name']) . '</strong>.');
        } else {
            flash_set('error', 'No new users were added (they may already be members).');
        }
        redirect(APP_URL . '/user-groups/members.php?id=' . $id);
    }

    if ($action === 'remove') {
        $uid = (int)($_POST['user_id'] ?? 0);

        // Never orphan a user: their primary group must stay valid.
        $pg = $db->prepare('SELECT group_id, full_name FROM users WHERE id = ?');
        $pg->execute([$uid]);
        $target = $pg->fetch();

        if (!$target) {
            flash_set('error', 'User not found.');
        } elseif ((int)$target['group_id'] === $id) {
            flash_set('error', 'This is the user\'s primary group. Change their primary group from the user\'s Edit page first.');
        } else {
            $db->prepare('DELETE FROM user_group_assignments WHERE user_id = ? AND group_id = ?')
               ->execute([$uid, $id]);
            flash_set('success', h($target['full_name']) . ' removed from <strong>' . h($group['name']) . '</strong>.');
        }
        redirect(APP_URL . '/user-groups/members.php?id=' . $id);
    }

    redirect(APP_URL . '/user-groups/members.php?id=' . $id);
}

// ── Load members and candidates ───────────────────────────────────────────────
$members = db()->prepare(
    'SELECT u.id, u.full_name, u.username, u.email, u.is_active,
            (u.group_id = ?) AS is_primary_group
     FROM users u
     WHERE u.id IN (' . ug_member_ids_subquery() . ')
     ORDER BY u.full_name'
);
$members->execute([$id, $id, $id]);
$members = $members->fetchAll();

$candidates = db()->prepare(
    'SELECT u.id, u.full_name, u.username, g.name AS primary_group
     FROM users u
     JOIN user_groups g ON g.id = u.group_id
     WHERE u.is_active = 1
       AND u.id NOT IN (' . ug_member_ids_subquery() . ')
     ORDER BY u.full_name'
);
$candidates->execute([$id, $id]);
$candidates = $candidates->fetchAll();

$page_title = 'Group Members';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/user-groups/index.php">User Groups</a></li>
            <li class="breadcrumb-item active">Members</li>
        </ol>
    </nav>
</div>

<div class="row g-4">

    <!-- Add members -->
    <?php if ($can_manage): ?>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-muted"></i>Add Users to <?= h($group['name']) ?></h6>
            </div>
            <div class="card-body p-4">
                <?php if (empty($candidates)): ?>
                    <p class="text-muted mb-0">Every active user is already a member of this group.</p>
                <?php else: ?>
                <form method="POST" action="<?= APP_URL ?>/user-groups/members.php?id=<?= $id ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <div class="mb-2">
                        <input type="text" id="memberFilter" class="form-control form-control-sm" style="border-radius:8px;"
                               placeholder="Search users to add…" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <select name="user_ids[]" id="candidateSelect" class="form-select" multiple size="12" style="border-radius:8px;">
                            <?php foreach ($candidates as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                    data-search="<?= h(strtolower($c['full_name'] . ' ' . $c['username'])) ?>">
                                <?= h($c['full_name']) ?> (<?= h($c['username']) ?>) — <?= h($c['primary_group']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Hold Ctrl/Cmd to select multiple users.</div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-plus me-1"></i> Add Selected
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Current members -->
    <div class="col-lg-<?= $can_manage ? '7' : '12' ?>">
        <div class="card h-100">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Members of <?= h($group['name']) ?></h6>
                <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-4" style="width:40px;">#</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <?php if ($can_manage): ?><th class="text-end px-4">Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($members)): ?>
                            <tr><td colspan="<?= $can_manage ? 5 : 4 ?>" class="text-center text-muted py-4">No members yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($members as $i => $m): ?>
                            <tr>
                                <td class="px-4"><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= h($m['full_name']) ?></strong>
                                    <?php if ($m['is_primary_group']): ?>
                                        <span class="badge bg-light text-dark ms-1" title="This is the user's primary group">Primary</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($m['username']) ?></td>
                                <td><?= h($m['email']) ?></td>
                                <?php if ($can_manage): ?>
                                <td class="text-end px-4">
                                    <?php if ($m['is_primary_group']): ?>
                                        <span class="text-muted small" title="Change the user's primary group from their Edit page.">
                                            <i class="fas fa-lock me-1"></i>Primary
                                        </span>
                                    <?php else: ?>
                                    <form method="POST" action="<?= APP_URL ?>/user-groups/members.php?id=<?= $id ?>"
                                          onsubmit="return confirm('Remove <?= h(addslashes($m['full_name'])) ?> from this group?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Remove from group">
                                            <i class="fas fa-user-minus"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($can_manage && !empty($candidates)): ?>
<script>
(function () {
    var filter = document.getElementById('memberFilter');
    var select = document.getElementById('candidateSelect');
    if (!filter || !select) return;
    filter.addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        Array.prototype.forEach.call(select.options, function (opt) {
            var hay = opt.getAttribute('data-search') || '';
            opt.hidden = q !== '' && hay.indexOf(q) === -1;
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
