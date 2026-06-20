<?php
/**
 * Tag / untag users on a file (manage access list).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_edit');
require_once __DIR__ . '/helpers.php';

$file_id = (int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0);
if ($file_id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$f_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
$f_stmt->execute([$file_id]);
$file = $f_stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }
if (!fm_can_view_file($file)) { flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php'); }

$user   = auth_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['tag_user_id'] ?? 0);

    if ($action === 'add' && $user_id > 0) {
        if ($user_id === (int)$file['creator_id']) {
            $errors[] = 'The file creator already has access.';
        } else {
            $u_stmt = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ? AND is_active = 1');
            $u_stmt->execute([$user_id]);
            $tagged_user = $u_stmt->fetch();
            if (!$tagged_user) {
                $errors[] = 'User not found.';
            } else {
                $ins = db()->prepare(
                    'INSERT IGNORE INTO file_manager_tagged_users (file_id, user_id, tagged_by) VALUES (?,?,?)'
                );
                $ins->execute([$file_id, $user_id, $user['id']]);
                if ($ins->rowCount()) {
                    fm_notify_tagged($file, $tagged_user, $user);
                    log_change('file-manager', 'UPDATE', $file_id,
                        "Tagged {$tagged_user['full_name']} on file");
                    flash_set('success', h($tagged_user['full_name']) . ' now has access.');
                } else {
                    flash_set('info', 'User already has access.');
                }
                redirect(APP_URL . '/file-manager/tag-users.php?file_id=' . $file_id);
            }
        }
    }

    if ($action === 'add_multiple') {
        $raw_ids = array_map('intval', $_POST['tag_user_ids'] ?? []);
        $ids     = array_filter($raw_ids, fn($id) => $id > 0 && $id !== (int)$file['creator_id']);
        $ids     = array_unique($ids);

        if (empty($ids)) {
            $errors[] = 'Please select at least one user to grant access.';
        } else {
            $ins        = db()->prepare('INSERT IGNORE INTO file_manager_tagged_users (file_id, user_id, tagged_by) VALUES (?,?,?)');
            $u_stmt     = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ? AND is_active = 1');
            $added      = 0;
            $addedNames = [];
            foreach ($ids as $uid) {
                $u_stmt->execute([$uid]);
                $tagged_user = $u_stmt->fetch();
                if (!$tagged_user) continue;
                $ins->execute([$file_id, $uid, $user['id']]);
                if ($ins->rowCount()) {
                    fm_notify_tagged($file, $tagged_user, $user);
                    $addedNames[] = $tagged_user['full_name'];
                    $added++;
                }
            }
            if ($added > 0) {
                log_change('file-manager', 'UPDATE', $file_id,
                    "Granted access to {$added} user(s): " . implode(', ', $addedNames));
                flash_set('success', "Access granted to {$added} user(s).");
            } else {
                flash_set('info', 'Selected users already have access.');
            }
            redirect(APP_URL . '/file-manager/tag-users.php?file_id=' . $file_id);
        }
    }

    if ($action === 'remove' && $user_id > 0) {
        db()->prepare(
            'DELETE FROM file_manager_tagged_users WHERE file_id = ? AND user_id = ?'
        )->execute([$file_id, $user_id]);
        log_change('file-manager', 'UPDATE', $file_id, "Removed access for user id={$user_id}");
        flash_set('success', 'Access removed.');
        redirect(APP_URL . '/file-manager/tag-users.php?file_id=' . $file_id);
    }
}

// Current tagged users
$tagged_stmt = db()->prepare(
    'SELECT u.id, u.full_name, u.email, tb.full_name AS tagged_by_name, t.created_at
     FROM file_manager_tagged_users t
     JOIN users u  ON u.id  = t.user_id
     JOIN users tb ON tb.id = t.tagged_by
     WHERE t.file_id = ?
     ORDER BY t.created_at ASC'
);
$tagged_stmt->execute([$file_id]);
$tagged_users = $tagged_stmt->fetchAll();

// Already-tagged IDs
$tagged_ids = array_column($tagged_users, 'id');
$tagged_ids[] = (int)$file['creator_id'];

// Users available to add
$avail_users = db()->query(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();
$avail_users = array_filter($avail_users, fn($u) => !in_array((int)$u['id'], $tagged_ids));

$page_title = 'Manage Access – ' . h($file['file_name']);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>"><?= h($file['file_name']) ?></a></li>
            <li class="breadcrumb-item active">Manage Access</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to File
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Current access list -->
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-users me-2 text-primary"></i>Current Access List
                </h6>
                <span class="badge bg-secondary"><?= count($tagged_users) + 1 ?> users</span>
            </div>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <!-- Creator -->
                <li class="list-group-item px-4 py-3 d-flex align-items-center gap-3">
                    <div style="width:36px;height:36px;border-radius:50%;background:#e8f4ff;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-crown text-primary" style="font-size:.75rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <?php
                        $cr_stmt = db()->prepare('SELECT full_name, email FROM users WHERE id = ?');
                        $cr_stmt->execute([$file['creator_id']]);
                        $creator_row = $cr_stmt->fetch();
                        ?>
                        <div class="fw-medium"><?= h($creator_row['full_name'] ?? '—') ?></div>
                        <div class="text-muted" style="font-size:.77rem;"><?= h($creator_row['email'] ?? '') ?></div>
                    </div>
                    <span class="badge bg-primary">Creator</span>
                </li>

                <?php foreach ($tagged_users as $tu): ?>
                <li class="list-group-item px-4 py-3 d-flex align-items-center gap-3">
                    <div style="width:36px;height:36px;border-radius:50%;background:#f0f4ff;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user text-secondary" style="font-size:.75rem;"></i>
                    </div>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="fw-medium" style="font-size:.88rem;"><?= h($tu['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.77rem;"><?= h($tu['email']) ?></div>
                        <div class="text-muted" style="font-size:.72rem;">
                            Added by <?= h($tu['tagged_by_name']) ?> · <?= date('d M Y', strtotime($tu['created_at'])) ?>
                        </div>
                    </div>
                    <form method="POST" class="flex-shrink-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action"      value="remove">
                        <input type="hidden" name="file_id"     value="<?= $file_id ?>">
                        <input type="hidden" name="tag_user_id" value="<?= $tu['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:6px;"
                                onclick="return confirm('Remove access for ' + <?= json_encode($tu['full_name']) ?> + '?')">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Add user form -->
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-warning"></i>Grant Access</h6>
            </div>
            <div class="card-body p-4">
                <?php if (empty($avail_users)): ?>
                <p class="text-muted" style="font-size:.88rem;">All active users already have access.</p>
                <?php else: ?>
                <form method="POST" id="grantAccessForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"  value="add_multiple">
                    <input type="hidden" name="file_id" value="<?= $file_id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Search Users <span class="text-danger">*</span></label>
                        <input type="text" id="grantSearch" class="form-control mb-2"
                               placeholder="Type name or email to filter…" autocomplete="off">
                        <div id="grantUserList" style="max-height:240px;overflow-y:auto;border:1px solid #dee2e6;border-radius:8px;padding:8px;">
                            <?php foreach ($avail_users as $u): ?>
                            <div class="form-check mb-1 grant-user-item"
                                 data-name="<?= strtolower(h($u['full_name'])) ?>"
                                 data-email="<?= strtolower(h($u['email'])) ?>">
                                <input class="form-check-input grant-user-cb" type="checkbox"
                                       name="tag_user_ids[]" value="<?= $u['id'] ?>"
                                       id="grant_<?= $u['id'] ?>">
                                <label class="form-check-label" for="grant_<?= $u['id'] ?>" style="font-size:.85rem;">
                                    <?= h($u['full_name']) ?>
                                    <span class="text-muted">&lt;<?= h($u['email']) ?>&gt;</span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="grantNoMatch" class="text-muted d-none mt-1" style="font-size:.82rem;">No users match your search.</div>
                        <div class="mt-2 d-flex gap-2">
                            <button type="button" id="grantSelectAll" class="btn btn-sm btn-outline-secondary" style="border-radius:6px;">Select all visible</button>
                            <button type="button" id="grantClearAll"  class="btn btn-sm btn-outline-secondary" style="border-radius:6px;">Clear all</button>
                        </div>
                    </div>
                    <div class="form-text mb-3">
                        Selected users will receive an email notification and can view this file.
                    </div>
                    <button type="submit" class="btn btn-warning w-100" style="border-radius:10px;">
                        <i class="fas fa-tags me-1"></i> Grant Access
                    </button>
                </form>
                <script>
                (function () {
                    var searchInput  = document.getElementById('grantSearch');
                    var items        = document.querySelectorAll('.grant-user-item');
                    var noMatch      = document.getElementById('grantNoMatch');
                    var selectAllBtn = document.getElementById('grantSelectAll');
                    var clearAllBtn  = document.getElementById('grantClearAll');
                    var form         = document.getElementById('grantAccessForm');

                    function visibleItems() {
                        return Array.from(items).filter(function (el) { return el.style.display !== 'none'; });
                    }

                    searchInput.addEventListener('input', function () {
                        var q = this.value.toLowerCase().trim();
                        var count = 0;
                        items.forEach(function (el) {
                            var match = !q || el.dataset.name.includes(q) || el.dataset.email.includes(q);
                            el.style.display = match ? '' : 'none';
                            if (match) count++;
                        });
                        noMatch.classList.toggle('d-none', count > 0);
                    });

                    selectAllBtn.addEventListener('click', function () {
                        visibleItems().forEach(function (el) {
                            el.querySelector('.grant-user-cb').checked = true;
                        });
                    });

                    clearAllBtn.addEventListener('click', function () {
                        items.forEach(function (el) {
                            el.querySelector('.grant-user-cb').checked = false;
                        });
                    });

                    form.addEventListener('submit', function (e) {
                        var checked = Array.from(document.querySelectorAll('.grant-user-cb:checked'));
                        if (checked.length === 0) {
                            e.preventDefault();
                            alert('Please select at least one user to grant access.');
                        }
                    });
                })();
                </script>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4" style="border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <i class="fas fa-lock text-muted mt-1"></i>
                    <div style="font-size:.85rem;">
                        <strong>Visibility rules:</strong>
                        <ul class="mt-2 mb-0 ps-3 text-muted">
                            <li>Super admins can always see all files</li>
                            <li>The file creator can always see their own file</li>
                            <li>The current holder can always see the file</li>
                            <li>Users explicitly granted access can see the file</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
