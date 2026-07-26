<?php
/**
 * My Schedule (staff self-service).
 *   1. Weekend (weekly-off) change request: staff pick their preferred weekly
 *      off day(s); the request is routed through the approval chain configured
 *      for their user group (shared with Leave Management). On final approval
 *      the days are applied to their attendance schedule automatically.
 *   2. Thursday / Friday day slots: staff may split those days into slots
 *      (On Campus / Online Class); the combined slot window becomes their
 *      expected clock-in/out time for that day.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
if (!att_can_view() && !att_self_service_allowed()) {
    $_SESSION['flash_error'] = 'You do not have permission to access this section.';
    redirect(APP_URL . '/index.php');
}

$page_title = 'My Schedule';
$user       = auth_user();
$user_id    = (int)$user['id'];
$db         = db();
$day_names  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ── POST: submit / cancel weekend request, save Thu/Fri slots ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'request_weekend') {
        $sel = [];
        foreach ((array)($_POST['weekly_off_days'] ?? []) as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 7) $sel[] = $d;
        }
        $sel = array_values(array_unique($sel));
        sort($sel);
        $reason = trim($_POST['reason'] ?? '');
        $flow   = att_weekend_flow_for_user($user);

        if (empty($sel)) {
            flash_set('error', 'Please choose at least one weekend day.');
        } elseif (count($sel) > 2) {
            flash_set('error', 'You can choose at most two weekend days.');
        } elseif (empty($flow)) {
            flash_set('error', 'No approval chain is configured for your user group yet. Please contact the Registrar office / administrator.');
        } else {
            $chk = $db->prepare("SELECT COUNT(*) FROM att_weekend_requests WHERE user_id = ? AND status = 'pending'");
            $chk->execute([$user_id]);
            if ((int)$chk->fetchColumn() > 0) {
                flash_set('error', 'You already have a pending weekend request. Cancel it before submitting a new one.');
            } else {
                $db->prepare('INSERT INTO att_weekend_requests (user_id, weekly_off_days, reason) VALUES (?,?,?)')
                   ->execute([$user_id, implode(',', $sel), $reason !== '' ? $reason : null]);
                $rid = (int)$db->lastInsertId();
                att_weekend_snapshot_flow($rid, $flow);
                log_change('staff-attendance', 'CREATE', $rid, 'Weekend request', null, null, implode(',', $sel));
                flash_set('success', 'Weekend request submitted for approval.');
            }
        }
        redirect(APP_URL . '/staff-attendance/my-schedule.php');
    }

    if ($act === 'cancel_weekend') {
        $rid  = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE att_weekend_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$rid, $user_id]);
        if ($stmt->rowCount()) {
            flash_set('success', 'Weekend request cancelled.');
        } else {
            flash_set('error', 'Request not found or no longer pending.');
        }
        redirect(APP_URL . '/staff-attendance/my-schedule.php');
    }

    if ($act === 'save_slots') {
        $weekday = (int)($_POST['weekday'] ?? 0);
        if (!in_array($weekday, [4, 5], true)) {
            flash_set('error', 'Slots can only be customised for Thursday and Friday.');
            redirect(APP_URL . '/staff-attendance/my-schedule.php');
        }
        $locs   = (array)($_POST['location'] ?? []);
        $starts = (array)($_POST['slot_start'] ?? []);
        $ends   = (array)($_POST['slot_end'] ?? []);
        $slots  = [];
        $err    = '';
        foreach ($starts as $i => $st) {
            $loc = ($locs[$i] ?? '') === 'online' ? 'online' : 'campus';
            $st  = att_normalize_time($st);
            $en  = att_normalize_time($ends[$i] ?? '');
            if ($st === null && $en === null) continue; // blank row
            if ($st === null || $en === null || att_time_to_minutes($en) <= att_time_to_minutes($st)) {
                $err = 'Each slot needs a start time and a later end time.';
                break;
            }
            $slots[] = ['location' => $loc, 'start' => $st, 'end' => $en];
        }
        if ($err === '' && count($slots) > 1) {
            usort($slots, fn($a, $b) => strcmp($a['start'], $b['start']));
            for ($i = 1; $i < count($slots); $i++) {
                if ($slots[$i]['start'] < $slots[$i - 1]['end']) { $err = 'Slots must not overlap.'; break; }
            }
        }
        if ($err !== '') {
            flash_set('error', $err);
        } else {
            $db->prepare('DELETE FROM att_day_slots WHERE user_id = ? AND weekday = ?')->execute([$user_id, $weekday]);
            if (!empty($slots)) {
                $ins = $db->prepare('INSERT INTO att_day_slots (user_id, weekday, slot_no, location, start_time, end_time, is_active) VALUES (?,?,?,?,?,?,1)');
                foreach ($slots as $n => $s) {
                    $ins->execute([$user_id, $weekday, $n + 1, $s['location'], $s['start'], $s['end']]);
                }
            }
            log_change('staff-attendance', 'UPDATE', $user_id, $day_names[$weekday] . ' slots', null, null, count($slots) . ' slot(s)');
            flash_set('success', $day_names[$weekday] . ' slots saved. The combined slot window is now your expected clock in/out time for that day.');
        }
        redirect(APP_URL . '/staff-attendance/my-schedule.php');
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$sched    = att_effective_schedule($user_id);
$flow     = att_weekend_flow_for_user($user);
$requests = [];
try {
    $stmt = $db->prepare('SELECT * FROM att_weekend_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$user_id]);
    $requests = $stmt->fetchAll();
} catch (Throwable $e) {
    // Migration not applied yet.
}
$slots_by_day = att_user_slots($user_id);

require_once __DIR__ . '/../includes/header.php';

$wk_badge = static function (string $s): string {
    return match ($s) {
        'pending'   => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved'  => '<span class="badge bg-success">Approved</span>',
        'rejected'  => '<span class="badge bg-danger">Rejected</span>',
        'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
        default     => '<span class="badge bg-light text-dark">' . h($s) . '</span>',
    };
};
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/staff.php">My Attendance</a></li>
            <li class="breadcrumb-item active">My Schedule</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/staff-attendance/staff.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> My Attendance</a>
</div>

<?= flash_show() ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-umbrella-beach me-2 text-primary"></i>My Weekend (Weekly Off)</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Current weekend:
                    <strong><?= h(implode(', ', array_map(fn($n) => $day_names[$n], $sched['weekly_off_days'] ?? [])) ?: 'none') ?></strong>
                    <?php if (!empty($sched['weekly_off_custom'])): ?><span class="badge bg-info text-dark ms-1">Own</span><?php else: ?><span class="badge bg-light text-dark border ms-1">Global</span><?php endif; ?>
                </p>
                <form method="POST" class="border-top pt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_weekend">
                    <label class="form-label fw-semibold small">Request a new weekend (needs approval)</label>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php foreach ($day_names as $n => $nm): ?>
                        <label class="form-check form-check-inline m-0">
                            <input class="form-check-input" type="checkbox" name="weekly_off_days[]" value="<?= $n ?>"
                                   <?= in_array($n, $sched['weekly_off_days'] ?? [], true) ? 'checked' : '' ?>>
                            <span class="form-check-label small"><?= h($nm) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (optional)"></textarea>
                    <?php if (empty($flow)): ?>
                        <div class="alert alert-warning small py-2 mb-2">No approval chain is configured for your user group yet — please contact the Registrar office / administrator.</div>
                    <?php else: ?>
                        <div class="small text-muted mb-2">
                            Approval chain:
                            <?php foreach ($flow as $i => $f): ?>
                                <?= $i > 0 ? '<i class="fas fa-arrow-right mx-1"></i>' : '' ?>
                                <span class="badge bg-light text-dark border"><?= h($f['label'] ?: $f['group_name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm" <?= empty($flow) ? 'disabled' : '' ?>><i class="fas fa-paper-plane me-1"></i> Submit for Approval</button>
                </form>
            </div>
        </div>

        <div class="card mt-3" style="border-radius:12px;">
            <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-primary"></i>My Weekend Requests</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th class="px-3">Requested Weekend</th><th>Status</th><th>Progress</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php if (empty($requests)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No weekend requests yet.</td></tr>
                        <?php else: foreach ($requests as $r):
                            $steps = att_weekend_request_approvals((int)$r['id']);
                        ?>
                            <tr>
                                <td class="px-3">
                                    <strong><?= h(implode(', ', array_map(fn($n) => $day_names[$n] ?? $n, att_parse_off_days((string)$r['weekly_off_days'])))) ?></strong>
                                    <div class="text-muted small"><?= h(date('d M Y', strtotime($r['created_at']))) ?></div>
                                </td>
                                <td><?= $wk_badge((string)$r['status']) ?></td>
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
                                <td class="text-end pe-3">
                                    <?php if ($r['status'] === 'pending'): ?>
                                    <form method="POST" onsubmit="return confirm('Cancel this weekend request?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="cancel_weekend">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary">Cancel</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <?php foreach ([4, 5] as $wd): $rows = $slots_by_day[$wd] ?? []; ?>
        <div class="card mb-3" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-clock me-2 text-primary"></i><?= h($day_names[$wd]) ?> Slots (Campus / Online Class)</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">
                    Split your <?= h($day_names[$wd]) ?> into up to 3 slots (e.g. a slot On Campus and a slot for Online Class).
                    Your first slot start and last slot end become your expected clock in / clock out time for that day.
                    Leave every row blank to remove the customisation.
                </p>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_slots">
                    <input type="hidden" name="weekday" value="<?= $wd ?>">
                    <?php for ($i = 0; $i < 3; $i++): $s = $rows[$i] ?? null; ?>
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-4">
                            <select name="location[<?= $i ?>]" class="form-select form-select-sm">
                                <option value="campus" <?= ($s['location'] ?? '') === 'campus' ? 'selected' : '' ?>>On Campus</option>
                                <option value="online" <?= ($s['location'] ?? '') === 'online' ? 'selected' : '' ?>>Online Class</option>
                            </select>
                        </div>
                        <div class="col-4"><input type="time" name="slot_start[<?= $i ?>]" class="form-control form-control-sm" value="<?= h(att_normalize_time($s['start_time'] ?? '') ?? '') ?>"></div>
                        <div class="col-4"><input type="time" name="slot_end[<?= $i ?>]" class="form-control form-control-sm" value="<?= h(att_normalize_time($s['end_time'] ?? '') ?? '') ?>"></div>
                    </div>
                    <?php endfor; ?>
                    <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save <?= h($day_names[$wd]) ?> Slots</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
