<?php
/**
 * Remuneration Approval Memo — Word (.doc) download.
 *
 * Generates the office memo placed before the Authority for approval of
 * examination remuneration, for one or more selected exams:
 *   1. Subject line with all selected exam titles
 *   2. Memo paragraphs (12, 13) with the exam titles substituted
 *   3. Per-department / office bill tables:
 *      SL | Name & Designation | Slot (per-exam breakdown) | Total Slots | Rate per slot | Total Remunerations
 *   4. Department-wise summary with grand total (Bangladeshi digit grouping)
 *      and the amount in words (Taka … only)
 *
 * The file is Word-compatible HTML served as .doc so it stays fully editable.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-invigilation');
require_once __DIR__ . '/slot-helpers.php';

// ── Selected exams (exam_ids[] like remuneration-bills.php, or single id=) ───
$selected_ids = [];
if (!empty($_GET['exam_ids']) && is_array($_GET['exam_ids'])) {
    foreach ($_GET['exam_ids'] as $eid) {
        $eid = (int)$eid;
        if ($eid > 0) $selected_ids[$eid] = $eid;
    }
}
if (!empty($_GET['id'])) {
    $eid = (int)$_GET['id'];
    if ($eid > 0) $selected_ids[$eid] = $eid;
}
$selected_ids = array_values($selected_ids);

if (empty($selected_ids)) {
    flash_set('error', 'Select at least one exam to download the approval memo.');
    redirect(APP_URL . '/exam-invigilation/remuneration-bills.php');
}

$ph = implode(',', array_fill(0, count($selected_ids), '?'));

$sel_st = db()->prepare(
    "SELECT id, exam_name, exam_year FROM ei_exams WHERE id IN ($ph)
     ORDER BY exam_year DESC, exam_name ASC"
);
$sel_st->execute($selected_ids);
$selected_exams = $sel_st->fetchAll();

if (empty($selected_exams)) {
    flash_set('error', 'Selected exams not found.');
    redirect(APP_URL . '/exam-invigilation/remuneration-bills.php');
}

$exam_label = implode(' & ', array_map(
    static fn ($e) => $e['exam_name'] . ' (' . $e['exam_year'] . ')',
    $selected_exams
));

// ── Helpers: Bangladeshi digit grouping + amount in words ───────────────────
function ei_bd_money(float $amount): string
{
    $s = (string)(int)round($amount);
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest  = substr($s, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s     = $rest . ',' . $last3;
    }
    return $s;
}

function ei_taka_words(float $amount): string
{
    $n = (int)round($amount);
    if ($n <= 0) return 'Taka Zero only';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
             'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two  = static function (int $x) use ($ones, $tens): string {
        if ($x < 20) return $ones[$x];
        return trim($tens[intdiv($x, 10)] . ($x % 10 ? ' ' . $ones[$x % 10] : ''));
    };
    $parts = [];
    $crore = intdiv($n, 10000000); $n %= 10000000;
    $lakh  = intdiv($n, 100000);   $n %= 100000;
    $thou  = intdiv($n, 1000);     $n %= 1000;
    $hund  = intdiv($n, 100);      $rest = $n % 100;
    if ($crore) $parts[] = $two($crore) . ' Crore';
    if ($lakh)  $parts[] = $two($lakh) . ' Lakh';
    if ($thou)  $parts[] = $two($thou) . ' Thousand';
    if ($hund)  $parts[] = $ones[$hund] . ' Hundred';
    if ($rest)  $parts[] = $two($rest);
    return 'Taka ' . implode(' ', $parts) . ' only';
}

// ── Invigilators: per faculty with per-exam slot breakdown ───────────────────
// Unique-slot / fixed payees are billed in their own rows below — excluded here
// so nobody is paid twice. Rows are ordered by designation within a department.
$rank_sql = ei_designation_rank_sql('f.designation');
$inv_sql  = static function (string $extra_where) use ($ph, $rank_sql): string {
    return "SELECT f.id, f.name, f.designation, f.remuneration_per_slot AS rate, d.name AS dept_name,
            SUM(x.cnt) AS total_slots,
            (SUM(x.cnt) * f.remuneration_per_slot) AS total_remuneration,
            GROUP_CONCAT(
                CONCAT(x.exam_name, ' (', x.exam_year, '): ', x.cnt)
                ORDER BY x.exam_year DESC, x.exam_name ASC SEPARATOR '||'
            ) AS breakdown
     FROM (
         SELECT a.faculty_id, e.id AS exam_id, e.exam_name, e.exam_year, COUNT(a.id) AS cnt
         FROM ei_slot_attendance a
         JOIN ei_exams e ON e.id = a.exam_id
         WHERE a.attended = 1 AND a.exam_id IN ($ph)
         GROUP BY a.faculty_id, e.id, e.exam_name, e.exam_year
     ) x
     JOIN ei_faculty f ON f.id = x.faculty_id
     JOIN dept_departments d ON d.id = f.dept_id
     WHERE f.is_active = 1 {$extra_where}
     GROUP BY f.id, f.name, f.designation, f.remuneration_per_slot, d.name
     ORDER BY d.name ASC, {$rank_sql} ASC, f.name ASC";
};
try {
    $bill_st = db()->prepare($inv_sql('AND COALESCE(f.pay_by_unique_slot, 0) = 0'));
    $bill_st->execute($selected_ids);
    $inv_rows = $bill_st->fetchAll();
} catch (Throwable $e) {
    // pay_by_unique_slot column missing (migration not run yet)
    $bill_st = db()->prepare($inv_sql(''));
    $bill_st->execute($selected_ids);
    $inv_rows = $bill_st->fetchAll();
}

// ── Unique-slot payees (officials): per payee with per-exam sitting breakdown ─
$payee_rows = [];
try {
    $p_st = db()->prepare(
        "SELECT f.id, f.name, f.designation, f.remuneration_per_slot AS rate,
                f.pay_fixed, f.fixed_payment_amount, d.name AS dept_name,
                SUM(x.cnt) AS total_slots,
                COUNT(DISTINCT x.exam_id) AS exams_cnt,
                GROUP_CONCAT(
                    CONCAT(x.exam_name, ' (', x.exam_year, '): ', x.cnt)
                    ORDER BY x.exam_year DESC, x.exam_name ASC SEPARATOR '||'
                ) AS breakdown
         FROM (
             SELECT u.faculty_id, e.id AS exam_id, e.exam_name, e.exam_year,
                    COUNT(DISTINCT CONCAT(u.slot_date, '|', u.time_slot)) AS cnt
             FROM ei_unique_slot_attendance u
             JOIN ei_exams e ON e.id = u.exam_id
             WHERE u.attended = 1 AND u.exam_id IN ($ph)
             GROUP BY u.faculty_id, e.id, e.exam_name, e.exam_year
         ) x
         JOIN ei_faculty f ON f.id = x.faculty_id
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.pay_by_unique_slot = 1 AND COALESCE(f.pay_fixed, 0) = 0
         GROUP BY f.id, f.name, f.designation, f.remuneration_per_slot, f.pay_fixed, f.fixed_payment_amount, d.name
         ORDER BY d.name ASC, {$rank_sql} ASC, f.name ASC"
    );
    $p_st->execute($selected_ids);
    $payee_rows = $p_st->fetchAll();
} catch (Throwable $e) {
    // ei-fixed-payment-payees-v1.sql not run — fall back without fixed-pay columns
    try {
        $p_st = db()->prepare(
            "SELECT f.id, f.name, f.designation, f.remuneration_per_slot AS rate,
                    0 AS pay_fixed, 0 AS fixed_payment_amount, d.name AS dept_name,
                    SUM(x.cnt) AS total_slots,
                    COUNT(DISTINCT x.exam_id) AS exams_cnt,
                    GROUP_CONCAT(
                        CONCAT(x.exam_name, ' (', x.exam_year, '): ', x.cnt)
                        ORDER BY x.exam_year DESC, x.exam_name ASC SEPARATOR '||'
                    ) AS breakdown
             FROM (
                 SELECT u.faculty_id, e.id AS exam_id, e.exam_name, e.exam_year,
                        COUNT(DISTINCT CONCAT(u.slot_date, '|', u.time_slot)) AS cnt
                 FROM ei_unique_slot_attendance u
                 JOIN ei_exams e ON e.id = u.exam_id
                 WHERE u.attended = 1 AND u.exam_id IN ($ph)
                 GROUP BY u.faculty_id, e.id, e.exam_name, e.exam_year
             ) x
             JOIN ei_faculty f ON f.id = x.faculty_id
             JOIN dept_departments d ON d.id = f.dept_id
             WHERE f.pay_by_unique_slot = 1
             GROUP BY f.id, f.name, f.designation, f.remuneration_per_slot, d.name
             ORDER BY d.name ASC, {$rank_sql} ASC, f.name ASC"
        );
        $p_st->execute($selected_ids);
        $payee_rows = $p_st->fetchAll();
    } catch (Throwable $e2) {
        $payee_rows = []; // ei-unique-slot-payees-v1.sql not run yet
    }
}

// ── Build sections grouped by department / office ───────────────────────────
// Fixed payees: ALWAYS the fixed amount per exam — attendance is irrelevant.
$fixed_rows = [];
try {
    $fx_st = db()->prepare(
        "SELECT f.id, f.name, f.designation, f.fixed_payment_amount, d.name AS dept_name,
                COALESCE((SELECT COUNT(DISTINCT CONCAT(u.slot_date, '|', u.time_slot))
                          FROM ei_unique_slot_attendance u
                          WHERE u.faculty_id = f.id AND u.attended = 1 AND u.exam_id IN ($ph)), 0) AS total_slots
         FROM ei_faculty f
         JOIN dept_departments d ON d.id = f.dept_id
         WHERE f.pay_fixed = 1
         ORDER BY d.name ASC, {$rank_sql} ASC, f.name ASC"
    );
    $fx_st->execute($selected_ids);
    $fixed_rows = $fx_st->fetchAll();
} catch (Throwable $e) {
    // ei-fixed-payment-payees-v1.sql not run yet
}

// Pay each person only ONCE, in one department.
// Priority: fixed payment → invigilation (per-slot) → per-sitting official.
$paid_keys  = [];
$fixed_rows = ei_dedupe_pay_rows($fixed_rows, 'name', $paid_keys, 'fixed_payment_amount');
$inv_rows   = ei_dedupe_pay_rows($inv_rows, 'name', $paid_keys);
$payee_rows = ei_dedupe_pay_rows($payee_rows, 'name', $paid_keys, 'total_slots');

$exam_count = max(1, count($selected_exams));
$sections   = []; // dept_name => ['has_inv' => bool, 'rows' => [...], 'total' => float]

foreach ($inv_rows as $r) {
    $dept = (string)$r['dept_name'];
    $sections[$dept]['has_inv'] = true;
    $sections[$dept]['rows'][] = [
        'name'      => $r['name'],
        'desig'     => $r['designation'],
        'breakdown' => $r['breakdown'] ? explode('||', $r['breakdown']) : [],
        'slots'     => (int)$r['total_slots'],
        'rate'      => $r['rate'] > 0 ? ei_bd_money((float)$r['rate']) . '/-' : '—',
        'total'     => (float)$r['total_remuneration'],
    ];
}

foreach ($payee_rows as $r) {
    $dept  = (string)$r['dept_name'];
    $total = (int)$r['total_slots'] * (float)$r['rate'];
    $sections[$dept]['has_inv'] = $sections[$dept]['has_inv'] ?? false;
    $sections[$dept]['rows'][] = [
        'name'      => $r['name'],
        'desig'     => $r['designation'],
        'breakdown' => $r['breakdown'] ? explode('||', $r['breakdown']) : [],
        'slots'     => (int)$r['total_slots'],
        'rate'      => $r['rate'] > 0 ? ei_bd_money((float)$r['rate']) . '/-' : '—',
        'total'     => $total,
    ];
}

foreach ($fixed_rows as $r) {
    $dept = (string)$r['dept_name'];
    $sections[$dept]['has_inv'] = $sections[$dept]['has_inv'] ?? false;
    $sections[$dept]['rows'][] = [
        'name'      => $r['name'],
        'desig'     => $r['designation'],
        'breakdown' => [], // fixed pay — slots/attendance never change the amount
        'slots'     => (int)$r['total_slots'],
        'rate'      => 'Fixed',
        'total'     => (float)$r['fixed_payment_amount'] * $exam_count,
    ];
}

foreach ($sections as $dept => &$sec) {
    $sec['total'] = array_sum(array_column($sec['rows'], 'total'));
}
unset($sec);

// Academic departments (with invigilators) first, offices after; alphabetical within each
uksort($sections, static function ($a, $b) use ($sections) {
    $ai = !empty($sections[$a]['has_inv']);
    $bi = !empty($sections[$b]['has_inv']);
    if ($ai !== $bi) return $ai ? -1 : 1;
    return strcasecmp($a, $b);
});

$grand_total = array_sum(array_column($sections, 'total'));

// ── Output Word-compatible HTML as .doc ───────────────────────────────────
$filename = 'Remuneration-Approval-Memo-' . date('Y-m-d') . '.doc';
header('Content-Type: application/vnd.ms-word; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo "\xEF\xBB\xBF"; // UTF-8 BOM so Word renders ৳/Bengali-safe text correctly
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<title>Remuneration Approval Memo</title>
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->
<style>
    body { font-family: 'Times New Roman', serif; font-size: 12pt; line-height: 1.45; }
    p    { margin: 0 0 10pt; text-align: justify; }
    h3   { text-align: center; margin: 0 0 2pt; }
    .sub { font-weight: bold; text-decoration: underline; margin-bottom: 12pt; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 14pt; }
    th, td { border: 1pt solid #000; padding: 3pt 5pt; font-size: 11pt; vertical-align: top; }
    th { background: #eeeeee; text-align: center; }
    .c { text-align: center; }
    .r { text-align: right; }
    .dept-h { font-weight: bold; margin: 12pt 0 4pt; font-size: 12pt; }
    .small { font-size: 10pt; color: #333; }
    .tot { font-weight: bold; }
</style>
</head>
<body>

<h3>Prime University</h3>
<p style="text-align:center;margin-bottom:2pt;">Office of the Controller of Examinations</p>
<p style="text-align:center;" class="small">Date: <?= date('d F Y') ?></p>

<p class="sub">Subject: Approval of Remuneration for <?= h($exam_label) ?>.</p>

<p>12.&nbsp;&nbsp;<?= h($exam_label) ?> is completed. There is a practice of providing remuneration for
the HoDs, all Examination Secretary, relevant Faculty members, departmental staffs, all staffs of
Controller of Examination office, Concerned Deans and Accounts office. So far upto Summer-2025, the
bills are being processed by the individual departments. But from Spring-2026, Hon. Vice Chancellor
directed Controller of Examinations to process all bills from his office. Accordingly, the bills are
prepared and placed for kind consideration of the Authority.</p>

<p>13.&nbsp;&nbsp;From Spring-2026, Trimester policy has been adopted and examination system has been
reformed. Yet the remunerations has been proposed for the relevant persons as per previous rules
stated below:</p>

<?php $sec_no = 0; foreach ($sections as $dept => $sec): $sec_no++; ?>
<p class="dept-h"><?= $sec_no ?>. <?= h($dept) ?></p>
<table>
    <thead>
        <tr>
            <th style="width:6%;">SL.<br>No.</th>
            <th style="width:28%;">Name &amp; Designation</th>
            <th style="width:30%;">Slot</th>
            <th style="width:9%;">Total<br>Slots</th>
            <th style="width:11%;">Rate per<br>slot</th>
            <th style="width:16%;">Total<br>Remunerations</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sec['rows'] as $ri => $row): ?>
        <tr>
            <td class="c"><?= $ri + 1 ?>.</td>
            <td><?= h($row['name']) ?><?= $row['desig'] ? ',<br><span class="small">' . h($row['desig']) . '</span>' : '' ?></td>
            <td class="small">
                <?php if (empty($row['breakdown'])): ?>—<?php else: ?>
                <?php foreach ($row['breakdown'] as $bi => $piece): ?>
                <?= h(trim($piece)) ?> slot(s)<?= $bi < count($row['breakdown']) - 1 ? '<br>' : '' ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td class="c"><?= $row['slots'] ?></td>
            <td class="c"><?= h($row['rate']) ?></td>
            <td class="r"><?= ei_bd_money($row['total']) ?>/-</td>
        </tr>
    <?php endforeach; ?>
        <tr class="tot">
            <td colspan="3" class="r">Total — <?= h($dept) ?>:</td>
            <td class="c"><?= array_sum(array_column($sec['rows'], 'slots')) ?></td>
            <td></td>
            <td class="r"><?= ei_bd_money($sec['total']) ?>/-</td>
        </tr>
    </tbody>
</table>
<?php endforeach; ?>

<?php if (empty($sections)): ?>
<p><em>No attendance has been marked yet for the selected exam(s), so no remuneration rows are available.</em></p>
<?php else: ?>

<p class="dept-h" style="margin-top:18pt;">Total Remunerations of <?= h($exam_label) ?>.</p>
<table>
    <thead>
        <tr>
            <th style="width:10%;">SL. No</th>
            <th style="width:62%;">Department/Section</th>
            <th style="width:28%;">Remunerations</th>
        </tr>
    </thead>
    <tbody>
    <?php $si = 0; foreach ($sections as $dept => $sec): $si++; ?>
        <tr>
            <td class="c"><?= $si ?>.</td>
            <td><?= h($dept) ?></td>
            <td class="r"><?= ei_bd_money($sec['total']) ?>/-</td>
        </tr>
    <?php endforeach; ?>
        <tr class="tot">
            <td colspan="2" class="r">Grand Total Remunerations (TK.)</td>
            <td class="r">Tk. <?= ei_bd_money($grand_total) ?>/-</td>
        </tr>
    </tbody>
</table>

<p class="tot">In words: <?= h(ei_taka_words($grand_total)) ?>.</p>

<?php endif; ?>

<br><br>
<table style="border:none;width:100%;">
    <tr>
        <td style="border:none;width:50%;">_______________________<br>Controller of Examinations</td>
        <td style="border:none;width:50%;text-align:right;">_______________________<br>Approved by</td>
    </tr>
</table>

</body>
</html>
