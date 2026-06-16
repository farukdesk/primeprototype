<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = h($exam['exam_name']) . ' – Remuneration Bill';
$print_mode = isset($_GET['print']) && $_GET['print'] === '1';

// ── Aggregate: per faculty, count attended slots, list rooms & time-slots ─────
$bill_st = db()->query(
    "SELECT
         f.id AS faculty_id,
         f.name AS faculty_name,
         f.designation,
         f.remuneration_per_slot AS rate,
         d.name AS dept_name,
         COUNT(a.id) AS attended_slots,
         (COUNT(a.id) * f.remuneration_per_slot) AS total_remuneration,
         GROUP_CONCAT(
             DISTINCT CONCAT(DATE_FORMAT(s.slot_date,'%d %b %Y'), ' / ', s.time_slot, ' / Rm ', s.room_number)
             ORDER BY s.slot_date ASC, s.time_slot ASC
             SEPARATOR '||'
         ) AS duty_details
     FROM ei_faculty f
     JOIN dept_departments d ON d.id = f.dept_id
     JOIN ei_slot_attendance a ON a.faculty_id = f.id AND a.exam_id = {$id} AND a.attended = 1
     JOIN ei_slots s ON s.id = a.slot_id
     WHERE f.is_active = 1
     GROUP BY f.id, f.name, f.designation, f.remuneration_per_slot, d.name
     ORDER BY d.name ASC, f.name ASC"
);
$bill_rows = $bill_st->fetchAll();

$grand_total = array_sum(array_column($bill_rows, 'total_remuneration'));
$grand_slots = array_sum(array_column($bill_rows, 'attended_slots'));

// Faculty with duty but no attendance marked yet (assigned but no record)
$untracked_st = db()->query(
    "SELECT DISTINCT f.id, f.name, f.designation, f.remuneration_per_slot AS rate, d.name AS dept_name
     FROM ei_slots s
     JOIN ei_faculty f ON f.id = s.faculty1_id OR f.id = s.faculty2_id
     JOIN dept_departments d ON d.id = f.dept_id
     WHERE s.exam_id = {$id}
       AND f.id NOT IN (SELECT faculty_id FROM ei_slot_attendance WHERE exam_id = {$id})
     ORDER BY d.name ASC, f.name ASC"
);
$untracked_rows = $untracked_st->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$print_mode): ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>"><?= h($exam['exam_name']) ?></a></li>
            <li class="breadcrumb-item active">Remuneration Bill</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-calendar-check me-1"></i> Mark Attendance
        </a>
        <a href="?id=<?= $id ?>&print=1" target="_blank"
           class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-print me-1"></i> Print
        </a>
    </div>
</div>
<?php flash_show(); ?>
<?php endif; ?>

<?php if ($print_mode): ?>
<style>
    @media print { .no-print { display:none!important; } }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; }
    .bill-header { text-align:center; margin-bottom:18px; }
    .bill-header h4 { margin:0 0 2px; font-size:1.2rem; }
    .bill-header p  { margin:0; color:#555; font-size:.9rem; }
</style>
<div class="bill-header">
    <h4><?= h($exam['exam_name']) ?> (<?= h($exam['exam_year']) ?>)</h4>
    <p>Exam Invigilation Remuneration Bill</p>
    <p>Printed: <?= date('d F Y, h:i A') ?></p>
</div>
<button class="btn btn-sm btn-outline-secondary no-print mb-3" onclick="window.print()">
    <i class="fas fa-print me-1"></i> Print
</button>
<?php else: ?>
<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #27ae60;">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= count($bill_rows) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Faculty with Attended Duty</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #4f8ef7;">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $grand_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Attended Slots</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center py-3" style="border-left:4px solid #f39c12;">
            <div style="font-size:1.8rem;font-weight:700;color:#f39c12;">৳<?= number_format($grand_total, 2) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Remuneration</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Bill Table -->
<div class="card <?= $print_mode ? 'border-0' : '' ?>">
    <?php if (!$print_mode): ?>
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-muted"></i>
            <?= h($exam['exam_name']) ?> — Invigilation Remuneration Bill
        </h6>
        <span class="text-muted" style="font-size:.82rem;"><?= h($exam['exam_year']) ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle" <?= $print_mode ? 'style="font-size:12px;"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="px-3 text-center" style="width:40px;">#</th>
                        <th>Faculty Name</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th class="text-end" style="width:100px;">Rate/Slot</th>
                        <th class="text-center" style="width:80px;">Slots</th>
                        <th class="text-end" style="width:130px;">Total (৳)</th>
                        <th>Attended Duty (Date / Time / Room)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bill_rows)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
                            No attendance marked yet.
                            <?php if (!$print_mode): ?>
                            <br><a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary mt-2" style="border-radius:8px;">
                                <i class="fas fa-calendar-check me-1"></i> Mark Attendance
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bill_rows as $i => $row): ?>
                    <tr>
                        <td class="px-3 text-center"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($row['faculty_name']) ?></td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary"><?= h($row['dept_name']) ?></span>
                        </td>
                        <td class="text-muted" style="font-size:.85rem;"><?= $row['designation'] ? h($row['designation']) : '—' ?></td>
                        <td class="text-end">
                            <?php if ($row['rate'] > 0): ?>
                            <span class="text-secondary">৳<?= number_format((float)$row['rate'], 2) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info bg-opacity-15 text-info fw-semibold"><?= (int)$row['attended_slots'] ?></span>
                        </td>
                        <td class="text-end">
                            <?php if ($row['total_remuneration'] > 0): ?>
                            <strong class="text-success">৳<?= number_format((float)$row['total_remuneration'], 2) ?></strong>
                            <?php else: ?>
                            <span class="text-muted">৳0.00</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['duty_details']): ?>
                            <?php $duties = explode('||', $row['duty_details']); ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($duties as $duty): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-dark border" style="font-size:.75rem;font-weight:400;border-radius:6px;">
                                    <?= h(trim($duty)) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($bill_rows)): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="5" class="text-end px-3">Grand Total:</td>
                        <td class="text-center"><?= $grand_slots ?></td>
                        <td class="text-end text-success">৳<?= number_format($grand_total, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if (!$print_mode && !empty($untracked_rows)): ?>
<div class="card mt-4 border-warning">
    <div class="card-header py-2 px-4 bg-warning bg-opacity-10 d-flex align-items-center gap-2">
        <i class="fas fa-exclamation-triangle text-warning"></i>
        <h6 class="mb-0 fw-semibold text-warning-emphasis">Assigned Faculty — Attendance Not Yet Marked</h6>
        <span class="badge bg-warning bg-opacity-20 text-warning ms-auto"><?= count($untracked_rows) ?> faculty</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3" style="width:40px;">#</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th class="text-end">Rate/Slot</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($untracked_rows as $ui => $ur): ?>
                <tr>
                    <td class="px-3"><?= $ui + 1 ?></td>
                    <td class="fw-medium"><?= h($ur['name']) ?></td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= h($ur['dept_name']) ?></span></td>
                    <td class="text-muted" style="font-size:.85rem;"><?= $ur['designation'] ? h($ur['designation']) : '—' ?></td>
                    <td class="text-end"><?= $ur['rate'] > 0 ? '৳' . number_format((float)$ur['rate'], 2) : '<span class="text-muted">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer py-2 px-4 text-end">
        <a href="<?= APP_URL ?>/exam-invigilation/attendance.php?id=<?= $id ?>"
           class="btn btn-sm btn-warning" style="border-radius:8px;">
            <i class="fas fa-calendar-check me-1"></i> Go to Attendance
        </a>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
