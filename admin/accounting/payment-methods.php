<?php
/**
 * Accounting – Payment Methods setup
 *
 * Manage the payment methods students can use for Pay Online:
 *   • Bank accounts   — bank name, branch name, account name, account number
 *   • Mobile banking  — operator (Bkash / Nagad / Rocket), wallet number and
 *     an optional charge note (e.g. “1.5% Charge Applicable”)
 * Any method can be activated / deactivated at any time; inactive methods are
 * hidden from students immediately. The payment guidelines shown to students
 * (one for bank, one for mobile banking) are also edited here.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment-methods-helpers.php';
require_once __DIR__ . '/../change-log/helpers.php';

$page_title = 'Payment Methods';
opm_ensure_tables();

$self_url = APP_URL . '/accounting/payment-methods.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'save_bank') {
        $bank_name      = trim((string)($_POST['bank_name'] ?? ''));
        $branch_name    = trim((string)($_POST['branch_name'] ?? ''));
        $account_name   = trim((string)($_POST['account_name'] ?? ''));
        $account_number = trim((string)($_POST['account_number'] ?? ''));
        if ($bank_name === '' || $branch_name === '' || $account_name === '' || $account_number === '') {
            flash_set('danger', 'Bank Name, Branch Name, Accounts Name and Accounts Number are all required.');
        } elseif ($id > 0) {
            db()->prepare(
                "UPDATE acc_payment_methods SET bank_name = ?, branch_name = ?, account_name = ?, account_number = ?
                 WHERE id = ? AND method_type = 'bank'"
            )->execute([$bank_name, $branch_name, $account_name, $account_number, $id]);
            log_change('accounting', 'UPDATE', $id, 'Payment method: ' . $bank_name . ' — ' . $branch_name,
                'payment_method', '', '', 'Bank payment method updated.');
            flash_set('success', 'Bank account updated.');
        } else {
            db()->prepare(
                "INSERT INTO acc_payment_methods (method_type, bank_name, branch_name, account_name, account_number, is_active, sort_order)
                 VALUES ('bank', ?, ?, ?, ?, 1, (SELECT COALESCE(MAX(t.sort_order),0)+1 FROM (SELECT sort_order FROM acc_payment_methods WHERE method_type='bank') t))"
            )->execute([$bank_name, $branch_name, $account_name, $account_number]);
            log_change('accounting', 'CREATE', (int)db()->lastInsertId(), 'Payment method: ' . $bank_name . ' — ' . $branch_name,
                'payment_method', '', '', 'Bank payment method added.');
            flash_set('success', 'Bank account added.');
        }
    } elseif ($action === 'save_mobile') {
        $operator      = trim((string)($_POST['operator_name'] ?? ''));
        $wallet_number = trim((string)($_POST['wallet_number'] ?? ''));
        $charge_note   = trim((string)($_POST['charge_note'] ?? ''));
        if (!in_array($operator, OPM_MOBILE_OPERATORS, true)) {
            flash_set('danger', 'Operator must be one of: ' . implode(', ', OPM_MOBILE_OPERATORS) . '.');
        } elseif ($wallet_number === '') {
            flash_set('danger', 'The wallet number is required.');
        } elseif ($id > 0) {
            db()->prepare(
                "UPDATE acc_payment_methods SET operator_name = ?, wallet_number = ?, charge_note = ?
                 WHERE id = ? AND method_type = 'mobile_banking'"
            )->execute([$operator, $wallet_number, $charge_note !== '' ? $charge_note : null, $id]);
            log_change('accounting', 'UPDATE', $id, 'Payment method: ' . $operator . ' (' . $wallet_number . ')',
                'payment_method', '', '', 'Mobile banking payment method updated.');
            flash_set('success', 'Mobile banking method updated.');
        } else {
            db()->prepare(
                "INSERT INTO acc_payment_methods (method_type, operator_name, wallet_number, charge_note, is_active, sort_order)
                 VALUES ('mobile_banking', ?, ?, ?, 1, (SELECT COALESCE(MAX(t.sort_order),0)+1 FROM (SELECT sort_order FROM acc_payment_methods WHERE method_type='mobile_banking') t))"
            )->execute([$operator, $wallet_number, $charge_note !== '' ? $charge_note : null]);
            log_change('accounting', 'CREATE', (int)db()->lastInsertId(), 'Payment method: ' . $operator . ' (' . $wallet_number . ')',
                'payment_method', '', '', 'Mobile banking payment method added.');
            flash_set('success', 'Mobile banking method added.');
        }
    } elseif ($action === 'toggle') {
        $m = opm_get_method($id);
        if (!$m) {
            flash_set('danger', 'Payment method not found.');
        } else {
            $new = (int)$m['is_active'] === 1 ? 0 : 1;
            db()->prepare('UPDATE acc_payment_methods SET is_active = ? WHERE id = ?')->execute([$new, $id]);
            log_change('accounting', 'UPDATE', $id, 'Payment method: ' . opm_method_title($m),
                'is_active', (string)(int)$m['is_active'], (string)$new,
                $new ? 'Payment method activated.' : 'Payment method deactivated — hidden from students.');
            flash_set('success', opm_method_title($m) . ($new ? ' activated.' : ' deactivated — students can no longer select it.'));
        }
    } elseif ($action === 'delete') {
        $m = opm_get_method($id);
        if (!$m) {
            flash_set('danger', 'Payment method not found.');
        } else {
            $used = db()->prepare('SELECT COUNT(*) FROM acc_online_payments WHERE method_id = ?');
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                flash_set('warning', opm_method_title($m) . ' has payment submissions linked to it and cannot be deleted — deactivate it instead.');
            } else {
                db()->prepare('DELETE FROM acc_payment_methods WHERE id = ?')->execute([$id]);
                log_change('accounting', 'DELETE', $id, 'Payment method: ' . opm_method_title($m),
                    'payment_method', '', '', 'Payment method deleted (no submissions were linked).');
                flash_set('success', opm_method_title($m) . ' deleted.');
            }
        }
    } elseif ($action === 'save_guidelines') {
        opm_save_guideline('bank', (string)($_POST['guideline_bank'] ?? ''));
        opm_save_guideline('mobile_banking', (string)($_POST['guideline_mobile'] ?? ''));
        flash_set('success', 'Payment guidelines saved. Students see the updated text immediately.');
    } else {
        flash_set('danger', 'Unknown action.');
    }
    redirect($self_url, 303);
}

$edit    = ($eid = (int)($_GET['edit'] ?? 0)) > 0 ? opm_get_method($eid) : null;
$methods = opm_all_methods();
$banks   = array_values(array_filter($methods, static fn(array $m): bool => $m['method_type'] === 'bank'));
$wallets = array_values(array_filter($methods, static fn(array $m): bool => $m['method_type'] === 'mobile_banking'));

$edit_bank   = $edit && $edit['method_type'] === 'bank' ? $edit : null;
$edit_mobile = $edit && $edit['method_type'] === 'mobile_banking' ? $edit : null;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-university me-2 text-primary"></i>Payment Methods</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Payment Methods</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/online-payments.php" class="btn btn-warning btn-sm">
        <i class="fas fa-clipboard-check me-1"></i> Online Payments Review
    </a>
</div>

<?= flash_show() ?>

<div class="alert alert-info small">
    <i class="fas fa-info-circle me-1"></i>
    These methods appear under <strong>Pay Online</strong> in the Student Accounts Portal. Deactivating a method hides it from
    students immediately without deleting its history. The guidelines below are shown to students next to the selected method.
</div>

<div class="row g-4">
    <!-- ── Bank accounts ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-3 px-4 fw-semibold"><i class="fas fa-university me-2 text-primary"></i>Bank Accounts</div>
            <div class="card-body p-4">
                <form method="post" class="row g-2 mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_bank">
                    <input type="hidden" name="id" value="<?= (int)($edit_bank['id'] ?? 0) ?>">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Bank Name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" class="form-control form-control-sm" maxlength="190" required value="<?= h((string)($edit_bank['bank_name'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Branch Name <span class="text-danger">*</span></label>
                        <input type="text" name="branch_name" class="form-control form-control-sm" maxlength="190" required value="<?= h((string)($edit_bank['branch_name'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Accounts Name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control form-control-sm" maxlength="190" required value="<?= h((string)($edit_bank['account_name'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Accounts Number <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control form-control-sm" maxlength="64" required value="<?= h((string)($edit_bank['account_number'] ?? '')) ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-<?= $edit_bank ? 'save' : 'plus' ?> me-1"></i><?= $edit_bank ? 'Update Bank Account' : 'Add Bank Account' ?>
                        </button>
                        <?php if ($edit_bank): ?>
                        <a href="<?= $self_url ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Bank</th><th>Branch</th><th>Accounts Name</th><th>Number</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($banks as $b): ?>
                            <tr class="<?= (int)$b['is_active'] === 1 ? '' : 'table-secondary' ?>">
                                <td class="fw-semibold small"><?= h((string)$b['bank_name']) ?></td>
                                <td class="small"><?= h((string)$b['branch_name']) ?></td>
                                <td class="small"><?= h((string)$b['account_name']) ?></td>
                                <td class="small font-monospace"><?= h((string)$b['account_number']) ?></td>
                                <td><?= (int)$b['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" class="d-inline"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int)$b['is_active'] === 1 ? 'warning' : 'success' ?>" title="<?= (int)$b['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= $self_url ?>?edit=<?= (int)$b['id'] ?>" title="Edit"><i class="fas fa-pen"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this bank account? Methods with linked submissions cannot be deleted.');"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash-can"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$banks): ?><tr><td colspan="6" class="text-center text-muted small py-3">No bank accounts yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mobile banking ── -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header py-3 px-4 fw-semibold"><i class="fas fa-mobile-alt me-2 text-success"></i>Mobile Banking</div>
            <div class="card-body p-4">
                <form method="post" class="row g-2 mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_mobile">
                    <input type="hidden" name="id" value="<?= (int)($edit_mobile['id'] ?? 0) ?>">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Operator <span class="text-danger">*</span></label>
                        <select name="operator_name" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach (OPM_MOBILE_OPERATORS as $op): ?>
                            <option value="<?= h($op) ?>" <?= ($edit_mobile['operator_name'] ?? '') === $op ? 'selected' : '' ?>><?= h($op) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Number <span class="text-danger">*</span></label>
                        <input type="text" name="wallet_number" class="form-control form-control-sm" maxlength="32" required value="<?= h((string)($edit_mobile['wallet_number'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Charge Note</label>
                        <input type="text" name="charge_note" class="form-control form-control-sm" maxlength="190" placeholder="e.g. 1.5% Charge Applicable" value="<?= h((string)($edit_mobile['charge_note'] ?? '')) ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-<?= $edit_mobile ? 'save' : 'plus' ?> me-1"></i><?= $edit_mobile ? 'Update Mobile Method' : 'Add Mobile Method' ?>
                        </button>
                        <?php if ($edit_mobile): ?>
                        <a href="<?= $self_url ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Operator</th><th>Number</th><th>Charge Note</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($wallets as $w): ?>
                            <tr class="<?= (int)$w['is_active'] === 1 ? '' : 'table-secondary' ?>">
                                <td class="fw-semibold small"><?= h((string)$w['operator_name']) ?></td>
                                <td class="small font-monospace"><?= h((string)$w['wallet_number']) ?></td>
                                <td class="small"><?= h((string)($w['charge_note'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                <td><?= (int)$w['is_active'] === 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" class="d-inline"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                                        <button class="btn btn-sm btn-outline-<?= (int)$w['is_active'] === 1 ? 'warning' : 'success' ?>" title="<?= (int)$w['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= $self_url ?>?edit=<?= (int)$w['id'] ?>" title="Edit"><i class="fas fa-pen"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this mobile banking method? Methods with linked submissions cannot be deleted.');"><?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash-can"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$wallets): ?><tr><td colspan="5" class="text-center text-muted small py-3">No mobile banking methods yet.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Guidelines ── -->
<div class="card border-0 shadow-sm my-4">
    <div class="card-header py-3 px-4 fw-semibold"><i class="fas fa-list-ol me-2 text-info"></i>Payment Guidelines (shown to students)</div>
    <div class="card-body p-4">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_guidelines">
            <div class="col-lg-6">
                <label class="form-label fw-semibold">Bank payment guideline</label>
                <textarea name="guideline_bank" class="form-control" rows="7"><?= h(opm_guideline('bank')) ?></textarea>
            </div>
            <div class="col-lg-6">
                <label class="form-label fw-semibold">Mobile banking guideline</label>
                <textarea name="guideline_mobile" class="form-control" rows="7"><?= h(opm_guideline('mobile_banking')) ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Guidelines</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
