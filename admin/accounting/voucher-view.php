<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$voucher = acc_get_voucher($id);
if (!$voucher) {
    flash_set('error', 'Voucher not found.');
    redirect(APP_URL . '/accounting/vouchers.php');
}

$items    = acc_get_voucher_items($id);
$currency = acc_currency();
$page_title = 'Voucher: ' . $voucher['voucher_number'];

// Payment-method details (surfaces Old ERP receipt numbers on the voucher)
$payment_info = acc_get_voucher_payment_info($id);
$payment_method     = $payment_info['payment_method'] ?? '';
$payment_method_lbl = $payment_info
    ? acc_payment_method_label($payment_method, $payment_info['mobile_banking_provider'] ?? null)
    : '';
$payment_txn_number = $payment_info['transaction_number'] ?? '';
$payment_txn_label  = $payment_method === 'old_erp' ? 'Old ERP Receipt No' : 'Transaction No';

// What this voucher is for (student/applicant + fee heads), so staff can see
// the kind of payment and which student at a glance.
$purpose = acc_get_voucher_purpose($id);

// Original voucher link (if this is a reversal)
$original = null;
if ($voucher['reversal_of']) {
    $stmt = db()->prepare('SELECT id, voucher_number FROM acc_vouchers WHERE id = ?');
    $stmt->execute([$voucher['reversal_of']]);
    $original = $stmt->fetch() ?: null;
}

// Delete-workflow state
$delete_request   = acc_get_delete_request_for_voucher($id);
$has_open_request = $delete_request && in_array($delete_request['status'], ['pending_dd', 'pending_treasurer'], true);
$can_delete       = acc_can_request_voucher_delete();
$delete_is_super  = acc_can_delete_voucher_directly();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-file-invoice me-2 text-primary"></i><?= h($voucher['voucher_number']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/vouchers.php">Vouchers</a></li>
            <li class="breadcrumb-item active"><?= h($voucher['voucher_number']) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($voucher['status'] === 'posted' && acc_can_create()): ?>
        <a href="<?= APP_URL ?>/accounting/voucher-reverse.php?id=<?= $voucher['id'] ?>"
           class="btn btn-warning btn-sm"
           onclick="return confirm('Are you sure you want to reverse this voucher? A mirror-image reversal entry will be created.')">
            <i class="fas fa-undo me-1"></i> Reverse Voucher
        </a>
        <?php endif; ?>
        <?php if ($can_delete && !$has_open_request): ?>
        <button type="button" class="btn btn-outline-danger btn-sm"
                data-bs-toggle="modal" data-bs-target="#voucherDeleteModal">
            <i class="fas fa-trash-alt me-1"></i> <?= $delete_is_super ? 'Delete Voucher' : 'Request Delete' ?>
        </button>
        <?php endif; ?>
        <?php if ($delete_request && acc_can_access_voucher_delete()): ?>
        <a href="<?= APP_URL ?>/accounting/voucher-delete-requests.php?id=<?= (int)$delete_request['id'] ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-trash-restore me-1"></i> Delete Request
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i> Print</button>
        <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?= flash_show() ?>

<?php if ($has_open_request): ?>
<div class="alert alert-warning">
    <i class="fas fa-clock me-1"></i>
    A delete request for this voucher is <strong><?= $delete_request['status'] === 'pending_dd' ? 'pending DD Accounts review' : 'pending Treasurer confirmation' ?></strong>.
    <a href="<?= APP_URL ?>/accounting/voucher-delete-requests.php?id=<?= (int)$delete_request['id'] ?>" class="alert-link">View request</a>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Voucher Header -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted small fw-semibold" style="width:140px">Voucher Number</td>
                                <td class="fw-bold"><?= h($voucher['voucher_number']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Voucher Type</td>
                                <td><?= acc_voucher_type_badge($voucher['voucher_type']) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Date</td>
                                <td><?= date('d F Y', strtotime($voucher['voucher_date'])) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Status</td>
                                <td><?= acc_voucher_status_badge($voucher['status']) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <td class="text-muted small fw-semibold" style="width:140px">Total Amount</td>
                                <td class="fw-bold fs-5 text-primary"><?= $currency ?> <?= number_format($voucher['total_amount'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Reference</td>
                                <td><?= h($voucher['reference'] ?? '–') ?></td>
                            </tr>
                            <?php if ($payment_method_lbl !== ''): ?>
                            <tr>
                                <td class="text-muted small fw-semibold">Payment Method</td>
                                <td>
                                    <?= h($payment_method_lbl) ?>
                                    <?php if ($payment_method === 'old_erp'): ?>
                                    <span class="badge bg-secondary ms-1">Old ERP</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($payment_txn_number !== ''): ?>
                            <tr>
                                <td class="text-muted small fw-semibold"><?= h($payment_txn_label) ?></td>
                                <td class="fw-semibold"><?= h($payment_txn_number) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-muted small fw-semibold">Created By</td>
                                <td><?= h($voucher['created_by_name'] ?? '–') ?></td>
                            </tr>
                            <tr>
                                <td class="text-muted small fw-semibold">Created At</td>
                                <td class="small text-muted"><?= date('d M Y, h:i A', strtotime($voucher['created_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php if ($voucher['narration']): ?>
                <div class="mt-3 p-3 rounded-3" style="background:#f8f9fb">
                    <span class="text-muted small fw-semibold">Narration: </span>
                    <?= h($voucher['narration']) ?>
                </div>
                <?php endif; ?>

                <?php if ($original): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="fas fa-undo me-1"></i> This is a reversal of voucher
                    <a href="<?= APP_URL ?>/accounting/voucher-view.php?id=<?= $original['id'] ?>" class="alert-link fw-bold"><?= h($original['voucher_number']) ?></a>
                </div>
                <?php endif; ?>

                <?php if ($voucher['status'] === 'reversed'): ?>
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    This voucher has been <strong>reversed</strong> by <?= h($voucher['reversed_by_name'] ?? 'N/A') ?>
                    on <?= $voucher['reversed_at'] ? date('d M Y', strtotime($voucher['reversed_at'])) : 'N/A' ?>.
                    <?php if ($voucher['reversal_voucher_number']): ?>
                    Reversal voucher: <strong><?= h($voucher['reversal_voucher_number']) ?></strong>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Voucher Purpose / Linked Payment -->
    <?php if ($purpose): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between">
                <strong class="small"><i class="fas fa-info-circle me-1 text-primary"></i>Voucher Purpose</strong>
                <span class="badge bg-primary"><?= h($purpose['label']) ?></span>
            </div>
            <div class="card-body p-4">
                <?php if ($purpose['kind'] === 'student_fee'): ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="text-muted small fw-semibold">Student</div>
                        <div class="fw-semibold">
                            <?= h($purpose['student_name']) ?>
                            <span class="badge bg-light text-dark border ms-1"><?= h($purpose['student_id']) ?></span>
                        </div>
                        <?php if ($purpose['package_id']): ?>
                        <a href="<?= APP_URL ?>/student-accounts/view.php?id=<?= (int)$purpose['package_id'] ?>"
                           class="small text-decoration-none"><i class="fas fa-external-link-alt me-1"></i>View student account</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($purpose['admitted_semester'] !== ''): ?>
                    <div class="col-md-6">
                        <div class="text-muted small fw-semibold">Admitted Semester</div>
                        <div><?= h($purpose['admitted_semester']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: /* admission_fee */ ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="text-muted small fw-semibold">Applicant</div>
                        <div class="fw-semibold">
                            <?= h($purpose['student_name']) ?>
                            <?php if ($purpose['assigned_student_id'] !== ''): ?>
                            <span class="badge bg-light text-dark border ms-1"><?= h($purpose['assigned_student_id']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small fw-semibold">Application No</div>
                        <div><?= h($purpose['app_number']) ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="text-muted small fw-semibold mb-1">Payment For</div>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Fee Head</th>
                                <th>Semester</th>
                                <th>Month</th>
                                <th class="text-end">Amount (<?= $currency ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purpose['items'] as $it): ?>
                            <tr>
                                <td class="fw-semibold"><?= h($it['fee_type_label']) ?></td>
                                <td class="small text-muted"><?= $it['semester_label'] !== '' ? h($it['semester_label']) : '–' ?></td>
                                <td class="small text-muted"><?= $it['month_number'] !== null ? 'Month ' . (int)$it['month_number'] : '–' ?></td>
                                <td class="text-end fw-semibold"><?= number_format($it['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Voucher Line Items (Journal Entries) -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-2 px-4">
                <strong class="small">Journal Entries (Double-Entry Ledger)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:120px">Account Code</th>
                                <th>Account Name</th>
                                <th>Description</th>
                                <th class="text-end text-success">Debit (<?= $currency ?>)</th>
                                <th class="text-end text-danger">Credit (<?= $currency ?>)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_debit  = 0;
                            $total_credit = 0;
                            foreach ($items as $item):
                                $total_debit  += (float)$item['debit_amount'];
                                $total_credit += (float)$item['credit_amount'];
                            ?>
                            <tr>
                                <td><span class="badge bg-light text-dark border"><?= h($item['code']) ?></span></td>
                                <td>
                                    <div class="fw-semibold small"><?= h($item['account_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem"><?= ucfirst($item['account_type']) ?></div>
                                </td>
                                <td class="small text-muted"><?= h($item['description'] ?? '–') ?></td>
                                <td class="text-end fw-semibold text-success">
                                    <?= $item['debit_amount'] > 0 ? number_format($item['debit_amount'], 2) : '–' ?>
                                </td>
                                <td class="text-end fw-semibold text-danger">
                                    <?= $item['credit_amount'] > 0 ? number_format($item['credit_amount'], 2) : '–' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3" class="text-end">Total</th>
                                <th class="text-end text-success"><?= number_format($total_debit, 2) ?></th>
                                <th class="text-end text-danger"><?= number_format($total_credit, 2) ?></th>
                            </tr>
                            <tr>
                                <td colspan="5" class="text-center small">
                                    <?php if (round($total_debit, 2) === round($total_credit, 2)): ?>
                                    <span class="text-success"><i class="fas fa-check-circle me-1"></i> Balanced — Total Debit = Total Credit</span>
                                    <?php else: ?>
                                    <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i> UNBALANCED</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    #sidebar, #topbar, .btn, nav[aria-label="breadcrumb"] { display: none !important; }
    #main-wrapper { margin-left: 0 !important; }
}
</style>

<?php if ($can_delete && !$has_open_request): ?>
<!-- Voucher Delete Modal -->
<div class="modal fade" id="voucherDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= APP_URL ?>/accounting/voucher-delete.php" enctype="multipart/form-data" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash-alt me-2 text-danger"></i><?= $delete_is_super ? 'Delete Voucher' : 'Request Voucher Deletion' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-<?= $delete_is_super ? 'danger' : 'warning' ?> small">
                    <?php if ($delete_is_super): ?>
                    Deleting <strong><?= h($voucher['voucher_number']) ?></strong> clears the whole entry and its calculations from every report. This is logged permanently and cannot be undone.
                    <?php else: ?>
                    Your request for <strong><?= h($voucher['voucher_number']) ?></strong> will be pending approval by <strong>DD Accounts</strong> and then the <strong>Treasurer</strong>.
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason for Deletion <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="4" required minlength="10"
                              placeholder="Explain in detail why this voucher must be deleted…"></textarea>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Attachment <span class="text-muted small">(optional)</span></label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                    <div class="form-text">Allowed: pdf, jpg, png, doc, docx, xls, xlsx (max 5 MB).</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-<?= $delete_is_super ? 'danger' : 'warning' ?>">
                    <i class="fas fa-<?= $delete_is_super ? 'trash-alt' : 'paper-plane' ?> me-1"></i>
                    <?= $delete_is_super ? 'Delete Voucher' : 'Submit Request' ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
