<?php
/**
 * Old ERP – Proof Audit & Auto-Fix
 *
 * The old ERP frequently lists the SAME month twice on a student's statement
 * (e.g. January…June 2025 each paid twice). The bulk CSV merge matches each
 * monthly row to the installment with the same calendar month, so the second
 * occurrence was skipped as a duplicate — the student now shows dues that were
 * actually paid in the old ERP.
 *
 * This tool cross-checks every student's OLD ERP proof screenshot (attached in
 * Student Accounts → view, label SFP_OLD_ERP_PROOF_LABEL) against the payments
 * imported into this ERP and the Fee Schedule & Outstanding Balance:
 *
 *   1. Scan a single Student ID, or a whole Department / Program / Batch.
 *   2. The proof image is read with client-side OCR (Tesseract.js — the same
 *      pattern as the Bulk ERP Check runner) and every monthly payment line is
 *      extracted, INCLUDING months that appear more than once.
 *   3. The proof months are compared with the old-ERP payments recorded in
 *      this system. A student is flagged when the proof shows money that was
 *      never imported while the student still shows outstanding dues.
 *   4. Auto-Fix records the missing amounts as Old ERP memo payments on the
 *      earliest months with room — exactly like a bulk-merge row — so the
 *      false dues disappear without double-counting income.
 *
 * Safety
 *   • The fix plan is recomputed SERVER-SIDE from the posted proof lines —
 *     nothing financial from the browser is trusted blindly.
 *   • The total inserted is clamped to the student's outstanding balance, and
 *     any OCR line larger than twice the biggest monthly due is rejected, so
 *     an OCR misread can never overpay an account.
 *   • Only `old_erp` memo payments are ever created; payments collected in
 *     THIS ERP are never modified or deleted by this tool.
 *   • Every fix is stored as an undoable batch (like the bulk merge) and every
 *     voucher carries the note “Old ERP proof audit fix” plus a change-log
 *     trail via the accounting engine.
 */

ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php'; // SFP_OLD_ERP_PROOF_LABEL
require_once __DIR__ . '/../students/helpers.php';         // sm_program_data(), sm_batches()

$page_title = 'Old ERP – Proof Audit & Auto-Fix';

// Amounts within this many BDT are treated as equal (old-ERP rounding).
const OEPA_AMOUNT_TOLERANCE = 5.0;
// A student is flagged / fixed only when the proof shows MORE than this many
// BDT that is not recorded anywhere. Keeps OCR noise from creating payments.
const OEPA_FLAG_TOLERANCE = 100.0;
// Sanity guard: a single OCR proof line larger than this multiple of the
// student's biggest monthly due is a misread and is skipped with a warning.
const OEPA_LINE_SANITY_FACTOR = 2.0;

// ── Small utilities ─────────────────────────────────────────────────────────

function oepa_json(array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function oepa_month_name(int $m): string
{
    static $names = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    return $names[$m] ?? (string)$m;
}

/**
 * Look up a student by ID, tolerant of leading zeros (same rule as the bulk merge).
 */
function oepa_lookup_student(string $sid): ?array
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
 * Flatten a fee summary into chronological monthly slots.
 *
 * @return array<int,array<string,mixed>> Each slot: semester_fee_id,
 *         semester_number, month_number, label, cal_month, cal_year, due,
 *         paid, out.
 */
function oepa_month_slots(array $summary): array
{
    $slots = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $slots[] = [
                'semester_fee_id' => (int)$sem['id'],
                'semester_number' => (int)$sem['semester_number'],
                'month_number'    => (int)$mr['month_number'],
                'label'           => (string)$mr['month_label'],
                'cal_month'       => (int)$mr['cal_month'],
                'cal_year'        => (int)$mr['cal_year'],
                'due'             => round((float)$mr['paid'] + (float)$mr['out'], 2),
                'paid'            => (float)$mr['paid'],
                'out'             => (float)$mr['out'],
            ];
        }
    }
    return $slots;
}

/**
 * A student's live old-ERP monthly tuition payments (memo/posted, not deleted).
 *
 * @return array<int,array<string,mixed>>
 */
function oepa_old_erp_tuition(int $student_pk): array
{
    $stmt = db()->prepare(
        "SELECT sp.id, sp.voucher_id, sp.semester_fee_id, sp.month_number, sp.amount,
                sp.transaction_number, v.voucher_date
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.student_id = ?
           AND sp.fee_type = 'semester_tuition'
           AND sp.payment_method = 'old_erp'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         ORDER BY v.voucher_date ASC, sp.id ASC"
    );
    $stmt->execute([$student_pk]);
    return $stmt->fetchAll();
}

/**
 * Build the per-student snapshot sent to the scanner: chronological monthly
 * slots, each with the old-ERP money already imported on it, plus totals.
 *
 * @return array<string,mixed>|null Null when the student has no fee summary.
 */
function oepa_student_snapshot(int $student_pk): ?array
{
    $summary = acc_student_fee_summary($student_pk);
    if (!$summary) {
        return null;
    }
    $slots = oepa_month_slots($summary);
    if (!$slots) {
        return null;
    }

    // Old-ERP money per slot; unlinked money is reported separately.
    $by_key   = [];
    $unlinked = 0.0;
    foreach (oepa_old_erp_tuition($student_pk) as $p) {
        $key = (int)($p['semester_fee_id'] ?? 0) . ':' . (int)($p['month_number'] ?? 0);
        $by_key[$key]['total'] = ($by_key[$key]['total'] ?? 0.0) + (float)$p['amount'];
        $by_key[$key]['count'] = ($by_key[$key]['count'] ?? 0) + 1;
    }
    $slot_keys = [];
    foreach ($slots as $slot) {
        $slot_keys[(int)$slot['semester_fee_id'] . ':' . (int)$slot['month_number']] = true;
    }
    foreach ($by_key as $key => $agg) {
        if (!isset($slot_keys[$key])) {
            $unlinked += (float)$agg['total'];
        }
    }

    $months    = [];
    $total_out = 0.0;
    $max_due   = 0.0;
    $oe_total  = 0.0;
    $oe_count  = 0;
    foreach ($slots as $slot) {
        $key = (int)$slot['semester_fee_id'] . ':' . (int)$slot['month_number'];
        $oe  = $by_key[$key] ?? ['total' => 0.0, 'count' => 0];
        $months[] = [
            'sfid'  => (int)$slot['semester_fee_id'],
            'sem'   => (int)$slot['semester_number'],
            'mn'    => (int)$slot['month_number'],
            'label' => (string)$slot['label'],
            'cm'    => (int)$slot['cal_month'],
            'cy'    => (int)$slot['cal_year'],
            'due'   => round((float)$slot['due'], 2),
            'out'   => round((float)$slot['out'], 2),
            'oe'    => round((float)$oe['total'], 2),
            'oec'   => (int)$oe['count'],
        ];
        $total_out += (float)$slot['out'];
        $max_due    = max($max_due, (float)$slot['due']);
        $oe_total  += (float)$oe['total'];
        $oe_count  += (int)$oe['count'];
    }

    // Everything RECEIVED for this student in the current ERP — every fee
    // head (admission, form, ID card, registration, tuition, other) and every
    // method (old-ERP memos and this-ERP collections alike). Compared with
    // the sum of the proof's "Received amount" column.
    $rcv = db()->prepare(
        "SELECT sp.fee_type, COALESCE(SUM(sp.amount), 0) AS total
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.student_id = ?
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         GROUP BY sp.fee_type"
    );
    $rcv->execute([$student_pk]);
    $received_total = 0.0;
    $heads = ['admission' => 0.0, 'form_fee' => 0.0, 'id_card_fee' => 0.0, 'registration' => 0.0];
    foreach ($rcv->fetchAll() as $r) {
        $received_total += (float)$r['total'];
        $ft = (string)$r['fee_type'];
        if (array_key_exists($ft, $heads)) {
            $heads[$ft] = round((float)$r['total'], 2);
        }
    }

    return [
        'months'             => $months,
        'total_out'          => round($total_out, 2),
        'max_month_due'      => round($max_due, 2),
        'old_erp_total'      => round($oe_total, 2),
        'old_erp_count'      => $oe_count,
        'old_erp_unlinked'   => round($unlinked, 2),
        'erp_received_total' => round($received_total, 2),
        'erp_heads'          => $heads,
    ];
}

// ── Undo (fix batch) helpers ────────────────────────────────────────────────

function oepa_ensure_batch_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS oepa_fix_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            student_pk INT UNSIGNED NOT NULL,
            student_sid VARCHAR(64) NOT NULL DEFAULT \'\',
            student_name VARCHAR(255) NOT NULL DEFAULT \'\',
            fixed_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            voucher_ids MEDIUMTEXT NOT NULL,
            details MEDIUMTEXT NULL DEFAULT NULL,
            undone_by INT UNSIGNED NULL DEFAULT NULL,
            undone_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function oepa_record_batch(array $stu, array $voucher_ids, float $total, array $details): int
{
    oepa_ensure_batch_table();
    $user = auth_user();
    db()->prepare(
        'INSERT INTO oepa_fix_batches
            (created_by, student_pk, student_sid, student_name, fixed_count, total_amount, voucher_ids, details)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        (int)($user['id'] ?? 0),
        (int)$stu['id'],
        (string)($stu['student_id'] ?? ''),
        (string)($stu['full_name'] ?? ''),
        count($voucher_ids),
        round($total, 2),
        json_encode(array_values(array_unique(array_map('intval', $voucher_ids)))),
        json_encode($details, JSON_UNESCAPED_UNICODE),
    ]);
    return (int)db()->lastInsertId();
}

function oepa_can_undo_batch(array $batch): bool
{
    if (!empty($batch['undone_at'])) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $user = auth_user();
    return $user && (int)$batch['created_by'] === (int)($user['id'] ?? 0);
}

// ── Department scope & filters (same pattern as the Bulk ERP Check) ────────

$f_dept    = (int)($_GET['dept']    ?? 0);
$f_program = (int)($_GET['program'] ?? 0);
$f_batch   = (int)($_GET['batch']   ?? 0);

$filter_sql    = '';
$filter_params = [];
if ($f_dept > 0)    { $filter_sql .= ' AND s.dept_id = ?';    $filter_params[] = $f_dept; }
if ($f_program > 0) { $filter_sql .= ' AND s.program_id = ?'; $filter_params[] = $f_program; }
if ($f_batch > 0)   { $filter_sql .= ' AND s.batch_id = ?';   $filter_params[] = $f_batch; }

$dept_scope   = get_dept_scope();
$scope_sql    = '';
$scope_params = [];
if ($dept_scope !== null) {
    if (empty($dept_scope)) {
        $scope_sql = ' AND 0 = 1';
    } else {
        $phs          = implode(',', array_fill(0, count($dept_scope), '?'));
        $scope_sql    = " AND s.dept_id IN ($phs)";
        $scope_params = array_values($dept_scope);
    }
}

$proof_exists =
    "EXISTS (SELECT 1 FROM student_files stf
              WHERE stf.student_id = p.student_id
                AND stf.file_name  = '" . SFP_OLD_ERP_PROOF_LABEL . "'
                AND stf.mime_type LIKE 'image/%')";

// ── JSON: queue of students to scan ─────────────────────────────────────────
if (($_GET['action'] ?? '') === 'list') {
    $sid      = trim((string)($_GET['sid'] ?? ''));
    $after_id = (int)($_GET['after_id'] ?? 0);

    $queue_params = [];
    if ($sid !== '') {
        $queue_where    = "$proof_exists AND s.student_id = ?";
        $queue_params[] = $sid;
    } else {
        $queue_where = $proof_exists;
    }
    // Single-ID lookups ignore the filters (department scope still applies).
    $use_filter_sql    = ($sid === '') ? $filter_sql : '';
    $use_filter_params = ($sid === '') ? $filter_params : [];

    $db = db();
    $cnt_stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM sfp_packages p
           JOIN students s ON s.id = p.student_id
          WHERE $queue_where $scope_sql $use_filter_sql AND p.id > ?"
    );
    $cnt_stmt->execute(array_merge($queue_params, $scope_params, $use_filter_params, [$after_id]));
    $remaining = (int)$cnt_stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.id AS package_id, p.student_id AS student_pk,
                s.full_name AS student_name, s.student_id AS student_sid,
                (SELECT stf.stored_name
                   FROM student_files stf
                  WHERE stf.student_id = p.student_id
                    AND stf.file_name  = '" . SFP_OLD_ERP_PROOF_LABEL . "'
                    AND stf.mime_type LIKE 'image/%'
                  ORDER BY stf.created_at DESC, stf.id DESC
                  LIMIT 1) AS proof_stored_name
           FROM sfp_packages p
           JOIN students s ON s.id = p.student_id
          WHERE $queue_where $scope_sql $use_filter_sql AND p.id > ?
          ORDER BY p.id ASC
          LIMIT 15"
    );
    $stmt->execute(array_merge($queue_params, $scope_params, $use_filter_params, [$after_id]));

    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['proof_stored_name'])) {
            continue;
        }
        $snap = oepa_student_snapshot((int)$row['student_pk']);
        if ($snap === null) {
            continue; // no fee schedule — nothing to compare
        }
        $items[] = array_merge($snap, [
            'package_id' => (int)$row['package_id'],
            'student_pk' => (int)$row['student_pk'],
            'sid'        => (string)$row['student_sid'],
            'name'       => (string)$row['student_name'],
            'proof_url'  => UPLOAD_URL . '/students/files/' . rawurlencode($row['proof_stored_name']),
            'view_url'   => APP_URL . '/student-accounts/view.php?id=' . (int)$row['package_id'],
        ]);
    }

    oepa_json(['success' => true, 'remaining' => $remaining, 'items' => $items]);
}

// ── JSON: auto-fix one student from the OCR-read proof lines ───────────────
if (($_GET['action'] ?? '') === 'fix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $student_pk = (int)($_POST['student_pk'] ?? 0);
    $rows_raw   = json_decode((string)($_POST['proof_rows'] ?? '[]'), true);
    if ($student_pk <= 0 || !is_array($rows_raw) || !$rows_raw || count($rows_raw) > 200) {
        oepa_json(['success' => false, 'error' => 'Invalid fix request.']);
    }

    // Load and scope-check the student.
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.dept_id, p.id AS package_id
         FROM students s
         JOIN sfp_packages p ON p.student_id = s.id
         WHERE s.id = ?
         LIMIT 1'
    );
    $stmt->execute([$student_pk]);
    $stu = $stmt->fetch();
    if (!$stu || empty($stu['package_id'])) {
        oepa_json(['success' => false, 'error' => 'Student or fee package not found.']);
    }
    if ($dept_scope !== null && !in_array((int)$stu['dept_id'], $dept_scope, true)) {
        oepa_json(['success' => false, 'error' => 'This student is outside your department scope.']);
    }

    $summary = acc_student_fee_summary($student_pk);
    $slots   = $summary ? oepa_month_slots($summary) : [];
    if (!$slots) {
        oepa_json(['success' => false, 'error' => 'The student has no monthly fee schedule to fix.']);
    }

    $max_due = 0.0;
    foreach ($slots as $slot) {
        $max_due = max($max_due, (float)$slot['due']);
    }

    // Sanitise the OCR proof lines: calendar month 1–12, plausible year,
    // positive amount below the sanity ceiling.
    $warnings = [];
    $proof    = [];   // "year-month" (year 0 = unknown) => total
    foreach ($rows_raw as $r) {
        $m = (int)($r['m'] ?? 0);
        $y = isset($r['y']) && $r['y'] !== null && $r['y'] !== '' ? (int)$r['y'] : 0;
        $a = round((float)($r['amount'] ?? 0), 2);
        if ($m < 1 || $m > 12 || $a <= 0 || ($y !== 0 && ($y < 2000 || $y > 2100))) {
            continue;
        }
        if ($max_due > 0 && $a > $max_due * OEPA_LINE_SANITY_FACTOR + OEPA_AMOUNT_TOLERANCE) {
            $warnings[] = oepa_month_name($m) . ($y ? ' ' . $y : '') . ': OCR amount '
                . acc_fmt($a) . ' exceeds twice the biggest monthly due (' . acc_fmt($max_due)
                . ') — looks like a misread, skipped.';
            continue;
        }
        // Resolve an unknown year to the earliest schedule year carrying this
        // calendar month; leave 0 when the month is not in the schedule.
        if ($y === 0) {
            foreach ($slots as $slot) {
                if ((int)$slot['cal_month'] === $m) {
                    $y = (int)$slot['cal_year'];
                    break;
                }
            }
        }
        $key = $y . '-' . $m;
        $proof[$key] = round(($proof[$key] ?? 0.0) + $a, 2);
    }
    if (!$proof) {
        oepa_json(['success' => false, 'error' => 'No usable monthly lines were read from the proof.', 'warnings' => $warnings]);
    }

    // Old-ERP money already recorded. ONLY old_erp-method vouchers are counted
    // against the proof: payments collected in THIS ERP with any other method
    // (cash / bank / mobile banking / online) are genuine new-ERP collections
    // and are never counted or touched.
    //
    // Duplicate-import detection FIRST: money may already exist in this ERP
    // recorded WITH the old_erp method (e.g. collected again using the old-ERP
    // method), duplicating the import. When the old-ERP records EXCEED the
    // proof, the fix deletes every old-ERP tuition MEMO voucher of the student
    // and re-merges fresh from the proof.
    $rebuild_deleted = 0;
    $old_rows        = oepa_old_erp_tuition($student_pk);
    $recorded_total  = 0.0;
    foreach ($old_rows as $p) {
        $recorded_total += (float)$p['amount'];
    }
    $recorded_total = round($recorded_total, 2);
    $proof_total    = round(array_sum($proof), 2);

    if ($recorded_total > $proof_total + OEPA_FLAG_TOLERANCE) {
        // Same safety guard as Undo: only MEMO vouchers where EVERY linked
        // payment belongs to this student and uses the old_erp method.
        $vids = array_values(array_unique(array_map(
            static fn($p) => (int)($p['voucher_id'] ?? 0), $old_rows
        )));
        $chk = db()->prepare(
            "SELECT v.status,
                    COUNT(sp.id) AS total_rows,
                    SUM(sp.payment_method = 'old_erp') AS old_erp_rows,
                    SUM(sp.student_id = ?) AS student_rows
             FROM acc_vouchers v
             LEFT JOIN sfp_payments sp ON sp.voucher_id = v.id
             WHERE v.id = ? AND v.is_deleted = 0
             GROUP BY v.id, v.status"
        );
        foreach ($vids as $vid) {
            if ($vid <= 0) {
                continue;
            }
            try {
                $chk->execute([$student_pk, $vid]);
                $vrow = $chk->fetch();
                if (!$vrow) {
                    continue; // already deleted elsewhere
                }
                if ((string)$vrow['status'] !== 'memo'
                    || (int)$vrow['total_rows'] < 1
                    || (int)$vrow['old_erp_rows'] !== (int)$vrow['total_rows']
                    || (int)$vrow['student_rows'] !== (int)$vrow['total_rows']) {
                    $warnings[] = 'Voucher #' . $vid . ' is not a pure old-ERP memo for this student — kept as is.';
                    continue;
                }
                acc_soft_delete_voucher($vid,
                    'Old ERP proof audit rebuild: old-ERP records exceeded the proof (duplicate import) — removed before re-merging from the proof.');
                $rebuild_deleted++;
            } catch (Throwable $e) {
                $warnings[] = 'Duplicate voucher #' . $vid . ' could not be removed: ' . $e->getMessage();
            }
        }
        // Recompute the schedule and old-ERP records AFTER the deletions.
        $summary = acc_student_fee_summary($student_pk);
        $slots   = $summary ? oepa_month_slots($summary) : [];
        if (!$slots) {
            oepa_json(['success' => false, 'error' => 'The fee schedule disappeared during the rebuild — check the student manually.', 'warnings' => $warnings]);
        }
        $old_rows       = oepa_old_erp_tuition($student_pk);
        $recorded_total = 0.0;
        foreach ($old_rows as $p) {
            $recorded_total += (float)$p['amount'];
        }
        $recorded_total = round($recorded_total, 2);
    }

    // Per-calendar-month view of the remaining old-ERP records (slot map).
    $slot_cal = [];   // "sfid:month_number" => "year-month"
    foreach ($slots as $slot) {
        $slot_cal[(int)$slot['semester_fee_id'] . ':' . (int)$slot['month_number']]
            = (int)$slot['cal_year'] . '-' . (int)$slot['cal_month'];
    }
    $recorded = [];
    foreach ($old_rows as $p) {
        $pkey = (int)($p['semester_fee_id'] ?? 0) . ':' . (int)($p['month_number'] ?? 0);
        $ckey = $slot_cal[$pkey] ?? null;
        if ($ckey !== null) {
            $recorded[$ckey] = round(($recorded[$ckey] ?? 0.0) + (float)$p['amount'], 2);
        }
    }

    // Missing money per proof month decides WHERE to place the fix, but the
    // amount inserted is capped by the GLOBAL deficit (proof total minus ALL
    // old-ERP records, wherever earlier fixes placed them) AND the outstanding
    // balance — the fix can NEVER pay more than the student actually owes.
    // The global cap makes the fix IDEMPOTENT: re-scanning a fixed student
    // inserts nothing, because the global deficit is already zero.
    $missing = [];
    foreach ($proof as $key => $amt) {
        $gap = round($amt - (float)($recorded[$key] ?? 0.0), 2);
        if ($gap > OEPA_AMOUNT_TOLERANCE) {
            $missing[$key] = $gap;
        }
    }
    ksort($missing);
    $total_missing  = round(array_sum($missing), 2);
    $global_missing = round($proof_total - $recorded_total, 2);
    $total_out      = 0.0;
    foreach ($slots as $slot) {
        $total_out += (float)$slot['out'];
    }
    $total_out = round($total_out, 2);

    if (min($total_missing, $global_missing) <= OEPA_FLAG_TOLERANCE) {
        if ($rebuild_deleted > 0) {
            // The rebuild alone already reconciled the account.
            $total_missing = 0.0;
        } else {
            oepa_json(['success' => false, 'error' => 'Nothing to fix: every old-ERP taka on the proof is already recorded (earlier fixes may sit on other months — the totals match).', 'warnings' => $warnings]);
        }
    }
    if ($rebuild_deleted === 0 && $total_out <= OEPA_AMOUNT_TOLERANCE) {
        oepa_json(['success' => false, 'error' => 'Nothing to fix: the student has no outstanding balance.', 'warnings' => $warnings]);
    }

    $budget = max(0.0, min($total_missing, $global_missing, $total_out));

    $cash_account_id   = acc_received_into_account_id_for_payment_method('old_erp');
    $income_account_id = acc_income_account_id_for_fee_type('semester_tuition');
    if ($cash_account_id <= 0 || $income_account_id <= 0) {
        oepa_json(['success' => false, 'error' => 'Old ERP cash / tuition income account is not configured in Accounting Settings.']);
    }

    // Allocate: each missing month goes to its own slot when it still has
    // room, otherwise to the earliest month with room (the duplicate-month
    // case — the first payment already filled the month, the second one
    // belongs to the next unpaid month). Splitting across slots is allowed.
    $room = [];
    foreach ($slots as $i => $slot) {
        $room[$i] = round((float)$slot['out'], 2);
    }
    $today       = date('Y-m-d');
    $voucher_ids = [];
    $details     = [];
    $inserted    = 0.0;
    $errors      = [];

    foreach ($missing as $key => $amt) {
        if ($budget <= OEPA_AMOUNT_TOLERANCE) {
            break;
        }
        [$ky, $km] = array_map('intval', explode('-', (string)$key));
        $take = round(min($amt, $budget), 2);
        $proof_label = oepa_month_name($km) . ($ky ? ' ' . $ky : '');
        $pay_date    = $ky ? sprintf('%04d-%02d-15', $ky, $km) : $today;
        if ($pay_date > $today) {
            $pay_date = $today;
        }

        // Target order: the month itself first, then every later month with room.
        $targets = [];
        foreach ($slots as $i => $slot) {
            if ((int)$slot['cal_year'] === $ky && (int)$slot['cal_month'] === $km) {
                $targets[] = $i;
            }
        }
        foreach ($slots as $i => $slot) {
            if (!in_array($i, $targets, true)) {
                $targets[] = $i;
            }
        }

        foreach ($targets as $i) {
            if ($take <= 0.005) {
                break;
            }
            if ($room[$i] <= OEPA_AMOUNT_TOLERANCE) {
                continue;
            }
            $alloc = round(min($take, $room[$i]), 2);
            $slot  = $slots[$i];
            try {
                $voucher_ids[] = acc_collect_student_fee(
                    (int)$stu['id'],
                    (int)$stu['package_id'],
                    'semester_tuition',
                    (int)$slot['semester_fee_id'],
                    (int)$slot['semester_number'],
                    (int)$slot['month_number'],
                    'old_erp',
                    null,
                    'OLD-ERP-PROOF',
                    $alloc,
                    $cash_account_id,
                    $income_account_id,
                    $pay_date,
                    'Old ERP proof audit fix',
                    'Old ERP proof audit: ' . $proof_label . ' is shown paid on the OLD ERP proof but was '
                        . 'missing from the import (duplicate month skipped by the bulk merge) — recorded on '
                        . $slot['label'] . '.',
                    true
                );
                $room[$i]  = round($room[$i] - $alloc, 2);
                $take      = round($take - $alloc, 2);
                $budget    = round($budget - $alloc, 2);
                $inserted  = round($inserted + $alloc, 2);
                $details[] = [
                    'proof_month' => $proof_label,
                    'placed_on'   => (string)$slot['label'],
                    'amount'      => $alloc,
                ];
            } catch (Throwable $e) {
                $errors[] = $proof_label . ' → ' . $slot['label'] . ': ' . $e->getMessage();
                break;
            }
        }
        if ($take > OEPA_AMOUNT_TOLERANCE) {
            $warnings[] = $proof_label . ': ' . acc_fmt($take)
                . ' could not be placed — no month with room left (the schedule is already fully covered).';
        }
    }

    $batch_id = null;
    if ($voucher_ids || $rebuild_deleted > 0) {
        if ($voucher_ids) {
            try {
                $batch_id = oepa_record_batch($stu, $voucher_ids, $inserted, array_merge($details,
                    $rebuild_deleted > 0 ? [['rebuild_deleted_vouchers' => $rebuild_deleted]] : []));
            } catch (Throwable $e) {
                $errors[] = 'Undo tracking could not be saved: ' . $e->getMessage();
            }
        }
        log_change('accounting', 'UPDATE', (int)$stu['id'],
            (string)$stu['full_name'] . ' (' . (string)$stu['student_id'] . ')',
            'old_erp_proof_audit', '', acc_fmt($inserted),
            'Old ERP proof audit auto-fix: '
            . ($rebuild_deleted > 0 ? $rebuild_deleted . ' duplicate old-ERP voucher(s) deleted and re-merged; ' : '')
            . count($voucher_ids) . ' payment(s) totalling '
            . acc_fmt($inserted) . ' recorded from the OLD ERP proof'
            . ($batch_id ? ' (fix batch #' . $batch_id . ')' : '') . '.');
    }

    oepa_json([
        'success'         => (bool)$voucher_ids || $rebuild_deleted > 0,
        'error'           => ($voucher_ids || $rebuild_deleted > 0) ? null : ($errors ? implode(' ', $errors) : 'No payment could be recorded.'),
        'fixed_count'     => count($voucher_ids),
        'total_amount'    => $inserted,
        'rebuild_deleted' => $rebuild_deleted,
        'details'         => $details,
        'warnings'        => array_merge($warnings, $errors),
        'batch_id'        => $batch_id,
    ]);
}

// ── POST: undo a fix batch ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'undo') {
    csrf_check();
    oepa_ensure_batch_table();
    $undo_id = (int)($_POST['batch_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM oepa_fix_batches WHERE id = ? LIMIT 1');
    $stmt->execute([$undo_id]);
    $batch = $stmt->fetch();

    if (!$batch) {
        flash_set('danger', 'Fix batch not found.');
    } elseif (!oepa_can_undo_batch($batch)) {
        flash_set('danger', 'Only the user who applied fix batch #' . $undo_id . ' — or a Super Administrator — can undo it.');
    } else {
        // Safety guard (same as the bulk-merge undo): only old-ERP MEMO
        // vouchers where every linked payment uses the old_erp method.
        $ids = json_decode((string)$batch['voucher_ids'], true);
        $ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
        $chk = db()->prepare(
            "SELECT v.status,
                    COUNT(sp.id) AS total_rows,
                    SUM(sp.payment_method = 'old_erp') AS old_erp_rows
             FROM acc_vouchers v
             LEFT JOIN sfp_payments sp ON sp.voucher_id = v.id
             WHERE v.id = ? AND v.is_deleted = 0
             GROUP BY v.id, v.status"
        );
        $deleted = 0;
        $undo_errors = [];
        foreach ($ids as $vid) {
            try {
                $chk->execute([$vid]);
                $vrow = $chk->fetch();
                if (!$vrow) {
                    continue; // already deleted elsewhere
                }
                if ((string)$vrow['status'] !== 'memo'
                    || (int)$vrow['total_rows'] < 1
                    || (int)$vrow['old_erp_rows'] !== (int)$vrow['total_rows']) {
                    throw new RuntimeException('Not an old-ERP memo payment — refusing to delete.');
                }
                acc_soft_delete_voucher($vid, 'Old ERP proof audit undo: fix batch #' . $undo_id . ' reverted.');
                $deleted++;
            } catch (Throwable $e) {
                $undo_errors[] = 'Voucher #' . $vid . ': ' . $e->getMessage();
            }
        }
        $user = auth_user();
        db()->prepare('UPDATE oepa_fix_batches SET undone_by = ?, undone_at = NOW() WHERE id = ?')
            ->execute([(int)($user['id'] ?? 0), $undo_id]);
        log_change('accounting', 'UPDATE', $undo_id,
            'Old ERP proof audit fix batch #' . $undo_id, 'undone', '0', '1',
            'Old ERP proof audit fix undone: ' . $deleted . ' voucher(s) soft-deleted.');
        $msg = 'Fix batch #' . $undo_id . ' undone: ' . $deleted . ' payment voucher(s) removed.';
        if ($undo_errors) {
            $msg .= ' ' . count($undo_errors) . ' could not be reverted: ' . implode(' ', $undo_errors);
        }
        flash_set($undo_errors ? 'warning' : 'success', $msg);
    }
}

// ── POST: clear the old-ERP import (super admin only; optional student scope) ───────────────
// Scoped: with a Student ID only THAT student's old-ERP vouchers are cleared;
// left empty it clears the WHOLE old-ERP import. One set-based UPDATE is used
// (no per-voucher loop), so even tens of thousands of vouchers cannot time
// out / crash the request. Every cleared voucher carries the marker reason
// 'Old ERP import cleared…' — the Restore action matches on that marker.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'clear_import') {
    csrf_check();
    if (!is_super_admin()) {
        flash_set('danger', 'Only a Super Administrator can clear the old-ERP import.');
    } elseif (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'DELETE OLD ERP') {
        flash_set('danger', 'Confirmation text did not match — nothing was deleted.');
    } else {
        set_time_limit(0);
        $user      = auth_user();
        $clear_sid = trim((string)($_POST['clear_student_id'] ?? ''));
        $clear_stu = $clear_sid !== '' ? oepa_lookup_student($clear_sid) : null;
        if ($clear_sid !== '' && !$clear_stu) {
            flash_set('danger', 'Student "' . h($clear_sid) . '" was not found — nothing was deleted.');
        } else {
            // Only vouchers whose payments ALL use the old_erp method — pure
            // old-ERP imports (bulk merge, single old-ERP collections and
            // proof-audit fixes). Vouchers carrying ANY payment collected in
            // this ERP (cash / bank / mobile banking / online) are NEVER
            // touched, so money actually collected in this ERP stays as it is.
            if ($clear_stu) {
                $reason = 'Old ERP import cleared for student ' . (string)$clear_stu['student_id']
                    . ': old-ERP transactions removed (payments collected in this ERP are kept).';
                $upd = db()->prepare(
                    "UPDATE acc_vouchers v
                     JOIN (SELECT sp.voucher_id
                             FROM sfp_payments sp
                            GROUP BY sp.voucher_id
                           HAVING SUM(sp.payment_method <> 'old_erp') = 0
                              AND SUM(COALESCE(sp.student_id, 0) <> ?) = 0) t ON t.voucher_id = v.id
                     SET v.is_deleted = 1, v.deleted_by = ?, v.deleted_at = NOW(), v.delete_reason = ?
                     WHERE v.is_deleted = 0"
                );
                $upd->execute([(int)$clear_stu['id'], (int)($user['id'] ?? 0), $reason]);
                $cleared = (int)$upd->rowCount();
                oepa_ensure_batch_table();
                db()->prepare('DELETE FROM oepa_fix_batches WHERE student_pk = ?')
                    ->execute([(int)$clear_stu['id']]);
                $label = 'student ' . (string)$clear_stu['student_id'];
            } else {
                $reason = 'Old ERP import cleared: every old-ERP transaction removed (payments collected in this ERP are kept).';
                $upd = db()->prepare(
                    "UPDATE acc_vouchers v
                     JOIN (SELECT sp.voucher_id
                             FROM sfp_payments sp
                            GROUP BY sp.voucher_id
                           HAVING SUM(sp.payment_method <> 'old_erp') = 0) t ON t.voucher_id = v.id
                     SET v.is_deleted = 1, v.deleted_by = ?, v.deleted_at = NOW(), v.delete_reason = ?
                     WHERE v.is_deleted = 0"
                );
                $upd->execute([(int)($user['id'] ?? 0), $reason]);
                $cleared = (int)$upd->rowCount();
                oepa_ensure_batch_table();
                db()->exec('DELETE FROM oepa_fix_batches'); // wipe the audit report / undo log
                $label = 'ALL students';
            }
            log_change('accounting', 'DELETE', (int)($clear_stu['id'] ?? 0),
                'Old ERP import (' . $label . ')', 'old_erp_clear_import',
                (string)$cleared, '0',
                'Old ERP import cleared for ' . $label . ': ' . $cleared
                . ' old-ERP voucher(s) soft-deleted with a restorable marker; proof-audit report reset.'
                . ' Payments collected in this ERP were not touched.');
            flash_set('success',
                'Old ERP import cleared for ' . $label . ': ' . $cleared . ' voucher(s) removed.'
                . ' Payments collected in this ERP are untouched.'
                . ' Cleared by mistake? Use "Restore Cleared Old-ERP Vouchers" below.');
        }
    }
}

// ── POST: restore a mistaken clear (super admin only) ──────────────────────
// Un-deletes ONLY vouchers the Clear action soft-deleted (matched on the
// 'Old ERP import cleared…' marker reason) whose payments are all old_erp.
// Vouchers deleted through the normal delete workflow, voucher reversals or
// proof-audit rebuilds are never resurrected.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'restore_clear') {
    csrf_check();
    if (!is_super_admin()) {
        flash_set('danger', 'Only a Super Administrator can restore cleared old-ERP vouchers.');
    } else {
        set_time_limit(0);
        $upd = db()->prepare(
            "UPDATE acc_vouchers v
             JOIN (SELECT sp.voucher_id
                     FROM sfp_payments sp
                    GROUP BY sp.voucher_id
                   HAVING SUM(sp.payment_method <> 'old_erp') = 0) t ON t.voucher_id = v.id
             SET v.is_deleted = 0, v.deleted_by = NULL, v.deleted_at = NULL,
                 v.delete_reason = NULL, v.delete_request_id = NULL
             WHERE v.is_deleted = 1
               AND v.delete_reason LIKE 'Old ERP import cleared%'"
        );
        $upd->execute();
        $restored = (int)$upd->rowCount();
        log_change('accounting', 'UPDATE', 0, 'Old ERP import', 'old_erp_restore_clear',
            '0', (string)$restored,
            'Old ERP clear restored: ' . $restored . ' old-ERP voucher(s) un-deleted (marker-matched only).');
        flash_set('success', $restored . ' old-ERP voucher(s) restored — balances, dues and reports include them again.');
    }
}

// ── HTML page ───────────────────────────────────────────────────────────────
$db = db();
$cnt_stmt = $db->prepare(
    "SELECT COUNT(*)
       FROM sfp_packages p
       JOIN students s ON s.id = p.student_id
      WHERE $proof_exists $scope_sql $filter_sql"
);
$cnt_stmt->execute(array_merge($scope_params, $filter_params));
$scan_total = (int)$cnt_stmt->fetchColumn();

oepa_ensure_batch_table();
$recent_stmt = $db->prepare(
    'SELECT b.*, u.full_name AS created_by_name, x.full_name AS undone_by_name
     FROM oepa_fix_batches b
     LEFT JOIN users u ON u.id = b.created_by
     LEFT JOIN users x ON x.id = b.undone_by
     ORDER BY b.id DESC
     LIMIT 10'
);
$recent_stmt->execute();
$recent_batches = $recent_stmt->fetchAll();

$departments = $db->query(
    'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();
$all_programs = sm_program_data();
$batches      = sm_batches();
if ($dept_scope !== null) {
    $departments = array_values(array_filter(
        $departments,
        fn($d) => in_array((int)$d['id'], $dept_scope, true)
    ));
    $all_programs = array_values(array_filter(
        $all_programs,
        fn($p) => in_array((int)$p['dept_id'], $dept_scope, true)
    ));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-magnifying-glass-dollar me-2 text-success"></i>Old ERP Proof Audit &amp; Auto-Fix
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Old ERP Proof Audit</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/accounting/old-erp-bulk-merge.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-file-csv me-1"></i> Bulk CSV Merge
        </a>
        <a href="<?= APP_URL ?>/accounting/old-erp-remap.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-screwdriver-wrench me-1"></i> Remap Months
        </a>
    </div>
</div>

<?= flash_show() ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>How it works:</strong> the old ERP often lists the <em>same month twice</em>
            (e.g. January–June 2025 each paid twice), but the bulk merge could only place each
            month once — so those students now show dues they already paid. This page OCR-reads
            every student's <strong>OLD ERP proof screenshot</strong>, extracts the monthly
            payment lines (including repeated months), and compares them with the old-ERP
            payments imported into this system and the <em>Fee Schedule &amp; Outstanding
            Balance</em>. Students whose proof shows money that was never imported — while they
            still show dues — are <strong>flagged</strong>, and <strong>Auto-Fix</strong> records
            the missing amounts as Old ERP memo payments on the earliest months with room.
            It also <strong>sums the proof's entire Received-amount column</strong> (monthly
            tuition + Admission + Registration + the bottom <em>Admission Form &amp; ID Card
            Fee</em> line) and compares it with the <strong>total received in this ERP</strong>
            across all fee heads and payment methods; a shortfall is flagged as
            <strong>RECEIVED TOTAL SHORT</strong>. The old ERP's own printed Total is never
            trusted: the line-by-line sum is used, and a wrong printed Total is reported.
            The fix is recomputed server-side, capped at the outstanding balance <em>and</em> at
            the <strong>global old-ERP deficit</strong> (proof total minus ALL old-ERP records,
            wherever earlier fixes placed them) — so <strong>re-scanning a fixed student adds
            nothing</strong>; the totals simply match. Only <strong>old-ERP vouchers</strong> are
            counted against the proof: payments collected in this ERP with any other method are
            genuine new collections and are never counted or touched. If the old-ERP records
            <strong>exceed</strong> the proof (duplicate import, e.g. the same payment collected
            again with the old-ERP method), the student is flagged <strong>DUPLICATE
            IMPORT</strong> and Fix deletes the old-ERP memos and re-merges them fresh from the
            proof. Suspicious OCR amounts are skipped, and every fix batch can be
            <strong>undone</strong> from the list at the bottom. <strong>Keep this tab open</strong>
            while a batch scan runs (≈ 3–8 seconds per student).
        </div>
    </div>
</div>

<!-- ── Filters ── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="dept" id="filter_dept" class="form-select form-select-sm">
                    <option value="">All Depts</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Program</label>
                <select name="program" id="filter_program" class="form-select form-select-sm">
                    <option value="">All Programs</option>
                    <?php foreach ($all_programs as $p): ?>
                    <option value="<?= $p['id'] ?>" data-dept="<?= $p['dept_id'] ?>" <?= $f_program == $p['id'] ? 'selected' : '' ?>>
                        <?= h($p['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold small mb-1">Batch</label>
                <select name="batch" class="form-select form-select-sm">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $f_batch == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-fill" type="submit"><i class="fas fa-filter me-1"></i>Apply Filter</button>
                <?php if ($f_dept || $f_program || $f_batch): ?>
                <a href="<?= APP_URL ?>/accounting/old-erp-proof-audit.php" class="btn btn-outline-secondary btn-sm flex-fill">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var deptSel    = document.getElementById('filter_dept');
    var programSel = document.getElementById('filter_program');
    if (!deptSel || !programSel) return;
    function filterPrograms() {
        var deptId = deptSel.value;
        programSel.querySelectorAll('option[data-dept]').forEach(function (opt) {
            var show = !deptId || opt.dataset.dept === deptId;
            opt.hidden   = !show;
            opt.disabled = !show;
            if (!show && opt.selected) programSel.value = '';
        });
    }
    deptSel.addEventListener('change', filterPrograms);
    filterPrograms();
}());
</script>

<!-- ── Controls ── -->
<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <button type="button" class="btn btn-success" id="run-btn">
            <i class="fas fa-play me-1"></i>Scan Batch (<?= number_format($scan_total) ?> with proof)
        </button>
        <button type="button" class="btn btn-outline-danger" id="stop-btn" disabled>
            <i class="fas fa-stop me-1"></i>Stop
        </button>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="autofix-toggle">
            <label class="form-check-label small" for="autofix-toggle">
                <strong>Auto-fix while scanning</strong>
                <span class="text-muted d-block" style="font-size:.75rem;">Flagged students are fixed immediately; otherwise use the Fix button per row.</span>
            </label>
        </div>
        <div class="input-group input-group-sm" style="max-width:340px;">
            <input type="text" class="form-control" id="scan-one-sid" placeholder="Student ID (e.g. 02826105101071)">
            <button type="button" class="btn btn-outline-primary" id="scan-one-btn">
                <i class="fas fa-magnifying-glass me-1"></i>Scan ID
            </button>
        </div>
        <div class="flex-grow-1" style="min-width:240px;">
            <div class="progress" style="height:22px;">
                <div id="progress-bar" class="progress-bar progress-bar-striped bg-success" role="progressbar" style="width:0%">0%</div>
            </div>
            <div class="small text-muted mt-1" id="status-line">Idle.</div>
        </div>
    </div>
    <div class="card-footer py-2 d-flex gap-4 small flex-wrap">
        <span><i class="fas fa-check-circle text-success me-1"></i>OK: <strong id="cnt-ok">0</strong></span>
        <span><i class="fas fa-triangle-exclamation text-danger me-1"></i>Flagged: <strong id="cnt-issue">0</strong></span>
        <span><i class="fas fa-wand-magic-sparkles text-primary me-1"></i>Fixed: <strong id="cnt-fixed">0</strong></span>
        <span><i class="fas fa-question-circle text-warning me-1"></i>OCR failed: <strong id="cnt-failed">0</strong></span>
        <span><i class="fas fa-list text-muted me-1"></i>Processed: <strong id="cnt-done">0</strong> / <span id="cnt-total"><?= number_format($scan_total) ?></span></span>
    </div>
</div>

<!-- ── Results ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-table-list me-2 text-muted"></i>Results</h6>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="oepaFilter('all')">All</button>
            <button class="btn btn-outline-danger btn-sm" onclick="oepaFilter('issue')">Flagged</button>
            <button class="btn btn-outline-warning btn-sm" onclick="oepaFilter('failed')">OCR failed</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:520px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Student</th>
                        <th class="text-end">Proof received (line sum)</th>
                        <th class="text-end">ERP received</th>
                        <th class="text-end">&Delta; received</th>
                        <th class="text-end">Outstanding</th>
                        <th class="text-end">Missing monthly</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="results-body">
                    <tr id="empty-row"><td colspan="8" class="text-center text-muted py-4">Not started yet.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Recent fix batches (undo) ── -->
<div class="card">
    <div class="card-header py-3 px-4 fw-semibold"><i class="fas fa-clock-rotate-left me-2 text-muted"></i>Recent Fix Batches</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Student</th><th class="text-end">Payments</th>
                        <th class="text-end">Amount</th><th>By</th><th>When</th><th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent_batches): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No fixes applied yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent_batches as $b): ?>
                    <tr>
                        <td><?= (int)$b['id'] ?></td>
                        <td><?= h($b['student_name']) ?> <small class="text-muted"><?= h($b['student_sid']) ?></small></td>
                        <td class="text-end"><?= (int)$b['fixed_count'] ?></td>
                        <td class="text-end"><?= h(acc_fmt((float)$b['total_amount'])) ?></td>
                        <td><?= h($b['created_by_name'] ?? '—') ?></td>
                        <td><?= h($b['created_at']) ?></td>
                        <td class="text-end">
                            <?php if (!empty($b['undone_at'])): ?>
                                <span class="badge bg-secondary" title="Undone by <?= h($b['undone_by_name'] ?? '') ?> at <?= h($b['undone_at']) ?>">Undone</span>
                            <?php elseif (oepa_can_undo_batch($b)): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Undo fix batch #<?= (int)$b['id'] ?>? Every payment it recorded will be removed (soft-delete, audit trail kept).');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="undo">
                                    <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm py-0" type="submit">Undo</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (is_super_admin()): ?>
<!-- ── Danger zone: clear / restore the old-ERP import ── -->
<div class="card border-danger mt-4">
    <div class="card-header py-3 px-4 fw-semibold text-danger">
        <i class="fas fa-trash-can me-2"></i>Danger Zone — Clear Old ERP Import &amp; Report
    </div>
    <div class="card-body">
        <p class="small mb-3">
            Removes old-ERP transactions — bulk-merge rows, single Old-ERP collections and
            proof-audit fixes. <strong>With a Student ID it clears ONLY that student</strong>;
            <strong>left empty it clears the ENTIRE old-ERP import for ALL students</strong> and
            resets the proof-audit report. Payments <strong>collected in this ERP</strong> (cash,
            bank, mobile banking, online) are <strong>never touched</strong>. Vouchers are
            soft-deleted with a restorable marker — a mistaken clear can be undone with the
            Restore button below. Type <code>DELETE OLD ERP</code> to confirm.
        </p>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-center mb-3"
              onsubmit="return confirm(this.clear_student_id.value.trim()
                  ? 'Clear the old-ERP import for student ' + this.clear_student_id.value.trim() + ' only?'
                  : 'No Student ID entered - this clears the ENTIRE old-ERP import for ALL students. Continue?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_import">
            <input type="text" name="clear_student_id" class="form-control form-control-sm"
                   style="max-width:250px;" placeholder="Student ID (empty = ALL students)" autocomplete="off">
            <input type="text" name="confirm_text" class="form-control form-control-sm"
                   style="max-width:220px;" placeholder="Type: DELETE OLD ERP" autocomplete="off" required>
            <button class="btn btn-danger btn-sm" type="submit">
                <i class="fas fa-trash-can me-1"></i>Clear Old ERP Import
            </button>
        </form>
        <form method="post" class="d-flex gap-2 flex-wrap align-items-center"
              onsubmit="return confirm('Restore every old-ERP voucher removed by the Clear action? Vouchers deleted through the normal delete workflow are not affected.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore_clear">
            <button class="btn btn-outline-success btn-sm" type="submit">
                <i class="fas fa-rotate-left me-1"></i>Restore Cleared Old-ERP Vouchers
            </button>
            <span class="small text-muted">Un-deletes only vouchers removed by the Clear action (marker-matched) — nothing else.</span>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    var CFG = {
        listUrl:   '<?= APP_URL ?>/accounting/old-erp-proof-audit.php?action=list&dept=<?= (int)$f_dept ?>&program=<?= (int)$f_program ?>&batch=<?= (int)$f_batch ?>',
        fixUrl:    '<?= APP_URL ?>/accounting/old-erp-proof-audit.php?action=fix',
        csrfField: <?= json_encode(CSRF_TOKEN_NAME) ?>,
        csrfToken: <?= json_encode(csrf_token()) ?>,
        lineTol:   <?= json_encode((float)OEPA_AMOUNT_TOLERANCE) ?>,
        flagTol:   <?= json_encode((float)OEPA_FLAG_TOLERANCE) ?>,
        saneMul:   <?= json_encode((float)OEPA_LINE_SANITY_FACTOR) ?>,
        total:     <?= (int)$scan_total ?>
    };

    var running = false, worker = null, queue = [], afterId = 0, singleMode = false;
    var nOk = 0, nIssue = 0, nFixed = 0, nFailed = 0, nDone = 0;

    function $id(i) { return document.getElementById(i); }
    function setStatus(t) { $id('status-line').textContent = t; }
    function fmt(n) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function setProgress() {
        var pct = CFG.total > 0 ? Math.min(100, Math.round(nDone / CFG.total * 100)) : 100;
        var bar = $id('progress-bar');
        bar.style.width = pct + '%';
        bar.textContent = pct + '%';
        $id('cnt-ok').textContent     = nOk;
        $id('cnt-issue').textContent  = nIssue;
        $id('cnt-fixed').textContent  = nFixed;
        $id('cnt-failed').textContent = nFailed;
        $id('cnt-done').textContent   = nDone;
    }

    // ── OCR: extract the monthly payment lines from the proof text ──────────
    // Each qualifying line yields one payment occurrence {m, y|null, amount},
    // so a month listed twice on the proof produces two rows — exactly the
    // duplicate-month case this tool exists for.
    var MONTHS = { jan:1, feb:2, mar:3, apr:4, may:5, jun:6, jul:7, aug:8, sep:9, oct:10, nov:11, dec:12 };
    var MONTH_RE = /\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t|tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b[\s\-\/\.]{0,3}((?:20)?\d{2})?/i;
    var SKIP_RE  = /payable|monthly\s*pay|old\s*data|current\s*due|balance|schedule/i;
    var TOTAL_RE = /grand\s*total|total\s*received|received\s*total|^\s*total\b/i;
    var DATE_RE  = /\b\d{1,2}[\/\-\.]\d{1,2}[\/\-\.](\d{2,4})\b/;
    // One-time fee heads on the proof (checked most-specific first, so the
    // bottom "Admission Form & ID Card Fee" line is never taken as Admission).
    var HEADS = [
        { key: 'form_id',      label: 'Admission Form & ID Card Fee', re: /admission\s*form|form\s*(?:fee)?\s*&\s*id\s*card|id\s*card\s*&\s*form/i },
        { key: 'form_fee',     label: 'Form Fee',                     re: /form\s*fee/i },
        { key: 'id_card_fee',  label: 'ID Card Fee',                  re: /id\s*card/i },
        { key: 'admission',    label: 'Admission Fee',                re: /admission/i },
        { key: 'registration', label: 'Registration Fee',             re: /registration|\breg\.?\s*fee/i },
        { key: 'other',        label: 'Other Fee',                    re: /re-?take|improvement|special\s*exam|remedial|misc/i }
    ];

    // First plausible amount on the line from the given index, with date
    // tokens stripped so day/year digits are never mistaken for money.
    function lineAmount(line, fromIdx) {
        var tail = line.slice(fromIdx).replace(new RegExp(DATE_RE.source, 'g'), ' ');
        var nums = tail.match(/\d[\d,]*(?:\.\d+)?/g) || [];
        for (var i = 0; i < nums.length; i++) {
            var v = parseFloat(nums[i].replace(/,/g, ''));
            if (!isNaN(v) && v >= 100 && v <= 500000) return v;
        }
        return null;
    }

    function parseProof(text) {
        var monthly = [], heads = [], printedTotal = null, sum = 0;
        String(text).split(/\n/).forEach(function (line) {
            // The old ERP's own printed Total is read but NEVER trusted: the
            // line-by-line sum of the Received-amount column is authoritative.
            var t = TOTAL_RE.exec(line);
            if (t) {
                var tv = lineAmount(line, t.index + t[0].length);
                if (tv !== null && (printedTotal === null || tv > printedTotal)) printedTotal = tv;
                return;
            }
            if (SKIP_RE.test(line)) return;
            var m = MONTH_RE.exec(line);
            if (m) {
                var month = MONTHS[m[1].slice(0, 3).toLowerCase()];
                if (!month) return;
                var year = null;
                if (m[2]) {
                    year = parseInt(m[2], 10);
                    if (year < 100) year += 2000;
                    if (year < 2000 || year > 2100) year = null;
                }
                // Fall back to a dd/mm/yyyy date on the same line for the year.
                var dm = DATE_RE.exec(line);
                if (year === null && dm) {
                    var dy = parseInt(dm[1], 10);
                    if (dy < 100) dy += 2000;
                    if (dy >= 2000 && dy <= 2100) year = dy;
                }
                var amount = lineAmount(line, m.index + m[0].length);
                if (amount === null) return;
                monthly.push({ m: month, y: year, amount: amount });
                sum += amount;
                return;
            }
            // One-time heads: Admission / Registration / the bottom
            // "Admission Form & ID Card Fee" line, etc.
            for (var hIdx = 0; hIdx < HEADS.length; hIdx++) {
                var hm = HEADS[hIdx].re.exec(line);
                if (!hm) continue;
                var ha = lineAmount(line, hm.index + hm[0].length);
                if (ha === null) ha = lineAmount(line, 0);
                if (ha !== null) {
                    heads.push({ key: HEADS[hIdx].key, label: HEADS[hIdx].label, amount: ha });
                    sum += ha;
                }
                return;
            }
        });
        return { monthly: monthly, heads: heads, printedTotal: printedTotal, sum: Math.round(sum * 100) / 100 };
    }

    // ── Compare the proof rows with the imported state ─────────────────────
    function compare(item, parsed) {
        var rows = parsed.monthly;
        var recorded = {}, yearsByMonth = {};
        item.months.forEach(function (mo) {
            var key = mo.cy + '-' + mo.cm;
            recorded[key] = (recorded[key] || 0) + mo.oe;
            (yearsByMonth[mo.cm] = yearsByMonth[mo.cm] || []).push(mo.cy);
        });
        var proof = {}, proofTotal = 0, usedYears = {}, skipped = 0;
        rows.forEach(function (r) {
            if (item.max_month_due > 0 && r.amount > item.max_month_due * CFG.saneMul + CFG.lineTol) {
                skipped++;
                return; // suspicious OCR amount — the server rejects it too
            }
            var y = r.y;
            if (y === null) {
                // Assign year-less occurrences to the schedule's years for that
                // month, in order (1st line → 1st year, 2nd → 2nd year, …).
                var ys = yearsByMonth[r.m] || [];
                var used = usedYears[r.m] || 0;
                y = ys.length ? ys[Math.min(used, ys.length - 1)] : null;
                usedYears[r.m] = used + 1;
                r.y = y;
            }
            var key = (y === null ? '0' : y) + '-' + r.m;
            proof[key] = (proof[key] || 0) + r.amount;
            proofTotal += r.amount;
        });
        // Per-month gaps show WHICH months look unpaid, but the amount that
        // needs fixing is the GLOBAL deficit: proof monthly sum minus EVERY
        // old-ERP taka recorded (earlier fixes may sit on other months). This
        // keeps re-scans at zero instead of flagging the same gap over and
        // over. Old-ERP records ABOVE the proof mean a duplicated import.
        var perMonthGap = 0;
        Object.keys(proof).forEach(function (key) {
            var gap = proof[key] - (recorded[key] || 0);
            if (gap > CFG.lineTol) perMonthGap += gap;
        });
        var missing = Math.min(perMonthGap, Math.max(0, proofTotal - item.old_erp_total));
        var dup = Math.max(0, Math.round((item.old_erp_total - proofTotal) * 100) / 100);
        // Received-total reconciliation: the sum of EVERY Received-amount
        // line on the proof (monthly + admission + registration + the bottom
        // Admission Form & ID Card Fee line) vs everything received in this
        // ERP across all fee heads and methods.
        var notes = [];
        if (parsed.printedTotal !== null && Math.abs(parsed.printedTotal - parsed.sum) > CFG.lineTol) {
            notes.push('The old ERP\'s own printed Total (' + fmt(parsed.printedTotal)
                + ') does not match the line-by-line sum (' + fmt(parsed.sum)
                + ') - the old ERP total is WRONG; the line sum is used.');
        }
        var ph = {};
        parsed.heads.forEach(function (hd) { ph[hd.key] = (ph[hd.key] || 0) + hd.amount; });
        var eh = item.erp_heads || {};
        [
            { label: 'Admission Fee', proof: ph.admission || 0, erp: eh.admission || 0 },
            { label: 'Registration Fee', proof: ph.registration || 0, erp: eh.registration || 0 },
            { label: 'Admission Form & ID Card Fee',
              proof: (ph.form_id || 0) + (ph.form_fee || 0) + (ph.id_card_fee || 0),
              erp: (eh.form_fee || 0) + (eh.id_card_fee || 0) }
        ].forEach(function (hc) {
            if (hc.proof > hc.erp + CFG.lineTol) {
                notes.push(hc.label + ': the proof shows ' + fmt(hc.proof)
                    + ' but the ERP holds only ' + fmt(hc.erp) + '.');
            }
        });
        var recvDiff = Math.round((parsed.sum - item.erp_received_total) * 100) / 100;
        if (recvDiff < -CFG.flagTol) {
            notes.push('The ERP holds ' + fmt(-recvDiff)
                + ' MORE than the proof sum - payments collected in this ERP after the screenshot are normal.');
        }
        if (skipped > 0) {
            notes.push(skipped + ' suspicious OCR line(s) skipped.');
        }
        if (dup > CFG.flagTol) {
            notes.push('Old-ERP records exceed the proof by ' + fmt(dup)
                + ' - the import is DUPLICATED (e.g. the same payment was collected again with the old-ERP method).'
                + ' Fix deletes the old-ERP memos and re-merges them fresh from the proof.');
        }
        var canFix = (missing > CFG.flagTol && item.total_out > CFG.flagTol) || dup > CFG.flagTol;
        var totalShort = recvDiff > CFG.flagTol;
        return {
            proofSum: parsed.sum,
            recvDiff: recvDiff,
            missing: Math.round(missing * 100) / 100,
            dup: dup,
            fixable: Math.min(missing, item.total_out),
            skipped: skipped,
            notes: notes,
            canFix: canFix,
            totalShort: totalShort,
            flagged: canFix || totalShort
        };
    }

    // ── Result rows ─────────────────────────────────────────────────────────
    function addRow(item, cmp, rows, status) {
        var er = $id('empty-row');
        if (er) er.remove();
        var tr = document.createElement('tr');
        tr.dataset.status = status;
        var badge = status === 'ok'
            ? '<span class="badge bg-success">OK</span>'
            : status === 'issue'
                ? ((cmp && cmp.dup > CFG.flagTol ? '<span class="badge bg-danger">DUPLICATE IMPORT</span> ' : '')
                    + (cmp && cmp.canFix && cmp.missing > CFG.flagTol ? '<span class="badge bg-danger">MISSING PAYMENTS</span> ' : '')
                    + (cmp && cmp.totalShort ? '<span class="badge bg-danger">RECEIVED TOTAL SHORT</span>' : ''))
                : status === 'fixed'
                    ? '<span class="badge bg-primary">FIXED</span>'
                    : '<span class="badge bg-warning text-dark">OCR failed – check manually</span>';
        if (status === 'issue') tr.className = 'table-danger';
        var notesHtml = (cmp && cmp.notes && cmp.notes.length)
            ? '<div class="small text-muted">' + esc(cmp.notes.join(' ')) + '</div>' : '';
        var actions = '<a href="' + esc(item.view_url) + '" target="_blank" class="btn btn-outline-primary btn-sm py-0">Open</a>';
        if (status === 'issue' && cmp && cmp.canFix) {
            actions = '<button type="button" class="btn btn-danger btn-sm py-0 me-1 oepa-fix-btn">'
                + '<i class="fas fa-wand-magic-sparkles me-1"></i>Fix</button>' + actions;
        }
        tr.innerHTML =
            '<td>' + esc(item.name) + '<br><small class="text-muted">' + esc(item.sid) + '</small></td>' +
            '<td class="text-end">' + (cmp ? fmt(cmp.proofSum) : '—') + '</td>' +
            '<td class="text-end">' + fmt(item.erp_received_total) + '</td>' +
            '<td class="text-end fw-semibold">' + (cmp ? fmt(cmp.recvDiff) : '—') + '</td>' +
            '<td class="text-end">' + fmt(item.total_out) + '</td>' +
            '<td class="text-end fw-semibold">' + (cmp ? fmt(cmp.missing) : '—') + '</td>' +
            '<td class="oepa-status">' + badge + notesHtml + '</td>' +
            '<td class="text-end oepa-actions">' + actions + '</td>';
        var fixBtn = tr.querySelector('.oepa-fix-btn');
        if (fixBtn) {
            fixBtn.addEventListener('click', function () { doFix(item, rows, tr, fixBtn); });
        }
        var body = $id('results-body');
        body.insertBefore(tr, body.firstChild);
        return tr;
    }

    function markFixed(tr, resp) {
        tr.className = 'table-primary';
        tr.dataset.status = 'fixed';
        tr.querySelector('.oepa-status').innerHTML = '<span class="badge bg-primary">FIXED — '
            + resp.fixed_count + ' payment(s), ' + fmt(resp.total_amount)
            + (resp.rebuild_deleted ? ' (' + resp.rebuild_deleted + ' duplicate voucher(s) deleted & re-merged)' : '')
            + '</span>'
            + (resp.warnings && resp.warnings.length
                ? '<div class="small text-muted">' + esc(resp.warnings.join(' ')) + '</div>' : '');
        var btn = tr.querySelector('.oepa-fix-btn');
        if (btn) btn.remove();
        nFixed++;
        setProgress();
    }

    function doFix(item, rows, tr, btn, cb) {
        if (btn) { btn.disabled = true; btn.textContent = 'Fixing…'; }
        var fd = new FormData();
        fd.append(CFG.csrfField, CFG.csrfToken);
        fd.append('student_pk', item.student_pk);
        fd.append('proof_rows', JSON.stringify(rows));
        fetch(CFG.fixUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.success) {
                    markFixed(tr, resp);
                } else {
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>Fix'; }
                    tr.querySelector('.oepa-status').innerHTML +=
                        '<div class="small text-danger">' + esc((resp && resp.error) || 'Fix failed.') + '</div>';
                }
                if (cb) cb();
            })
            .catch(function () {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>Fix'; }
                tr.querySelector('.oepa-status').innerHTML += '<div class="small text-danger">Network error while fixing.</div>';
                if (cb) cb();
            });
    }

    window.oepaFilter = function (status) {
        document.querySelectorAll('#results-body tr').forEach(function (tr) {
            if (!tr.dataset.status) return;
            tr.style.display = (status === 'all'
                || (status === 'issue'  && (tr.dataset.status === 'issue' || tr.dataset.status === 'fixed'))
                || (status === 'failed' && tr.dataset.status === 'failed')) ? '' : 'none';
        });
    };

    // ── Scan loop ───────────────────────────────────────────────────────────
    function fetchBatch(cb) {
        fetch(CFG.listUrl + '&after_id=' + afterId)
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.success) { cb([]); return; }
                CFG.total = nDone + (resp.remaining || 0);
                $id('cnt-total').textContent = CFG.total.toLocaleString();
                cb(resp.items || []);
            })
            .catch(function () { cb([]); });
    }

    function finishRun(msg) {
        running = false;
        singleMode = false;
        $id('run-btn').disabled = false;
        $id('stop-btn').disabled = true;
        setStatus(msg);
        setProgress();
    }

    function processNext() {
        if (!running) { setStatus('Stopped. Click Scan to continue.'); return; }
        if (queue.length === 0) {
            if (singleMode) { finishRun('Single ID scan finished – see the top row.'); return; }
            setStatus('Loading next batch…');
            fetchBatch(function (items) {
                if (items.length === 0) {
                    finishRun('Done. ' + nIssue + ' student(s) flagged, ' + nFixed + ' fixed, ' + nFailed + ' unreadable proof(s).');
                    return;
                }
                queue = items;
                processNext();
            });
            return;
        }

        var item = queue.shift();
        afterId = Math.max(afterId, item.package_id);
        setStatus('Reading proof for ' + item.name + ' (' + item.sid + ')…');

        worker.recognize(item.proof_url).then(function (res) {
            var text = (res && res.data && res.data.text) || '';
            var parsed = parseProof(text);
            if (parsed.monthly.length === 0 && parsed.heads.length === 0) {
                nFailed++; nDone++;
                addRow(item, null, null, 'failed');
                setProgress();
                setTimeout(processNext, 50);
                return;
            }
            var cmp = compare(item, parsed);
            if (cmp.flagged) {
                nIssue++; nDone++;
                var tr = addRow(item, cmp, parsed.monthly, 'issue');
                setProgress();
                var autoBtn = tr.querySelector('.oepa-fix-btn');
                if ($id('autofix-toggle').checked && autoBtn) {
                    doFix(item, parsed.monthly, tr, autoBtn, function () {
                        setTimeout(processNext, 50);
                    });
                    return;
                }
            } else {
                nOk++; nDone++;
                addRow(item, cmp, parsed.monthly, 'ok');
                setProgress();
            }
            setTimeout(processNext, 50);
        }).catch(function () {
            nFailed++; nDone++;
            addRow(item, null, null, 'failed');
            setProgress();
            setTimeout(processNext, 50);
        });
    }

    function loadTesseract(cb) {
        if (window.Tesseract) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
        s.onload = cb;
        s.onerror = function () { setStatus('Could not load the OCR library (CDN unreachable).'); };
        document.head.appendChild(s);
    }

    function ensureWorker(cb) {
        loadTesseract(function () {
            if (worker) { cb(); return; }
            window.Tesseract.createWorker('eng').then(function (w) {
                worker = w;
                cb();
            }).catch(function (e) {
                finishRun('Could not start the OCR engine: ' + e);
            });
        });
    }

    $id('run-btn').addEventListener('click', function () {
        if (running) return;
        running = true;
        singleMode = false;
        afterId = 0;
        queue = [];
        $id('run-btn').disabled = true;
        $id('stop-btn').disabled = false;
        setStatus('Starting OCR engine…');
        ensureWorker(processNext);
    });

    $id('scan-one-btn').addEventListener('click', function () {
        if (running) return;
        var sid = ($id('scan-one-sid').value || '').trim();
        if (sid === '') { setStatus('Enter a student ID first.'); return; }
        running = true;
        singleMode = true;
        $id('run-btn').disabled = true;
        $id('stop-btn').disabled = false;
        setStatus('Looking up ' + sid + '…');
        fetch(CFG.listUrl + '&sid=' + encodeURIComponent(sid) + '&after_id=0')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var items = (resp && resp.items) || [];
                if (items.length === 0) {
                    finishRun('No account with an OLD ERP proof image found for ID "' + sid + '".');
                    return;
                }
                queue = items;
                ensureWorker(processNext);
            })
            .catch(function (e) { finishRun('Lookup failed: ' + e); });
    });

    $id('stop-btn').addEventListener('click', function () {
        running = false;
        $id('run-btn').disabled = false;
        $id('stop-btn').disabled = true;
    });

    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
