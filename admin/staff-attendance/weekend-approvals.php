<?php
/**
 * Schedule Approvals – weekend (weekly-off) AND Thursday/Friday slot
 * (On Campus / Online Class) change requests.
 *
 * Staff submit requests from My Schedule; each request is routed through the
 * SCHEDULE approval chain configured for the requester's user group
 * (att_schedule_approval_flow — separate from the Leave Management chain).
 * Members of the current step's group approve or reject here; module admins
 * can always act. On final approval the change is applied EFFECTIVE-DATED
 * from the approval date — earlier days keep the previously approved schedule.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
$user     = auth_user();
$is_admin = att_is_admin();

$page_title = 'Schedule Approvals';
$db         = db();
$day_names  = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$fmt_days   = function (string $csv) use ($day_names): string {
    return implode(', ', array_map(fn($n) => $day_names[$n] ?? (string)$n, att_parse_off_days($csv)));
};
$fmt_slots_json = static function (?string $json): string {
    $arr = json_decode((string)$json, true);
    if (!is_array($arr) || empty($arr)) return 'Remove custom slots';
    return implode('; ', array_map(
        fn($s) => (($s['location'] ?? '') === 'online' ? 'Online Class' : 'On Campus')
                . ' ' . h((string)($s['start'] ?? '')) . '–' . h((string)($s['end'] ?? '')),
        $arr
    ));
};

// ── POST: approve / reject the current step (weekend or slots) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $type   = ($_POST['type'] ?? 'weekend') === 'slots' ? 'slots' : 'weekend';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');
    $back   = APP_URL . '/staff-attendance/weekend-approvals.php';

    $req_table = $type === 'slots' ? 'att_slot_requests'          : 'att_weekend_requests';
    $apr_table = $type === 'slots' ? 'att_slot_request_approvals' : 'att_weekend_request_approvals';

    $req = null;
    try {
        $stmt = $db->prepare("SELECT * FROM $req_table WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
    } catch (Throwable $e) {
        // Migration not applied.
    }

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
    $can_act = $type === 'slots'
        ? att_slot_user_can_act($req, $user)
        : att_weekend_user_can_act($req, $user);
    if (!$can_act && !$is_admin) {
        flash_set('error', 'You are not authorized to act on this step.');
        redirect($back);
    }

    $step = $type === 'slots'
        ? att_slot_current_step($id, (int)$req['current_step'])
        : att_weekend_current_step($id, (int)$req['current_step']);

    $db->beginTransaction();
    try {
        if ($step) {
            $db->prepare(
                "UPDATE $apr_table
                    SET status = ?, approver_id = ?, note = ?, acted_at = NOW()
                  WHERE id = ?"
            )->execute([
                $action === 'approve' ? 'approved' : 'rejected',
                (int)$user['id'],
                $note !== '' ? $note : null,
                (int)$step['id'],
            ]);
        }
        if ($action === 'reject') {
            $db->prepare("UPDATE $req_table SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $result = 'rejected';
        } else {
            $next = $db->prepare("SELECT MIN(step_order) FROM $apr_table WHERE request_id = ? AND step_order > ?");
            $next->execute([$id, (int)$req['current_step']]);
            $next_step = $next->fetchColumn();
            if ($next_step) {
                $db->prepare("UPDATE $req_table SET current_step = ? WHERE id = ?")->execute([(int)$next_step, $id]);
                $result = 'advanced';
            } else {
                $db->prepare("UPDATE $req_table SET status = 'approved' WHERE id = ?")->execute([$id]);
                // Apply EFFECTIVE from today — earlier days keep the schedule
                // that was approved for them (never rewrites the past).
                if ($type === 'slots') {
                    $slots = json_decode((string)($req['slots_json'] ?? ''), true);
                    att_apply_slots((int)$req['user_id'], (int)$req['weekday'], is_array($slots) ? $slots : [], date('Y-m-d'));
                } else {
                    att_apply_weekend((int)$req['user_id'], (string)$req['weekly_off_days'], date('Y-m-d'));
                }
                $result = 'approved';
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash_set('error', 'Could not record your decision. Please try again.');
        redirect($back);
    }

    log_change('staff-attendance', 'UPDATE', $id, $type === 'slots' ? 'Slot request' : 'Weekend request', 'approval', 'pending', $result);
    flash_set('success', match ($result) {
        'rejected' => ($type === 'slots' ? 'Slot' : 'Weekend') . ' request rejected.',
        'approved' => 'Final approval recorded — the change applies from today onward; earlier days keep the previous schedule.',
        default    => 'Approved. The request has advanced to the next approval step.',
    });
    redirect($back);
}

// ── Requests awaiting me + all pending (admins) ──────────────────────────
$group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);

$awaiting_wk    = [];
$awaiting_slots = [];
$all_pending_wk    = [];
$all_pending_slots = [];
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
        $awaiting_wk = $stmt->fetchAll();
    }
    if ($is_admin) {
        $all_pending_wk = $db->query(
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
try {
    if (!empty($group_ids)) {
        $ph   = implode(',', array_fill(0, count($group_ids), '?'));
        $stmt = $db->prepare(
            "SELECT r.*, u.full_name AS requester_name
               FROM att_slot_requests r
               JOIN users u ON u.id = r.user_id
               JOIN att_slot_request_approvals a
                 ON a.request_id = r.id AND a.step_order = r.current_step
              WHERE r.status = 'pending' AND a.status = 'pending'
                AND a.group_id IN ($ph)
                AND r.user_id <> ?
              ORDER BY r.created_at ASC"
        );
        $stmt->execute(array_merge($group_ids, [(int)$user['id']]));
        $awaiting_slots = $stmt->fetchAll();
    }
    if ($is_admin) {
        $all_pending_slots = $db->query(
            "SELECT r.*, u.full_name AS requester_name
               FROM att_slot_requests r
               JOIN users u ON u.id = r.user_id
              WHERE r.status = 'pending'
              ORDER BY r.created_at ASC"
        )->fetchAll();
    }
} catch (Throwable $e) {
    // Slot migration not applied yet.
}

require_once __DIR__ . '/../includes/header.php';

/** Render a table of pending requests with approve/reject actions. */
$render = function (array $rows, bool $can_act, string $type) use ($fmt_days, $fmt_slots_json, $day_names) { ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th class="px-3">Staff</th><th>Requested Change</th><th>Reason</th><th>Chain Progress</th>
                <?php if ($can_act): ?><th style="width:260px;">Action</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Nothing pending.</td></tr>
            <?php else: foreach ($rows as $r):
                $steps = $type === 'slots'
                    ? att_slot_request_approvals((int)$r['id'])
                    : att_weekend_request_approvals((int)$r['id']);
            ?>
                <tr>
                    <td class="px-3">
                        <strong><?= h($r['requester_name']) ?></strong>
                        <div class="text-muted small"><?= h(date('d M Y', strtotime($r['created_at']))) ?></div>
                    </td>
                    <td>
                        <?php if ($type === 'slots'): ?>
                            <strong><?= h($day_names[(int)$r['weekday']] ?? '?') ?> slots</strong>
                            <div class="small"><?= $fmt_slots_json((string)($r['slots_json'] ?? '')) ?></div>
                        <?php else: ?>
                            <strong>Weekend: <?= h($fmt_days((string)$r['weekly_off_days'])) ?></strong>
                        <?php endif; ?>
                    </td>
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
                            <input type="hidden" name="type" value="<?= h($type) ?>">
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Note (optional)">
                            <button name="action" value="approve" class="btn btn-sm btn-success" onclick="return confirm('Approve this request? It will apply from today onward.');"><i class="fas fa-check"></i></button>
                            <button name="action" value="reject" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?');"><i class="fas fa-times"></i></button>
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
            <li class="breadcrumb-item active">Schedule Approvals</li>
        </ol>
    </nav>
    <?php if ($is_admin): ?>
    <a href="<?= APP_URL ?>/staff-attendance/schedule-flow.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-diagram-project me-1"></i> Schedule Approval Flow</a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<div class="alert alert-info small py-2" style="border-radius:10px;">
    <i class="fas fa-circle-info me-1"></i>
    Schedule changes (weekend + Thursday/Friday On Campus / Online Class slots) use their <strong>own approval chain</strong>,
    separate from Leave Management. Approved changes apply <strong>from the approval date onward</strong> — earlier days keep the previously approved schedule.
</div>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-umbrella-beach me-2 text-primary"></i>Weekend Requests — Awaiting My Approval <span class="badge bg-warning text-dark ms-1"><?= count($awaiting_wk) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($awaiting_wk, true, 'weekend'); ?></div>
</div>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clock me-2 text-primary"></i>Thu/Fri Slot Requests (On Campus / Online Class) — Awaiting My Approval <span class="badge bg-warning text-dark ms-1"><?= count($awaiting_slots) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($awaiting_slots, true, 'slots'); ?></div>
</div>

<?php if ($is_admin): ?>
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>All Pending Weekend Requests (admin) <span class="badge bg-secondary ms-1"><?= count($all_pending_wk) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($all_pending_wk, true, 'weekend'); ?></div>
</div>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>All Pending Slot Requests (admin) <span class="badge bg-secondary ms-1"><?= count($all_pending_slots) ?></span></h6>
    </div>
    <div class="card-body p-0"><?php $render($all_pending_slots, true, 'slots'); ?></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
