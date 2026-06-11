<?php
/**
 * Admissions – Top Sheet (Enrollment Summary Report)
 *
 * Shows admission counts per program grouped by date range.
 * An "admission" is counted when status = 'admission_complete'.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('admissions');

$page_title = 'Top Sheet';

// ── Date filter ────────────────────────────────────────────────────────────────
$today      = date('Y-m-d');
$date_from  = trim($_GET['date_from'] ?? $today);
$date_to    = trim($_GET['date_to']   ?? $today);

// Normalise / validate
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = $today; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = $today; }
if ($date_to < $date_from) { $date_to = $date_from; }

$is_single_day = ($date_from === $date_to);

// ── Labels from settings ───────────────────────────────────────────────────────
$semester_label   = adm_get_setting('top_sheet_semester_label',  'Summer Semester 2026');
$admission_label  = adm_get_setting('top_sheet_admission_label', 'Admission in Summer 2026');

// ── Load program label mappings ────────────────────────────────────────────────
$mappings = [];
try {
    $map_rows = db()->query(
        'SELECT program_id, short_label, full_name, sort_order, is_visible
         FROM admissions_top_sheet_programs
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
    foreach ($map_rows as $m) {
        $mappings[(int)$m['program_id']] = $m;
    }
} catch (\Throwable $e) {
    // Table may not exist yet – run admissions-top-sheet.sql migration
}

// ── Query: count per program ───────────────────────────────────────────────────
// prev_count  = completed before date_from
// range_count = completed in [date_from, date_to]
// total_count = completed through date_to (prev + range)

try {
    $rows = db()->prepare(
        "SELECT
             a.program_id,
             p.program_name,
             SUM(CASE WHEN DATE(a.updated_at) < ?  THEN 1 ELSE 0 END) AS prev_count,
             SUM(CASE WHEN DATE(a.updated_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS range_count,
             SUM(CASE WHEN DATE(a.updated_at) <= ? THEN 1 ELSE 0 END)  AS total_count
         FROM admissions_applications a
         LEFT JOIN dept_academic_programs p ON p.id = a.program_id
         WHERE a.status = 'admission_complete'
           AND DATE(a.updated_at) <= ?
         GROUP BY a.program_id, p.program_name
         ORDER BY a.program_id ASC"
    );
    $rows->execute([$date_from, $date_from, $date_to, $date_to, $date_to]);
    $raw_rows = $rows->fetchAll();
} catch (\Throwable $e) {
    $raw_rows = [];
}

// ── Merge mappings: sort & label ───────────────────────────────────────────────
// Build a unified list: mapped programs first (in sort_order), then unmapped.
$data_by_program = [];
foreach ($raw_rows as $r) {
    $pid = (int)$r['program_id'];
    $data_by_program[$pid] = $r;
}

$report_rows = [];

// 1. Mapped programs (in user-defined order, even if count = 0)
foreach ($map_rows ?? [] as $m) {
    if (!$m['is_visible']) { continue; }
    $pid = (int)$m['program_id'];
    $r   = $data_by_program[$pid] ?? null;
    $report_rows[] = [
        'program_id'   => $pid,
        'short_label'  => $m['short_label'],
        'full_name'    => $m['full_name'] ?? '',
        'sort_order'   => (int)$m['sort_order'],
        'prev_count'   => $r ? (int)$r['prev_count']  : 0,
        'range_count'  => $r ? (int)$r['range_count'] : 0,
        'total_count'  => $r ? (int)$r['total_count'] : 0,
        'mapped'       => true,
    ];
    unset($data_by_program[$pid]); // mark as consumed
}

// 2. Unmapped programs that still have counts
foreach ($data_by_program as $pid => $r) {
    if ((int)$r['total_count'] === 0) { continue; }
    $report_rows[] = [
        'program_id'  => $pid,
        'short_label' => $r['program_name'] ?: '(Program #' . $pid . ')',
        'full_name'   => '',
        'sort_order'  => 9999,
        'prev_count'  => (int)$r['prev_count'],
        'range_count' => (int)$r['range_count'],
        'total_count' => (int)$r['total_count'],
        'mapped'      => false,
    ];
}

// Sort unmapped rows to the bottom (mapped rows keep their original sort_order)
usort($report_rows, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

// ── Grand totals ───────────────────────────────────────────────────────────────
$grand_prev  = array_sum(array_column($report_rows, 'prev_count'));
$grand_range = array_sum(array_column($report_rows, 'range_count'));
$grand_total = array_sum(array_column($report_rows, 'total_count'));

// ── Legend: only mapped programs with a full_name ──────────────────────────────
$legend = array_filter(
    $report_rows,
    fn($r) => $r['mapped'] && $r['full_name'] !== ''
);

// ── Print URL ─────────────────────────────────────────────────────────────────
$print_qs = http_build_query(['date_from' => $date_from, 'date_to' => $date_to]);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-table me-2 text-primary"></i>Admission Top Sheet
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admissions/index.php">Admissions</a></li>
            <li class="breadcrumb-item active">Top Sheet</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin() || adm_can_edit()): ?>
        <a href="<?= APP_URL ?>/admissions/top-sheet-settings.php"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i> Configure
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admissions/top-sheet-print.php?<?= $print_qs ?>"
           target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-print me-1"></i> Print / Download PDF
        </a>
    </div>
</div>

<?php flash_show(); ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= h($date_from) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= h($date_to) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
                <a href="?" class="btn btn-outline-secondary btn-sm ms-1">Today</a>
            </div>
            <!-- Quick shortcuts -->
            <div class="col-12 d-flex gap-2 flex-wrap mt-1">
                <?php
                $shortcuts = [
                    'Today'         => [$today, $today],
                    'Yesterday'     => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                    'This Week'     => [date('Y-m-d', strtotime('monday this week')), $today],
                    'This Month'    => [date('Y-m-01'), $today],
                ];
                foreach ($shortcuts as $label => [$f, $t]):
                    $active = ($date_from === $f && $date_to === $t);
                ?>
                <a href="?date_from=<?= $f ?>&date_to=<?= $t ?>"
                   class="btn btn-<?= $active ? 'primary' : 'outline-secondary' ?> btn-sm py-0 px-2" style="font-size:.75rem">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
</div>

<!-- Report Info Banner -->
<div class="alert alert-light border mb-3 py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <div>
        <span class="fw-semibold text-primary"><?= h($semester_label) ?></span>
        <span class="text-muted ms-2">·</span>
        <span class="text-muted ms-2"><?= h($admission_label) ?></span>
    </div>
    <div class="ms-auto text-muted small">
        <?php if ($is_single_day): ?>
            Report Date: <strong><?= date('d M Y', strtotime($date_from)) ?></strong>
        <?php else: ?>
            Period: <strong><?= date('d M Y', strtotime($date_from)) ?></strong>
            &ndash; <strong><?= date('d M Y', strtotime($date_to)) ?></strong>
        <?php endif; ?>
    </div>
</div>

<!-- Enrollment Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-users me-2 text-primary"></i>Admission Statement of Enrollment
        </h6>
        <span class="badge bg-primary"><?= $grand_total ?> Total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0" style="font-size:.9rem;">
            <thead>
                <tr class="table-light">
                    <th rowspan="2" class="align-middle ps-4" style="width:40%">Program</th>
                    <th colspan="3" class="text-center fw-semibold text-primary border-start">
                        <?= h($admission_label) ?>
                    </th>
                </tr>
                <tr class="table-light">
                    <th class="text-center border-start" style="width:17%">
                        <?= $is_single_day ? 'Previous Day' : 'Before Period' ?>
                    </th>
                    <th class="text-center" style="width:17%">
                        <?= $is_single_day ? 'Today' : 'This Period' ?>
                    </th>
                    <th class="text-center" style="width:17%">Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($report_rows)): ?>
                <tr>
                    <td colspan="4" class="text-center py-4 text-muted">
                        No admissions found for the selected period.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($report_rows as $row): ?>
                <tr>
                    <td class="ps-4 fw-medium">
                        <?= h($row['short_label']) ?>
                        <?php if (!$row['mapped']): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:.65rem">unmapped</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center <?= $row['prev_count'] > 0 ? '' : 'text-muted' ?>">
                        <?= $row['prev_count'] > 0 ? $row['prev_count'] : '—' ?>
                    </td>
                    <td class="text-center <?= $row['range_count'] > 0 ? 'fw-semibold text-success' : 'text-muted' ?>">
                        <?= $row['range_count'] > 0 ? $row['range_count'] : '—' ?>
                    </td>
                    <td class="text-center fw-semibold">
                        <?= $row['total_count'] > 0 ? $row['total_count'] : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-dark fw-bold">
                    <td class="ps-4">Grand Total</td>
                    <td class="text-center"><?= $grand_prev  ?: '—' ?></td>
                    <td class="text-center"><?= $grand_range ?: '—' ?></td>
                    <td class="text-center"><?= $grand_total ?: '—' ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if (!empty($legend)): ?>
    <div class="card-footer bg-white py-3 px-4">
        <p class="small text-muted fw-semibold mb-2">Program Legend</p>
        <div class="row g-1">
        <?php foreach ($legend as $row): ?>
            <div class="col-12 col-md-6">
                <span class="small">
                    <strong class="text-dark"><?= h($row['short_label']) ?></strong>
                    <span class="text-muted ms-1">= <?= h($row['full_name']) ?></span>
                </span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
