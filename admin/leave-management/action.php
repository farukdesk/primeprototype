<?php
/**
 * POST handler for leave request actions: approve, reject, cancel.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
// Module access OR self-service (Administrative / Faculty employee types).
if (!lm_can_view()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/leave-management/index.php');
}
csrf_check();

$user   = auth_user();
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$note   = trim($_POST['note'] ?? '');

if ($id < 1) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/leave-management/index.php');
}

$stmt = db()->prepare('SELECT * FROM leave_requests WHERE id = ?');
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) {
    flash_set('error', 'Leave request not found.');
    redirect(APP_URL . '/leave-management/index.php');
}

$view_url = APP_URL . '/leave-management/view.php?id=' . $id;

// ── Cancel (requester only, while pending) ─────────────────────────────────────
if ($action === 'cancel') {
    if ((int)$req['user_id'] !== (int)$user['id'] && !lm_is_admin()) {
        flash_set('error', 'You can only cancel your own request.');
        redirect($view_url);
    }
    if ($req['status'] !== 'pending') {
        flash_set('error', 'Only pending requests can be cancelled.');
        redirect($view_url);
    }
    db()->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    log_change('leave-management', 'UPDATE', $id, lm_category_label($req['category']), 'status', $req['status'], 'cancelled');
    flash_set('success', 'Leave request cancelled.');
    redirect($view_url);
}

// ── Approve / Reject ───────────────────────────────────────────────────────────
if ($action === 'approve' || $action === 'reject') {
    if ($req['status'] !== 'pending') {
        flash_set('error', 'This request is no longer pending.');
        redirect($view_url);
    }
    if ((int)$req['user_id'] === (int)$user['id']) {
        flash_set('error', 'You cannot approve or reject your own request.');
        redirect($view_url);
    }

    $step     = lm_current_step($id, (int)$req['current_step']);
    $can_step = lm_user_can_act($req, $user);
    $is_admin = lm_is_admin();

    if (!$can_step && !$is_admin) {
        flash_set('error', 'You are not authorized to act on this step.');
        redirect($view_url);
    }

    // Approvers apply their uploaded signature.
    $sig_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
    $sig_stmt->execute([$user['id']]);
    $sig_file = $sig_stmt->fetchColumn() ?: null;
    if ($action === 'approve' && !$sig_file) {
        flash_set('error', 'You must upload a signature image before approving. Please visit My Signature first.');
        redirect(APP_URL . '/my-signature/index.php');
    }

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
                $user['id'],
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
        $db->rollBack();
        flash_set('error', 'Could not record your decision. Please try again.');
        redirect($view_url);
    }

    // Final approval: mark every day of the leave as "Approved Leave / Day Off"
    // on the Staff Attendance calendar (skipped for same-day short leave).
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
                $mark->execute([(int)$req['user_id'], date('Y-m-d', $d), $note_txt, $id, (int)$user['id']]);
            }
        } catch (Throwable $ex) {
            // att_day_status table not installed – the calendar still shows the
            // day as On Leave via the approved leave request itself.
        }
    }

    log_change('leave-management', 'UPDATE', $id, lm_category_label($req['category']), 'approval', 'pending', $result);

    // Notifications: alert the next approval group, or send the final
    // approved / rejected notice (in-app + email) to the requester.
    lm_notify_decision($id, $result, $note);

    flash_set('success', match ($result) {
        'rejected' => 'Leave request rejected.',
        'approved' => 'Final approval recorded — the leave request is now approved.',
        default    => 'Approved. The request has advanced to the next approval step.',
    });
    redirect($view_url);
}

flash_set('error', 'Unknown action.');
redirect($view_url);
