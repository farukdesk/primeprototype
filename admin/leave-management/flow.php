<?php
/**
 * Admin: configure the ordered, multi-user-group approval flow for leave.
 * Each active step is a user group that approves in sequence.
 *
 * Note: changing the flow only affects requests submitted afterwards; each
 * request snapshots the flow at submission time.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('leave-management', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Leave Approval Flow';
$db         = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $label    = trim($_POST['label'] ?? '');
        // Validate the group exists, is active and not super.
        $chk = $db->prepare('SELECT id FROM user_groups WHERE id = ? AND is_active = 1 AND is_super = 0');
        $chk->execute([$group_id]);
        if (!$chk->fetchColumn()) {
            flash_set('error', 'Please choose a valid user group.');
        } else {
            $next = (int)$db->query('SELECT COALESCE(MAX(step_order),0) + 1 FROM leave_approval_flow')->fetchColumn();
            $db->prepare('INSERT INTO leave_approval_flow (step_order, group_id, label, is_active) VALUES (?,?,?,1)')
               ->execute([$next, $group_id, $label !== '' ? $label : null]);
            log_change('leave-management', 'CREATE', $group_id, 'Approval step ' . $next);
            flash_set('success', 'Approval step added.');
        }
    } elseif ($action === 'delete') {
        $sid = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM leave_approval_flow WHERE id = ?')->execute([$sid]);
        // Re-sequence remaining steps to keep them contiguous.
        $rows = $db->query('SELECT id FROM leave_approval_flow ORDER BY step_order ASC, id ASC')->fetchAll();
        $i = 0;
        $upd = $db->prepare('UPDATE leave_approval_flow SET step_order = ? WHERE id = ?');
        foreach ($rows as $r) { $i++; $upd->execute([$i, (int)$r['id']]); }
        log_change('leave-management', 'DELETE', $sid, 'Approval step');
        flash_set('success', 'Approval step removed.');
    } elseif ($action === 'move') {
        $sid = (int)($_POST['id'] ?? 0);
        $dir = $_POST['dir'] ?? '';
        $cur = $db->prepare('SELECT id, step_order FROM leave_approval_flow WHERE id = ?');
        $cur->execute([$sid]);
        $row = $cur->fetch();
        if ($row) {
            $cmp = $dir === 'up' ? '<' : '>';
            $ord = $dir === 'up' ? 'DESC' : 'ASC';
            $nbr = $db->prepare("SELECT id, step_order FROM leave_approval_flow WHERE step_order $cmp ? ORDER BY step_order $ord LIMIT 1");
            $nbr->execute([(int)$row['step_order']]);
            $neighbor = $nbr->fetch();
            if ($neighbor) {
                $swap = $db->prepare('UPDATE leave_approval_flow SET step_order = ? WHERE id = ?');
                $swap->execute([(int)$neighbor['step_order'], (int)$row['id']]);
                $swap->execute([(int)$row['step_order'], (int)$neighbor['id']]);
            }
        }
        flash_set('success', 'Approval order updated.');
    } elseif ($action === 'toggle') {
        $sid = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE leave_approval_flow SET is_active = 1 - is_active WHERE id = ?')->execute([$sid]);
        flash_set('success', 'Step status updated.');
    }

    redirect(APP_URL . '/leave-management/flow.php');
}

$steps = $db->query(
    'SELECT f.*, g.name AS group_name
       FROM leave_approval_flow f
       JOIN user_groups g ON g.id = f.group_id
      ORDER BY f.step_order ASC, f.id ASC'
)->fetchAll();
$groups = lm_group_options();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leave-management/index.php">Leave Management</a></li>
            <li class="breadcrumb-item active">Approval Flow</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-primary"></i>Approval Steps (in order)</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3" style="width:60px;">Step</th>
                                <th>Approving Group</th>
                                <th>Label</th>
                                <th>Status</th>
                                <th style="width:170px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($steps)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No steps configured yet. Add the first approval group below.</td></tr>
                        <?php else: $n = 0; $count = count($steps); foreach ($steps as $s): $n++; ?>
                            <tr>
                                <td class="px-3"><span class="badge bg-primary"><?= (int)$s['step_order'] ?></span></td>
                                <td><strong><?= h($s['group_name']) ?></strong></td>
                                <td class="text-muted"><?= h($s['label'] ?: '—') ?></td>
                                <td>
                                    <?php if ($s['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="dir" value="up">
                                            <button class="btn btn-sm btn-outline-secondary" title="Move up" style="border-radius:7px;" <?= $n === 1 ? 'disabled' : '' ?>><i class="fas fa-arrow-up"></i></button>
                                        </form>
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="dir" value="down">
                                            <button class="btn btn-sm btn-outline-secondary" title="Move down" style="border-radius:7px;" <?= $n === $count ? 'disabled' : '' ?>><i class="fas fa-arrow-down"></i></button>
                                        </form>
                                        <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-info" title="Toggle active" style="border-radius:7px;"><i class="fas fa-power-off"></i></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Remove this approval step?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-muted"></i>Add Approval Step</h6></div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Approving User Group</label>
                        <select name="group_id" class="form-select" required>
                            <option value="">— Select a group —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Label <span class="text-muted">(optional)</span></label>
                        <input type="text" name="label" class="form-control" maxlength="120" placeholder="e.g. Head of Department">
                        <div class="form-text">Shown on the request timeline for this step.</div>
                    </div>
                    <button class="btn btn-primary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i> Add Step</button>
                </form>
                <hr>
                <p class="text-muted small mb-0">
                    Steps are approved in order. A member of each group signs with their uploaded signature.
                    Changes apply to <strong>new</strong> requests only.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
