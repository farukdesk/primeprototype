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
    fclose($out);
    exit;
}

/**
 * Normalise a human-entered fee-type label to an internal fee_type enum.
 */
function oebm_normalize_fee_type(string $raw): ?string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[\s_\-]+/', ' ', $s);
    $s = trim($s);
    return match ($s) {
        'admission', 'admission fee', 'admission fees'        => 'admission',
        'form', 'form fee', 'form fees'                       => 'form_fee',
        'id card', 'id card fee', 'id card fees', 'idcard'    => 'id_card_fee',
        'registration', 'registration fee', 'registration fees', 'reg', 'reg fee' => 'registration',
        default                                               => null,
    };
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
    if (!preg_match('/^\s*([a-z]+)[\s_\-.\/]*([0-9]{2,4})?\s*$/i', $raw, $m)) {
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
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    $parts = array_values(array_unique($parts));
    return implode(', ', $parts);
}

/**
 * Split a normalised receipt string into its individual receipt numbers.
 *
 * @return string[]
 */
function oebm_split_receipts(string $receipt): array
{
    $parts = array_map('trim', explode(',', $receipt));
    return array_values(array_filter($parts, static fn($p) => $p !== ''));
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
    $last_col = count($header) - 1;
    for ($i = 1; $i < $count; $i++) {
        $r = $parsed[$i];
        // The Receipt Number is typically the last column and may legitimately
        // contain several comma-separated receipt numbers. When that cell is not
        // quoted, str_getcsv spreads those numbers across extra trailing columns;
        // gather everything from the receipt column onward so none are lost.
        if ($col_receipt === $last_col && count($r) > $col_receipt + 1) {
            $receipt_raw = implode(', ', array_slice($r, $col_receipt));
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
    $counts  = ['merge' => 0, 'duplicate' => 0, 'invalid' => 0];

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

        // ── Student ─────────────────────────────────────────────────────────
        $summary = null;
        if ($sid === '') {
            $status  = 'invalid';
            $notes[] = 'Student ID is missing.';
        } else {
            if (!array_key_exists($sid, $summary_cache)) {
                $stu = acc_get_student_by_sid($sid);
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
            $notes[] = 'Unrecognised fee type "' . $fee_raw . '". Use Admission Fee, Form Fee, ID Card Fee, Registration Fee, or a month name (Jan–Dec, optionally with a year like Jan-26) for monthly tuition.';
        } elseif ($fee_type !== null) {
            $resolved['fee_type']       = $fee_type;
            $resolved['fee_type_label'] = acc_fee_type_label($fee_type);
        } else {
            // Provisional label; replaced with the concrete installment below.
            $resolved['fee_type_label'] = oebm_month_name($month_num)
                . ($month_year !== null ? ' ' . $month_year : '') . ' Tuition';
        }

        // ── Date ────────────────────────────────────────────────────────────
        $date = oebm_parse_date($date_raw);
        if ($date === null) {
            if ($status !== 'invalid') $status = 'invalid';
            $notes[] = 'Invalid date "' . $date_raw . '".';
        } else {
            $resolved['date'] = $date;
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

                $month_label = oebm_month_name($month_num)
                    . ($month_year !== null ? ' ' . $month_year : '');

                $slot_matches = static function (array $slot) use ($month_num, $month_year): bool {
                    if ($slot['cal_month'] !== $month_num) {
                        return false;
                    }
                    return $month_year === null || $slot['cal_year'] === $month_year;
                };

                $target = null;
                foreach ($slots as $k => $slot) {
                    if (!$slot['consumed'] && $slot_matches($slot)) {
                        $target = $k;
                        break;
                    }
                }

                if ($target === null) {
                    $has_month = false;
                    foreach ($slots as $slot) {
                        if ($slot_matches($slot)) { $has_month = true; break; }
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
            } elseif ($summary && $fee_type !== null) {
                $head        = $summary['totals'][$fee_type] ?? ['due' => 0, 'paid' => 0, 'out' => 0];
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

            if ($action === 'confirm') {
                // Merge only the rows that passed validation. Re-validation above
                // guarantees we never touch duplicate/invalid rows.
                $cash_account_id = acc_received_into_account_id_for_payment_method('old_erp');
                $merged = 0;
                $failed = [];
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
                        }
                    }
                    $did_commit = true;
                    $commit_summary = ['merged' => $merged, 'failed' => $failed];
                    $skipped = ($counts['duplicate'] ?? 0) + ($counts['invalid'] ?? 0);
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
                <li>Prepare a CSV with the columns <code>Student ID</code>, <code>Fee Type</code>, <code>Date</code>, <code>Amount Paid</code>, <code>Receipt Number</code>. Dates use the <strong>DD/MM/YYYY</strong> format (e.g. <code>15/01/2023</code>).</li>
                <li><code>Fee Type</code> may be <strong>Admission Fee</strong>, <strong>Form Fee</strong>, <strong>ID Card Fee</strong> or <strong>Registration Fee</strong>, or a <strong>month name</strong> (<code>Jan</code>, <code>February</code>, …) for a monthly tuition installment. Add a year to target a specific one, e.g. <code>Jan-26</code> means January 2026.</li>
                <li><strong>Monthly payments don't need to be in order.</strong> Each month is matched to the installment with the same calendar month in the student's own schedule, so out-of-order months from the old ERP are placed on the correct slot automatically.</li>
                <li>Upload it to preview. Every row is validated and colour-coded before anything is saved.</li>
                <li><strong>Duplicate receipt numbers are allowed</strong> — one historical receipt often covers several fee heads, so the same number may repeat across rows.</li>
                <li><strong>A single row may carry several receipt numbers</strong> — list them comma separated in the Receipt Number cell (e.g. <code>OLD-RCPT-1002, OLD-RCPT-1003</code>).</li>
                <li>Rows whose fee head or month is already paid in the current ERP are <strong>highlighted for manual correction</strong> — including any amount mismatch — and are never re-inserted.</li>
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
?>
<!-- ── Preview ────────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-table me-2 text-primary"></i><?= $did_commit ? 'Merge Result' : 'Preview' ?></span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-success">Ready to merge: <?= (int)$merge_count ?></span>
            <span class="badge bg-warning text-dark">Already in ERP / review: <?= (int)$dup_count ?></span>
            <span class="badge bg-danger">Invalid: <?= (int)$inv_count ?></span>
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
                            default     => 'table-danger',
                        };
                        $badge = match ($r['status']) {
                            'merge'     => '<span class="badge bg-success">Merge</span>',
                            'duplicate' => '<span class="badge bg-warning text-dark">Review</span>',
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
            Only the <strong><?= (int)$merge_count ?></strong> green row(s) will be merged. Yellow and red rows are skipped.
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
