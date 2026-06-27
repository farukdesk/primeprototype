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
            if ($ff_due > 0 && abs($amt - $ff_due) < 0.001 && abs($amt - $adm_due) > 0.001) {
                $remapped = 'form_fee';
            } elseif ($ic_due > 0 && abs($amt - $ic_due) < 0.001 && abs($amt - $adm_due) > 0.001) {
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
                }
                $slots =& $slot_state[$sid];

                $month_label = oebm_format_month_label($month_num, $month_year);

                $target = null;
                foreach ($slots as $k => $slot) {
                    if (!$slot['consumed'] && oebm_slot_matches($slot, $month_num, $month_year)) {
                        $target = $k;
                        break;
                    }
                }

                if ($target === null) {
                    $has_month = false;
                    foreach ($slots as $slot) {
                        if (oebm_slot_matches($slot, $month_num, $month_year)) { $has_month = true; break; }
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

                    if ($slot['out'] <= 0.001 && $slot['paid'] > 0.001) {
                        // This installment is already paid in the current ERP.
                        $status = 'duplicate';
                        $resolved['existing_amount'] = $slot['paid'];
                        $slots[$target]['consumed'] = true;
                        if (abs($slot['paid'] - (float)$resolved['amount']) > 0.001) {
                            $notes[] = $slot['label'] . ' tuition is already paid in current ERP ('
                                . acc_currency() . ' ' . number_format($slot['paid'], 2)
                                . '), but CSV amount is ' . acc_currency() . ' ' . number_format((float)$resolved['amount'], 2)
                                . ' — amount mismatch, manual correction needed.';
                        } else {
                            $notes[] = $slot['label'] . ' tuition is already paid in current ERP — skipped.';
                        }
                    } elseif ((float)$resolved['amount'] > ($tuition_remaining[$sid] ?? 0) + 0.001) {
                        // Would push tuition paid beyond the total tuition due.
                        $status  = 'invalid';
                        $notes[] = 'Amount ' . acc_currency() . ' ' . number_format((float)$resolved['amount'], 2)
                            . ' exceeds the outstanding tuition of '
                            . acc_currency() . ' ' . number_format((float)($tuition_remaining[$sid] ?? 0), 2) . '.';
                    } else {
                        $slots[$target]['consumed'] = true;
                        $tuition_remaining[$sid]    = max(0.0, ($tuition_remaining[$sid] ?? 0) - (float)$resolved['amount']);
                        $notes[] = 'Applied to ' . $slot['label'] . ' tuition installment.';
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
            } elseif ($summary && $fee_type !== null) {
                // One-time / registration head — validate against this head's
                // own obligation in the current ERP (admission, form fee, ID card
                // fee or registration). The grand-total per head lives under
                // $summary['totals'][$fee_type] as due / paid / out.
                $head        = $summary['totals'][$fee_type] ?? null;
                $due         = (float)($head['due'] ?? 0);
                $paid        = (float)($head['paid'] ?? 0);
                $outstanding = (float)($head['out'] ?? 0);

                if ($outstanding <= 0.001 && $paid > 0.001) {
                    // This fee head is already (fully) paid in the current ERP.
                    $status = 'duplicate';
                    $resolved['existing_amount'] = $paid;
                    if (abs($paid - (float)$resolved['amount']) > 0.001) {
                        $notes[] = $resolved['fee_type_label'] . ' is already paid in current ERP ('
                            . acc_currency() . ' ' . number_format($paid, 2)
                            . '), but CSV amount is ' . acc_currency() . ' ' . number_format((float)$resolved['amount'], 2)
                            . ' — amount mismatch, manual correction needed.';
                    } else {
                        $notes[] = $resolved['fee_type_label'] . ' is already paid in current ERP — skipped.';
                    }
                } elseif ($resolved['amount'] > $due + 0.001) {
                    // Amount exceeds the obligation for this head → invalid old-ERP figure.
                    $status  = 'invalid';
                    $notes[] = 'Amount ' . acc_currency() . ' ' . number_format((float)$resolved['amount'], 2)
                        . ' exceeds the ' . $resolved['fee_type_label'] . ' due of '
                        . acc_currency() . ' ' . number_format($due, 2) . '.';
                } elseif ($resolved['amount'] > $outstanding + 0.001) {
                    // Part of this head is already paid; the CSV amount would overpay.
                    $status  = 'invalid';
                    $notes[] = 'Amount ' . acc_currency() . ' ' . number_format((float)$resolved['amount'], 2)
                        . ' exceeds the outstanding ' . $resolved['fee_type_label'] . ' of '
                        . acc_currency() . ' ' . number_format($outstanding, 2)
                        . ' (' . acc_currency() . ' ' . number_format($paid, 2) . ' already paid in current ERP).';
                } elseif ($fee_type === 'registration') {
                    // Registration is tracked per semester. Pin the payment to the
                    // first semester that still has an outstanding registration fee
                    // so sfp_payments carries a sensible semester reference.
                    foreach (($summary['semesters'] ?? []) as $sem) {
                        if ((float)($sem['reg_out'] ?? 0) > 0.001) {
                            $resolved['semester_fee_id'] = (int)$sem['id'];
                            $resolved['semester_number'] = (int)$sem['semester_number'];
                            break;
                        }
                    }
                }
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
    } elseif ($action === 'report_pdf') {
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
                            acc_collect_student_fee(
                                (int)$res['student_pk'],
                                (int)$res['package_id'],
                                $res['fee_type'],
                                $res['semester_fee_id'] !== null ? (int)$res['semester_fee_id'] : null,
                                $res['semester_number'] !== null ? (int)$res['semester_number'] : null,
                                $res['month_number'] !== null ? (int)$res['month_number'] : null,
                                'old_erp',
                                null,
                                $res['receipt'],
                                (float)$res['amount'],
                                $cash_account_id,
                                $income_account_id,
                                $res['date'],
                                'Old ERP bulk merge',
                                'Old ERP receipt ' . $res['receipt'],
                                true // allow duplicate receipt numbers in bulk merge
                            );
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
                <li>Upload it to preview. Every row is validated and colour-coded before anything is saved.</li>
                <li><strong>Duplicate receipt numbers are allowed</strong> — one historical receipt often covers several fee heads, so the same number may repeat across rows.</li>
                <li><strong>A single row may carry several receipt numbers</strong> — list them comma separated in the Receipt Number cell (e.g. <code>OLD-RCPT-1002, OLD-RCPT-1003</code>).</li>
                <li>Rows whose fee head or month is already paid in the current ERP are <strong>highlighted for manual correction</strong> — including any amount mismatch — and are never re-inserted.</li>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
