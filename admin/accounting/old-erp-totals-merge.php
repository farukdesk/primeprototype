<?php
/**
 * Old ERP – Totals CSV Merge (v2)
 * ================================================================
 * Merge a student's OLD ERP history from a TOTALS-based CSV (no per-receipt
 * rows and no receipt numbers). Each row carries:
 *
 *   1. Student ID    – matched with or without leading zeros
 *   2. Student Name  – informational; a mismatch is warned, never blocks
 *   3. Amount Paid (Incl. Admission & Registration) – the WHOLE amount the
 *      student paid in the old ERP. Allocated server-side in this order:
 *      Admission → Form Fee → ID Card Fee → Registration (per semester,
 *      CAPPED at the Registration "Received Amount" read from the OLD ERP
 *      proof's transaction history — Head of A/C: Registration Fee →
 *      Payable / Received / Due) → Monthly tuition (earliest months first).
 *      Registration is only marked paid up to what the proof shows as
 *      actually RECEIVED; the rest of the registration fees stay as DUES
 *      and the money is merged into the monthly payments instead. When no
 *      proof reading is stored the row falls back to schedule order and is
 *      loudly warned (preview + merge results). Anything beyond the
 *      schedule is recorded on the last month and flagged for review.
 *   4. Scholarship Amount – merged into monthly tuition as clearly-marked
 *      OLD-ERP SCHOLARSHIP memo rows (transaction no. OLD-ERP-SCHOLARSHIP),
 *      so the months stop showing false dues while the rows stay identifiable
 *      as scholarship, not cash.
 *   5. Other Fees Total – total paid for other purposes.
 *   6. Other Fees Detail – the head/purpose. Known heads (Re-Take,
 *      Improvement, Special Exam Mid/Final) are recorded under their own fee
 *      type; anything else is recorded under the catch-all 'other' head with
 *      the CSV head name written on the payment note (the fee_type list is a
 *      fixed enum, so brand-new heads are preserved as notes, never lost).
 *
 * Options
 *   • "Form & ID Card fee missing in old ERP": for batches where the old ERP
 *     never charged these fees. Sets sfp_packages.form_id_fee_missing = 1
 *     (column auto-created) which makes the head WAIVED — neither counted as
 *     due nor marked paid anywhere in the system.
 *
 * Safety
 *   • All payments are recorded exactly like the classic bulk merge: old_erp
 *     MEMO vouchers via acc_collect_student_fee(), so nothing is counted as
 *     new income in this ERP's books.
 *   • Idempotent: a row is skipped when the student's existing old-ERP records
 *     already cover the CSV total (±5 BDT) — re-uploading the file is safe.
 *   • A mistaken merge can be cleared per student / batch / program /
 *     department from the Old ERP Proof Audit page (Clear Old ERP Import).
 *
 * Proof screenshots: upload the ZIP of images named {student id}.png via
 * Student Accounts → Bulk OLD ERP Proof Upload (linked from this page).
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Old ERP – Totals CSV Merge';

const OESM_AMOUNT_TOLERANCE = 5.0;      // old-ERP rounding tolerance (BDT)
const OESM_MAX_ROWS         = 3000;     // hard cap per upload
const OESM_SESSION_KEY      = 'oesm_rows_v1';
const OESM_BATCH_SIZE       = 100;      // students merged per request – keeps memory flat on big files

// ── Sample CSV template download ──────────────────────────────────────
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="old-erp-totals-merge-sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Student Name', 'Amount Paid (Incl. Admission & Registration)', 'Scholarship Amount', 'Other Fees Total', 'Other Fees Detail'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Md Example Student', '85000', '10000', '2000', 'Late Fine: 1000; Special Examination (Mid Term): 1000'], ',', '"', '\\');
    fputcsv($out, ['02826105101072', 'Ms Example Student', '42000', '0', '0', ''], ',', '"', '\\');
    fputcsv($out, ['2826105101073', 'Another Student', '60000', '5000', '800', 'Library Late Fine'], ',', '"', '\\');
    fclose($out);
    exit;
}

// ── Small utilities ─────────────────────────────────────────────────

function oesm_money(string $raw): float
{
    $s = preg_replace('/[^0-9.\-]/', '', trim($raw));
    return $s === '' || $s === '-' ? 0.0 : round((float)$s, 2);
}

/**
 * Look up a student by ID, tolerant of leading zeros (same rule as the classic
 * bulk merge and the proof audit).
 */
function oesm_lookup_student(string $sid): ?array
{
    $sid = trim($sid);
    if ($sid === '') {
        return null;
    }
    $stu = acc_get_student_by_sid($sid);
    if ($stu) {
        return $stu;
    }
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
 * Flatten a fee summary into chronological monthly slots (same shape as the
 * proof audit).
 */
function oesm_month_slots(array $summary): array
{
    $slots = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $slots[] = [
                'semester_fee_id' => (int)$sem['id'],
                'semester_number' => (int)$sem['semester_number'],
                'month_number'    => (int)$mr['month_number'],
                'label'           => (string)$mr['month_label'],
                'out'             => round((float)$mr['out'], 2),
            ];
        }
    }
    return $slots;
}

/**
 * Map an "Other Fees Detail" head from the CSV to a fee_type. Covers every
 * additional / examination fee category in the system; unknown heads fall
 * back to 'other' (the head name is preserved on the payment note — the
 * fee_type column is a fixed enum, so brand-new heads are kept as notes).
 */
function oesm_other_fee_type(string $detail): string
{
    $s = strtolower($detail);
    if (str_contains($s, 'retake') || str_contains($s, 're-take') || str_contains($s, 're take')) {
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
    if (str_contains($s, 'library') && str_contains($s, 'fine')) {
        return 'library_late_fine';
    }
    if (str_contains($s, 'fine')) {
        return 'late_fine';
    }
    if (str_contains($s, 'transcript')) {
        return 'transcript_fee';
    }
    if (str_contains($s, 'testimonial')) {
        return 'testimonial_fee';
    }
    if (str_contains($s, 'syllabus')) {
        return 'syllabus_sale';
    }
    if (str_contains($s, 'remedial')) {
        return 'remedial_course_fee';
    }
    if (str_contains($s, 're-registration') || str_contains($s, 're registration') || str_contains($s, 'reregistration')) {
        return 're_registration_fee';
    }
    if (str_contains($s, 're-exam') || str_contains($s, 're exam') || str_contains($s, 'reexam')) {
        return 're_exam_fee';
    }
    if (str_contains($s, 're-admission') || str_contains($s, 're admission') || str_contains($s, 'readmission')) {
        return 're_admission_fee';
    }
    if (str_contains($s, 'provisional')) {
        return 'provisional_certificate_fee';
    }
    if (str_contains($s, 'appeared')) {
        return 'appeared_certificate_fee';
    }
    if (str_contains($s, 'original') && str_contains($s, 'certificate')) {
        return 'original_certificate_fee';
    }
    if (str_contains($s, 'miscellaneous') || str_contains($s, 'misc')) {
        return 'miscellaneous_fee';
    }
    if (str_contains($s, 'id card') || str_contains($s, 'id-card')) {
        return 'id_card_replacement_fee';
    }
    if (str_contains($s, 'english')) {
        return 'english_language_fee';
    }
    if (str_contains($s, 'convocation')) {
        return 'convocation_registration_fee';
    }
    if (str_contains($s, 'advocateship') || str_contains($s, 'advocate')) {
        return 'advocateship_training_fee';
    }
    return 'other';
}

/**
 * Parse the "Other Fees Detail" cell into individual additional-fee items.
 *
 * Supports an itemised detail such as
 *   "Late Fine: 1000.0; Special Examination (Mid Term): 1000.0"
 * (heads separated by ';', '|' or newlines; per-head amounts optional).
 *
 * Reconciliation with the Other Fees Total column:
 *   • itemised amounts → each head keeps its own amount; any un-itemised
 *     remainder of the total is recorded under 'other' so no old-ERP money
 *     is ever lost (warned for review);
 *   • no amounts in the detail → a single head gets the whole total;
 *     multiple heads split the total evenly (last absorbs rounding; warned).
 *
 * @return array{items: array<int, array{fee_type:string,label:string,amount:float}>, warning:string}
 */
function oesm_parse_other_detail(string $detail, float $other_total): array
{
    $items   = [];
    $warning = '';
    $total   = round($other_total, 2);

    $parts = preg_split('/[;|\n]+/', $detail) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));

    foreach ($parts as $part) {
        $label  = $part;
        $amount = 0.0;
        // "Head: 1000.0" / "Head - 1000" / "Head = 1,000"
        if (preg_match('/^(.*?)\s*[:=\-]\s*([0-9][0-9,]*(?:\.[0-9]+)?)\s*$/', $part, $m)) {
            $label  = trim($m[1]);
            $amount = round((float)str_replace(',', '', $m[2]), 2);
        }
        if ($label === '') {
            $label = 'Other';
        }
        // Combined heads sharing one amount, e.g.
        // "Re-Take Fee & Improvement Fee: 2000.0" — split the amount evenly
        // across the heads (last head absorbs the rounding remainder).
        $sub_labels = preg_split('/\s*&\s*|\s+and\s+/i', $label) ?: [$label];
        $sub_labels = array_values(array_filter(array_map('trim', $sub_labels), static fn($l) => $l !== ''));
        if (count($sub_labels) <= 1) {
            $items[] = [
                'fee_type' => oesm_other_fee_type($label),
                'label'    => $label,
                'amount'   => $amount,
            ];
        } else {
            $n_sub = count($sub_labels);
            $per   = round($amount / $n_sub, 2);
            foreach ($sub_labels as $si => $sub_label) {
                $items[] = [
                    'fee_type' => oesm_other_fee_type($sub_label),
                    'label'    => $sub_label,
                    'amount'   => ($si < $n_sub - 1) ? $per : round($amount - $per * ($n_sub - 1), 2),
                ];
            }
        }
    }

    if (!$items) {
        if ($total > 0.005) {
            $items[] = [
                'fee_type' => 'other',
                'label'    => $detail !== '' ? $detail : 'Other fees (old ERP)',
                'amount'   => $total,
            ];
        }
        return ['items' => $items, 'warning' => ''];
    }

    $sum = round(array_sum(array_column($items, 'amount')), 2);

    if ($sum <= 0.005) {
        // No per-head amounts in the detail — distribute the total.
        $n = count($items);
        if ($total > 0.005 && $n > 0) {
            $per = round($total / $n, 2);
            foreach ($items as $i => &$it) {
                $it['amount'] = ($i < $n - 1) ? $per : round($total - $per * ($n - 1), 2);
            }
            unset($it);
            if ($n > 1) {
                $warning = 'Other Fees Detail carries no per-head amounts — the Other Fees Total was split evenly across ' . $n . ' heads.';
            }
        }
        return ['items' => $items, 'warning' => $warning];
    }

    $diff = round($total - $sum, 2);
    if ($diff > OESM_AMOUNT_TOLERANCE) {
        // Itemised amounts do not reach the total — keep the difference under Other.
        $items[] = [
            'fee_type' => 'other',
            'label'    => 'Other fees (remainder not itemised in CSV)',
            'amount'   => $diff,
        ];
        $warning = 'Other Fees Detail items total ' . acc_fmt($sum) . ' but Other Fees Total is '
            . acc_fmt($total) . ' — the remaining ' . acc_fmt($diff) . ' was recorded under Other.';
    } elseif ($diff < -OESM_AMOUNT_TOLERANCE) {
        $warning = 'Other Fees Detail items total ' . acc_fmt($sum) . ' which is MORE than the Other Fees Total ('
            . acc_fmt($total) . ') — the itemised amounts were recorded as-is; please verify.';
    }

    // Drop zero-amount label-only leftovers when other items carry amounts.
    $items = array_values(array_filter($items, static fn($it) => $it['amount'] > 0.005));

    return ['items' => $items, 'warning' => $warning];
}

/**
 * Old-ERP money already recorded AS SCHOLARSHIP (memo rows whose
 * transaction number is OLD-ERP-SCHOLARSHIP) for a student. Used to detect
 * wrong / reversed scholarship marking from an earlier import run.
 */
function oesm_existing_scholarship_total(int $student_pk): float
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(sp.amount), 0)
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.student_id = ?
           AND sp.payment_method = 'old_erp'
           AND sp.transaction_number = 'OLD-ERP-SCHOLARSHIP'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')"
    );
    $stmt->execute([$student_pk]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * Total live old-ERP money already recorded for a student (all fee heads).
 */
function oesm_existing_old_erp_total(int $student_pk): float
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(sp.amount), 0)
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.student_id = ?
           AND sp.payment_method = 'old_erp'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')"
    );
    $stmt->execute([$student_pk]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * Auto-migrate: sfp_packages.form_id_fee_missing flag (waives the Form &
 * ID Card head — neither due nor paid — for batches where the old ERP never
 * charged it). acc_package_form_id_fee() honours this flag.
 */
function oesm_ensure_missing_flag_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $col = db()->query("SHOW COLUMNS FROM sfp_packages LIKE 'form_id_fee_missing'")->fetch();
        if (!$col) {
            db()->exec(
                "ALTER TABLE sfp_packages
                 ADD COLUMN form_id_fee_missing TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT 'Form & ID Card fee missing in old ERP - head waived (neither due nor paid)'"
            );
        }
    } catch (Throwable $e) {
        // Leave it to the DBA when ALTER is not permitted; the merge still works,
        // only the waiver option will have no effect.
    }
    $done = true;
}

// ── POST: confirm (merge the validated rows stored in the session) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm') {
    csrf_check();
    set_time_limit(0);
    ignore_user_abort(true);
    // Each student needs a full nested fee summary plus many voucher inserts.
    // Give PHP head-room and, more importantly, only merge OESM_BATCH_SIZE
    // students per request (the page auto-continues below) so memory can
    // never run out even on a 3000-row file.
    @ini_set('memory_limit', '512M');

    $payload = $_SESSION[OESM_SESSION_KEY] ?? null;
    if (!is_array($payload) || !is_array($payload['rows'] ?? null) || !$payload['rows']) {
        flash_set('error', 'Nothing to confirm — please upload the CSV again.');
        redirect(APP_URL . '/accounting/old-erp-totals-merge.php');
    }
    $rows_left    = $payload['rows'];
    $rows         = array_splice($rows_left, 0, OESM_BATCH_SIZE);   // this request's batch
    $mark_missing = !empty($payload['mark_form_id_missing']);

    $cash = acc_received_into_account_id_for_payment_method('old_erp');
    if ($cash <= 0) {
        unset($_SESSION[OESM_SESSION_KEY]);
        flash_set('error', 'The Old ERP cash account is not configured in Accounting Settings — nothing was merged.');
        redirect(APP_URL . '/accounting/old-erp-totals-merge.php');
    }
    if ($mark_missing) {
        oesm_ensure_missing_flag_column();
    }

    // Progress accumulated across batches (kept in the session).
    $prog = is_array($payload['progress'] ?? null)
        ? $payload['progress']
        : ['merged' => 0, 'skipped' => 0, 'failed' => 0, 'grand' => 0.0,
           'total' => count($payload['rows']), 'warnings' => []];

    $today    = date('Y-m-d');
    $merged   = (int)($prog['merged']  ?? 0);
    $skipped  = (int)($prog['skipped'] ?? 0);
    $failed   = (int)($prog['failed']  ?? 0);
    $grand    = (float)($prog['grand'] ?? 0.0);
    $warnings = (array)($prog['warnings'] ?? []);

    foreach ($rows as $r) {
        $sid = (string)($r['sid'] ?? '');
        try {
            $stu = oesm_lookup_student($sid);
            if (!$stu || empty($stu['package_id'])) {
                $failed++;
                $warnings[] = $sid . ': student or fee package not found.';
                continue;
            }
            $stu_pk = (int)$stu['id'];
            $pkg_id = (int)$stu['package_id'];

            $amount    = round((float)($r['amount'] ?? 0), 2);
            $sch       = round((float)($r['scholarship'] ?? 0), 2);
            $other     = round((float)($r['other_total'] ?? 0), 2);
            $detail    = trim((string)($r['other_detail'] ?? ''));
            $csv_total = round($amount + $sch + $other, 2);

            // Idempotency guard: skip when old-ERP records already cover the CSV total.
            if ($csv_total > 0 && oesm_existing_old_erp_total($stu_pk) >= $csv_total - OESM_AMOUNT_TOLERANCE) {
                $skipped++;
                continue;
            }

            // Waiver flag: Form & ID Card fee missing in the old ERP.
            if ($mark_missing) {
                try {
                    $upd = db()->prepare('UPDATE sfp_packages SET form_id_fee_missing = 1 WHERE id = ? AND form_id_fee_missing = 0');
                    $upd->execute([$pkg_id]);
                    if ($upd->rowCount() > 0) {
                        log_change('accounting', 'UPDATE', $stu_pk,
                            (string)$stu['full_name'] . ' (' . (string)$stu['student_id'] . ')',
                            'form_id_fee_missing', '0', '1',
                            'Old ERP totals merge: Form & ID Card fee marked MISSING in the old ERP — head waived (neither due nor paid).');
                    }
                } catch (Throwable $e) {
                    $warnings[] = $sid . ': could not set the Form & ID Card "missing in old ERP" flag — ' . $e->getMessage();
                }
            }

            $summary = acc_student_fee_summary($stu_pk);
            if (!$summary) {
                $failed++;
                $warnings[] = $sid . ': the student has no fee summary.';
                continue;
            }
            $tt       = $summary['totals'] ?? [];
            $slots    = oesm_month_slots($summary);
            $room     = [];
            foreach ($slots as $i => $slot) {
                $room[$i] = (float)$slot['out'];
            }
            $tui_inc  = acc_income_account_id_for_fee_type('semester_tuition');
            $inserted = 0.0;
            $vcount   = 0;

            // ── 1) Amount Paid: one-time heads first ───────────────────────
            $remaining = $amount;
            foreach (['admission', 'form_fee', 'id_card_fee'] as $hk) {
                if ($remaining <= 0.005) {
                    break;
                }
                $head_room = round((float)($tt[$hk]['out'] ?? 0), 2);
                if ($head_room <= 0.005) {
                    continue;
                }
                $inc = acc_income_account_id_for_fee_type($hk);
                if ($inc <= 0) {
                    $warnings[] = $sid . ': no income account mapped for ' . acc_fee_type_label($hk) . ' — head skipped.';
                    continue;
                }
                $take = round(min($remaining, $head_room), 2);
                acc_collect_student_fee(
                    $stu_pk, $pkg_id, $hk, null, null, null,
                    'old_erp', null, 'OLD-ERP-TOTAL', $take,
                    $cash, $inc, $today,
                    'Old ERP totals merge',
                    'Old ERP totals merge: ' . acc_fee_type_label($hk) . ' allocated from the total Amount Paid column.',
                    true
                );
                $vcount++;
                $inserted  = round($inserted + $take, 2);
                $remaining = round($remaining - $take, 2);
            }

            // ── 2) Registration per semester ─────────────────────────────────
            if ($remaining > 0.005) {
                $reg_inc = acc_income_account_id_for_fee_type('registration');
                if ($reg_inc > 0) {
                    foreach (($summary['semesters'] ?? []) as $sem) {
                        if ($remaining <= 0.005) {
                            break;
                        }
                        $sem_room = round((float)($sem['reg_out'] ?? 0), 2);
                        if ($sem_room <= 0.005) {
                            continue;
                        }
                        $take = round(min($remaining, $sem_room), 2);
                        acc_collect_student_fee(
                            $stu_pk, $pkg_id, 'registration',
                            (int)$sem['id'], (int)$sem['semester_number'], null,
                            'old_erp', null, 'OLD-ERP-TOTAL', $take,
                            $cash, $reg_inc, $today,
                            'Old ERP totals merge',
                            'Old ERP totals merge: Registration Fee allocated to semester ' . (int)$sem['semester_number'] . ' from the total Amount Paid column.',
                            true
                        );
                        $vcount++;
                        $inserted  = round($inserted + $take, 2);
                        $remaining = round($remaining - $take, 2);
                    }
                }
            }

            // ── 3) Monthly tuition: earliest months with room ──────────────────
            if ($remaining > 0.005 && $tui_inc > 0) {
                foreach ($slots as $i => $slot) {
                    if ($remaining <= 0.005) {
                        break;
                    }
                    if ($room[$i] <= 0.005) {
                        continue;
                    }
                    $take = round(min($remaining, $room[$i]), 2);
                    acc_collect_student_fee(
                        $stu_pk, $pkg_id, 'semester_tuition',
                        (int)$slot['semester_fee_id'], (int)$slot['semester_number'], (int)$slot['month_number'],
                        'old_erp', null, 'OLD-ERP-TOTAL', $take,
                        $cash, $tui_inc, $today,
                        'Old ERP totals merge',
                        'Old ERP totals merge: monthly tuition allocated to ' . $slot['label'] . ' from the total Amount Paid column.',
                        true
                    );
                    $vcount++;
                    $room[$i]  = round($room[$i] - $take, 2);
                    $inserted  = round($inserted + $take, 2);
                    $remaining = round($remaining - $take, 2);
                }
                // Beyond the schedule: record the ACTUAL old-ERP money on the
                // last month so it is never lost, and flag it for review.
                if ($remaining > OESM_AMOUNT_TOLERANCE && $slots) {
                    $last = $slots[count($slots) - 1];
                    acc_collect_student_fee(
                        $stu_pk, $pkg_id, 'semester_tuition',
                        (int)$last['semester_fee_id'], (int)$last['semester_number'], (int)$last['month_number'],
                        'old_erp', null, 'OLD-ERP-TOTAL', $remaining,
                        $cash, $tui_inc, $today,
                        'Old ERP totals merge',
                        'Old ERP totals merge: amount beyond the scheduled dues — recorded on the last month (' . $last['label'] . '); verify manually.',
                        true
                    );
                    $vcount++;
                    $inserted   = round($inserted + $remaining, 2);
                    $warnings[] = $sid . ': ' . acc_fmt($remaining) . ' was beyond the scheduled dues — recorded on the last month; verify manually.';
                    $remaining  = 0.0;
                }
            } elseif ($remaining > OESM_AMOUNT_TOLERANCE) {
                $warnings[] = $sid . ': ' . acc_fmt($remaining) . ' could not be placed (no tuition income account or no monthly schedule).';
            }

            // ── 4) Scholarship: merged into monthly tuition, clearly marked ─────
            if ($sch > 0.005 && $tui_inc > 0) {
                $sch_left = $sch;
                foreach ($slots as $i => $slot) {
                    if ($sch_left <= 0.005) {
                        break;
                    }
                    if ($room[$i] <= 0.005) {
                        continue;
                    }
                    $take = round(min($sch_left, $room[$i]), 2);
                    acc_collect_student_fee(
                        $stu_pk, $pkg_id, 'semester_tuition',
                        (int)$slot['semester_fee_id'], (int)$slot['semester_number'], (int)$slot['month_number'],
                        'old_erp', null, 'OLD-ERP-SCHOLARSHIP', $take,
                        $cash, $tui_inc, $today,
                        'Old ERP scholarship',
                        'SCHOLARSHIP (old ERP): scholarship amount adjusted into monthly tuition on ' . $slot['label'] . ' — this is a scholarship, not cash received.',
                        true
                    );
                    $vcount++;
                    $room[$i] = round($room[$i] - $take, 2);
                    $inserted = round($inserted + $take, 2);
                    $sch_left = round($sch_left - $take, 2);
                }
                if ($sch_left > OESM_AMOUNT_TOLERANCE && $slots) {
                    $last = $slots[count($slots) - 1];
                    acc_collect_student_fee(
                        $stu_pk, $pkg_id, 'semester_tuition',
                        (int)$last['semester_fee_id'], (int)$last['semester_number'], (int)$last['month_number'],
                        'old_erp', null, 'OLD-ERP-SCHOLARSHIP', $sch_left,
                        $cash, $tui_inc, $today,
                        'Old ERP scholarship',
                        'SCHOLARSHIP (old ERP): scholarship amount beyond the scheduled dues — recorded on the last month (' . $last['label'] . '); verify manually.',
                        true
                    );
                    $vcount++;
                    $inserted   = round($inserted + $sch_left, 2);
                    $warnings[] = $sid . ': scholarship ' . acc_fmt($sch_left) . ' was beyond the scheduled dues — recorded on the last month; verify manually.';
                }
            } elseif ($sch > 0.005) {
                $warnings[] = $sid . ': scholarship ' . acc_fmt($sch) . ' could not be placed (no tuition income account).';
            }

            // ── 5) Other fees: itemised additional payments ─────────────────
            // The Other Fees Detail cell may itemise several heads, e.g.
            // "Late Fine: 1000.0; Special Examination (Mid Term): 1000.0".
            // Each head is recorded as its OWN additional payment (outside the
            // monthly schedule) under the matching fee type; unknown heads go
            // to 'other' with the CSV head name preserved on the note.
            if ($other > 0.005) {
                $parsed_other = oesm_parse_other_detail($detail, $other);
                if ($parsed_other['warning'] !== '') {
                    $warnings[] = $sid . ': ' . $parsed_other['warning'];
                }
                foreach ($parsed_other['items'] as $o_item) {
                    $o_amt = round((float)$o_item['amount'], 2);
                    if ($o_amt <= 0.005) {
                        continue;
                    }
                    $ft  = (string)$o_item['fee_type'];
                    $inc = acc_income_account_id_for_fee_type($ft);
                    if ($inc <= 0 && $ft !== 'other') {
                        $ft  = 'other';
                        $inc = acc_income_account_id_for_fee_type('other');
                    }
                    if ($inc <= 0) {
                        $warnings[] = $sid . ': other fee "' . (string)$o_item['label'] . '" (' . acc_fmt($o_amt) . ') skipped — no income account mapped for the "other" head.';
                        continue;
                    }
                    acc_collect_student_fee(
                        $stu_pk, $pkg_id, $ft, null, null, null,
                        'old_erp', null, 'OLD-ERP-OTHER', $o_amt,
                        $cash, $inc, $today,
                        'Old ERP totals merge',
                        'Old ERP other fees — head: ' . (string)$o_item['label']
                            . ($ft === 'other' ? ' (recorded under the catch-all Other head; head noted from CSV)' : '') . '.',
                        true
                    );
                    $vcount++;
                    $inserted = round($inserted + $o_amt, 2);
                }
            }

            log_change('accounting', 'CREATE', $stu_pk,
                (string)$stu['full_name'] . ' (' . (string)$stu['student_id'] . ')',
                'old_erp_totals_merge', '', acc_fmt($inserted),
                'Old ERP totals merge: ' . $vcount . ' payment(s) totalling ' . acc_fmt($inserted) . ' recorded from the totals CSV'
                . ($mark_missing ? '; Form & ID Card fee marked missing in old ERP (waived)' : '') . '.');

            $grand = round($grand + $inserted, 2);
            $merged++;
        } catch (Throwable $e) {
            $failed++;
            $warnings[] = $sid . ': ' . $e->getMessage();
        }
        // Free the per-student fee summary and force cycle collection so a
        // large file cannot exhaust the PHP memory limit.
        unset($stu, $summary, $tt, $slots, $room, $parsed_other);
        gc_collect_cycles();
    }

    // More rows left? Persist the progress and auto-continue with the next
    // batch in a fresh request – memory stays flat regardless of file size.
    if ($rows_left) {
        $payload['rows']     = $rows_left;
        $payload['progress'] = [
            'merged'   => $merged,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'grand'    => $grand,
            'total'    => (int)($prog['total'] ?? 0),
            'warnings' => array_slice($warnings, 0, 200),
        ];
        $_SESSION[OESM_SESSION_KEY] = $payload;

        $done_cnt  = $merged + $skipped + $failed;
        $total_cnt = max((int)($prog['total'] ?? 0), $done_cnt + count($rows_left));
        $pct       = $total_cnt > 0 ? (int)floor($done_cnt / $total_cnt * 100) : 0;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Old ERP Totals Merge – processing…</title>
    <style>
        body{font-family:Inter,Arial,sans-serif;background:#f4f6fb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#fff;border:1px solid #e0e7ef;border-radius:12px;padding:32px 40px;max-width:480px;text-align:center;}
        .bar{background:#e9ecef;border-radius:8px;height:14px;overflow:hidden;margin:18px 0 8px;}
        .fill{background:#198754;height:100%;width:<?= $pct ?>%;transition:width .3s;}
    </style>
</head>
<body>
    <div class="box">
        <h3 style="margin:0 0 6px;">Merging old-ERP totals…</h3>
        <p style="color:#6c757d;margin:0;">Processed <?= $done_cnt ?> of <?= $total_cnt ?> students (<?= $pct ?>%).<br>
           Please keep this tab open — the next batch starts automatically.</p>
        <div class="bar"><div class="fill"></div></div>
        <form id="oesm_continue" method="post" action="<?= APP_URL ?>/accounting/old-erp-totals-merge.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <noscript><button type="submit">Continue</button></noscript>
        </form>
    </div>
    <script>setTimeout(function () { document.getElementById('oesm_continue').submit(); }, 400);</script>
</body>
</html>
        <?php
        exit;
    }

    unset($_SESSION[OESM_SESSION_KEY]);
    $msg = $merged . ' student(s) merged (' . acc_fmt($grand) . ' recorded as old-ERP memo payments).';
    if ($skipped > 0) {
        $msg .= ' ' . $skipped . ' skipped (old-ERP records already cover the CSV total).';
    }
    if ($failed > 0) {
        $msg .= ' ' . $failed . ' failed.';
    }
    if ($warnings) {
        $shown = array_slice($warnings, 0, 12);
        $msg  .= ' Warnings: ' . implode(' | ', $shown);
        if (count($warnings) > count($shown)) {
            $msg .= ' | … and ' . (count($warnings) - count($shown)) . ' more (see the change log).';
        }
    }
    flash_set($failed > 0 ? 'warning' : 'success', $msg);
    redirect(APP_URL . '/accounting/old-erp-totals-merge.php');
}

// ── POST: upload & preview ────────────────────────────────────────────
$preview       = null;
$preview_ready = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload') {
    csrf_check();
    unset($_SESSION[OESM_SESSION_KEY]);

    $mark_missing = !empty($_POST['mark_form_id_missing']);

    if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose a CSV file to upload.');
    } else {
        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            flash_set('error', 'The uploaded file could not be read.');
        } else {
            $preview   = [];
            $ready     = [];
            $row_no    = 0;
            while (($cells = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $row_no++;
                if ($row_no > OESM_MAX_ROWS + 1) {
                    flash_set('error', 'The file has more than ' . OESM_MAX_ROWS . ' rows — please split it.');
                    $preview = null;
                    $ready   = [];
                    break;
                }
                $sid = trim((string)($cells[0] ?? ''));
                // Header row: first cell mentions "student"
                if ($row_no === 1 && stripos($sid, 'student') !== false) {
                    continue;
                }
                if ($sid === '' && trim(implode('', array_map('strval', $cells))) === '') {
                    continue; // fully empty line
                }

                $name        = trim((string)($cells[1] ?? ''));
                $amount      = oesm_money((string)($cells[2] ?? ''));
                $scholarship = oesm_money((string)($cells[3] ?? ''));
                $other_total = oesm_money((string)($cells[4] ?? ''));
                $detail      = trim((string)($cells[5] ?? ''));
                $csv_total   = round($amount + $scholarship + $other_total, 2);

                // Itemised Other Fees breakdown (e.g. "Late Fine: 1000;
                // Special Examination (Mid Term): 1000") for preview + validation.
                $other_breakdown = [];
                $other_warn      = '';
                if ($other_total > 0.005) {
                    $parsed_other = oesm_parse_other_detail($detail, $other_total);
                    foreach ($parsed_other['items'] as $oi) {
                        $other_breakdown[] = acc_fee_type_label((string)$oi['fee_type'])
                            . ($oi['fee_type'] === 'other' && $oi['label'] !== '' ? ' (' . (string)$oi['label'] . ')' : '')
                            . ': ' . acc_fmt((float)$oi['amount']);
                    }
                    $other_warn = $parsed_other['warning'];
                }

                $status = 'ready';
                $note   = '';
                $stu    = null;

                if ($sid === '') {
                    $status = 'failed';
                    $note   = 'Missing Student ID.';
                } elseif ($amount < 0 || $scholarship < 0 || $other_total < 0) {
                    $status = 'failed';
                    $note   = 'Negative amounts are not allowed.';
                } elseif ($csv_total <= 0) {
                    $status = 'skipped';
                    $note   = 'All amounts are zero — nothing to merge.';
                } else {
                    $stu = oesm_lookup_student($sid);
                    if (!$stu) {
                        $status = 'failed';
                        $note   = 'Student not found.';
                    } elseif (empty($stu['package_id'])) {
                        $status = 'failed';
                        $note   = 'No fee package — create the student account first.';
                    } else {
                        $existing = oesm_existing_old_erp_total((int)$stu['id']);
                        if ($existing >= $csv_total - OESM_AMOUNT_TOLERANCE) {
                            $status = 'skipped';
                            $note   = 'Old-ERP records (' . acc_fmt($existing) . ') already cover the CSV total.';
                        } elseif ($existing > OESM_AMOUNT_TOLERANCE) {
                            $note = 'Has ' . acc_fmt($existing) . ' old-ERP records already; only the CSV amounts will be allocated on the remaining dues.';
                        }
                        // Detect wrong / reversed scholarship marking from an
                        // earlier import: the amount already marked as scholarship
                        // in the DB differs from the CSV's Scholarship Amount.
                        $existing_sch = oesm_existing_scholarship_total((int)$stu['id']);
                        if ($existing_sch > OESM_AMOUNT_TOLERANCE
                            && abs($existing_sch - $scholarship) > OESM_AMOUNT_TOLERANCE) {
                            $note .= ($note ? ' ' : '') . 'WRONG SCHOLARSHIP MARKING: existing old-ERP records mark '
                                . acc_fmt($existing_sch) . ' as scholarship but the CSV says '
                                . acc_fmt($scholarship) . ' — clear this student\'s old ERP import (Proof Audit → Clear Old ERP Import), then re-upload this file to fix the labels.';
                        }
                        if ($name !== '' && $stu && strcasecmp(trim($name), trim((string)$stu['full_name'])) !== 0) {
                            $note .= ($note ? ' ' : '') . 'Name differs in DB: “' . (string)$stu['full_name'] . '”.';
                        }
                    }
                }

                $preview[] = [
                    'sid'             => $sid,
                    'name'            => $name,
                    'db_name'         => $stu['full_name'] ?? '',
                    'amount'          => $amount,
                    'scholarship'     => $scholarship,
                    'other_total'     => $other_total,
                    'other_detail'    => $detail,
                    'other_breakdown' => $other_breakdown,
                    'status'          => $status,
                    'note'            => trim($note . ($other_warn !== '' ? ($note !== '' ? ' ' : '') . $other_warn : '')),
                ];
                if ($status === 'ready') {
                    $ready[] = [
                        'sid'          => $sid,
                        'amount'       => $amount,
                        'scholarship'  => $scholarship,
                        'other_total'  => $other_total,
                        'other_detail' => $detail,
                    ];
                }
            }
            fclose($fh);

            if ($preview !== null) {
                $preview_ready = count($ready);
                $_SESSION[OESM_SESSION_KEY] = [
                    'rows'                 => $ready,
                    'mark_form_id_missing' => $mark_missing ? 1 : 0,
                ];
                if (!$preview) {
                    flash_set('error', 'The file contained no usable rows.');
                    $preview = null;
                }
            }
        }
    }
}

$mark_missing_view = !empty($_POST['mark_form_id_missing']);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-file-invoice-dollar me-2 text-success"></i>Old ERP – Totals CSV Merge
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Old ERP Totals Merge</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/accounting/old-erp-totals-merge.php?sample=1" class="btn btn-outline-success btn-sm">
            <i class="fas fa-download me-1"></i> Sample CSV
        </a>
        <a href="<?= APP_URL ?>/student-accounts/bulk-proof-upload.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-images me-1"></i> Upload Proof ZIP (screenshots)
        </a>
        <a href="<?= APP_URL ?>/accounting/old-erp-bulk-merge.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-csv me-1"></i> Classic Bulk Merge (per receipt)
        </a>
        <a href="<?= APP_URL ?>/accounting/old-erp-proof-audit.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-magnifying-glass-dollar me-1"></i> Proof Audit / Clear Import
        </a>
    </div>
</div>

<?= flash_show() ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>How it works:</strong> upload a CSV with the columns
            <code>Student ID</code>, <code>Student Name</code>,
            <code>Amount Paid (Incl. Admission &amp; Registration)</code>,
            <code>Scholarship Amount</code>, <code>Other Fees Total</code>,
            <code>Other Fees Detail</code>. No receipt numbers are needed — the whole
            <strong>Amount Paid</strong> is allocated automatically: Admission → Form Fee → ID Card Fee →
            Registration (per semester) → monthly tuition (earliest months first). The
            <strong>Scholarship Amount</strong> is merged into monthly tuition as clearly-marked
            <em>OLD-ERP SCHOLARSHIP</em> rows (so those months stop showing dues while staying
            identifiable as scholarship, not cash). <strong>Other Fees</strong> may be itemised in the Detail
            column as <code>Head: amount; Head: amount</code> — e.g.
            <code>Late Fine: 1000; Special Examination (Mid Term): 1000</code> — and each head is
            recorded as its own <em>additional payment</em> (outside the monthly schedule, never counted
            in monthly tuition) under the matching head (Late Fine, Library Late Fine, Special Exam,
            Re-Take, Improvement, Transcript, Testimonial, Syllabus, Remedial, Re-Registration,
            Re-Exam, Re-Admission, Certificates, Convocation, English Language, ID Card Replacement,
            Advocateship…) or under <em>Other</em> with the head
            name from the CSV written on the payment note. Student IDs match with or without leading
            zeros; a student whose old-ERP records already cover the CSV total is
            <strong>skipped</strong>, so re-uploading is always safe. Everything is recorded as
            <strong>old-ERP memo payments</strong> (never counted as new income), and a mistaken merge can
            be cleared per student / batch / program / department from the Proof Audit page.
            Proof screenshots: bundle them as <code>{student id}.png</code> in a ZIP and use
            <strong>Upload Proof ZIP</strong> above — each image is attached to the student as an
            OLD ERP Proof.
        </div>
    </div>
</div>

<!-- ── Step 1: upload ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Step 1 — Upload the Totals CSV</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <div class="col-md-5">
                <label class="form-label fw-semibold">CSV file <span class="text-danger">*</span></label>
                <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
            </div>
            <div class="col-md-5">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="mark_form_id_missing" value="1"
                           id="mark_missing" <?= $mark_missing_view ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mark_missing">
                        <strong>Form &amp; ID Card fee is MISSING in the old ERP</strong> for this batch
                        <small class="text-muted d-block">
                            The head is waived for every student in this file: it is
                            <strong>not marked paid</strong> and <strong>not counted as due</strong> —
                            it simply disappears from dues, statements and reports (logged per student,
                            reversible by clearing the flag).
                        </small>
                    </label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-success w-100"><i class="fas fa-list-check me-1"></i>Validate &amp; Preview</button>
            </div>
        </form>
    </div>
</div>

<?php if (is_array($preview)): ?>
<!-- ── Step 2: preview & confirm ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-table-list me-2 text-muted"></i>Step 2 — Preview (<?= count($preview) ?> row(s), <?= (int)$preview_ready ?> ready)</span>
        <form method="post" onsubmit="return confirm('Merge <?= (int)$preview_ready ?> student(s) from this file as old-ERP memo payments?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn btn-success btn-sm" <?= $preview_ready > 0 ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i>Confirm &amp; Merge <?= (int)$preview_ready ?> Student(s)
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:560px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Student ID</th>
                        <th>Name (CSV)</th>
                        <th class="text-end">Amount Paid</th>
                        <th class="text-end">Scholarship</th>
                        <th class="text-end">Other Fees</th>
                        <th>Other Detail</th>
                        <th>Status</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $p): ?>
                    <tr class="<?= $p['status'] === 'failed' ? 'table-danger' : ($p['status'] === 'skipped' ? 'table-secondary' : '') ?>">
                        <td class="font-monospace"><?= h($p['sid']) ?></td>
                        <td><?= h($p['name']) ?></td>
                        <td class="text-end"><?= h(acc_fmt((float)$p['amount'])) ?></td>
                        <td class="text-end"><?= $p['scholarship'] > 0 ? h(acc_fmt((float)$p['scholarship'])) : '—' ?></td>
                        <td class="text-end"><?= $p['other_total'] > 0 ? h(acc_fmt((float)$p['other_total'])) : '—' ?></td>
                        <td>
                            <?php if (!empty($p['other_breakdown'])): ?>
                                <?php foreach ($p['other_breakdown'] as $ob): ?>
                                    <div class="small"><?= h($ob) ?></div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?= h($p['other_detail']) ?: '—' ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'ready'): ?>
                            <span class="badge bg-success">Ready</span>
                            <?php elseif ($p['status'] === 'skipped'): ?>
                            <span class="badge bg-secondary">Skipped</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Failed</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= h($p['note']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
