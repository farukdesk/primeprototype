<?php
/**
 * Old ERP – Remap Wrongly Placed Monthly Tuition Payments
 *
 * Repair tool for old-ERP (memo) monthly tuition payments that the bulk CSV
 * merge placed on the wrong installment slot. This typically happened when a
 * student's old-ERP payments started BEFORE their schedule's payment start
 * month (e.g. payments began in December while the schedule starts in
 * January): the December payment was matched to the December slot near the
 * END of the schedule year, leaving the first months showing unpaid while a
 * later month showed paid.
 *
 * The remap re-packs a student's old-ERP monthly tuition payments onto the
 * schedule from the start month onward, in chronological (payment date)
 * order, AROUND any payments already collected in THIS ERP — those keep
 * their slots and are never modified.
 *
 * Scope & safety
 *   • ONLY rows with payment_method = 'old_erp' AND fee_type =
 *     'semester_tuition' (memo vouchers) are ever touched. Payments collected
 *     in this ERP (cash / bank / mobile banking, posted vouchers) are NEVER
 *     modified — the UPDATE statement itself re-asserts these conditions.
 *   • Only the schedule linkage columns (semester_fee_id, semester_number,
 *     month_number) are updated. Amounts, vouchers, dates and receipt numbers
 *     are never changed, so money figures, the books and receipts stay intact.
 *   • Two-step: preview first, then confirm. The plan is recomputed
 *     server-side at confirm time — nothing from the browser is trusted.
 *   • The whole apply runs in a transaction and every updated row is written
 *     to the immutable change log.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Old ERP – Remap Monthly Payments';

// A month counts as SHORT only beyond this many BDT. Small differences up to
// this amount — poisha rounding residues (e.g. a due of 3,104.09 next to
// 3,104.07) and minor old-ERP discrepancies — are never treated as gaps: they
// don't flag the student in the scan and are never re-balanced or moved.
const OERM_GAP_TOLERANCE = 20.0;

/**
 * Look up a student by ID, tolerant of leading zeros (same rule as the bulk merge).
 */
function oerm_lookup_student(string $sid): ?array
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
 * Load a student's live monthly tuition payments, split into:
 *   [0] fixed   – payments collected in THIS ERP (never touched), and
 *   [1] movable – old-ERP memo payments (remap candidates),
 * both in chronological (payment date, then id) order.
 *
 * @return array{0: array<int,array<string,mixed>>, 1: array<int,array<string,mixed>>}
 */
function oerm_load_payments(int $package_id): array
{
    $stmt = db()->prepare(
        "SELECT sp.id, sp.semester_fee_id, sp.semester_number, sp.month_number,
                sp.payment_method, sp.transaction_number, sp.amount,
                v.voucher_date, v.voucher_number, v.status AS voucher_status
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.package_id = ?
           AND sp.fee_type = 'semester_tuition'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         ORDER BY v.voucher_date ASC, sp.id ASC"
    );
    $stmt->execute([$package_id]);

    $fixed = [];
    $movable = [];
    foreach ($stmt->fetchAll() as $r) {
        if (($r['payment_method'] ?? '') === 'old_erp') {
            $movable[] = $r;
        } else {
            $fixed[] = $r;
        }
    }
    return [$fixed, $movable];
}

/**
 * Does this student actually have a FIXABLE gap?
 *
 * A student needs fixing ONLY when some month is short (beyond the rounding
 * tolerance) while a LATER month holds old-ERP money that could fill it. A
 * student whose months are all paid — with only a trailing shortfall at the
 * end of the schedule because the money simply ran out — is fine and up to
 * date, and must never be flagged or touched.
 */
function oerm_has_fixable_gap(array $summary, array $fixed, array $movable): bool
{
    $due  = [];
    $paid = [];
    $oe   = [];
    $keys = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $key = (int)$sem['id'] . ':' . (int)$mr['month_number'];
            $keys[] = $key;
            $due[$key]  = (float)$mr['paid'] + (float)$mr['out'];
            $paid[$key] = 0.0;
            $oe[$key]   = 0.0;
        }
    }
    if (!$keys) {
        return false;
    }
    // Money not linked to a specific installment — e.g. a lump-sum cash
    // collection covering several months in one payment row — goes into a
    // pool that is spread SEQUENTIALLY into the earliest short months,
    // exactly how the fee summary displays it. A month covered by such a
    // lump sum is therefore never mistaken for a gap.
    $pool = 0.0;
    foreach ($fixed as $p) {
        $key = (int)($p['semester_fee_id'] ?? 0) . ':' . (int)($p['month_number'] ?? 0);
        if (isset($paid[$key])) {
            $paid[$key] += (float)$p['amount'];
            // Month-linked this-ERP money in a LATER month is pullable too:
            // the shortfall re-balancer may move it backward to fill an
            // earlier unpaid month (e.g. August due while September and
            // October are paid by cash).
            $oe[$key]   += (float)$p['amount'];
        } else {
            $pool += (float)$p['amount'];
        }
    }
    foreach ($movable as $p) {
        $key = (int)($p['semester_fee_id'] ?? 0) . ':' . (int)($p['month_number'] ?? 0);
        if (isset($paid[$key])) {
            $paid[$key] += (float)$p['amount'];
            $oe[$key]   += (float)$p['amount'];
        } else {
            $pool += (float)$p['amount'];
        }
    }
    $n = count($keys);
    if ($pool > 0.005) {
        for ($i = 0; $i < $n && $pool > 0.005; $i++) {
            $k    = $keys[$i];
            $need = round($due[$k] - $paid[$k], 2);
            if ($need <= 0.005) {
                continue;
            }
            $alloc     = min($need, $pool);
            $paid[$k]  = round($paid[$k] + $alloc, 2);
            $pool      = round($pool - $alloc, 2);
        }
    }
    for ($i = 0; $i < $n; $i++) {
        $k = $keys[$i];
        if (round($due[$k] - $paid[$k], 2) <= OERM_GAP_TOLERANCE) {
            continue;
        }
        // This month is genuinely short — fixable only if a LATER month still
        // holds month-linked tuition money (old-ERP or this-ERP) that could be
        // pulled back to fill it.
        for ($j = $i + 1; $j < $n; $j++) {
            if ($oe[$keys[$j]] > OERM_GAP_TOLERANCE) {
                return true;
            }
        }
        // Gap exists but there is no later tuition money — the money simply
        // ran out here (normal trailing shortfall). Nothing this tool can or
        // should move.
        return false;
    }
    return false;
}

/**
 * Build the remap plan for one student.
 *
 * Semesters are walked chronologically. Payments collected in THIS ERP keep
 * their slots and pre-consume capacity/months; old-ERP payments are then
 * placed — in payment-date order — into the earliest semester that still has
 * tuition capacity, taking the earliest free month slot in that semester.
 *
 * @return array{plan: array<int,array<string,mixed>>, changed: int, warnings: string[]}
 */
function oerm_build_plan(array $summary, array $fixed, array $movable): array
{
    $semesters = $summary['semesters'] ?? [];

    $order        = [];  // chronological semester_fee_id list
    $caps         = [];  // sfid => remaining tuition capacity (due − this-ERP paid)
    $months_int   = [];  // sfid => months per semester
    $occupied     = [];  // sfid => [month_number => true]
    $sem_no       = [];  // sfid => semester_number
    $sem_label    = [];  // sfid => human label
    $month_labels = [];  // sfid => [month_number => calendar label]

    foreach ($semesters as $sem) {
        $sfid = (int)$sem['id'];
        $order[]            = $sfid;
        $caps[$sfid]        = (float)($sem['tuition_due'] ?? 0);
        $months_int[$sfid]  = max(1, (int)($sem['months_per_sem'] ?? 1));
        $occupied[$sfid]    = [];
        $sem_no[$sfid]      = (int)$sem['semester_number'];
        $sem_label[$sfid]   = ($sem['semester_label'] ?? '') !== '' && $sem['semester_label'] !== null
            ? (string)$sem['semester_label']
            : ('Semester ' . (int)$sem['semester_number']);
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $month_labels[$sfid][(int)$mr['month_number']] = (string)($mr['month_label'] ?? '');
        }
    }

    // Payments collected in THIS ERP keep their place: consume capacity + months.
    $plan_unlinked = 0.0;
    foreach ($fixed as $p) {
        $sfid = (int)($p['semester_fee_id'] ?? 0);
        if ($sfid && isset($caps[$sfid])) {
            $caps[$sfid] -= (float)$p['amount'];
            if (!empty($p['month_number'])) {
                $occupied[$sfid][(int)$p['month_number']] = true;
            }
        } else {
            $plan_unlinked += (float)$p['amount'];
        }
    }
    // This-ERP money not linked to a semester (e.g. a lump-sum cash collection)
    // still consumes tuition capacity — spread it over the semesters in order,
    // exactly how the fee summary applies it, so old-ERP rows are never packed
    // into space that cash already fills.
    foreach ($order as $u_sfid) {
        if ($plan_unlinked <= 0.005) {
            break;
        }
        if ($caps[$u_sfid] > 0.005) {
            $u_take = min($caps[$u_sfid], $plan_unlinked);
            $caps[$u_sfid]  -= $u_take;
            $plan_unlinked  -= $u_take;
        }
    }

    $plan     = [];
    $changed  = 0;
    $warnings = [];

    foreach ($movable as $p) {
        $amount = (float)$p['amount'];

        // Earliest semester that still has tuition capacity.
        $target = null;
        foreach ($order as $sfid) {
            if ($caps[$sfid] > 0.005) {
                $target = $sfid;
                break;
            }
        }

        if ($target === null) {
            $warnings[] = 'Payment ' . (string)$p['voucher_number'] . ' (' . acc_fmt($amount)
                . ') exceeds the remaining tuition capacity of the whole schedule — left unchanged.';
            $plan[] = [
                'id'             => (int)$p['id'],
                'voucher_number' => (string)$p['voucher_number'],
                'voucher_date'   => (string)$p['voucher_date'],
                'receipt'        => (string)($p['transaction_number'] ?? ''),
                'amount'         => $amount,
                'old_sfid'       => (int)($p['semester_fee_id'] ?? 0),
                'old_sem'        => (int)($p['semester_number'] ?? 0),
                'old_month'      => (int)($p['month_number'] ?? 0),
                'new_sfid'       => (int)($p['semester_fee_id'] ?? 0),
                'new_sem'        => (int)($p['semester_number'] ?? 0),
                'new_month'      => (int)($p['month_number'] ?? 0),
                'new_sem_label'  => '',
                'new_month_label'=> '',
                'changed'        => false,
            ];
            continue;
        }

        $caps[$target] -= $amount;

        // Earliest free month slot within the target semester.
        $m = null;
        for ($i = 1; $i <= $months_int[$target]; $i++) {
            if (empty($occupied[$target][$i])) {
                $m = $i;
                break;
            }
        }
        if ($m === null) {
            $m = $months_int[$target];
        }
        $occupied[$target][$m] = true;

        $is_changed = ((int)($p['semester_fee_id'] ?? 0) !== $target)
                   || ((int)($p['month_number'] ?? 0) !== $m);
        if ($is_changed) {
            $changed++;
        }

        $plan[] = [
            'id'              => (int)$p['id'],
            'voucher_number'  => (string)$p['voucher_number'],
            'voucher_date'    => (string)$p['voucher_date'],
            'receipt'         => (string)($p['transaction_number'] ?? ''),
            'amount'          => $amount,
            'old_sfid'        => (int)($p['semester_fee_id'] ?? 0),
            'old_sem'         => (int)($p['semester_number'] ?? 0),
            'old_month'       => (int)($p['month_number'] ?? 0),
            'new_sfid'        => $target,
            'new_sem'         => $sem_no[$target] ?? 0,
            'new_month'       => $m,
            'new_sem_label'   => $sem_label[$target] ?? '',
            'new_month_label' => $month_labels[$target][$m] ?? '',
            'changed'         => $is_changed,
        ];
    }

    return ['plan' => $plan, 'changed' => $changed, 'warnings' => $warnings];
}

/**
 * Option lists for the scan filters (Department, Program, Batch).
 *
 * @return array{0:array,1:array,2:array}
 */
function oerm_filter_options(): array
{
    $depts = db()->query(
        'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
    )->fetchAll();
    $programs = db()->query(
        'SELECT id, dept_id, program_name FROM dept_academic_programs WHERE is_active = 1 ORDER BY program_name ASC'
    )->fetchAll();
    $batches = db()->query(
        'SELECT id, name FROM student_batches WHERE is_active = 1 ORDER BY sort_order, name ASC'
    )->fetchAll();
    return [$depts, $programs, $batches];
}

/**
 * Scan students with old-ERP monthly tuition payments — optionally filtered by
 * Department / Program / Batch — and return only those whose payments would
 * move, i.e. whose months are misaligned (an earlier month left unpaid while a
 * later month shows paid).
 *
 * The batch filter matches the student's home batch OR an active batch
 * transfer, the same rule Student Management uses.
 *
 * @return array<int,array<string,mixed>>
 */
function oerm_scan_affected(int $f_dept, int $f_program, int $f_batch, int $limit = 1000): array
{
    $where  = '';
    $params = [];
    if ($f_dept > 0) {
        $where   .= ' AND s.dept_id = ?';
        $params[] = $f_dept;
    }
    if ($f_program > 0) {
        $where   .= ' AND s.program_id = ?';
        $params[] = $f_program;
    }
    if ($f_batch > 0) {
        $where   .= ' AND (s.batch_id = ? OR s.id IN (SELECT sbt.student_id FROM student_batch_transfers sbt WHERE sbt.to_batch_id = ? AND sbt.is_active = 1))';
        $params[] = $f_batch;
        $params[] = $f_batch;
    }

    $stmt = db()->prepare(
        "SELECT DISTINCT s.id AS student_pk, s.student_id, s.full_name, p.id AS package_id,
                d.name AS dept_name, pr.program_name, b.name AS batch_name
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         JOIN students s     ON s.id = sp.student_id
         JOIN sfp_packages p ON p.id = sp.package_id
         LEFT JOIN dept_departments d        ON d.id  = s.dept_id
         LEFT JOIN dept_academic_programs pr ON pr.id = s.program_id
         LEFT JOIN student_batches b         ON b.id  = s.batch_id
         WHERE sp.payment_method = 'old_erp'
           AND sp.fee_type = 'semester_tuition'
           AND v.is_deleted = 0" . $where . "
         ORDER BY s.student_id
         LIMIT " . max(1, $limit)
    );
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $cand) {
        $csum = acc_student_fee_summary((int)$cand['student_pk']);
        if (!$csum) {
            continue;
        }
        [$cfixed, $cmovable] = oerm_load_payments((int)$cand['package_id']);
        if (!$cmovable) {
            continue;
        }
        // Only students with a REAL gap are listed: a short month with old-ERP
        // money in a later month. Fully up-to-date students — including those
        // with only a trailing shortfall where the money simply ran out — are
        // never flagged.
        if (!oerm_has_fixable_gap($csum, $cfixed, $cmovable)) {
            continue;
        }
        $cplan      = oerm_build_plan($csum, $cfixed, $cmovable);
        $ctransfers = oerm_build_shortfall_plan($csum, $cfixed, $cplan['plan']);
        if ($cplan['changed'] > 0 || $ctransfers) {
            $rows[] = [
                'student_id'   => (string)$cand['student_id'],
                'full_name'    => (string)$cand['full_name'],
                'dept_name'    => (string)($cand['dept_name'] ?? ''),
                'program_name' => (string)($cand['program_name'] ?? ''),
                'batch_name'   => (string)($cand['batch_name'] ?? ''),
                'movable'      => count($cmovable),
                'changed'      => (int)$cplan['changed'],
                'shifts'       => count($ctransfers),
                'shift_amount' => array_sum(array_map(static fn(array $t): float => (float)$t['amount'], $ctransfers)),
            ];
        }
    }
    return $rows;
}

/**
 * Apply the remap auto-fix for ONE student (used by the bulk fix).
 *
 * The plan is recomputed SERVER-SIDE — nothing from the browser is trusted —
 * and applied in a transaction. Only old-ERP (memo) monthly tuition rows can
 * move; amounts, vouchers, dates and receipts never change, and every moved
 * row is written to the immutable change log.
 *
 * @return array{0:int, 1:?string, 2:array} [payments moved, error message or null, move list for undo]
 */
function oerm_apply_student_fix(string $sid): array
{
    $student = oerm_lookup_student($sid);
    if (!$student) {
        return [0, 'Student ' . $sid . ': not found.', []];
    }
    if ((int)($student['package_id'] ?? 0) <= 0) {
        return [0, 'Student ' . $sid . ': no fee package in the current system.', []];
    }
    $summary = acc_student_fee_summary((int)$student['id']);
    if (!$summary) {
        return [0, 'Student ' . $sid . ': could not load the fee summary.', []];
    }
    [$fixed, $movable] = oerm_load_payments((int)$student['package_id']);
    if (!$movable) {
        return [0, null, []];
    }
    if (!oerm_has_fixable_gap($summary, $fixed, $movable)) {
        return [0, null, []]; // fully up to date — never touch a fine student
    }
    $plan      = oerm_build_plan($summary, $fixed, $movable);
    $transfers = oerm_build_shortfall_plan($summary, $fixed, $plan['plan']);
    if ((int)$plan['changed'] === 0 && !$transfers) {
        return [0, null, []];
    }

    $db = db();
    $db->beginTransaction();
    try {
        // The WHERE clause re-asserts the safety scope: only old-ERP monthly
        // tuition rows of THIS package can ever move.
        $upd = $db->prepare(
            "UPDATE sfp_payments
             SET semester_fee_id = ?, semester_number = ?, month_number = ?
             WHERE id = ?
               AND package_id = ?
               AND fee_type = 'semester_tuition'
               AND payment_method = 'old_erp'"
        );
        $updated = 0;
        $moves   = [];
        foreach ($plan['plan'] as $row) {
            if (!$row['changed']) {
                continue;
            }
            $upd->execute([
                (int)$row['new_sfid'],
                (int)$row['new_sem'],
                (int)$row['new_month'],
                (int)$row['id'],
                (int)$student['package_id'],
            ]);
            if ($upd->rowCount() > 0) {
                $updated++;
                log_change(
                    'accounting',
                    'UPDATE',
                    (int)$row['id'],
                    'Payment #' . (int)$row['id'] . ' / ' . $row['voucher_number'],
                    'schedule_slot',
                    'sem ' . $row['old_sem'] . ' / month ' . $row['old_month'],
                    'sem ' . $row['new_sem'] . ' / month ' . $row['new_month'],
                    'Old ERP bulk auto-fix for ' . ($student['full_name'] ?? '') . ' (' . ($student['student_id'] ?? '') . '): '
                    . 'payment moved to ' . $row['new_sem_label'] . ' – Month ' . $row['new_month']
                    . ($row['new_month_label'] !== '' ? ' (' . $row['new_month_label'] . ')' : '')
                    . '. Misaligned month correction; amount/voucher unchanged.'
                );
                $moves[] = [
                    'payment_id'  => (int)$row['id'],
                    'voucher'     => (string)$row['voucher_number'],
                    'student_sid' => (string)($student['student_id'] ?? $sid),
                    'amount'      => (float)$row['amount'],
                    'from' => [
                        'semester_fee_id' => (int)($row['old_sfid'] ?? 0),
                        'semester_number' => (int)$row['old_sem'],
                        'month_number'    => (int)$row['old_month'],
                    ],
                    'to' => [
                        'semester_fee_id' => (int)$row['new_sfid'],
                        'semester_number' => (int)$row['new_sem'],
                        'month_number'    => (int)$row['new_month'],
                    ],
                ];
            }
        }
        // Phase 2 — shortfall re-balance: pull any missing amount of an
        // earlier month from the latest month still holding old-ERP money
        // (e.g. April short by 1.42 while June is the last paid month:
        // exactly 1.42 moves from June to April, the rest stays in June).
        [$t_applied, $t_moves] = oerm_execute_transfers($transfers, (int)$student['package_id'], $student);
        $updated += $t_applied;
        foreach ($t_moves as $t_mv) {
            $moves[] = $t_mv;
        }
        $db->commit();
        return [$updated, null, $moves];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('old-erp-remap bulk fix failed for ' . $sid . ': ' . $e->getMessage());
        return [0, 'Student ' . $sid . ': fix failed and was fully rolled back — no payment was modified.', []];
    }
}

/**
 * Build the shortfall re-balance plan (phase 2, runs after the whole-row remap).
 *
 * When an EARLIER month is short by a partial amount while a LATER month holds
 * old-ERP money, exactly the missing amount is pulled from the LAST paid month
 * — e.g. April due 6,208.34 but paid 6,206.92 (short 1.42) while June is the
 * last paid month: exactly 1.42 moves from the June payment to April and the
 * rest stays in June. Only old-ERP money ever moves; the student's total paid
 * never changes and nothing is deleted.
 *
 * @param array $summary   acc_student_fee_summary() result (per-month dues).
 * @param array $fixed     This-ERP payments (never touched) — they pin amounts.
 * @param array $plan_rows Rows from oerm_build_plan()['plan'] — the old-ERP
 *                         rows at their POST-remap slots.
 * @return array<int,array<string,mixed>> Transfer list (type: move|split).
 */
function oerm_build_shortfall_plan(array $summary, array $fixed, array $plan_rows): array
{
    // Chronological slots with their due amount and a human label.
    $slots = [];
    $index = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $key = (int)$sem['id'] . ':' . (int)$mr['month_number'];
            $index[$key] = count($slots);
            $slots[] = [
                'sfid'    => (int)$sem['id'],
                'sem'     => (int)$sem['semester_number'],
                'month'   => (int)$mr['month_number'],
                'label'   => 'Semester ' . (int)$sem['semester_number'] . ' – Month ' . (int)$mr['month_number']
                    . ((string)($mr['month_label'] ?? '') !== '' ? ' (' . (string)$mr['month_label'] . ')' : ''),
                'due'     => (float)$mr['paid'] + (float)$mr['out'],
                'paid'    => 0.0,
                'oe_rows' => [],
            ];
        }
    }
    if (!$slots) {
        return [];
    }

    // This-ERP payments pin their amounts; old-ERP rows are the movable pool.
    // This-ERP money without an installment linkage (e.g. a lump-sum cash
    // collection) is collected into a pool and spread sequentially below.
    $unlinked = 0.0;
    foreach ($fixed as $p) {
        $key = (int)($p['semester_fee_id'] ?? 0) . ':' . (int)($p['month_number'] ?? 0);
        if (isset($index[$key])) {
            $slots[$index[$key]]['paid'] += (float)$p['amount'];
            // Month-linked this-ERP rows join the donor pool: when an earlier
            // month is short, they can be pulled BACKWARD from a later month
            // (never forward). Old-ERP rows are added after this loop, so
            // within the same month old-ERP money is taken before cash.
            $slots[$index[$key]]['oe_rows'][] = [
                'id'      => (int)$p['id'],
                'voucher' => (string)$p['voucher_number'],
                'amount'  => (float)$p['amount'],
            ];
        } else {
            $unlinked += (float)$p['amount'];
        }
    }
    foreach ($plan_rows as $r) {
        $key = (int)$r['new_sfid'] . ':' . (int)$r['new_month'];
        if (!isset($index[$key])) {
            continue;
        }
        $i = $index[$key];
        $slots[$i]['paid'] += (float)$r['amount'];
        $slots[$i]['oe_rows'][] = [
            'id'      => (int)$r['id'],
            'voucher' => (string)$r['voucher_number'],
            'amount'  => (float)$r['amount'],
        ];
    }

    // Spread unlinked this-ERP money sequentially into the earliest short
    // months — matching the fee-summary display — so the re-balancer never
    // "fills" a month that a lump-sum cash collection already covers.
    if ($unlinked > 0.005) {
        $ns = count($slots);
        for ($ui = 0; $ui < $ns && $unlinked > 0.005; $ui++) {
            $need = round($slots[$ui]['due'] - $slots[$ui]['paid'], 2);
            if ($need <= 0.005) {
                continue;
            }
            $alloc = min($need, $unlinked);
            $slots[$ui]['paid'] = round($slots[$ui]['paid'] + $alloc, 2);
            $unlinked = round($unlinked - $alloc, 2);
        }
    }

    $transfers = [];
    $n = count($slots);
    for ($i = 0; $i < $n; $i++) {
        $short = round($slots[$i]['due'] - $slots[$i]['paid'], 2);
        if ($short <= OERM_GAP_TOLERANCE) {
            continue;
        }
        // Pull from the LATEST later slot still holding old-ERP money, so the
        // shortfall always ends up on the last month(s) — never in the middle.
        for ($j = $n - 1; $j > $i && $short > 0.009; $j--) {
            while ($short > 0.009 && $slots[$j]['oe_rows']) {
                $k    = count($slots[$j]['oe_rows']) - 1; // take from the last row first
                $row  = $slots[$j]['oe_rows'][$k];
                $take = round(min($short, (float)$row['amount']), 2);
                if ($take <= 0.009) {
                    break;
                }
                $full = $take >= (float)$row['amount'] - 0.005;
                $transfers[] = [
                    'type'       => $full ? 'move' : 'split',
                    'payment_id' => (int)$row['id'],
                    'voucher'    => (string)$row['voucher'],
                    'amount'     => $take,
                    'from'       => ['semester_fee_id' => $slots[$j]['sfid'], 'semester_number' => $slots[$j]['sem'], 'month_number' => $slots[$j]['month']],
                    'from_label' => $slots[$j]['label'],
                    'to'         => ['semester_fee_id' => $slots[$i]['sfid'], 'semester_number' => $slots[$i]['sem'], 'month_number' => $slots[$i]['month']],
                    'to_label'   => $slots[$i]['label'],
                ];
                $slots[$j]['oe_rows'][$k]['amount'] = round((float)$row['amount'] - $take, 2);
                $slots[$j]['paid'] = round($slots[$j]['paid'] - $take, 2);
                $slots[$i]['paid'] = round($slots[$i]['paid'] + $take, 2);
                $short = round($short - $take, 2);
                if ($slots[$j]['oe_rows'][$k]['amount'] <= 0.005) {
                    array_pop($slots[$j]['oe_rows']);
                }
            }
        }
    }
    return $transfers;
}

/**
 * Split helper: clone an sfp_payments row onto another installment slot with a
 * given amount, keeping every other column (voucher, receipt, method, dates,
 * notes) identical. Returns the new row id.
 */
function oerm_clone_payment_row(int $payment_id, float $amount, int $sfid, int $sem, int $month): int
{
    static $cols = null;
    if ($cols === null) {
        $cols = db()->query('SHOW COLUMNS FROM sfp_payments')->fetchAll(PDO::FETCH_COLUMN);
    }
    $ins  = [];
    $sel  = [];
    $vals = [];
    foreach ($cols as $c) {
        if ($c === 'id') {
            continue;
        }
        $ins[] = '`' . $c . '`';
        if ($c === 'amount') {
            $sel[]  = '?';
            $vals[] = round($amount, 2);
        } elseif ($c === 'semester_fee_id') {
            $sel[]  = '?';
            $vals[] = $sfid;
        } elseif ($c === 'semester_number') {
            $sel[]  = '?';
            $vals[] = $sem;
        } elseif ($c === 'month_number') {
            $sel[]  = '?';
            $vals[] = $month;
        } else {
            $sel[] = '`' . $c . '`';
        }
    }
    $stmt = db()->prepare(
        'INSERT INTO sfp_payments (' . implode(', ', $ins) . ') SELECT ' . implode(', ', $sel)
        . ' FROM sfp_payments WHERE id = ?'
    );
    $vals[] = $payment_id;
    $stmt->execute($vals);
    return (int)db()->lastInsertId();
}

/**
 * Execute shortfall transfers. MUST run inside an open transaction; throws on
 * any inconsistency so the caller's transaction rolls the student back fully.
 *
 * @return array{0:int, 1:array} [applied count, undo entries]
 */
function oerm_execute_transfers(array $transfers, int $package_id, array $student): array
{
    if (!$transfers) {
        return [0, []];
    }
    $db      = db();
    $applied = 0;
    $moves   = [];
    $sid     = (string)($student['student_id'] ?? '');
    $name    = (string)($student['full_name'] ?? '');

    // Shortfall moves/splits may touch tuition rows of ANY payment method —
    // money only ever moves BACKWARD to an earlier unpaid month, amounts /
    // vouchers / receipts never change, and a split keeps the donor row's
    // own method. Scope stays locked to this package's tuition rows.
    $upd_slot = $db->prepare(
        "UPDATE sfp_payments
         SET semester_fee_id = ?, semester_number = ?, month_number = ?
         WHERE id = ?
           AND package_id = ?
           AND fee_type = 'semester_tuition'"
    );
    $upd_amt = $db->prepare(
        "UPDATE sfp_payments
         SET amount = ROUND(amount - ?, 2)
         WHERE id = ?
           AND package_id = ?
           AND fee_type = 'semester_tuition'
           AND amount >= ?"
    );

    foreach ($transfers as $t) {
        $amt = round((float)$t['amount'], 2);
        if ($t['type'] === 'move') {
            // The whole remaining payment moves to the short month.
            $upd_slot->execute([
                (int)$t['to']['semester_fee_id'],
                (int)$t['to']['semester_number'],
                (int)$t['to']['month_number'],
                (int)$t['payment_id'],
                $package_id,
            ]);
            if ($upd_slot->rowCount() < 1) {
                throw new RuntimeException('Payment ' . (string)$t['voucher'] . ' changed while fixing — aborted.');
            }
            log_change('accounting', 'UPDATE', (int)$t['payment_id'],
                'Payment #' . (int)$t['payment_id'] . ' / ' . (string)$t['voucher'],
                'schedule_slot', (string)$t['from_label'], (string)$t['to_label'],
                'Old ERP shortfall fix for ' . $name . ' (' . $sid . '): whole payment of ' . acc_fmt($amt)
                . ' moved from ' . (string)$t['from_label'] . ' to fill ' . (string)$t['to_label']
                . '. Nothing deleted; amount/voucher unchanged.');
            $moves[] = [
                'type'        => 'move',
                'payment_id'  => (int)$t['payment_id'],
                'voucher'     => (string)$t['voucher'],
                'student_sid' => $sid,
                'amount'      => $amt,
                'from'        => $t['from'],
                'to'          => $t['to'],
            ];
        } else {
            // Split: ONLY the missing amount moves; the rest stays where it is.
            $upd_amt->execute([$amt, (int)$t['payment_id'], $package_id, $amt - 0.005]);
            if ($upd_amt->rowCount() < 1) {
                throw new RuntimeException('Payment ' . (string)$t['voucher'] . ' no longer holds ' . acc_fmt($amt) . ' — aborted.');
            }
            $new_id = oerm_clone_payment_row(
                (int)$t['payment_id'],
                $amt,
                (int)$t['to']['semester_fee_id'],
                (int)$t['to']['semester_number'],
                (int)$t['to']['month_number']
            );
            log_change('accounting', 'UPDATE', (int)$t['payment_id'],
                'Payment #' . (int)$t['payment_id'] . ' / ' . (string)$t['voucher'],
                'tuition_amount_split', (string)$t['from_label'], (string)$t['to_label'],
                'Old ERP shortfall fix for ' . $name . ' (' . $sid . '): ' . acc_fmt($amt)
                . ' pulled from ' . (string)$t['from_label'] . ' (the last paid month) to fill ' . (string)$t['to_label']
                . '. Payment row split (new row #' . $new_id . '); the rest stays — total paid unchanged, nothing deleted.');
            $moves[] = [
                'type'        => 'split',
                'payment_id'  => (int)$t['payment_id'],
                'donor_id'    => (int)$t['payment_id'],
                'new_id'      => $new_id,
                'voucher'     => (string)$t['voucher'],
                'student_sid' => $sid,
                'amount'      => $amt,
                'from'        => $t['from'],
                'to'          => $t['to'],
            ];
        }
        $applied++;
    }
    return [$applied, $moves];
}

// ── Undo (fix batch) helpers ───────────────────────────────────────────────────
//
// Every applied fix (single-student apply or bulk auto-fix) is recorded as one
// fix batch carrying the exact from/to slot of every payment it moved. Undoing
// a batch moves each payment BACK to its original month — only while it still
// sits where the fix put it, so later manual corrections are never overwritten.
// Nothing is ever deleted; amounts, vouchers and receipts are untouched.

/**
 * Ensure the fix-batch tracking table behind the Undo feature exists.
 */
function oerm_ensure_batch_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS oerm_fix_batches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fixed_students INT UNSIGNED NOT NULL DEFAULT 0,
            moved_count INT UNSIGNED NOT NULL DEFAULT 0,
            moved_payments MEDIUMTEXT NOT NULL,
            undone_by INT UNSIGNED NULL DEFAULT NULL,
            undone_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

/**
 * Record an applied fix as an undoable batch. Returns the batch id (0 when
 * there was nothing to record).
 */
function oerm_record_batch(array $moves, int $fixed_students): int
{
    if (!$moves) {
        return 0;
    }
    oerm_ensure_batch_table();
    $user = auth_user();
    db()->prepare(
        'INSERT INTO oerm_fix_batches (created_by, fixed_students, moved_count, moved_payments) VALUES (?,?,?,?)'
    )->execute([
        (int)($user['id'] ?? 0),
        max(0, $fixed_students),
        count($moves),
        json_encode(array_values($moves), JSON_UNESCAPED_UNICODE),
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Fetch a single fix batch by id.
 */
function oerm_get_batch(int $id): ?array
{
    oerm_ensure_batch_table();
    $stmt = db()->prepare('SELECT * FROM oerm_fix_batches WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Latest fix batches for the on-page list, newest first.
 */
function oerm_recent_batches(int $limit = 10): array
{
    oerm_ensure_batch_table();
    $stmt = db()->prepare(
        'SELECT b.*, u.full_name AS created_by_name, x.full_name AS undone_by_name
         FROM oerm_fix_batches b
         LEFT JOIN users u ON u.id = b.created_by
         LEFT JOIN users x ON x.id = b.undone_by
         ORDER BY b.id DESC
         LIMIT ' . max(1, $limit)
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Only the user who ran the fix — or a Super Administrator — may undo it,
 * and only while the batch has not been undone yet.
 */
function oerm_can_undo_batch(array $batch): bool
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

/**
 * Undo a fix batch: move every payment it moved back to its original month.
 *
 * A payment is only moved back while it still sits where the fix put it — a
 * payment moved again since (manually or by a later fix) is skipped, never
 * overwritten. The WHERE clause re-asserts the safety scope: only old-ERP
 * monthly tuition rows can ever move. Nothing is deleted; amounts, vouchers
 * and receipts are untouched. Every reverted row is change-logged.
 *
 * @return array{moved_back:int, skipped:int, errors:string[]}
 */
function oerm_undo_batch(array $batch): array
{
    $moved_back = 0;
    $skipped    = 0;
    $errors     = [];

    $moves = json_decode((string)($batch['moved_payments'] ?? '[]'), true);
    // Undo in REVERSE order — a payment may have been moved more than once
    // (whole-row remap first, shortfall re-balance after), so reverting the
    // steps backwards restores the exact original state.
    $moves = is_array($moves) ? array_reverse($moves) : [];
    $db  = db();
    $upd = $db->prepare(
        "UPDATE sfp_payments
         SET semester_fee_id = ?, semester_number = ?, month_number = ?
         WHERE id = ?
           AND semester_fee_id = ?
           AND month_number = ?
           AND fee_type = 'semester_tuition'"
    );
    foreach ($moves as $mv) {
        $type = (string)($mv['type'] ?? 'move');
        $from = $mv['from'] ?? null;
        $to   = $mv['to'] ?? null;
        if (!is_array($from) || !is_array($to)) {
            continue;
        }
        if ($type === 'split') {
            // Delete the split-off row (only while it still sits where the fix
            // put it, with the same amount) and return the amount to the
            // original payment — both steps atomically.
            $pid_new = (int)($mv['new_id'] ?? 0);
            $pid_don = (int)($mv['donor_id'] ?? ($mv['payment_id'] ?? 0));
            $amt     = round((float)($mv['amount'] ?? 0), 2);
            if ($pid_new <= 0 || $pid_don <= 0 || $amt <= 0) {
                continue;
            }
            $own_tx = false;
            try {
                $own_tx = !$db->inTransaction();
                if ($own_tx) {
                    $db->beginTransaction();
                }
                $del = $db->prepare(
                    "DELETE FROM sfp_payments
                     WHERE id = ?
                       AND fee_type = 'semester_tuition'
                       AND semester_fee_id = ?
                       AND month_number = ?
                       AND ABS(amount - ?) < 0.01"
                );
                $del->execute([$pid_new, (int)($to['semester_fee_id'] ?? 0), (int)($to['month_number'] ?? 0), $amt]);
                if ($del->rowCount() < 1) {
                    if ($own_tx) {
                        $db->rollBack();
                    }
                    $skipped++;
                    continue;
                }
                $add = $db->prepare(
                    "UPDATE sfp_payments SET amount = ROUND(amount + ?, 2)
                     WHERE id = ? AND fee_type = 'semester_tuition'"
                );
                $add->execute([$amt, $pid_don]);
                if ($add->rowCount() < 1) {
                    if ($own_tx) {
                        $db->rollBack();
                    }
                    $errors[] = 'Split from payment #' . $pid_don . ': original row missing — left unchanged.';
                    continue;
                }
                if ($own_tx) {
                    $db->commit();
                }
                $moved_back++;
                log_change('accounting', 'UPDATE', $pid_don,
                    'Payment #' . $pid_don . ' / ' . (string)($mv['voucher'] ?? ''),
                    'tuition_amount_split',
                    'sem ' . (int)($to['semester_number'] ?? 0) . ' / month ' . (int)($to['month_number'] ?? 0),
                    'sem ' . (int)($from['semester_number'] ?? 0) . ' / month ' . (int)($from['month_number'] ?? 0),
                    'Old ERP remap undo: fix batch #' . (int)$batch['id'] . ' reverted — ' . acc_fmt($amt)
                    . ' returned to the original payment; the split-off row was removed. Total unchanged.');
            } catch (Throwable $e) {
                if ($own_tx && $db->inTransaction()) {
                    $db->rollBack();
                }
                $errors[] = 'Split row #' . $pid_new . ': ' . $e->getMessage();
            }
            continue;
        }
        // Whole-row move.
        $pid = (int)($mv['payment_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        try {
            $upd->execute([
                (int)($from['semester_fee_id'] ?? 0),
                (int)($from['semester_number'] ?? 0),
                (int)($from['month_number'] ?? 0),
                $pid,
                (int)($to['semester_fee_id'] ?? 0),
                (int)($to['month_number'] ?? 0),
            ]);
            if ($upd->rowCount() > 0) {
                $moved_back++;
                log_change('accounting', 'UPDATE', $pid,
                    'Payment #' . $pid . ' / ' . (string)($mv['voucher'] ?? ''),
                    'schedule_slot',
                    'sem ' . (int)($to['semester_number'] ?? 0) . ' / month ' . (int)($to['month_number'] ?? 0),
                    'sem ' . (int)($from['semester_number'] ?? 0) . ' / month ' . (int)($from['month_number'] ?? 0),
                    'Old ERP remap undo: fix batch #' . (int)$batch['id']
                    . ' reverted — payment moved back to its original month. Amount/voucher unchanged.');
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $errors[] = 'Payment #' . $pid . ': ' . $e->getMessage();
        }
    }

    // Mark the batch as undone (one undo per batch).
    $user = auth_user();
    db()->prepare('UPDATE oerm_fix_batches SET undone_by = ?, undone_at = NOW() WHERE id = ?')
        ->execute([(int)($user['id'] ?? 0), (int)$batch['id']]);

    return ['moved_back' => $moved_back, 'skipped' => $skipped, 'errors' => $errors];
}

// ── Controller ────────────────────────────────────────────────────────────────────

$errors    = [];
$student   = null;
$plan      = null;
$scan_rows = null;
$shortfall_transfers = null;
$f_dept    = (int)($_POST['f_dept']    ?? 0);
$f_program = (int)($_POST['f_program'] ?? 0);
$f_batch   = (int)($_POST['f_batch']   ?? 0);

$sid_input = trim((string)($_POST['student_sid'] ?? $_GET['student_sid'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'scan') {
        // Scan students with old-ERP monthly tuition payments — optionally
        // filtered by Department / Program / Batch — and show only those whose
        // payments would move (capped for safety).
        $scan_rows = oerm_scan_affected($f_dept, $f_program, $f_batch);
    } elseif ($action === 'bulk_apply') {
        // ── Bulk auto-fix ────────────────────────────────────────────────
        // Fix every selected student in one go. Each student's plan is
        // recomputed server-side and applied in its own transaction, exactly
        // like the single-student apply. Afterwards the scan RE-RUNS
        // AUTOMATICALLY with the same filters so the result can be verified
        // immediately.
        $bulk_sids = array_values(array_unique(array_filter(
            array_map(static fn($v) => trim((string)$v), (array)($_POST['bulk_sids'] ?? [])),
            static fn(string $v): bool => $v !== ''
        )));
        if (!$bulk_sids) {
            $errors[] = 'No students were selected for the bulk auto-fix.';
        } else {
            $fixed_students = 0;
            $fixed_payments = 0;
            $bulk_errors    = [];
            $all_moves = [];
            foreach ($bulk_sids as $bsid) {
                [$moved_n, $bulk_err, $bulk_moves] = oerm_apply_student_fix($bsid);
                if ($bulk_err !== null) {
                    $bulk_errors[] = $bulk_err;
                } elseif ($moved_n > 0) {
                    $fixed_students++;
                    $fixed_payments += $moved_n;
                    foreach ($bulk_moves as $bulk_mv) {
                        $all_moves[] = $bulk_mv;
                    }
                }
            }
            // Record the whole bulk fix as ONE undoable batch.
            $fix_batch_id = 0;
            if ($all_moves) {
                try {
                    $fix_batch_id = oerm_record_batch($all_moves, $fixed_students);
                } catch (Throwable $e) {
                    $bulk_errors[] = 'Undo tracking could not be saved for this fix: ' . $e->getMessage();
                }
            }
            $msg = 'Bulk auto-fix: <strong>' . $fixed_payments . '</strong> old-ERP payment(s) moved onto the correct month(s) across <strong>'
                . $fixed_students . '</strong> student(s). Nothing was deleted — payments were only re-arranged; amounts, vouchers and receipts were not changed. The list below was rescanned automatically.';
            if ($fix_batch_id > 0) {
                $msg .= ' Saved as fix batch #' . $fix_batch_id . ' — it can be undone from the <em>Recent Fix Batches</em> list on this page.';
            }
            if ($bulk_errors) {
                $msg .= ' ' . count($bulk_errors) . ' issue(s): ' . h(implode(' ', $bulk_errors));
            }
            flash_set($bulk_errors ? 'warning' : 'success', $msg);
        }
        // Auto rescan with the same filters so the fix is verifiable at once.
        $scan_rows = oerm_scan_affected($f_dept, $f_program, $f_batch);
    } elseif ($action === 'preview' || $action === 'apply') {
        if ($sid_input === '') {
            $errors[] = 'Please enter a Student ID.';
        } else {
            $student = oerm_lookup_student($sid_input);
            if (!$student) {
                $errors[] = 'No student found with this ID.';
            } elseif ((int)($student['package_id'] ?? 0) <= 0) {
                $errors[] = 'This student has no fee package in the current system.';
            } else {
                $summary = acc_student_fee_summary((int)$student['id']);
                if (!$summary) {
                    $errors[] = 'Could not load the fee summary for this student.';
                } else {
                    [$fixed, $movable] = oerm_load_payments((int)$student['package_id']);
                    if (!$movable) {
                        $errors[] = 'This student has no old-ERP monthly tuition payments to remap.';
                    } else {
                        // The plan is ALWAYS recomputed server-side — also on apply —
                        // so nothing posted from the browser can influence the mapping.
                        $plan = oerm_build_plan($summary, $fixed, $movable);
                        $shortfall_transfers = oerm_build_shortfall_plan($summary, $fixed, $plan['plan']);
                        // A student with no REAL gap (every month paid, or only a
                        // trailing shortfall where the money simply ran out) is
                        // fine and up to date — show the plan as fully unchanged
                        // and never move anything.
                        if (!oerm_has_fixable_gap($summary, $fixed, $movable)) {
                            foreach ($plan['plan'] as $gk => $gv) {
                                $plan['plan'][$gk]['changed'] = false;
                            }
                            $plan['changed'] = 0;
                            $shortfall_transfers = [];
                        }

                        if ($action === 'apply') {
                            if ((int)$plan['changed'] === 0 && !$shortfall_transfers) {
                                flash_set('info', 'Nothing to change — all old-ERP monthly payments are already on the correct months and amounts.');
                                redirect(APP_URL . '/accounting/old-erp-remap.php');
                            }
                            $db = db();
                            $db->beginTransaction();
                            try {
                                // The WHERE clause re-asserts the safety scope: only
                                // old-ERP monthly tuition rows of THIS package can move.
                                $upd = $db->prepare(
                                    "UPDATE sfp_payments
                                     SET semester_fee_id = ?, semester_number = ?, month_number = ?
                                     WHERE id = ?
                                       AND package_id = ?
                                       AND fee_type = 'semester_tuition'
                                       AND payment_method = 'old_erp'"
                                );
                                $updated     = 0;
                                $apply_moves = [];
                                foreach ($plan['plan'] as $row) {
                                    if (!$row['changed']) {
                                        continue;
                                    }
                                    $upd->execute([
                                        (int)$row['new_sfid'],
                                        (int)$row['new_sem'],
                                        (int)$row['new_month'],
                                        (int)$row['id'],
                                        (int)$student['package_id'],
                                    ]);
                                    if ($upd->rowCount() > 0) {
                                        $updated++;
                                        log_change(
                                            'accounting',
                                            'UPDATE',
                                            (int)$row['id'],
                                            'Payment #' . (int)$row['id'] . ' / ' . $row['voucher_number'],
                                            'schedule_slot',
                                            'sem ' . $row['old_sem'] . ' / month ' . $row['old_month'],
                                            'sem ' . $row['new_sem'] . ' / month ' . $row['new_month'],
                                            'Old ERP remap for ' . ($student['full_name'] ?? '') . ' (' . ($student['student_id'] ?? '') . '): '
                                            . 'payment moved to ' . $row['new_sem_label'] . ' – Month ' . $row['new_month']
                                            . ($row['new_month_label'] !== '' ? ' (' . $row['new_month_label'] . ')' : '')
                                            . '. Pre-start month correction; amount/voucher unchanged.'
                                        );
                                        $apply_moves[] = [
                                            'payment_id'  => (int)$row['id'],
                                            'voucher'     => (string)$row['voucher_number'],
                                            'student_sid' => (string)($student['student_id'] ?? ''),
                                            'amount'      => (float)$row['amount'],
                                            'from' => [
                                                'semester_fee_id' => (int)($row['old_sfid'] ?? 0),
                                                'semester_number' => (int)$row['old_sem'],
                                                'month_number'    => (int)$row['old_month'],
                                            ],
                                            'to' => [
                                                'semester_fee_id' => (int)$row['new_sfid'],
                                                'semester_number' => (int)$row['new_sem'],
                                                'month_number'    => (int)$row['new_month'],
                                            ],
                                        ];
                                    }
                                }
                                // Phase 2 — shortfall re-balance: pull any missing amount
                                // of an earlier month from the latest month still holding
                                // old-ERP money. Nothing is deleted; totals never change.
                                [$t_applied, $t_moves] = oerm_execute_transfers($shortfall_transfers ?? [], (int)$student['package_id'], $student);
                                $updated += $t_applied;
                                foreach ($t_moves as $t_mv) {
                                    $apply_moves[] = $t_mv;
                                }
                                $db->commit();
                                // Record this apply as an undoable fix batch (best-effort:
                                // the remap itself already succeeded).
                                $apply_batch_id = 0;
                                try {
                                    $apply_batch_id = oerm_record_batch($apply_moves, 1);
                                } catch (Throwable $e) {
                                    error_log('old-erp-remap: undo tracking failed — ' . $e->getMessage());
                                }
                                flash_set('success', $updated . ' old-ERP payment(s) remapped for <strong>' . h((string)$student['full_name'])
                                    . '</strong>. Nothing was deleted — payments were only re-arranged; amounts, vouchers and receipts were not changed.'
                                    . ($apply_batch_id > 0 ? ' Saved as fix batch #' . $apply_batch_id . ' — it can be undone from the Old ERP remap page.' : '')
                                    . ' Verify the schedule below.');
                                redirect(APP_URL . '/accounting/collect-payment.php?tab=student&student_sid=' . urlencode((string)$student['student_id']));
                            } catch (Throwable $e) {
                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }
                                error_log('old-erp-remap apply failed: ' . $e->getMessage());
                                $errors[] = 'Remap failed and was fully rolled back. No payment was modified.';
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'undo_fix') {
        // ── Undo a fix batch ──────────────────────────────────────────────────────
        // Moves every payment the batch moved back to its original month —
        // only while it still sits where the fix put it. Nothing is deleted.
        $undo_id    = (int)($_POST['undo_batch_id'] ?? 0);
        $undo_batch = $undo_id > 0 ? oerm_get_batch($undo_id) : null;
        if (!$undo_batch) {
            $errors[] = 'Fix batch not found.';
        } elseif (!empty($undo_batch['undone_at'])) {
            $errors[] = 'Fix batch #' . $undo_id . ' has already been undone.';
        } elseif (!oerm_can_undo_batch($undo_batch)) {
            $errors[] = 'Only the user who ran fix batch #' . $undo_id . ' — or a Super Administrator — can undo it.';
        } else {
            $undo_res = oerm_undo_batch($undo_batch);
            $undo_msg = 'Fix batch #' . $undo_id . ' undone: ' . $undo_res['moved_back'] . ' payment(s) moved back to their original month(s).';
            if ($undo_res['skipped'] > 0) {
                $undo_msg .= ' ' . $undo_res['skipped'] . ' payment(s) were left unchanged because they had been moved again since the fix.';
            }
            if ($undo_res['errors']) {
                $undo_msg .= ' ' . count($undo_res['errors']) . ' error(s): ' . implode(' ', $undo_res['errors']);
            }
            flash_set($undo_res['errors'] ? 'warning' : 'success', $undo_msg);
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

[$oerm_depts, $oerm_programs, $oerm_batches] = oerm_filter_options();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-screwdriver-wrench me-2 text-warning"></i>Old ERP – Remap Monthly Payments
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/old-erp-bulk-merge.php">Old ERP Bulk CSV Merge</a></li>
            <li class="breadcrumb-item active">Remap Monthly Payments</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/old-erp-bulk-merge.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Bulk Merge
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small">
            <strong>Fix old-ERP monthly payments that were merged onto the wrong installment.</strong>
            <ul class="mb-0 mt-1 ps-3">
                <li>Happens when a student's old-ERP payments started <strong>before</strong> the schedule's payment start month (e.g. December while the schedule starts in January) — the early payment landed on a much later slot.</li>
                <li>The remap re-packs the student's <strong>old-ERP (memo) monthly tuition payments only</strong> onto the schedule from the start month forward, in payment-date order.</li>
                <li>Payments collected in <strong>this ERP</strong> (cash / bank / mobile banking) keep their slots during the re-pack. The <strong>shortfall re-balance</strong> may pull part of a this-ERP payment <strong>backward</strong> to fill an earlier unpaid month (e.g. August due while September / October are paid by cash) — amounts, vouchers and receipts still never change, and money is never pushed forward.</li>
                <li>Only the semester/month linkage moves — <strong>amounts, vouchers, dates and receipts never change</strong>, so the books stay balanced.</li>
                <li><strong>Partial shortfalls are re-balanced too.</strong> When an earlier month is short by a small amount (e.g. April missing 1.42) while a later month is paid, exactly that amount is pulled from the <strong>last paid month</strong> (e.g. June) to fill the earlier month — the rest stays where it is. The payment row is split, nothing is deleted, and the student's total paid never changes.</li>
                <li>Use the <strong>Department / Program / Batch filters</strong> to scan a specific group, then fix the listed students <strong>in bulk</strong> — the scan <strong>re-runs automatically</strong> after the fix so you can verify the result right away.</li>
                <li>Nothing is saved until you confirm; every change is recorded in the change log.</li>
            </ul>
        </div>
    </div>
</div>

<!-- ── Scan for affected students ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-magnifying-glass-chart me-2 text-primary"></i>Scan for Affected Students
    </div>
    <div class="card-body p-4 pb-3 border-bottom">
        <form method="post" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="scan">
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Department</label>
                <select name="f_dept" class="form-select form-select-sm">
                    <option value="0">All Departments</option>
                    <?php foreach ($oerm_depts as $fd): ?>
                    <option value="<?= (int)$fd['id'] ?>" <?= $f_dept === (int)$fd['id'] ? 'selected' : '' ?>><?= h((string)$fd['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Program</label>
                <select name="f_program" class="form-select form-select-sm">
                    <option value="0">All Programs</option>
                    <?php foreach ($oerm_programs as $fp): ?>
                    <option value="<?= (int)$fp['id'] ?>" <?= $f_program === (int)$fp['id'] ? 'selected' : '' ?>><?= h((string)$fp['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Batch</label>
                <select name="f_batch" class="form-select form-select-sm">
                    <option value="0">All Batches</option>
                    <?php foreach ($oerm_batches as $fb): ?>
                    <option value="<?= (int)$fb['id'] ?>" <?= $f_batch === (int)$fb['id'] ? 'selected' : '' ?>><?= h((string)$fb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fas fa-radar me-1"></i> Scan Now
                </button>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if ($scan_rows === null): ?>
        <p class="text-muted small px-4 py-3 mb-0">Scans every student with old-ERP monthly tuition payments (up to 1000 — narrow it with the filters above) and lists only those whose months are <strong>misaligned</strong>: an earlier month left unpaid while a later month shows paid (e.g. April empty but May paid). This can take a moment.</p>
        <?php elseif (!$scan_rows): ?>
        <div class="alert alert-success m-3 mb-3"><i class="fas fa-check-circle me-1"></i> No affected students found for the selected filters — every old-ERP monthly payment is already on the correct slot.</div>
        <?php else: ?>
        <form method="post" id="oerm-bulk-form"
              onsubmit="return confirm('Auto-fix the selected student(s)? Their old-ERP monthly payments will be moved onto the earliest unpaid month(s) — amounts, vouchers and receipts never change — and the list will be rescanned automatically.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_apply">
            <input type="hidden" name="f_dept" value="<?= (int)$f_dept ?>">
            <input type="hidden" name="f_program" value="<?= (int)$f_program ?>">
            <input type="hidden" name="f_batch" value="<?= (int)$f_batch ?>">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th class="ps-4" style="width:36px;"><input type="checkbox" class="form-check-input" id="oerm-sel-all" checked aria-label="Select all students"></th>
                        <th>Student ID</th><th>Student</th>
                        <th>Department</th><th>Program</th><th>Batch</th>
                        <th class="text-end">Old-ERP Monthly Payments</th>
                        <th class="text-end">Would Move</th>
                        <th class="text-end">Amount Fixes</th><th class="text-end pe-4">Action</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($scan_rows as $sr): ?>
                    <tr>
                        <td class="ps-4"><input type="checkbox" class="form-check-input oerm-sel" name="bulk_sids[]" value="<?= h($sr['student_id']) ?>" checked aria-label="Select student <?= h($sr['student_id']) ?>"></td>
                        <td class="fw-semibold"><?= h($sr['student_id']) ?></td>
                        <td><?= h($sr['full_name']) ?></td>
                        <td class="small"><?= h($sr['dept_name'] !== '' ? $sr['dept_name'] : '—') ?></td>
                        <td class="small"><?= h($sr['program_name'] !== '' ? $sr['program_name'] : '—') ?></td>
                        <td class="small"><?= h($sr['batch_name'] !== '' ? $sr['batch_name'] : '—') ?></td>
                        <td class="text-end"><?= (int)$sr['movable'] ?></td>
                        <td class="text-end text-danger fw-semibold"><?= (int)$sr['changed'] ?></td>
                        <td class="text-end fw-semibold <?= !empty($sr['shifts']) ? 'text-warning' : 'text-muted' ?>">
                            <?= !empty($sr['shifts']) ? (int)$sr['shifts'] . ' (' . h(acc_fmt((float)($sr['shift_amount'] ?? 0))) . ')' : '—' ?>
                        </td>
                        <td class="text-end pe-4">
                            <button type="submit" form="oerm-preview-form" name="student_sid" value="<?= h($sr['student_id']) ?>" class="btn btn-outline-warning btn-sm py-0 px-2">
                                <i class="fas fa-eye me-1"></i>Preview
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">
                    <strong><span id="oerm-sel-count"><?= count($scan_rows) ?></span></strong> of <?= count($scan_rows) ?> student(s) selected.
                    The auto-fix moves <strong>old-ERP (memo)</strong> monthly tuition payments onto the earliest unpaid month(s);
                    amounts, vouchers and receipts never change, and the list <strong>rescans automatically</strong> afterwards.
                </span>
                <button type="submit" class="btn btn-warning" id="oerm-bulk-btn">
                    <i class="fas fa-wand-magic-sparkles me-1"></i> Auto-Fix Selected &amp; Rescan
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- External form target for the per-row Preview buttons (avoids nesting forms) -->
<form method="post" id="oerm-preview-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
</form>

<script>
(function () {
    'use strict';
    var all = document.getElementById('oerm-sel-all');
    if (!all) { return; }
    var boxes   = Array.prototype.slice.call(document.querySelectorAll('.oerm-sel'));
    var countEl = document.getElementById('oerm-sel-count');
    var bulkBtn = document.getElementById('oerm-bulk-btn');
    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        if (countEl) { countEl.textContent = n; }
        if (bulkBtn) { bulkBtn.disabled = n === 0; }
        all.checked = boxes.length > 0 && n === boxes.length;
    }
    all.addEventListener('change', function () {
        boxes.forEach(function (b) { b.checked = all.checked; });
        refresh();
    });
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
})();
</script>

<!-- ── Recent fix batches (undo) ── -->
<?php $recent_fix_batches = oerm_recent_batches(10); ?>
<?php if ($recent_fix_batches): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-rotate-left me-2 text-danger"></i>Recent Fix Batches (Undo)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th class="ps-4">Batch</th><th>Fixed At</th><th>Fixed By</th>
                    <th class="text-end">Students</th><th class="text-end">Payments Moved</th>
                    <th>State</th><th class="text-end pe-4">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent_fix_batches as $fbatch): $fb_undone = !empty($fbatch['undone_at']); ?>
                <tr>
                    <td class="ps-4 fw-semibold">#<?= (int)$fbatch['id'] ?></td>
                    <td class="small"><?= h((string)$fbatch['created_at']) ?></td>
                    <td class="small"><?= h((string)($fbatch['created_by_name'] ?? '—')) ?></td>
                    <td class="text-end"><?= (int)$fbatch['fixed_students'] ?></td>
                    <td class="text-end"><?= (int)$fbatch['moved_count'] ?></td>
                    <td>
                        <?php if ($fb_undone): ?>
                        <span class="badge bg-secondary">Undone<?= !empty($fbatch['undone_by_name']) ? ' by ' . h((string)$fbatch['undone_by_name']) : '' ?> at <?= h((string)$fbatch['undone_at']) ?></span>
                        <?php else: ?>
                        <span class="badge bg-success">Applied</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <?php if (!$fb_undone && oerm_can_undo_batch($fbatch)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Undo fix batch #<?= (int)$fbatch['id'] ?>? Every payment it moved will be returned to its original month. Amounts, vouchers and receipts are never touched.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="undo_fix">
                            <input type="hidden" name="undo_batch_id" value="<?= (int)$fbatch['id'] ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2">
                                <i class="fas fa-rotate-left me-1"></i>Undo
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 small text-muted">
            Undo moves every payment a fix batch moved back to its original month — only while it still sits where the fix put it,
            so later manual corrections are never overwritten. Nothing is ever deleted; amounts, vouchers and receipts are untouched.
            Only the user who ran the fix — or a Super Administrator — can undo it, once per batch.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Per-student preview ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 fw-semibold">
        <i class="fas fa-user-graduate me-2 text-primary"></i>Preview a Student
    </div>
    <div class="card-body p-4">
        <form method="post" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Student ID <span class="text-danger">*</span></label>
                <input type="text" name="student_sid" class="form-control" required
                       placeholder="e.g. 02826105101071" value="<?= h($sid_input) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-eye me-1"></i> Preview Remap
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($plan !== null && $student): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold">
            <i class="fas fa-table me-2 text-primary"></i>Remap Plan – <?= h((string)$student['full_name']) ?>
            <span class="text-muted">(<?= h((string)$student['student_id']) ?>)</span>
        </span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-warning text-dark">Will move: <?= (int)$plan['changed'] ?></span>
            <span class="badge bg-info text-dark">Amount fixes: <?= is_array($shortfall_transfers) ? count($shortfall_transfers) : 0 ?></span>
            <span class="badge bg-secondary">Unchanged: <?= count($plan['plan']) - (int)$plan['changed'] ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($plan['warnings'])): ?>
        <div class="alert alert-warning m-3"><ul class="mb-0"><?php foreach ($plan['warnings'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th class="ps-4">Voucher</th><th>Payment Date</th><th>Receipt</th>
                    <th class="text-end">Amount</th>
                    <th>Current Slot</th><th>New Slot</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($plan['plan'] as $row): ?>
                <tr class="<?= $row['changed'] ? 'table-warning' : '' ?>">
                    <td class="ps-4 small"><?= h($row['voucher_number']) ?></td>
                    <td class="small"><?= h($row['voucher_date']) ?></td>
                    <td class="small"><?= h($row['receipt'] !== '' ? $row['receipt'] : '—') ?></td>
                    <td class="text-end small fw-semibold"><?= h(number_format((float)$row['amount'], 2)) ?></td>
                    <td class="small">Sem <?= (int)$row['old_sem'] ?> · Month <?= (int)$row['old_month'] ?></td>
                    <td class="small fw-semibold">
                        <?= h($row['new_sem_label']) ?> · Month <?= (int)$row['new_month'] ?>
                        <?php if ($row['new_month_label'] !== ''): ?>
                        <span class="text-muted">(<?= h($row['new_month_label']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $row['changed']
                        ? '<span class="badge bg-warning text-dark">Moves</span>'
                        : '<span class="badge bg-secondary">Unchanged</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($shortfall_transfers): ?>
        <div class="border-top">
            <div class="px-4 pt-3 small text-muted">
                <strong>Amount fixes (shortfall re-balance):</strong> each amount below is pulled from the <strong>last paid month</strong>
                to fill an earlier month that is short — e.g. April missing 1.42 while June is the last paid month: exactly 1.42 moves
                from June to April and the rest stays in June. Nothing is deleted; the student's total paid never changes.
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light"><tr>
                        <th class="ps-4">Voucher</th>
                        <th class="text-end">Amount to Move</th>
                        <th>From (last paid month)</th><th>To (short month)</th><th>How</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($shortfall_transfers as $tr): ?>
                    <tr class="table-info">
                        <td class="ps-4 small"><?= h((string)$tr['voucher']) ?></td>
                        <td class="text-end small fw-semibold"><?= h(acc_fmt((float)$tr['amount'])) ?></td>
                        <td class="small"><?= h((string)$tr['from_label']) ?></td>
                        <td class="small fw-semibold"><?= h((string)$tr['to_label']) ?></td>
                        <td class="small"><?= $tr['type'] === 'split' ? 'Split — only this amount moves, the rest stays' : 'Whole payment moves' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php $oerm_total_fixes = (int)$plan['changed'] + (is_array($shortfall_transfers) ? count($shortfall_transfers) : 0); ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Only monthly tuition <strong>month linkage</strong> moves: the re-pack touches <strong>old-ERP (memo)</strong> rows only, and the
            shortfall re-balance may pull a later payment (any method) <strong>backward</strong> to an earlier unpaid month. Vouchers and
            receipts are untouched, nothing is deleted, and the student's total paid never changes.
        </span>
        <form method="post"
              onsubmit="return confirm('Apply <?= $oerm_total_fixes ?> fix(es) for <?= h(addslashes((string)$student['full_name'])) ?>? Payments are only re-arranged — nothing is deleted and the total paid never changes.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="student_sid" value="<?= h((string)$student['student_id']) ?>">
            <button type="submit" class="btn btn-warning" <?= $oerm_total_fixes > 0 ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i> Confirm &amp; Fix <?= $oerm_total_fixes ?> Item(s)
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
