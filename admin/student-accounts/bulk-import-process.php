<?php
/**
 * Student Accounts – Bulk PDF / CSV Import (AJAX backend)
 *
 * Accepts either:
 *   1) a ZIP file of Prime University old-ERP student payment PDFs, or
 *   2) a CSV export of old-ERP student ledgers.
 *
 * Actions (POST JSON responses):
 *   action=upload  – Accept ZIP, extract to temp dir, return {session_key, total, programs_sample}
 *   action=batch   – Process N PDFs; return {done, offset, created, skipped, failed, rows[]}
 *   action=cleanup – Remove the temp directory for the session
 *
 * PHP session is used to store the temp-dir path and manifest keyed by session_key.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../accounting/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ─────────────────────────────────────────────────────────────────────────────
// Helper: JSON response
// ─────────────────────────────────────────────────────────────────────────────

function bip_json(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bip_error(string $message): void
{
    bip_json(['success' => false, 'error' => $message]);
}

// ─────────────────────────────────────────────────────────────────────────────
// PDF text extractor – pure-PHP pass (no shell required)
// Same technique as admin/students/smart-upload.php
// ─────────────────────────────────────────────────────────────────────────────

function bip_parse_content_stream(string $content): string
{
    $text = '';
    if (preg_match_all('/BT(.*?)ET/s', $content, $bt_blocks)) {
        foreach ($bt_blocks[1] as $bt) {
            if (preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*(?:Tj|TJ|\'|\")/s', $bt, $m)) {
                foreach ($m[1] as $s) {
                    $s = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $s);
                    $s = preg_replace('/\\\\(.)/', '$1', $s);
                    $text .= $s . ' ';
                }
            }
            if (preg_match_all('/\[([^\]]*)\]\s*TJ/s', $bt, $m)) {
                foreach ($m[1] as $arr) {
                    if (preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $arr, $sm)) {
                        foreach ($sm[1] as $s) {
                            $s = preg_replace('/\\\\(.)/', '$1', $s);
                            $text .= $s;
                        }
                    }
                    $text .= ' ';
                }
            }
        }
    }
    // Hex strings
    if (preg_match_all('/<([0-9a-fA-F]+)>\s*(?:Tj|TJ)/', $content, $m)) {
        foreach ($m[1] as $hex) {
            if (strlen($hex) % 2 === 0) {
                $text .= hex2bin($hex) . ' ';
            }
        }
    }
    return $text;
}

function bip_extract_text(string $filepath): string
{
    $fh = @fopen($filepath, 'rb');
    if (!$fh) return '';
    $raw    = '';
    $budget = 10 * 1024 * 1024;
    while (!feof($fh) && strlen($raw) < $budget) {
        $raw .= fread($fh, 65536);
    }
    fclose($fh);
    if (strlen($raw) < 5) return '';

    $text   = '';
    $offset = 0;
    while (($pos = strpos($raw, 'stream', $offset)) !== false) {
        $nl_pos = strpos($raw, "\n", $pos + 6);
        if ($nl_pos === false) { $offset = $pos + 6; continue; }
        $stream_start = $nl_pos + 1;
        $end_pos = strpos($raw, 'endstream', $stream_start);
        if ($end_pos === false) { $offset = $stream_start; continue; }
        $stream_data = rtrim(substr($raw, $stream_start, $end_pos - $stream_start), "\r\n");
        $offset = $end_pos + 9;

        $decoded = @gzuncompress($stream_data);
        if ($decoded === false) $decoded = @gzinflate($stream_data);
        if ($decoded === false) $decoded = $stream_data;

        $text .= bip_parse_content_stream($decoded) . ' ';
    }

    // Try pdftotext as fallback if PHP pass yielded no useful text.
    // Only invoke pdftotext when the file is confirmed to be inside the expected
    // temp directory so that the shell argument is always a sanitised path.
    if (trim($text) === '' && function_exists('shell_exec') && function_exists('exec')) {
        // Resolve real paths to guard against symlinks / path traversal
        $real_tmp  = realpath(sys_get_temp_dir());
        $real_file = realpath($filepath);
        if ($real_file !== false && $real_tmp !== false
            && strpos($real_file, $real_tmp . DIRECTORY_SEPARATOR) === 0
        ) {
            // Locate pdftotext via `which`; use exec() for a cleaner call
            $which_out = [];
            exec('which pdftotext 2>/dev/null', $which_out);
            $pdftotext_bin = isset($which_out[0]) ? trim($which_out[0]) : '';

            if ($pdftotext_bin !== '' && is_executable($pdftotext_bin)) {
                $cmd_out = [];
                exec($pdftotext_bin . ' ' . escapeshellarg($real_file) . ' - 2>/dev/null', $cmd_out);
                $out = implode("\n", $cmd_out);
                if (trim($out) !== '') {
                    $text = $out;
                }
            }
        }
    }

    return $text;
}

// ─────────────────────────────────────────────────────────────────────────────
// Number helper: remove commas and cast
// ─────────────────────────────────────────────────────────────────────────────

function bip_num(string $s): float
{
    return (float)str_replace([',', ' '], '', $s);
}

// ─────────────────────────────────────────────────────────────────────────────
// Date normaliser: "30-12-2025" or "2026-02-17" → "YYYY-MM-DD"
// ─────────────────────────────────────────────────────────────────────────────

function bip_date(string $s): ?string
{
    $s = trim($s);
    // Already YYYY-MM-DD
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return $s;
    }
    // DD-MM-YYYY or D-M-YYYY
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// PDF data parser – specific to Prime University payment statement format
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Parse payment statement text extracted from a Prime University ERP PDF.
 *
 * @return array{
 *   student_name: string,
 *   program_name: string,
 *   beginning_semester: string,
 *   total_semesters: int,
 *   admission_fee: float,
 *   registration_fee_total: float,
 *   english_course_fee: float,
 *   tuition_full: float,
 *   fixed_institutional_fees: float,
 *   concession: float,
 *   monthly_payment: float,
 *   transactions: array
 * }
 */
function bip_parse_pdf_text(string $text): array
{
    // Normalise whitespace but preserve newlines for line-based parsing
    $text = preg_replace('/\r\n?/', "\n", $text);
    // Replace multiple spaces/tabs with single space within lines
    $text = preg_replace('/[ \t]+/', ' ', $text);

    $data = [
        'student_name'             => '',
        'program_name'             => '',
        'beginning_semester'       => '',
        'total_semesters'          => 0,
        'admission_fee'            => 0.0,
        'registration_fee_total'   => 0.0,
        'english_course_fee'       => 0.0,
        'tuition_full'             => 0.0,
        'fixed_institutional_fees' => 0.0,
        'concession'               => 0.0,
        'monthly_payment'          => 0.0,
        'transactions'             => [],
    ];

    // ── Student Name ─────────────────────────────────────────────────────────
    if (preg_match('/Student Name\s+([^\n\t]+?)\s+ID No/i', $text, $m)) {
        $data['student_name'] = trim($m[1]);
    }

    // ── Program name – text between "Program" and "Batch" ───────────────────
    if (preg_match('/\bProgram\b\s+([^\n\t]+?)\s+Batch/i', $text, $m)) {
        $data['program_name'] = trim($m[1]);
    }

    // ── Beginning Semester ───────────────────────────────────────────────────
    if (preg_match('/Beg[ia]n+ing Semester\s+([^\n\t]+?)\s+Total Semesters/i', $text, $m)) {
        $data['beginning_semester'] = trim($m[1]);
    }

    // ── Total Semesters ──────────────────────────────────────────────────────
    if (preg_match('/Total Semesters\s+(\d+)/i', $text, $m)) {
        $data['total_semesters'] = (int)$m[1];
    }

    // ── Admission Fee ────────────────────────────────────────────────────────
    // Must match "Admission Fee" NOT preceded by "Admission Form"
    if (preg_match('/(?<!Form &amp; ID Card )Admission Fee\s+([\d,]+)/i', $text, $m)) {
        $data['admission_fee'] = bip_num($m[1]);
    } elseif (preg_match('/Admission Fee\s+([\d,]+)/i', $text, $m)) {
        $data['admission_fee'] = bip_num($m[1]);
    }

    // ── Registration Fee ─────────────────────────────────────────────────────
    if (preg_match('/Registration Fee\s+([\d,]+)/i', $text, $m)) {
        $data['registration_fee_total'] = bip_num($m[1]);
    }

    // ── English Language Course Fee ──────────────────────────────────────────
    if (preg_match('/English Language Course Fee\s+([\d,]+)/i', $text, $m)) {
        $data['english_course_fee'] = bip_num($m[1]);
    }

    // ── Tuition Fee/Credit ───────────────────────────────────────────────────
    if (preg_match('/Tuition Fee\s*\/\s*Credit\s+([\d,]+)/i', $text, $m)) {
        $data['tuition_full'] = bip_num($m[1]);
    }

    // ── Miscellaneous/Semester Fee (= fixed institutional fees) ─────────────
    if (preg_match('/Miscellaneous\s*[\/\s]+Semester Fee\s+([\d,]+)/i', $text, $m)) {
        $data['fixed_institutional_fees'] = bip_num($m[1]);
    }

    // ── Concession ───────────────────────────────────────────────────────────
    if (preg_match('/Concession\s+([\d,]+)/i', $text, $m)) {
        $data['concession'] = bip_num($m[1]);
    }

    // ── Monthly Payment ──────────────────────────────────────────────────────
    if (preg_match('/Monthly Payment\s+([\d,]+)/i', $text, $m)) {
        $data['monthly_payment'] = bip_num($m[1]);
    }

    // ── Transaction History ──────────────────────────────────────────────────
    $transactions = [];

    // ── Admission Fee payment ────────────────────────────────────────────────
    // Pattern: "Admission Fee <payable_amount> <date> <receipt> <received_amount>"
    // Looking in the transaction table section (after "Transaction History" header)
    $tx_section = '';
    if (preg_match('/Transaction History(.*)/si', $text, $m)) {
        $tx_section = $m[1];
    }

    if ($tx_section !== '') {
        // One-time fees: Admission Fee and Registration Fee rows
        // Format (on one or more lines): HeadOfAC  PayableAmount  Date  ReceiptNo  ReceivedAmount  [LateFine]  DueAmount
        foreach (['Admission Fee', 'Registration Fee'] as $head) {
            $pattern = '/' . preg_quote($head, '/') . '\s+'
                . '([\d,]+)\s+'                         // payable amount
                . '(\d{1,2}-\d{1,2}-\d{4}|\d{4}-\d{2}-\d{2})\s+'  // date
                . '(\d+)\s+'                             // receipt no
                . '([\d,]+)/i';                          // received amount
            if (preg_match($pattern, $tx_section, $m)) {
                $date = bip_date($m[2]);
                if ($date) {
                    $transactions[] = [
                        'head'     => $head,
                        'payable'  => bip_num($m[1]),
                        'date'     => $date,
                        'receipt'  => $m[3],
                        'received' => bip_num($m[4]),
                        'fee_type' => ($head === 'Admission Fee') ? 'admission' : 'registration',
                    ];
                }
            }
        }

        // ── Monthly payments ─────────────────────────────────────────────────
        // Month labels: January-2026, February-2026 … December-2026, or cross-year
        $month_names = 'January|February|March|April|May|June|July|August|September|October|November|December';
        // Capture: MonthName-YEAR  payable  [multiple date/receipt pairs]  received
        // Since multiple receipts can appear for one month, we take the total received
        $month_pattern = '/(' . $month_names . ')-(\d{4})\s+'
            . '([\d,]+)\s+'         // payable
            . '[\s\S]+?'            // dates and receipt numbers (greedy-safe)
            . '([\d,]+)\s+'         // received (last big number before 0  / Late Fine / Due Amount)
            . '(\d+)\s+'            // late fine
            . '(\d+)/i';            // due amount

        // Simpler line-by-line approach for monthly rows
        $lines = explode("\n", $tx_section);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(' . $month_names . ')-(\d{4})/i', $line, $hm)) {
                $month_label = $hm[1] . '-' . $hm[2];

                // Extract all dates from this line (there may be 1 or 2)
                preg_match_all('/(\d{1,2}-\d{1,2}-\d{4}|\d{4}-\d{2}-\d{2})/', $line, $dates);
                // Use the latest (last) date for this entry
                $raw_dates = $dates[1] ?? [];
                $payment_date = null;
                foreach (array_reverse($raw_dates) as $rd) {
                    $converted = bip_date($rd);
                    if ($converted) { $payment_date = $converted; break; }
                }

                // Extract all receipt numbers (5–6 digit numbers)
                preg_match_all('/\b(\d{5,7})\b/', $line, $receipts);
                $receipt_no = $receipts[1][count($receipts[1]) - 1] ?? '';

                // Extract all monetary amounts (numbers possibly with commas)
                preg_match_all('/([\d,]{3,})/', $line, $amounts);
                $amt_vals = array_map('bip_num', $amounts[1] ?? []);
                // received is the amount after the receipt numbers / dates
                // Strategy: take the amount that appears after the last receipt number
                $received = 0.0;
                if (!empty($amt_vals)) {
                    // Last amount before the trailing 0 0 (late fine / due) should be received
                    // Typically: PAYABLE  [date+receipt pairs]  RECEIVED  LATE_FINE  DUE
                    // Filter to meaningful amounts (ignore year-like values 2025/2026)
                    $meaningful = array_values(array_filter($amt_vals, function ($v) {
                        return $v >= 100 && ($v < 2000 || $v > 2100);
                    }));
                    if (!empty($meaningful)) {
                        // First = payable, last = received (or last non-zero)
                        $received = end($meaningful);
                    }
                }

                if ($payment_date && $received > 0) {
                    $transactions[] = [
                        'head'       => $month_label,
                        'payable'    => bip_num($amounts[1][0] ?? '0'),
                        'date'       => $payment_date,
                        'receipt'    => $receipt_no,
                        'received'   => $received,
                        'fee_type'   => 'semester_tuition',
                        'month_label'=> $month_label,
                    ];
                }
            }
        }
    }

    $data['transactions'] = $transactions;
    return $data;
}

// ─────────────────────────────────────────────────────────────────────────────
// Import one student from parsed PDF data
// Returns ['status'=>'created'|'skipped'|'failed', 'message'=>..., 'student_name'=>...]
// ─────────────────────────────────────────────────────────────────────────────

function bip_import_student(
    string $student_sid,
    array  $pdf,
    bool   $overwrite,
    int    $cash_account_id,
    int    $income_account_id,
    int    $user_id
): array {
    $db = db();

    // ── 1. Find student ──────────────────────────────────────────────────────
    $s_stmt = $db->prepare('SELECT * FROM students WHERE student_id = ? LIMIT 1');
    $s_stmt->execute([$student_sid]);
    $student = $s_stmt->fetch();
    if (!$student) {
        return ['status' => 'failed', 'message' => 'Student not found in DB (ID: ' . $student_sid . ')'];
    }

    $student_db_id = (int)$student['id'];

    // ── 2. Check existing package ────────────────────────────────────────────
    $dup = $db->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
    $dup->execute([$student_db_id]);
    $existing_pkg_id = $dup->fetchColumn();

    if ($existing_pkg_id && !$overwrite) {
        return [
            'status'  => 'skipped',
            'message' => 'Package already exists (overwrite disabled)',
            'student_name' => $student['full_name'],
        ];
    }

    // If overwrite: delete existing package (cascade deletes semester_fees, payments, scholarships)
    if ($existing_pkg_id && $overwrite) {
        $db->prepare('DELETE FROM sfp_packages WHERE id = ?')->execute([$existing_pkg_id]);
    }

    // ── 3. Derive program constants ──────────────────────────────────────────
    $total_semesters = $pdf['total_semesters'] ?: 8;
    $tuition_full    = $pdf['tuition_full'];
    $fixed_fees      = $pdf['fixed_institutional_fees'];
    $english_fee     = $pdf['english_course_fee'];
    $admission_fee   = $pdf['admission_fee'];
    $reg_fee_total   = $pdf['registration_fee_total'];
    $concession      = $pdf['concession'];
    $monthly_payment = $pdf['monthly_payment'];
    $program_name    = $pdf['program_name'] ?: 'Unknown Programme';

    // Compute total_months from monthly payment when possible
    // monthly = (payable - admission - reg_total) / total_months
    $payable_total = $tuition_full + $fixed_fees + $english_fee - $concession;
    $monthly_base  = $payable_total - $admission_fee - $reg_fee_total;
    $total_months  = ($monthly_payment > 0 && $monthly_base > 0)
        ? (int)round($monthly_base / $monthly_payment)
        : $total_semesters * 6;

    if ($total_months <= 0) {
        $total_months = $total_semesters * 6;
    }

    $months_per_semester = $total_semesters > 0
        ? round($total_months / $total_semesters, 2)
        : 6.0;

    $tuition_per_semester = $total_semesters > 0
        ? round($tuition_full / $total_semesters, 2)
        : $tuition_full;

    $reg_fee_per_semester = $total_semesters > 0
        ? round($reg_fee_total / $total_semesters, 2)
        : 0.0;

    // Try to match cf_program
    $cf_program_id = null;
    if ($program_name !== '') {
        $prog_stmt = $db->prepare(
            'SELECT id FROM cf_programs
             WHERE program_name LIKE ? OR program_slug LIKE ?
             LIMIT 1'
        );
        $prog_stmt->execute(['%' . $program_name . '%', '%' . strtolower(str_replace(' ', '-', $program_name)) . '%']);
        $cf_program_id = $prog_stmt->fetchColumn() ?: null;
    }

    // ── 4. Insert package ────────────────────────────────────────────────────
    $monthly_fixed_fee   = $total_months > 0 ? round($fixed_fees / $total_months, 4) : 0;
    $monthly_english_fee = $total_months > 0 ? round($english_fee / $total_months, 4) : 0;

    $db->prepare(
        'INSERT INTO sfp_packages
           (student_id, cf_program_id, program_name,
            total_semesters, total_months, months_per_semester,
            standard_tuition_full, tuition_per_semester, admission_fees,
            fixed_institutional_fees, english_course_fee,
            reg_fee_per_semester, form_id_fee,
            safety_net_cap, safety_net_per_semester,
            attendance_requirement, safety_net_gpa_threshold,
            monthly_fixed_fee, monthly_english_fee,
            note, assigned_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $student_db_id,
        $cf_program_id,
        $program_name,
        $total_semesters,
        $total_months,
        $months_per_semester,
        (int)$tuition_full,
        $tuition_per_semester,
        (int)$admission_fee,
        (int)$fixed_fees,
        (int)$english_fee,
        $reg_fee_per_semester,
        0,    // form_id_fee – not in old ERP breakdown, set to 0
        null, // safety_net_cap
        null, // safety_net_per_semester
        70,   // attendance_requirement default
        3.00, // safety_net_gpa_threshold default
        $monthly_fixed_fee,
        $monthly_english_fee,
        'Imported from old ERP (bulk PDF import)',
        $user_id,
    ]);
    $package_id = (int)$db->lastInsertId();

    // ── 5. Generate semester fee rows ────────────────────────────────────────
    sfp_generate_semester_fees($package_id, $total_semesters, $tuition_per_semester);

    // ── 6. Apply concession as scholarship on all semesters ─────────────────
    if ($concession > 0 && $total_semesters > 0) {
        $sf_rows = sfp_get_semester_fees($package_id);
        $concession_per_sem = round($concession / $total_semesters, 2);

        $sc_insert = $db->prepare(
            'INSERT INTO sfp_semester_scholarships
               (sf_id, label, discount_pct, discount_type, fixed_amount, amount,
                is_from_policy, applies_to_fixed, applies_to_english,
                support_doc_id, note, created_by)
             VALUES (?, ?, 0, \'fixed\', ?, ?, 0, 0, 0, NULL, ?, ?)'
        );

        foreach ($sf_rows as $sf) {
            $cap    = min($concession_per_sem, (float)$sf['tuition_fee']);
            $sc_insert->execute([
                (int)$sf['id'],
                'ERP Concession',
                $concession_per_sem,
                $cap,
                'Imported from old ERP',
                $user_id,
            ]);
            sfp_recalculate_semester((int)$sf['id'], $user_id);
        }
    }

    // ── 7. Import transaction history ────────────────────────────────────────
    // Build semester map for monthly transaction linkage
    $sf_rows = sfp_get_semester_fees($package_id);
    $sf_by_sem = [];
    foreach ($sf_rows as $sf) {
        $sf_by_sem[(int)$sf['semester_number']] = (int)$sf['id'];
    }

    $months_int = max(1, (int)round($months_per_semester));

    // Determine start month from "Beginning Semester" or admission semester
    $start_month = 1;
    $start_year  = (int)date('Y');
    $beg = $pdf['beginning_semester'] ?: ($student['admitted_semester'] ?? '');
    if (preg_match('/\b(\d{4})\b/', $beg, $ym)) {
        $start_year = (int)$ym[1];
    }
    // Try to map month name from semester label
    $sem_month_map = [
        'spring' => 1, 'summer' => 5, 'fall' => 9,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
        'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];
    foreach ($sem_month_map as $keyword => $mnum) {
        if (stripos($beg, $keyword) !== false) {
            $start_month = $mnum;
            break;
        }
    }

    $month_names_map = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    foreach ($pdf['transactions'] as $tx) {
        $amount = (float)$tx['received'];
        if ($amount <= 0) continue;

        $fee_type        = $tx['fee_type'];
        $date            = $tx['date'] ?? date('Y-m-d');
        $receipt         = $tx['receipt'] ?? '';
        $semester_fee_id = null;
        $semester_number = null;
        $month_number    = null;

        if ($fee_type === 'semester_tuition') {
            // Determine which semester/month from the label, e.g. "January-2026"
            $parts = explode('-', $tx['month_label'] ?? '');
            if (count($parts) === 2) {
                $m_name = strtolower(trim($parts[0]));
                $m_year = (int)trim($parts[1]);
                $m_num  = $month_names_map[$m_name] ?? 0;
                if ($m_num > 0) {
                    // Calculate offset from start
                    $start_serial = ($start_year - 1) * 12 + $start_month;
                    $this_serial  = ($m_year - 1) * 12 + $m_num;
                    $offset       = $this_serial - $start_serial; // 0-based
                    if ($offset >= 0) {
                        $sem_idx      = (int)floor($offset / $months_int) + 1; // 1-based
                        $month_number = ($offset % $months_int) + 1;            // 1-based within sem
                        if (isset($sf_by_sem[$sem_idx])) {
                            $semester_fee_id = $sf_by_sem[$sem_idx];
                            $semester_number = $sem_idx;
                        }
                    }
                }
            }
        }

        // Post receipt voucher
        $month_part = isset($tx['month_label']) && $tx['month_label'] !== ''
            ? ' ' . $tx['month_label'] : '';
        $receipt_part = $receipt !== '' ? ' [Rcpt:' . $receipt . ']' : '';
        $narration = 'ERP Import: ' . ucfirst(str_replace('_', ' ', $fee_type))
            . $month_part . $receipt_part;

        try {
            $voucher_id = acc_post_voucher('receipt', $date, [
                ['account_id' => $cash_account_id,   'debit' => $amount, 'credit' => 0,       'description' => $narration],
                ['account_id' => $income_account_id, 'debit' => 0,       'credit' => $amount, 'description' => $narration],
            ], $narration, $receipt ? 'ERP-' . $receipt : '');
        } catch (Throwable $e) {
            // Log and skip this payment; don't abort the whole student import
            error_log('bip_import_student payment error for ' . $student_sid . ': ' . $e->getMessage());
            continue;
        }

        $db->prepare(
            'INSERT INTO sfp_payments
               (student_id, package_id, semester_fee_id, fee_type, semester_number, month_number,
                payment_method, mobile_banking_provider, transaction_number,
                amount, voucher_id, note, collected_by)
             VALUES (?,?,?,?,?,?,\'cash\',NULL,?,?,?,?,?)'
        )->execute([
            $student_db_id,
            $package_id,
            $semester_fee_id,
            $fee_type,
            $semester_number,
            $month_number,
            $receipt ?: null,
            round($amount, 2),
            $voucher_id,
            'Imported from old ERP',
            $user_id,
        ]);
    }

    log_change(
        'student-accounts',
        'CREATE',
        $package_id,
        ($student['full_name'] ?? $student_sid) . ' – ' . $program_name,
        null, null, null,
        'Package imported from old ERP bulk upload.'
    );

    return [
        'status'       => 'created',
        'message'      => 'Imported successfully',
        'student_name' => $student['full_name'],
        'package_id'   => $package_id,
    ];
}

function bip_pick_last_date(string $value): ?string
{
    if (preg_match_all('/\d{4}-\d{2}-\d{2}|\d{1,2}-\d{1,2}-\d{4}/', $value, $matches)) {
        foreach (array_reverse($matches[0]) as $candidate) {
            $date = bip_date($candidate);
            if ($date !== null) {
                return $date;
            }
        }
    }

    return null;
}

function bip_pick_last_token(string $value): string
{
    $parts = preg_split('/\s+/', trim($value)) ?: [];
    if (empty($parts)) {
        return '';
    }

    return (string)end($parts);
}

function bip_parse_csv_transactions(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $month_names = 'January|February|March|April|May|June|July|August|September|October|November|December';
    $transactions = [];

    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $head = trim((string)($row['Head'] ?? ''));
        if ($head === '') {
            continue;
        }

        $date = bip_pick_last_date((string)($row['Date'] ?? ''));
        $received = bip_num((string)($row['Received'] ?? '0'));
        if ($date === null || $received <= 0) {
            continue;
        }

        $tx = [
            'head'     => $head,
            'payable'  => bip_num((string)($row['Payable'] ?? '0')),
            'date'     => $date,
            'receipt'  => bip_pick_last_token((string)($row['Receipt'] ?? '')),
            'received' => $received,
        ];

        if (preg_match('/^(' . $month_names . ')-\d{4}$/i', $head)) {
            $tx['fee_type'] = 'semester_tuition';
            $tx['month_label'] = $head;
            $transactions[] = $tx;
            continue;
        }

        if (strcasecmp($head, 'Admission Fee') === 0) {
            $tx['fee_type'] = 'admission';
            $transactions[] = $tx;
            continue;
        }

        if (strcasecmp($head, 'Registration Fee') === 0) {
            $tx['fee_type'] = 'registration';
            $transactions[] = $tx;
        }
    }

    return $transactions;
}

function bip_parse_csv_row(array $row): ?array
{
    $student_sid = trim((string)($row['Student ID'] ?? ''));
    if ($student_sid === '') {
        return null;
    }

    $total_semesters = (int)preg_replace('/\D+/', '', (string)($row['Total Semesters'] ?? '0'));

    return [
        'student_name'             => trim((string)($row['Student Name'] ?? '')),
        'program_name'             => trim((string)($row['Program'] ?? '')),
        'beginning_semester'       => trim((string)($row['Beginning Semester'] ?? '')),
        'total_semesters'          => $total_semesters,
        'admission_fee'            => bip_num((string)($row['Admission Fee'] ?? '0')),
        'registration_fee_total'   => bip_num((string)($row['Registration Fee'] ?? '0')),
        'english_course_fee'       => bip_num((string)($row['English Language Course Fee'] ?? '0')),
        'tuition_full'             => bip_num((string)($row['Tuition Fee/Credit'] ?? '0')),
        'fixed_institutional_fees' => bip_num((string)($row['Miscellaneous/Semester Fee'] ?? '0')),
        'concession'               => bip_num((string)($row['Concession'] ?? '0')),
        'monthly_payment'          => bip_num((string)($row['Monthly Payment'] ?? '0')),
        'transactions'             => bip_parse_csv_transactions((string)($row['Transaction History'] ?? '')),
    ];
}

function bip_manifest_from_csv(string $csv_path): array
{
    $handle = @fopen($csv_path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Could not open the uploaded CSV file.');
    }

    try {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new RuntimeException('The uploaded CSV file is empty.');
        }

        $headers = array_map(static function ($header): string {
            $header = (string)$header;
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            return trim($header);
        }, $headers);

        if (!in_array('Student ID', $headers, true)) {
            throw new RuntimeException('CSV must include a "Student ID" column.');
        }

        $manifest = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string)($values[$index] ?? ''));
            }

            $parsed = bip_parse_csv_row($row);
            if ($parsed === null) {
                continue;
            }

            $manifest[] = [
                'sid'    => trim((string)$row['Student ID']),
                'data'   => $parsed,
                'source' => 'csv',
            ];
        }
    } finally {
        fclose($handle);
    }

    return $manifest;
}

// ─────────────────────────────────────────────────────────────────────────────
// SESSION HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function bip_session_key(string $key): string
{
    return 'bip_' . $key;
}

function bip_session_get(string $key): ?array
{
    return $_SESSION[bip_session_key($key)] ?? null;
}

function bip_session_set(string $key, array $data): void
{
    $_SESSION[bip_session_key($key)] = $data;
}

function bip_session_del(string $key): void
{
    unset($_SESSION[bip_session_key($key)]);
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMP DIRECTORY HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function bip_temp_dir_create(): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    for ($i = 0; $i < 5; $i++) {
        $dir = $base . 'bip-' . bin2hex(random_bytes(12));
        if (mkdir($dir, 0700, true)) {
            return $dir;
        }
    }
    throw new RuntimeException('Could not create temp directory for bulk import.');
}

function bip_temp_dir_remove(string $dir): void
{
    if ($dir === '') return;
    // Resolve real paths to guard against symlinks / relative traversal
    $real_dir = realpath($dir);
    $real_tmp = realpath(sys_get_temp_dir());
    if ($real_dir === false || $real_tmp === false) return;
    // Safety: only remove directories that are inside sys_get_temp_dir
    if (strpos($real_dir, $real_tmp . DIRECTORY_SEPARATOR) !== 0) return;
    if (!is_dir($real_dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($real_dir);
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF for AJAX
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bip_error('POST required.');
}
csrf_check();

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: upload
// ─────────────────────────────────────────────────────────────────────────────

if ($action === 'upload') {
    if (empty($_FILES['import_file']['name']) || ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        bip_error('No ZIP or CSV file uploaded, or upload error occurred.');
    }

    $file_ext = strtolower(pathinfo((string)$_FILES['import_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, ['zip', 'csv'], true)) {
        bip_error('Only ZIP and CSV files are accepted.');
    }

    $cash_account_id   = (int)($_POST['cash_account_id']   ?? 0);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    if (!$cash_account_id)   bip_error('Cash / received-into account is required.');
    if (!$income_account_id) bip_error('Income account is required.');

    try {
        $tmp_dir = '';
        $manifest = [];

        if ($file_ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                bip_error('PHP ZipArchive extension is not available on this server.');
            }

            $zip_mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['import_file']['tmp_name']);
            $valid_mimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'];
            if (!in_array($zip_mime, $valid_mimes, true)) {
                bip_error('The uploaded ZIP file is not valid.');
            }

            $zip = new ZipArchive();
            if ($zip->open($_FILES['import_file']['tmp_name']) !== true) {
                bip_error('Could not open the ZIP file. It may be corrupt.');
            }

            $tmp_dir = bip_temp_dir_create();

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (substr($entry, -1) === '/') continue;
                if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'pdf') continue;

                $sid  = pathinfo(basename($entry), PATHINFO_FILENAME);
                if ($sid === '') continue;

                $dest = $tmp_dir . DIRECTORY_SEPARATOR . $sid . '.pdf';
                $zip->extractTo($tmp_dir, $entry);

                // extractTo preserves directory structure; find actual extracted path
                $extracted = $tmp_dir . DIRECTORY_SEPARATOR . $entry;
                if (is_file($extracted) && $extracted !== $dest) {
                    if (!rename($extracted, $dest)) {
                        // rename can fail across filesystems; fall back to copy+unlink
                        if (copy($extracted, $dest)) {
                            unlink($extracted);
                        } else {
                            error_log('bip upload: failed to move ' . $extracted . ' to ' . $dest);
                            continue;
                        }
                    }
                }

                if (is_file($dest)) {
                    $manifest[] = ['sid' => $sid, 'path' => $dest, 'source' => 'pdf'];
                }
            }
            $zip->close();
        } else {
            $manifest = bip_manifest_from_csv($_FILES['import_file']['tmp_name']);
        }

    } catch (Throwable $e) {
        if ($tmp_dir !== '') {
            bip_temp_dir_remove($tmp_dir);
        }
        bip_error('Failed to prepare import file: ' . $e->getMessage());
    }

    if (empty($manifest)) {
        if ($tmp_dir !== '') {
            bip_temp_dir_remove($tmp_dir);
        }
        bip_error($file_ext === 'zip' ? 'No PDF files found in the ZIP archive.' : 'No student rows found in the CSV file.');
    }

    // Save manifest + settings to session
    $session_key = bin2hex(random_bytes(16));
    bip_session_set($session_key, [
        'tmp_dir'           => $tmp_dir,
        'manifest'          => $manifest,
        'cash_account_id'   => $cash_account_id,
        'income_account_id' => $income_account_id,
        'overwrite'         => !empty($_POST['overwrite']) && $_POST['overwrite'] === '1',
    ]);

    bip_json([
        'success'     => true,
        'session_key' => $session_key,
        'total'       => count($manifest),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: batch
// ─────────────────────────────────────────────────────────────────────────────

if ($action === 'batch') {
    $session_key = trim($_POST['session_key'] ?? '');
    $offset      = max(0, (int)($_POST['offset'] ?? 0));
    $batch_size  = max(1, min(50, (int)($_POST['batch_size'] ?? 20)));

    $session = bip_session_get($session_key);
    if (!$session) {
        bip_error('Import session not found or expired. Please re-upload the ZIP or CSV file.');
    }

    $manifest          = $session['manifest'];
    $cash_account_id   = (int)$session['cash_account_id'];
    $income_account_id = (int)$session['income_account_id'];
    $overwrite         = (bool)$session['overwrite'];

    $user    = auth_user();
    $user_id = (int)($user['id'] ?? 0);

    $batch = array_slice($manifest, $offset, $batch_size);
    $rows  = [];
    $created = 0;
    $skipped = 0;
    $failed  = 0;

    foreach ($batch as $item) {
        $sid  = $item['sid'];
        $source = $item['source'] ?? 'pdf';

        if ($source === 'csv') {
            $pdf = $item['data'] ?? null;
            if (!is_array($pdf)) {
                $rows[]  = ['sid' => $sid, 'status' => 'failed', 'message' => 'CSV row data is missing'];
                $failed++;
                continue;
            }
        } else {
            $path = $item['path'] ?? '';

            if (!is_file($path)) {
                $rows[]  = ['sid' => $sid, 'status' => 'failed', 'message' => 'PDF file not found in temp dir'];
                $failed++;
                continue;
            }

            $text = bip_extract_text($path);
            if (trim($text) === '') {
                $rows[]  = ['sid' => $sid, 'status' => 'failed', 'message' => 'Could not extract text from PDF'];
                $failed++;
                continue;
            }

            $pdf = bip_parse_pdf_text($text);
        }

        try {
            $result = bip_import_student(
                $sid,
                $pdf,
                $overwrite,
                $cash_account_id,
                $income_account_id,
                $user_id
            );
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'message' => $e->getMessage()];
        }

        $rows[] = array_merge(['sid' => $sid], $result);
        if ($result['status'] === 'created')  $created++;
        elseif ($result['status'] === 'skipped') $skipped++;
        else                                     $failed++;
    }

    $done = ($offset + $batch_size) >= count($manifest);

    bip_json([
        'success' => true,
        'offset'  => $offset + count($batch),
        'total'   => count($manifest),
        'done'    => $done,
        'created' => $created,
        'skipped' => $skipped,
        'failed'  => $failed,
        'rows'    => $rows,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ACTION: cleanup
// ─────────────────────────────────────────────────────────────────────────────

if ($action === 'cleanup') {
    $session_key = trim($_POST['session_key'] ?? '');
    $session = bip_session_get($session_key);
    if ($session && isset($session['tmp_dir'])) {
        bip_temp_dir_remove($session['tmp_dir']);
    }
    bip_session_del($session_key);
    bip_json(['success' => true]);
}

bip_error('Unknown action.');
