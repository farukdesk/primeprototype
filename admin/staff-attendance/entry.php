<?php
/**
 * Record or edit a single day's attendance for a staff member.
 * Requires can_edit. Uses att_records' unique (user_id, work_date) so re-saving
 * the same staff/date updates the existing row.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Record Attendance';
$db         = db();
$me         = auth_user();

$sel_user = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$sel_date = att_normalize_date($_GET['date'] ?? $_POST['work_date'] ?? date('Y-m-d'));

// ── Save handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid     = (int)($_POST['user_id'] ?? 0);
    $wdate   = att_normalize_date($_POST['work_date'] ?? '');
    $in_t    = att_normalize_time($_POST['in_time'] ?? '');
    $out_t   = att_normalize_time($_POST['out_time'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    if ($remarks !== '') $remarks = mb_substr($remarks, 0, 255);

    // Validate the selected user is an attendance-visible staff member.
    $valid_ids = array_map(fn($s) => (int)$s['id'], att_staff_list());

    if ($uid < 1 || !in_array($uid, $valid_ids, true)) {
        flash_set('error', 'Please choose a valid staff member.');
    } elseif ($out_t !== null && $in_t !== null && att_time_to_minutes($out_t) < att_time_to_minutes($in_t)) {
        flash_set('error', 'Out time cannot be earlier than in time.');
    } else {
        $exists = $db->prepare('SELECT id FROM att_records WHERE user_id = ? AND work_date = ?');
        $exists->execute([$uid, $wdate]);
        $rec_id = (int)$exists->fetchColumn();

        $stmt = $db->prepare(
            'INSERT INTO att_records (user_id, work_date, in_time, out_time, remarks, created_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE in_time = VALUES(in_time), out_time = VALUES(out_time),
                                     remarks = VALUES(remarks)'
        );
        $stmt->execute([$uid, $wdate, $in_t, $out_t, $remarks !== '' ? $remarks : null, (int)$me['id']]);

        log_change(
            'staff-attendance',
            $rec_id ? 'UPDATE' : 'CREATE',
            $rec_id ?: (int)$db->lastInsertId(),
            'Attendance ' . $wdate,
            null, null,
            'in=' . ($in_t ?? '—') . ', out=' . ($out_t ?? '—')
        );
        flash_set('success', 'Attendance saved for ' . date('d M Y', strtotime($wdate)) . '.');
        redirect(APP_URL . '/staff-attendance/index.php?report=daily&date=' . urlencode($wdate));
    }
    // Fall through on error, preserving selection.
    $sel_user = $uid;
    $sel_date = $wdate;
}

// ── Existing record for the selected staff/date (edit prefill) ───────────────
$record = null;
if ($sel_user > 0) {
    $rs = $db->prepare('SELECT * FROM att_records WHERE user_id = ? AND work_date = ?');
    $rs->execute([$sel_user, $sel_date]);
    $record = $rs->fetch() ?: null;
}

$staff = att_staff_list();
$sched = $sel_user > 0 ? att_effective_schedule($sel_user) : att_global_schedule();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Record Attendance</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-clock me-2 text-primary"></i>Daily Attendance</h6>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-md-7">
                        <label class="form-label fw-semibold small mb-1">Employee</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">Select staff…</option>
                            <?php foreach ($staff as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= $sel_user === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= h($s['full_name']) ?><?= $s['employee_id'] ? ' (' . h($s['employee_id']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small mb-1">Date</label>
                        <input type="date" name="work_date" class="form-control" value="<?= h($sel_date) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">In Time</label>
                        <input type="time" name="in_time" class="form-control" value="<?= h(att_normalize_time($record['in_time'] ?? '') ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small mb-1">Out Time</label>
                        <input type="time" name="out_time" class="form-control" value="<?= h(att_normalize_time($record['out_time'] ?? '') ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="remarks" class="form-control" maxlength="255" value="<?= h($record['remarks'] ?? '') ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Attendance</button>
                        <a href="<?= APP_URL ?>/staff-attendance/index.php?report=daily&date=<?= urlencode($sel_date) ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-clock me-2 text-muted"></i>Applicable Schedule</h6>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-7 text-muted">Office Start</dt><dd class="col-5"><?= h($sched['start_time']) ?></dd>
                    <dt class="col-7 text-muted">Office Close</dt><dd class="col-5"><?= h($sched['close_time']) ?></dd>
                    <dt class="col-7 text-muted">In-time Buffer</dt><dd class="col-5"><?= (int)$sched['in_buffer_minutes'] ?> min</dd>
                    <dt class="col-7 text-muted">Out-time Buffer</dt><dd class="col-5"><?= (int)$sched['out_buffer_minutes'] ?> min</dd>
                    <?php if (!empty($sched['custom'])): ?>
                    <dd class="col-12 mt-2"><span class="badge bg-info text-dark">Individual override applies</span></dd>
                    <?php endif; ?>
                </dl>
                <p class="text-muted small mt-3 mb-0">
                    An in-time later than start + buffer is counted as <em>Late In</em>;
                    an out-time earlier than close − buffer is counted as <em>Early Out</em>.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
