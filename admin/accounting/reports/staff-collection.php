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

// ── Build query ───────────────────────────────────────────────────────────────

$where  = ['v.status = \'posted\'', 'v.is_deleted = 0'];
$params = [];

if ($date_from) {
    $where[]  = 'DATE(v.voucher_date) >= ?';
    $params[] = $date_from;
}
if ($date_to) {
    $where[]  = 'DATE(v.voucher_date) <= ?';
    $params[] = $date_to;
}
if ($fee_type) {
    $where[]  = 'p.fee_type = ?';
    $params[] = $fee_type;
}
if ($pay_method) {
    $where[]  = 'p.payment_method = ?';
    $params[] = $pay_method;
}
if ($staff_id) {
    $where[]  = 'p.collected_by = ?';
    $params[] = $staff_id;
}

$where_sql = implode(' AND ', $where);

// ── Detailed rows (per-transaction with full student info) ─────────────────

$rows = db()->prepare(
    "SELECT
         s.student_id                              AS sid,
         s.full_name                               AS student_name,
         COALESCE(ap.program_name, d.name, '—')   AS program,
         COALESCE(s.admitted_semester, '—')        AS batch,
         COALESCE(u.full_name, 'System')           AS collected_by,
         p.fee_type,
         p.payment_method,
         p.mobile_banking_provider,
         v.voucher_number                          AS invoice_no,
         p.amount,
         DATE(v.voucher_date)                      AS collection_date
     FROM sfp_payments p
     JOIN acc_vouchers                v  ON v.id  = p.voucher_id
     JOIN students                    s  ON s.id  = p.student_id
     LEFT JOIN users                  u  ON u.id  = p.collected_by
     LEFT JOIN dept_departments       d  ON d.id  = s.dept_id
     LEFT JOIN dept_academic_programs ap ON ap.id = s.program_id
     WHERE $where_sql
     ORDER BY v.voucher_date DESC, p.id DESC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$grand_total = array_sum(array_column($rows, 'amount'));

// ── Per-staff summary (for stat cards) ────────────────────────────────────

$staff_totals = [];
foreach ($rows as $r) {
    $name = $r['collected_by'];
    $staff_totals[$name] = ($staff_totals[$name] ?? 0.0) + (float)$r['amount'];
}
arsort($staff_totals);

// ── Staff list for filter dropdown ─────────────────────────────────────────

$staff_list = db()->query(
    "SELECT DISTINCT u.id, u.full_name
     FROM sfp_payments p
     JOIN users u ON u.id = p.collected_by
     ORDER BY u.full_name ASC"
)->fetchAll();

$fee_types   = acc_student_fee_types();
$pay_methods = ['cash' => 'Cash', 'bank' => 'Bank', 'mobile_banking' => 'Mobile Banking'];

// ── Period label for display ───────────────────────────────────────────────

$period_label = ($date_from === $date_to && $date_from === date('Y-m-d'))
    ? 'Today — ' . date('d M Y')
    : (($date_from ? date('d M Y', strtotime($date_from)) : 'All time')
       . ' — '
       . ($date_to ? date('d M Y', strtotime($date_to)) : 'All time'));

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ── Screen: page header ── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2 no-print">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-info"></i>Staff Collection Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Staff Collection Report</li>
        </ol></nav>
    </div>
    <button onclick="window.print()" class="btn btn-info btn-sm text-white shadow-sm">
        <i class="fas fa-print me-1"></i> Print A4
    </button>
</div>

<!-- ── Filters ── -->
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body p-3">
        <!-- Quick date shortcuts -->
        <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
            <span class="small fw-semibold text-muted me-1">Quick Range:</span>
            <?php
            $quick_ranges = [
                'today' => 'Today',
                'week'  => 'This Week',
                'month' => 'This Month',
                'year'  => 'This Year',
            ];
            // Detect active quick range
            $active_range = '';
            $today_str = date('Y-m-d');
            if ($date_from === $today_str && $date_to === $today_str) {
                $active_range = 'today';
            } elseif ($date_to === $today_str) {
                $week_start  = date('Y-m-d', strtotime('monday this week'));
                $month_start = date('Y-m-01');
                $year_start  = date('Y-01-01');
                if ($date_from === $week_start)  $active_range = 'week';
                elseif ($date_from === $month_start) $active_range = 'month';
                elseif ($date_from === $year_start)  $active_range = 'year';
            }
            foreach ($quick_ranges as $range => $label):
                $cls = ($active_range === $range) ? 'btn-info text-white' : 'btn-outline-info';
            ?>
            <button type="button" class="btn <?= $cls ?> btn-sm sc-btn" data-range="<?= $range ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </div>

        <form method="get" id="filterForm" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">From</label>
                <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Fee Type</label>
                <select name="fee_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($fee_types as $ft): ?>
                    <option value="<?= h($ft) ?>" <?= $fee_type === $ft ? 'selected' : '' ?>><?= h(acc_fee_type_label($ft)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach ($pay_methods as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $pay_method === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($staff_list)): ?>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Collected By</label>
                <select name="staff_id" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($staff_list as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= $staff_id === (int)$st['id'] ? 'selected' : '' ?>><?= h($st['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync me-1"></i> Generate</button>
                <a href="?" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     PRINTABLE REPORT AREA
     ══════════════════════════════════════════════════════════════════════ -->
<div id="printArea">

<!-- ── Print header ── -->
<div class="print-header d-none d-print-block mb-4">
    <table width="100%" style="border-bottom:2px solid #0d6efd;padding-bottom:10px;margin-bottom:10px">
        <tr>
            <td width="70">
                <img src="<?= h(acc_university_logo_url()) ?>" alt="Logo" style="height:56px;width:auto">
            </td>
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

<!-- ── Summary stat cards (screen) ── -->
<?php if (!empty($rows)): ?>
<div class="row g-3 mb-4 no-print">
    <!-- Grand Total -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#0d6efd,#6ea8fe)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas fa-coins fa-lg opacity-75"></i>
                    <span class="fw-semibold small opacity-90">Grand Total</span>
                </div>
                <div class="fw-bold" style="font-size:1.3rem"><?= $currency ?> <?= number_format($grand_total, 2) ?></div>
                <div style="font-size:.72rem;opacity:.8"><?= count($rows) ?> transaction(s)</div>
            </div>
        </div>
    </div>
    <?php foreach ($staff_totals as $sname => $stotal): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="rounded-circle bg-info bg-opacity-10 text-info d-inline-flex align-items-center justify-content-center" style="width:28px;height:28px;font-size:.8rem">
                        <i class="fas fa-user"></i>
                    </span>
                    <span class="fw-semibold small text-truncate" title="<?= h($sname) ?>"><?= h($sname) ?></span>
                </div>
                <div class="fw-bold text-primary" style="font-size:1.1rem"><?= $currency ?> <?= number_format($stotal, 2) ?></div>
                <div class="text-muted" style="font-size:.72rem">Collected</div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Print summary strip ── -->
<div class="d-none d-print-block mb-3">
    <table width="100%" style="border-collapse:collapse">
        <tr>
            <td style="background:#0d6efd;color:#fff;padding:6px 10px;border-radius:4px 0 0 4px;font-weight:700;font-size:9pt">
                Grand Total &nbsp; <?= $currency ?> <?= number_format($grand_total, 2) ?>
            </td>
            <td style="padding:6px 10px;font-size:8pt;color:#555">
                <?= count($rows) ?> transaction(s) &nbsp;|&nbsp; <?= h($period_label) ?>
            </td>
            <?php foreach ($staff_totals as $sname => $stotal): ?>
            <td style="padding:6px 10px;font-size:8pt;color:#333;border-left:1px solid #dee2e6">
                <strong><?= h($sname) ?>:</strong> <?= $currency ?> <?= number_format($stotal, 2) ?>
            </td>
            <?php endforeach; ?>
        </tr>
    </table>
</div>
<?php endif; ?>

<!-- ── Detail table ── -->
<div class="card border-0 shadow-sm">
    <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between no-print">
        <strong class="small">
            <i class="fas fa-table me-1 text-info"></i>
            Transaction Breakdown &nbsp;·&nbsp; <?= h($period_label) ?>
        </strong>
        <span class="fw-bold small text-primary">
            <?= count($rows) ?> record(s) &nbsp;|&nbsp; Total: <?= $currency ?> <?= number_format($grand_total, 2) ?>
        </span>
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
                        <th>Student Name</th>
                        <th>ID</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Collected By</th>
                        <th>Fee Type</th>
                        <th>Method</th>
                        <th>Invoice No</th>
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
                ];
                ?>
                <tbody id="tableBody">
                    <?php foreach ($rows as $i => $r):
                        $ft_color = $ft_colors[$r['fee_type']] ?? 'secondary';
                        $pm_icon  = $pm_icons[$r['payment_method']] ?? 'fa-credit-card';
                    ?>
                    <tr class="data-row" data-page="<?= floor($i / 10) + 1 ?>">
                        <td class="text-center text-muted small"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['student_name']) ?></td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-10 text-dark border" style="font-size:.78rem"><?= h($r['sid']) ?></span>
                        </td>
                        <td class="text-muted"><?= h($r['program']) ?></td>
                        <td class="text-muted"><?= h($r['batch']) ?></td>
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
                            <span class="badge bg-light text-dark border" style="font-family:monospace;font-size:.78rem"><?= h($r['invoice_no']) ?></span>
                        </td>
                        <td class="text-end fw-bold text-success"><?= number_format((float)$r['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#e8f0fe">
                    <tr>
                        <td colspan="9" class="text-end fw-bold" style="font-size:.85rem">
                            <i class="fas fa-calculator me-1 text-primary"></i> Grand Total
                        </td>
                        <td class="text-end fw-bold text-primary" style="font-size:.9rem">
                            <?= $currency ?> <?= number_format($grand_total, 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- ── Pagination (screen only) ── -->
        <?php $total_pages = (int)ceil(count($rows) / 10); ?>
        <?php if ($total_pages > 1): ?>
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-top no-print">
            <div class="small text-muted" id="pageInfo">Showing page 1 of <?= $total_pages ?></div>
            <nav>
                <ul class="pagination pagination-sm mb-0" id="pagination">
                    <li class="page-item" id="prevBtn">
                        <a class="page-link" href="#" onclick="changePage(-1);return false">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?= $p === 1 ? 'active' : '' ?>" id="pageBtn<?= $p ?>">
                        <a class="page-link" href="#" onclick="goToPage(<?= $p ?>);return false"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item" id="nextBtn">
                        <a class="page-link" href="#" onclick="changePage(1);return false">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- #printArea -->

<!-- ══════════════════════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Screen ── */
#collectionTable thead th {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #495057;
    border-bottom: 2px solid #d0d9f5;
    white-space: nowrap;
}
#collectionTable tbody tr:hover {
    background: #f5f8ff;
}

/* ── Print ── */
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper, body, html { margin: 0 !important; padding: 0 !important; }
    #printArea { width: 100%; }

    #collectionTable {
        font-size: 7.8pt !important;
        border-collapse: collapse;
        width: 100%;
    }
    #collectionTable th,
    #collectionTable td {
        padding: 4px 5px !important;
        border: 1px solid #ccc !important;
        vertical-align: middle !important;
    }
    #collectionTable thead {
        background: #dce8ff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #collectionTable tfoot {
        background: #e0eaff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Show ALL rows when printing */
    .data-row { display: table-row !important; }

    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
    .badge { border: 1px solid #aaa !important; background: transparent !important; color: #000 !important; }
    #tableWrapper { overflow: visible !important; }
}
@page {
    size: A4 portrait;
    margin: 12mm 10mm 14mm 10mm;
}
</style>

<!-- ══════════════════════════════════════════════════════════════════════════
     SCRIPTS
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    /* ── Quick date range buttons ── */
    var today = new Date();
    function fmt(d) { return d.toISOString().slice(0, 10); }

    document.querySelectorAll('.sc-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = this.dataset.range;
            var from, to = fmt(today);
            if (range === 'today') {
                from = to;
            } else if (range === 'week') {
                var d = new Date(today);
                var day = d.getDay() || 7; // treat Sunday as 7
                d.setDate(d.getDate() - day + 1); // Monday
                from = fmt(d);
            } else if (range === 'month') {
                from = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01';
            } else if (range === 'year') {
                from = today.getFullYear() + '-01-01';
            }
            document.getElementById('date_from').value = from;
            document.getElementById('date_to').value   = to;
            document.getElementById('filterForm').submit();
        });
    });

    /* ── Pagination ── */
    var currentPage = 1;
    var totalPages  = <?= $total_pages ?? 1 ?>;

    function showPage(page) {
        if (page < 1) page = 1;
        if (page > totalPages) page = totalPages;
        currentPage = page;

        document.querySelectorAll('.data-row').forEach(function (row) {
            row.style.display = parseInt(row.dataset.page) === currentPage ? '' : 'none';
        });

        // Update pagination buttons
        for (var p = 1; p <= totalPages; p++) {
            var btn = document.getElementById('pageBtn' + p);
            if (btn) btn.classList.toggle('active', p === currentPage);
        }

        var info = document.getElementById('pageInfo');
        if (info) info.textContent = 'Showing page ' + currentPage + ' of ' + totalPages;
    }

    window.goToPage    = function (p) { showPage(p); };
    window.changePage  = function (d) { showPage(currentPage + d); };

    if (totalPages > 1) showPage(1);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
