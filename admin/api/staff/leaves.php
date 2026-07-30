<?php
/**
 * Staff API – /api/staff/leaves.php
 * ===================================
 * GET  : leave balance + the employee's leave requests (with approval steps)
 * POST : submit a new leave request (same rules as the admin-panel form)
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

// Reuse the Leave Management module's helpers (categories, balances, flow).
require_once dirname(__DIR__, 2) . '/leave-management/helpers.php';

$lm_user = ['id' => $uid, 'group_id' => staff_user_group_id($uid)];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year    = (int)date('Y');
    $balance = lm_get_balance($uid, $year);

    $stmt = db()->prepare(
        'SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100'
    );
    $stmt->execute([$uid]);

    $requests = [];
    foreach ($stmt->fetchAll() as $r) {
        $approvals = [];
        foreach (lm_request_approvals((int)$r['id']) as $a) {
            $approvals[] = [
                'step_order'    => (int)$a['step_order'],
                'label'         => $a['label'] ?: $a['group_name'],
                'group_name'    => $a['group_name'],
                'status'        => $a['status'],
                'approver_name' => $a['approver_name'],
                'acted_at'      => $a['acted_at'],
                'note'          => $a['note'],
            ];
        }
        $requests[] = [
            'id'             => (int)$r['id'],
            'category'       => $r['category'],
            'category_label' => lm_category_label($r['category']),
            'pay_type'       => $r['pay_type'],
            'start_date'     => $r['start_date'],
            'end_date'       => $r['end_date'],
            'start_time'     => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : null,
            'end_time'       => $r['end_time']   ? substr((string)$r['end_time'], 0, 5)   : null,
            'days'           => (float)$r['days'],
            'reason'         => $r['reason'],
            'status'         => $r['status'],
            'current_step'   => (int)$r['current_step'],
            'created_at'     => $r['created_at'],
            'approvals'      => $approvals,
        ];
    }

    api_ok([
        'balance'    => $balance,
        'categories' => LM_CATEGORIES,
        'requests'   => $requests,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use GET or POST.');
}

// ── Submit a new leave request ───────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$category = (string)($input['category'] ?? '');
$start    = trim((string)($input['start_date'] ?? ''));
$end      = trim((string)($input['end_date'] ?? ''));
$reason   = trim((string)($input['reason'] ?? ''));
$start_t  = trim((string)($input['start_time'] ?? ''));
$end_t    = trim((string)($input['end_time'] ?? ''));
$pay_in   = (string)($input['pay_type'] ?? 'paid');

if (!in_array($category, LM_CATEGORIES, true)) api_error(400, 'Please choose a valid leave category.');
if ($start === '')  api_error(400, 'Start date is required.');
if ($reason === '') api_error(400, 'Please provide a reason for the leave.');

$pay_type = lm_fixed_pay($category);
if ($pay_type === null && lm_category_needs_paytype($category)) {
    if (!in_array($pay_in, ['paid', 'unpaid'], true)) api_error(400, 'Please mark the leave as Paid or Unpaid.');
    $pay_type = $pay_in;
}

if ($category === 'short') {
    $end = $start; // short leave is within a single day
    if ($start_t === '' || $end_t === '') api_error(400, 'Short leave requires a start and end time.');
    if ($end_t <= $start_t) api_error(400, 'Short-leave end time must be after the start time.');
} else {
    if ($end === '') api_error(400, 'End date is required.');
    $start_t = '';
    $end_t   = '';
}

$s = strtotime($start);
$e = strtotime($end);
if ($s === false || $e === false) api_error(400, 'Invalid date(s) supplied.');
if ($e < $s) api_error(400, 'End date cannot be before the start date.');

$days = lm_calc_days($category, $start, $end);

if (in_array($category, LM_BALANCE_CATEGORIES, true)) {
    $req_year = (int)date('Y', $s);
    $balance  = lm_get_balance($uid, $req_year);
    $used     = lm_used_days($uid, $req_year, $category, true);
    $total    = $category === 'casual' ? $balance['casual_total'] : $balance['sick_total'];
    if ($days > $total - $used) {
        api_error(400, sprintf(
            'Insufficient %s balance: %.1f day(s) remaining (including pending requests), but %.1f requested.',
            lm_category_label($category), $total - $used, $days
        ));
    }
}

$max = lm_category_max_days($category);
if ($max !== null && $days > $max) {
    api_error(400, sprintf(
        '%s cannot exceed %.0f day(s); you requested %.1f.',
        lm_category_label($category), $max, $days
    ));
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare(
        'INSERT INTO leave_requests
            (user_id, category, pay_type, start_date, end_date, start_time, end_time, days, reason, status, current_step)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $uid, $category, $pay_type, $start, $end,
        $start_t !== '' ? $start_t : null,
        $end_t   !== '' ? $end_t   : null,
        $days, $reason, 'pending', 1,
    ]);
    $rid = (int)$db->lastInsertId();
    lm_snapshot_flow_for_request($rid, lm_flow_group_for_user($lm_user));
    $db->commit();
} catch (Throwable $ex) {
    $db->rollBack();
    api_error(500, 'Could not submit the request. Please try again.');
}

// Best-effort notifications + audit trail (never block the API response).
try {
    if (function_exists('lm_notify_submitted')) lm_notify_submitted($rid);
} catch (Throwable $e) {
}
try {
    if (function_exists('log_change')) {
        log_change('leave-management', 'CREATE', $rid, lm_category_label($category) . ' (' . $days . 'd) via mobile app');
    }
} catch (Throwable $e) {
}

api_ok(['message' => 'Your leave request has been submitted.', 'request_id' => $rid]);
