<?php
/**
 * Submit a new leave request (staff).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('leave-management', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Request Leave';
$user       = auth_user();
$errors     = [];
$year       = (int)date('Y');
$balance    = lm_get_balance((int)$user['id'], $year);
$flow       = lm_active_flow();

// Preserve input on validation error
$in = [
    'category'   => $_POST['category']   ?? 'casual',
    'pay_type'   => $_POST['pay_type']   ?? 'paid',
    'start_date' => $_POST['start_date'] ?? '',
    'end_date'   => $_POST['end_date']   ?? '',
    'start_time' => $_POST['start_time'] ?? '',
    'end_time'   => $_POST['end_time']   ?? '',
    'reason'     => $_POST['reason']     ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $category = in_array($in['category'], LM_CATEGORIES, true) ? $in['category'] : '';
    $start    = $in['start_date'];
    $end      = $in['end_date'];
    $reason   = trim($in['reason']);
    $pay_type = null;
    $start_t  = null;
    $end_t    = null;

    if ($category === '')      $errors[] = 'Please choose a valid leave category.';
    if ($start === '')         $errors[] = 'Start date is required.';
    if ($end === '')           $errors[] = 'End date is required.';
    if ($reason === '')        $errors[] = 'Please provide a reason for the leave.';

    // Additional / Short leave require a paid/unpaid selection
    if (in_array($category, LM_PAYTYPE_CATEGORIES, true)) {
        $pay_type = in_array($in['pay_type'], ['paid', 'unpaid'], true) ? $in['pay_type'] : '';
        if ($pay_type === '') $errors[] = 'Please mark the leave as Paid or Unpaid.';
    }

    // Short leave: single day, requires a time range
    if ($category === 'short') {
        $end = $start; // short leave is within one day
        if ($in['start_time'] === '' || $in['end_time'] === '') {
            $errors[] = 'Short leave requires a start and end time.';
        } elseif ($in['end_time'] <= $in['start_time']) {
            $errors[] = 'Short-leave end time must be after the start time.';
        } else {
            $start_t = $in['start_time'];
            $end_t   = $in['end_time'];
        }
    }

    // Date sanity
    if ($start !== '' && $end !== '') {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($s === false || $e === false) {
            $errors[] = 'Invalid date(s) supplied.';
        } elseif ($e < $s) {
            $errors[] = 'End date cannot be before the start date.';
        }
    }

    $days = ($category !== '' && $start !== '' && $end !== '') ? lm_calc_days($category, $start, $end) : 0.0;

    // Balance check for Casual / Sick
    if (empty($errors) && in_array($category, LM_BALANCE_CATEGORIES, true)) {
        $used_incl_pending = lm_used_days((int)$user['id'], (int)date('Y', strtotime($start)), $category, true);
        $total = $category === 'casual' ? $balance['casual_total'] : $balance['sick_total'];
        $remaining = $total - $used_incl_pending;
        if ($days > $remaining) {
            $errors[] = sprintf(
                'Insufficient %s balance: %s day(s) remaining (including pending requests), but %s requested.',
                lm_category_label($category),
                rtrim(rtrim(number_format($remaining, 1), '0'), '.'),
                rtrim(rtrim(number_format($days, 1), '0'), '.')
            );
        }
    }

    if (empty($errors)) {
        $db = db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO leave_requests
                    (user_id, category, pay_type, start_date, end_date, start_time, end_time, days, reason, status, current_step)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $user['id'], $category, $pay_type, $start, $end, $start_t, $end_t, $days, $reason, 'pending', 1,
            ]);
            $rid = (int)$db->lastInsertId();
            lm_snapshot_flow_for_request($rid);
            $db->commit();

            log_change('leave-management', 'CREATE', $rid, lm_category_label($category) . ' (' . $days . 'd)');
            flash_set('success', 'Your leave request has been submitted.');
            redirect(APP_URL . '/leave-management/view.php?id=' . $rid);
        } catch (Throwable $ex) {
            $db->rollBack();
            $errors[] = 'Could not submit the request. Please try again.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leave-management/index.php">Leave Management</a></li>
            <li class="breadcrumb-item active">Request Leave</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (empty($flow)): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-1"></i>
    No approval flow has been configured yet. You can still submit a request, but it will remain pending until an administrator sets up the approval steps.
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-plane-departure me-2 text-primary"></i>New Leave Request</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" novalidate id="leaveForm">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Leave Category</label>
                        <select name="category" id="category" class="form-select" required onchange="lmToggle()">
                            <?php foreach (LM_CATEGORIES as $c): ?>
                            <option value="<?= $c ?>" <?= $in['category'] === $c ? 'selected' : '' ?>><?= h(lm_category_label($c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Casual &amp; Sick leave consume your yearly balance. Additional &amp; Short leave are marked Paid/Unpaid and do not use a balance.
                        </div>
                    </div>

                    <div class="row g-3" id="payTypeWrap">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium">Pay Type</label>
                            <select name="pay_type" class="form-select">
                                <option value="paid"   <?= $in['pay_type'] === 'paid'   ? 'selected' : '' ?>>Paid</option>
                                <option value="unpaid" <?= $in['pay_type'] === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium" id="startLabel">Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="form-control"
                                   value="<?= h($in['start_date']) ?>" required onchange="lmDays()">
                        </div>
                        <div class="col-md-6 mb-3" id="endWrap">
                            <label class="form-label fw-medium">End Date</label>
                            <input type="date" name="end_date" id="end_date" class="form-control"
                                   value="<?= h($in['end_date']) ?>" onchange="lmDays()">
                        </div>
                    </div>

                    <div class="row g-3" id="timeWrap">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium">Start Time</label>
                            <input type="time" name="start_time" class="form-control" value="<?= h($in['start_time']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-medium">End Time</label>
                            <input type="time" name="end_time" class="form-control" value="<?= h($in['end_time']) ?>">
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3" id="daysInfo" style="display:none;">
                        <i class="fas fa-calendar-day me-1"></i> Duration: <strong id="daysCount">0</strong> day(s)
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Briefly describe the reason for your leave"><?= h($in['reason']) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-paper-plane me-1"></i> Submit Request
                        </button>
                        <a href="<?= APP_URL ?>/leave-management/index.php" class="btn btn-outline-secondary" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-wallet me-2 text-muted"></i>Your Balance (<?= $year ?>)</h6></div>
            <div class="card-body p-4">
                <div class="d-flex justify-content-between mb-2">
                    <span>Casual Leave</span>
                    <strong><?= rtrim(rtrim(number_format($balance['casual_remaining'], 1), '0'), '.') ?> / <?= rtrim(rtrim(number_format($balance['casual_total'], 1), '0'), '.') ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Sick Leave</span>
                    <strong><?= rtrim(rtrim(number_format($balance['sick_remaining'], 1), '0'), '.') ?> / <?= rtrim(rtrim(number_format($balance['sick_total'], 1), '0'), '.') ?></strong>
                </div>
                <p class="text-muted small mb-0 mt-2">Remaining / Total days.</p>
            </div>
        </div>

        <?php if (!empty($flow)): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-muted"></i>Approval Steps</h6></div>
            <div class="card-body p-4">
                <ol class="mb-0 ps-3 small">
                    <?php foreach ($flow as $f): ?>
                    <li class="mb-1"><?= h($f['label'] ?: $f['group_name']) ?> <span class="text-muted">(<?= h($f['group_name']) ?>)</span></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function lmToggle() {
    var cat   = document.getElementById('category').value;
    var isPay = (cat === 'additional' || cat === 'short');
    var isShort = (cat === 'short');
    document.getElementById('payTypeWrap').style.display = isPay ? '' : 'none';
    document.getElementById('timeWrap').style.display    = isShort ? '' : 'none';
    document.getElementById('endWrap').style.display      = isShort ? 'none' : '';
    document.getElementById('startLabel').textContent     = isShort ? 'Date' : 'Start Date';
    document.getElementById('end_date').required          = !isShort;
    lmDays();
}
function lmDays() {
    var cat = document.getElementById('category').value;
    var s = document.getElementById('start_date').value;
    var e = document.getElementById('end_date').value;
    var info = document.getElementById('daysInfo');
    if (cat === 'short') { info.style.display = 'none'; return; }
    if (!s || !e) { info.style.display = 'none'; return; }
    var sd = new Date(s), ed = new Date(e);
    if (ed < sd) { info.style.display = 'none'; return; }
    var days = Math.floor((ed - sd) / 86400000) + 1;
    document.getElementById('daysCount').textContent = days;
    info.style.display = '';
}
lmToggle();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
