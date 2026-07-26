<?php
/**
 * Weekend (weekly-off) change requests – approval chain.
 *
 * Staff submit weekend requests from My Schedule; each request is routed
 * through the ordered approval chain configured for the requester's user group
 * (shared with Leave Management). Members of the current step's group approve
 * or reject here; module admins can always act. On final approval the
 * requested days are applied to the member's attendance schedule.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
$user     = auth_user();
$is_admin = att_is_admin();

$page_title = 'Weekend Approvals';
$db         = db();
$day_names  = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$fmt_days   = function (string $csv) use ($day_names): string {
    return implode(', ', array_map(fn($n) => $day_names[$n] ?? (string)$n, att_parse_off_days($csv)));
};

// ── POST: approve / reject the current step ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
    $back   = APP_URL . '/staff-attendance/weekend-approvals.php';

    $stmt = $db->prepare('SELECT * FROM att_weekend_requests WHERE id = ?');
    $stmt->execute([$id]);
    $req = $stmt->fetch();

    if (!$req || !in_array($action, ['approve', 'reject'], true)) {
        flash_set('error', 'Invalid request.');
        redirect($back);
    }
    if ($req['status'] !== 'pending') {
        flash_set('error', 'This request is no longer pending.');
        redirect($back);
    }
    if ((int)$req['user_id'] === (int)$user['id']) {
        flash_set('error', 'You cannot approve or reject your own request.');
        redirect($back);
    }
    if (!att_weekend_user_can_act($req, $user) && !$is_admin) {
        flash_set('error', 'You are not authorized to act on this step.');
        redirect($back);
    }

    $step = att_weekend_current_step($id, (int)$req['current_step']);
    $db->beginTransaction();
    try {
        if ($step) {
            $db->prepare(
                'UPDATE att_weekend_request_approvals
                    SET status = ?, approver_id = ?, note = ?, acted_at = NOW()
                  WHERE id = ?'
            )->execute([
                $action === 'approve' ? 'approved' : 'rejected',
                (int)$user['id'],
                $note !== '' ? $note : null,
                (int)$step['id'],
            ]);
        }
        if ($action === 'reject') {
            $db->prepare("UPDATE att_weekend_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $result = 'rejected';
        } else {
            $next = $db->prepare('SELECT MIN(step_order) FROM att_weekend_request_approvals WHERE request_id = ? AND step_order > ?');
            $next->execute([$id, (int)$req['current_step']]);
            $next_step = $next->fetchColumn();
            if ($next_step) {
                $db->prepare('UPDATE att_weekend_requests SET current_step = ? WHERE id = ?')->execute([(int)$next_step, $id]);
                $result = 'advanced';
            } else {
                $db->prepare("UPDATE att_weekend_requests SET status = 'approved' WHERE id = ?")->execute([$id]);
                att_apply_weekend((int)$req['user_id'], (string)$req['weekly_off_days']);
                $result = 'approved';
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash_set('error', 'Could not record your decision. Please try again.');
        redirect($back);
    }

    log_change('staff-attendance', 'UPDATE', $id, 'Weekend request', 'approval', 'pending', $result);
    flash_set('success', match ($result) {
        'rejected' => 'Weekend request rejected.',
        'approved' => 'Final approval recorded — the new weekend now applies to the member\'s attendance schedule.',
        default    => 'Approved. The request has advanced to the next approval step.',
    });
    redirect($back);
}

// ── Requests awaiting me + all pending (admins) ──────────────────────────────
$group_ids   = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
$awaiting    = [];
$all_pending = [];
try {
    if (!empty($group_ids)) {
        $ph   = implode(',', array_fill(0, count($group_ids), '?'));
        $stmt = $db->prepare(
            "SELECT r.*, u.full_name AS requester_name
               FROM att_weekend_requests r
               JOIN users u ON u.id = r.user_id
               JOIN att_weekend_request_approvals a
                 ON a.request_id = r.id AND a.step_order = r.current_step
              WHERE r.status = 'pending' AND a.status = 'pending'
                AND a.group_id IN ($ph)
                AND r.user_id <> ?
              ORDER BY r.created_at ASC"
        );
        $stmt->execute(array_merge($group_ids, [(int)$user['id']]));
        $awaiting = $stmt->fetchAll();
    }
    if ($is_admin) {
        $all_pending = $db->query(
            "SELECT r.*, u.full_name AS requester_name
               FROM att_weekend_requests r
               JOIN users u ON u.id = r.user_id
              WHERE r.status = 'pending'
              ORDER BY r.created_at ASC"
        )->fetchAll();
    }
} catch (Throwable $e) {
    // Migration not applied yet.
}

require_once __DIR__ . '/../includes/header.php';

/** Render a table of pending weekend requests with approve/reject actions. */
$render = function (array $rows, bool $can_act) use ($fmt_days) { ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th class="px-3">Staff</th><th>Requested Weekend</th><th>Reason</th><th>Chain Progress</th>
                <?php if ($can_act): ?><th style="width:260px;">Action</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nothing pending.</td></tr>
            <?php else: foreach ($rows as $r):
                $steps = att_weekend_request_approvals((int)$r['id']);
            ?>
                <tr>
                    <td class="px-3">
                        <strong><?= h($r['requester_name']) ?></strong>
                        <div class="text-muted small"><?= h(date('d M Y', strtotime($r['created_at']))) ?></div>
                    </td>
                    <td><strong><?= h($fmt_days((string)$r['weekly_off_days'])) ?></strong></td>
                    <td class="small"><?= h((string)($r['reason'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="small">
                        <?php foreach ($steps as $st): ?>
                            <div>
                                <?php if ($st['status'] === 'approved'): ?><i class="fas fa-check text-success me-1"></i>
                                <?php elseif ($st['status'] === 'rejected'): ?><i class="fas fa-times text-danger me-1"></i>
                                <?php else: ?><i class="far fa-clock text-muted me-1"></i><?php endif; ?>
                                <?= h($st['label'] ?: $st['group_name']) ?>
                                <?php if (!empty($st['approver_name'])): ?><span class="text-muted">— <?= h($st['approver_name']) ?></span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td>
                    <?php if ($can_act): ?>
                    <td>
                        <form method="POST" class="d-flex gap-1 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Note (optional)">
                            <button name="action" value="approve" class="btn btn-sm btn-success" onclick="return confirm('Approve this weekend request?');"><i class="fas fa-check"></i></button>
                            <button name="action" value="reject" class="btn btn-sm btn-danger" onclick="return confirm('Reject this weekend request?');"><i class="fas fa-times"></i></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php }; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Weekend Approvals</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clipboard-check me-2 text-primary"></i>Awaiting My Approval <span class="badge bg-warning text-dark ms-1"><?= count($awaiting) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($awaiting, true); ?></div>
</div>

<?php if ($is_admin): ?>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>All Pending Requests (admin) <span class="badge bg-secondary ms-1"><?= count($all_pending) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($all_pending, true); ?></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
