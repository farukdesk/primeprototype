<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation');

$page_title = 'Remuneration Bills';
$print_mode = isset($_GET['print']) && $_GET['print'] === '1';

// ── All exams for the selector ───────────────────────────────────────────────
$exams = db()->query(
    'SELECT id, exam_name, exam_year, is_active FROM ei_exams ORDER BY exam_year DESC, exam_name ASC'
)->fetchAll();

// ── Selected exam IDs (single or multiple) ───────────────────────────────────
$selected_ids = [];
if (!empty($_GET['exam_ids']) && is_array($_GET['exam_ids'])) {
    foreach ($_GET['exam_ids'] as $eid) {
        $eid = (int)$eid;
        if ($eid > 0) {
            $selected_ids[$eid] = $eid;
        }
    }
}
$selected_ids = array_values($selected_ids);

$selected_exams = [];
$bill_rows      = [];
$exam_summary   = [];
$untracked_rows = [];
$dept_groups    = [];
$grand_total    = 0.0;
$grand_slots    = 0;
$qs_base        = '';

if ($selected_ids) {
    $ph      = implode(',', array_fill(0, count($selected_ids), '?'));
    $qs_base = http_build_query(['exam_ids' => $selected_ids]);

    $sel_st = db()->prepare(
        "SELECT id, exam_name, exam_year FROM ei_exams WHERE id IN ($ph)
         ORDER BY exam_year DESC, exam_name ASC"
    );
    $sel_st->execute($selected_ids);
    $selected_exams = $sel_st->fetchAll();

    // ── Aggregate per faculty across ALL selected exams ─────────────────────
    $bill_st = db()->prepare(
        "SELECT
             f.id AS faculty_id,
             f.name AS faculty_name,
             f.designation,
             f.remuneration_per_slot AS rate,
             d.name AS dept_name,
             SUM(x.cnt) AS attended_slots,
             (SUM(x.cnt) * f.remuneration_per_slot) AS total_remuneration,
             GROUP_CONCAT(
                 CONCAT(x.exam_name, ' (', x.exam_year, '): ', x.cnt)
                 ORDER BY x.exam_year DESC, x.exam_name ASC
                 SEPARATOR '||'
             ) AS exam_breakdown
         FROM (
             SELECT a.faculty_id, e.id AS exam_id, e.exam_name, e.exam_year, COUNT(a.id) AS cnt
             FROM ei_slot_attendance a
             JOIN ei_exams e ON e.id = a.exam_id
             WHERE a.attended = 1 AND a.exam_id IN ($ph)
             GROUP BY a.faculty_id, e.id, e.exam_name, e.exam_year
         ) x
         JOIN ei_faculty f ON f.id = x.faculty_id
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.is_active = 1
         GROUP BY f.id, f.name, f.designation, f.remuneration_per_slot, d.name
         ORDER BY d.name ASC, f.name ASC"
    );
    $bill_st->execute($selected_ids);
    $bill_rows = $bill_st->fetchAll();

    $grand_total = array_sum(array_column($bill_rows, 'total_remuneration'));
    $grand_slots = array_sum(array_column($bill_rows, 'attended_slots'));

    // ── Per-exam summary ─────────────────────────────────────────────────────
    $sum_st = db()->prepare(
        "SELECT e.id, e.exam_name, e.exam_year,
                COUNT(f.id) AS slots,
                COALESCE(SUM(f.remuneration_per_slot), 0) AS total
         FROM ei_exams e
         LEFT JOIN ei_slot_attendance a ON a.exam_id = e.id AND a.attended = 1
         LEFT JOIN ei_faculty f ON f.id = a.faculty_id AND f.is_active = 1
         WHERE e.id IN ($ph)
         GROUP BY e.id, e.exam_name, e.exam_year
         ORDER BY e.exam_year DESC, e.exam_name ASC"
    );
    $sum_st->execute($selected_ids);
    $exam_summary = $sum_st->fetchAll();

    // ── Faculty assigned in selected exams but attendance not yet marked ────
    $untracked_st = db()->prepare(
        "SELECT DISTINCT f.id, f.name, f.designation, f.remuneration_per_slot AS rate, d.name AS dept_name
         FROM ei_slots s
         JOIN ei_faculty f ON (f.id = s.faculty1_id OR f.id = s.faculty2_id)
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE s.exam_id IN ($ph)
           AND NOT EXISTS (
               SELECT 1 FROM ei_slot_attendance a
               WHERE a.exam_id = s.exam_id AND a.faculty_id = f.id
           )
         ORDER BY d.name ASC, f.name ASC"
    );
    $untracked_st->execute($selected_ids);
    $untracked_rows = $untracked_st->fetchAll();
}

$exam_label = implode(', ', array_map(
    static fn($e) => $e['exam_name'] . ' (' . $e['exam_year'] . ')',
    $selected_exams
));

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$print_mode): ?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item active">Remuneration Bills</li>
        </ol>
    </nav>
    <?php if ($selected_ids): ?>
    <a href="?<?= h($qs_base) ?>&print=1" target="_blank"
       class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-print me-1"></i> Print
    </a>
    <?php endif; ?>
</div>
<?php flash_show(); ?>

<!-- ── Exam selector ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-muted"></i>
            Generate Remuneration Bill
        </h6>
        <span class="text-muted" style="font-size:.82rem;">Select one or multiple exams</span>
    </div>
    <div class="card-body px-4">
        <?php if (empty($exams)): ?>
        <p class="text-muted mb-0">No exams found. <a href="<?= APP_URL ?>/exam-invigilation/create.php">Create an exam</a> first.</p>
        <?php else: ?>
        <form method="get">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="select-all-exams">
                    <label class="form-check-label fw-semibold" for="select-all-exams" style="font-size:.85rem;">Select All</label>
                </div>
                <span class="text-muted" style="font-size:.8rem;" id="selected-count"></span>
            </div>
            <div class="row g-2 mb-3">
                <?php foreach ($exams as $ex): ?>
                <div class="col-md-4 col-lg-3">
                    <label class="border rounded-3 d-flex align-items-start gap-2 p-2 w-100 h-100" style="cursor:pointer;font-size:.85rem;">
                        <input class="form-check-input exam-check mt-1" type="checkbox" name="exam_ids[]"
                               value="<?= (int)$ex['id'] ?>" <?= in_array((int)$ex['id'], $selected_ids, true) ? 'checked' : '' ?>>
                        <span>
                            <span class="fw-medium d-block"><?= h($ex['exam_name']) ?></span>
                            <span class="text-muted"><?= h($ex['exam_year']) ?></span>
                            <?php if (!(int)$ex['is_active']): ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary ms-1">Inactive</span>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:10px;">
                <i class="fas fa-calculator me-1"></i> Generate Bill
            </button>
        </form>
        <script>
        (function () {
            var all    = document.getElementById('select-all-exams');
            var checks = Array.prototype.slice.call(document.querySelectorAll('.exam-check'));
            var count  = document.getElementById('selected-count');
            function refresh() {
                var n = checks.filter(function (c) { return c.checked; }).length;
                count.textContent = n ? n + ' exam(s) selected' : '';
                all.checked = n === checks.length && n > 0;
            }
            all.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = all.checked; });
                refresh();
            });
            checks.forEach(function (c) { c.addEventListener('change', refresh); });
            refresh();
        })();
        </script>
        <?php endif; ?>
    </div>
</div>
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
    <h4>Exam Invigilation Remuneration Bill<?= count($selected_exams) > 1 ? ' (Combined)' : '' ?></h4>
    <p><?= h($exam_label) ?></p>
    <p>Printed: <?= date('d F Y, h:i A') ?></p>
</div>
<button class="btn btn-sm btn-outline-secondary no-print mb-3" onclick="window.print()">
    <i class="fas fa-print me-1"></i> Print
</button>
<?php endif; ?>

<?php if ($selected_ids): ?>

<?php if (!$print_mode): ?>
<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center py-3" style="border-left:4px solid #8e44ad;">
            <div style="font-size:1.8rem;font-weight:700;color:#8e44ad;"><?= count($selected_exams) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Exams Selected</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3" style="border-left:4px solid #27ae60;">
            <div style="font-size:1.8rem;font-weight:700;color:#27ae60;"><?= count($bill_rows) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Faculty with Attended Duty</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3" style="border-left:4px solid #4f8ef7;">
            <div style="font-size:1.8rem;font-weight:700;color:#4f8ef7;"><?= $grand_slots ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Attended Slots</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center py-3" style="border-left:4px solid #f39c12;">
            <div style="font-size:1.8rem;font-weight:700;color:#f39c12;">৳<?= number_format((float)$grand_total, 2) ?></div>
            <div class="text-muted" style="font-size:.8rem;">Total Remuneration</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (count($selected_exams) > 1): ?>
<!-- Per-exam summary -->
<div class="card mb-4 <?= $print_mode ? 'border-0' : '' ?>">
    <?php if (!$print_mode): ?>
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-layer-group me-2 text-muted"></i>Per-Exam Summary</h6>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle" <?= $print_mode ? 'style="font-size:12px;"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="px-3 text-center" style="width:40px;">#</th>
                        <th>Exam</th>
                        <th class="text-center" style="width:120px;">Year</th>
                        <th class="text-center" style="width:120px;">Attended Slots</th>
                        <th class="text-end" style="width:160px;">Amount (৳)</th>
                        <?php if (!$print_mode): ?><th class="text-center" style="width:120px;">Detail Bill</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($exam_summary as $si => $sr): ?>
                    <tr>
                        <td class="px-3 text-center"><?= $si + 1 ?></td>
                        <td class="fw-medium"><?= h($sr['exam_name']) ?></td>
                        <td class="text-center"><?= h($sr['exam_year']) ?></td>
                        <td class="text-center"><?= (int)$sr['slots'] ?></td>
                        <td class="text-end"><?= $sr['total'] > 0 ? '৳' . number_format((float)$sr['total'], 2) : '<span class="text-muted">৳0.00</span>' ?></td>
                        <?php if (!$print_mode): ?>
                        <td class="text-center">
                            <a href="<?= APP_URL ?>/exam-invigilation/remuneration-bill.php?id=<?= (int)$sr['id'] ?>"
                               class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.75rem;">
                                <i class="fas fa-file-invoice"></i> View
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="3" class="text-end px-3">Grand Total:</td>
                        <td class="text-center"><?= $grand_slots ?></td>
                        <td class="text-end text-success">৳<?= number_format((float)$grand_total, 2) ?></td>
                        <?php if (!$print_mode): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Combined bill table -->
<div class="card <?= $print_mode ? 'border-0' : '' ?>">
    <?php if (!$print_mode): ?>
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-muted"></i>
            Invigilation Remuneration Bill<?= count($selected_exams) > 1 ? ' (Combined)' : '' ?>
        </h6>
        <span class="text-muted" style="font-size:.82rem;"><?= h($exam_label) ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle" <?= $print_mode ? 'style="font-size:12px;"' : '' ?>>
                <thead class="table-light">
                    <tr>
                        <th class="px-3 text-center" style="width:40px;">#</th>
                        <th>Faculty Name</th>
                        <th>Designation</th>
                        <th class="text-end" style="width:100px;">Rate/Slot</th>
                        <th class="text-center" style="width:80px;">Slots</th>
                        <th class="text-end" style="width:130px;">Total (৳)</th>
                        <th>Per-Exam Breakdown (Exam: Slots)</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bill_rows)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted"></i>
                            No attendance marked yet for the selected exam(s).
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Safety net: rebuild department groups if not already built
                    if (empty($dept_groups)) {
                        $dept_groups = [];
                        foreach ($bill_rows as $row) {
                            $dept_groups[$row['dept_name']][] = $row;
                        }
                    }
                    ?>
                    <?php foreach ($dept_groups as $dept_name => $dept_rows): ?>
                    <?php
                        $dept_slots = array_sum(array_column($dept_rows, 'attended_slots'));
                        $dept_total = array_sum(array_column($dept_rows, 'total_remuneration'));
                    ?>
                    <tr style="background:#eef3fd;">
                        <td colspan="7" class="px-3 py-2 fw-semibold" style="font-size:.9rem;">
                            <i class="fas fa-building-columns me-1 text-primary"></i> <?= h($dept_name) ?>
                            <span class="text-muted fw-normal" style="font-size:.78rem;">— <?= count($dept_rows) ?> faculty</span>
                        </td>
                    </tr>
                    <?php foreach ($dept_rows as $i => $row): ?>
                    <tr>
                        <td class="px-3 text-center"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($row['faculty_name']) ?></td>
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
                            <?php if ($row['exam_breakdown']): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach (explode('||', $row['exam_breakdown']) as $piece): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-dark border" style="font-size:.75rem;font-weight:400;border-radius:6px;">
                                    <?= h(trim($piece)) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-semibold">
                        <td colspan="4" class="text-end px-3">Subtotal — <?= h($dept_name) ?>:</td>
                        <td class="text-center"><?= $dept_slots ?></td>
                        <td class="text-end text-success">৳<?= number_format((float)$dept_total, 2) ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($bill_rows)): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="4" class="text-end px-3">Grand Total (All Departments):</td>
                        <td class="text-center"><?= $grand_slots ?></td>
                        <td class="text-end text-success">৳<?= number_format((float)$grand_total, 2) ?></td>
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
</div>
<?php endif; ?>

<?php elseif (!$print_mode): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-hand-pointer fa-2x mb-2 d-block"></i>
        Select one or more exams above, then click <strong>Generate Bill</strong>.
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
