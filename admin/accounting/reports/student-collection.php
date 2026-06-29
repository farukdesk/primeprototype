<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Student Collection Report';
$currency   = acc_currency();
$db         = db();

// ── Filters ───────────────────────────────────────────────────────────────────
$date_from  = trim($_GET['date_from'] ?? date('Y-m-01'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$fee_type   = trim($_GET['fee_type']  ?? '');
$pay_method = trim($_GET['payment_method'] ?? '');
$f_dept     = (int)($_GET['dept_id'] ?? 0);
$f_program  = trim($_GET['program'] ?? '');
$f_batch    = (int)($_GET['batch'] ?? 0); // University batch (student_batches.id)

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = ''; }
if ($date_from && $date_to && $date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

// ── Filter option lists ─────────────────────────────────────────────────────
$depts = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$programs_list = $db->query(
    'SELECT DISTINCT program_name FROM dept_academic_programs WHERE program_name IS NOT NULL AND program_name <> "" ORDER BY program_name'
)->fetchAll(PDO::FETCH_COLUMN);

$batches_list = $db->query(
    'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['v.status = \'posted\'', 'v.is_deleted = 0'];
$params = [];

if ($date_from) { $where[] = 'DATE(v.voucher_date) >= ?'; $params[] = $date_from; }
if ($date_to)   { $where[] = 'DATE(v.voucher_date) <= ?'; $params[] = $date_to;   }
if ($fee_type)  { $where[] = 'p.fee_type = ?';            $params[] = $fee_type;  }
if ($pay_method){ $where[] = 'p.payment_method = ?';      $params[] = $pay_method;}
if ($f_dept)    { $where[] = 's.dept_id = ?';             $params[] = $f_dept;    }
if ($f_program !== '') { $where[] = 'ap.program_name = ?'; $params[] = $f_program; }
if ($f_batch)   { $where[] = 's.batch_id = ?';            $params[] = $f_batch;   }

$where_sql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT
         v.id                AS voucher_id,
         s.student_id        AS sid,
         s.full_name         AS student_name,
         d.name              AS dept_name,
         ap.program_name,
         COALESCE(ub.name, '')          AS batch_name,
         v.voucher_date      AS collection_date,
         v.voucher_number    AS invoice_no,
         p.fee_type,
         p.payment_method,
         p.mobile_banking_provider,
         p.amount
     FROM sfp_payments p
     JOIN students                s  ON s.id  = p.student_id
     JOIN acc_vouchers            v  ON v.id  = p.voucher_id
     LEFT JOIN dept_departments   d  ON d.id  = s.dept_id
     LEFT JOIN dept_academic_programs ap ON ap.id = s.program_id
     LEFT JOIN student_batches    ub ON ub.id = s.batch_id
     WHERE $where_sql
     ORDER BY v.voucher_date DESC, p.id DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Aggregations for charts / KPIs ──────────────────────────────────────────
$total          = 0.0;
$by_program     = [];
$by_dept        = [];
$by_batch       = [];
$by_fee_type    = [];
$by_pay_method  = [];
$unique_students = [];

foreach ($rows as $r) {
    $amt = (float)$r['amount'];
    $total += $amt;
    $unique_students[$r['sid']] = true;

    $prog  = $r['program_name'] ?: 'Unspecified';
    $dept  = $r['dept_name']    ?: 'Unspecified';
    $batch = $r['batch_name']   ?: 'Unspecified';
    $ft    = acc_fee_type_label($r['fee_type']);
    $pm    = acc_payment_method_label($r['payment_method']);

    $by_program[$prog]    = ($by_program[$prog]    ?? 0) + $amt;
    $by_dept[$dept]       = ($by_dept[$dept]       ?? 0) + $amt;
    $by_batch[$batch]     = ($by_batch[$batch]     ?? 0) + $amt;
    $by_fee_type[$ft]     = ($by_fee_type[$ft]     ?? 0) + $amt;
    $by_pay_method[$pm]   = ($by_pay_method[$pm]   ?? 0) + $amt;
}

arsort($by_program);
arsort($by_dept);
arsort($by_batch);
arsort($by_fee_type);
arsort($by_pay_method);

$txn_count    = count($rows);
$student_count = count($unique_students);
$avg_txn      = $txn_count ? $total / $txn_count : 0;

// Keep charts readable: top 8 programs / batches, rest grouped as "Others"
function sc_top_n(array $data, int $n = 8): array {
    if (count($data) <= $n) { return $data; }
    $top = array_slice($data, 0, $n, true);
    $others = array_sum(array_slice($data, $n, null, true));
    if ($others > 0) { $top['Others'] = $others; }
    return $top;
}
$chart_program = sc_top_n($by_program);
$chart_dept    = sc_top_n($by_dept);
$chart_batch   = sc_top_n($by_batch);

$fee_types   = acc_student_fee_types();
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking', 'old_erp' => 'Old ERP'];

$period_label = ($date_from ? date('d M Y', strtotime($date_from)) : 'All time')
              . ' — ' . ($date_to ? date('d M Y', strtotime($date_to)) : 'All time');

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2 no-print">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Student Collection Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/reports/index.php">Reports</a></li>
            <li class="breadcrumb-item active">Student Collection</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print A4</button>
    </div>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3 no-print">
    <div class="card-body p-3">
        <div class="mb-2 d-flex flex-wrap gap-1">
            <span class="small fw-semibold me-1 align-self-center text-muted">Quick:</span>
            <button type="button" class="btn btn-outline-primary btn-sm sc-btn" data-range="today">Today</button>
            <button type="button" class="btn btn-outline-primary btn-sm sc-btn" data-range="week">This Week</button>
            <button type="button" class="btn btn-outline-primary btn-sm sc-btn" data-range="month">This Month</button>
            <button type="button" class="btn btn-outline-primary btn-sm sc-btn" data-range="year">This Year</button>
        </div>
        <form method="get" id="filterForm" class="row g-2 align-items-end">
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="dept_id" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Program</label>
                <select name="program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($programs_list as $pname): ?>
                    <option value="<?= h($pname) ?>" <?= $f_program === $pname ? 'selected' : '' ?>><?= h($pname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="0">All Batches</option>
                    <?php foreach ($batches_list as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Fee Type</label>
                <select name="fee_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($fee_types as $ft): ?>
                    <option value="<?= h($ft) ?>" <?= $fee_type === $ft ? 'selected' : '' ?>><?= h(acc_fee_type_label($ft)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach ($pay_methods as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $pay_method === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Print header ── -->
<div class="d-none d-print-block mb-3">
    <table width="100%" style="border-bottom:2px solid #0d6efd;padding-bottom:10px;margin-bottom:10px">
        <tr>
            <td width="70"><img src="<?= h(acc_university_logo_url()) ?>" alt="Logo" style="height:56px;width:auto"></td>
            <td style="padding-left:12px">
                <div style="font-size:15pt;font-weight:700;color:#0d6efd">Prime University</div>
                <div style="font-size:8pt;color:#555"><?= h(acc_university_address()) ?></div>
            </td>
            <td align="right" style="vertical-align:top">
                <div style="font-size:13pt;font-weight:700;color:#0d6efd">Student Collection Report</div>
                <div style="font-size:8pt;color:#666">Period: <?= h($period_label) ?></div>
                <div style="font-size:7.5pt;color:#999;margin-top:4px">Printed: <?= date('d M Y, h:i A') ?></div>
            </td>
        </tr>
    </table>
</div>

<?php if (empty($rows)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
        <p class="mb-0">No collection records found for the selected filters.</p>
    </div>
</div>
<?php else: ?>

<!-- ── KPI cards ── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#0d6efd,#6ea8fe)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-coins"></i><span class="small fw-semibold opacity-90">Total Collected</span></div>
                <div class="fw-bold" style="font-size:1.25rem"><?= $currency ?> <?= number_format($total, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#16a34a,#4ade80)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-receipt"></i><span class="small fw-semibold opacity-90">Transactions</span></div>
                <div class="fw-bold" style="font-size:1.25rem"><?= number_format($txn_count) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#7c3aed,#a78bfa)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-user-graduate"></i><span class="small fw-semibold opacity-90">Students</span></div>
                <div class="fw-bold" style="font-size:1.25rem"><?= number_format($student_count) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#ea580c,#fb923c)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-chart-line"></i><span class="small fw-semibold opacity-90">Avg / Txn</span></div>
                <div class="fw-bold" style="font-size:1.25rem"><?= $currency ?> <?= number_format($avg_txn, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts ── -->
<div class="row g-3 mb-3 no-print">
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-graduation-cap me-1 text-primary"></i>Program-wise Collection</strong></div>
            <div class="card-body p-3"><canvas id="programChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-money-bill-wave me-1 text-success"></i>Payment Method</strong></div>
            <div class="card-body p-3"><canvas id="payChart" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-building me-1 text-info"></i>Department-wise Collection</strong></div>
            <div class="card-body p-3"><canvas id="deptChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-layer-group me-1 text-warning"></i>Batch-wise Collection</strong></div>
            <div class="card-body p-3"><canvas id="batchChart" height="220"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-tags me-1 text-danger"></i>Fee Type Breakdown</strong></div>
            <div class="card-body p-3"><canvas id="feeChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<!-- ── Table ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <strong class="small"><span id="recCount"><?= $txn_count ?></span> record(s) · <?= h($period_label) ?></strong>
        <div class="d-flex align-items-center gap-2 no-print">
            <input type="search" id="tableSearch" class="form-control form-control-sm" style="max-width:220px" placeholder="Search records…">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small" id="reportTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student Name</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Collection Date</th>
                        <th>Invoice No</th>
                        <th>Fee Type</th>
                        <th>Payment Method</th>
                        <th class="text-end">Amount (<?= h($currency) ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td class="text-muted idx"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['sid']) ?></td>
                        <td><?= h($r['student_name']) ?></td>
                        <td><?= h($r['program_name'] ?? '—') ?></td>
                        <td><?= h($r['batch_name'] !== '' ? $r['batch_name'] : '—') ?></td>
                        <td class="text-muted"><?= date('d M Y', strtotime($r['collection_date'])) ?></td>
                        <td><a href="<?= APP_URL ?>/accounting/fee-invoice.php?voucher_id=<?= (int)$r['voucher_id'] ?>" target="_blank" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none"><?= h($r['invoice_no']) ?></a></td>
                        <td><?= h(acc_fee_type_label($r['fee_type'])) ?></td>
                        <td><?= h(acc_payment_method_label($r['payment_method'], $r['mobile_banking_provider'])) ?></td>
                        <td class="text-end fw-semibold amt"><?= number_format((float)$r['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="9" class="text-end fw-bold">Filtered Total</td>
                        <td class="text-end fw-bold" id="footTotal"><?= $currency ?> <?= number_format($total, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2 no-print">
        <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0">Rows:</label>
            <select id="pageSize" class="form-select form-select-sm" style="width:auto">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="all">All</option>
            </select>
            <span class="small text-muted" id="pageInfo"></span>
        </div>
        <nav><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper, #content { margin-left: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
@page { size: A4 portrait; margin: 12mm; }
</style>

<?php if (!empty($rows)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    var palette = ['#0d6efd','#16a34a','#7c3aed','#ea580c','#dc2626','#0891b2','#ca8a04','#db2777','#475569','#65a30d'];

    function bar(id, labels, data, color) {
        var el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: color || 'rgba(13,110,253,.75)', borderRadius: 4 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }
    function pie(id, labels, data) {
        var el = document.getElementById(id);
        if (!el) return;
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: palette }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
        });
    }

    bar('programChart', <?= json_encode(array_keys($chart_program)) ?>, <?= json_encode(array_map('round', array_values($chart_program))) ?>);
    bar('deptChart',    <?= json_encode(array_keys($chart_dept)) ?>,    <?= json_encode(array_map('round', array_values($chart_dept))) ?>, 'rgba(8,145,178,.75)');
    bar('batchChart',   <?= json_encode(array_keys($chart_batch)) ?>,   <?= json_encode(array_map('round', array_values($chart_batch))) ?>, 'rgba(202,138,4,.8)');
    pie('feeChart',     <?= json_encode(array_keys($by_fee_type)) ?>,   <?= json_encode(array_map('round', array_values($by_fee_type))) ?>);
    pie('payChart',     <?= json_encode(array_keys($by_pay_method)) ?>, <?= json_encode(array_map('round', array_values($by_pay_method))) ?>);

    // ── Quick date buttons ──
    var today = new Date();
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    document.querySelectorAll('.sc-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = this.dataset.range, from, to = fmt(today);
            if (range === 'today') { from = to; }
            else if (range === "week") { var d = new Date(today); d.setDate(d.getDate() - ((d.getDay()+6)%7)); from = fmt(d); } // (getDay()+6)%7 = days since Monday
            else if (range === 'month') { from = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-01'; }
            else if (range === 'year') { from = today.getFullYear() + '-01-01'; }
            document.getElementById('date_from').value = from;
            document.getElementById('date_to').value = to;
            document.getElementById('filterForm').submit();
        });
    });

    // ── Client-side search + pagination ──
    var table   = document.getElementById('reportTable');
    var allRows = Array.prototype.slice.call(table.tBodies[0].rows);
    var search  = document.getElementById('tableSearch');
    var pageSel = document.getElementById('pageSize');
    var pager   = document.getElementById('pager');
    var pageInfo = document.getElementById('pageInfo');
    var recCount = document.getElementById('recCount');
    var footTotal = document.getElementById('footTotal');
    var currency = <?= json_encode($currency) ?>;
    var page = 1;

    function money(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function filtered() {
        var q = (search.value || '').toLowerCase().trim();
        if (!q) return allRows;
        return allRows.filter(function (tr) { return tr.textContent.toLowerCase().indexOf(q) !== -1; });
    }

    function render() {
        var rows = filtered();
        var size = pageSel.value === 'all' ? rows.length : parseInt(pageSel.value, 10);
        if (size < 1) size = rows.length || 1;
        var pages = Math.max(1, Math.ceil(rows.length / size));
        if (page > pages) page = pages;
        var start = (page - 1) * size;
        var end = start + size;

        var total = 0;
        allRows.forEach(function (tr) { tr.style.display = 'none'; });
        rows.forEach(function (tr, i) {
            total += parseFloat((tr.querySelector('.amt').textContent || '0').replace(/,/g, '')) || 0;
            if (i >= start && i < end) {
                tr.style.display = '';
                tr.querySelector('.idx').textContent = i + 1;
            }
        });

        recCount.textContent = rows.length;
        footTotal.textContent = currency + ' ' + money(total);
        pageInfo.textContent = rows.length ? ('Showing ' + (start + 1) + '–' + Math.min(end, rows.length) + ' of ' + rows.length) : 'No matching records';

        pager.innerHTML = '';
        if (pages > 1) {
            function pageItem(label, target, disabled, active) {
                var li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                var a = document.createElement('a');
                a.className = 'page-link'; a.href = '#'; a.textContent = label;
                a.addEventListener('click', function (e) { e.preventDefault(); if (!disabled && !active) { page = target; render(); } });
                li.appendChild(a); pager.appendChild(li);
            }
            pageItem('«', page - 1, page === 1, false);
            var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
            if (from > 1) pageItem('1', 1, false, page === 1);
            if (from > 2) pageItem('…', page, true, false);
            for (var p = from; p <= to; p++) pageItem(String(p), p, false, p === page);
            if (to < pages - 1) pageItem('…', page, true, false);
            if (to < pages) pageItem(String(pages), pages, false, page === pages);
            pageItem('»', page + 1, page === pages, false);
        }
    }

    search.addEventListener('input', function () { page = 1; render(); });
    pageSel.addEventListener('change', function () { page = 1; render(); });
    render();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
