<?php
/**
 * Approved Leave / Day Off marks.
 *
 * Module admins and members of the "Registrar office" user group can mark a
 * day (or a date range) as Approved Leave or Day Off for one or more staff
 * members. A marked day counts as On Leave (never Absent) on every attendance
 * report. Days are also marked automatically when a leave request receives
 * its final approval in Leave Management.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
if (!att_can_mark_dayoff()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$page_title = 'Approved Leave / Day Off';
$db         = db();
$user       = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM att_day_status WHERE id = ?')->execute([$id]);
            log_change('staff-attendance', 'DELETE', $id, 'Day status', null, null, 'removed');
            flash_set('success', 'Mark removed.');
        } catch (Throwable $e) {
            flash_set('error', 'Could not remove the mark. Has the staff-attendance-self-service.sql migration been applied?');
        }
        redirect(APP_URL . '/staff-attendance/day-status.php');
    }

    if ($act === 'add') {
        $from = att_normalize_date($_POST['from_date'] ?? '');
        $to   = ($_POST['to_date'] ?? '') !== '' ? att_normalize_date($_POST['to_date']) : $from;
        if ($from > $to) [$from, $to] = [$to, $from];
        $status = ($_POST['status'] ?? '') === 'day_off' ? 'day_off' : 'approved_leave';
        $note   = trim($_POST['note'] ?? '');
        $ids    = array_map('intval', (array)($_POST['user_ids'] ?? []));
        $valid  = array_map(fn($u) => (int)$u['id'], att_mappable_users());
        $ids    = array_values(array_intersect($ids, $valid));

        if (empty($ids)) {
            flash_set('error', 'Please choose at least one staff member.');
        } elseif ((strtotime($to) - strtotime($from)) / 86400 > 62) {
            flash_set('error', 'Date range too large (max 62 days).');
        } else {
            try {
                $n = 0;
                for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
                    foreach ($ids as $uid) {
                        att_mark_dayoff($uid, date('Y-m-d', $d), $status, $note !== '' ? $note : null, 'manual', null, (int)$user['id']);
                        $n++;
                    }
                }
                log_change('staff-attendance', 'CREATE', 0, 'Day status', null, null, $status . ' ' . $from . ' → ' . $to . ' × ' . count($ids) . ' staff');
                flash_set('success', $n . ' day mark(s) saved.');
            } catch (Throwable $e) {
                flash_set('error', 'Could not save. Please run the staff-attendance-self-service.sql migration first.');
            }
        }
        redirect(APP_URL . '/staff-attendance/day-status.php');
    }
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$m_from = $month . '-01';
$m_to   = date('Y-m-t', strtotime($m_from));
$rows   = att_day_status_rows($m_from, $m_to);
$staff  = att_mappable_users();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Approved Leave / Day Off</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-umbrella-beach me-2 text-primary"></i>Mark a Day as Approved Leave / Day Off</h6>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">From</label>
                <input type="date" name="from_date" class="form-control" required value="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">To <span class="fw-normal text-muted">(optional)</span></label>
                <input type="date" name="to_date" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Mark as</label>
                <select name="status" class="form-select">
                    <option value="approved_leave">Approved Leave</option>
                    <option value="day_off">Day Off</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Note <span class="fw-normal text-muted">(optional)</span></label>
                <input type="text" name="note" class="form-control" maxlength="255" placeholder="e.g. Special day off">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small mb-1">Staff members <span class="fw-normal text-muted">(Ctrl/Cmd-click for multiple)</span></label>
                <select name="user_ids[]" class="form-select" multiple size="10" required>
                    <?php foreach ($staff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h($s['full_name']) ?><?= !empty($s['employee_id']) ? ' (' . h($s['employee_id']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Marks</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Marks in <?= h(date('F Y', strtotime($m_from))) ?></h6>
        <form method="get" class="d-flex gap-2">
            <input type="month" name="month" class="form-control form-control-sm" value="<?= h($month) ?>">
            <button class="btn btn-sm btn-outline-primary">Go</button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th class="px-3">Date</th><th>Staff</th><th>Status</th><th>Note</th><th>Source</th><th>Marked By</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No marks in this month.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td class="px-3"><?= h(date('d M Y (D)', strtotime($r['status_date']))) ?></td>
                        <td><strong><?= h($r['full_name']) ?></strong></td>
                        <td>
                            <?php if ($r['status'] === 'day_off'): ?>
                                <span class="badge bg-secondary">Day Off</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Approved Leave</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= h((string)($r['note'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= $r['source'] === 'leave' ? '<span class="badge bg-info text-dark">Leave approval</span>' : '<span class="badge bg-light text-dark border">Manual</span>' ?></td>
                        <td class="small"><?= h((string)($r['created_by_name'] ?? '—')) ?></td>
                        <td class="text-end pe-3">
                            <form method="POST" onsubmit="return confirm('Remove this mark?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
