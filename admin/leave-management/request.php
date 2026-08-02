<?php
/**
 * Submit a new leave request (staff).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
// Module access OR self-service (Administrative / Faculty employee types).
if (!lm_can_request()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$page_title = 'Request Leave';
$user       = auth_user();
$errors     = [];
$year       = (int)date('Y');
$balance    = lm_get_balance((int)$user['id'], $year);
$flow_group = lm_flow_group_for_user($user);
$flow       = lm_active_flow_for_group($flow_group);
$is_faculty = lm_is_faculty((int)$user['id']);

// Preserve input on validation error
$in = [
    'category'   => $_POST['category']   ?? 'casual',
    'pay_type'   => $_POST['pay_type']   ?? 'paid',
    'start_date' => $_POST['start_date'] ?? '',
    'end_date'   => $_POST['end_date']   ?? '',
    'start_time' => $_POST['start_time'] ?? '',
    'end_time'   => $_POST['end_time']   ?? '',
    'reason'     => $_POST['reason']     ?? '',
    'makeup_plan' => $_POST['makeup_plan'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $category = in_array($in['category'], LM_CATEGORIES, true) ? $in['category'] : '';
    $start    = $in['start_date'];
    $end      = $in['end_date'];
    $reason   = trim($in['reason']);
    $makeup   = $is_faculty ? trim($in['makeup_plan']) : '';
    $pay_type = null;
    $start_t  = null;
    $end_t    = null;

    if ($category === '')      $errors[] = 'Please choose a valid leave category.';
    if ($start === '')         $errors[] = 'Start date is required.';
    // Short leave is a single-day leave: the end date is always the start date.
    if ($end === '' && $category !== 'short') $errors[] = 'End date is required.';
    if ($reason === '')        $errors[] = 'Please provide a reason for the leave.';

    // Paid/unpaid: fixed for some categories, chosen by the requester for others.
    $fixed_pay = lm_fixed_pay($category);
    if ($fixed_pay !== null) {
        $pay_type = $fixed_pay;
    } elseif (lm_category_needs_paytype($category)) {
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

    // Entitlement cap for fixed-day categories (Maternity 120, Paternity 7).
    if (empty($errors)) {
        $max = lm_category_max_days($category);
        if ($max !== null && $days > $max) {
            $errors[] = sprintf(
                '%s cannot exceed %s day(s); you requested %s.',
                lm_category_label($category),
                rtrim(rtrim(number_format($max, 1), '0'), '.'),
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
                    (user_id, category, pay_type, start_date, end_date, start_time, end_time, days, reason, makeup_plan, status, current_step)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $user['id'], $category, $pay_type, $start, $end, $start_t, $end_t, $days, $reason, $makeup !== '' ? $makeup : null, 'pending', 1,
            ]);
            $rid = (int)$db->lastInsertId();
            lm_snapshot_flow_for_request($rid, $flow_group);
            $db->commit();

            log_change('leave-management', 'CREATE', $rid, lm_category_label($category) . ' (' . $days . 'd)');
            // In-app + email confirmation to the requester; alert the first approving group.
            lm_notify_submitted($rid);
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
    No approval flow has been configured for your user group yet. You can still submit a request, but it will remain pending until an administrator sets up the approval steps for your group.
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
                            Casual &amp; Sick leave consume your yearly balance. Additional leave is marked Paid/Unpaid.
                            Short, Duty, Maternity &amp; Paternity leave are paid; Study &amp; Extra Ordinary leave are unpaid. Maternity is capped at <?= (int)LM_MATERNITY_DAYS ?> day(s) and Paternity at <?= (int)LM_PATERNITY_DAYS ?> day(s).
                        </div>
                    </div>

                    <div class="alert alert-secondary py-2 px-3" id="catNote" style="display:none;"></div>

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

                    <?php if ($is_faculty): ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Makeup Class Schedule Plan <span class="text-muted">(optional)</span></label>
                        <textarea name="makeup_plan" class="form-control" rows="3"
                                  placeholder="e.g. CSE-101 (Sec A) class of 12 Aug will be made up on 16 Aug, 10:00 AM, Room 402"><?= h($in['makeup_plan']) ?></textarea>
                        <div class="form-text">Describe how and when you plan to make up the classes affected by this leave. This helps approvers process your request faster.</div>
                    </div>
                    <?php endif; ?>

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
            <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-1">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-route me-2 text-muted"></i>Approval Steps</h6>
                <span class="badge bg-light text-dark border" title="Your most senior group with a configured approval flow"><?= h(lm_group_name($flow_group)) ?> flow</span>
            </div>
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
var LM_NOTES = {
    short:     'Short leave is always <strong>paid</strong>. Choose the date and the start/end time.',
    duty:      'Duty leave is always <strong>paid</strong>.',
    extraordinary: 'Extra Ordinary leave is always <strong>unpaid</strong>.',
    study:     'Study leave is always <strong>unpaid</strong>.',
    maternity: 'Maternity leave is <strong>paid</strong> with an entitlement of up to <strong><?= (int)LM_MATERNITY_DAYS ?></strong> day(s).',
    paternity: 'Paternity leave is <strong>paid</strong> with an entitlement of up to <strong><?= (int)LM_PATERNITY_DAYS ?></strong> day(s).'
};
function lmToggle() {
    var cat   = document.getElementById('category').value;
    var isPay = (cat === 'additional');
    var isShort = (cat === 'short');
    document.getElementById('payTypeWrap').style.display = isPay ? '' : 'none';
    document.getElementById('timeWrap').style.display    = isShort ? '' : 'none';
    document.getElementById('endWrap').style.display      = isShort ? 'none' : '';
    document.getElementById('startLabel').textContent     = isShort ? 'Date' : 'Start Date';
    document.getElementById('end_date').required          = !isShort;
    var note = document.getElementById('catNote');
    if (LM_NOTES[cat]) { note.innerHTML = LM_NOTES[cat]; note.style.display = ''; }
    else { note.style.display = 'none'; }
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
