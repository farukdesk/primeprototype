<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('invigilation-duty');

$page_title = 'My Invigilation Duty';

$user        = auth_user();
$faculty_ids = ivd_faculty_ids_for_user((int)($user['id'] ?? 0));
$duties      = ivd_duties_for_faculty($faculty_ids);

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

require_once __DIR__ . '/../includes/header.php';

function ivd_render_duty_rows(array $rows): void
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
                </tr>
            </thead>
            <tbody>
                <?php if ($upcoming): ivd_render_duty_rows($upcoming); else: ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No upcoming invigilation duty assigned.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

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

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
