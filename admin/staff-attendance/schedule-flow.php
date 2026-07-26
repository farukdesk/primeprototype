<?php
/**
 * Schedule Approval Flow (admin).
 *
 * Configures the DEDICATED, ordered approval chain for schedule changes
 * (weekend + Thursday/Friday On Campus / Online Class slots), scoped per
 * requester user group. This chain lives in att_schedule_approval_flow and is
 * completely SEPARATE from the Leave Management chain (leave_approval_flow),
 * so leave and schedule changes can be approved by different people.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
if (!att_is_admin()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$page_title = 'Schedule Approval Flow';
$db         = db();

$groups = [];
try {
    $groups = $db->query('SELECT id, name FROM user_groups ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    // ignore
}
$rg = (int)($_GET['g'] ?? 0);

// ── POST: add / delete / reorder steps ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act  = $_POST['action'] ?? '';
    $rg   = (int)($_POST['requester_group_id'] ?? $rg);
    $back = APP_URL . '/staff-attendance/schedule-flow.php?g=' . $rg;

    try {
        if ($act === 'add_step') {
            $gid   = (int)($_POST['group_id'] ?? 0);
            $label = trim($_POST['label'] ?? '');
            if ($rg < 1 || $gid < 1) {
                flash_set('error', 'Please choose both the requester group and the approving group.');
            } else {
                $mx = $db->prepare('SELECT COALESCE(MAX(step_order), 0) FROM att_schedule_approval_flow WHERE requester_group_id = ?');
                $mx->execute([$rg]);
                $order = (int)$mx->fetchColumn() + 1;
                $db->prepare(
                    'INSERT INTO att_schedule_approval_flow (requester_group_id, step_order, group_id, label, is_active)
                     VALUES (?,?,?,?,1)'
                )->execute([$rg, $order, $gid, $label !== '' ? $label : null]);
                log_change('staff-attendance', 'CREATE', $rg, 'Schedule flow step', null, null, 'step ' . $order . ' → group ' . $gid);
                flash_set('success', 'Approval step added.');
            }
        } elseif ($act === 'delete_step') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('DELETE FROM att_schedule_approval_flow WHERE id = ?')->execute([$id]);
            // Re-number the remaining steps so the order stays contiguous.
            $rows = $db->prepare('SELECT id FROM att_schedule_approval_flow WHERE requester_group_id = ? ORDER BY step_order ASC, id ASC');
            $rows->execute([$rg]);
            $n = 0;
            $up = $db->prepare('UPDATE att_schedule_approval_flow SET step_order = ? WHERE id = ?');
            foreach ($rows->fetchAll() as $r) $up->execute([++$n, (int)$r['id']]);
            log_change('staff-attendance', 'DELETE', $id, 'Schedule flow step', null, null, null);
            flash_set('success', 'Approval step removed.');
        } elseif ($act === 'move_step') {
            $id  = (int)($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $cur = $db->prepare('SELECT * FROM att_schedule_approval_flow WHERE id = ?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if ($row) {
                $op  = $dir === 'up' ? '<' : '>';
                $ord = $dir === 'up' ? 'DESC' : 'ASC';
                $nb  = $db->prepare(
                    "SELECT * FROM att_schedule_approval_flow
                      WHERE requester_group_id = ? AND step_order $op ?
                      ORDER BY step_order $ord LIMIT 1"
                );
                $nb->execute([(int)$row['requester_group_id'], (int)$row['step_order']]);
                $other = $nb->fetch();
                if ($other) {
                    $up = $db->prepare('UPDATE att_schedule_approval_flow SET step_order = ? WHERE id = ?');
                    $up->execute([(int)$other['step_order'], (int)$row['id']]);
                    $up->execute([(int)$row['step_order'], (int)$other['id']]);
                }
            }
        }
    } catch (Throwable $e) {
        flash_set('error', 'Schedule approval flow is not available yet — please apply the database migration (admin/staff-attendance-schedule-approvals.sql).');
    }
    redirect($back);
}

// ── Steps of the selected requester group ────────────────────────────────
$steps = [];
if ($rg > 0) {
    try {
        $stmt = $db->prepare(
            'SELECT f.*, g.name AS group_name
               FROM att_schedule_approval_flow f
               JOIN user_groups g ON g.id = f.group_id
              WHERE f.requester_group_id = ?
              ORDER BY f.step_order ASC, f.id ASC'
        );
        $stmt->execute([$rg]);
        $steps = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Migration not applied yet.
    }
}

// Requester groups that already have a chain (for the overview badges).
$configured = [];
try {
    foreach ($db->query(
        'SELECT f.requester_group_id, COUNT(*) AS steps, g.name
           FROM att_schedule_approval_flow f
           JOIN user_groups g ON g.id = f.requester_group_id
          WHERE f.is_active = 1
          GROUP BY f.requester_group_id, g.name
          ORDER BY g.name ASC'
    )->fetchAll() as $r) {
        $configured[] = $r;
    }
} catch (Throwable $e) {
    // ignore
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Schedule Approval Flow</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/staff-attendance/weekend-approvals.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-clipboard-check me-1"></i> Schedule Approvals</a>
</div>

<?= flash_show() ?>

<div class="alert alert-info small py-2" style="border-radius:10px;">
    <i class="fas fa-circle-info me-1"></i>
    This chain approves <strong>schedule changes only</strong> — weekend (weekly off) requests and Thursday/Friday
    <strong>On Campus / Online Class</strong> slot requests. It is completely <strong>separate from the Leave Management
    approval flow</strong>, so different people can approve leaves and schedules. Configure one chain per requester group.
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-primary"></i>Requester Group</h6></div>
            <div class="card-body">
                <form method="GET" class="d-flex gap-2 align-items-end mb-3">
                    <div class="flex-grow-1">
                        <label class="form-label fw-semibold small mb-1">Configure the chain for members of</label>
                        <select name="g" class="form-select" onchange="this.form.submit()">
                            <option value="0">— choose a user group —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $rg === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
                <h6 class="fw-semibold small text-muted">Groups with a configured chain</h6>
                <?php if (empty($configured)): ?>
                    <p class="small text-muted mb-0">No schedule approval chains configured yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($configured as $c): ?>
                        <li class="mb-1">
                            <a href="?g=<?= (int)$c['requester_group_id'] ?>"><?= h($c['name']) ?></a>
                            <span class="badge bg-light text-dark border ms-1"><?= (int)$c['steps'] ?> step(s)</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-diagram-project me-2 text-primary"></i>Approval Steps<?= $rg > 0 ? ' — in order' : '' ?></h6></div>
            <div class="card-body p-0">
                <?php if ($rg < 1): ?>
                    <p class="text-muted p-4 mb-0">Choose a requester group on the left to configure its chain.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th class="px-3" style="width:60px;">Step</th><th>Approving Group</th><th>Label</th><th style="width:150px;"></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($steps)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No steps yet — add the first approver below.</td></tr>
                        <?php else: foreach ($steps as $s): ?>
                            <tr>
                                <td class="px-3 fw-bold"><?= (int)$s['step_order'] ?></td>
                                <td><?= h($s['group_name']) ?></td>
                                <td class="small"><?= h((string)($s['label'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end pe-3">
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="move_step">
                                        <input type="hidden" name="requester_group_id" value="<?= $rg ?>">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button name="dir" value="up" class="btn btn-sm btn-outline-secondary" title="Move up"><i class="fas fa-arrow-up"></i></button>
                                        <button name="dir" value="down" class="btn btn-sm btn-outline-secondary" title="Move down"><i class="fas fa-arrow-down"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this approval step?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_step">
                                        <input type="hidden" name="requester_group_id" value="<?= $rg ?>">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="border-top p-3">
                    <form method="POST" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_step">
                        <input type="hidden" name="requester_group_id" value="<?= $rg ?>">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small mb-1">Approving group (next step)</label>
                            <select name="group_id" class="form-select form-select-sm" required>
                                <option value="">— choose —</option>
                                <?php foreach ($groups as $g): ?>
                                <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Label (optional)</label>
                            <input type="text" name="label" class="form-control form-control-sm" placeholder="e.g. Head of Dept">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary btn-sm w-100"><i class="fas fa-plus me-1"></i> Add Step</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
