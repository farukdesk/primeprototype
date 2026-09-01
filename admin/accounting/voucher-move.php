<?php
/**
 * Accounting – Move Voucher to Another Category
 *
 * When a payment was collected on the wrong fee head (e.g. an additional
 * payment recorded under "Other Fee" that should be "Re-Take Fee", or a
 * tuition payment placed on the wrong month), this page moves the voucher's
 * fee line(s) to a different category WITHOUT touching the money:
 *
 *   • Amounts, voucher number, voucher date, payment method and receipt
 *     number are NEVER changed — only the fee head changes.
 *   • Each sfp_payments row linked to the voucher can be re-targeted
 *     independently (a multi-fee receipt keeps its other lines intact).
 *   • Scheduled heads keep their links: Registration requires a semester,
 *     Semester Tuition requires a semester + month.
 *   • For POSTED vouchers, when the source and target heads map to
 *     different income accounts, a journal voucher is posted
 *     (Dr old income / Cr new income) so the ledgers match the new head.
 *     Memo (Old ERP) vouchers are not in the books, so no journal is needed.
 *   • Every touched row is written to the immutable change log with the
 *     reason supplied by the user.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Move Voucher to Another Category';
$currency   = acc_currency();

$voucher_id = (int)($_POST['voucher_id'] ?? $_GET['id'] ?? 0);
$voucher    = $voucher_id > 0 ? acc_get_voucher($voucher_id) : null;

if (!$voucher) {
    flash_set('danger', 'Voucher not found or already deleted.');
    redirect(APP_URL . '/accounting/vouchers.php', 303);
}
if ($voucher['status'] === 'reversed') {
    flash_set('warning', 'Voucher ' . h($voucher['voucher_number']) . ' is reversed and cannot be moved.');
    redirect(APP_URL . '/accounting/voucher-view.php?id=' . $voucher_id, 303);
}

// ── Linked student fee lines ─────────────────────────────────────────────────
$pay_stmt = db()->prepare(
    'SELECT sp.*, s.id AS student_pk, s.student_id AS student_sid, s.full_name AS student_name\n     FROM sfp_payments sp\n     JOIN students s ON s.id = sp.student_id\n     WHERE sp.voucher_id = ?\n     ORDER BY sp.id ASC'
);
$pay_stmt->execute([$voucher_id]);
$payments = $pay_stmt->fetchAll();

if (!$payments) {
    flash_set('warning', 'Only student fee vouchers can be moved to another category. Voucher '
        . h($voucher['voucher_number']) . ' has no linked student fee payment.');
    redirect(APP_URL . '/accounting/voucher-view.php?id=' . $voucher_id, 303);
}

$student_pk = (int)$payments[0]['student_pk'];
$package_id = (int)$payments[0]['package_id'];
$summary    = acc_student_fee_summary($student_pk);

// Semester option data (for Registration / Semester Tuition targets)
$semester_options = [];
foreach (($summary['semesters'] ?? []) as $sem) {
    $label = ($sem['semester_label'] ?? '') !== '' && $sem['semester_label'] !== null
        ? (string)$sem['semester_label']
        : ('Semester ' . (int)$sem['semester_number']);
    $months = [];
    foreach (($sem['monthly_rows'] ?? []) as $mr) {
        $months[] = [
            'no'    => (int)$mr['month_number'],
            'label' => (string)($mr['month_label'] ?? ''),
            'out'   => (float)($mr['out'] ?? 0),
        ];
    }
    $semester_options[] = [
        'id'      => (int)$sem['id'],
        'number'  => (int)$sem['semester_number'],
        'label'   => $label,
        'reg_out' => (float)($sem['reg_out'] ?? 0),
        'months'  => $months,
    ];
}
$sem_by_id       = [];
$sem_label_by_no = [];
foreach ($semester_options as $so) {
    $sem_by_id[$so['id']]           = $so;
    $sem_label_by_no[$so['number']] = $so['label'];
}

/** Describe a fee head (type + optional semester/month) for notes and logs. */
function vm_head_label(string $fee_type, ?int $sem_no, ?int $month_no, array $sem_label_by_no): string
{
    $label = acc_fee_type_label($fee_type);
    if ($sem_no) {
        $label .= ' – ' . ($sem_label_by_no[$sem_no] ?? 'Semester ' . $sem_no);
    }
    if ($month_no) {
        $label .= ' – Month ' . $month_no;
    }
    return $label;
}

$scheduled_types = ['admission', 'form_fee', 'id_card_fee', 'registration', 'semester_tuition', 'project_fee', 'bi_tri_shift_fee'];
$additional_types = acc_additional_fee_types();
$valid_types = acc_student_fee_types();

$errors = [];

// ── POST: apply the move ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $reason  = trim((string)($_POST['reason'] ?? ''));
    $rows_in = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];

    if (mb_strlen($reason) < 10) {
        $errors[] = 'Please explain why this voucher is being moved (at least 10 characters).';
    }

    $changes = [];
    foreach ($payments as $p) {
        $pid = (int)$p['id'];
        $in  = $rows_in[$pid] ?? null;
        if (!is_array($in)) {
            continue;
        }
        $target = trim((string)($in['fee_type'] ?? ''));
        if ($target === '' || $target === '__keep') {
            continue;
        }
        if (!in_array($target, $valid_types, true)) {
            $errors[] = 'Invalid target category for line #' . $pid . '.';
            continue;
        }

        $sfid = null; $sem_no = null; $month_no = null;
        if ($target === 'registration' || $target === 'semester_tuition') {
            $sfid = (int)($in['semester_fee_id'] ?? 0);
            if (!isset($sem_by_id[$sfid])) {
                $errors[] = 'Please select the semester for the "' . acc_fee_type_label($target) . '" target (line #' . $pid . ').';
                continue;
            }
            $sem_no = (int)$sem_by_id[$sfid]['number'];
            if ($target === 'semester_tuition') {
                $month_no = (int)($in['month_number'] ?? 0);
                $max_months = count($sem_by_id[$sfid]['months']);
                if ($month_no < 1 || $month_no > max(1, $max_months)) {
                    $errors[] = 'Please select the month for the "Semester Tuition" target (line #' . $pid . ').';
                    continue;
                }
            }
        } elseif ($target === 'bi_tri_shift_fee') {
            $month_no = (int)($in['month_number'] ?? 0) ?: null;
        }

        // Skip when the target equals the current head — nothing to move.
        if ($target === (string)$p['fee_type']
            && (int)($p['semester_fee_id'] ?? 0) === (int)($sfid ?? 0)
            && (int)($p['month_number'] ?? 0) === (int)($month_no ?? 0)) {
            continue;
        }

        $changes[] = [
            'row'      => $p,
            'fee_type' => $target,
            'sfid'     => $sfid,
            'sem_no'   => $sem_no,
            'month_no' => $month_no,
        ];
    }

    if (!$errors && !$changes) {
        $errors[] = 'Nothing to move — pick a different category for at least one line.';
    }

    if (!$errors) {
        $db    = db();
        $today = date('Y-m-d');
        $applied = 0;
        $moved_amount = 0.0;

        $db->beginTransaction();
        try {
            // WHERE re-asserts the safety scope: only rows of THIS voucher can change.
            $upd = $db->prepare(
                'UPDATE sfp_payments\n                 SET fee_type = ?, semester_fee_id = ?, semester_number = ?, month_number = ?, note = ?\n                 WHERE id = ? AND voucher_id = ?'
            );

            foreach ($changes as $c) {
                $p = $c['row'];
                $from_label = vm_head_label(
                    (string)$p['fee_type'],
                    $p['semester_number'] !== null ? (int)$p['semester_number'] : null,
                    $p['month_number'] !== null ? (int)$p['month_number'] : null,
                    $sem_label_by_no
                );
                $to_label = vm_head_label($c['fee_type'], $c['sem_no'], $c['month_no'], $sem_label_by_no);

                $base_note = trim((string)($p['note'] ?? ''));
                $new_note  = ($base_note !== '' ? $base_note . ' ' : '')
                           . '[Moved from ' . $from_label . ' to ' . $to_label . ' on ' . $today . ' — ' . $reason . ']';

                $upd->execute([
                    $c['fee_type'],
                    $c['sfid'],
                    $c['sem_no'],
                    $c['month_no'],
                    $new_note,
                    (int)$p['id'],
                    $voucher_id,
                ]);
                if ($upd->rowCount() > 0) {
                    $applied++;
                    $moved_amount = round($moved_amount + (float)$p['amount'], 2);
                    log_change(
                        'accounting',
                        'UPDATE',
                        (int)$p['id'],
                        'Payment #' . (int)$p['id'] . ' / ' . (string)$voucher['voucher_number'],
                        'fee_head',
                        $from_label,
                        $to_label,
                        'Voucher move for ' . (string)$p['student_name'] . ' (' . (string)$p['student_sid'] . '): '
                        . acc_fmt((float)$p['amount']) . ' moved from ' . $from_label . ' to ' . $to_label
                        . '. Reason: ' . $reason . '. Voucher/receipt unchanged.'
                    );
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('voucher-move apply error for voucher ' . $voucher_id . ': ' . $e->getMessage());
            $errors[] = 'Failed to move the voucher: ' . $e->getMessage();
        }

        if (!$errors) {
            // ── Books: reclassify POSTED money when heads map to different income
            //    accounts. Posted AFTER the row transaction so a journal failure
            //    never blocks the head fix (it is reported instead).
            $journal_warning = null;
            if ($voucher['status'] === 'posted') {
                $pairs = [];   // "from|to" => amount
                foreach ($changes as $c) {
                    $from_acc = acc_income_account_id_for_fee_type((string)$c['row']['fee_type']);
                    $to_acc   = acc_income_account_id_for_fee_type($c['fee_type']);
                    if ($from_acc > 0 && $to_acc > 0 && $from_acc !== $to_acc) {
                        $key = $from_acc . '|' . $to_acc;
                        $pairs[$key] = round(($pairs[$key] ?? 0.0) + (float)$c['row']['amount'], 2);
                    }
                }
                if ($pairs) {
                    $lines = [];
                    foreach ($pairs as $key => $amt) {
                        [$from_acc, $to_acc] = array_map('intval', explode('|', $key));
                        $desc = 'Voucher move ' . (string)$voucher['voucher_number'] . ': category reclassification';
                        $lines[] = ['account_id' => $from_acc, 'debit' => $amt, 'credit' => 0, 'description' => $desc];
                        $lines[] = ['account_id' => $to_acc,   'debit' => 0, 'credit' => $amt, 'description' => $desc];
                    }
                    try {
                        acc_post_voucher('journal', date('Y-m-d'), $lines,
                            'Voucher move: ' . (string)$voucher['voucher_number'] . ' for '
                            . (string)$payments[0]['student_name'] . ' (' . (string)$payments[0]['student_sid'] . ') — '
                            . acc_fmt($moved_amount) . ' reclassified to a different fee category. Reason: ' . $reason,
                            (string)($voucher['reference'] ?? ''));
                    } catch (Throwable $e) {
                        error_log('voucher-move journal error for voucher ' . $voucher_id . ': ' . $e->getMessage());
                        $journal_warning = 'The fee lines were moved, but the income reclassification journal failed: '
                                         . $e->getMessage();
                    }
                }
            }

            $msg = 'Voucher ' . h($voucher['voucher_number']) . ': ' . $applied . ' fee line(s) totalling '
                 . h(acc_fmt($moved_amount)) . ' moved to the new category. '
                 . 'Amounts, voucher and receipt were not changed.';
            if ($journal_warning !== null) {
                flash_set('warning', $msg . '<br><strong>Warning:</strong> ' . h($journal_warning));
            } else {
                flash_set('success', $msg);
            }
            redirect(APP_URL . '/accounting/voucher-view.php?id=' . $voucher_id, 303);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-people-arrows me-2 text-warning"></i>Move Voucher to Another Category</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/vouchers.php">Vouchers</a></li>
            <li class="breadcrumb-item active"><?= h($voucher['voucher_number']) ?></li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $voucher_id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-eye me-1"></i> View Voucher
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-light border small">
    <i class="fas fa-info-circle text-primary me-1"></i>
    <strong>How it works:</strong> pick a new category for the fee line(s) below. Amounts, the voucher number,
    date, payment method and receipt number never change — money only changes head. For posted vouchers, when the
    old and new categories map to different income accounts, a journal voucher is posted automatically so the
    ledgers match the new head. Every change is written to the change log with your reason.
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="fw-semibold"><?= h($voucher['voucher_number']) ?></span>
            <?= acc_voucher_type_badge($voucher['voucher_type']) ?>
            <?= acc_voucher_status_badge($voucher['status']) ?>
            <span class="text-muted small ms-2"><?= date('d M Y', strtotime($voucher['voucher_date'])) ?></span>
        </div>
        <div class="small">
            <span class="badge bg-light text-dark border"><i class="fas fa-id-card me-1 text-primary"></i><?= h($payments[0]['student_sid']) ?></span>
            <span class="text-muted ms-1"><?= h($payments[0]['student_name']) ?></span>
            <span class="fw-semibold ms-2"><?= h(acc_fmt((float)$voucher['total_amount'])) ?></span>
        </div>
    </div>
    <div class="card-body p-4">
        <form method="post" onsubmit="return confirm('Move the selected fee line(s) to the new category? Amounts and the voucher stay unchanged. This will be recorded in the change log.');">
            <?= csrf_field() ?>
            <input type="hidden" name="voucher_id" value="<?= $voucher_id ?>">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Current Category</th>
                            <th class="text-end">Amount</th>
                            <th style="min-width:260px">Move To</th>
                            <th style="min-width:200px">Semester</th>
                            <th style="min-width:180px">Month</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <?php
                        $pid = (int)$p['id'];
                        $cur_label = vm_head_label(
                            (string)$p['fee_type'],
                            $p['semester_number'] !== null ? (int)$p['semester_number'] : null,
                            $p['month_number'] !== null ? (int)$p['month_number'] : null,
                            $sem_label_by_no
                        );
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><?= h($cur_label) ?></div>
                                <?php if (!empty($p['note'])): ?>
                                <div class="text-muted" style="font-size:.72rem"><?= h($p['note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold"><?= $currency ?> <?= number_format((float)$p['amount'], 2) ?></td>
                            <td>
                                <select name="rows[<?= $pid ?>][fee_type]" class="form-select form-select-sm js-vm-type" data-row="<?= $pid ?>">
                                    <option value="__keep">— Keep current —</option>
                                    <optgroup label="Scheduled Fees">
                                        <?php foreach ($scheduled_types as $ft): ?>
                                        <option value="<?= h($ft) ?>"><?= h(acc_fee_type_label($ft)) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Additional / Examination Fees">
                                        <?php foreach ($additional_types as $ft): ?>
                                        <option value="<?= h($ft) ?>"><?= h(acc_fee_type_label($ft)) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Other">
                                        <option value="other"><?= h(acc_fee_type_label('other')) ?></option>
                                    </optgroup>
                                </select>
                            </td>
                            <td>
                                <select name="rows[<?= $pid ?>][semester_fee_id]" class="form-select form-select-sm js-vm-sem d-none" data-row="<?= $pid ?>">
                                    <option value="">— Semester —</option>
                                    <?php foreach ($semester_options as $so): ?>
                                    <option value="<?= (int)$so['id'] ?>"><?= h($so['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="rows[<?= $pid ?>][month_number]" class="form-select form-select-sm js-vm-month d-none" data-row="<?= $pid ?>">
                                    <option value="">— Month —</option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Reason for Move <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required minlength="10"
                          placeholder="Explain why this voucher must be moved to a different category…"><?= h((string)($_POST['reason'] ?? '')) ?></textarea>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-warning"><i class="fas fa-people-arrows me-1"></i> Move Voucher</button>
                <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $voucher_id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var SEMS = <?= json_encode($semester_options, JSON_UNESCAPED_UNICODE) ?>;
    var CURRENCY = <?= json_encode($currency, JSON_UNESCAPED_UNICODE) ?>;

    function findSem(id) {
        for (var i = 0; i < SEMS.length; i++) {
            if (SEMS[i].id === id) { return SEMS[i]; }
        }
        return null;
    }

    document.querySelectorAll('.js-vm-type').forEach(function (typeSel) {
        var row    = typeSel.dataset.row;
        var semSel = document.querySelector('.js-vm-sem[data-row="' + row + '"]');
        var monSel = document.querySelector('.js-vm-month[data-row="' + row + '"]');

        function fillMonths() {
            var sem = findSem(parseInt(semSel.value || '0', 10));
            monSel.innerHTML = '';
            var opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = '— Month —';
            monSel.appendChild(opt0);
            if (!sem) { return; }
            sem.months.forEach(function (m) {
                var o = document.createElement('option');
                o.value = m.no;
                o.textContent = 'Month ' + m.no
                    + (m.label ? ' (' + m.label + ')' : '')
                    + (m.out > 0 ? ' — due ' + CURRENCY + ' ' + m.out.toFixed(2) : ' — paid');
                monSel.appendChild(o);
            });
        }

        function refresh() {
            var t = typeSel.value;
            var needSem = (t === 'semester_tuition' || t === 'registration');
            var needMon = (t === 'semester_tuition');
            semSel.classList.toggle('d-none', !needSem);
            monSel.classList.toggle('d-none', !needMon);
            if (!needSem) { semSel.value = ''; }
            if (needMon) { fillMonths(); } else { monSel.value = ''; }
        }

        semSel.addEventListener('change', fillMonths);
        typeSel.addEventListener('change', refresh);
        refresh();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
