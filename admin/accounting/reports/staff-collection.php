<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('accounting-reports');
require_once __DIR__ . '/../helpers.php';
acc_ensure_bank_name_columns();

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
if ($date_from && $date_to && $date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

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
         p.student_id                              AS student_pk,
         p.package_id,
         p.fee_type,
         p.semester_number,
         p.month_number,
         sf.semester_label,
         p.payment_method,
         p.mobile_banking_provider,
         p.bank_name,
         (SELECT a.name
            FROM acc_voucher_items vi
            JOIN acc_accounts a ON a.id = vi.account_id
           WHERE vi.voucher_id = v.id AND vi.debit_amount > 0 AND a.type = 'asset'
           ORDER BY vi.id ASC
           LIMIT 1)                                AS received_into,
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
     LEFT JOIN sfp_semester_fees      sf ON sf.id = p.semester_fee_id
     WHERE $where_sql
     ORDER BY v.voucher_date DESC, p.id DESC"
);
$rows->execute($params);
$rows = $rows->fetchAll();

$grand_total = array_sum(array_column($rows, 'amount'));

// ── Calendar months for monthly installments ───────────────────────────────
// sfp_payments stores semester_number + month_number (the slot inside the
// semester). Convert that to the real calendar month ("March 2026") using the
// package's payment start and months-per-semester, shifted past any semester
// drops – the same logic the fee schedule and invoice use.
$pkg_ids = array_values(array_unique(array_filter(array_map(
    static fn(array $r): int => (int)($r['package_id'] ?? 0), $rows
))));
$pkg_cal = [];   // [package_id] => start_month / start_year / months_int / num_semesters
if ($pkg_ids) {
    $ph = implode(',', array_fill(0, count($pkg_ids), '?'));
    $pk_stmt = db()->prepare(
        "SELECT p.*, s.admitted_semester,
                cp.bi_semester_start_month  AS linked_bi_semester_start_month,
                cp.tri_semester_start_month AS linked_tri_semester_start_month
         FROM sfp_packages p
         JOIN students s ON s.id = p.student_id
         LEFT JOIN cf_programs cp ON cp.id = p.cf_program_id
         WHERE p.id IN ($ph)"
    );
    $pk_stmt->execute($pkg_ids);
    $pkgs = [];
    foreach ($pk_stmt->fetchAll() as $pkg) { $pkgs[(int)$pkg['id']] = $pkg; }

    $sf_stmt = db()->prepare(
        "SELECT package_id, semester_number, semester_label
         FROM sfp_semester_fees WHERE package_id IN ($ph)
         ORDER BY semester_number ASC"
    );
    $sf_stmt->execute($pkg_ids);
    $sfs_by_pkg = [];
    foreach ($sf_stmt->fetchAll() as $sf) { $sfs_by_pkg[(int)$sf['package_id']][] = $sf; }

    foreach ($pkgs as $pid => $pkg) {
        $sfs   = $sfs_by_pkg[$pid] ?? [];
        $start = acc_package_payment_start($pkg, $sfs);
        $pkg_cal[$pid] = [
            'start_month'   => (int)$start['month'],
            'start_year'    => (int)$start['year'],
            'months_int'    => max(1, (int)round((float)($pkg['months_per_semester'] ?? 0))),
            'num_semesters' => count($sfs),
        ];
    }
}

// Calendar month ['month','year'] for one fee line, or null when it cannot be resolved.
$line_calendar = static function (array $r) use ($pkg_cal): ?array {
    $pid = (int)($r['package_id'] ?? 0);
    $mn  = (int)($r['month_number'] ?? 0);
    if ($pid <= 0 || $mn <= 0 || !isset($pkg_cal[$pid])) { return null; }
    $c   = $pkg_cal[$pid];
    $sem = (int)($r['semester_number'] ?? 0);
    if ((string)$r['fee_type'] === 'bi_tri_shift_fee') {
        // Extra months appended after the last scheduled semester
        $offset = $c['num_semesters'] * $c['months_int'] + ($mn - 1);
    } elseif ($sem > 0) {
        $offset = ($sem - 1) * $c['months_int'] + ($mn - 1);
    } else {
        return null;
    }
    $sid  = (int)($r['student_pk'] ?? 0);
    $info = (function_exists('sd_shifted_slot_calendar') && $sid > 0)
        ? sd_shifted_slot_calendar($sid, $c['start_month'], $c['start_year'], $offset)
        : acc_month_year_for_slot($c['start_month'], $c['start_year'], $offset);
    return ['month' => (int)$info['month'], 'year' => (int)$info['year']];
};

// "March 2026" · "March–May 2026" · "November 2026–January 2027" (consecutive months collapsed).
$format_month_span = static function (array $cal_months): string {
    $keys = [];
    foreach ($cal_months as $cm) { $keys[$cm['year'] * 12 + ($cm['month'] - 1)] = true; }
    $serials = array_keys($keys);
    sort($serials);
    $name  = static fn(int $s): string => date('F', mktime(0, 0, 0, ($s % 12) + 1, 1, 2000));
    $year  = static fn(int $s): int => intdiv($s, 12);
    $parts = [];
    $start = $prev = null;
    $flush = static function () use (&$parts, &$start, &$prev, $name, $year): void {
        if ($start === null) { return; }
        if ($start === $prev) {
            $parts[] = $name($start) . ' ' . $year($start);
        } elseif ($year($start) === $year($prev)) {
            $parts[] = $name($start) . '–' . $name($prev) . ' ' . $year($start);
        } else {
            $parts[] = $name($start) . ' ' . $year($start) . '–' . $name($prev) . ' ' . $year($prev);
        }
    };
    foreach ($serials as $s) {
        if ($start === null)  { $start = $prev = $s; continue; }
        if ($s === $prev + 1) { $prev = $s; continue; }
        $flush();
        $start = $prev = $s;
    }
    $flush();
    return implode(', ', $parts);
};

// Fallback when the calendar month is unknown: "Month 3", "Months 1–3".
$compress_months = static function (array $months): string {
    $months = array_values(array_unique(array_map('intval', $months)));
    sort($months);
    $parts = [];
    $start = $prev = null;
    $flush = static function () use (&$parts, &$start, &$prev): void {
        if ($start === null) { return; }
        $parts[] = $start === $prev ? 'Month ' . $start : 'Months ' . $start . '–' . $prev;
    };
    foreach ($months as $m) {
        if ($start === null)  { $start = $prev = $m; continue; }
        if ($m === $prev + 1) { $prev = $m; continue; }
        $flush();
        $start = $prev = $m;
    }
    $flush();
    return implode(', ', $parts);
};

// ── Group fee lines into receipts (one row per voucher) ─────────────────────
// A single payment of e.g. 5,000 may be split across several fee heads
// (tuition + registration + form fee ...). Each head is its own sfp_payments
// line, but the report shows ONE row per receipt with the breakdown inside it.
$receipts = [];   // [voucher_id] => receipt (header fields + 'items' => [fee_type => ...])
foreach ($rows as $r) {
    $vid = (int)$r['voucher_id'];
    if (!isset($receipts[$vid])) {
        $receipts[$vid] = [
            'voucher_id'              => $vid,
            'invoice_no'              => $r['invoice_no'],
            'collection_date'         => $r['collection_date'],
            'sid'                     => $r['sid'],
            'student_name'            => $r['student_name'],
            'program'                 => $r['program'],
            'batch'                   => $r['batch'],
            'collected_by'            => $r['collected_by'],
            'payment_method'          => $r['payment_method'],
            'mobile_banking_provider' => $r['mobile_banking_provider'],
            'bank_name'               => $r['bank_name'],
            'received_into'           => $r['received_into'],
            'amount'                  => 0.0,
            'lines'                   => 0,
            'items'                   => [],
        ];
    }
    $ft  = (string)$r['fee_type'];
    $amt = (float)$r['amount'];
    $receipts[$vid]['amount'] += $amt;
    $receipts[$vid]['lines']++;

    if (!isset($receipts[$vid]['items'][$ft])) {
        $receipts[$vid]['items'][$ft] = [
            'fee_type' => $ft,
            'label'    => acc_fee_type_label($ft),
            'amount'   => 0.0,
            'sems'     => [],   // [semester label] => ['cal' => [[month,year]...], 'slots' => [month numbers]]
            'ctx'      => '',
        ];
    }
    $receipts[$vid]['items'][$ft]['amount'] += $amt;

    $sem = trim((string)($r['semester_label'] ?? ''));
    if ($sem === '' && !empty($r['semester_number'])) { $sem = 'Semester ' . (int)$r['semester_number']; }
    if ($sem !== '' || !empty($r['month_number'])) {
        if (!isset($receipts[$vid]['items'][$ft]['sems'][$sem])) {
            $receipts[$vid]['items'][$ft]['sems'][$sem] = ['cal' => [], 'slots' => []];
        }
        if (!empty($r['month_number'])) {
            $cal = $line_calendar($r);
            if ($cal !== null) { $receipts[$vid]['items'][$ft]['sems'][$sem]['cal'][]   = $cal; }
            else               { $receipts[$vid]['items'][$ft]['sems'][$sem]['slots'][] = (int)$r['month_number']; }
        }
    }
}
// Human context per head, e.g. "Spring 2026: March–May 2026 · Summer 2026: June 2026"
foreach ($receipts as &$rc) {
    foreach ($rc['items'] as &$it) {
        $ctx_parts = [];
        foreach ($it['sems'] as $sem => $b) {
            $months = [];
            if ($b['cal'])   { $months[] = $format_month_span($b['cal']); }
            if ($b['slots']) { $months[] = $compress_months($b['slots']); }
            $m   = implode(', ', $months);
            $sem = (string)$sem;
            if ($sem !== '' && $m !== '')  { $ctx_parts[] = $sem . ': ' . $m; }
            elseif ($sem !== '')           { $ctx_parts[] = $sem; }
            elseif ($m !== '')             { $ctx_parts[] = $m; }
        }
        $it['ctx'] = implode(' · ', $ctx_parts);
        unset($it['sems']);
    }
    unset($it);
    $rc['items'] = array_values($rc['items']);
}
unset($rc);
$receipts = array_values($receipts);

// ── Fee-type summary (print) ────────────────────────────────────────────────
$fee_summary = [];   // [fee_type] => label / amount / lines / receipts (distinct vouchers)
foreach ($rows as $r) {
    $ft = (string)$r['fee_type'];
    if (!isset($fee_summary[$ft])) {
        $fee_summary[$ft] = ['label' => acc_fee_type_label($ft), 'amount' => 0.0, 'lines' => 0, 'receipts' => []];
    }
    $fee_summary[$ft]['amount'] += (float)$r['amount'];
    $fee_summary[$ft]['lines']++;
    $fee_summary[$ft]['receipts'][(int)$r['voucher_id']] = true;
}
uasort($fee_summary, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);

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

// ── Staff × Collection-channel cross-tab (for print summary) ───────────────
// A "channel" is finer than the payment method: Cash, each mobile-banking
// provider (bKash / Nagad / Rocket), each bank the money was deposited to
// (bank_name chosen at collection; older rows fall back to the receipt
// voucher's receiving account), and Old ERP.
$mb_provider_labels = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket'];
$group_labels       = ['cash' => 'Cash', 'mobile_banking' => 'Mobile Banking', 'bank' => 'Bank', 'old_erp' => 'Old ERP'];
$group_order        = ['cash' => 0, 'mobile_banking' => 1, 'bank' => 2, 'old_erp' => 3];
$provider_order     = ['bkash' => 0, 'nagad' => 1, 'rocket' => 2];

$channel_group  = [];   // [channel_key] => group (cash|mobile_banking|bank|old_erp)
$channel_label  = [];   // [channel_key] => column label
$channel_totals = [];   // [channel_key] => amount (all staff)
$channel_rcpts  = [];   // [channel_key] => [voucher_id => true]
$staff_matrix   = [];   // [staff_name][channel_key] => amount
$staff_txns     = [];   // [staff_name] => number of receipts (vouchers)
foreach ($rows as $r) {
    $amt  = (float)$r['amount'];
    $name = $r['collected_by'];
    $pm   = strtolower(trim((string)$r['payment_method']));
    switch ($pm) {
        case 'mobile_banking':
            $prov  = strtolower(trim((string)($r['mobile_banking_provider'] ?? '')));
            $key   = 'mb:' . ($prov !== '' ? $prov : 'other');
            $label = $mb_provider_labels[$prov] ?? ($prov !== '' ? ucfirst($prov) : 'Other Wallet');
            $group = 'mobile_banking';
            break;
        case 'bank':
            $bank  = trim((string)($r['bank_name'] ?? ''));
            if ($bank === '') { $bank = trim((string)($r['received_into'] ?? '')); }
            $key   = 'bank:' . ($bank !== '' ? $bank : 'unspecified');
            $label = $bank !== '' ? $bank : 'Unspecified';
            $group = 'bank';
            break;
        case 'old_erp':
            $key = 'old_erp'; $label = 'Old ERP'; $group = 'old_erp';
            break;
        default:
            $key = 'cash'; $label = 'Cash'; $group = 'cash';
    }
    $channel_group[$key]       = $group;
    $channel_label[$key]       = $label;
    $channel_totals[$key]      = ($channel_totals[$key] ?? 0.0) + $amt;
    $channel_rcpts[$key][(int)$r['voucher_id']] = true;
    $staff_matrix[$name][$key] = ($staff_matrix[$name][$key] ?? 0.0) + $amt;
}
foreach ($receipts as $rc) {
    $staff_txns[$rc['collected_by']] = ($staff_txns[$rc['collected_by']] ?? 0) + 1;
}

// Column order: Cash → bKash, Nagad, Rocket → banks (A–Z) → Old ERP
$channel_keys = array_keys($channel_label);
usort($channel_keys, static function (string $a, string $b) use ($channel_group, $channel_label, $group_order, $provider_order): int {
    $ga = $group_order[$channel_group[$a]] ?? 9;
    $gb = $group_order[$channel_group[$b]] ?? 9;
    if ($ga !== $gb) { return $ga <=> $gb; }
    if ($channel_group[$a] === 'mobile_banking') {
        $pa = $provider_order[substr($a, 3)] ?? 9;
        $pb = $provider_order[substr($b, 3)] ?? 9;
        if ($pa !== $pb) { return $pa <=> $pb; }
    }
    return strcasecmp($channel_label[$a], $channel_label[$b]);
});

// Group the columns for the two-row header. A group with more than one
// channel (e.g. several banks) also gets a "<Group> Total" subtotal column.
$summary_groups = [];   // [group] => ['label' => ..., 'cols' => [['key','label','subtotal'], ...]]
foreach ($channel_keys as $k) {
    $g = $channel_group[$k];
    $summary_groups[$g]['label']  = $group_labels[$g] ?? ucfirst($g);
    $summary_groups[$g]['cols'][] = ['key' => $k, 'label' => $channel_label[$k], 'subtotal' => false];
}
$has_sub_header = false;
foreach ($summary_groups as $g => &$grp) {
    if (count($grp['cols']) > 1) {
        $grp['cols'][] = ['key' => 'sub:' . $g, 'label' => $grp['label'] . ' Total', 'subtotal' => true];
        $has_sub_header = true;
    }
}
unset($grp);

// Amount for one summary cell (a channel or a group subtotal). Null = nothing collected.
$summary_cell = static function (array $amounts, array $col) use ($summary_groups): ?float {
    if ($col['subtotal']) {
        $g   = substr($col['key'], 4);
        $sum = 0.0;
        $any = false;
        foreach ($summary_groups[$g]['cols'] as $c) {
            if (!$c['subtotal'] && isset($amounts[$c['key']])) { $sum += $amounts[$c['key']]; $any = true; }
        }
        return $any ? $sum : null;
    }
    return $amounts[$col['key']] ?? null;
};

// Order staff rows by their total collection (desc) to match $staff_totals
$staff_matrix_sorted = [];
foreach ($staff_totals as $name => $tot) {
    if (isset($staff_matrix[$name])) { $staff_matrix_sorted[$name] = $staff_matrix[$name]; }
}
$staff_matrix = $staff_matrix_sorted;

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
<!-- ── Print-only summary: staff × collection channel (Cash / bKash / Nagad / Rocket / each Bank) ── -->
<div class="d-none d-print-block mb-3">
    <div style="font-size:10pt;font-weight:700;color:#0d6efd;margin-bottom:5px">Collection Summary — by Staff &amp; Payment Channel</div>
    <table class="sc-summary">
        <thead>
            <tr>
                <th rowspan="<?= $has_sub_header ? 2 : 1 ?>" class="sc-l">#</th>
                <th rowspan="<?= $has_sub_header ? 2 : 1 ?>" class="sc-l">Staff</th>
                <th rowspan="<?= $has_sub_header ? 2 : 1 ?>" class="sc-r">Receipts</th>
                <?php foreach ($summary_groups as $g => $grp): $ncols = count($grp['cols']); ?>
                    <?php if ($ncols === 1): ?>
                    <th rowspan="<?= $has_sub_header ? 2 : 1 ?>" class="sc-r"><?= h(($g === 'bank' ? 'Bank – ' : '') . $grp['cols'][0]['label']) ?></th>
                    <?php else: ?>
                    <th colspan="<?= $ncols ?>" class="sc-c sc-grp"><?= h($grp['label']) ?></th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <th rowspan="<?= $has_sub_header ? 2 : 1 ?>" class="sc-r">Total (<?= h($currency) ?>)</th>
            </tr>
            <?php if ($has_sub_header): ?>
            <tr>
                <?php foreach ($summary_groups as $grp): if (count($grp['cols']) === 1) continue; ?>
                    <?php foreach ($grp['cols'] as $col): ?>
                    <th class="sc-r<?= $col['subtotal'] ? ' sc-sub' : '' ?>"><?= h($col['label']) ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php $sidx = 0; foreach ($staff_matrix as $sname => $amounts): $sidx++; ?>
            <tr>
                <td class="sc-muted"><?= $sidx ?></td>
                <td class="sc-name"><?= h($sname) ?></td>
                <td class="sc-r sc-muted"><?= (int)($staff_txns[$sname] ?? 0) ?></td>
                <?php foreach ($summary_groups as $grp): foreach ($grp['cols'] as $col): $v = $summary_cell($amounts, $col); ?>
                <td class="sc-r<?= $col['subtotal'] ? ' sc-sub' : '' ?>"><?= $v !== null ? number_format($v, 2) : '—' ?></td>
                <?php endforeach; endforeach; ?>
                <td class="sc-r sc-total"><?= number_format($staff_totals[$sname] ?? 0, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="sc-r">Total Collection</td>
                <td class="sc-r"><?= count($receipts) ?></td>
                <?php foreach ($summary_groups as $grp): foreach ($grp['cols'] as $col): $v = $summary_cell($channel_totals, $col); ?>
                <td class="sc-r"><?= $v !== null ? number_format($v, 2) : '—' ?></td>
                <?php endforeach; endforeach; ?>
                <td class="sc-r sc-grand"><?= number_format($grand_total, 2) ?></td>
            </tr>
        </tfoot>
    </table>
    <div style="font-size:7pt;color:#777;margin-top:3px">Mobile Banking is split by wallet provider. Bank columns show the bank the payment was deposited to, as selected at collection time.</div>
</div>

<!-- ── Print-only summaries: by Fee Type (left) and by Payment Channel (right) ── -->
<div class="d-none d-print-block mb-3">
    <table style="width:100%;border-collapse:separate;border-spacing:0">
        <tr>
            <td style="width:57%;vertical-align:top;padding-right:8px">
                <div class="sc-title">Collection by Fee Type</div>
                <table class="sc-summary">
                    <thead>
                        <tr>
                            <th class="sc-l">#</th>
                            <th class="sc-l">Fee Type</th>
                            <th class="sc-r">Receipts</th>
                            <th class="sc-r">Amount (<?= h($currency) ?>)</th>
                            <th class="sc-r">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $fidx = 0; foreach ($fee_summary as $fs): $fidx++; ?>
                        <tr>
                            <td class="sc-muted"><?= $fidx ?></td>
                            <td class="sc-name"><?= h($fs['label']) ?></td>
                            <td class="sc-r sc-muted"><?= count($fs['receipts']) ?></td>
                            <td class="sc-r sc-total"><?= number_format($fs['amount'], 2) ?></td>
                            <td class="sc-r sc-muted"><?= $grand_total > 0 ? number_format($fs['amount'] / $grand_total * 100, 1) . '%' : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="sc-r">Total</td>
                            <td class="sc-r"><?= count($receipts) ?></td>
                            <td class="sc-r sc-grand"><?= number_format($grand_total, 2) ?></td>
                            <td class="sc-r">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </td>
            <td style="width:43%;vertical-align:top;padding-left:8px">
                <div class="sc-title">Collection by Payment Channel</div>
                <table class="sc-summary">
                    <thead>
                        <tr>
                            <th class="sc-l">Channel</th>
                            <th class="sc-r">Receipts</th>
                            <th class="sc-r">Amount (<?= h($currency) ?>)</th>
                            <th class="sc-r">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary_groups as $g => $grp): $gcols = array_values(array_filter($grp['cols'], static fn(array $c): bool => !$c['subtotal'])); ?>
                            <?php foreach ($gcols as $col): $v = (float)($channel_totals[$col['key']] ?? 0); ?>
                            <tr>
                                <td class="sc-name"><?= h($g === 'bank' ? 'Bank – ' . $col['label'] : $col['label']) ?></td>
                                <td class="sc-r sc-muted"><?= count($channel_rcpts[$col['key']] ?? []) ?></td>
                                <td class="sc-r sc-total"><?= number_format($v, 2) ?></td>
                                <td class="sc-r sc-muted"><?= $grand_total > 0 ? number_format($v / $grand_total * 100, 1) . '%' : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($gcols) > 1): $gsum = 0.0; $grc = []; foreach ($gcols as $col) { $gsum += (float)($channel_totals[$col['key']] ?? 0); $grc += ($channel_rcpts[$col['key']] ?? []); } ?>
                            <tr class="sc-subrow">
                                <td class="sc-r"><?= h($grp['label']) ?> Total</td>
                                <td class="sc-r"><?= count($grc) ?></td>
                                <td class="sc-r"><?= number_format($gsum, 2) ?></td>
                                <td class="sc-r"><?= $grand_total > 0 ? number_format($gsum / $grand_total * 100, 1) . '%' : '—' ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="sc-r">Total Collection</td>
                            <td class="sc-r"><?= count($receipts) ?></td>
                            <td class="sc-r sc-grand"><?= number_format($grand_total, 2) ?></td>
                            <td class="sc-r">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </td>
        </tr>
    </table>
</div>

<!-- ── Summary stat cards (screen) ── -->
<div class="row g-3 mb-3 no-print">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#0d6efd,#6ea8fe)">
            <div class="card-body p-3 text-white">
                <div class="d-flex align-items-center gap-2 mb-1"><i class="fas fa-coins fa-lg opacity-75"></i><span class="fw-semibold small opacity-90">Grand Total</span></div>
                <div class="fw-bold" style="font-size:1.3rem"><?= $currency ?> <?= number_format($grand_total, 2) ?></div>
                <div style="font-size:.72rem;opacity:.8"><?= count($receipts) ?> receipt(s) · <?= count($rows) ?> fee line(s)</div>
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
        <strong class="small"><i class="fas fa-table me-1 text-info"></i> Transaction Breakdown · <span id="recCount"><?= count($receipts) ?></span> receipt(s) <span class="text-muted fw-normal">(<?= count($rows) ?> fee line(s))</span></strong>
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
                        <th class="text-center" style="width:30px">#</th>
                        <th style="width:110px">Date &amp; Invoice</th>
                        <th>Student</th>
                        <th>Collected By</th>
                        <th>Fee Breakdown</th>
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
                ?>
                <tbody id="tableBody">
                    <?php foreach ($receipts as $i => $rc):
                        $multi   = count($rc['items']) > 1;
                        $bank_lbl = trim((string)($rc['bank_name'] ?? '')) !== '' ? (string)$rc['bank_name'] : (string)($rc['received_into'] ?? '');
                        $method_lbl = acc_payment_method_label($rc['payment_method'], $rc['mobile_banking_provider'], $bank_lbl);
                        $meta = array_filter([$rc['sid'], $rc['batch'] !== '—' ? $rc['batch'] : '', $rc['program'] !== '—' ? $rc['program'] : '']);
                    ?>
                    <tr class="data-row">
                        <td class="text-center text-muted small idx"><?= $i + 1 ?></td>
                        <td class="small text-nowrap">
                            <div class="text-muted"><?= date('d M Y', strtotime($rc['collection_date'])) ?></div>
                            <a href="<?= APP_URL ?>/accounting/fee-invoice.php?voucher_id=<?= (int)$rc['voucher_id'] ?>"
                               target="_blank" class="inv-link text-decoration-none" title="Open invoice"
                               style="font-family:monospace;font-size:.76rem"><?= h($rc['invoice_no']) ?> <i class="fas fa-external-link-alt" style="font-size:.55rem"></i></a>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($rc['student_name']) ?></div>
                            <div class="small text-muted"><?= h(implode(' · ', $meta)) ?></div>
                        </td>
                        <td>
                            <div><?= h($rc['collected_by']) ?></div>
                            <div class="small text-muted"><?= h($method_lbl) ?></div>
                        </td>
                        <td class="bd-cell">
                            <?php foreach ($rc['items'] as $it): $ft_color = $ft_colors[$it['fee_type']] ?? 'secondary'; ?>
                            <div class="bd-line">
                                <span class="bd-head">
                                    <span class="badge bg-<?= $ft_color ?> bg-opacity-10 text-<?= $ft_color ?> border border-<?= $ft_color ?> border-opacity-25" style="font-size:.74rem"><?= h($it['label']) ?></span>
                                    <?php if ($it['ctx'] !== ''): ?><span class="bd-ctx text-muted"><?= h($it['ctx']) ?></span><?php endif; ?>
                                </span>
                                <?php if ($multi): ?><span class="bd-amt"><?= number_format($it['amount'], 2) ?></span><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end fw-bold text-success amt"><?= number_format((float)$rc['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#e8f0fe">
                    <tr>
                        <td colspan="5" class="text-end fw-bold" style="font-size:.85rem"><i class="fas fa-calculator me-1 text-primary"></i> Grand Total</td>
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
/* Fee breakdown inside a receipt row */
.bd-cell { min-width:230px; }
.bd-line { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
.bd-line + .bd-line { border-top:1px dashed #e3e8f2; margin-top:3px; padding-top:3px; }
.bd-head { display:inline-flex; flex-wrap:wrap; align-items:baseline; gap:4px 6px; }
.bd-ctx { font-size:.72rem; }
.bd-amt { font-variant-numeric:tabular-nums; white-space:nowrap; font-size:.78rem; color:#495057; }
/* Print summary: staff × collection channel */
.sc-summary { width:100%; border-collapse:collapse; font-size:8pt; }
.sc-summary th, .sc-summary td { padding:3px 6px; border:1px solid #ccc; vertical-align:middle; }
.sc-summary thead th { background:#dce8ff; border-color:#b9c9ef; font-weight:700; text-align:right; white-space:nowrap; }
.sc-summary .sc-l { text-align:left; }
.sc-summary .sc-c { text-align:center; }
.sc-summary .sc-r { text-align:right; white-space:nowrap; }
.sc-summary thead th.sc-grp { background:#c9daff; }
.sc-summary .sc-sub { background:#f3f6fd; font-weight:600; }
.sc-summary .sc-muted { color:#666; }
.sc-summary .sc-name { font-weight:600; text-align:left; }
.sc-summary .sc-total { font-weight:700; }
.sc-summary tfoot td { background:#e0eaff; border-color:#b9c9ef; font-weight:700; }
.sc-summary .sc-grand { color:#0d6efd; }
.sc-summary tr.sc-subrow td { background:#f3f6fd; font-weight:600; }
.sc-title { font-size:10pt; font-weight:700; color:#0d6efd; margin-bottom:5px; }
@media print {
    #sidebar, #topbar, .no-print, nav[aria-label="breadcrumb"] { display:none !important; }
    #main-wrapper, body, html { margin:0 !important; padding:0 !important; }
    #printArea { width:100%; }
    .sc-summary { -webkit-print-color-adjust:exact; print-color-adjust:exact; page-break-inside:avoid; }
    .sc-summary thead { display:table-header-group; }
    .sc-summary tr { page-break-inside:avoid; }
    #collectionTable { font-size:7.2pt !important; border-collapse:collapse; width:100%; table-layout:fixed; }
    #collectionTable th, #collectionTable td { padding:2px 4px !important; border:1px solid #ccc !important; vertical-align:top !important; word-break:break-word; overflow-wrap:anywhere; white-space:normal !important; line-height:1.25; }
    #collectionTable thead th { font-size:6.8pt !important; }
    /* Proportional column widths so all 6 columns fit one A4 landscape page */
    #collectionTable th:nth-child(1), #collectionTable td:nth-child(1) { width:3%; }   /* # */
    #collectionTable th:nth-child(2), #collectionTable td:nth-child(2) { width:11%; }  /* Date & Invoice */
    #collectionTable th:nth-child(3), #collectionTable td:nth-child(3) { width:22%; }  /* Student */
    #collectionTable th:nth-child(4), #collectionTable td:nth-child(4) { width:14%; }  /* Collected By & Method */
    #collectionTable th:nth-child(5), #collectionTable td:nth-child(5) { width:39%; }  /* Fee Breakdown */
    #collectionTable th:nth-child(6), #collectionTable td:nth-child(6) { width:11%; text-align:right; } /* Amount */
    #collectionTable .small, #collectionTable .bd-ctx { font-size:6.5pt !important; }
    .bd-cell { min-width:0; }
    .bd-line { gap:4px; }
    .bd-line + .bd-line { border-top:1px dotted #bbb; margin-top:1px; padding-top:1px; }
    .bd-ctx { color:#555 !important; }
    .bd-amt { font-size:7pt; color:#000; }
    #collectionTable td:nth-child(2) .inv-link { font-family:inherit !important; font-size:6.5pt !important; word-break:break-all; }
    #collectionTable td:nth-child(6) { font-size:7pt !important; white-space:nowrap !important; }
    #collectionTable thead { background:#dce8ff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; display:table-header-group; }
    #collectionTable tfoot { background:#e0eaff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    #collectionTable tr { page-break-inside:avoid; }
    .inv-link .fa-external-link-alt { display:none !important; }
    .inv-link { color:#000 !important; text-decoration:none !important; }
    .data-row { display:table-row !important; }
    .card { box-shadow:none !important; border:1px solid #dee2e6 !important; }
    .badge { display:inline !important; border:0 !important; padding:0 4px 0 0 !important; background:transparent !important; color:#000 !important; font-weight:400 !important; font-size:inherit !important; white-space:normal !important; word-break:break-word; overflow-wrap:anywhere; }
    #tableWrapper { overflow:visible !important; }
}
@page { size: A4 landscape; margin: 10mm 10mm 12mm 10mm; }
</style>

<!-- ── Quick date range buttons (always available, even with no results) ── -->
<script>
(function () {
    'use strict';
    var today = new Date();
    function fmt(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    document.querySelectorAll('.sc-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = this.dataset.range, from, to = fmt(today);
            if (range === 'today') { from = to; }
            else if (range === 'week') { var d = new Date(today); d.setDate(d.getDate() - ((d.getDay()+6)%7)); from = fmt(d); } // (getDay()+6)%7 = days since Monday
            else if (range === 'month') { from = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-01'; }
            else if (range === 'year') { from = today.getFullYear() + '-01-01'; }
            document.getElementById('date_from').value = from;
            document.getElementById('date_to').value = to;
            document.getElementById('filterForm').submit();
        });
    });
})();
</script>

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
