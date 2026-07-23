<?php
/**
 * Old ERP – Bulk CSV Merge
 *
 * Bulk-merge a student's full historical (old ERP) account into the current
 * system. The admin uploads a CSV containing, per row:
 *
 *     Student ID, Fee Type, Date, Amount Paid, Receipt Number
 *
 * Dates are accepted in DD/MM/YYYY format (and several other common formats).
 * The Receipt Number cell may carry more than one receipt number, comma
 * separated (e.g. "12345, 67890"); they are captured even when the cell is not
 * quoted and listed as a single, de-duplicated reference on the payment.
 *
 * where Fee Type is either a one-time / registration head — Admission Fee,
 * Form Fee, ID Card Fee, Registration Fee — or the name of a month (Jan,
 * February, …), optionally with a year suffix such as "Jan-26" (January 2026),
 * identifying a monthly tuition installment.
 *
 * Monthly payments exported from the old ERP are frequently listed out of
 * order (e.g. a student whose schedule starts in January may have rows that
 * begin at March). Each monthly row is therefore matched to the installment
 * with the same calendar month in the student's own schedule, so the months
 * are re-ordered onto the correct slots automatically, regardless of the order
 * they appear in the file.
 *
 * The workflow is two steps:
 *   1. Upload → server validates every row and renders a colour-coded preview.
 *   2. Confirm → only the rows that passed validation are merged. Each merged
 *      row is recorded as an `old_erp` payment (a memo voucher, exactly like a
 *      single Old ERP collection on collect-payment.php).
 *
 * Unlike a single Old ERP collection, the bulk merge intentionally ALLOWS
 * duplicate receipt numbers: one historical receipt commonly bundles several
 * fee heads (e.g. Admission + Form + ID Card + Registration), so the same
 * receipt number legitimately appears on more than one row, both within the
 * file and against receipts already stored in the current ERP. Rows are only
 * blocked when the fee head itself is already paid, or the amount is invalid.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Old ERP Bulk CSV Merge';

// Fee heads this tool can merge: admission-day one-time fees, registration and
// monthly tuition (the latter supplied per row as a month name, Jan–Dec).
const OEBM_FEE_TYPES = ['admission', 'form_fee', 'id_card_fee', 'registration', 'semester_tuition'];

// Amounts within this many BDT of each other are treated as EQUAL. Old-ERP CSV
// exports frequently carry tiny 1–5 BDT rounding differences; such rows are
// counted as correct instead of being flagged as mismatches. Never raise this
// without accounting sign-off — it directly affects financial reconciliation.
const OEBM_AMOUNT_TOLERANCE = 5.0;

// ── Sample CSV template download ────────────────────────────────────────────
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="old-erp-bulk-merge-sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Fee Type', 'Date', 'Amount Paid', 'Receipt Number'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Admission Fee', '15/01/2023', '10000', 'OLD-RCPT-1001'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Form Fee', '15/01/2023', '500', 'OLD-RCPT-1001'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'ID Card Fee', '15/01/2023', '500', 'OLD-RCPT-1001'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Registration Fee', '15/01/2023', '2000', 'OLD-RCPT-1001'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Jan-23', '20/01/2023', '5000', 'OLD-RCPT-1002, OLD-RCPT-1003'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Feb-23', '18/02/2023', '5000', 'OLD-RCPT-1010'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Mar-23', '19/03/2023', '5000', 'OLD-RCPT-1021'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Re-Take Fee', '22/03/2023', '1500', 'OLD-RCPT-1025'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Special Exam (Mid Term)', '05/04/2023', '800', 'OLD-RCPT-1030'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Miscellaneous (Remedial Course)', '17/06/2026, 06/04/2026', '1200', 'OLD-RCPT-1040'], ',', '"', '\\');
    fclose($out);
    exit;
}

/**
 * Normalise a human-entered fee-type label to an internal fee_type enum.
 */
function oebm_normalize_fee_type(string $raw): ?string
{
    $s = strtolower(trim($raw));
    // Drop bracketed qualifiers such as "(Remedial Course)" and collapse any
    // run of spaces / underscores / dashes to a single space so labels written
    // as "retake_fee", "Re-Take Fee" or "special exam (final)" all normalise.
    $s = preg_replace('/[()\[\]{}]/', ' ', $s);
    $s = preg_replace('/[\s_\-]+/', ' ', $s);
    $s = trim($s);

    // One-time / registration heads (exact match).
    $exact = match ($s) {
        'admission', 'admission fee', 'admission fees'        => 'admission',
        'form', 'form fee', 'form fees'                       => 'form_fee',
        'id card', 'id card fee', 'id card fees', 'idcard'    => 'id_card_fee',
        'registration', 'registration fee', 'registration fees', 'reg', 'reg fee' => 'registration',
        default                                               => null,
    };
    if ($exact !== null) {
        return $exact;
    }

    // Additional / examination fee heads. These carry a variable amount and are
    // not part of the scheduled fee obligations, so the old ERP labels them
    // inconsistently — match them loosely on their key word(s).
    if (str_contains($s, 'retake') || str_contains($s, 're take')) {
        return 'retake_fee';
    }
    if (str_contains($s, 'improvement')) {
        return 'improvement_fee';
    }
    if (str_contains($s, 'special') && str_contains($s, 'mid')) {
        return 'special_exam_midterm';
    }
    if (str_contains($s, 'special') && str_contains($s, 'final')) {
        return 'special_exam_final';
    }
    // Remedial-course, miscellaneous and any other ad-hoc fee are collected under
    // the catch-all "other" head (the schema has no dedicated remedial type).
    if (str_contains($s, 'remedial') || str_contains($s, 'remidial')
        || str_contains($s, 'miscellaneous') || str_contains($s, 'misc')
        || $s === 'other' || $s === 'other fee' || $s === 'fee' || $s === 'fees') {
        return 'other';
    }

    return null;
}

/**
 * Fee-type labels that are old-ERP export artefacts rather than real payments.
 *
 * The old ERP export interleaves informational summary lines — notably
 * "Monthly Payment Old Data" and "Current Dues" — among the genuine receipts.
 * These are never collectable payments, so the whole row is ignored.
 */
function oebm_is_ignored_fee_type(string $raw): bool
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[()\[\]{}]/', ' ', $s);
    $s = preg_replace('/[\s_\-]+/', ' ', $s);
    $s = trim($s);
    if ($s === '') {
        return false;
    }
    return str_contains($s, 'monthly payment old data')
        || str_contains($s, 'current due');
}

/**
 * Look up a student by ID, tolerant of leading zeros.
 *
 * The old ERP and the current system sometimes store the same student ID with
 * or without leading zeros (e.g. "02826105101071" vs "2826105101071"). An exact
 * match is tried first, then a leading-zero-insensitive match on both sides so
 * the row is accepted regardless of how the zero is written.
 *
 * @return array<string,mixed>|null
 */
function oebm_lookup_student(string $sid): ?array
{
    $sid = trim($sid);
    if ($sid === '') {
        return null;
    }
    $stu = acc_get_student_by_sid($sid);
    if ($stu) {
        return $stu;
    }
    // Leading-zero-insensitive match on both the CSV value and the stored ID.
    $stmt = db()->prepare(
        "SELECT s.id, s.student_id, s.full_name, s.dept_id, s.status,
                p.id AS package_id
         FROM students s
         LEFT JOIN sfp_packages p ON p.student_id = s.id
         WHERE TRIM(LEADING '0' FROM s.student_id) = TRIM(LEADING '0' FROM ?)
         LIMIT 1"
    );
    $stmt->execute([$sid]);
    return $stmt->fetch() ?: null;
}

/**
 * Normalise a month-name fee cell to a calendar month number (1–12).
 *
 * Accepts full and abbreviated English month names (case/space-insensitive),
 * e.g. "Jan", "January", "SEPT". An optional year suffix is ignored here; use
 * oebm_parse_month_year() when the year matters. Returns null when the cell is
 * not a month.
 */
function oebm_normalize_month(string $raw): ?int
{
    $parsed = oebm_parse_month_year($raw);
    return $parsed['month'] ?? null;
}

/**
 * Parse a month-name fee cell that may carry an optional year suffix.
 *
 * Accepts plain month names ("Jan", "January") as well as month-year forms
 * such as "jan-26", "Feb-26", "Mar 2026" or "Jan/26", where a two-digit year is
 * interpreted as 20xx. Returns ['month' => 1–12, 'year' => int|null], or null
 * when the cell is not a recognised month.
 *
 * @return array{month:int, year:?int}|null
 */
function oebm_parse_month_year(string $raw): ?array
{
    static $map = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    // Split the alphabetic month token from an optional trailing year, allowing
    // common separators (space, dash, dot, slash, underscore) or none at all.
    // The year, when present, must be exactly two or four digits — a two-digit
    // year is taken as 20xx (this ERP's data is all from the current century),
    // and odd 3-digit inputs are rejected as invalid rather than guessed.
    if (!preg_match('/^\s*([a-z]+)[\s_\-.\/]*([0-9]{2}|[0-9]{4})?\s*$/i', $raw, $m)) {
        return null;
    }
    $month = $map[strtolower($m[1])] ?? null;
    if ($month === null) {
        return null;
    }

    $year = null;
    if (isset($m[2]) && $m[2] !== '') {
        $year = (int)$m[2];
        if ($year < 100) {
            // Two-digit year → 20xx (e.g. "26" → 2026).
            $year += 2000;
        }
    }
    return ['month' => $month, 'year' => $year];
}

/**
 * Human-readable month name for a calendar month number (1–12).
 */
function oebm_month_name(int $month): string
{
    static $names = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];
    return $names[$month] ?? (string)$month;
}

/**
 * Human-readable month label including an optional year (e.g. "January 2026").
 */
function oebm_format_month_label(int $month, ?int $year): string
{
    return oebm_month_name($month) . ($year !== null ? ' ' . $year : '');
}

/**
 * Absolute calendar-month index (year * 12 + month) used to compare and
 * shift calendar months across year boundaries.
 */
function oebm_month_index(int $year, int $month): int
{
    return $year * 12 + $month;
}

/**
 * Infer the calendar year of a month-name cell that carries no explicit year,
 * using the row's payment date: of the candidate years (date year ± 1), the
 * one whose month falls closest to the payment date is chosen. A December
 * installment paid in early January therefore resolves to the PREVIOUS year,
 * and a January installment paid in late December to the NEXT year.
 */
function oebm_infer_month_year(int $month_num, ?string $date): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return null;
    }
    $dy = (int)date('Y', $ts);
    $dm = (int)date('n', $ts);
    $date_idx  = oebm_month_index($dy, $dm);
    $best_year = null;
    $best_dist = PHP_INT_MAX;
    foreach ([$dy - 1, $dy, $dy + 1] as $y) {
        $dist = abs(oebm_month_index($y, $month_num) - $date_idx);
        if ($dist < $best_dist) {
            $best_dist = $dist;
            $best_year = $y;
        }
    }
    return $best_year;
}


function oebm_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd.m.Y', 'j-M-Y', 'd-M-Y', 'd M Y', 'm/d/y', 'd/m/y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $raw);
        if ($dt instanceof DateTime) {
            $errs = DateTime::getLastErrors();
            if (!$errs || ($errs['warning_count'] === 0 && $errs['error_count'] === 0)) {
                return $dt->format('Y-m-d');
            }
        }
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

/**
 * Does a cell look like a calendar date (e.g. "17/06/2026", "6-4-2026")?
 *
 * A deliberately strict day/month/year shape used to recognise a date cell —
 * tight enough never to mistake an amount ("5000") or a receipt number
 * ("OLD-RCPT-1001") for a date when recovering unquoted spilled columns.
 */
function oebm_looks_like_date(string $raw): bool
{
    return (bool)preg_match('#^\s*\d{1,4}[/.\-]\d{1,2}[/.\-]\d{1,4}\s*$#', trim($raw));
}

/**
 * Split a Date cell that may legitimately carry more than one date.
 *
 * The old ERP occasionally records two dates for a single payment, e.g.
 * "17/06/2026, 06/04/2026". This splits on commas and trims/drops blanks so the
 * caller can pick the first valid date rather than rejecting the whole row.
 *
 * @return string[]
 */
function oebm_split_dates(string $raw): array
{
    $parts = array_map('trim', explode(',', $raw));
    return array_values(array_filter($parts, static fn($p) => $p !== ''));
}

/**
 * Parse an amount cell (strips currency symbols, thousands separators).
 * Returns null when the value is not a valid positive number.
 */
function oebm_parse_amount(string $raw): ?float
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    // Strip everything except digits, decimal point and minus sign.
    $s = preg_replace('/[^0-9.\-]/', '', $s);
    if ($s === '' || $s === '-' || $s === '.' || !is_numeric($s)) {
        return null;
    }
    return (float)$s;
}

/**
 * Normalise a receipt cell that may legitimately carry several receipt numbers.
 *
 * A single historical payment is sometimes recorded against more than one
 * receipt number; in the CSV these are comma separated (e.g. "12345, 67890").
 * This splits on commas, trims each entry, drops blanks/duplicates and rejoins
 * them with a consistent ", " separator so the stored reference is clean and
 * each individual number can still be matched against existing payments.
 */
function oebm_normalize_receipt(string $raw): string
{
    return implode(', ', oebm_split_receipts($raw, true));
}

/**
 * Split a receipt string into its individual, trimmed receipt numbers.
 *
 * @param  bool $unique When true, duplicate receipt numbers are removed.
 * @return string[]
 */
function oebm_split_receipts(string $receipt, bool $unique = false): array
{
    $parts = array_map('trim', explode(',', $receipt));
    $parts = array_filter($parts, static fn($p) => $p !== '');
    if ($unique) {
        $parts = array_unique($parts);
    }
    return array_values($parts);
}

/**
 * Locate a column index from the header row by matching candidate keywords.
 *
 * @param string[] $header
 * @param string[] $needles
 */
function oebm_find_col(array $header, array $needles): ?int
{
    foreach ($header as $i => $name) {
        $n = strtolower(trim((string)$name));
        foreach ($needles as $needle) {
            if ($n === $needle || str_contains($n, $needle)) {
                return $i;
            }
        }
    }
    return null;
}

/**
 * Read CSV text into an array of associative rows keyed by our logical fields.
 *
 * @return array{rows:array<int,array<string,string>>, error:?string}
 */
function oebm_read_csv(string $csv_text): array
{
    // Normalise line endings and strip a UTF-8 BOM if present.
    $csv_text = preg_replace("/^\xEF\xBB\xBF/", '', $csv_text);
    $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);

    $lines = array_values(array_filter(explode("\n", $csv_text), static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return ['rows' => [], 'error' => 'The CSV file is empty.'];
    }

    $parsed = array_map(static fn($l) => str_getcsv($l, ',', '"', ''), $lines);
    $header = $parsed[0];

    $col_student = oebm_find_col($header, ['student id', 'studentid', 'student']);
    $col_fee     = oebm_find_col($header, ['fee type', 'feetype', 'fee head', 'fee']);
    $col_date    = oebm_find_col($header, ['date']);
    $col_amount  = oebm_find_col($header, ['amount paid', 'amount', 'paid']);
    $col_receipt = oebm_find_col($header, ['receipt number', 'receipt no', 'receipt', 'recpit', 'recipt', 'voucher no', 'voucher']);

    $missing = [];
    if ($col_student === null) $missing[] = 'Student ID';
    if ($col_fee === null)     $missing[] = 'Fee Type';
    if ($col_date === null)    $missing[] = 'Date';
    if ($col_amount === null)  $missing[] = 'Amount Paid';
    if ($col_receipt === null) $missing[] = 'Receipt Number';
    if ($missing) {
        return ['rows' => [], 'error' => 'The CSV header is missing required column(s): ' . implode(', ', $missing) . '. Expected columns: Student ID, Fee Type, Date, Amount Paid, Receipt Number.'];
    }

    $rows = [];
    $count = count($parsed);
    $header_count = count($header);
    $last_col = $header_count - 1;
    for ($i = 1; $i < $count; $i++) {
        $r = $parsed[$i];
        // The old ERP sometimes records two dates for one payment, e.g.
        // "17/06/2026, 06/04/2026". When that cell is not quoted, str_getcsv
        // splits it into extra columns, pushing Amount and Receipt to the right.
        // Collapse consecutive date-looking cells at the Date position back into
        // a single cell (kept comma-separated) and realign the trailing columns.
        if ($col_date !== null && $col_date < $last_col && count($r) > $header_count) {
            $extra  = count($r) - $header_count;
            $dates  = [trim((string)($r[$col_date] ?? ''))];
            $j      = $col_date + 1;
            while ($extra > 0 && $j < count($r) && oebm_looks_like_date((string)$r[$j])) {
                $dates[] = trim((string)$r[$j]);
                array_splice($r, $j, 1); // remove spilled cell, shifting later columns left
                $extra--;
            }
            $r[$col_date] = implode(', ', $dates);
        }
        // The Receipt Number is typically the last column and may legitimately
        // contain several comma-separated receipt numbers. When that cell is not
        // quoted, str_getcsv spreads those numbers across extra trailing columns;
        // gather everything from the receipt column onward so none are lost.
        if ($col_receipt === $last_col && count($r) > $col_receipt + 1) {
            $receipt_raw = implode(', ', array_filter(
                array_slice($r, $col_receipt),
                static fn($v) => trim((string)$v) !== ''
            ));
        } else {
            $receipt_raw = (string)($r[$col_receipt] ?? '');
        }
        $rows[] = [
            'student_id' => trim((string)($r[$col_student] ?? '')),
            'fee_type'   => trim((string)($r[$col_fee] ?? '')),
            'date'       => trim((string)($r[$col_date] ?? '')),
            'amount'     => trim((string)($r[$col_amount] ?? '')),
            'receipt'    => oebm_normalize_receipt($receipt_raw),
        ];
    }

    return ['rows' => $rows, 'error' => null];
}

/**
 * Flatten a student's fee summary into an ordered list of monthly tuition
 * installment slots, each carrying the calendar month it falls on plus the
 * amounts already paid / outstanding in the current ERP.
 *
 * Slots are returned in chronological order (semester, then month), which lets
 * the validator place an out-of-order CSV month onto the earliest matching,
 * still-unconsumed installment.
 *
 * @param  array<string,mixed> $summary  Result of acc_student_fee_summary().
 * @return array<int,array<string,mixed>>
 */
function oebm_build_month_slots(array $summary): array
{
    $slots = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $slots[] = [
                'semester_fee_id' => (int)$sem['id'],
                'semester_number' => (int)$sem['semester_number'],
                'month_number'    => (int)$mr['month_number'],
                'cal_month'       => (int)$mr['cal_month'],
                'cal_year'        => (int)$mr['cal_year'],
                'label'           => (string)$mr['month_label'],
                'paid'            => (float)$mr['paid'],
                'out'             => (float)$mr['out'],
                'consumed'        => false,
            ];
        }
    }
    return $slots;
}

/**
 * Does an installment slot match a requested calendar month (and optional year)?
 *
 * When $month_year is null only the calendar month is compared (the original
 * month-only behaviour); when supplied the slot must also fall in that year.
 *
 * @param array<string,mixed> $slot
 */
function oebm_slot_matches(array $slot, int $month_num, ?int $month_year): bool
{
    if ((int)$slot['cal_month'] !== $month_num) {
        return false;
    }
    return $month_year === null || (int)$slot['cal_year'] === $month_year;
}

/**
 * Validate and classify every CSV row.
 *
 * Each result row carries a status:
 *   - merge     : valid, will be inserted on confirm.
 *   - duplicate : already present in the current ERP, skipped (manual review).
 *   - invalid   : failed validation, skipped.
 *
 * @param array<int,array<string,string>> $rows
 * @return array{results:array<int,array<string,mixed>>, counts:array<string,int>}
 */
function oebm_validate_rows(array $rows): array
{
    $results = [];
    $counts  = ['merge' => 0, 'duplicate' => 0, 'invalid' => 0, 'ignored' => 0];

    $summary_cache = [];   // SID => fee summary (or false when not found)

    // Per-student state for monthly tuition allocation across the batch.
    $slot_state        = [];   // SID => mutable month-slot list (oebm_build_month_slots)
    $tuition_remaining = [];   // SID => outstanding tuition pool, decremented as rows consume it
    $shift_offset      = [];   // SID => uniform shift (months): + forward (pre-start) / - backward (post-start)

    // Per-student state for one-time heads and registration across the batch,
    // so a re-run or an in-file duplicate row (e.g. admission fee written twice)
    // is reconciled against BOTH the current ERP and rows merged earlier in
    // this same file — a head can never be inserted twice.
    $head_state = [];   // SID => fee_type => ['due','paid','out','erp_paid']
    $reg_state  = [];   // SID => cumulative registration timeline + semester slots

    // ── Pre-scan: earliest monthly payment per student ──────────────────────
    // A student's old-ERP payments may begin BEFORE their schedule's payment
    // start month (e.g. payments start in December while the schedule starts
    // in January) or AFTER it (e.g. the CSV starts in February with no trace
    // of January). Find each student's earliest CSV month (explicit year
    // suffix, or the year inferred from the payment date) so the whole monthly
    // series can be shifted uniformly — forward or backward — onto the
    // schedule from the start month onward, keeping its original order.
    $earliest_month_idx = [];  // SID => smallest calendar-month index among monthly rows
    foreach ($rows as $pre_row) {
        $pre_sid = trim((string)($pre_row['student_id'] ?? ''));
        $pre_fee = (string)($pre_row['fee_type'] ?? '');
        if ($pre_sid === '' || oebm_is_ignored_fee_type($pre_fee) || oebm_normalize_fee_type($pre_fee) !== null) {
            continue;
        }
        $pre_month = oebm_parse_month_year($pre_fee);
        if ($pre_month === null) {
            continue;
        }
        $pre_year = $pre_month['year'];
        if ($pre_year === null) {
            $pre_date = null;
            foreach (oebm_split_dates((string)($pre_row['date'] ?? '')) as $dp) {
                $pre_date = oebm_parse_date($dp);
                if ($pre_date !== null) {
                    break;
                }
            }
            $pre_year = oebm_infer_month_year((int)$pre_month['month'], $pre_date);
        }
        if ($pre_year === null) {
            continue;
        }
        $pre_idx = oebm_month_index($pre_year, (int)$pre_month['month']);
        if (!isset($earliest_month_idx[$pre_sid]) || $pre_idx < $earliest_month_idx[$pre_sid]) {
            $earliest_month_idx[$pre_sid] = $pre_idx;
        }
    }

    foreach ($rows as $idx => $row) {
        $row_no   = $idx + 2; // +1 header, +1 to be 1-based for humans
        $sid      = $row['student_id'];
        $fee_raw  = $row['fee_type'];
        $date_raw = $row['date'];
        $amt_raw  = $row['amount'];
        $receipt  = $row['receipt'];

        $status = 'merge';
        $notes  = [];
        $resolved = [
            'student_pk'        => null,
            'package_id'        => null,
            'student_name'      => '',
            'fee_type'          => null,
            'fee_type_label'    => $fee_raw,
            'date'              => null,
            'amount'            => null,
            'receipt'           => $receipt,
            'existing_amount'   => null,
            'semester_fee_id'   => null,
            'semester_number'   => null,
            'month_number'      => null,
            'reg_allocations'   => null,
        ];

        // Skip a completely blank line.
        if ($sid === '' && $fee_raw === '' && $date_raw === '' && $amt_raw === '' && $receipt === '') {
            continue;
        }

        // ── Rows to ignore entirely ─────────────────────────────────────────
        // The old ERP export interleaves informational lines that are not real
        // payments — "Monthly Payment Old Data" / "Current Dues" summaries and
        // zero-amount placeholder rows. These are dropped from the merge with an
        // explicit "ignored" status so the admin can see they were recognised
        // and intentionally skipped (never merged, never flagged as invalid).
        if (oebm_is_ignored_fee_type($fee_raw)) {
            $counts['ignored']++;
            $results[] = [
                'row_no'   => $row_no,
                'status'   => 'ignored',
                'notes'    => ['Fee type "' . $fee_raw . '" is an old-ERP summary line — row ignored.'],
                'input'    => $row,
                'resolved' => $resolved,
            ];
            continue;
        }
        $amount_peek = oebm_parse_amount($amt_raw);
        if ($amount_peek !== null && abs($amount_peek) < 0.001) {
            $counts['ignored']++;
            $results[] = [
                'row_no'   => $row_no,
                'status'   => 'ignored',
                'notes'    => ['Amount is zero — row ignored.'],
                'input'    => $row,
                'resolved' => $resolved,
            ];
            continue;
        }

        // ── Student ─────────────────────────────────────────────────────────
        $summary = null;
        if ($sid === '') {
            $status  = 'invalid';
            $notes[] = 'Student ID is missing.';
        } else {
            if (!array_key_exists($sid, $summary_cache)) {
                $stu = oebm_lookup_student($sid);
                if (!$stu) {
                    $summary_cache[$sid] = false;
                } else {
                    $pkg_id = (int)($stu['package_id'] ?? 0);
                    $summary_cache[$sid] = [
                        'student_pk'   => (int)$stu['id'],
                        'student_name' => (string)($stu['full_name'] ?? ''),
                        'package_id'   => $pkg_id,
                        'summary'      => $pkg_id > 0 ? acc_student_fee_summary((int)$stu['id']) : null,
                    ];
                }
            }
            $cached = $summary_cache[$sid];
            if ($cached === false) {
                $status  = 'invalid';
                $notes[] = 'No student found with this ID.';
            } else {
                $resolved['student_pk']   = $cached['student_pk'];
                $resolved['student_name'] = $cached['student_name'];
                $resolved['package_id']   = $cached['package_id'];
                if ((int)$cached['package_id'] <= 0 || !$cached['summary']) {
                    $status  = 'invalid';
                    $notes[] = 'Student has no fee package in the current system.';
                } else {
                    $summary = $cached['summary'];
                }
            }
        }

        // ── Fee type ────────────────────────────────────────────────────────
        // A fee cell is either a one-time / registration head, or the name of a
        // month (Jan, February, …) identifying a monthly tuition installment.
        $fee_type  = oebm_normalize_fee_type($fee_raw);
        $month_info = $fee_type === null ? oebm_parse_month_year($fee_raw) : null;
        $month_num  = $month_info['month'] ?? null;
        $month_year = $month_info['year'] ?? null;
        if ($fee_type === null && $month_num === null) {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Unrecognised fee type "' . $fee_raw . '". Use Admission Fee, Form Fee, ID Card Fee, Registration Fee, a month name (Jan–Dec, optionally with a year like Jan-26) for monthly tuition, or an additional fee (Re-Take, Improvement, Special Exam Mid Term/Final, Remedial/Miscellaneous, Other).';
        } elseif ($fee_type !== null) {
            $resolved['fee_type']       = $fee_type;
            $resolved['fee_type_label'] = acc_fee_type_label($fee_type);
        } else {
            // Provisional label; replaced with the concrete installment below.
            $resolved['fee_type_label'] = oebm_format_month_label($month_num, $month_year) . ' Tuition';
        }

        // ── Date ────────────────────────────────────────────────────────────
        // A Date cell may carry more than one date (e.g. "17/06/2026,
        // 06/04/2026"); use the first one that parses rather than rejecting the
        // whole row, and note when a second date was present for transparency.
        $date_parts = oebm_split_dates($date_raw);
        $date = null;
        foreach ($date_parts as $dp) {
            $date = oebm_parse_date($dp);
            if ($date !== null) {
                break;
            }
        }
        if ($date === null) {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Invalid date "' . $date_raw . '".';
        } else {
            $resolved['date'] = $date;
            if (count($date_parts) > 1) {
                $notes[] = 'Multiple dates found ("' . $date_raw . '"); used the first valid date ' . $date . '.';
            }
        }

        // ── Amount ──────────────────────────────────────────────────────────
        $amount = oebm_parse_amount($amt_raw);
        if ($amount === null) {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Invalid amount "' . $amt_raw . '".';
        } elseif ($amount <= 0) {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Amount must be greater than zero.';
        } else {
            $resolved['amount'] = round($amount, 2);
        }

        // ── Receipt number ──────────────────────────────────────────────────
        // A receipt number is required, but duplicates are intentionally
        // allowed in the bulk merge: one historical receipt often bundles
        // several fee heads, so the same number may repeat across rows.
        if ($receipt === '') {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Receipt number is required for old ERP payments.';
        }

        // ── Disambiguate mislabelled admission rows ─────────────────────────
        // The old ERP frequently lists BOTH the admission fee and the form fee
        // under the single "Admission Fee" label, so the head appears twice for
        // one student (e.g. 10,000 = real admission, 500 = form fee). Re-classify
        // by amount: a row whose amount matches the Form Fee (or ID Card Fee)
        // due — and not the admission base due — is that fee mislabelled.
        if ($fee_type === 'admission' && $summary && $resolved['amount'] !== null) {
            $amt     = (float)$resolved['amount'];
            $adm_due = (float)($summary['totals']['admission']['due'] ?? 0);
            $ff_due  = (float)($summary['totals']['form_fee']['due'] ?? 0);
            $ic_due  = (float)($summary['totals']['id_card_fee']['due'] ?? 0);
            $remapped = null;
            // Amount matching uses the CSV-rounding tolerance so a form fee
            // exported as e.g. 498 or 503 still re-classifies correctly.
            if ($ff_due > 0 && abs($amt - $ff_due) <= OEBM_AMOUNT_TOLERANCE && abs($amt - $adm_due) > OEBM_AMOUNT_TOLERANCE) {
                $remapped = 'form_fee';
            } elseif ($ic_due > 0 && abs($amt - $ic_due) <= OEBM_AMOUNT_TOLERANCE && abs($amt - $adm_due) > OEBM_AMOUNT_TOLERANCE) {
                $remapped = 'id_card_fee';
            }
            if ($remapped !== null) {
                $fee_type = $remapped;
                $resolved['fee_type']       = $remapped;
                $resolved['fee_type_label'] = acc_fee_type_label($remapped);
                $notes[] = 'Re-classified from "' . $fee_raw . '" to ' . acc_fee_type_label($remapped)
                    . ' based on the amount (' . acc_currency() . ' ' . number_format($amt, 2) . ').';
            }
        }

        // ── Existing-payment / amount-validation checks ─────────────────────
        // Only meaningful once we have a fee head/month, an amount and a package.
        //
        // Duplicate receipt numbers are allowed here, so a matching receipt in
        // the current ERP is shown for reference but never blocks the row. The
        // real duplicate guard is the per-fee-head / per-installment "already
        // paid" check below.
        if ($status !== 'invalid' && ($fee_type !== null || $month_num !== null) && $resolved['amount'] !== null) {
            $existing = null;
            foreach (oebm_split_receipts($receipt) as $rcpt) {
                $existing = acc_find_payment_by_transaction_number($rcpt);
                if ($existing) {
                    break;
                }
            }
            if ($existing) {
                $resolved['existing_amount'] = (float)$existing['amount'];
            }
            if ($summary && $month_num !== null) {
                // ── Monthly tuition installment ─────────────────────────────
                // Resolve the CSV month name to the matching calendar-month slot
                // in this student's schedule. Slots are matched by their real
                // calendar month (not by the row's position in the file), so
                // monthly payments listed out of order in the old ERP export are
                // automatically placed on the correct installment. When the CSV
                // cell also specifies a year (e.g. "Jan-26"), the slot must match
                // that calendar year too, which disambiguates schedules that span
                // the same month across two academic years.
                if (!isset($slot_state[$sid])) {
                    $slot_state[$sid]        = oebm_build_month_slots($summary);
                    $tuition_remaining[$sid] = (float)($summary['totals']['tuition']['out'] ?? 0);

                    // Schedule-alignment shift: the student's EARLIEST CSV month is
                    // aligned onto the FIRST installment of their schedule, and every
                    // monthly row is shifted by that same number of months, keeping
                    // the original order.
                    //   • Payments starting BEFORE the schedule (e.g. December while
                    //     the schedule starts in January) shift FORWARD (+offset).
                    //   • Payments starting AFTER the schedule (e.g. the CSV starts
                    //     in February with no trace of January) shift BACKWARD
                    //     (-offset) so they fill the schedule serially from the
                    //     start month — February pays January's slot, March pays
                    //     February's, and so on.
                    $shift_offset[$sid] = 0;
                    $first_slot = $slot_state[$sid][0] ?? null;
                    if ($first_slot && isset($earliest_month_idx[$sid])) {
                        $first_idx = oebm_month_index((int)$first_slot['cal_year'], (int)$first_slot['cal_month']);
                        $shift_offset[$sid] = $first_idx - $earliest_month_idx[$sid];
                    }
                }
                $slots =& $slot_state[$sid];

                $month_label = oebm_format_month_label($month_num, $month_year);

                // Apply the student's uniform schedule-alignment shift (0 for most
                // students; positive = forward for pre-start series, negative =
                // backward for a series that starts after the schedule).
                $match_month = $month_num;
                $match_year  = $month_year;
                $offset      = (int)($shift_offset[$sid] ?? 0);
                if ($offset !== 0) {
                    $direction = $offset > 0 ? 'before' : 'after';
                    $dir_word  = $offset > 0 ? 'forward' : 'backward';
                    $row_year = $month_year ?? oebm_infer_month_year($month_num, $resolved['date']);
                    if ($row_year !== null) {
                        $shifted_idx = oebm_month_index($row_year, $month_num) + $offset;
                        $match_month = ((($shifted_idx - 1) % 12) + 12) % 12 + 1;
                        $match_year  = (int)(($shifted_idx - $match_month) / 12);
                        $notes[] = 'Old-ERP payments start ' . $direction . ' this schedule: ' . oebm_format_month_label($month_num, $row_year)
                            . ' shifted ' . $dir_word . ' ' . abs($offset) . ' month(s) to '
                            . oebm_format_month_label($match_month, $match_year) . '.';
                    } else {
                        // The year could not be resolved, but the alignment shift must
                        // move EVERY month of the student's series uniformly — shift
                        // the calendar month and match it year-insensitively so no
                        // month is ever left behind on a misaligned slot.
                        $match_month = ((($month_num - 1 + $offset) % 12) + 12) % 12 + 1;
                        $match_year  = null;
                        $notes[] = 'Old-ERP payments start ' . $direction . ' this schedule: ' . oebm_month_name($month_num)
                            . ' shifted ' . $dir_word . ' ' . abs($offset) . ' month(s) to ' . oebm_month_name($match_month) . '.';
                    }
                }

                $target = null;
                foreach ($slots as $k => $slot) {
                    if (!$slot['consumed'] && oebm_slot_matches($slot, $match_month, $match_year)) {
                        $target = $k;
                        break;
                    }
                }

                if ($target === null) {
                    $has_month = false;
                    foreach ($slots as $slot) {
                        if (oebm_slot_matches($slot, $match_month, $match_year)) { $has_month = true; break; }
                    }
                    if ($has_month) {
                        $status  = 'duplicate';
                        $notes[] = 'All ' . $month_label . ' tuition installment(s) are already accounted for in the current ERP — skipped.';
                    } else {
                        $status  = 'invalid';
                        $notes[] = 'Student schedule has no ' . $month_label . ' tuition installment.';
                    }
                } else {
                    $slot = $slots[$target];
                    $resolved['fee_type']        = 'semester_tuition';
                    $resolved['fee_type_label']  = 'Tuition – ' . $slot['label'];
                    $resolved['semester_fee_id'] = $slot['semester_fee_id'];
                    $resolved['semester_number'] = $slot['semester_number'];
                    $resolved['month_number']    = $slot['month_number'];

                    $csv_amt = (float)$resolved['amount'];
                    if ($slot['out'] <= 0.001 && $slot['paid'] > 0.001) {
                        // This installment is already fully paid in the current ERP.
                        $status = 'duplicate';
                        $resolved['existing_amount'] = $slot['paid'];
                        $slots[$target]['consumed'] = true;
                        if (abs((float)$slot['paid'] - $csv_amt) <= OEBM_AMOUNT_TOLERANCE) {
                            // Identical, or off by a few BDT of CSV rounding — correct.
                            $notes[] = $slot['label'] . ' tuition is already paid in current ERP ('
                                . acc_fmt((float)$slot['paid'])
                                . (abs((float)$slot['paid'] - $csv_amt) > 0.001
                                    ? ', CSV ' . acc_fmt($csv_amt) . ' — within the ' . acc_fmt(OEBM_AMOUNT_TOLERANCE) . ' tolerance, counted as correct'
                                    : '')
                                . ') — skipped.';
                        } else {
                            $notes[] = $slot['label'] . ' tuition is already paid in current ERP ('
                                . acc_fmt((float)$slot['paid'])
                                . '), but CSV amount is ' . acc_fmt($csv_amt)
                                . ' — mismatch beyond the ' . acc_fmt(OEBM_AMOUNT_TOLERANCE)
                                . ' tolerance, manual correction needed.';
                        }
                    } elseif ((float)$slot['paid'] > 0.001 && $csv_amt <= (float)$slot['paid'] + OEBM_AMOUNT_TOLERANCE) {
                        // Partially paid, and the CSV amount adds nothing beyond the
                        // tolerance — this installment is already up to date.
                        $status = 'duplicate';
                        $resolved['existing_amount'] = $slot['paid'];
                        $slots[$target]['consumed'] = true;
                        $notes[] = $slot['label'] . ' tuition already has ' . acc_fmt((float)$slot['paid'])
                            . ' in the current ERP, covering this row\'s ' . acc_fmt($csv_amt) . ' — skipped.';
                    } else {
                        // Push forward only what is still missing on this installment,
                        // so a re-run brings the ERP up to the CSV (the latest data)
                        // without ever double-counting the part already recorded.
                        $already   = max(0.0, (float)$slot['paid']);
                        $merge_amt = round($csv_amt - $already, 2);
                        $pool      = (float)($tuition_remaining[$sid] ?? 0);
                        if ($merge_amt > $pool + OEBM_AMOUNT_TOLERANCE) {
                            // Would push tuition paid beyond the total tuition due.
                            $status  = 'invalid';
                            $notes[] = 'Amount ' . acc_fmt($merge_amt)
                                . ' exceeds the outstanding tuition of ' . acc_fmt($pool) . '.';
                        } else {
                            if ($merge_amt > $pool) {
                                // Over by a few BDT of CSV rounding — clamp, count as correct.
                                $notes[] = 'CSV amount exceeds the outstanding tuition by '
                                    . acc_fmt($merge_amt - $pool) . ' (within tolerance) — clamped to ' . acc_fmt($pool) . '.';
                                $merge_amt = round($pool, 2);
                            }
                            if ($merge_amt <= 0.001) {
                                $status = 'duplicate';
                                $slots[$target]['consumed'] = true;
                                $notes[] = 'Nothing left outstanding for ' . $slot['label'] . ' tuition — skipped.';
                            } else {
                                if ($already > 0.001) {
                                    $resolved['existing_amount'] = $already;
                                    $notes[] = acc_fmt($already) . ' is already recorded for ' . $slot['label']
                                        . ' — merging only the missing ' . acc_fmt($merge_amt)
                                        . ' to match the CSV total of ' . acc_fmt($csv_amt) . '.';
                                }
                                $resolved['amount'] = $merge_amt;
                                $slots[$target]['consumed'] = true;
                                $tuition_remaining[$sid]    = max(0.0, $pool - $merge_amt);
                                $notes[] = 'Applied to ' . $slot['label'] . ' tuition installment.';
                            }
                        }
                    }
                }
                unset($slots);
            } elseif ($summary && $fee_type !== null && (acc_is_additional_fee_type($fee_type) || $fee_type === 'other')) {
                // ── Additional / examination / miscellaneous fee ────────────
                // These carry a variable amount and have no scheduled "due", so
                // there is nothing to validate against the fee schedule and no
                // per-head duplicate guard — merge them as-is. Duplicate receipt
                // numbers remain allowed, so a matching receipt is only noted.
                $notes[] = 'Additional fee — will be recorded as ' . $resolved['fee_type_label'] . '.';
            } elseif ($summary && $fee_type === 'registration') {
                // ── Registration fee — placed by cumulative total per semester ──
                // Registration is a fixed per-semester fee (e.g. 1,000 BDT, or 500
                // BDT for masters programmes — always read from this student's own
                // package, never assumed). The TOTAL collected decides exactly
                // which semesters are covered: at 1,000/semester, 1,000 collected
                // means semester 1 is paid, 2,000 means semesters 1–2, and so on.
                // Each CSV registration row is placed on that cumulative timeline
                // — behind whatever the current ERP already holds plus earlier
                // registration rows of this same file — so on a re-run a 2nd/3rd
                // registration payment can never slip onto the wrong semester:
                // rows the ERP total already covers are skipped, a partially
                // covered row merges only the missing difference, and new rows
                // fill the next unpaid semester(s) in order.
                if (!isset($reg_state[$sid])) {
                    $reg_slots     = [];
                    $reg_due_total = 0.0;
                    foreach (($summary['semesters'] ?? []) as $sem) {
                        $sem_reg_due = (float)($sem['reg_fee'] ?? 0);
                        if ($sem_reg_due <= 0.001) {
                            continue;
                        }
                        $reg_slots[] = [
                            'semester_fee_id' => (int)$sem['id'],
                            'semester_number' => (int)$sem['semester_number'],
                            'cum_start'       => $reg_due_total,
                            'cum_end'         => $reg_due_total + $sem_reg_due,
                        ];
                        $reg_due_total += $sem_reg_due;
                    }
                    $erp_reg_paid = (float)($summary['totals']['registration']['paid'] ?? 0);
                    $reg_state[$sid] = [
                        'slots'     => $reg_slots,
                        'due_total' => $reg_due_total,
                        'erp_paid'  => $erp_reg_paid,
                        'paid_eff'  => $erp_reg_paid,   // ERP + rows merged earlier in this file
                        'csv_cum'   => 0.0,
                    ];
                }
                $rs         =& $reg_state[$sid];
                $csv_amt    = (float)$resolved['amount'];
                $span_start = (float)$rs['csv_cum'];
                $span_end   = $span_start + $csv_amt;
                $rs['csv_cum'] = $span_end;

                if ($rs['due_total'] <= 0.001) {
                    $status  = 'invalid';
                    $notes[] = 'This student has no registration fee obligation in the current system.';
                } elseif ($span_end > (float)$rs['due_total'] + OEBM_AMOUNT_TOLERANCE) {
                    $status  = 'invalid';
                    $notes[] = 'With this row the registration total in the CSV reaches ' . acc_fmt($span_end)
                        . ', which exceeds the total registration due of ' . acc_fmt((float)$rs['due_total'])
                        . ' — check for a duplicated registration row.';
                } elseif ($span_end <= (float)$rs['paid_eff'] + OEBM_AMOUNT_TOLERANCE) {
                    // Everything up to and including this row is already collected
                    // (in the current ERP or earlier in this file) — up to date.
                    $status = 'duplicate';
                    $resolved['existing_amount'] = $rs['erp_paid'];
                    $notes[] = 'Registration of ' . acc_fmt((float)$rs['paid_eff'])
                        . ' is already collected — this row\'s ' . acc_fmt($csv_amt)
                        . ' is already accounted for, skipped.';
                } else {
                    $merge_start = max($span_start, (float)$rs['paid_eff']);
                    $merge_end   = min($span_end, (float)$rs['due_total']);
                    $merge_amt   = round($merge_end - $merge_start, 2);

                    // Split the merged span across the semester(s) it falls on.
                    $allocs = [];
                    foreach ($rs['slots'] as $rslot) {
                        $a = max($merge_start, (float)$rslot['cum_start']);
                        $b = min($merge_end, (float)$rslot['cum_end']);
                        if ($b - $a > 0.001) {
                            $allocs[] = [
                                'semester_fee_id' => $rslot['semester_fee_id'],
                                'semester_number' => $rslot['semester_number'],
                                'amount'          => round($b - $a, 2),
                            ];
                        }
                    }
                    if (!$allocs || $merge_amt <= 0.001) {
                        $status = 'duplicate';
                        $resolved['existing_amount'] = $rs['erp_paid'];
                        $notes[] = 'Registration is already fully allocated — skipped.';
                    } else {
                        $rs['paid_eff'] = max((float)$rs['paid_eff'], $merge_end);
                        $resolved['amount']          = $merge_amt;
                        $resolved['reg_allocations'] = $allocs;
                        $resolved['semester_fee_id'] = $allocs[0]['semester_fee_id'];
                        $resolved['semester_number'] = $allocs[0]['semester_number'];
                        $sem_list = implode(', ', array_map(
                            static fn(array $a2): int => (int)$a2['semester_number'],
                            $allocs
                        ));
                        if ($merge_amt < $csv_amt - 0.001) {
                            $resolved['existing_amount'] = $rs['erp_paid'];
                            $notes[] = 'Part of this registration row is already collected ('
                                . acc_fmt((float)$rs['erp_paid']) . ' in total) — merging only the missing '
                                . acc_fmt($merge_amt) . ', applied to semester ' . $sem_list . '.';
                        } else {
                            $notes[] = 'Registration applied to semester ' . $sem_list . '.';
                        }
                    }
                }
                unset($rs);
            } elseif ($summary && $fee_type !== null) {
                // ── One-time admission-day head (admission / form / ID card) ────
                // Reconciled against BOTH the current ERP and the rows already
                // merged earlier in this same file, so an admission fee written
                // twice in the CSV can never be inserted twice. Amounts within the
                // CSV-rounding tolerance are counted as correct, and when the ERP
                // holds only part of the head, just the missing difference is
                // pushed forward so the total ends up matching the CSV (the
                // latest, authoritative figure).
                if (!isset($head_state[$sid][$fee_type])) {
                    $head = $summary['totals'][$fee_type] ?? null;
                    $head_state[$sid][$fee_type] = [
                        'due'      => (float)($head['due'] ?? 0),
                        'paid'     => (float)($head['paid'] ?? 0),
                        'out'      => (float)($head['out'] ?? 0),
                        'erp_paid' => (float)($head['paid'] ?? 0),
                    ];
                }
                $hs          =& $head_state[$sid][$fee_type];
                $due         = (float)$hs['due'];
                $paid        = (float)$hs['paid'];
                $outstanding = (float)$hs['out'];
                $csv_amt     = (float)$resolved['amount'];

                if ($outstanding <= 0.001 && $paid > 0.001) {
                    // Already (fully) paid — in the current ERP or by an earlier
                    // row of this same file (e.g. admission fee listed twice).
                    $status = 'duplicate';
                    $resolved['existing_amount'] = $paid;
                    if (abs($paid - $csv_amt) <= OEBM_AMOUNT_TOLERANCE) {
                        $notes[] = $resolved['fee_type_label'] . ' is already paid ('
                            . acc_fmt($paid)
                            . (abs($paid - $csv_amt) > 0.001
                                ? ', CSV ' . acc_fmt($csv_amt) . ' — within the ' . acc_fmt(OEBM_AMOUNT_TOLERANCE) . ' tolerance, counted as correct'
                                : '')
                            . ') — skipped.';
                    } else {
                        $notes[] = $resolved['fee_type_label'] . ' is already paid ('
                            . acc_fmt($paid) . '), but CSV amount is ' . acc_fmt($csv_amt)
                            . ' — mismatch beyond the ' . acc_fmt(OEBM_AMOUNT_TOLERANCE)
                            . ' tolerance, manual correction needed.';
                    }
                } elseif ($csv_amt > $due + OEBM_AMOUNT_TOLERANCE) {
                    // Amount exceeds the obligation for this head → invalid figure.
                    $status  = 'invalid';
                    $notes[] = 'Amount ' . acc_fmt($csv_amt)
                        . ' exceeds the ' . $resolved['fee_type_label'] . ' due of '
                        . acc_fmt($due) . '.';
                } elseif ($paid > 0.001 && $csv_amt <= $paid + OEBM_AMOUNT_TOLERANCE) {
                    // Already covered (within the CSV-rounding tolerance).
                    $status = 'duplicate';
                    $resolved['existing_amount'] = $paid;
                    $notes[] = $resolved['fee_type_label'] . ' already has ' . acc_fmt($paid)
                        . ' recorded, covering this row\'s ' . acc_fmt($csv_amt) . ' — skipped.';
                } else {
                    // Push forward only the part still missing so the head ends up
                    // matching the CSV total without ever double-counting.
                    $merge_amt = round(min($csv_amt - $paid, $outstanding), 2);
                    if ($merge_amt <= 0.001) {
                        $status = 'duplicate';
                        $resolved['existing_amount'] = $paid;
                        $notes[] = $resolved['fee_type_label'] . ' has nothing left outstanding — skipped.';
                    } else {
                        if ($paid > 0.001) {
                            $resolved['existing_amount'] = $paid;
                            $notes[] = acc_fmt($paid) . ' is already recorded for '
                                . $resolved['fee_type_label'] . ' — merging only the missing '
                                . acc_fmt($merge_amt) . ' to match the CSV total of ' . acc_fmt($csv_amt) . '.';
                        } elseif ($merge_amt < $csv_amt - 0.001) {
                            $notes[] = 'CSV amount exceeds the outstanding by '
                                . acc_fmt($csv_amt - $merge_amt)
                                . ' (within tolerance) — clamped to ' . acc_fmt($merge_amt) . '.';
                        }
                        $resolved['amount'] = $merge_amt;
                        $hs['paid'] = $paid + $merge_amt;
                        $hs['out']  = max(0.0, $outstanding - $merge_amt);
                    }
                }
                unset($hs);
            }
        }

        if ($status === 'merge' && !$notes) {
            $notes[] = 'Ready to merge.';
        }

        $counts[$status]++;
        $results[] = [
            'row_no'   => $row_no,
            'status'   => $status,
            'notes'    => $notes,
            'input'    => $row,
            'resolved' => $resolved,
        ];
    }

    return ['results' => $results, 'counts' => $counts];
}

/**
 * Collect the rows that did NOT merge — i.e. failed or need manual attention.
 *
 * "Failed" rows are those flagged invalid (failed validation) or duplicate
 * (already in the current ERP / needs review). Intentionally-ignored summary
 * and zero-amount rows are not failures and are excluded. Any commit-time
 * failures (rows that passed validation but threw while saving) are merged in
 * by row number, with their saved error message used as the issue.
 *
 * @param array<int,array<string,mixed>> $results        Validated rows.
 * @param array<int,string>              $commit_failures Map of row_no => message.
 * @return array<int,array{row_no:int,student_id:string,student_name:string,fee_type:string,date:string,amount:string,status:string,issue:string}>
 */
function oebm_failed_report_rows(array $results, array $commit_failures = []): array
{
    $rows = [];
    foreach ($results as $r) {
        $status = (string)$r['status'];
        $res    = $r['resolved'];
        $row_no = (int)$r['row_no'];

        $is_validation_failure = in_array($status, ['invalid', 'duplicate'], true);
        $is_commit_failure     = isset($commit_failures[$row_no]);

        if (!$is_validation_failure && !$is_commit_failure) {
            continue;
        }

        if ($is_commit_failure) {
            $status_label = 'Failed';
            $issue        = (string)$commit_failures[$row_no];
        } elseif ($status === 'duplicate') {
            $status_label = 'Already in ERP / review';
            $issue        = trim(implode(' ', $r['notes']));
        } else {
            $status_label = 'Invalid';
            $issue        = trim(implode(' ', $r['notes']));
        }
        if ($issue === '') {
            $issue = 'No further detail provided.';
        }

        $rows[] = [
            'row_no'       => $row_no,
            'student_id'   => (string)($r['input']['student_id'] ?? ''),
            'student_name' => (string)($res['student_name'] ?? ''),
            'fee_type'     => (string)($res['fee_type_label'] ?? ($r['input']['fee_type'] ?? '')),
            'date'         => (string)($res['date'] ?? ($r['input']['date'] ?? '')),
            'amount'       => $res['amount'] !== null ? number_format((float)$res['amount'], 2) : (string)($r['input']['amount'] ?? ''),
            'status'       => $status_label,
            'issue'        => $issue,
        ];
    }
    return $rows;
}

/**
 * Build a printable HTML document listing the failed / needs-attention rows,
 * for Dompdf rendering.
 *
 * @param array<int,array<string,string>> $failed_rows     From oebm_failed_report_rows().
 * @param string                          $generated_label Human date/time label.
 */
function oebm_failed_report_html(array $failed_rows, string $generated_label): string
{
    $esc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $logo_path     = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    $logo_data_uri = '';
    if (is_file($logo_path) && is_readable($logo_path)) {
        $logo_binary = file_get_contents($logo_path);
        if ($logo_binary !== false) {
            $logo_data_uri = 'data:image/png;base64,' . base64_encode($logo_binary);
        }
    }

    $rows_html = '';
    if (!$failed_rows) {
        $rows_html = '<tr><td colspan="6" style="padding:12px;border:1px solid #0f172a;text-align:center;color:#16a34a;">'
            . 'No failed rows — every row was either merged or intentionally ignored.</td></tr>';
    } else {
        foreach ($failed_rows as $i => $row) {
            $zebra = ($i % 2 === 0) ? '#ffffff' : '#f8fafc';
            $rows_html .= '<tr style="background:' . $zebra . ';">'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;text-align:center;">' . $esc($row['row_no']) . '</td>'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;font-weight:bold;">' . $esc($row['student_id']) . '</td>'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;">' . $esc($row['student_name'] !== '' ? $row['student_name'] : '—') . '</td>'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;">' . $esc($row['fee_type'] !== '' ? $row['fee_type'] : '—') . '</td>'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;">' . $esc($row['status']) . '</td>'
                . '<td style="padding:7px 9px;border:1px solid #cbd5e1;">' . $esc($row['issue']) . '</td>'
                . '</tr>';
        }
    }

    $logo_html = $logo_data_uri !== ''
        ? '<img src="' . $logo_data_uri . '" alt="Logo" style="height:42px;margin-bottom:6px;">'
        : '';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        . 'body{font-family:DejaVu Sans, sans-serif;color:#0f172a;font-size:9.5pt;margin:0;}'
        . '</style></head><body>'
        . '<div style="text-align:center;margin:0 0 14px;">'
        . $logo_html
        . '<div style="font-size:14pt;font-weight:bold;">Prime University</div>'
        . '<div style="font-size:11pt;font-weight:bold;margin-top:2px;">Old ERP Bulk Merge — Failed Rows Report</div>'
        . '<div style="font-size:8.5pt;color:#64748b;margin-top:2px;">Generated ' . $esc($generated_label)
        . ' &nbsp;|&nbsp; Total failed rows: ' . count($failed_rows) . '</div>'
        . '</div>'
        . '<table style="width:100%;border-collapse:collapse;font-size:8.5pt;">'
        . '<thead><tr style="background:#0f172a;color:#ffffff;">'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:center;width:5%;">Row</th>'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:left;width:16%;">Student ID</th>'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:left;width:18%;">Student</th>'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:left;width:15%;">Fee Type</th>'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:left;width:16%;">Status</th>'
        . '<th style="padding:8px 9px;border:1px solid #0f172a;text-align:left;width:30%;">Issue</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows_html . '</tbody>'
        . '</table>'
        . '<div style="margin:14px 0 0;font-size:8pt;color:#64748b;">These rows were not merged. Correct the listed issues in the CSV and re-upload, '
        . 'or resolve them manually in the current ERP. This is a software-generated report.</div>'
        . '</body></html>';
}

/**
 * Audit existing Old ERP payments against the uploaded CSV.
 *
 * The CSV is the authoritative old-ERP statement, so every payment stored with
 * payment method `old_erp` must be supported by it. Payments made through any
 * OTHER method (cash / bank / mobile banking) were collected in THIS ERP and
 * are always correct — they are never audited or flagged.
 *
 * A stored old-ERP payment is flagged when, for its student:
 *   • none of its receipt number(s) appear anywhere in the CSV, or
 *   • the ERP holds more against a receipt than the CSV supports (beyond the
 *     CSV-rounding tolerance).
 *
 * @param array<int,array<string,mixed>> $results Validated CSV rows.
 * @return array{flagged:array<int,array<string,mixed>>, students_checked:int, erp_total:float, csv_total:float}
 */
function oebm_audit_old_erp(array $results): array
{
    // Per-student CSV receipts and totals. The ORIGINAL CSV amounts are used
    // (not the merged top-up deltas) so already-merged rows still count as
    // fully supported.
    $students = [];
    foreach ($results as $r) {
        if ($r['status'] === 'ignored') {
            continue;
        }
        $res = $r['resolved'];
        $pk  = $res['student_pk'] ?? null;
        if (!$pk) {
            continue;
        }
        $raw_amt = oebm_parse_amount((string)($r['input']['amount'] ?? ''));
        $amt = $raw_amt !== null ? max(0.0, (float)$raw_amt) : max(0.0, (float)($res['amount'] ?? 0));
        if (!isset($students[$pk])) {
            $students[$pk] = [
                'sid'            => (string)($r['input']['student_id'] ?? ''),
                'name'           => (string)($res['student_name'] ?? ''),
                'csv_total'      => 0.0,
                'receipts'       => [],
                'receipt_totals' => [],
            ];
        }
        $students[$pk]['csv_total'] += $amt;
        foreach (oebm_split_receipts((string)$res['receipt']) as $rc) {
            $students[$pk]['receipts'][$rc] = true;
            $students[$pk]['receipt_totals'][$rc] = ($students[$pk]['receipt_totals'][$rc] ?? 0.0) + $amt;
        }
    }

    if (!$students) {
        return ['flagged' => [], 'students_checked' => 0, 'erp_total' => 0.0, 'csv_total' => 0.0];
    }

    $flagged   = [];
    $erp_total = 0.0;
    $csv_total = 0.0;

    $stmt = db()->prepare(
        "SELECT sp.id, sp.voucher_id, sp.fee_type, sp.semester_number, sp.month_number,
                sp.amount, sp.transaction_number,
                v.voucher_number, v.voucher_date, v.status AS voucher_status
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.student_id = ?
           AND sp.payment_method = 'old_erp'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         ORDER BY sp.id ASC"
    );

    foreach ($students as $pk => $info) {
        $csv_total += (float)$info['csv_total'];
        $stmt->execute([(int)$pk]);
        $pays = $stmt->fetchAll();

        // ERP totals per receipt for the over-collection check.
        $erp_receipt_totals = [];
        foreach ($pays as $p) {
            $erp_total += (float)$p['amount'];
            foreach (oebm_split_receipts((string)($p['transaction_number'] ?? '')) as $rc) {
                $erp_receipt_totals[$rc] = ($erp_receipt_totals[$rc] ?? 0.0) + (float)$p['amount'];
            }
        }

        foreach ($pays as $p) {
            $p_receipts = oebm_split_receipts((string)($p['transaction_number'] ?? ''));
            $reason = null;

            $matched = false;
            foreach ($p_receipts as $rc) {
                if (isset($info['receipts'][$rc])) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $reason = $p_receipts
                    ? 'Receipt ' . implode(', ', $p_receipts) . ' does not appear anywhere in the uploaded CSV for this student.'
                    : 'Payment has no receipt number and cannot be matched to any CSV row.';
            } else {
                // Receipt exists in the CSV — flag only when the ERP holds more
                // against it than the CSV supports (beyond the tolerance).
                foreach ($p_receipts as $rc) {
                    if (!isset($info['receipt_totals'][$rc])) {
                        continue;
                    }
                    $erp_rc = (float)($erp_receipt_totals[$rc] ?? 0);
                    $csv_rc = (float)$info['receipt_totals'][$rc];
                    if ($erp_rc > $csv_rc + OEBM_AMOUNT_TOLERANCE) {
                        $reason = 'ERP holds ' . acc_fmt($erp_rc) . ' against receipt ' . $rc
                            . ' but the CSV supports only ' . acc_fmt($csv_rc)
                            . ' — over-collected by ' . acc_fmt($erp_rc - $csv_rc) . '.';
                        break;
                    }
                }
            }

            if ($reason !== null) {
                $flagged[] = [
                    'student_sid'    => $info['sid'],
                    'student_name'   => $info['name'],
                    'payment_id'     => (int)$p['id'],
                    'voucher_id'     => (int)$p['voucher_id'],
                    'voucher_number' => (string)$p['voucher_number'],
                    'voucher_status' => (string)$p['voucher_status'],
                    'voucher_date'   => (string)$p['voucher_date'],
                    'fee_type_label' => acc_fee_type_label((string)$p['fee_type']),
                    'semester'       => $p['semester_number'] !== null ? (int)$p['semester_number'] : null,
                    'month'          => $p['month_number'] !== null ? (int)$p['month_number'] : null,
                    'amount'         => (float)$p['amount'],
                    'receipt'        => (string)($p['transaction_number'] ?? ''),
                    'reason'         => $reason,
                ];
            }
        }
    }

    return [
        'flagged'          => $flagged,
        'students_checked' => count($students),
        'erp_total'        => $erp_total,
        'csv_total'        => $csv_total,
    ];
}

$errors   = [];
$results  = null;
$counts   = null;
$csv_b64  = '';   // carried between preview and confirm
$did_commit = false;
$commit_summary = null;

// ── POST: preview or confirm ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    $csv_text = '';
    if ($action === 'preview') {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a CSV file to upload.';
        } else {
            $tmp  = $_FILES['csv_file']['tmp_name'];
            $size = (int)($_FILES['csv_file']['size'] ?? 0);
            if ($size <= 0) {
                $errors[] = 'The uploaded file is empty.';
            } elseif ($size > 5 * 1024 * 1024) {
                $errors[] = 'The CSV file is too large (max 5 MB).';
            } else {
                $csv_text = (string)file_get_contents($tmp);
            }
        }
    } elseif ($action === 'confirm') {
        $csv_text = base64_decode((string)($_POST['csv_data'] ?? ''), true) ?: '';
        if ($csv_text === '') {
            $errors[] = 'The merge session expired. Please upload the CSV again.';
        }
    } elseif ($action === 'report_pdf' || $action === 'audit_cleanup') {
        $csv_text = base64_decode((string)($_POST['csv_data'] ?? ''), true) ?: '';
        if ($csv_text === '') {
            $errors[] = 'The merge session expired. Please upload the CSV again.';
        }
    } else {
        $errors[] = 'Unknown action.';
    }

    if (!$errors && $csv_text !== '') {
        $parsed = oebm_read_csv($csv_text);
        if ($parsed['error']) {
            $errors[] = $parsed['error'];
        } elseif (!$parsed['rows']) {
            $errors[] = 'No data rows were found in the CSV (only a header was present).';
        } else {
            if ($action === 'audit_cleanup') {
                // ── Delete extra old-ERP payments picked in the audit ──────────
                // Only old-ERP MEMO vouchers where every linked payment row uses
                // the old_erp method can be removed here. Payments collected in
                // THIS ERP (cash / bank / mobile banking) are never touchable
                // through this tool. Deletion happens BEFORE re-validation so
                // the preview and audit below reflect the cleaned state.
                $audit_ids = array_values(array_unique(array_filter(
                    array_map('intval', (array)($_POST['audit_voucher_ids'] ?? [])),
                    static fn(int $v): bool => $v > 0
                )));

                if (!acc_can_delete_voucher_directly()) {
                    $errors[] = 'Only a Super Administrator can delete extra old-ERP payments.';
                } elseif (!$audit_ids) {
                    $errors[] = 'No audited payments were selected for deletion.';
                } else {
                    $chk = db()->prepare(
                        "SELECT v.status,
                                COUNT(sp.id) AS total_rows,
                                SUM(sp.payment_method = 'old_erp') AS old_erp_rows
                         FROM acc_vouchers v
                         LEFT JOIN sfp_payments sp ON sp.voucher_id = v.id
                         WHERE v.id = ? AND v.is_deleted = 0
                         GROUP BY v.id, v.status"
                    );
                    $audit_deleted  = 0;
                    $cleanup_errors = [];
                    foreach ($audit_ids as $vid) {
                        try {
                            $chk->execute([$vid]);
                            $vrow = $chk->fetch();
                            if (!$vrow) {
                                throw new RuntimeException('Voucher not found or already deleted.');
                            }
                            if ((string)$vrow['status'] !== 'memo'
                                || (int)$vrow['total_rows'] < 1
                                || (int)$vrow['old_erp_rows'] !== (int)$vrow['total_rows']) {
                                throw new RuntimeException('Not an old-ERP memo payment — refusing to delete.');
                            }
                            acc_direct_delete_voucher(
                                $vid,
                                'Old ERP bulk-merge audit: payment not supported by the latest old-ERP CSV.'
                            );
                            $audit_deleted++;
                        } catch (Throwable $e) {
                            $cleanup_errors[] = 'Voucher #' . $vid . ': ' . $e->getMessage();
                        }
                    }
                    $msg = $audit_deleted . ' extra old-ERP payment(s) deleted after audit.';
                    if ($cleanup_errors) {
                        $msg .= ' ' . count($cleanup_errors) . ' could not be deleted: ' . implode(' ', $cleanup_errors);
                    }
                    flash_set($cleanup_errors ? 'warning' : 'success', $msg);
                }
            }

            $validated = oebm_validate_rows($parsed['rows']);
            $results = $validated['results'];
            $counts  = $validated['counts'];
            $csv_b64 = base64_encode($csv_text);

            if ($action === 'report_pdf') {
                // Stream a PDF report of the failed / needs-attention rows.
                $commit_failures = [];
                $cf_raw = (string)($_POST['commit_failures'] ?? '');
                if ($cf_raw !== '') {
                    $decoded = json_decode(base64_decode($cf_raw, true) ?: '', true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            $commit_failures[(int)$k] = (string)$v;
                        }
                    }
                }
                $failed_rows = oebm_failed_report_rows($results, $commit_failures);
                require_once __DIR__ . '/../../vendor/autoload.php';
                $pdf_html = oebm_failed_report_html($failed_rows, date('d M Y, h:i A'));
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
                $dompdf->loadHtml($pdf_html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                $dompdf->stream('old-erp-bulk-merge-failed-' . date('Ymd-His') . '.pdf', ['Attachment' => true]);
                exit;
            }

            if ($action === 'confirm') {
                // Merge only the rows that passed validation. Re-validation above
                // guarantees we never touch duplicate/invalid rows.
                $cash_account_id = acc_received_into_account_id_for_payment_method('old_erp');
                $merged = 0;
                $failed = [];
                $commit_failures_map = [];
                if ($cash_account_id <= 0) {
                    $errors[] = 'Received-into (cash) account is not configured for Old ERP payments. Please set it in Accounting Settings.';
                } else {
                    foreach ($results as $r) {
                        if ($r['status'] !== 'merge') {
                            continue;
                        }
                        $res = $r['resolved'];
                        try {
                            $income_account_id = acc_income_account_id_for_fee_type($res['fee_type']);
                            if ($income_account_id <= 0) {
                                throw new RuntimeException('No income account mapped for ' . $res['fee_type_label'] . '.');
                            }
                            // A registration row may cover several semesters (one
                            // cumulative payment paying e.g. semesters 2 and 3);
                            // record one payment per semester so each semester's
                            // registration is attributed correctly. All other rows
                            // are a single portion.
                            $portions = !empty($res['reg_allocations'])
                                ? $res['reg_allocations']
                                : [[
                                    'semester_fee_id' => $res['semester_fee_id'],
                                    'semester_number' => $res['semester_number'],
                                    'amount'          => (float)$res['amount'],
                                ]];
                            foreach ($portions as $portion) {
                                acc_collect_student_fee(
                                    (int)$res['student_pk'],
                                    (int)$res['package_id'],
                                    $res['fee_type'],
                                    $portion['semester_fee_id'] !== null ? (int)$portion['semester_fee_id'] : null,
                                    $portion['semester_number'] !== null ? (int)$portion['semester_number'] : null,
                                    $res['month_number'] !== null ? (int)$res['month_number'] : null,
                                    'old_erp',
                                    null,
                                    $res['receipt'],
                                    (float)$portion['amount'],
                                    $cash_account_id,
                                    $income_account_id,
                                    $res['date'],
                                    'Old ERP bulk merge',
                                    'Old ERP receipt ' . $res['receipt'],
                                    true // allow duplicate receipt numbers in bulk merge
                                );
                            }
                            $merged++;
                        } catch (Throwable $e) {
                            $failed[] = 'Row ' . $r['row_no'] . ' (receipt ' . $res['receipt'] . '): ' . $e->getMessage();
                            $commit_failures_map[(int)$r['row_no']] = $e->getMessage();
                        }
                    }
                    $did_commit = true;
                    $commit_summary = ['merged' => $merged, 'failed' => $failed, 'failures_map' => $commit_failures_map];
                    $skipped = ($counts['duplicate'] ?? 0) + ($counts['invalid'] ?? 0) + ($counts['ignored'] ?? 0);
                    $msg = $merged . ' payment(s) merged successfully. ' . $skipped . ' row(s) were skipped (duplicates / invalid).';
                    if ($failed) {
                        $msg .= ' ' . count($failed) . ' row(s) failed during merge.';
                    }
                    flash_set($failed ? 'warning' : 'success', $msg);
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-file-csv me-2 text-success"></i>Old ERP Bulk CSV Merge
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/collect-payment.php">Collect Payment</a></li>
            <li class="breadcrumb-item active">Old ERP Bulk CSV Merge</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Collect Payment
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- ── How it works ───────────────────────────────────────────────────────── -->
<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>Merge a student's full historical account from the old ERP in bulk.</strong>
            <ol class="mb-2 mt-1 ps-3">
                <li>Prepare a CSV with the columns <code>Student ID</code>, <code>Fee Type</code>, <code>Date</code>, <code>Amount Paid</code>, <code>Receipt Number</code>. Dates use the <strong>DD/MM/YYYY</strong> format (e.g. <code>15/01/2023</code>). If a cell carries <strong>two dates</strong> (e.g. <code>17/06/2026, 06/04/2026</code>) the first valid date is used.</li>
                <li><code>Fee Type</code> may be <strong>Admission Fee</strong>, <strong>Form Fee</strong>, <strong>ID Card Fee</strong> or <strong>Registration Fee</strong>, a <strong>month name</strong> (<code>Jan</code>, <code>February</code>, …) for a monthly tuition installment (add a year to target a specific one, e.g. <code>Jan-26</code> = January 2026), or an <strong>additional fee</strong> — <strong>Re-Take</strong>, <strong>Improvement</strong>, <strong>Special Exam (Mid Term / Final)</strong>, <strong>Remedial / Miscellaneous</strong> or <strong>Other</strong> (these are recorded at the amount given, with no schedule check).</li>
                <li><strong>Monthly payments don't need to be in order.</strong> Each month is matched to the installment with the same calendar month in the student's own schedule, so out-of-order months from the old ERP are placed on the correct slot automatically.</li>
                <li><strong>Misaligned payment series are shifted automatically — in both directions.</strong> If a student's old-ERP payments begin before their schedule's payment start month (e.g. December while the schedule starts in January), the whole monthly series is shifted <strong>forward</strong>; if they begin after it (e.g. the CSV starts in February with no trace of January), the whole series is shifted <strong>backward</strong> so payments fill the schedule serially from the start month, keeping their original order. Rows merged onto wrong slots by an earlier version can be repaired with the <a class="alert-link" href="<?= APP_URL ?>/accounting/old-erp-remap.php">Old ERP remap tool</a>.</li>
                <li><strong>Old ERP payment audit on every upload.</strong> After the preview, every payment already stored with the <em>Old ERP</em> method is compared against the CSV; anything extra (receipt missing from the CSV, or more collected than the CSV shows) is flagged so a Super Administrator can delete it. Payments collected in this ERP (cash / bank / mobile banking) are trusted and never flagged.</li>
                <li>Upload it to preview. Every row is validated and colour-coded before anything is saved.</li>
                <li><strong>Duplicate receipt numbers are allowed</strong> — one historical receipt often covers several fee heads, so the same number may repeat across rows.</li>
                <li><strong>A single row may carry several receipt numbers</strong> — list them comma separated in the Receipt Number cell (e.g. <code>OLD-RCPT-1002, OLD-RCPT-1003</code>).</li>
                <li><strong>Safe to re-run.</strong> Rows already in the current ERP are skipped, and amounts differing by up to <strong>5 BDT</strong> (CSV rounding) are counted as correct. When only part of a row's amount is recorded, just the <strong>missing difference is pushed forward</strong> so totals match the CSV (the latest data). Bigger mismatches are <strong>highlighted for manual correction</strong> and never re-inserted — a fee head written twice in the file (e.g. Admission Fee) is merged once, never twice.</li>
                <li><strong>Registration fees are placed by cumulative total.</strong> Registration is a fixed per-semester fee read from the student's own package (e.g. 1,000 BDT, or 500 BDT for masters). The total already collected decides which semester comes next (1,000 = semester 1 paid, 2,000 = semesters 1–2, …), so 2nd/3rd registration rows land on the correct semester even on a re-run, and a single row spanning several semesters is split across them.</li>
                <li><strong>Summary and zero-amount rows are ignored.</strong> Old-ERP export lines such as <code>Monthly Payment Old Data</code> or <code>Current Dues</code>, and any row with an amount of <code>0</code>, are skipped automatically (shown in grey). Student IDs are matched with or without leading zeros.</li>
                <li>Confirm to merge only the valid rows. Each is stored as an <em>Old ERP</em> payment (a memo voucher), so dues update without double-counting income.</li>
            </ol>
            <a href="<?= APP_URL ?>/accounting/old-erp-bulk-merge.php?sample=1" class="alert-link">
                <i class="fas fa-download me-1"></i>Download a sample CSV template
            </a>
        </div>
    </div>
</div>

<!-- ── Upload form ────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-upload me-2 text-primary"></i>Upload Old ERP CSV
    </div>
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="col-md-8">
                <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                <div class="form-text">Columns: Student ID, Fee Type, Date, Amount Paid, Receipt Number. Max 5 MB.</div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-1"></i> Preview &amp; Validate
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
<?php
    $merge_count = $counts['merge'] ?? 0;
    $dup_count   = $counts['duplicate'] ?? 0;
    $inv_count   = $counts['invalid'] ?? 0;
    $ign_count   = $counts['ignored'] ?? 0;
?>
<!-- ── Preview ────────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-table me-2 text-primary"></i><?= $did_commit ? 'Merge Result' : 'Preview' ?></span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-success">Ready to merge: <?= (int)$merge_count ?></span>
            <span class="badge bg-warning text-dark">Already in ERP / review: <?= (int)$dup_count ?></span>
            <span class="badge bg-danger">Invalid: <?= (int)$inv_count ?></span>
            <span class="badge bg-secondary">Ignored: <?= (int)$ign_count ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($did_commit && $commit_summary): ?>
        <div class="alert alert-<?= $commit_summary['failed'] ? 'warning' : 'success' ?> m-3">
            <strong><?= (int)$commit_summary['merged'] ?></strong> payment(s) merged.
            <?php if (!empty($commit_summary['failed'])): ?>
            <div class="mt-2"><strong>Failures:</strong><ul class="mb-0"><?php foreach ($commit_summary['failed'] as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Student ID</th>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">In ERP</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $res = $r['resolved'];
                        $row_class = match ($r['status']) {
                            'merge'     => 'table-success',
                            'duplicate' => 'table-warning',
                            'ignored'   => 'table-secondary',
                            default     => 'table-danger',
                        };
                        $badge = match ($r['status']) {
                            'merge'     => '<span class="badge bg-success">Merge</span>',
                            'duplicate' => '<span class="badge bg-warning text-dark">Review</span>',
                            'ignored'   => '<span class="badge bg-secondary">Ignored</span>',
                            default     => '<span class="badge bg-danger">Invalid</span>',
                        };
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td><?= (int)$r['row_no'] ?></td>
                        <td><?= $badge ?></td>
                        <td><?= h($r['input']['student_id']) ?></td>
                        <td><?= h($res['student_name']) ?></td>
                        <td><?= h($res['fee_type_label']) ?></td>
                        <td><?= h($res['date'] ?? $r['input']['date']) ?></td>
                        <td class="text-end"><?= $res['amount'] !== null ? h(number_format((float)$res['amount'], 2)) : h($r['input']['amount']) ?></td>
                        <td class="text-end"><?= $res['existing_amount'] !== null ? h(number_format((float)$res['existing_amount'], 2)) : '—' ?></td>
                        <td class="small"><?= h(implode(' ', $r['notes'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!$did_commit): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Only the <strong><?= (int)$merge_count ?></strong> green row(s) will be merged. Yellow, red and grey rows are skipped.
        </span>
        <form method="post" onsubmit="return confirm('Merge <?= (int)$merge_count ?> valid payment(s)? Duplicates and invalid rows will be skipped.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="csv_data" value="<?= h($csv_b64) ?>">
            <button type="submit" class="btn btn-success" <?= $merge_count > 0 ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i> Confirm &amp; Merge <?= (int)$merge_count ?> Payment(s)
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php
    // ── Failed / needs-attention report ─────────────────────────────────────
    $report_commit_failures = ($did_commit && $commit_summary && !empty($commit_summary['failures_map']))
        ? $commit_summary['failures_map']
        : [];
    $failed_report_rows = oebm_failed_report_rows($results, $report_commit_failures);
    $commit_failures_b64 = $report_commit_failures
        ? base64_encode((string)json_encode($report_commit_failures))
        : '';
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-triangle-exclamation me-2 text-danger"></i>Failed Rows Report
            <span class="badge bg-danger ms-1"><?= count($failed_report_rows) ?></span>
        </span>
        <?php if ($failed_report_rows): ?>
        <form method="post" target="_blank">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="report_pdf">
            <input type="hidden" name="csv_data" value="<?= h($csv_b64) ?>">
            <input type="hidden" name="commit_failures" value="<?= h($commit_failures_b64) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-file-pdf me-1"></i> Download PDF
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (!$failed_report_rows): ?>
        <div class="alert alert-success m-3 mb-0">
            <i class="fas fa-check-circle me-1"></i> No failed rows — every row was either merged or intentionally ignored.
        </div>
        <?php else: ?>
        <div class="px-4 pt-3 small text-muted">
            The following student ID(s) could not be merged. Correct the listed issues in the CSV and re-upload, or resolve them manually.
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student ID</th>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Status</th>
                        <th>Issue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failed_report_rows as $fr): ?>
                    <tr>
                        <td><?= (int)$fr['row_no'] ?></td>
                        <td class="fw-semibold"><?= h($fr['student_id']) ?></td>
                        <td><?= h($fr['student_name'] !== '' ? $fr['student_name'] : '—') ?></td>
                        <td><?= h($fr['fee_type'] !== '' ? $fr['fee_type'] : '—') ?></td>
                        <td><?= h($fr['status']) ?></td>
                        <td class="small"><?= h($fr['issue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
    // ── Old ERP payment audit ──────────────────────────────────────────────
    // Compare the old-ERP payments already stored in this ERP against the
    // uploaded CSV (the authoritative statement). Only payments recorded with
    // the old_erp method are audited — anything collected in this ERP (cash /
    // bank / mobile banking) is trusted and never flagged.
    $audit            = oebm_audit_old_erp($results);
    $audit_flagged    = $audit['flagged'];
    $can_audit_delete = acc_can_delete_voucher_directly();
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-scale-balanced me-2 text-warning"></i>Old ERP Payment Audit
            <span class="badge <?= $audit_flagged ? 'bg-warning text-dark' : 'bg-success' ?> ms-1"><?= count($audit_flagged) ?> flagged</span>
        </span>
        <span class="small text-muted">
            <?= (int)$audit['students_checked'] ?> student(s) checked &nbsp;|&nbsp;
            Old-ERP payments in ERP: <strong><?= h(acc_fmt((float)$audit['erp_total'])) ?></strong> &nbsp;|&nbsp;
            CSV total: <strong><?= h(acc_fmt((float)$audit['csv_total'])) ?></strong>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!$audit_flagged): ?>
        <div class="alert alert-success m-3 mb-3">
            <i class="fas fa-check-circle me-1"></i> Every old-ERP payment stored for these students is supported by the uploaded CSV — nothing extra found.
        </div>
        <?php else: ?>
        <div class="px-4 pt-3 small text-muted">
            The payments below are stored with the <strong>Old ERP</strong> method but are <strong>not supported by the uploaded CSV</strong>
            (receipt missing from the CSV, or more collected than the CSV shows). Payments collected in this ERP
            (cash / bank / mobile banking) are trusted and never listed here. Review each line carefully — this is financial data —
            then delete the extras: deletion soft-removes the memo voucher (a permanent snapshot and change-log entry are kept)
            and the student's dues update immediately.
        </div>
        <form method="post" onsubmit="return confirm('Delete the selected extra old-ERP payment(s)? The memo vouchers will be soft-removed (audit trail kept) and dues will update.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="audit_cleanup">
            <input type="hidden" name="csv_data" value="<?= h($csv_b64) ?>">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;" class="text-center"><?= $can_audit_delete ? '<i class="fas fa-check-square"></i>' : '' ?></th>
                            <th>Student ID</th>
                            <th>Student</th>
                            <th>Fee Type</th>
                            <th>Sem / Month</th>
                            <th>Date</th>
                            <th>Receipt</th>
                            <th>Voucher</th>
                            <th class="text-end">Amount</th>
                            <th>Why flagged</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_flagged as $fp): ?>
                        <tr class="table-warning">
                            <td class="text-center">
                                <?php if ($can_audit_delete): ?>
                                <input type="checkbox" class="form-check-input" name="audit_voucher_ids[]" value="<?= (int)$fp['voucher_id'] ?>">
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= h($fp['student_sid']) ?></td>
                            <td><?= h($fp['student_name'] !== '' ? $fp['student_name'] : '—') ?></td>
                            <td><?= h($fp['fee_type_label']) ?></td>
                            <td class="small"><?= $fp['semester'] !== null ? 'S' . (int)$fp['semester'] : '—' ?><?= $fp['month'] !== null ? ' / M' . (int)$fp['month'] : '' ?></td>
                            <td class="small"><?= h($fp['voucher_date']) ?></td>
                            <td class="small"><?= h($fp['receipt'] !== '' ? $fp['receipt'] : '—') ?></td>
                            <td class="small"><a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= (int)$fp['voucher_id'] ?>" target="_blank" rel="noopener noreferrer"><?= h($fp['voucher_number']) ?></a></td>
                            <td class="text-end fw-semibold"><?= h(number_format((float)$fp['amount'], 2)) ?></td>
                            <td class="small"><?= h($fp['reason']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">
                    Deleting removes the <em>memo</em> voucher only (old-ERP payments are never counted in this ERP's books);
                    every deletion is recorded in the change log with a permanent snapshot.
                </span>
                <?php if ($can_audit_delete): ?>
                <button type="submit" class="btn btn-outline-danger">
                    <i class="fas fa-trash-can me-1"></i> Delete Selected Extra Payment(s)
                </button>
                <?php else: ?>
                <span class="badge bg-secondary">Only a Super Administrator can delete these</span>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
