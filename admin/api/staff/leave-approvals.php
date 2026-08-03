<?php
/**
 * Staff API – /api/staff/leave-approvals.php
 * ============================================
 * For employees with leave approval access (their user group appears in an
 * active leave approval flow).
 *
 * GET  : leave requests currently awaiting the caller's approval
 * POST : { id, action: approve|reject, note? } – record a decision.
 *        Mirrors admin/leave-management/action.php.
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

require_once dirname(__DIR__, 2) . '/leave-management/helpers.php';

$lm_user = [
    'id'        => $uid,
    'group_id'  => staff_user_group_id($uid),
    'group_ids' => lm_user_group_ids($uid),
];
$group_ids = array_map('intval', $lm_user['group_ids'] ?: [$lm_user['group_id']]);
$group_ids = array_values(array_filter(array_unique($group_ids)));

// Approver's uploaded signature (required for approving, same as the web UI).
$sig_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
$sig_stmt->execute([$uid]);
$sig_file = $sig_stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requests = [];
    if (!empty($group_ids)) {
        $ph   = implode(',', array_fill(0, count($group_ids), '?'));
        $stmt = db()->prepare(
            "SELECT r.*, a.label AS step_label, g.name AS step_group_name,
                    u.full_name AS requester_name,
                    sp.designation AS requester_designation,
                    sd.name AS requester_department
               FROM leave_requests r
               JOIN leave_request_approvals a
                 ON a.request_id = r.id AND a.step_order = r.current_step
               JOIN user_groups g ON g.id = a.group_id
               JOIN users u ON u.id = r.user_id
          LEFT JOIN staff_profiles sp ON sp.user_id = r.user_id
          LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
              WHERE r.status = 'pending' AND a.status = 'pending'
                AND a.group_id IN ($ph)
                AND r.user_id <> ?
              ORDER BY r.created_at ASC, r.id ASC
              LIMIT 100"
        );
        $stmt->execute(array_merge($group_ids, [$uid]));
        foreach ($stmt->fetchAll() as $r) {
            $requests[] = [
                'id'             => (int)$r['id'],
                'requester_name' => $r['requester_name'],
                'designation'    => $r['requester_designation'],
                'department'     => $r['requester_department'],
                'category'       => $r['category'],
                'category_label' => lm_category_label($r['category']),
                'pay_type'       => $r['pay_type'],
                'start_date'     => $r['start_date'],
                'end_date'       => $r['end_date'],
                'start_time'     => $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : null,
                'end_time'       => $r['end_time']   ? substr((string)$r['end_time'], 0, 5)   : null,
                'days'           => (float)$r['days'],
                'reason'         => $r['reason'],
                'step_label'     => $r['step_label'] ?: $r['step_group_name'],
                'created_at'     => $r['created_at'],
            ];
        }
    }
    api_ok([
        'has_signature' => (bool)$sig_file,
        'requests'      => $requests,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'Method Not Allowed. Use GET or POST.');
}

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = (string)($input['action'] ?? '');
$id     = (int)($input['id'] ?? 0);
$note   = trim((string)($input['note'] ?? ''));

if ($id < 1) api_error(400, 'Invalid request id.');
if (!in_array($action, ['approve', 'reject'], true)) {
    api_error(400, 'Action must be approve or reject.');
}

$stmt = db()->prepare('SELECT * FROM leave_requests WHERE id = ?');
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) api_error(404, 'Leave request not found.');
if ($req['status'] !== 'pending') api_error(400, 'This request is no longer pending.');
if ((int)$req['user_id'] === $uid) {
    api_error(400, 'You cannot approve or reject your own request.');
}
if (!lm_user_can_act($req, $lm_user)) {
    api_error(403, 'You are not authorized to act on this step.');
}
if ($action === 'approve' && !$sig_file) {
    api_error(
        400,
        'You must upload a signature image in the admin panel (My Signature) before approving.',
        ['signature_required' => true]
    );
}

$step = lm_current_step($id, (int)$req['current_step']);

$db = db();
$db->beginTransaction();
try {
    if ($step) {
        // Record the decision on the current step row.
        $db->prepare(
            'UPDATE leave_request_approvals
                SET status = ?, approver_id = ?, signature_file = ?, note = ?, acted_at = NOW()
              WHERE id = ?'
        )->execute([
            $action === 'approve' ? 'approved' : 'rejected',
            $uid,
            $action === 'approve' ? $sig_file : null,
            $note !== '' ? $note : null,
            $step['id'],
        ]);
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE leave_requests SET status = 'rejected' WHERE id = ?")->execute([$id]);
        $result = 'rejected';
    } else {
        // Is there a further step?
        $next = $db->prepare(
            'SELECT MIN(step_order) FROM leave_request_approvals WHERE request_id = ? AND step_order > ?'
        );
        $next->execute([$id, (int)$req['current_step']]);
        $next_step = $next->fetchColumn();

        if ($next_step) {
            $db->prepare('UPDATE leave_requests SET current_step = ? WHERE id = ?')
               ->execute([(int)$next_step, $id]);
            $result = 'advanced';
        } else {
            $db->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")->execute([$id]);
            $result = 'approved';
        }
    }
    $db->commit();
} catch (Throwable $ex) {
    if ($db->inTransaction()) $db->rollBack();
    api_error(500, 'Could not record your decision. Please try again.');
}

// Final approval: mark every day of the leave on the Staff Attendance calendar
// (mirrors admin/leave-management/action.php; skipped for same-day short leave).
if ($result === 'approved' && $req['category'] !== 'short') {
    try {
        $mark = $db->prepare(
            "INSERT INTO att_day_status (user_id, status_date, status, note, source, leave_request_id, created_by)
             VALUES (?,?,'approved_leave',?,'leave',?,?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source),
                                     leave_request_id = VALUES(leave_request_id)"
        );
        $note_txt = lm_category_label($req['category']) . ' (request #' . $id . ')';
        for ($d = strtotime($req['start_date']); $d !== false && $d <= strtotime($req['end_date']); $d = strtotime('+1 day', $d)) {
            $mark->execute([(int)$req['user_id'], date('Y-m-d', $d), $note_txt, $id, $uid]);
        }
    } catch (Throwable $ex) {
        // att_day_status table not installed – the calendar still shows the
        // day as On Leave via the approved leave request itself.
    }
}

try {
    if (function_exists('log_change')) {
        log_change('leave-management', 'UPDATE', $id, lm_category_label($req['category']) . ' via mobile app', 'approval', 'pending', $result);
    }
} catch (Throwable $e) {
}

// Notifications: next approval group, or final approved / rejected notice
// (in-app + email + FCM push) to the requester.
try {
    lm_notify_decision($id, $result, $note);
} catch (Throwable $e) {
}

api_ok([
    'result'  => $result,
    'message' => match ($result) {
        'rejected' => 'Leave request rejected.',
        'approved' => 'Final approval recorded — the leave request is now approved.',
        default    => 'Approved. The request has advanced to the next approval step.',
    },
]);
