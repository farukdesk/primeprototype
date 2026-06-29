<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';

$page_title = 'Staff Collection Report';
$currency   = acc_currency();

// Default: today's date for both from and to
$date_from  = trim($_GET['date_from'] ?? date('Y-m-d'));
$date_to    = trim($_GET['date_to']   ?? date('Y-m-d'));
$fee_type   = trim($_GET['fee_type']  ?? '');
$pay_method = trim($_GET['payment_method'] ?? '');
$staff_id   = (int)($_GET['staff_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) { $date_from = ''; }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   { $date_to   = ''; }

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['v.status = \'posted\'', 'v.is_deleted = 0'];
$params = [];

if ($date_from) { $where[] = 'DATE(v.voucher_date) >= ?'; $params[] = $date_from; }
if ($date_to)   { $where[] = 'DATE(v.voucher_date) <= ?'; $params[] = $date_to;   }
if ($fee_type)  { $where[] = 'p.fee_type = ?';            $params[] = $fee_type;  }
if ($pay_method){ $where[] = 'p.payment_method = ?';      $params[] = $pay_method;}
if ($staff_id)  { $where[] = 'p.collected_by = ?';        $params[] = $staff_id;  }

$where_sql = implode(' AND ', $where);

$rows = db()->prepare(
    "SELECT
         s.student_id                              AS sid,
         s.full_name                               AS student_name,
         COALESCE(ap.program_name, d.name, '—')   AS program,
         COALESCE(ub.name, s.admitted_semester, '—') AS batch,
         COALESCE(u.full_name, 'System')           AS collected_by,
         p.fee_type,
         p.payment_method,
         p.mobile_banking_provider,
         v.id                                      AS voucher_id,
         v.voucher_number                          AS invoice_no,
         p.amount,
         DATE(v.voucher_date)                      AS collection_date
     FROM sfp_payments p
     JOIN acc_vouchers                v  ON v.id  = p.voucher_id
     JOIN students                    s  ON s.id  = p.student_id
     LEFT JOIN users                  u  ON u.id  = p.collected_by
     LEFT JOIN dept_departments       d  ON d.id  = s.dept_id
     LEFT JOIN dept_academic_programs ap ON ap.id = s.program_id
     LEFT JOIN student_batches        ub ON ub.id = s.batch_id
     WHERE $where_sql
     ORDER BY v.voucher_date DESC, p.id DESC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$grand_total = array_sum(array_column($rows, 'amount'));

// ── Aggregations (stat cards + charts) ─────────────────────────────────────
$staff_totals  = [];
$by_pay_method = [];
$by_fee_type   = [];
foreach ($rows as $r) {
    $amt  = (float)$r['amount'];
    $name = $r['collected_by'];
    $staff_totals[$name]  = ($staff_totals[$name] ?? 0.0) + $amt;
    $pm   = acc_payment_method_label($r['payment_method']);
    $ft   = acc_fee_type_label($r['fee_type']);
    $by_pay_method[$pm]   = ($by_pay_method[$pm] ?? 0) + $amt;
    $by_fee_type[$ft]     = ($by_fee_type[$ft]   ?? 0) + $amt;
}
arsort($staff_totals);
arsort($by_pay_method);
arsort($by_fee_type);

// ── Staff list for filter dropdown ─────────────────────────────────────────
$staff_list = db()->query(
    "SELECT DISTINCT u.id, u.full_name
     FROM sfp_payments p
     JOIN users u ON u.id = p.collected_by
     ORDER BY u.full_name ASC"
)->fetchAll();

$fee_types   = acc_student_fee_types();
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking', 'old_erp' => 'Old ERP'];

// ── Period label for display ───────────────────────────────────────────────
$period_label = ($date_from === $date_to && $date_from === date('Y-m-d'))
    ? 'Today — ' . date('d M Y')
    : (($date_from ? date('d M Y', strtotime($date_from)) : 'All time')
       . ' — '
       . ($date_to ? date('d M Y', strtotime($date_to)) : 'All time'));

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page header ── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2 no-print">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-info"></i>Staff Collection Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/reports/index.php">Reports</a></li>
            <li class="breadcrumb-item active">Staff Collection</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-info btn-sm text-white shadow-sm">
        <i class="fas fa-print me-1"></i> Print A4
    </button>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-3 no-print">
    <div class="card-body p-3">
        <div class="mb-2 d-flex flex-wrap align-items-center gap-1">
            <span class="small fw-semibold text-muted me-1">Quick:</span>
            <?php
            $quick_ranges = ['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'];
            $active_range = '';
            $today_str = date('Y-m-d');
            if ($date_from === $today_str && $date_to === $today_str) {
                $active_range = 'today';
            } elseif ($date_to === $today_str) {
                if ($date_from === date('Y-m-d', strtotime('monday this week'))) $active_range = 'week';
                elseif ($date_from === date('Y-m-01')) $active_range = 'month';
                elseif ($date_from === date('Y-01-01')) $active_range = 'year';
            }
            foreach ($quick_ranges as $range => $label):
                $cls = ($active_range === $range) ? 'btn-info text-white' : 'btn-outline-info';
            ?>
            <button type="button" class="btn <?= $cls ?> btn-sm sc-btn" data-range="<?= $range ?>"><?= $label ?></button>
            <?php endforeach; ?>
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
            <?php if (!empty($staff_list)): ?>
            <div class="col-6 col-md-auto">
                <label class="form-label small fw-semibold mb-1">Collected By</label>
                <select name="staff_id" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($staff_list as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= $staff_id === (int)$st['id'] ? 'selected' : '' ?>><?= h($st['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div id="printArea">

<!-- ── Print header ── -->
<div class="print-header d-none d-print-block mb-4">
    <table width="100%" style="border-bottom:2px solid #0d6efd;padding-bottom:10px;margin-bottom:10px">
        <tr>
            <td width="70"><img src="<?= h(acc_university_logo_url()) ?>" alt="Logo" style="height:56px;width:auto"></td>
            <td style="padding-left:12px">
                <div style="font-size:15pt;font-weight:700;color:#0d6efd">Prime University</div>
                <div style="font-size:8pt;color:#555"><?= h(acc_university_address()) ?></div>
                <div style="font-size:8pt;color:#555"><?= h(acc_university_website()) ?></div>
            </td>
            <td align="right" style="vertical-align:top">
                <div style="font-size:13pt;font-weight:700;color:#198754">Staff Collection Report</div>
                <div style="font-size:8pt;color:#666">Period: <?= h($period_label) ?></div>
                <?php if ($fee_type):   ?><div style="font-size:8pt;color:#666">Fee Type: <?= h(acc_fee_type_label($fee_type)) ?></div><?php endif; ?>
                <?php if ($pay_method): ?><div style="font-size:8pt;color:#666">Method: <?= h($pay_methods[$pay_method] ?? $pay_method) ?></div><?php endif; ?>
                <div style="font-size:7.5pt;color:#999;margin-top:4px">Printed: <?= date('d M Y, h:i A') ?></div>
            </td>
        </tr>
    </table>
</div>

<?php if (!empty($rows)): ?>
<!-- ── Summary stat cards (screen) ── -->
<div class="row g-3 mb-3 no-print">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#0d6efd,#6ea8fe)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-coins fa-lg opacity-75"></i><span class="fw-semibold small opacity-90">Grand Total</span></div>
                <div class="fw-bold" style="font-size:1.3rem"><?= $currency ?> <?= number_format($grand_total, 2) ?></div>
                <div style="font-size:.72rem;opacity:.8"><?= count($rows) ?> transaction(s)</div>
            </div>
        </div>
    </div>
    <?php $shown = 0; foreach ($staff_totals as $sname => $stotal): if ($shown++ >= 3) break; ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="rounded-circle bg-info bg-opacity-10 text-info d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:.8rem"><i class="fas fa-user"></i></span>
                    <span class="fw-semibold small text-truncate" title="<?= h($sname) ?>"><?= h($sname) ?></span>
                </div>
                <div class="fw-bold text-primary" style="font-size:1.1rem"><?= $currency ?> <?= number_format($stotal, 2) ?></div>
                <div class="text-muted" style="font-size:.72rem">Collected</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Charts ── -->
<div class="row g-3 mb-3 no-print">
    <div class="col-12 col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-user-tie me-1 text-info"></i>Collection by Staff</strong></div>
            <div class="card-body p-3"><canvas id="staffChart" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-money-bill-wave me-1 text-success"></i>Payment Method</strong></div>
            <div class="card-body p-3"><canvas id="payChart" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-2 px-3 bg-white"><strong class="small"><i class="fas fa-tags me-1 text-danger"></i>Fee Type</strong></div>
            <div class="card-body p-3"><canvas id="feeChart" height="200"></canvas></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Detail table ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <strong class="small"><i class="fas fa-table me-1 text-info"></i> Transaction Breakdown · <span id="recCount"><?= count($rows) ?></span> record(s)</strong>
        <div class="d-flex align-items-center gap-2 no-print">
            <input type="search" id="tableSearch" class="form-control form-control-sm" style="max-width:220px" placeholder="Search records…">
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
            <p class="mb-2 fw-semibold">No collection records found</p>
            <p class="small mb-0">Try adjusting your date range or filters.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive" id="tableWrapper">
            <table class="table table-hover align-middle mb-0" id="collectionTable" style="font-size:.82rem">
                <thead style="background:#f0f4ff">
                    <tr>
                        <th class="text-center" style="width:38px">#</th>
                        <th style="width:82px">Date</th>
                        <th>Student</th>
                        <th>Program / Batch</th>
                        <th>Staff</th>
                        <th>Fee Type</th>
                        <th>Method</th>
                        <th>Invoice</th>
                        <th class="text-end">Amount (<?= h($currency) ?>)</th>
                    </tr>
                </thead>
                <?php
                $ft_colors = [
                    'admission'        => 'primary',
                    'registration'     => 'success',
                    'semester_tuition' => 'info',
                    'fixed_fee'        => 'warning',
                    'english_fee'      => 'secondary',
                    'other'            => 'dark',
                ];
                $pm_icons = [
                    'cash'           => 'fa-money-bill-wave',
                    'bank'           => 'fa-university',
                    'mobile_banking' => 'fa-mobile-alt',
                    'old_erp'        => 'fa-database',
                ];
                ?>
                <tbody id="tableBody">
                    <?php foreach ($rows as $i => $r):
                        $ft_color = $ft_colors[$r['fee_type']] ?? 'secondary';
                        $pm_icon  = $pm_icons[$r['payment_method']] ?? 'fa-credit-card';
                    ?>
                    <tr class="data-row">
                        <td class="text-center text-muted small idx"><?= $i + 1 ?></td>
                        <td class="text-muted small text-nowrap"><?= date('d M Y', strtotime($r['collection_date'])) ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($r['student_name']) ?></div>
                            <span class="badge bg-secondary bg-opacity-10 text-dark border" style="font-size:.72rem"><?= h($r['sid']) ?></span>
                        </td>
                        <td>
                            <div class="small text-muted"><?= h($r['program']) ?></div>
                            <span class="badge bg-light text-secondary border" style="font-size:.7rem"><?= h($r['batch']) ?></span>
                        </td>
                        <td>
                            <span class="d-flex align-items-center gap-1">
                                <span class="rounded-circle bg-info bg-opacity-10 text-info d-inline-flex align-items-center justify-content-center" style="width:22px;height:22px;font-size:.65rem;flex-shrink:0"><i class="fas fa-user"></i></span>
                                <?= h($r['collected_by']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $ft_color ?> bg-opacity-10 text-<?= $ft_color ?> border border-<?= $ft_color ?> border-opacity-25" style="font-size:.75rem">
                                <?= h(acc_fee_type_label($r['fee_type'])) ?>
                            </span>
                        </td>
                        <td>
                            <i class="fas <?= $pm_icon ?> text-muted me-1" style="font-size:.75rem"></i>
                            <?= h(acc_payment_method_label($r['payment_method'], $r['mobile_banking_provider'])) ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/accounting/fee-invoice.php?voucher_id=<?= (int)$r['voucher_id'] ?>"
                               target="_blank"
                               class="badge bg-light text-primary border text-decoration-none inv-link"
                               style="font-family:monospace;font-size:.78rem"
                               title="Open invoice">
                                <?= h($r['invoice_no']) ?>&nbsp;<i class="fas fa-external-link-alt" style="font-size:.6rem"></i>
                            </a>
                        </td>
                        <td class="text-end fw-bold text-success amt"><?= number_format((float)$r['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#e8f0fe">
                    <tr>
                        <td colspan="8" class="text-end fw-bold" style="font-size:.85rem"><i class="fas fa-calculator me-1 text-primary"></i> Filtered Total</td>
                        <td class="text-end fw-bold text-primary" style="font-size:.9rem" id="footTotal"><?= $currency ?> <?= number_format($grand_total, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
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
        <?php endif; ?>
    </div>
</div>

</div><!-- #printArea -->

<style>
#collectionTable thead th { font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#495057; border-bottom:2px solid #d0d9f5; white-space:nowrap; }
#collectionTable tbody tr:hover { background:#f5f8ff; }
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display:none !important; }
    #main-wrapper, body, html { margin:0 !important; padding:0 !important; }
    #printArea { width:100%; }
    #collectionTable { font-size:7.8pt !important; border-collapse:collapse; width:100%; }
    #collectionTable th, #collectionTable td { padding:4px 5px !important; border:1px solid #ccc !important; vertical-align:middle !important; }
    #collectionTable thead { background:#dce8ff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    #collectionTable tfoot { background:#e0eaff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .inv-link .fa-external-link-alt { display:none !important; }
    .inv-link { color:#000 !important; text-decoration:none !important; }
    .data-row { display:table-row !important; }
    .card { box-shadow:none !important; border:1px solid #dee2e6 !important; }
    .badge { border:1px solid #aaa !important; background:transparent !important; color:#000 !important; }
    #tableWrapper { overflow:visible !important; }
}
@page { size: A4 portrait; margin: 12mm 10mm 14mm 10mm; }
</style>

<?php if (!empty($rows)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';
    var palette = ['#0d6efd','#16a34a','#7c3aed','#ea580c','#dc2626','#0891b2','#ca8a04','#db2777','#475569','#65a30d'];

    var staffEl = document.getElementById('staffChart');
    if (staffEl) new Chart(staffEl, {
        type: 'bar',
        data: { labels: <?= json_encode(array_keys($staff_totals)) ?>, datasets: [{ data: <?= json_encode(array_map('round', array_values($staff_totals))) ?>, backgroundColor: 'rgba(13,202,240,.75)', borderRadius: 4 }] },
        options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
    });
    function pie(id, labels, data) {
        var el = document.getElementById(id);
        if (el) new Chart(el, { type: 'doughnut', data: { labels: labels, datasets: [{ data: data, backgroundColor: palette }] }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } } });
    }
    pie('payChart', <?= json_encode(array_keys($by_pay_method)) ?>, <?= json_encode(array_map('round', array_values($by_pay_method))) ?>);
    pie('feeChart', <?= json_encode(array_keys($by_fee_type)) ?>, <?= json_encode(array_map('round', array_values($by_fee_type))) ?>);

    // ── Quick date buttons ──
    var today = new Date();
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    document.querySelectorAll('.sc-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = this.dataset.range, from, to = fmt(today);
            if (range === 'today') { from = to; }
            else if (range === 'week') { var d = new Date(today); d.setDate(d.getDate() - ((d.getDay()+6)%7)); from = fmt(d); }
            else if (range === 'month') { from = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-01'; }
            else if (range === 'year') { from = today.getFullYear() + '-01-01'; }
            document.getElementById('date_from').value = from;
            document.getElementById('date_to').value = to;
            document.getElementById('filterForm').submit();
        });
    });

    // ── Client-side search + pagination ──
    var table = document.getElementById('collectionTable');
    if (!table) return;
    var allRows = Array.prototype.slice.call(table.tBodies[0].rows);
    var search = document.getElementById('tableSearch');
    var pageSel = document.getElementById('pageSize');
    var pager = document.getElementById('pager');
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
        var start = (page - 1) * size, end = start + size, total = 0;
        allRows.forEach(function (tr) { tr.style.display = 'none'; });
        rows.forEach(function (tr, i) {
            total += parseFloat((tr.querySelector('.amt').textContent || '0').replace(/,/g, '')) || 0;
            if (i >= start && i < end) { tr.style.display = ''; tr.querySelector('.idx').textContent = i + 1; }
        });
        recCount.textContent = rows.length;
        footTotal.textContent = currency + ' ' + money(total);
        pageInfo.textContent = rows.length ? ('Showing ' + (start + 1) + '–' + Math.min(end, rows.length) + ' of ' + rows.length) : 'No matching records';
        pager.innerHTML = '';
        if (pages > 1) {
            function item(label, target, disabled, active) {
                var li = document.createElement('li');
                li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
                var a = document.createElement('a');
                a.className = 'page-link'; a.href = '#'; a.textContent = label;
                a.addEventListener('click', function (e) { e.preventDefault(); if (!disabled && !active) { page = target; render(); } });
                li.appendChild(a); pager.appendChild(li);
            }
            item('«', page - 1, page === 1, false);
            var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
            if (from > 1) item('1', 1, false, page === 1);
            if (from > 2) item('…', page, true, false);
            for (var p = from; p <= to; p++) item(String(p), p, false, p === page);
            if (to < pages - 1) item('…', page, true, false);
            if (to < pages) item(String(pages), pages, false, page === pages);
            item('»', page + 1, page === pages, false);
        }
    }
    search.addEventListener('input', function () { page = 1; render(); });
    pageSel.addEventListener('change', function () { page = 1; render(); });
    render();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
