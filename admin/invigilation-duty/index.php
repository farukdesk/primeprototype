<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('invigilation-duty');

$page_title = 'My Invigilation Duty';

$user        = auth_user();
$faculty_ids = ivd_faculty_ids_for_user((int)($user['id'] ?? 0));

// ── Handle swap / substitute request actions (POST, redirect-after-POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if (empty($faculty_ids)) {
        flash_set('error', 'Your account is not linked to a faculty record.');
    } elseif ($action === 'create') {
        [$ok, $msg] = ivd_create_swap_request(
            $faculty_ids,
            (int)($_POST['slot_id'] ?? 0),
            (string)($_POST['request_type'] ?? 'substitute'),
            (int)($_POST['target_faculty_id'] ?? 0),
            trim((string)($_POST['message'] ?? ''))
        );
        flash_set($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'accept') {
        [$ok, $msg] = ivd_accept_request((int)($_POST['request_id'] ?? 0), $faculty_ids);
        flash_set($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reject') {
        [$ok, $msg] = ivd_reject_request((int)($_POST['request_id'] ?? 0), $faculty_ids);
        flash_set($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'cancel') {
        [$ok, $msg] = ivd_cancel_request((int)($_POST['request_id'] ?? 0), $faculty_ids);
        flash_set($ok ? 'success' : 'error', $msg);
    }
    header('Location: ' . APP_URL . '/invigilation-duty/index.php');
    exit;
}

$duties      = ivd_duties_for_faculty($faculty_ids);

$incoming_requests = ivd_incoming_requests($faculty_ids);
$outgoing_requests = ivd_outgoing_requests($faculty_ids);

$today    = date('Y-m-d');
$upcoming = [];
$past     = [];
foreach ($duties as $d) {
    if ($d['slot_date'] >= $today) $upcoming[] = $d;
    else                           $past[]     = $d;
}
// Most recent past duty first
$past = array_reverse($past);

$total_count    = count($duties);
$upcoming_count = count($upcoming);
$attended_count = count(array_filter($duties, static fn ($d) => $d['attended'] === 1));

// Eligible swap/substitute targets for each upcoming slot (for the request modal).
$slot_targets = [];
foreach ($upcoming as $d) {
    $slot_targets[(int)$d['id']] = ivd_eligible_targets_for_slot($d, $faculty_ids);
}

require_once __DIR__ . '/../includes/header.php';

function ivd_request_status_badge(string $status): string
{
    switch ($status) {
        case 'accepted':  return '<span class="badge bg-success">Accepted</span>';
        case 'rejected':  return '<span class="badge bg-danger">Declined</span>';
        case 'cancelled': return '<span class="badge bg-secondary">Cancelled</span>';
        default:          return '<span class="badge bg-warning text-dark">Pending</span>';
    }
}

function ivd_render_duty_rows(array $rows, bool $allow_request = false): void
{
    foreach ($rows as $d): ?>
    <tr>
        <td>
            <span class="fw-semibold"><?= h(date('d M Y', strtotime($d['slot_date']))) ?></span>
            <div class="text-muted" style="font-size:.78rem;"><?= h(date('l', strtotime($d['slot_date']))) ?></div>
        </td>
        <td><?= h($d['time_slot']) ?></td>
        <td>
            <?= h($d['exam_name']) ?>
            <?php if (!empty($d['exam_year'])): ?>
                <span class="text-muted" style="font-size:.8rem;">(<?= h($d['exam_year']) ?>)</span>
            <?php endif; ?>
        </td>
        <td>
            <span class="badge bg-light text-dark border">Room <?= h($d['room_number']) ?></span>
            <?php if (!empty($d['dept_name'])): ?>
                <div class="text-muted mt-1" style="font-size:.78rem;"><?= h($d['dept_name']) ?></div>
            <?php endif; ?>
        </td>
        <td><span class="badge <?= $d['my_position'] === 1 ? 'bg-primary' : 'bg-info text-dark' ?>">Invigilator <?= (int)$d['my_position'] ?></span></td>
        <td><?= $d['co_name'] !== null && $d['co_name'] !== '' ? h($d['co_name']) : '<span class="text-muted">—</span>' ?></td>
        <td>
            <?php if ($d['attended'] === 1): ?>
                <span class="badge bg-success">Attended</span>
            <?php elseif ($d['attended'] === 0): ?>
                <span class="badge bg-danger">Absent</span>
            <?php else: ?>
                <span class="badge bg-secondary">Pending</span>
            <?php endif; ?>
        </td>
        <?php if ($allow_request): ?>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="#ivdRequestModal"
                    data-slot-id="<?= (int)$d['id'] ?>"
                    data-slot-label="<?= h(date('d M Y', strtotime($d['slot_date'])) . ' · ' . $d['time_slot'] . ' · Room ' . $d['room_number']) ?>">
                <i class="fas fa-exchange-alt me-1"></i>Swap / Substitute
            </button>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">My Invigilation Duty</li>
        </ol>
    </nav>
</div>

<?php if (empty($faculty_ids)): ?>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-user-clock text-muted mb-3" style="font-size:2.5rem;"></i>
        <h5 class="fw-semibold mb-2">No invigilation profile found</h5>
        <p class="text-muted mb-0" style="max-width:520px;margin:0 auto;">
            Your account is not yet linked to a faculty record in the Exam Invigilation pool.
            Please contact the Controller of Examinations office to link your account.
        </p>
    </div>
</div>

<?php else: ?>

<!-- ── Summary cards ── -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:46px;height:46px;background:#eef2ff;">
                    <i class="fas fa-clipboard-list" style="color:#4f46e5;"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:.8rem;">Total Duties</div>
                    <div class="fw-bold fs-5"><?= $total_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:46px;height:46px;background:#ecfdf5;">
                    <i class="fas fa-calendar-day" style="color:#059669;"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:.8rem;">Upcoming</div>
                    <div class="fw-bold fs-5"><?= $upcoming_count ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width:46px;height:46px;background:#fef9c3;">
                    <i class="fas fa-user-check" style="color:#ca8a04;"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:.8rem;">Attended</div>
                    <div class="fw-bold fs-5"><?= $attended_count ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Incoming swap / substitute requests ── -->
<?php if ($incoming_requests): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-inbox me-2 text-warning"></i>Requests Awaiting Your Response
            <span class="badge bg-warning text-dark ms-1"><?= count($incoming_requests) ?></span>
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>From</th>
                    <th>Type</th>
                    <th>Duty</th>
                    <th>Message</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($incoming_requests as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= h($r['requester_name']) ?></td>
                    <td><span class="badge <?= $r['request_type'] === 'swap' ? 'bg-primary' : 'bg-info text-dark' ?>"><?= $r['request_type'] === 'swap' ? 'Swap' : 'Substitute' ?></span></td>
                    <td>
                        <div class="fw-semibold"><?= h($r['exam_name']) ?></div>
                        <div class="text-muted" style="font-size:.8rem;">
                            <?= h(date('d M Y', strtotime((string)$r['slot_date'])) . ' · ' . $r['time_slot'] . ' · Room ' . $r['room_number']) ?>
                        </div>
                    </td>
                    <td><?= $r['message'] !== null && $r['message'] !== '' ? h($r['message']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Accept</button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1"></i>Decline</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Upcoming duties ── -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-day me-2 text-success"></i>Upcoming Duties</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Exam</th>
                    <th>Room</th>
                    <th>Role</th>
                    <th>Co-Invigilator</th>
                    <th>Attendance</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($upcoming): ivd_render_duty_rows($upcoming, true); else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No upcoming invigilation duty assigned.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── My swap / substitute requests ── -->
<?php if ($outgoing_requests): ?>
<div class="card mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-paper-plane me-2 text-primary"></i>My Requests</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>To</th>
                    <th>Type</th>
                    <th>Duty</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($outgoing_requests as $r): ?>
                <tr>
                    <td class="fw-semibold"><?= h($r['target_name']) ?></td>
                    <td><span class="badge <?= $r['request_type'] === 'swap' ? 'bg-primary' : 'bg-info text-dark' ?>"><?= $r['request_type'] === 'swap' ? 'Swap' : 'Substitute' ?></span></td>
                    <td>
                        <div class="fw-semibold"><?= h($r['exam_name']) ?></div>
                        <div class="text-muted" style="font-size:.8rem;">
                            <?= h(date('d M Y', strtotime((string)$r['slot_date'])) . ' · ' . $r['time_slot'] . ' · Room ' . $r['room_number']) ?>
                        </div>
                    </td>
                    <td><?= ivd_request_status_badge((string)$r['status']) ?></td>
                    <td class="text-end">
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-ban me-1"></i>Cancel</button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Past duties ── -->
<div class="card">
    <div class="card-header bg-white">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2 text-secondary"></i>Past Duties</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Exam</th>
                    <th>Room</th>
                    <th>Role</th>
                    <th>Co-Invigilator</th>
                    <th>Attendance</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($past): ivd_render_duty_rows($past); else: ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No past invigilation duty.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Request Swap / Substitute modal ── -->
<div class="modal fade" id="ivdRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="slot_id" id="ivdSlotId" value="">
                <div class="modal-header">
                    <h6 class="modal-title fw-semibold"><i class="fas fa-exchange-alt me-2 text-primary"></i>Request Swap / Substitute</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size:.82rem;">Your duty</label>
                        <div class="fw-semibold" id="ivdSlotLabel">—</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Request type</label>
                        <select name="request_type" class="form-select">
                            <option value="substitute">Substitute — someone covers for me</option>
                            <option value="swap">Swap — exchange this duty with a colleague</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Faculty to request</label>
                        <select name="target_faculty_id" id="ivdTargetSelect" class="form-select" required>
                            <option value="">Select a faculty member…</option>
                        </select>
                        <div class="form-text">Only faculty who are free at this duty's date and time are listed.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Message <span class="text-muted">(optional)</span></label>
                        <textarea name="message" class="form-control" rows="2" maxlength="500" placeholder="Add a short note for your colleague…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="ivdSubmitBtn"><i class="fas fa-paper-plane me-1"></i>Send Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var slotTargets = <?= json_encode($slot_targets, JSON_UNESCAPED_UNICODE) ?>;
    var modal = document.getElementById('ivdRequestModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        if (!btn) return;
        var slotId = btn.getAttribute('data-slot-id') || '';
        var label  = btn.getAttribute('data-slot-label') || '';
        document.getElementById('ivdSlotId').value = slotId;
        document.getElementById('ivdSlotLabel').textContent = label;

        var select = document.getElementById('ivdTargetSelect');
        var submit = document.getElementById('ivdSubmitBtn');
        select.innerHTML = '';
        var targets = slotTargets[slotId] || {};
        var keys = Object.keys(targets);
        if (keys.length === 0) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No available faculty for this slot';
            select.appendChild(opt);
            select.disabled = true;
            submit.disabled = true;
            return;
        }
        select.disabled = false;
        submit.disabled = false;
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select a faculty member…';
        select.appendChild(placeholder);
        keys.forEach(function (k) {
            var t = targets[k];
            var opt = document.createElement('option');
            opt.value = t.id;
            var txt = t.name;
            if (t.designation) txt += ' (' + t.designation + ')';
            if (t.dept_name) txt += ' — ' + t.dept_name;
            opt.textContent = txt;
            select.appendChild(opt);
        });
    });
})();
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
