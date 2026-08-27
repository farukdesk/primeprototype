<?php
/**
 * Accounting – Move Fee Head → Monthly Payments (Bulk, per Batch)
 *
 * Lets accounts staff move money already collected on one or more
 * "Semester N – Registration Fee" heads onto the student's monthly tuition
 * schedule (earliest outstanding month onwards) for a WHOLE batch at once,
 * optionally narrowed by Department / Program. After the move the selected
 * registration head shows as DUE again, because the registration paid total
 * decreases and the fee summary recomputes automatically.
 *
 * How the move works, per student
 *   • The amount "paid" on each selected head is read from the live fee
 *     summary (acc_student_fee_summary), which distributes total registration
 *     money sequentially across semesters.
 *   • Registration payment rows (sfp_payments, fee_type = 'registration') are
 *     consumed — rows linked to the selected semesters first, then unlinked
 *     rows, then the rest, newest first. Rows are converted or SPLIT exactly
 *     per target month slot, so each moved portion lands on a specific
 *     Semester/Month with fee_type = 'semester_tuition'.
 *   • Target slots are the earliest months that still have an outstanding
 *     amount, in schedule order ("months payment onwards").
 *   • Amounts, vouchers, dates and receipt numbers are NEVER changed in
 *     total: money only changes head, never quantity.
 *
 * Books (double entry)
 *   • Old-ERP memo rows are not in the books, so nothing further is needed.
 *   • For POSTED money, if the Registration head and the Monthly Tuition head
 *     map to different income accounts, a journal voucher is posted per
 *     student (Dr Registration income / Cr Tuition income) so the ledgers
 *     match the new head. With the default mapping (both 4100) no journal is
 *     needed.
 *
 * Safety
 *   • Two-step: Preview first, then Confirm. The plan is recomputed
 *     server-side at confirm time — nothing from the browser is trusted.
 *   • Each student is applied in its own transaction.
 *   • Every touched row is written to the immutable change log.
 *
 * NOTE on "which semester becomes due": the fee summary distributes total
 * registration money sequentially (Semester 1 first). Moving money therefore
 * marks the LAST paid registration semester(s) as due. In the normal case —
 * moving the most recent semester's registration while later semesters are
 * still unpaid — this is exactly the selected head.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Move Fee Head → Monthly Payments';

const MFH_EPS = 0.005;
const MFH_MAX_STUDENTS = 2000;

/**
 * Option lists for the filters (Department, Program, Batch).
 *
 * @return array{0:array,1:array,2:array}
 */
function mfh_filter_options(): array
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
 * Students in the selected Batch (and optional Department / Program) that
 * have at least one live registration payment. The batch filter matches the
 * student's home batch OR an active batch transfer, the same rule Student
 * Management uses.
 */
function mfh_students(int $f_dept, int $f_program, int $f_batch): array
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
         FROM students s
         JOIN sfp_packages p ON p.student_id = s.id
         LEFT JOIN dept_departments d        ON d.id  = s.dept_id
         LEFT JOIN dept_academic_programs pr ON pr.id = s.program_id
         LEFT JOIN student_batches b         ON b.id  = s.batch_id
         WHERE EXISTS (
                 SELECT 1
                 FROM sfp_payments sp
                 JOIN acc_vouchers v ON v.id = sp.voucher_id
                 WHERE sp.package_id = p.id
                   AND sp.fee_type = 'registration'
                   AND v.is_deleted = 0
                   AND v.status IN ('posted','memo')
               )" . $where . "
         ORDER BY s.student_id
         LIMIT " . MFH_MAX_STUDENTS
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Live registration payment rows for a package (with voucher status).
 */
function mfh_load_registration_rows(int $package_id): array
{
    $stmt = db()->prepare(
        "SELECT sp.id, sp.student_id, sp.package_id, sp.semester_fee_id, sp.semester_number,
                sp.month_number, sp.payment_method, sp.mobile_banking_provider,
                sp.transaction_number, sp.amount, sp.voucher_id, sp.note, sp.collected_by,
                v.status AS voucher_status, v.voucher_number
         FROM sfp_payments sp
         JOIN acc_vouchers v ON v.id = sp.voucher_id
         WHERE sp.package_id = ?
           AND sp.fee_type = 'registration'
           AND v.is_deleted = 0
           AND v.status IN ('posted','memo')
         ORDER BY sp.id ASC"
    );
    $stmt->execute([$package_id]);
    return $stmt->fetchAll();
}

/**
 * Amount currently counted as paid on each selected registration head, from
 * the live fee summary (sequential distribution).
 *
 * @param int[] $sem_numbers
 */
function mfh_head_amounts(array $summary, array $sem_numbers): array
{
    $heads = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        $no = (int)$sem['semester_number'];
        if (!in_array($no, $sem_numbers, true)) {
            continue;
        }
        $paid = (float)($sem['reg_paid'] ?? 0);
        if ($paid <= MFH_EPS) {
            continue;
        }
        $sem_label = ($sem['semester_label'] ?? '') !== '' && $sem['semester_label'] !== null
            ? (string)$sem['semester_label']
            : ('Semester ' . $no);
        $heads[] = [
            'semester_number' => $no,
            'label'           => $sem_label . ' – Registration Fee',
            'amount'          => round($paid, 2),
        ];
    }
    return $heads;
}

/**
 * Target month slots: every month that still has an outstanding amount, in
 * schedule order (earliest month onwards).
 */
function mfh_target_slots(array $summary): array
{
    $slots = [];
    foreach (($summary['semesters'] ?? []) as $sem) {
        $sfid      = (int)$sem['id'];
        $sem_no    = (int)$sem['semester_number'];
        $sem_label = ($sem['semester_label'] ?? '') !== '' && $sem['semester_label'] !== null
            ? (string)$sem['semester_label']
            : ('Semester ' . $sem_no);
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $out = (float)($mr['out'] ?? 0);
            if ($out <= MFH_EPS) {
                continue;
            }
            $m_label = (string)($mr['month_label'] ?? '');
            $slots[] = [
                'sfid'     => $sfid,
                'sem_no'   => $sem_no,
                'month_no' => (int)$mr['month_number'],
                'label'    => $sem_label . ' – Month ' . (int)$mr['month_number']
                            . ($m_label !== '' ? ' (' . $m_label . ')' : ''),
                'capacity' => round($out, 2),
            ];
        }
    }
    return $slots;
}

/**
 * Order registration rows for consumption: rows linked to the selected
 * semesters first, then unlinked (package-level) rows, then the rest —
 * newest first within each group.
 *
 * @param int[] $sem_numbers
 */
function mfh_order_reg_rows(array $rows, array $sem_numbers): array
{
    $sel = [];
    $unlinked = [];
    $other = [];
    foreach ($rows as $r) {
        $no = $r['semester_number'] !== null ? (int)$r['semester_number'] : 0;
        if ($no > 0 && in_array($no, $sem_numbers, true)) {
            $sel[] = $r;
        } elseif ($no === 0) {
            $unlinked[] = $r;
        } else {
            $other[] = $r;
        }
    }
    $desc = static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id'];
    usort($sel, $desc);
    usort($unlinked, $desc);
    usort($other, $desc);
    return array_merge($sel, $unlinked, $other);
}

/**
 * Build the move plan for one student.
 *
 * @param int[] $sem_numbers
 * @return array{heads:array,move_total:float,moved:float,posted_moved:float,
 *               leftover:float,actions:array,slot_labels:string[]}
 */
function mfh_build_student_plan(array $summary, array $reg_rows, array $sem_numbers): array
{
    $heads      = mfh_head_amounts($summary, $sem_numbers);
    $move_total = round(array_sum(array_column($heads, 'amount')), 2);
    $result = [
        'heads'        => $heads,
        'move_total'   => $move_total,
        'moved'        => 0.0,
        'posted_moved' => 0.0,
        'leftover'     => 0.0,
        'actions'      => [],
        'slot_labels'  => [],
    ];
    if ($move_total <= MFH_EPS) {
        return $result;
    }

    $slots          = mfh_target_slots($summary);
    $capacity_total = round(array_sum(array_column($slots, 'capacity')), 2);
    $movable        = round(min($move_total, $capacity_total), 2);
    $result['leftover'] = round($move_total - $movable, 2);
    if ($movable <= MFH_EPS) {
        return $result;
    }

    $rows      = mfh_order_reg_rows($reg_rows, $sem_numbers);
    $slot_i    = 0;
    $n_slots   = count($slots);
    $remaining = $movable;

    foreach ($rows as $r) {
        if ($remaining <= MFH_EPS || $slot_i >= $n_slots) {
            break;
        }
        $take = round(min((float)$r['amount'], $remaining), 2);
        if ($take <= MFH_EPS) {
            continue;
        }

        // Spread this row's moved portion across the earliest open slots.
        $allocs = [];
        $left   = $take;
        while ($left > MFH_EPS && $slot_i < $n_slots) {
            $cap = $slots[$slot_i]['capacity'];
            $a   = round(min($cap, $left), 2);
            if ($a > MFH_EPS) {
                $allocs[] = [
                    'sfid'     => $slots[$slot_i]['sfid'],
                    'sem_no'   => $slots[$slot_i]['sem_no'],
                    'month_no' => $slots[$slot_i]['month_no'],
                    'label'    => $slots[$slot_i]['label'],
                    'amount'   => $a,
                ];
                $slots[$slot_i]['capacity'] = round($cap - $a, 2);
                $left = round($left - $a, 2);
            }
            if ($slots[$slot_i]['capacity'] <= MFH_EPS) {
                $slot_i++;
            }
        }
        $allocated = round($take - $left, 2);
        if ($allocated <= MFH_EPS) {
            break;
        }
        $remaining = round($remaining - $allocated, 2);

        $result['actions'][] = [
            'row'                  => $r,
            'moved_from_row'       => $allocated,
            'keep_as_registration' => round((float)$r['amount'] - $allocated, 2),
            'allocations'          => $allocs,
        ];
        $result['moved'] = round($result['moved'] + $allocated, 2);
        if (($r['voucher_status'] ?? '') === 'posted') {
            $result['posted_moved'] = round($result['posted_moved'] + $allocated, 2);
        }
        foreach ($allocs as $al) {
            $result['slot_labels'][] = $al['label'];
        }
    }

    return $result;
}

/**
 * Apply the move for ONE student. The plan is recomputed here — nothing from
 * the browser is trusted. Runs in its own transaction; the reclassification
 * journal (books) is posted after the rows commit.
 *
 * @param int[] $sem_numbers
 * @return array{0:int,1:float,2:?string} [rows changed, amount moved, warning]
 */
function mfh_apply_student(array $stu, array $sem_numbers, string $head_note): array
{
    $student_pk = (int)$stu['student_pk'];
    $package_id = (int)$stu['package_id'];
    $sid        = (string)$stu['student_id'];

    $summary = acc_student_fee_summary($student_pk);
    if (!$summary) {
        return [0, 0.0, 'Student ' . $sid . ': could not load the fee summary — skipped.'];
    }
    $plan = mfh_build_student_plan($summary, mfh_load_registration_rows($package_id), $sem_numbers);
    if ($plan['moved'] <= MFH_EPS) {
        return [0, 0.0, null];
    }

    $db    = db();
    $user  = auth_user();
    $today = date('Y-m-d');

    $db->beginTransaction();
    try {
        // WHERE re-asserts the safety scope: only registration rows of THIS
        // package can ever be converted.
        $upd_full = $db->prepare(
            "UPDATE sfp_payments
             SET fee_type = 'semester_tuition', semester_fee_id = ?, semester_number = ?,
                 month_number = ?, amount = ?, note = ?
             WHERE id = ? AND package_id = ? AND fee_type = 'registration'"
        );
        $upd_partial = $db->prepare(
            "UPDATE sfp_payments
             SET amount = ?, note = ?
             WHERE id = ? AND package_id = ? AND fee_type = 'registration'"
        );
        $ins = $db->prepare(
            'INSERT INTO sfp_payments
                (student_id, package_id, semester_fee_id, fee_type, semester_number, month_number,
                 payment_method, mobile_banking_provider, transaction_number, amount, voucher_id, note, collected_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $rows_changed = 0;
        foreach ($plan['actions'] as $act) {
            $r          = $act['row'];
            $allocs     = $act['allocations'];
            $base_note  = trim((string)($r['note'] ?? ''));
            $moved_note = 'Moved from ' . $head_note . ' to monthly payment on ' . $today;
            $insert_allocs = $allocs;

            if ($act['keep_as_registration'] > MFH_EPS) {
                // Partial move: shrink the original registration row, insert
                // one new tuition row per target month slot.
                $new_note = ($base_note !== '' ? $base_note . ' ' : '')
                          . '[' . acc_fmt($act['moved_from_row']) . ' moved to monthly payment on ' . $today . ']';
                $upd_partial->execute([
                    $act['keep_as_registration'],
                    $new_note,
                    (int)$r['id'],
                    $package_id,
                ]);
                if ($upd_partial->rowCount() > 0) {
                    $rows_changed++;
                    log_change(
                        'accounting',
                        'UPDATE',
                        (int)$r['id'],
                        'Payment #' . (int)$r['id'] . ' / ' . (string)$r['voucher_number'],
                        'amount',
                        (string)$r['amount'],
                        (string)$act['keep_as_registration'],
                        'Bulk fee-head move for ' . ($stu['full_name'] ?? '') . ' (' . $sid . '): '
                        . acc_fmt($act['moved_from_row']) . ' split off ' . $head_note
                        . ' onto the monthly schedule. Voucher/receipt unchanged.'
                    );
                }
            } else {
                // Full move: the first slot re-uses the existing row, the
                // remaining slots get new rows.
                $first = array_shift($insert_allocs);
                $new_note = ($base_note !== '' ? $base_note . ' ' : '') . '[' . $moved_note . ']';
                $upd_full->execute([
                    (int)$first['sfid'],
                    (int)$first['sem_no'],
                    (int)$first['month_no'],
                    $first['amount'],
                    $new_note,
                    (int)$r['id'],
                    $package_id,
                ]);
                if ($upd_full->rowCount() > 0) {
                    $rows_changed++;
                    log_change(
                        'accounting',
                        'UPDATE',
                        (int)$r['id'],
                        'Payment #' . (int)$r['id'] . ' / ' . (string)$r['voucher_number'],
                        'fee_head',
                        'registration',
                        'semester_tuition (sem ' . (int)$first['sem_no'] . ' / month ' . (int)$first['month_no'] . ')',
                        'Bulk fee-head move for ' . ($stu['full_name'] ?? '') . ' (' . $sid . '): '
                        . acc_fmt($first['amount']) . ' moved from ' . $head_note . ' to ' . $first['label']
                        . '. Voucher/receipt unchanged.'
                    );
                }
            }

            foreach ($insert_allocs as $al) {
                $ins->execute([
                    $student_pk,
                    $package_id,
                    (int)$al['sfid'],
                    'semester_tuition',
                    (int)$al['sem_no'],
                    (int)$al['month_no'],
                    (string)$r['payment_method'],
                    $r['mobile_banking_provider'] ?? null,
                    $r['transaction_number'] ?? null,
                    $al['amount'],
                    (int)$r['voucher_id'],
                    $moved_note,
                    $user['id'] ?? null,
                ]);
                $new_id = (int)$db->lastInsertId();
                $rows_changed++;
                log_change(
                    'accounting',
                    'CREATE',
                    $new_id,
                    'Payment #' . $new_id . ' / ' . (string)$r['voucher_number'],
                    null,
                    null,
                    null,
                    'Bulk fee-head move for ' . ($stu['full_name'] ?? '') . ' (' . $sid . '): '
                    . acc_fmt($al['amount']) . ' from ' . $head_note . ' applied to ' . $al['label']
                    . ' (split of payment #' . (int)$r['id'] . ', same voucher).'
                );
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('move-fee-head apply error for student ' . $sid . ': ' . $e->getMessage());
        return [0, 0.0, 'Student ' . $sid . ': failed to apply — ' . $e->getMessage()];
    }

    // ── Books: reclassify POSTED money when the two heads use different
    //    income accounts. Posted AFTER the row transaction so a journal
    //    failure never blocks the schedule fix (it is reported instead).
    $warning = null;
    if ($plan['posted_moved'] > MFH_EPS) {
        $reg_acc = acc_income_account_id_for_fee_type('registration');
        $tui_acc = acc_income_account_id_for_fee_type('semester_tuition');
        if ($reg_acc > 0 && $tui_acc > 0 && $reg_acc !== $tui_acc) {
            try {
                acc_post_voucher('journal', $today, [
                    ['account_id' => $reg_acc, 'debit' => $plan['posted_moved'], 'credit' => 0,
                     'description' => 'Fee head move: ' . $head_note . ' → Monthly Tuition'],
                    ['account_id' => $tui_acc, 'debit' => 0, 'credit' => $plan['posted_moved'],
                     'description' => 'Fee head move: ' . $head_note . ' → Monthly Tuition'],
                ],
                'Fee head move for ' . ($stu['full_name'] ?? '') . ' (' . $sid . '): '
                . acc_fmt($plan['posted_moved']) . ' reclassified from ' . $head_note . ' to Monthly Tuition.');
            } catch (Throwable $e) {
                error_log('move-fee-head journal error for student ' . $sid . ': ' . $e->getMessage());
                $warning = 'Student ' . $sid . ': rows moved, but the income reclassification journal failed — '
                         . $e->getMessage();
            }
        }
    }

    return [$rows_changed, $plan['moved'], $warning];
}

// ── Read filters (POST wins over GET so the form round-trips) ────────────────
$f_dept    = (int)($_POST['dept_id']    ?? $_GET['dept_id']    ?? 0);
$f_program = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$f_batch   = (int)($_POST['batch_id']   ?? $_GET['batch_id']   ?? 0);
$sem_numbers = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['semesters'] ?? [])),
    static fn(int $n): bool => $n >= 1 && $n <= 24
)));
sort($sem_numbers);

$errors  = [];
$preview = null;   // array of per-student preview rows
$preview_totals = ['students' => 0, 'amount' => 0.0, 'leftover' => 0.0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($f_batch <= 0)      $errors[] = 'Please select the Batch (the move always runs per batch).';
    if (!$sem_numbers)      $errors[] = 'Please select at least one Registration Fee head (semester).';

    if (!$errors && in_array($action, ['preview', 'apply'], true)) {
        $head_note = 'Registration Fee (Semester ' . implode(', ', $sem_numbers) . ')';
        $students  = mfh_students($f_dept, $f_program, $f_batch);

        if ($action === 'preview') {
            $preview = [];
            foreach ($students as $stu) {
                $summary = acc_student_fee_summary((int)$stu['student_pk']);
                if (!$summary) {
                    continue;
                }
                $plan = mfh_build_student_plan(
                    $summary,
                    mfh_load_registration_rows((int)$stu['package_id']),
                    $sem_numbers
                );
                if ($plan['move_total'] <= MFH_EPS) {
                    continue;
                }
                $preview[] = [
                    'student_id'   => (string)$stu['student_id'],
                    'full_name'    => (string)$stu['full_name'],
                    'batch_name'   => (string)($stu['batch_name'] ?? ''),
                    'dept_name'    => (string)($stu['dept_name'] ?? ''),
                    'heads'        => $plan['heads'],
                    'moved'        => $plan['moved'],
                    'leftover'     => $plan['leftover'],
                    'slot_labels'  => $plan['slot_labels'],
                ];
                $preview_totals['students']++;
                $preview_totals['amount']   = round($preview_totals['amount'] + $plan['moved'], 2);
                $preview_totals['leftover'] = round($preview_totals['leftover'] + $plan['leftover'], 2);
            }
        } else { // apply — recompute everything server-side and execute
            $tot_students = 0;
            $tot_rows     = 0;
            $tot_amount   = 0.0;
            $warnings     = [];
            foreach ($students as $stu) {
                [$rows, $moved, $warn] = mfh_apply_student($stu, $sem_numbers, $head_note);
                if ($rows > 0) {
                    $tot_students++;
                    $tot_rows += $rows;
                    $tot_amount = round($tot_amount + $moved, 2);
                }
                if ($warn !== null) {
                    $warnings[] = $warn;
                }
            }
            $msg = 'Fee head move complete: ' . acc_fmt($tot_amount) . ' moved from ' . h($head_note)
                 . ' to monthly payments for ' . $tot_students . ' student(s) (' . $tot_rows
                 . ' payment rows updated). The selected registration head(s) now show as due.';
            if ($warnings) {
                $shown = array_slice($warnings, 0, 15);
                $msg .= '<br><strong>Warnings (' . count($warnings) . '):</strong><br>'
                      . h(implode(' | ', $shown))
                      . (count($warnings) > 15 ? ' …' : '');
                flash_set('warning', $msg);
            } else {
                flash_set('success', $msg);
            }
            redirect(APP_URL . '/accounting/move-fee-head.php?batch_id=' . $f_batch
                . '&dept_id=' . $f_dept . '&program_id=' . $f_program, 303);
        }
    }
}

[$depts, $programs, $batches] = mfh_filter_options();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-people-arrows me-2 text-warning"></i>Move Fee Head → Monthly Payments</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Move Fee Head</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/collect-payment.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Collect Payment</a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-light border small">
    <i class="fas fa-info-circle text-primary me-1"></i>
    <strong>How it works:</strong> for every student in the selected batch, the money already collected on the
    selected <em>Registration Fee</em> head(s) is moved onto the monthly tuition schedule — filling the earliest
    outstanding months onwards. The registration head then shows as <strong>due</strong> again.
    Amounts, vouchers, dates and receipt numbers never change; money only changes head.
    Posted amounts are reclassified in the books with a journal voucher when the two heads map to different
    income accounts. Every change is written to the change log.
    <br><i class="fas fa-triangle-exclamation text-warning me-1"></i>
    Registration money is distributed sequentially across semesters (Semester 1 first), so the move marks the
    <em>latest paid</em> registration semester(s) as due — normally exactly the head you selected, as long as
    later semesters are still unpaid.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark p-2"><i class="fas fa-filter"></i></span>
            <div>
                <div class="fw-semibold">1 · Select Batch &amp; Fee Heads</div>
                <div class="text-muted small">Choose the batch (required), optionally narrow by department / program, then tick the registration heads to move.</div>
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Batch <span class="text-danger">*</span></label>
                    <select name="batch_id" class="form-select" required>
                        <option value="">— Select Batch —</option>
                        <?php foreach ($batches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $f_batch === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department <small class="text-muted fw-normal">(optional)</small></label>
                    <select name="dept_id" class="form-select">
                        <option value="0">— All Departments —</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Program <small class="text-muted fw-normal">(optional)</small></label>
                    <select name="program_id" class="form-select">
                        <option value="0">— All Programs —</option>
                        <?php foreach ($programs as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $f_program === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Fee heads to move <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php for ($n = 1; $n <= 12; $n++): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="semesters[]"
                                   value="<?= $n ?>" id="mfh-sem-<?= $n ?>"
                                   <?= in_array($n, $sem_numbers, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mfh-sem-<?= $n ?>">Semester <?= $n ?> – Registration Fee</label>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="form-text">Only semesters that actually exist on a student's schedule are considered for that student.</div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-warning"><i class="fas fa-magnifying-glass me-1"></i> Preview Move</button>
                <a href="<?= APP_URL ?>/accounting/move-fee-head.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($preview !== null): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary p-2"><i class="fas fa-clipboard-check"></i></span>
            <div>
                <div class="fw-semibold">2 · Preview</div>
                <div class="text-muted small">
                    <?= (int)$preview_totals['students'] ?> student(s) ·
                    <?= h(acc_fmt($preview_totals['amount'])) ?> will move to monthly payments
                    <?php if ($preview_totals['leftover'] > MFH_EPS): ?>
                        · <span class="text-danger"><?= h(acc_fmt($preview_totals['leftover'])) ?> cannot move (no outstanding months) and stays on registration</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($preview_totals['students'] > 0): ?>
        <form method="post" onsubmit="return confirm('Move <?= h(acc_fmt($preview_totals['amount'])) ?> from the selected registration head(s) to monthly payments for <?= (int)$preview_totals['students'] ?> student(s)? The registration head(s) will show as due again. This will be recorded in the change log.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="batch_id" value="<?= (int)$f_batch ?>">
            <input type="hidden" name="dept_id" value="<?= (int)$f_dept ?>">
            <input type="hidden" name="program_id" value="<?= (int)$f_program ?>">
            <?php foreach ($sem_numbers as $n): ?>
            <input type="hidden" name="semesters[]" value="<?= (int)$n ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-danger"><i class="fas fa-check me-1"></i> Confirm &amp; Move for Whole Batch</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (!$preview): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-circle-check fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No student in this selection has money on the selected registration head(s). Nothing to move.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Batch / Dept</th>
                        <th>Head(s) moved from</th>
                        <th class="text-end">Amount to move</th>
                        <th>Applied to (months onwards)</th>
                        <th class="text-end">Stays on registration</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $row): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h($row['full_name']) ?></div>
                            <div class="text-muted small"><?= h($row['student_id']) ?></div>
                        </td>
                        <td class="small"><?= h($row['batch_name']) ?><br><span class="text-muted"><?= h($row['dept_name']) ?></span></td>
                        <td class="small">
                            <?php foreach ($row['heads'] as $hd): ?>
                            <div><?= h($hd['label']) ?> <span class="text-muted">(<?= h(acc_fmt($hd['amount'])) ?>)</span></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end fw-semibold"><?= h(acc_fmt($row['moved'])) ?></td>
                        <td class="small">
                            <?php
                            $labels = $row['slot_labels'];
                            $shown  = array_slice($labels, 0, 3);
                            echo h(implode('; ', $shown));
                            if (count($labels) > 3) {
                                echo ' <span class="text-muted">+' . (count($labels) - 3) . ' more month(s)</span>';
                            }
                            ?>
                        </td>
                        <td class="text-end small <?= $row['leftover'] > MFH_EPS ? 'text-danger fw-semibold' : 'text-muted' ?>">
                            <?= $row['leftover'] > MFH_EPS ? h(acc_fmt($row['leftover'])) : '—' ?>
                        </td>
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
