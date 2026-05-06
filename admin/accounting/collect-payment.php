<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title      = 'Collect Payment';
$cash_accounts   = acc_cash_accounts();
$income_accounts = acc_income_accounts();
$default_cash    = acc_setting('default_cash_account', '1100');
$errors          = [];

// ── POST: process a student-fee payment ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'student') {
    csrf_check();

    $student_id      = (int)($_POST['student_id']       ?? 0);
    $package_id      = (int)($_POST['package_id']       ?? 0);
    $fee_type        = trim($_POST['fee_type']           ?? '');
    $semester_fee_id = (int)($_POST['semester_fee_id']  ?? 0) ?: null;
    $semester_number = (int)($_POST['semester_number']  ?? 0) ?: null;
    $amount          = (float)($_POST['amount']         ?? 0);
    $cash_account_id   = (int)($_POST['cash_account_id']   ?? 0);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    $date            = trim($_POST['voucher_date']       ?? date('Y-m-d'));
    $reference       = trim($_POST['reference']          ?? '');
    $narration       = trim($_POST['narration']          ?? '');

    $valid_types = ['admission','registration','semester_tuition','fixed_fee','english_fee','other'];

    if (!$student_id)                          $errors[] = 'Invalid student.';
    if (!$package_id)                          $errors[] = 'Student has no fee package.';
    if (!in_array($fee_type, $valid_types))    $errors[] = 'Invalid fee type selected.';
    if ($amount <= 0)                          $errors[] = 'Amount must be greater than zero.';
    if (!$cash_account_id)                     $errors[] = 'Please select the received-into account.';
    if (!$income_account_id)                   $errors[] = 'Please select the income account.';
    if (!$date)                                $errors[] = 'Date is required.';

    if (empty($errors)) {
        try {
            $vid = acc_collect_student_fee(
                $student_id, $package_id, $fee_type,
                $semester_fee_id, $semester_number,
                $amount, $cash_account_id, $income_account_id,
                $date, $reference, $narration
            );

            // Fetch voucher number for notifications
            $voucher = acc_get_voucher($vid);
            $voucher_number = $voucher['voucher_number'] ?? '—';

            // Fetch student with dept name + phone/email for notifications
            $stu_stmt = db()->prepare(
                'SELECT s.*, d.name AS dept_name
                 FROM students s
                 LEFT JOIN dept_departments d ON d.id = s.dept_id
                 WHERE s.id = ?'
            );
            $stu_stmt->execute([$student_id]);
            $stu = $stu_stmt->fetch();

            // Semester label (if semester payment)
            $sem_label = '';
            if ($semester_fee_id) {
                $sf_row = db()->prepare('SELECT semester_label, semester_number FROM sfp_semester_fees WHERE id = ?');
                $sf_row->execute([$semester_fee_id]);
                $sf_row = $sf_row->fetch();
                $sem_label = $sf_row['semester_label'] ?: ('Semester ' . $sf_row['semester_number']);
            }

            $currency   = acc_currency();
            $fee_label  = acc_fee_type_label($fee_type);
            $outstanding = acc_total_outstanding($package_id);

            // ── Email invoice ─────────────────────────────────────────────
            if ($stu) {
                acc_send_fee_invoice_email($stu, [
                    'voucher_number'   => $voucher_number,
                    'payment_date'     => $date,
                    'fee_type_label'   => $fee_label,
                    'semester_label'   => $sem_label,
                    'amount'           => $amount,
                    'outstanding_total'=> $outstanding,
                    'reference'        => $reference,
                    'narration'        => $narration,
                ]);

                // ── SMS ────────────────────────────────────────────────────
                $phone = $stu['phone'] ?? '';
                if ($phone) {
                    acc_send_fee_sms($phone, [
                        'student_name'   => $stu['full_name'],
                        'student_sid'    => $stu['student_id'],
                        'amount'         => number_format($amount, 2),
                        'currency'       => $currency,
                        'fee_type'       => $fee_label . ($sem_label ? ' (' . $sem_label . ')' : ''),
                        'voucher_number' => $voucher_number,
                        'app_name'       => APP_NAME,
                    ]);
                }
            }

            flash_set('success',
                'Payment of ' . $currency . ' ' . number_format($amount, 2) . ' collected successfully. ' .
                '<a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid . '" class="alert-link">View Voucher #' . h($voucher_number) . '</a>'
            );
            redirect(APP_URL . '/accounting/collect-payment.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── POST: process an admission-applicant fee payment ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'admission') {
    csrf_check();
    require_once __DIR__ . '/../admissions/helpers.php';

    $app_id            = (int)($_POST['application_id']    ?? 0);
    $amount            = (float)($_POST['amount']          ?? 0);
    $cash_account_id   = (int)($_POST['cash_account_id']   ?? 0);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    $date              = trim($_POST['voucher_date']        ?? date('Y-m-d'));
    $reference         = trim($_POST['reference']          ?? '');
    $narration         = trim($_POST['narration']          ?? '');
    $assign_sid        = ($_POST['assign_student_id'] ?? '0') === '1';

    if (!$app_id)            $errors[] = 'Invalid application.';
    if ($amount <= 0)        $errors[] = 'Amount must be greater than zero.';
    if (!$cash_account_id)   $errors[] = 'Please select the received-into account.';
    if (!$income_account_id) $errors[] = 'Please select the income account.';
    if (!$date)              $errors[] = 'Date is required.';

    // Load applicant to validate and get details
    $applicant = null;
    if (empty($errors) && $app_id) {
        $app_stmt = db()->prepare(
            'SELECT a.*, d.name AS dept_name, p.program_name
             FROM admissions_applications a
             LEFT JOIN dept_departments d       ON d.id = a.dept_id
             LEFT JOIN dept_academic_programs p ON p.id = a.program_id
             WHERE a.id = ?'
        );
        $app_stmt->execute([$app_id]);
        $applicant = $app_stmt->fetch();
        if (!$applicant) $errors[] = 'Application not found.';
    }

    if (empty($errors)) {
        try {
            $vid = acc_collect_applicant_admission_fee(
                $app_id, $amount, $cash_account_id, $income_account_id,
                $date, $reference, $narration
            );

            $voucher        = acc_get_voucher($vid);
            $voucher_number = $voucher['voucher_number'] ?? '—';
            $currency       = acc_currency();

            // Assign student ID if requested and not yet assigned
            $assigned_sid = '';
            if ($assign_sid && !empty($applicant['program_id']) && empty($applicant['office_student_id'])) {
                $assigned_sid = adm_sid_generate((int)$applicant['program_id']);
                if ($assigned_sid !== '') {
                    db()->prepare(
                        'UPDATE admissions_applications SET office_student_id = ? WHERE id = ?'
                    )->execute([$assigned_sid, $app_id]);
                }
            }

            $success_msg = 'Admission fee of ' . $currency . ' ' . number_format($amount, 2) .
                ' collected for <strong>' . h($applicant['student_name']) . '</strong>. ' .
                '<a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid .
                '" class="alert-link">View Voucher #' . h($voucher_number) . '</a>';

            if ($assigned_sid !== '') {
                $success_msg .= ' — Student ID assigned: <strong>' . h($assigned_sid) . '</strong>';
            }

            flash_set('success', $success_msg);
            redirect(APP_URL . '/accounting/collect-payment.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── POST: process a general payment ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'general') {
    csrf_check();

    $amount            = (float)($_POST['amount']            ?? 0);
    $cash_account_id   = (int)($_POST['cash_account_id']     ?? 0);
    $income_account_id = (int)($_POST['income_account_id']   ?? 0);
    $date              = trim($_POST['voucher_date']          ?? date('Y-m-d'));
    $reference         = trim($_POST['reference']            ?? '');
    $narration         = trim($_POST['narration']            ?? '');

    if ($amount <= 0)            $errors[] = 'Amount must be greater than zero.';
    if (!$cash_account_id)       $errors[] = 'Please select the received-into account.';
    if (!$income_account_id)     $errors[] = 'Please select the income type.';
    if (!$date)                  $errors[] = 'Date is required.';
    if ($cash_account_id === $income_account_id) $errors[] = 'Source and destination accounts cannot be the same.';

    if (empty($errors)) {
        try {
            $vid = acc_collect_payment($amount, $cash_account_id, $income_account_id, $date, $reference, $narration);
            flash_set('success', 'Payment collected successfully. <a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid . '" class="alert-link">View Voucher</a>');
            redirect(APP_URL . '/accounting/collect-payment.php');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$active_tab = 'student';
if (($_POST['mode'] ?? '') === 'general')    $active_tab = 'general';
if (($_POST['mode'] ?? '') === 'admission')  $active_tab = 'admission';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-hand-holding-usd me-2 text-success"></i>Collect Payment</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Collect Payment</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/vouchers.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-list me-1"></i> All Vouchers
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- ── Tabs ──────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="payTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'student' ? 'active' : '' ?>"
                id="tab-student" data-bs-toggle="tab" data-bs-target="#pane-student"
                type="button" role="tab">
            <i class="fas fa-user-graduate me-1"></i> Student Fee Collection
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'general' ? 'active' : '' ?>"
                id="tab-general" data-bs-toggle="tab" data-bs-target="#pane-general"
                type="button" role="tab">
            <i class="fas fa-coins me-1"></i> General Receipt
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $active_tab === 'admission' ? 'active' : '' ?>"
                id="tab-admission" data-bs-toggle="tab" data-bs-target="#pane-admission"
                type="button" role="tab">
            <i class="fas fa-user-plus me-1"></i> Admission Fee (Pre-Enrolment)
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1 – Student Fee Collection                                          -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'student' ? 'show active' : '' ?>"
     id="pane-student" role="tabpanel">

    <!-- Student search ──────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header py-3 px-4">
            <span class="fw-semibold"><i class="fas fa-search me-2 text-primary"></i>Find Student</span>
        </div>
        <div class="card-body p-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Student ID or Name</label>
                    <input type="text" id="studentSearch" class="form-control"
                           placeholder="Type student ID or name…" autocomplete="off">
                    <div id="studentSuggestions" class="list-group position-absolute z-3 w-100" style="max-width:420px;display:none;"></div>
                </div>
                <div class="col-md-3">
                    <button type="button" id="btnLoadFees" class="btn btn-primary w-100" disabled>
                        <i class="fas fa-calculator me-1"></i> Load Fee Summary
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Fee summary panel ──────────────────────────────────────────────── -->
    <div id="feeSummaryWrap" style="display:none;">

        <!-- Student info strip -->
        <div class="card border-0 shadow-sm mb-3" id="studentInfoCard">
            <div class="card-body py-3 px-4 d-flex align-items-center gap-3 flex-wrap">
                <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center" style="width:48px;height:48px;flex-shrink:0;">
                    <i class="fas fa-user-graduate fa-lg"></i>
                </div>
                <div>
                    <div class="fw-bold fs-6" id="infoName"></div>
                    <div class="small text-muted" id="infoMeta"></div>
                </div>
                <div class="ms-auto d-flex gap-2">
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2" id="infoStatus"></span>
                </div>
            </div>
        </div>

        <!-- Outstanding fee table -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Obligations & Outstanding Balance</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-6" id="totalOutstandingBadge"></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0" id="feeTable">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Fee Type</th>
                                <th class="text-end">Total Due</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end fw-bold">Outstanding</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="feeTableBody"></tbody>
                        <tfoot class="table-light fw-bold" id="feeTableFoot">
                            <tr>
                                <td class="ps-4">Total</td>
                                <td class="text-end" id="footTotalDue"></td>
                                <td class="text-end" id="footTotalPaid"></td>
                                <td class="text-end text-danger" id="footTotalOut"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Payment form (shown when user clicks Collect) -->
        <div id="paymentFormCard" style="display:none;">
            <div class="card border-0 shadow-sm border-start border-success border-3">
                <div class="card-header py-3 px-4 bg-success bg-opacity-10">
                    <span class="fw-semibold text-success"><i class="fas fa-check-circle me-2"></i>
                        Confirm & Post Payment — <span id="payFormFeeLabel"></span>
                    </span>
                </div>
                <div class="card-body p-4">
                    <form method="post" id="studentPayForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="student">
                        <input type="hidden" name="student_id"      id="hStudentId">
                        <input type="hidden" name="package_id"      id="hPackageId">
                        <input type="hidden" name="fee_type"        id="hFeeType">
                        <input type="hidden" name="semester_fee_id" id="hSemFeeId">
                        <input type="hidden" name="semester_number" id="hSemNumber">
                        <input type="hidden" name="income_account_id" id="hIncomeAccountId">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                <input type="date" name="voucher_date" class="form-control"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    Amount (<?= acc_currency() ?>) <span class="text-danger">*</span>
                                </label>
                                <input type="number" name="amount" id="payAmount" class="form-control"
                                       step="0.01" min="0.01" required>
                                <div class="form-text">Outstanding: <strong id="payOutstanding"></strong>
                                    — partial payments allowed.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Received Into <span class="text-danger">*</span></label>
                                <select name="cash_account_id" class="form-select" required>
                                    <option value="">— Select Account —</option>
                                    <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>"
                                        <?= ($a['code'] == $default_cash) ? 'selected' : '' ?>>
                                        <?= h($a['code'] . ' – ' . $a['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Reference <small class="text-muted fw-normal">(optional)</small></label>
                                <input type="text" name="reference" class="form-control"
                                       placeholder="e.g. Receipt #, Challan #" id="payReference">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Note <small class="text-muted fw-normal">(optional)</small></label>
                                <input type="text" name="narration" class="form-control"
                                       id="payNarration" placeholder="Additional note">
                            </div>
                        </div>

                        <!-- Income account (hidden, auto-selected, shown read-only) -->
                        <div class="alert alert-light border mt-3 small">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            <strong>Accounting entry:</strong>
                            <span class="text-success">Debit</span> the received-into account &amp;
                            <span class="text-danger">Credit</span> <span id="incomeAccountLabel">the income account</span> automatically.
                            An email invoice<?= acc_setting('sms_enabled','0')==='1' ? ' and SMS' : '' ?> will be sent to the student.
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-check me-1"></i> Post &amp; Collect
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="btnCancelPay">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div><!-- /feeSummaryWrap -->

</div><!-- /tab-pane student -->


<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2 – General Receipt                                                 -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'general' ? 'show active' : '' ?>"
     id="pane-general" role="tabpanel">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header py-3 px-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-success p-2"><i class="fas fa-coins"></i></span>
                        <div>
                            <div class="fw-semibold">General Receipt Voucher</div>
                            <div class="text-muted small">Records any other money received (not tied to a student)</div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="mode" value="general">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                <input type="date" name="voucher_date" class="form-control"
                                       value="<?= old('voucher_date', date('Y-m-d')) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Amount (<?= acc_currency() ?>) <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                                       placeholder="0.00" value="<?= old('amount') ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Received Into <span class="text-danger">*</span></label>
                                <select name="cash_account_id" class="form-select" required>
                                    <option value="">— Select Account —</option>
                                    <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>"
                                        <?= (old('cash_account_id','') == $a['id'] || ($a['code'] == $default_cash && !old('cash_account_id'))) ? 'selected' : '' ?>>
                                        <?= h($a['code'] . ' – ' . $a['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Income Type <span class="text-danger">*</span></label>
                                <select name="income_account_id" class="form-select" required>
                                    <option value="">— Select Income —</option>
                                    <?php foreach ($income_accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>" <?= old('income_account_id') == $a['id'] ? 'selected' : '' ?>>
                                        <?= h($a['code'] . ' – ' . $a['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Reference / Invoice # <small class="text-muted fw-normal">(optional)</small></label>
                                <input type="text" name="reference" class="form-control"
                                       placeholder="e.g. INV-2025-001"
                                       value="<?= old('reference') ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Description / Narration <small class="text-muted fw-normal">(optional)</small></label>
                                <textarea name="narration" class="form-control" rows="2"
                                          placeholder="e.g. Miscellaneous income"><?= old('narration') ?></textarea>
                            </div>
                        </div>

                        <div class="alert alert-light border mt-4 small">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            <strong>Accounting entry:</strong>
                            <span class="text-success">Debit</span> the received-into account &amp;
                            <span class="text-danger">Credit</span> the income type account automatically.
                        </div>

                        <div class="d-flex gap-2 mt-2">
                            <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i> Post Receipt Voucher</button>
                            <a href="<?= APP_URL ?>/accounting/index.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div><!-- /tab-pane general -->

<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3 – Admission Fee Collection (Pre-Enrolment Applicant)              -->
<!-- ════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'admission' ? 'show active' : '' ?>"
     id="pane-admission" role="tabpanel">

    <!-- Applicant search ─────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header py-3 px-4">
            <span class="fw-semibold">
                <i class="fas fa-search me-2 text-primary"></i>Find Applicant by Form / Application Number
            </span>
        </div>
        <div class="card-body p-4">
            <p class="text-muted small mb-3">
                <i class="fas fa-info-circle me-1 text-primary"></i>
                Use this tab to collect the <strong>admission fee</strong> for applicants who have
                submitted an application form but have not yet been assigned a Student&nbsp;ID.
            </p>
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Application / Form Number</label>
                    <input type="text" id="admAppNumber" class="form-control"
                           placeholder="e.g. 1001" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <button type="button" id="btnLoadApplicant" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i> Find Applicant
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Applicant details + payment form (shown after successful lookup) ── -->
    <div id="admDetailWrap" style="display:none;">

        <!-- Applicant info strip -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body py-3 px-4 d-flex align-items-center gap-3 flex-wrap">
                <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center"
                     style="width:48px;height:48px;flex-shrink:0;">
                    <i class="fas fa-user-plus fa-lg"></i>
                </div>
                <div>
                    <div class="fw-bold fs-6" id="admInfoName"></div>
                    <div class="small text-muted" id="admInfoMeta"></div>
                </div>
                <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
                    <span id="admSidBadge"></span>
                    <span class="badge bg-warning text-dark border border-warning-subtle px-3 py-2" id="admStatusBadge"></span>
                </div>
            </div>
        </div>

        <!-- Payment collection form -->
        <div class="card border-0 shadow-sm border-start border-primary border-3">
            <div class="card-header py-3 px-4 bg-primary bg-opacity-10">
                <span class="fw-semibold text-primary">
                    <i class="fas fa-hand-holding-usd me-2"></i>Collect Admission Fee
                </span>
            </div>
            <div class="card-body p-4">
                <form method="post" id="admPayForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode"           value="admission">
                    <input type="hidden" name="application_id" id="hAdmAppId">
                    <input type="hidden" name="income_account_id" id="hAdmIncomeId">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="voucher_date" class="form-control"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Amount (<?= acc_currency() ?>) <span class="text-danger">*</span>
                            </label>
                            <input type="number" name="amount" id="admAmount" class="form-control"
                                   step="0.01" min="0.01" required>
                            <div class="form-text">
                                Suggested: <strong id="admSuggestedFee">—</strong>
                                &nbsp;|&nbsp; Already paid: <strong id="admAlreadyPaid">—</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Received Into <span class="text-danger">*</span></label>
                            <select name="cash_account_id" class="form-select" required>
                                <option value="">— Select Account —</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"
                                    <?= ($a['code'] == $default_cash) ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Reference <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <input type="text" name="reference" class="form-control"
                                   placeholder="e.g. Receipt #, Challan #">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Note <small class="text-muted fw-normal">(optional)</small>
                            </label>
                            <input type="text" name="narration" id="admNarration" class="form-control"
                                   placeholder="Additional note">
                        </div>

                        <!-- Assign Student ID option (shown only when possible) -->
                        <div class="col-12" id="admSidOption" style="display:none;">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="assign_student_id" id="chkAssignSid" value="1" checked>
                                <label class="form-check-label fw-semibold" for="chkAssignSid">
                                    <i class="fas fa-id-card me-1 text-success"></i>
                                    Generate &amp; assign a Student ID to this applicant after fee collection
                                </label>
                            </div>
                            <div class="form-text text-muted ms-4 ps-2">
                                A unique Student ID will be auto-generated from the program's ID settings
                                and saved to the admission application record.
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-light border mt-3 small">
                        <i class="fas fa-info-circle text-primary me-1"></i>
                        <strong>Accounting entry:</strong>
                        <span class="text-success">Debit</span> the received-into account &amp;
                        <span class="text-danger">Credit</span> <span id="admIncomeLabel">the Admission Fees income account</span> automatically.
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="fas fa-check me-1"></i> Post &amp; Collect
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnAdmCancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div><!-- /admDetailWrap -->

</div><!-- /tab-pane admission -->

</div><!-- /tab-content -->

<!-- ── JavaScript ──────────────────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    // ── Data from PHP ────────────────────────────────────────────────────────
    const CURRENCY = <?= json_encode(acc_currency()) ?>;
    const APP_URL  = <?= json_encode(APP_URL) ?>;

    // Income account map injected by AJAX response
    let incomeAccountsMap = {};

    // Currently loaded student + summary
    let currentStudent = null;
    let currentSummary = null;

    // ── Helpers ──────────────────────────────────────────────────────────────
    function fmt(n) {
        return CURRENCY + ' ' + Number(n).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function feeTypeLabel(type) {
        const map = {
            admission:        'Admission Fee',
            registration:     'Registration Fees',
            semester_tuition: 'Semester Tuition',
            fixed_fee:        'Fixed Institutional Fee',
            english_fee:      'English Course Fee',
            other:            'Other',
        };
        return map[type] || type;
    }

    // ── Student search autocomplete ──────────────────────────────────────────
    const searchInput    = document.getElementById('studentSearch');
    const suggestions    = document.getElementById('studentSuggestions');
    const btnLoad        = document.getElementById('btnLoadFees');
    let   selectedSid    = null;

    let searchTimer;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        if (q.length < 2) { suggestions.style.display = 'none'; return; }
        searchTimer = setTimeout(() => {
            fetch(APP_URL + '/student-accounts/student-search.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    suggestions.innerHTML = '';
                    if (!data.length) { suggestions.style.display = 'none'; return; }
                    data.forEach(s => {
                        const a = document.createElement('a');
                        a.href = '#';
                        a.className = 'list-group-item list-group-item-action small';
                        a.textContent = s.student_id + ' – ' + s.full_name;
                        a.addEventListener('click', e => {
                            e.preventDefault();
                            searchInput.value = s.student_id + ' – ' + s.full_name;
                            selectedSid       = s.student_id;
                            suggestions.style.display = 'none';
                            btnLoad.disabled  = false;
                        });
                        suggestions.appendChild(a);
                    });
                    suggestions.style.display = '';
                });
        }, 250);
    });

    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target)) suggestions.style.display = 'none';
    });

    // ── Load fee summary ─────────────────────────────────────────────────────
    btnLoad.addEventListener('click', function () {
        if (!selectedSid) return;

        btnLoad.disabled = true;
        btnLoad.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Loading…';

        fetch(APP_URL + '/accounting/get-student-fees.php?student_sid=' + encodeURIComponent(selectedSid))
            .then(r => r.json())
            .then(data => {
                btnLoad.disabled = false;
                btnLoad.innerHTML = '<i class="fas fa-calculator me-1"></i> Load Fee Summary';

                if (data.error) {
                    alert(data.error);
                    return;
                }

                currentStudent     = data.student;
                currentSummary     = data.summary;
                incomeAccountsMap  = data.income_accounts;

                renderFeeSummary(data);
                document.getElementById('feeSummaryWrap').style.display = '';
                document.getElementById('paymentFormCard').style.display = 'none';
            })
            .catch(() => {
                btnLoad.disabled = false;
                btnLoad.innerHTML = '<i class="fas fa-calculator me-1"></i> Load Fee Summary';
                alert('Network error. Please try again.');
            });
    });

    // ── Render fee table ─────────────────────────────────────────────────────
    function renderFeeSummary(data) {
        const s   = data.summary;
        const pkg = s.package;

        // Student info strip
        document.getElementById('infoName').textContent  = currentStudent.full_name;
        document.getElementById('infoMeta').textContent  =
            'ID: ' + currentStudent.student_id + '   |   Program: ' + pkg.program_name;
        document.getElementById('infoStatus').textContent = pkg.student_status ?? 'Active';

        const tbody = document.getElementById('feeTableBody');
        tbody.innerHTML = '';

        let grandDue  = 0, grandPaid = 0, grandOut = 0;

        // Helper to add a row
        function addRow(label, due, paid, out, feeType, semFeeId, semNumber, semLabel) {
            grandDue  += due;
            grandPaid += paid;
            grandOut  += out;

            const tr = document.createElement('tr');
            const pct = out > 0 ? Math.round((out / due) * 100) : 0;

            tr.innerHTML = `
                <td class="ps-4">
                    <div class="fw-semibold small">${label}</div>
                    ${due > 0 && out > 0
                        ? `<div class="progress mt-1" style="height:4px;width:120px;">
                               <div class="progress-bar bg-warning" style="width:${100-pct}%"></div>
                               <div class="progress-bar bg-danger opacity-50" style="width:${pct}%"></div>
                           </div>`
                        : (due > 0 ? '<div class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size:10px;">Fully Paid</div>' : '')}
                </td>
                <td class="text-end small">${due > 0 ? fmt(due) : '—'}</td>
                <td class="text-end small text-success">${paid > 0 ? fmt(paid) : '—'}</td>
                <td class="text-end small fw-bold ${out > 0 ? 'text-danger' : 'text-muted'}">${out > 0 ? fmt(out) : '—'}</td>
                <td class="text-center">
                    ${out > 0
                        ? `<button type="button" class="btn btn-sm btn-outline-success py-0 px-2 collectBtn"
                                data-fee-type="${feeType}"
                                data-sem-fee-id="${semFeeId ?? ''}"
                                data-sem-number="${semNumber ?? ''}"
                                data-sem-label="${semLabel ?? ''}"
                                data-outstanding="${out}"
                                data-label="${label}">
                               <i class="fas fa-hand-holding-usd me-1"></i>Collect
                           </button>`
                        : '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check me-1"></i>Paid</span>'}
                </td>`;
            tbody.appendChild(tr);
        }

        const t = s.totals;

        // Admission fee
        addRow('Admission Fee', t.admission.due, t.admission.paid, t.admission.out,
               'admission', null, null, null);

        // Registration fees
        addRow(
            'Registration Fees (' + s.semesters.length + ' semester' + (s.semesters.length > 1 ? 's' : '') +
            ' × ' + fmt(s.cf_settings.reg_fee_per_semester) + ')',
            t.registration.due, t.registration.paid, t.registration.out,
            'registration', null, null, null
        );

        // Semester tuition (one row per semester)
        s.semesters.forEach(sf => {
            const label = sf.semester_label
                ? sf.semester_label + ' Tuition'
                : 'Semester ' + sf.semester_number + ' Tuition';
            addRow(label, sf.tuition_due, sf.tuition_paid, sf.tuition_out,
                   'semester_tuition', sf.id, sf.semester_number, sf.semester_label ?? 'Semester ' + sf.semester_number);
        });

        // Fixed institutional fee
        if (t.fixed.due > 0) {
            addRow('Fixed Institutional Fee', t.fixed.due, t.fixed.paid, t.fixed.out,
                   'fixed_fee', null, null, null);
        }

        // English course fee
        if (t.english.due > 0) {
            addRow('English Course Fee', t.english.due, t.english.paid, t.english.out,
                   'english_fee', null, null, null);
        }

        // Footer totals
        document.getElementById('footTotalDue').textContent  = fmt(grandDue);
        document.getElementById('footTotalPaid').textContent = fmt(grandPaid);
        document.getElementById('footTotalOut').textContent  = fmt(grandOut);

        // Badge
        document.getElementById('totalOutstandingBadge').textContent =
            'Outstanding: ' + fmt(grandOut);

        // Attach collect button handlers
        tbody.querySelectorAll('.collectBtn').forEach(btn => {
            btn.addEventListener('click', () => openPayForm(btn));
        });
    }

    // ── Open payment form for a specific fee ─────────────────────────────────
    function openPayForm(btn) {
        const feeType   = btn.dataset.feeType;
        const semFeeId  = btn.dataset.semFeeId  || '';
        const semNumber = btn.dataset.semNumber || '';
        const semLabel  = btn.dataset.semLabel  || '';
        const out       = parseFloat(btn.dataset.outstanding);
        const label     = btn.dataset.label;

        document.getElementById('hStudentId').value   = currentStudent.id;
        document.getElementById('hPackageId').value   = currentStudent.package_id;
        document.getElementById('hFeeType').value     = feeType;
        document.getElementById('hSemFeeId').value    = semFeeId;
        document.getElementById('hSemNumber').value   = semNumber;
        document.getElementById('hIncomeAccountId').value = incomeAccountsMap[feeType] ?? '';

        document.getElementById('payAmount').value    = out.toFixed(2);
        document.getElementById('payOutstanding').textContent = fmt(out);
        document.getElementById('payFormFeeLabel').textContent = label;

        // Auto-fill narration
        const pkg = currentSummary.package;
        let narr  = label + ' – ' + pkg.student_name + ' (' + currentStudent.student_id + ')';
        if (semLabel) narr += ' – ' + semLabel;
        document.getElementById('payNarration').value = narr;

        // Show income account label for the info box
        const incomeAccId = incomeAccountsMap[feeType] ?? 0;
        const incomeAccLabel = <?= json_encode(
            array_column(array_map(fn($a) => ['id' => $a['id'], 'label' => $a['code'] . ' – ' . $a['name']], $income_accounts), 'label', 'id')
        ) ?>;
        document.getElementById('incomeAccountLabel').textContent =
            incomeAccLabel[incomeAccId] ? incomeAccLabel[incomeAccId] : 'the income account';

        const card = document.getElementById('paymentFormCard');
        card.style.display = '';
        card.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    document.getElementById('btnCancelPay').addEventListener('click', () => {
        document.getElementById('paymentFormCard').style.display = 'none';
    });

    // ════════════════════════════════════════════════════════════════════════
    // Tab 3 – Admission Applicant logic
    // ════════════════════════════════════════════════════════════════════════

    const admAppNumberInput = document.getElementById('admAppNumber');
    const btnLoadApplicant  = document.getElementById('btnLoadApplicant');
    const admDetailWrap     = document.getElementById('admDetailWrap');
    const incomeAccLabel    = <?= json_encode(
        array_column(array_map(fn($a) => ['id' => $a['id'], 'label' => $a['code'] . ' – ' . $a['name']], $income_accounts), 'label', 'id')
    ) ?>;

    function fmtAdm(n) {
        return CURRENCY + ' ' + Number(n).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    // Allow pressing Enter in the app number box to trigger search
    admAppNumberInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); btnLoadApplicant.click(); }
    });

    btnLoadApplicant.addEventListener('click', function () {
        const appNo = admAppNumberInput.value.trim();
        if (!appNo) { admAppNumberInput.focus(); return; }

        btnLoadApplicant.disabled = true;
        btnLoadApplicant.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Searching…';
        admDetailWrap.style.display = 'none';

        fetch(APP_URL + '/accounting/get-applicant-fees.php?app_number=' + encodeURIComponent(appNo))
            .then(r => r.json())
            .then(data => {
                btnLoadApplicant.disabled = false;
                btnLoadApplicant.innerHTML = '<i class="fas fa-search me-1"></i> Find Applicant';

                if (data.error) {
                    alert(data.error);
                    return;
                }

                const ap  = data.applicant;

                // Info strip
                document.getElementById('admInfoName').textContent =
                    ap.student_name;
                document.getElementById('admInfoMeta').textContent =
                    'Form #: ' + ap.app_number +
                    (ap.program_name ? '   |   ' + ap.program_name : '') +
                    (ap.dept_name    ? '   |   ' + ap.dept_name    : '') +
                    (ap.present_contact ? '   |   ' + ap.present_contact : '');

                // Status badge
                document.getElementById('admStatusBadge').textContent =
                    ap.status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

                // Student ID badge (if already assigned)
                const sidBadge = document.getElementById('admSidBadge');
                if (ap.office_student_id) {
                    sidBadge.className = 'badge bg-success px-3 py-2';
                    sidBadge.textContent = 'Student ID: ' + ap.office_student_id;
                } else {
                    sidBadge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-2';
                    sidBadge.textContent = 'No Student ID yet';
                }

                // Hidden fields
                document.getElementById('hAdmAppId').value  = ap.id;
                document.getElementById('hAdmIncomeId').value = data.income_account_id;

                // Fee amounts
                document.getElementById('admSuggestedFee').textContent  = fmtAdm(data.suggested_fee);
                document.getElementById('admAlreadyPaid').textContent   = fmtAdm(data.already_paid);
                document.getElementById('admAmount').value = (data.suggested_fee - data.already_paid).toFixed(2);

                // Income account label
                const incLbl = incomeAccLabel[data.income_account_id] || 'Admission Fees income account';
                document.getElementById('admIncomeLabel').textContent = incLbl;

                // Auto-fill narration
                document.getElementById('admNarration').value =
                    'Admission Fee – ' + ap.student_name + ' (Form #' + ap.app_number + ')';

                // Show/hide "Assign Student ID" option
                const sidOption = document.getElementById('admSidOption');
                sidOption.style.display = data.can_assign_sid ? '' : 'none';

                admDetailWrap.style.display = '';
                admDetailWrap.scrollIntoView({behavior: 'smooth', block: 'start'});
            })
            .catch(() => {
                btnLoadApplicant.disabled = false;
                btnLoadApplicant.innerHTML = '<i class="fas fa-search me-1"></i> Find Applicant';
                alert('Network error. Please try again.');
            });
    });

    document.getElementById('btnAdmCancel').addEventListener('click', () => {
        admDetailWrap.style.display = 'none';
        admAppNumberInput.value = '';
        admAppNumberInput.focus();
    });

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
