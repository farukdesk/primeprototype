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
    foreach ($fixed as $p) {
        $sfid = (int)($p['semester_fee_id'] ?? 0);
        if ($sfid && isset($caps[$sfid])) {
            $caps[$sfid] -= (float)$p['amount'];
            if (!empty($p['month_number'])) {
                $occupied[$sfid][(int)$p['month_number']] = true;
            }
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

// ── Controller ────────────────────────────────────────────────────────────────────

$errors    = [];
$student   = null;
$plan      = null;
$scan_rows = null;

$sid_input = trim((string)($_POST['student_sid'] ?? $_GET['student_sid'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'scan') {
        // Scan all students that have old-ERP monthly tuition payments and show
        // only those whose payments would move (capped for safety).
        $cand_stmt = db()->query(
            "SELECT DISTINCT s.id AS student_pk, s.student_id, s.full_name, p.id AS package_id
             FROM sfp_payments sp
             JOIN acc_vouchers v ON v.id = sp.voucher_id
             JOIN students s     ON s.id = sp.student_id
             JOIN sfp_packages p ON p.id = sp.package_id
             WHERE sp.payment_method = 'old_erp'
               AND sp.fee_type = 'semester_tuition'
               AND v.is_deleted = 0
             ORDER BY s.student_id
             LIMIT 500"
        );
        $scan_rows = [];
        foreach ($cand_stmt->fetchAll() as $cand) {
            $csum = acc_student_fee_summary((int)$cand['student_pk']);
            if (!$csum) {
                continue;
            }
            [$cfixed, $cmovable] = oerm_load_payments((int)$cand['package_id']);
            if (!$cmovable) {
                continue;
            }
            $cplan = oerm_build_plan($csum, $cfixed, $cmovable);
            if ($cplan['changed'] > 0) {
                $scan_rows[] = [
                    'student_id' => (string)$cand['student_id'],
                    'full_name'  => (string)$cand['full_name'],
                    'movable'    => count($cmovable),
                    'changed'    => (int)$cplan['changed'],
                ];
            }
        }
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

                        if ($action === 'apply') {
                            if ((int)$plan['changed'] === 0) {
                                flash_set('info', 'Nothing to change — all old-ERP monthly payments are already on the correct slots.');
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
                                $updated = 0;
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
                                    }
                                }
                                $db->commit();
                                flash_set('success', $updated . ' old-ERP payment(s) remapped for <strong>' . h((string)$student['full_name'])
                                    . '</strong>. Amounts, vouchers and receipts were not changed. Verify the schedule below.');
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
    } else {
        $errors[] = 'Unknown action.';
    }
}

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
                <li>Payments collected in <strong>this ERP</strong> (cash / bank / mobile banking) keep their slots and are <strong>never touched</strong>.</li>
                <li>Only the semester/month linkage moves — <strong>amounts, vouchers, dates and receipts never change</strong>, so the books stay balanced.</li>
                <li>Nothing is saved until you confirm; every change is recorded in the change log.</li>
            </ul>
        </div>
    </div>
</div>

<!-- ── Scan for affected students ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-magnifying-glass-chart me-2 text-primary"></i>Scan for Affected Students</span>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="scan">
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-radar me-1"></i> Scan Now
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if ($scan_rows === null): ?>
        <p class="text-muted small px-4 py-3 mb-0">Scans every student with old-ERP monthly tuition payments (up to 500) and lists only those whose payments would move. This can take a moment.</p>
        <?php elseif (!$scan_rows): ?>
        <div class="alert alert-success m-3 mb-3"><i class="fas fa-check-circle me-1"></i> No affected students found — every old-ERP monthly payment is already on the correct slot.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light"><tr>
                    <th class="ps-4">Student ID</th><th>Student</th>
                    <th class="text-end">Old-ERP Monthly Payments</th>
                    <th class="text-end">Would Move</th><th class="text-end pe-4">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($scan_rows as $sr): ?>
                <tr>
                    <td class="ps-4 fw-semibold"><?= h($sr['student_id']) ?></td>
                    <td><?= h($sr['full_name']) ?></td>
                    <td class="text-end"><?= (int)$sr['movable'] ?></td>
                    <td class="text-end text-danger fw-semibold"><?= (int)$sr['changed'] ?></td>
                    <td class="text-end pe-4">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="preview">
                            <input type="hidden" name="student_sid" value="<?= h($sr['student_id']) ?>">
                            <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-2">
                                <i class="fas fa-eye me-1"></i>Preview
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

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
    </div>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Only <strong>old-ERP (memo)</strong> monthly tuition payments move. This-ERP payments, amounts, vouchers and receipts are untouched.
        </span>
        <form method="post"
              onsubmit="return confirm('Remap <?= (int)$plan['changed'] ?> old-ERP payment(s) for <?= h(addslashes((string)$student['full_name'])) ?>? Amounts and vouchers will NOT change.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="student_sid" value="<?= h((string)$student['student_id']) ?>">
            <button type="submit" class="btn btn-warning" <?= (int)$plan['changed'] > 0 ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i> Confirm &amp; Remap <?= (int)$plan['changed'] ?> Payment(s)
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
