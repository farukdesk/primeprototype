<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title      = 'Collect Payment';
$cash_accounts   = acc_cash_accounts();
$income_accounts = acc_income_accounts();
$default_cash    = acc_setting('default_cash_account', '1100');
$received_into_map = acc_received_into_account_map_for_payment_methods();
$cash_account_labels_by_id = [];
foreach ($cash_accounts as $a) {
    $cash_account_labels_by_id[(int)$a['id']] = $a['code'] . ' – ' . $a['name'];
}
$errors          = [];

// ── One-time payment nonce helpers (prevent duplicate payment on browser refresh / POST replay) ──
function payment_nonce_generate(): void {
    $_SESSION['collect_payment_nonce'] = bin2hex(random_bytes(16));
}

/**
 * Validate the submitted nonce against the session nonce, consume it, and
 * redirect with a warning on mismatch (duplicate-submission detected).
 */
function payment_nonce_check_and_consume(string $fallback_redirect_url): void {
    $submitted = $_POST['payment_nonce'] ?? '';
    $stored    = $_SESSION['collect_payment_nonce'] ?? '';
    if (!$submitted || !$stored || !hash_equals($stored, $submitted)) {
        flash_set('warning', 'This payment form was already submitted. Please check the transaction history below to avoid collecting twice.');
        redirect($fallback_redirect_url, 303);
    }
    unset($_SESSION['collect_payment_nonce']);
}

// Fresh nonce on every GET so the form starts with a valid token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    payment_nonce_generate();
}

$received_into_mapping_error = static function (string $payment_method): string {
    return 'Received-into mapping is missing for payment method "' . acc_payment_method_label($payment_method) . '". Please configure it in Accounting Settings.';
};

// ── POST: process a student-fee payment ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'student') {
    csrf_check();

    // Duplicate-submission guard: validate and consume the one-time nonce
    payment_nonce_check_and_consume(APP_URL . '/accounting/collect-payment.php?tab=student');

    $student_id      = (int)($_POST['student_id']       ?? 0);
    $package_id      = (int)($_POST['package_id']       ?? 0);
    $collection_mode = trim((string)($_POST['collection_mode'] ?? 'single'));
    $fee_items_json  = trim((string)($_POST['fee_items'] ?? ''));
    $fee_type        = trim($_POST['fee_type']           ?? '');
    $semester_fee_id = (int)($_POST['semester_fee_id']  ?? 0) ?: null;
    $semester_number = (int)($_POST['semester_number']  ?? 0) ?: null;
    $month_number    = (int)($_POST['month_number']      ?? 0) ?: null;
    $amount          = (float)($_POST['amount']         ?? 0);
    $payment_method  = trim((string)($_POST['payment_method'] ?? 'cash'));
    $mobile_banking_provider = trim((string)($_POST['mobile_banking_provider'] ?? ''));
    $transaction_number = trim((string)($_POST['transaction_number'] ?? ''));
    $received_into_account_id = acc_received_into_account_id_for_payment_method($payment_method);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    $date            = trim($_POST['voucher_date']       ?? date('Y-m-d'));
    $reference       = trim($_POST['reference']          ?? '');
    $narration       = trim($_POST['narration']          ?? '');

    $valid_types = ['admission','registration','semester_tuition','fixed_fee','english_fee','other'];

    if (!$student_id)                          $errors[] = 'Invalid student.';
    if (!$package_id)                          $errors[] = 'Student has no fee package.';
    if (!$date)                                $errors[] = 'Date is required.';
    if (!in_array($payment_method, ['cash', 'bank', 'mobile_banking'], true)) {
        $errors[] = 'Invalid payment method selected.';
    }
    if ($payment_method === 'mobile_banking' && !in_array($mobile_banking_provider, ['bkash', 'nagad', 'rocket'], true)) {
        $errors[] = 'Please select a mobile banking provider.';
    }
    if ($payment_method !== 'cash' && $transaction_number === '') {
        $errors[] = 'Transaction number is required for non-cash payments.';
    }
    if ($received_into_account_id <= 0) {
        $errors[] = $received_into_mapping_error($payment_method);
    }

    if (empty($errors)) {
        try {
            $fee_items = [];
            if ($collection_mode === 'multi') {
                $summary = acc_student_fee_summary($student_id);
                if (!$summary) {
                    throw new RuntimeException('Could not load current fee summary for this student.');
                }
                $outstanding_lookup = [];
                $tot = $summary['totals'] ?? [];
                $outstanding_lookup['admission|||'] = (float)($tot['admission']['out'] ?? 0);
                foreach (($summary['semesters'] ?? []) as $sf) {
                    $key_reg = 'registration|' . (int)$sf['id'] . '|' . (int)$sf['semester_number'] . '|';
                    $outstanding_lookup[$key_reg] = (float)($sf['reg_out'] ?? 0);
                    foreach (($sf['monthly_rows'] ?? []) as $mr) {
                        $key_tuition = 'semester_tuition|' . (int)$sf['id'] . '|' . (int)$sf['semester_number'] . '|' . (int)$mr['month_number'];
                        $outstanding_lookup[$key_tuition] = (float)($mr['out'] ?? 0);
                    }
                }

                $decoded = json_decode($fee_items_json, true);
                if (!is_array($decoded) || !$decoded) {
                    throw new RuntimeException('Please select at least one outstanding fee item.');
                }
                foreach ($decoded as $item) {
                    $row_fee_type = trim((string)($item['fee_type'] ?? ''));
                    $row_amount   = (float)($item['amount'] ?? 0);
                    if (!in_array($row_fee_type, $valid_types, true) || $row_amount <= 0) {
                        continue;
                    }
                    $key = $row_fee_type . '|'
                        . ((int)($item['semester_fee_id'] ?? 0) ?: '') . '|'
                        . ((int)($item['semester_number'] ?? 0) ?: '') . '|'
                        . ((int)($item['month_number'] ?? 0) ?: '');
                    $outstanding_now = (float)($outstanding_lookup[$key] ?? 0);
                    // Skip already-fully-paid items in case the UI selection becomes stale.
                    if ($outstanding_now <= 0) {
                        continue;
                    }
                    $fee_items[] = [
                        'fee_type'        => $row_fee_type,
                        'semester_fee_id' => (int)($item['semester_fee_id'] ?? 0) ?: null,
                        'semester_number' => (int)($item['semester_number'] ?? 0) ?: null,
                        'month_number'    => (int)($item['month_number'] ?? 0) ?: null,
                        'amount'          => round(min($row_amount, $outstanding_now), 2),
                        'label'           => trim((string)($item['label'] ?? '')),
                        'income_account_id' => (int)($item['income_account_id'] ?? 0),
                    ];
                }
                if (!$fee_items) {
                    throw new RuntimeException('No valid fee items were selected.');
                }
            } else {
                if (!in_array($fee_type, $valid_types, true)) {
                    throw new RuntimeException('Invalid fee type selected.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                if (!$income_account_id) {
                    throw new RuntimeException('Please select the income account.');
                }
                $fee_items[] = [
                    'fee_type'        => $fee_type,
                    'semester_fee_id' => $semester_fee_id,
                    'semester_number' => $semester_number,
                    'month_number'    => $month_number,
                    'amount'          => round($amount, 2),
                    'label'           => '',
                    'income_account_id' => $income_account_id,
                ];
            }

            $stu_stmt = db()->prepare(
                'SELECT s.*, d.name AS dept_name, p.program_name
                 FROM students s
                 LEFT JOIN dept_departments d       ON d.id = s.dept_id
                 LEFT JOIN dept_academic_programs p ON p.id = s.program_id
                 WHERE s.id = ?'
            );
            $stu_stmt->execute([$student_id]);
            $stu = $stu_stmt->fetch();

            $voucher_links   = [];
            $voucher_ids_arr = [];
            $all_invoice_items = [];
            $last_voucher_id = 0;
            $total_amount    = 0.0;
            $first_voucher_number = '';

            foreach ($fee_items as $item) {
                if (!$item['income_account_id']) {
                    throw new RuntimeException('Income account mapping is missing for one of the selected fee items.');
                }
                $item_narration = $narration;
                if ($collection_mode === 'multi' && !empty($item['label'])) {
                    $item_narration = $item['label'] . ($narration !== '' ? ' | ' . $narration : '');
                }
                $provider = $mobile_banking_provider !== '' ? $mobile_banking_provider : null;
                $txn_no = $transaction_number !== '' ? $transaction_number : null;
                $vid = acc_collect_student_fee(
                    $student_id, $package_id, $item['fee_type'],
                    $item['semester_fee_id'], $item['semester_number'], $item['month_number'],
                    $payment_method, $provider, $txn_no,
                    $item['amount'], $received_into_account_id, $item['income_account_id'],
                    $date, $reference, $item_narration
                );
                $last_voucher_id = (int)$vid;
                $total_amount += (float)$item['amount'];
                $voucher_ids_arr[] = (int)$vid;

                // Fetch voucher number for success message
                $voucher        = acc_get_voucher($vid);
                $voucher_number = $voucher['voucher_number'] ?? '—';
                if ($first_voucher_number === '') {
                    $first_voucher_number = $voucher_number;
                }

                // Semester label (if semester payment)
                $sem_label = '';
                if ($item['semester_fee_id']) {
                    $sf_row = db()->prepare('SELECT semester_label, semester_number FROM sfp_semester_fees WHERE id = ?');
                    $sf_row->execute([$item['semester_fee_id']]);
                    $sf_row = $sf_row->fetch();
                    $sem_label = ($sf_row && $sf_row['semester_label'] !== '' && $sf_row['semester_label'] !== null)
                        ? (string)$sf_row['semester_label']
                        : ($sf_row && $sf_row['semester_number'] ? 'Semester ' . $sf_row['semester_number'] : '');
                }

                $fee_label = acc_fee_type_label($item['fee_type']);

                // Build invoice item for the combined email PDF
                $all_invoice_items[] = [
                    'voucher_id'     => $vid,
                    'voucher_number' => $voucher_number,
                    'fee_type_label' => $fee_label,
                    'semester_label' => $sem_label,
                    'month_label'    => $item['month_number'] ? 'Month ' . $item['month_number'] : '',
                    'amount'         => $item['amount'],
                    'narration'      => $item_narration,
                ];

                $voucher_links[] =
                    '<a href="' . APP_URL . '/accounting/fee-invoice.php?voucher_id=' . (int)$vid .
                    '" target="_blank" class="alert-link fw-semibold"><i class="fas fa-print me-1"></i>' .
                    h($voucher_number) . '</a>';
            }

            // ── Send ONE email and ONE SMS after all fee items are collected ──────
            if ($stu && $all_invoice_items) {
                $currency      = acc_currency();
                $first_item    = $all_invoice_items[0];
                $multi         = count($all_invoice_items) > 1;
                $summary_info  = [
                    'voucher_id'     => $first_item['voucher_id'],
                    'voucher_number' => $first_voucher_number,
                    'payment_date'   => $date,
                    'fee_type_label' => $multi ? 'Multiple Fee Payment' : $first_item['fee_type_label'],
                    'semester_label' => $multi ? '' : $first_item['semester_label'],
                    'amount'         => $total_amount,
                    'reference'      => $reference,
                    'narration'      => $multi ? 'Multiple fee payment' : $first_item['narration'],
                ];
                acc_send_fee_invoice_email($stu, $summary_info, $all_invoice_items);

                $phone = $stu['phone'] ?? '';
                if ($phone) {
                    $fee_type_sms = $multi
                        ? 'Multiple Fees'
                        : ($first_item['fee_type_label'] . ($first_item['semester_label'] ? ' (' . $first_item['semester_label'] . ')' : ''));
                    acc_send_fee_sms($phone, [
                        'student_name'   => $stu['full_name'],
                        'student_sid'    => $stu['student_id'],
                        'amount'         => number_format($total_amount, 2),
                        'currency'       => $currency,
                        'fee_type'       => $fee_type_sms,
                        'voucher_number' => $first_voucher_number,
                        'app_name'       => APP_NAME,
                    ]);
                }
            }

            $currency = acc_currency();
            if ($collection_mode === 'multi') {
                $combined_invoice_url = APP_URL . '/accounting/fee-invoice.php?voucher_ids=' . implode(',', $voucher_ids_arr);
                $item_count = count($voucher_ids_arr);
                $fees_label = $item_count === 1 ? '1 fee' : $item_count . ' fees';
                flash_set(
                    'success',
                    $fees_label . ' collected successfully. Total: ' . $currency . ' ' . number_format($total_amount, 2) .
                    '. &nbsp;<a href="' . $combined_invoice_url . '" target="_blank" class="alert-link fw-semibold"><i class="fas fa-print me-1"></i>Print Invoice</a>'
                );
            } else {
                $voucher = acc_get_voucher($last_voucher_id);
                $voucher_number = $voucher['voucher_number'] ?? '—';
                flash_set(
                    'success',
                    'Payment of ' . $currency . ' ' . number_format($total_amount, 2) . ' collected successfully. ' .
                    '<a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $last_voucher_id . '" class="alert-link">View Voucher #' . h($voucher_number) . '</a>' .
                    ' &nbsp;|&nbsp; ' .
                    '<a href="' . APP_URL . '/accounting/fee-invoice.php?voucher_id=' . $last_voucher_id . '" target="_blank" class="alert-link fw-semibold"><i class="fas fa-print me-1"></i>Print Invoice</a>'
                );
            }
            $sid_for_next = $stu['student_id'] ?? '';
            $next_url = APP_URL . '/accounting/collect-payment.php?tab=student&student_sid=' . urlencode($sid_for_next);
            if ($collection_mode === 'multi' && $voucher_ids_arr) {
                $next_url .= '&invoice_voucher_ids=' . implode(',', $voucher_ids_arr);
            } elseif ($last_voucher_id > 0) {
                $next_url .= '&invoice_voucher_id=' . (int)$last_voucher_id;
            }
            redirect($next_url, 303);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    // Regenerate nonce if errors occurred so the user can retry
    if (!empty($errors)) {
        payment_nonce_generate();
    }
}

// ── POST: process an admission-applicant fee payment ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'admission') {
    csrf_check();
    require_once __DIR__ . '/../admissions/helpers.php';

    // Duplicate-submission guard: validate and consume the one-time nonce
    payment_nonce_check_and_consume(APP_URL . '/accounting/collect-payment.php?tab=admission');

    $app_id            = (int)($_POST['application_id']    ?? 0);
    $amount            = (float)($_POST['amount']          ?? 0);
    $payment_method    = trim((string)($_POST['payment_method'] ?? 'cash'));
    $mobile_banking_provider = trim((string)($_POST['mobile_banking_provider'] ?? ''));
    $transaction_number = trim((string)($_POST['transaction_number'] ?? ''));
    $received_into_account_id = acc_received_into_account_id_for_payment_method($payment_method);
    $income_account_id = (int)($_POST['income_account_id'] ?? 0);
    $date              = trim($_POST['voucher_date']        ?? date('Y-m-d'));
    $reference         = trim($_POST['reference']          ?? '');
    $narration         = trim($_POST['narration']          ?? '');

    if (!$app_id)            $errors[] = 'Invalid application.';
    if ($amount <= 0)        $errors[] = 'Amount must be greater than zero.';
    if (!$income_account_id) $errors[] = 'Please select the income account.';
    if (!$date)              $errors[] = 'Date is required.';
    if (!in_array($payment_method, ['cash', 'bank', 'mobile_banking'], true)) {
        $errors[] = 'Invalid payment method selected.';
    }
    if ($payment_method === 'mobile_banking' && !in_array($mobile_banking_provider, ['bkash', 'nagad', 'rocket'], true)) {
        $errors[] = 'Please select a mobile banking provider.';
    }
    if ($payment_method !== 'cash' && $transaction_number === '') {
        $errors[] = 'Transaction number is required for non-cash payments.';
    }
    if ($received_into_account_id <= 0) {
        $errors[] = $received_into_mapping_error($payment_method);
    }

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
            $adm_provider = $mobile_banking_provider !== '' ? $mobile_banking_provider : null;
            $adm_txn_no = $transaction_number !== '' ? $transaction_number : null;
            $vid = acc_collect_applicant_admission_fee(
                $app_id, $amount, $received_into_account_id, $income_account_id,
                $payment_method, $adm_provider, $adm_txn_no,
                $date, $reference, $narration
            );

            $voucher        = acc_get_voucher($vid);
            $voucher_number = $voucher['voucher_number'] ?? '—';
            $currency       = acc_currency();

            // Generate & assign student ID if not yet assigned and settings exist
            $assigned_sid = $applicant['office_student_id'] ?? '';
            if ($assigned_sid === '' && !empty($applicant['program_id'])) {
                $new_sid = adm_sid_generate((int)$applicant['program_id']);
                if ($new_sid !== '') {
                    db()->prepare(
                        'UPDATE admissions_applications SET office_student_id = ? WHERE id = ?'
                    )->execute([$new_sid, $app_id]);
                    $assigned_sid = $new_sid;
                }
            }

            // Mark application as admission complete
            db()->prepare(
                "UPDATE admissions_applications SET status = 'admission_complete' WHERE id = ?"
            )->execute([$app_id]);

            // Create student record in students module (if not already exists)
            if ($assigned_sid !== '') {
                acc_create_student_from_applicant($applicant, $assigned_sid);
            }

            // Retrieve cf_settings for fee breakdown in notifications
            $cf_row      = db()->query('SELECT admission_fee_base, form_id_fee FROM cf_settings WHERE id = 1')->fetch();
            $adm_fee_lbl = ($cf_row
                ? 'Admission Fee + ID Card & Form Fee (' . $currency . ' ' . number_format((float)$cf_row['admission_fee_base'], 2) .
                  ' + ' . $currency . ' ' . number_format((float)$cf_row['form_id_fee'], 2) . ')'
                : 'Admission Fee');

            // Send email invoice
            acc_send_admission_complete_email($applicant, $assigned_sid, [
                'voucher_id'        => $vid,
                'voucher_number'    => $voucher_number,
                'payment_date'      => $date,
                'fee_type_label'    => $adm_fee_lbl,
                'semester_label'    => '',
                'amount'            => $amount,
                'outstanding_total' => 0,
                'reference'         => $reference,
                'narration'         => $narration,
            ]);

            // Send SMS
            acc_send_admission_complete_sms($applicant, $assigned_sid, $voucher_number);

            $success_msg = 'Admission &amp; ID/Form fee of ' . $currency . ' ' . number_format($amount, 2) .
                ' collected for <strong>' . h($applicant['student_name']) . '</strong>. ' .
                '<a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid .
                '" class="alert-link">View Voucher #' . h($voucher_number) . '</a>' .
                ' — Status set to <strong>Admission Complete</strong>.';

            if ($assigned_sid !== '') {
                $success_msg .= ' Student ID: <strong>' . h($assigned_sid) . '</strong>.';
            }

            $success_msg .= ' Student Copy invoice has been sent by email' . ($sms_enabled ? ' and payment SMS has been sent.' : ' (SMS currently disabled in Accounting Settings).');
            $success_msg .= ' &nbsp;|&nbsp; <a href="' . APP_URL . '/accounting/fee-invoice.php?voucher_id=' . $vid .
                '" target="_blank" class="alert-link fw-semibold"><i class="fas fa-print me-1"></i>Print Invoice</a>';

            flash_set('success', $success_msg);
            redirect(APP_URL . '/accounting/collect-payment.php?tab=admission&invoice_voucher_id=' . (int)$vid, 303);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    // Regenerate nonce if errors occurred so the user can retry
    if (!empty($errors)) {
        payment_nonce_generate();
    }
}

// ── POST: process a general payment ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'general') {
    csrf_check();

    // Duplicate-submission guard: validate and consume the one-time nonce
    payment_nonce_check_and_consume(APP_URL . '/accounting/collect-payment.php');

    $amount            = (float)($_POST['amount']            ?? 0);
    $received_into_account_id = (int)($_POST['received_into_account_id'] ?? $_POST['cash_account_id'] ?? 0);
    $income_account_id = (int)($_POST['income_account_id']   ?? 0);
    $date              = trim($_POST['voucher_date']          ?? date('Y-m-d'));
    $reference         = trim($_POST['reference']            ?? '');
    $narration         = trim($_POST['narration']            ?? '');

    if ($amount <= 0)            $errors[] = 'Amount must be greater than zero.';
    if (!$received_into_account_id) $errors[] = 'Please select the received-into account.';
    if (!$income_account_id)     $errors[] = 'Please select the income type.';
    if (!$date)                  $errors[] = 'Date is required.';
    if ($received_into_account_id === $income_account_id) $errors[] = 'Source and destination accounts cannot be the same.';

    if (empty($errors)) {
        try {
            $vid = acc_collect_payment($amount, $received_into_account_id, $income_account_id, $date, $reference, $narration);
            flash_set('success', 'Payment collected successfully. <a href="' . APP_URL . '/accounting/voucher-view.php?id=' . $vid . '" class="alert-link">View Voucher</a>');
            redirect(APP_URL . '/accounting/collect-payment.php', 303);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }
    // Regenerate nonce if errors occurred so the user can retry
    if (!empty($errors)) {
        payment_nonce_generate();
    }
}

$form_nonce = $_SESSION['collect_payment_nonce'] ?? '';

$active_tab = 'student';
if (($_POST['mode'] ?? '') === 'general')    $active_tab = 'general';
if (($_POST['mode'] ?? '') === 'admission')  $active_tab = 'admission';
if (in_array($_GET['tab'] ?? '', ['student', 'general', 'admission'], true)) {
    $active_tab = $_GET['tab'];
}

$sms_enabled = acc_setting('sms_enabled', '0') === '1';
$adm_notification_note = $sms_enabled
    ? 'Student Copy invoice email and payment SMS are sent to the applicant with their Student ID.'
    : 'Student Copy invoice email is sent to the applicant with their Student ID (SMS currently disabled).';
$invoice_popup_voucher_id = (int)($_GET['invoice_voucher_id'] ?? 0);
$invoice_popup_voucher_ids_raw = '';
if (!empty($_GET['invoice_voucher_ids'])) {
    $_ids = array_filter(array_map('intval', explode(',', (string)$_GET['invoice_voucher_ids'])));
    if ($_ids) { $invoice_popup_voucher_ids_raw = implode(',', $_ids); }
}
$auto_student_sid = trim($_GET['student_sid'] ?? '');

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
                    <div class="form-text">Select from suggestions or enter a valid Student ID, then press Enter to load quickly.</div>
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
        <div id="studentFeeAccordion">

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

        <!-- ── Smart Payment Card ─────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-3" id="smartPayCard" style="display:none;">
            <div class="card-header py-3 px-4 bg-primary bg-opacity-10 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span class="fw-semibold text-primary fs-6">
                    <i class="fas fa-bolt me-2"></i>Smart Payment
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge rounded-pill bg-danger px-3 py-2 small" id="spPastDue" style="display:none;">
                        <i class="fas fa-exclamation-triangle me-1"></i>Past Due: <span id="spPastDueAmt">—</span>
                    </span>
                    <span class="badge rounded-pill bg-warning text-dark px-3 py-2 small" id="spCurrentDue" style="display:none;">
                        <i class="fas fa-calendar-day me-1"></i>Current: <span id="spCurrentDueAmt">—</span>
                    </span>
                    <span class="badge rounded-pill bg-info px-3 py-2 small" id="spFutureDue" style="display:none;">
                        <i class="fas fa-calendar-alt me-1"></i>Upcoming: <span id="spFutureDueAmt">—</span>
                    </span>
                    <span class="small text-primary fw-semibold">Total: <span class="badge bg-danger px-3 py-2 fs-6" id="spTotalOut">—</span></span>
                </div>
            </div>
            <div class="card-body p-4">

                <!-- Amount entry -->
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-sm-5 col-md-4">
                        <label class="form-label fw-semibold mb-1" for="spAmount">
                            Amount to Collect (<?= acc_currency() ?>) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text fw-bold text-primary"><?= acc_currency() ?></span>
                            <input type="number" id="spAmount" class="form-control form-control-lg fw-bold text-end"
                                   step="1" min="1" placeholder="0" autocomplete="off"
                                   aria-label="Amount received in <?= acc_currency() ?>">
                        </div>
                    </div>
                    <div class="col-sm-7 col-md-8 d-flex align-items-end gap-2 flex-wrap pb-1">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="spBtnAllOut">
                            <i class="fas fa-fire me-1"></i>All Outstanding
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning text-warning-emphasis" id="spBtnDueNow">
                            <i class="fas fa-clock me-1"></i>Due Now
                        </button>
                    </div>
                </div>

                <!-- Note -->
                <div id="spNoteWrap" style="display:none;">
                    <div class="alert border small py-2 mb-3" id="spNote" role="status" aria-live="polite"></div>
                </div>

                <!-- Distribution preview (full width) -->
                <div id="spDistWrap" style="display:none;">
                    <div class="border rounded overflow-hidden mb-3">
                        <div class="px-3 py-2 bg-light border-bottom d-flex align-items-center justify-content-between">
                            <span class="small fw-semibold text-muted">
                                <i class="fas fa-layer-group me-1"></i>Payment Distribution
                            </span>
                            <span class="small text-muted" id="spDistMeta"></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3" style="width:36px;" scope="col" aria-label="Row number">#</th>
                                        <th scope="col">Fee Item</th>
                                        <th class="text-end" scope="col">Outstanding</th>
                                        <th class="text-end fw-bold text-success" scope="col">Applied</th>
                                        <th class="text-center" style="width:90px;" scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="spDistBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="spProceedWrap" style="display:none;">
                        <button type="button" class="btn btn-primary px-4" id="spBtnProceed">
                            <i class="fas fa-arrow-right me-2"></i>Proceed to Payment Details
                        </button>
                    </div>
                </div>

                <div id="spDistEmpty" class="text-center text-muted py-4 small">
                    <i class="fas fa-calculator fa-2x text-primary opacity-25 d-block mb-2"></i>
                    Enter an amount above to see how it will be distributed across outstanding fees.
                </div>

            </div>
        </div>
        <!-- /Smart Payment Card ──────────────────────────────────────────── -->

        <!-- Payment form (shown when user clicks Collect / Proceed) -->
        <div id="paymentFormCard" style="display:none;">
            <div class="card border-0 shadow-sm border-start border-success border-3 mb-3">
                <div class="card-header py-3 px-4 bg-success bg-opacity-10 d-flex align-items-center justify-content-between">
                    <span class="fw-semibold text-success"><i class="fas fa-check-circle me-2"></i>
                        Confirm &amp; Post Payment — <span id="payFormFeeLabel"></span>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="collapse" data-bs-target="#paymentFormCollapse" aria-expanded="true" aria-controls="paymentFormCollapse">
                        <i class="fas fa-chevron-down me-1"></i>Expand
                    </button>
                </div>
                <div class="card-body p-4 collapse show" id="paymentFormCollapse">
                    <form method="post" id="studentPayForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="payment_nonce" value="<?= h($form_nonce) ?>">
                        <input type="hidden" name="mode" value="student">
                        <input type="hidden" name="collection_mode" id="hCollectionMode" value="single">
                        <input type="hidden" name="fee_items" id="hFeeItems" value="">
                        <input type="hidden" name="student_id"      id="hStudentId">
                        <input type="hidden" name="package_id"      id="hPackageId">
                        <input type="hidden" name="fee_type"        id="hFeeType">
                        <input type="hidden" name="semester_fee_id" id="hSemFeeId">
                        <input type="hidden" name="semester_number" id="hSemNumber">
                        <input type="hidden" name="month_number"    id="hMonthNumber">
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
                                <?php
                                $student_default_received_id = (int)($received_into_map['cash'] ?? 0);
                                $student_default_received_label = $cash_account_labels_by_id[$student_default_received_id] ?? 'Not configured';
                                ?>
                                <input type="hidden" name="received_into_account_id" id="hStudentReceivedIntoAccountId" value="<?= (int)$student_default_received_id ?>">
                                <input type="text" class="form-control" id="studentReceivedIntoLabel" value="<?= h($student_default_received_label) ?>" readonly>
                                <div class="form-text">Auto-mapped from Accounting Settings based on payment method.</div>
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

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" id="payMethod" class="form-select" required>
                                    <option value="cash">Cash</option>
                                    <option value="bank">Bank</option>
                                    <option value="mobile_banking">Mobile Banking</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="payMobileProviderWrap" style="display:none;">
                                <label class="form-label fw-semibold">Mobile Banking Provider <span class="text-danger">*</span></label>
                                <select name="mobile_banking_provider" id="payMobileProvider" class="form-select">
                                    <option value="">— Select Provider —</option>
                                    <option value="bkash">Bkash</option>
                                    <option value="nagad">Nagad</option>
                                    <option value="rocket">Rocket</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="payTxnWrap" style="display:none;">
                                <label class="form-label fw-semibold">Transaction Number <span class="text-danger">*</span></label>
                                <input type="text" name="transaction_number" id="payTxnNumber" class="form-control"
                                       placeholder="Enter transaction number">
                            </div>
                        </div>

                        <!-- Income account (hidden, auto-selected, shown read-only) -->
                        <div class="alert alert-light border mt-3 small">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            <strong>Accounting entry:</strong>
                            <span class="text-success">Debit</span> the received-into account &amp;
                            <span class="text-danger">Credit</span> <span id="incomeAccountLabel">the income account</span> automatically.
                            Student Copy invoice email<?= $sms_enabled ? ' and payment SMS' : '' ?> will be sent to the student.
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

        <!-- Fee schedule table -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="fas fa-file-invoice-dollar me-2 text-success"></i>Fee Schedule &amp; Outstanding Balance</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 fs-6" id="totalOutstandingBadge"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#feeObligationsCollapse" aria-expanded="true" aria-controls="feeObligationsCollapse">
                        <i class="fas fa-list me-1"></i>Details
                    </button>
                </div>
            </div>
            <!-- Starts collapsed; JS auto-opens in renderFeeSummary() when outstanding fees exist -->
            <div class="card-body p-0 collapse" id="feeObligationsCollapse" data-bs-parent="#studentFeeAccordion">
                <div class="px-4 py-2 border-bottom bg-light-subtle" id="multiCollectBar" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="small text-muted">
                            Selected <strong id="multiCollectCount">0</strong> item(s), total
                            <strong id="multiCollectTotal"><?= h(acc_currency()) ?> 0.00</strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-success" id="btnCollectSelected">
                            <i class="fas fa-layer-group me-1"></i>Collect Selected Fees
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0" id="feeTable">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width:42px;">#</th>
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
                                <td></td>
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

        <!-- Transaction History -->
        <div class="card border-0 shadow-sm mb-4" id="transactionHistoryCard" style="display:none;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><i class="fas fa-history me-2 text-info"></i>Payment Transaction History</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-info-subtle text-info border border-info-subtle px-3 py-2" id="transactionCount"></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#transactionHistoryCollapse" aria-expanded="false" aria-controls="transactionHistoryCollapse">
                        <i class="fas fa-list me-1"></i>History
                    </button>
                </div>
            </div>
            <div class="card-body p-0 collapse" id="transactionHistoryCollapse" data-bs-parent="#studentFeeAccordion">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Date</th>
                                <th>Fee Type</th>
                                <th>Semester</th>
                                <th>Month</th>
                                <th>Payment Method</th>
                                <th>Txn #</th>
                                <th class="text-end">Amount</th>
                                <th>Voucher #</th>
                                <th>Invoice</th>
                                <th>Collected By</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="transactionTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        </div><!-- /studentFeeAccordion -->
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
                    <form method="post" id="generalPayForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="payment_nonce" value="<?= h($form_nonce) ?>">
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
                Use this tab to collect the <strong>admission fee, ID card &amp; form fees</strong> for applicants who have
                submitted an application form. Upon payment the applicant's status will be set to
                <strong>Admission Complete</strong>, a Student&nbsp;ID will be generated, and the student will be
                created in the Student module.
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

        <!-- Fee breakdown info -->
        <div class="alert alert-info border mb-3 small" id="admFeeBreakdown" style="display:none;">
            <i class="fas fa-receipt me-1"></i>
            <strong>Fee Breakdown:</strong>
            Admission Fee: <strong id="admFeeAdmission">—</strong> +
            ID Card &amp; Form Fee: <strong id="admFeeFormId">—</strong> =
            Total: <strong id="admFeeTotal">—</strong>
        </div>

        <!-- Already collected banner (shown when status = admission_complete) -->
        <div class="alert alert-success border d-flex align-items-center gap-2 mb-3" id="admAlreadyCollectedBanner" style="display:none;">
            <i class="fas fa-check-circle fa-2x text-success"></i>
            <div>
                <div class="fw-semibold">Admission Fee Already Collected</div>
                <div class="small">This applicant's admission fee has been collected and their status is <strong>Admission Complete</strong>.
                    Their student record has been created in the Student module.</div>
            </div>
        </div>

        <!-- Payment collection form -->
        <div id="admPayFormWrap">
        <div class="card border-0 shadow-sm border-start border-primary border-3">
            <div class="card-header py-3 px-4 bg-primary bg-opacity-10">
                <span class="fw-semibold text-primary">
                    <i class="fas fa-hand-holding-usd me-2"></i>Collect Admission, ID Card &amp; Form Fees
                </span>
            </div>
            <div class="card-body p-4">
                <form method="post" id="admPayForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="payment_nonce" value="<?= h($form_nonce) ?>">
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
                                Total Amount (<?= acc_currency() ?>) <span class="text-danger">*</span>
                            </label>
                            <input type="number" name="amount" id="admAmount" class="form-control"
                                   step="0.01" min="0.01" required>
                            <div class="form-text">
                                Total (Admission + ID/Form): <strong id="admSuggestedFee">—</strong>
                                &nbsp;|&nbsp; Already paid: <strong id="admAlreadyPaid">—</strong>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Received Into <span class="text-danger">*</span></label>
                            <?php
                            $admission_default_received_id = (int)($received_into_map['cash'] ?? 0);
                            $admission_default_received_label = $cash_account_labels_by_id[$admission_default_received_id] ?? 'Not configured';
                            ?>
                            <input type="hidden" name="received_into_account_id" id="hAdmReceivedIntoAccountId" value="<?= (int)$admission_default_received_id ?>">
                            <input type="text" class="form-control" id="admReceivedIntoLabel" value="<?= h($admission_default_received_label) ?>" readonly>
                            <div class="form-text">Auto-mapped from Accounting Settings based on payment method.</div>
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

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="admPayMethod" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="mobile_banking">Mobile Banking</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="admProviderWrap" style="display:none;">
                            <label class="form-label fw-semibold">Mobile Banking Provider <span class="text-danger">*</span></label>
                            <select name="mobile_banking_provider" id="admMobileProvider" class="form-select">
                                <option value="">— Select Provider —</option>
                                <option value="bkash">Bkash</option>
                                <option value="nagad">Nagad</option>
                                <option value="rocket">Rocket</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="admTxnWrap" style="display:none;">
                            <label class="form-label fw-semibold">Transaction Number <span class="text-danger">*</span></label>
                            <input type="text" name="transaction_number" id="admTxnNumber" class="form-control"
                                   placeholder="Enter transaction number">
                        </div>
                    </div>

                    <div class="alert alert-light border mt-3 small">
                        <i class="fas fa-info-circle text-primary me-1"></i>
                        <strong>What happens on submit:</strong>
                        <ul class="mb-0 mt-1">
                            <li><span class="text-success">Debit</span> the received-into account &amp; <span class="text-danger">Credit</span> <span id="admIncomeLabel">the Admission Fees income account</span>.</li>
                            <li>Application status set to <strong>Admission Complete</strong>.</li>
                            <li>Student ID generated &amp; student record created in the Student module.</li>
                            <li><?= h($adm_notification_note) ?></li>
                        </ul>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="fas fa-check me-1"></i> Post &amp; Complete Admission
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnAdmCancel">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        </div><!-- /admPayFormWrap -->
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
    const INVOICE_POPUP_URL = <?= json_encode(
        $invoice_popup_voucher_ids_raw !== ''
            ? APP_URL . '/accounting/fee-invoice.php?voucher_ids=' . $invoice_popup_voucher_ids_raw . '&from=collect-payment&student_sid=' . urlencode($auto_student_sid)
            : ($invoice_popup_voucher_id > 0
                ? APP_URL . '/accounting/fee-invoice.php?voucher_id=' . $invoice_popup_voucher_id . '&from=collect-payment&student_sid=' . urlencode($auto_student_sid)
                : '')
    ) ?>;
    const AUTO_STUDENT_SID = <?= json_encode($auto_student_sid) ?>;
    const RECEIVED_INTO_MAP = <?= json_encode($received_into_map) ?>;
    const CASH_ACCOUNT_LABELS = <?= json_encode($cash_account_labels_by_id) ?>;

    // Income account map injected by AJAX response
    let incomeAccountsMap = {};

    // Currently loaded student + summary
    let currentStudent = null;
    let currentSummary = null;
    let selectedFeeItems = new Map();

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

    function openAccordionSection(collapseId) {
        const target = document.getElementById(collapseId);
        if (!target) return;
        if (window.bootstrap && window.bootstrap.Collapse) {
            window.bootstrap.Collapse.getOrCreateInstance(target).show();
        } else {
            target.classList.add('show');
        }
    }

    function syncMultiCollectBar() {
        const bar = document.getElementById('multiCollectBar');
        const countEl = document.getElementById('multiCollectCount');
        const totalEl = document.getElementById('multiCollectTotal');
        const total = Array.from(selectedFeeItems.values()).reduce((sum, item) => sum + Number(item.amount || 0), 0);
        countEl.textContent = String(selectedFeeItems.size);
        totalEl.textContent = fmt(total);
        bar.style.display = selectedFeeItems.size > 0 ? '' : 'none';
    }

    function syncStudentReceivedIntoAccount() {
        const method = document.getElementById('payMethod').value;
        const accountId = Number(RECEIVED_INTO_MAP[method] || 0);
        document.getElementById('hStudentReceivedIntoAccountId').value = String(accountId);
        const label = CASH_ACCOUNT_LABELS[accountId];
        document.getElementById('studentReceivedIntoLabel').value = label || (accountId > 0 ? ('Account #' + accountId) : 'Not configured');
    }

    function syncAdmissionReceivedIntoAccount() {
        const method = document.getElementById('admPayMethod').value;
        const accountId = Number(RECEIVED_INTO_MAP[method] || 0);
        document.getElementById('hAdmReceivedIntoAccountId').value = String(accountId);
        const label = CASH_ACCOUNT_LABELS[accountId];
        document.getElementById('admReceivedIntoLabel').value = label || (accountId > 0 ? ('Account #' + accountId) : 'Not configured');
    }

    function updateStudentPaymentMethodUI() {
        const method = document.getElementById('payMethod').value;
        const providerWrap = document.getElementById('payMobileProviderWrap');
        const provider = document.getElementById('payMobileProvider');
        const txnWrap = document.getElementById('payTxnWrap');
        const txnInput = document.getElementById('payTxnNumber');
        const isMobile = method === 'mobile_banking';
        const needsTxn = method !== 'cash';

        providerWrap.style.display = isMobile ? '' : 'none';
        txnWrap.style.display = needsTxn ? '' : 'none';
        provider.required = isMobile;
        txnInput.required = needsTxn;
        if (!isMobile) provider.value = '';
        if (!needsTxn) txnInput.value = '';
        syncStudentReceivedIntoAccount();
    }

    function updateAdmissionPaymentMethodUI() {
        const method = document.getElementById('admPayMethod').value;
        const providerWrap = document.getElementById('admProviderWrap');
        const provider = document.getElementById('admMobileProvider');
        const txnWrap = document.getElementById('admTxnWrap');
        const txnInput = document.getElementById('admTxnNumber');
        const isMobile = method === 'mobile_banking';
        const needsTxn = method !== 'cash';

        providerWrap.style.display = isMobile ? '' : 'none';
        txnWrap.style.display = needsTxn ? '' : 'none';
        provider.required = isMobile;
        txnInput.required = needsTxn;
        if (!isMobile) provider.value = '';
        if (!needsTxn) txnInput.value = '';
        syncAdmissionReceivedIntoAccount();
    }

    // ════════════════════════════════════════════════════════════════════════
    // Smart Payment – Auto-Distribution Logic
    // ════════════════════════════════════════════════════════════════════════

    const spCard         = document.getElementById('smartPayCard');
    const spAmount       = document.getElementById('spAmount');
    const spDistBody     = document.getElementById('spDistBody');
    const spDistWrap     = document.getElementById('spDistWrap');
    const spDistEmpty    = document.getElementById('spDistEmpty');
    const spDistMeta     = document.getElementById('spDistMeta');
    const spNote         = document.getElementById('spNote');
    const spNoteWrap     = document.getElementById('spNoteWrap');
    const spProceedWrap  = document.getElementById('spProceedWrap');
    const spTotalOut     = document.getElementById('spTotalOut');

    const SP_MIN_AMOUNT  = 0.01;   // minimum meaningful currency unit
    const SP_FLOAT_EPS   = 0.005;  // floating-point comparison tolerance
    const SP_DEBOUNCE_MS = 120;    // ms to wait before recomputing distribution

    // Month name → number lookup (defined once at module scope)
    const MONTH_MAP = {Jan:1,Feb:2,Mar:3,Apr:4,May:5,Jun:6,Jul:7,Aug:8,Sep:9,Oct:10,Nov:11,Dec:12};

    let smartDistribution = []; // last computed allocation

    /** Parse "May 2026" → {month:5, year:2026} or null */
    function parseMonthLabel(label) {
        if (!label) return null;
        const parts = label.trim().split(' ');
        const m = MONTH_MAP[parts[0]];
        const y = parseInt(parts[1]);
        return (m && y) ? {month: m, year: y} : null;
    }

    /** Return 'past' | 'current' | 'future' | 'other' for a fee item */
    function itemPeriod(item) {
        if (!item.cal_month) return 'other';
        const now = new Date();
        const ny = now.getFullYear(), nm = now.getMonth() + 1;
        if (item.cal_year < ny || (item.cal_year === ny && item.cal_month < nm)) return 'past';
        if (item.cal_year === ny && item.cal_month === nm) return 'current';
        return 'future';
    }

    /**
     * Build an ordered list of all outstanding fee items (chronological).
     * Admission → per-semester [registration, monthly m1..mN] in semester order.
     */
    function buildOutstandingItems() {
        if (!currentSummary) return [];
        const items = [];
        const t = currentSummary.totals;
        const s = currentSummary;

        // 1. Admission (one-time, highest priority)
        if (t.admission.out > 0) {
            items.push({
                fee_type:          'admission',
                semester_fee_id:   null,
                semester_number:   null,
                month_number:      null,
                out:               t.admission.out,
                label:             'Admission Fee',
                income_account_id: incomeAccountsMap['admission'] ?? 0,
                cal_month:         null, cal_year: null,
            });
        }

        // 2. Per semester
        for (const sf of s.semesters) {
            const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
            const semN     = sf.semester_number;

            // Registration for this semester
            if (sf.reg_out > 0) {
                items.push({
                    fee_type:          'registration',
                    semester_fee_id:   sf.id,
                    semester_number:   semN,
                    month_number:      null,
                    out:               sf.reg_out,
                    label:             semLabel + ' — Registration Fee',
                    income_account_id: incomeAccountsMap['registration'] ?? 0,
                    cal_month:         null, cal_year: null,
                });
            }

            // Monthly overall fees
            for (const mr of sf.monthly_rows) {
                if (mr.out <= 0) continue;
                const parsed = parseMonthLabel(mr.month_label);
                items.push({
                    fee_type:          'semester_tuition',
                    semester_fee_id:   sf.id,
                    semester_number:   semN,
                    month_number:      mr.month_number,
                    out:               mr.out,
                    label:             semLabel + ' — Month ' + mr.month_number
                                       + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                    month_label:       mr.month_label || '',
                    income_account_id: incomeAccountsMap['semester_tuition'] ?? 0,
                    cal_month:         parsed ? parsed.month : null,
                    cal_year:          parsed ? parsed.year  : null,
                });
            }
        }
        return items;
    }

    /**
     * Distribute `amountToPay` greedily across outstanding items (oldest-first).
     * Returns array of {…item, applied, full}.
     */
    function computeDistribution(amountToPay) {
        const items = buildOutstandingItems();
        let remaining = Math.round(amountToPay * 100) / 100;
        const result  = [];

        for (const item of items) {
            if (remaining < SP_MIN_AMOUNT) break;
            const apply   = Math.round(Math.min(item.out, remaining) * 100) / 100;
            if (apply <= 0) continue;
            result.push({...item, applied: apply, full: apply >= item.out - SP_FLOAT_EPS});
            remaining = Math.round((remaining - apply) * 100) / 100;
        }
        return result;
    }

    /** Compute totals for past-due / current-month / future pills */
    function getSmartPayStats() {
        const items = buildOutstandingItems();
        let past = 0, current = 0, future = 0, total = 0;
        for (const item of items) {
            total += item.out;
            const period = itemPeriod(item);
            if (period === 'past')    past    += item.out;
            else if (period === 'current') current += item.out;
            else if (period === 'future')  future  += item.out;
            // 'other' (admission, registration) counted in total but not in period pills
        }
        return {past, current, future, total};
    }

    /** Render the distribution preview table */
    function renderSmartPreview(distribution) {
        spDistBody.innerHTML = '';
        let pastCount = 0, currentCount = 0, futureCount = 0, otherCount = 0;

        if (distribution.length === 0) {
            spDistWrap.style.display  = 'none';
            spDistEmpty.style.display = '';
            spNoteWrap.style.display  = 'none';
            spProceedWrap.style.display = 'none';
            return;
        }

        let totalApplied = 0;

        distribution.forEach((item, index) => {
            const period = itemPeriod(item);
            if (period === 'past')    pastCount++;
            else if (period === 'current') currentCount++;
            else if (period === 'future')  futureCount++;
            else otherCount++;

            totalApplied += item.applied;

            let periodBadge = '';
            if (period === 'past')
                periodBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1 small">Past Due</span>';
            else if (period === 'current')
                periodBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1 small">Current</span>';
            else if (period === 'future')
                periodBadge = '<span class="badge bg-info-subtle text-info border border-info-subtle ms-1 small">Advance</span>';

            const statusCell = item.full
                ? '<span class="text-success small"><i class="fas fa-check-circle"></i> Cleared</span>'
                : '<span class="text-warning-emphasis small"><i class="fas fa-adjust"></i> Partial</span>';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ps-3 small text-muted">${index + 1}</td>
                <td class="small">${item.label}${periodBadge}</td>
                <td class="text-end small text-muted">${fmt(item.out)}</td>
                <td class="text-end small fw-semibold text-success">${fmt(item.applied)}</td>
                <td class="text-center">${statusCell}</td>`;
            spDistBody.appendChild(tr);
        });

        // Total row
        const footTr = document.createElement('tr');
        footTr.className = 'table-light fw-bold';
        footTr.innerHTML = `
            <td class="ps-3 small" colspan="3" style="text-align:right;">Total Applied:</td>
            <td class="text-end small fw-bold text-success">${fmt(totalApplied)}</td>
            <td></td>`;
        spDistBody.appendChild(footTr);

        // Meta line (e.g. "3 items – 2 overdue & one-time fees, 1 advance")
        const parts = [];
        if (pastCount > 0)               parts.push(pastCount + ' overdue');
        if (otherCount > 0)              parts.push(otherCount + ' one-time fee' + (otherCount > 1 ? 's' : ''));
        if (currentCount > 0)            parts.push('current month');
        if (futureCount > 0)             parts.push(futureCount + ' advance month' + (futureCount > 1 ? 's' : ''));
        spDistMeta.textContent = distribution.length + ' item' + (distribution.length !== 1 ? 's' : '')
            + (parts.length ? ' — ' + parts.join(', ') : '');

        spDistWrap.style.display  = '';
        spDistEmpty.style.display = 'none';

        // Note about advance payment
        if (futureCount > 0) {
            spNote.className = 'alert alert-info border small py-2 mb-0';
            spNote.innerHTML = '<i class="fas fa-info-circle me-1"></i>'
                + '<strong>Advance payment:</strong> This amount clears all overdue fees and pre-pays '
                + futureCount + ' upcoming month' + (futureCount > 1 ? 's' : '') + '.';
            spNoteWrap.style.display = '';
        } else if (pastCount > 0 && currentCount === 0 && futureCount === 0) {
            spNote.className = 'alert alert-warning border small py-2 mb-0';
            spNote.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>'
                + 'This amount partially covers overdue fees only.';
            spNoteWrap.style.display = '';
        } else {
            spNoteWrap.style.display = 'none';
        }

        spProceedWrap.style.display = '';
    }

    /** Show/refresh the smart pay card after a student is loaded */
    function showSmartPayCard() {
        const stats = getSmartPayStats();

        spTotalOut.textContent = fmt(stats.total);

        // Past-due pill
        if (stats.past > 0) {
            document.getElementById('spPastDueAmt').textContent = fmt(stats.past);
            document.getElementById('spPastDue').style.display  = '';
        } else {
            document.getElementById('spPastDue').style.display  = 'none';
        }
        // Current-month pill
        if (stats.current > 0) {
            document.getElementById('spCurrentDueAmt').textContent = fmt(stats.current);
            document.getElementById('spCurrentDue').style.display   = '';
        } else {
            document.getElementById('spCurrentDue').style.display   = 'none';
        }
        // Future pill
        if (stats.future > 0) {
            document.getElementById('spFutureDueAmt').textContent = fmt(stats.future);
            document.getElementById('spFutureDue').style.display   = '';
        } else {
            document.getElementById('spFutureDue').style.display   = 'none';
        }

        spAmount.value = '';
        spDistBody.innerHTML = '';
        spDistWrap.style.display   = 'none';
        spDistEmpty.style.display  = '';
        spNoteWrap.style.display   = 'none';
        spProceedWrap.style.display = 'none';
        smartDistribution = [];

        if (stats.total > 0) {
            spCard.style.display = '';
        } else {
            spCard.style.display = 'none';
        }
    }

    /** Live update distribution when amount changes */
    let spTimer = null;
    spAmount.addEventListener('input', function () {
        clearTimeout(spTimer);
        spTimer = setTimeout(() => {
            const amt = parseFloat(this.value);
            if (!amt || amt <= 0) {
                smartDistribution = [];
                renderSmartPreview([]);
                return;
            }
            smartDistribution = computeDistribution(amt);
            renderSmartPreview(smartDistribution);
        }, SP_DEBOUNCE_MS);
    });

    /** "All Outstanding" quick button */
    document.getElementById('spBtnAllOut').addEventListener('click', () => {
        const stats = getSmartPayStats();
        if (stats.total <= 0) return;
        spAmount.value = Math.ceil(stats.total);
        spAmount.dispatchEvent(new Event('input'));
    });

    /** "Due Now" quick button (past + current only) */
    document.getElementById('spBtnDueNow').addEventListener('click', () => {
        const stats = getSmartPayStats();
        const due = stats.past + stats.current;
        if (due <= 0) return;
        spAmount.value = Math.ceil(due);
        spAmount.dispatchEvent(new Event('input'));
    });

    /** "Proceed to Payment Details" – populate existing multi-mode form */
    document.getElementById('spBtnProceed').addEventListener('click', () => {
        if (!smartDistribution || smartDistribution.length === 0) return;
        if (!currentStudent) return;

        const total    = smartDistribution.reduce((s, i) => s + i.applied, 0);
        const items    = smartDistribution.map(i => ({
            fee_type:          i.fee_type,
            semester_fee_id:   i.semester_fee_id || null,
            semester_number:   i.semester_number || null,
            month_number:      i.month_number    || null,
            amount:            i.applied.toFixed(2),
            label:             i.label,
            income_account_id: i.income_account_id || (incomeAccountsMap[i.fee_type] ?? 0),
        }));

        // Fill existing payment form in multi mode
        document.getElementById('hCollectionMode').value  = 'multi';
        document.getElementById('hFeeItems').value        = JSON.stringify(items);
        document.getElementById('hStudentId').value       = currentStudent.id;
        document.getElementById('hPackageId').value       = currentStudent.package_id;
        document.getElementById('hFeeType').value         = 'other';
        document.getElementById('hSemFeeId').value        = '';
        document.getElementById('hSemNumber').value       = '';
        document.getElementById('hMonthNumber').value     = '';
        document.getElementById('hIncomeAccountId').value = '';
        document.getElementById('payAmount').value        = total.toFixed(2);
        document.getElementById('payOutstanding').textContent = fmt(total);
        document.getElementById('payFormFeeLabel').textContent = 'Smart Payment (' + items.length + ' item' + (items.length !== 1 ? 's' : '') + ')';
        document.getElementById('payNarration').value =
            'Smart fee payment – ' + currentSummary.package.student_name + ' (' + currentStudent.student_id + ')';
        document.getElementById('incomeAccountLabel').textContent = 'Mapped Income Accounts';

        // Deselect any manual checkboxes
        selectedFeeItems.clear();
        document.querySelectorAll('.feeMultiChk').forEach(el => { el.checked = false; });
        syncMultiCollectBar();

        // Show and scroll to the payment confirmation form
        const payCard = document.getElementById('paymentFormCard');
        payCard.style.display = '';
        openAccordionSection('paymentFormCollapse');
        payCard.scrollIntoView({behavior: 'smooth', block: 'start'});
    });

    // ── Student search autocomplete ──────────────────────────────────────────
    const searchInput    = document.getElementById('studentSearch');
    const suggestions    = document.getElementById('studentSuggestions');
    const btnLoad        = document.getElementById('btnLoadFees');
    let   selectedSid    = null;
    function resolveStudentSidFromInput(rawValue) {
        const raw = (rawValue || '').trim();
        if (!raw) return '';
        return raw.includes(' – ') ? raw.split(' – ')[0].trim() : raw;
    }

    let searchTimer;
    searchInput.addEventListener('input', function () {
        selectedSid = null;
        btnLoad.disabled = true;
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
    searchInput.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!selectedSid) {
            selectedSid = resolveStudentSidFromInput(this.value);
        }
        if (selectedSid) {
            btnLoad.disabled = false;
            btnLoad.click();
        }
    });

    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target)) suggestions.style.display = 'none';
    });

    // ── Load fee summary ─────────────────────────────────────────────────────
    btnLoad.addEventListener('click', function () {
        if (!selectedSid) {
            selectedSid = resolveStudentSidFromInput(searchInput.value);
            if (!selectedSid) return;
        }

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
                const totals = (data.summary && data.summary.totals) ? data.summary.totals : {};
                const needsPayment = Number((totals.admission && totals.admission.out) || 0)
                    + Number((totals.registration && totals.registration.out) || 0)
                    + Number((totals.tuition && totals.tuition.out) || 0) > 0;
                if (needsPayment) {
                    openAccordionSection('feeObligationsCollapse');
                }
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

        // Add a thin section-header row to visually group rows
        function addSectionRow(label) {
            const tr = document.createElement('tr');
            tr.className = 'table-secondary';
            tr.innerHTML = `<td colspan="6" class="ps-4 py-1 small fw-semibold text-muted">
                <i class="fas fa-chevron-right me-1"></i>${label}
            </td>`;
            tbody.appendChild(tr);
        }

        // Add a fee data row (monthNumber optional – null for non-monthly fees)
        function addRow(label, due, paid, out, feeType, semFeeId, semNumber, semLabel, monthNumber, monthLabel) {
            grandDue  += due;
            grandPaid += paid;
            grandOut  += out;

            const tr  = document.createElement('tr');
            const pct = (due > 0 && out > 0) ? Math.round((out / due) * 100) : 0;
            const rowKey = [feeType, semFeeId ?? '', semNumber ?? '', monthNumber ?? ''].join('|');
            const rowData = {
                fee_type: feeType,
                semester_fee_id: semFeeId || null,
                semester_number: semNumber || null,
                month_number: monthNumber || null,
                amount: Number(out).toFixed(2),
                label,
                income_account_id: incomeAccountsMap[feeType] ?? 0,
                month_label: monthLabel || ''
            };

            tr.innerHTML = `
                <td class="text-center">
                    ${out > 0
                        ? `<input type="checkbox" class="form-check-input feeMultiChk" data-row-key="${rowKey}">`
                        : ''}
                </td>
                <td class="ps-4">
                    <div class="small">${label}</div>
                    ${due > 0 && out > 0
                        ? `<div class="progress mt-1" style="height:3px;width:100px;">
                               <div class="progress-bar bg-success" style="width:${100-pct}%"></div>
                               <div class="progress-bar bg-danger opacity-50" style="width:${pct}%"></div>
                           </div>`
                        : ''}
                </td>
                <td class="text-end small">${due > 0 ? fmt(due) : '—'}</td>
                <td class="text-end small text-success">${paid > 0 ? fmt(paid) : '—'}</td>
                <td class="text-end small fw-semibold ${out > 0 ? 'text-danger' : 'text-success'}">${out > 0 ? fmt(out) : (due > 0 ? '<i class="fas fa-check-circle"></i> Paid' : '—')}</td>
                <td class="text-center">
                    ${out > 0
                        ? `<button type="button" class="btn btn-sm btn-outline-success py-0 px-2 collectBtn"
                                data-fee-type="${feeType}"
                                data-sem-fee-id="${semFeeId ?? ''}"
                                 data-sem-number="${semNumber ?? ''}"
                                 data-sem-label="${semLabel ?? ''}"
                                 data-month-number="${monthNumber ?? ''}"
                                 data-month-label="${monthLabel ?? ''}"
                                 data-outstanding="${out}"
                                 data-income-account-id="${incomeAccountsMap[feeType] ?? ''}"
                                 data-label="${label}">
                                <i class="fas fa-hand-holding-usd me-1"></i>Collect
                            </button>`
                        : (due > 0 ? '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-check me-1"></i>Paid</span>' : '—')}
                </td>`;
            tbody.appendChild(tr);
            const chk = tr.querySelector('.feeMultiChk');
            if (chk) {
                chk.addEventListener('change', function () {
                    if (this.checked) {
                        selectedFeeItems.set(rowKey, rowData);
                    } else {
                        selectedFeeItems.delete(rowKey);
                    }
                    syncMultiCollectBar();
                });
            }
        }

        const t = s.totals;

        // ── Admission Fee ────────────────────────────────────────────────────
        addSectionRow('Admission');
        addRow('Admission Fee', t.admission.due, t.admission.paid, t.admission.out,
               'admission', null, null, null, null, null);

        // ── Per-semester: Registration + Monthly overall fees ────────────────
        s.semesters.forEach(sf => {
            const semLabel = sf.semester_label || ('Semester ' + sf.semester_number);
            addSectionRow(semLabel);

            // Registration fee for this semester
            addRow(
                semLabel + ' – Registration Fee',
                sf.reg_fee, sf.reg_paid, sf.reg_out,
                'registration', sf.id, sf.semester_number, semLabel, null, null
            );

            // Monthly overall fees (tuition + fixed + English portion / months)
            sf.monthly_rows.forEach(mr => {
                addRow(
                    semLabel + ' – Month ' + mr.month_number + (mr.month_label ? ' (' + mr.month_label + ')' : ''),
                    mr.due, mr.paid, mr.out,
                    'semester_tuition', sf.id, sf.semester_number, semLabel, mr.month_number, mr.month_label || ''
                );
            });
        });

        // Footer totals
        document.getElementById('footTotalDue').textContent  = fmt(grandDue);
        document.getElementById('footTotalPaid').textContent = fmt(grandPaid);
        document.getElementById('footTotalOut').textContent  = fmt(grandOut);

        // Outstanding badge
        document.getElementById('totalOutstandingBadge').textContent =
            'Outstanding: ' + fmt(grandOut);
        selectedFeeItems.clear();
        syncMultiCollectBar();

        // Attach collect button handlers
        tbody.querySelectorAll('.collectBtn').forEach(btn => {
            btn.addEventListener('click', () => openPayForm(btn));
        });

        // Render transaction history
        renderTransactionHistory(data.payments || []);

        // Show the Smart Payment card
        showSmartPayCard();
    }

    // ── Render transaction history ────────────────────────────────────────────
    function renderTransactionHistory(payments) {
        const card       = document.getElementById('transactionHistoryCard');
        const tbody      = document.getElementById('transactionTableBody');
        const countBadge = document.getElementById('transactionCount');

        tbody.innerHTML = '';
        countBadge.textContent = payments.length + ' transaction' + (payments.length !== 1 ? 's' : '');

        if (payments.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-3 small"><i class="fas fa-info-circle me-1"></i>No transactions recorded yet.</td></tr>';
        } else {
            payments.forEach(p => {
                const feeLabel  = feeTypeLabel(p.fee_type);
                const semText   = p.semester_number ? ('Semester ' + p.semester_number) : '—';
                const monText   = p.month_number
                    ? ('Month ' + p.month_number + (p.month_label ? ' (' + p.month_label + ')' : ''))
                    : (p.fee_type === 'semester_tuition' ? 'Lump sum' : '—');
                const statusBadge = p.voucher_status === 'posted'
                    ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Posted</span>'
                    : '<span class="badge bg-warning text-dark">' + p.voucher_status + '</span>';
                const dateStr = p.voucher_date
                    ? new Date(p.voucher_date + 'T00:00:00').toLocaleDateString('en-BD', {day: '2-digit', month: 'short', year: 'numeric'})
                    : '—';

                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4 small">${dateStr}</td>
                    <td class="small">${feeLabel}</td>
                    <td class="small">${semText}</td>
                    <td class="small">${monText}</td>
                    <td class="small">${p.payment_method_label || 'Cash'}</td>
                    <td class="small">${p.transaction_number || '—'}</td>
                    <td class="text-end small fw-semibold text-success">${fmt(p.amount)}</td>
                    <td class="small"><a href="${APP_URL}/accounting/voucher-view.php?id=${p.voucher_id}" target="_blank" rel="noopener noreferrer" class="text-decoration-none">${p.voucher_number ?? '—'}</a></td>
                    <td class="small"><a href="${APP_URL}/accounting/fee-invoice.php?voucher_id=${p.voucher_id}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-print me-1"></i>Invoice</a></td>
                    <td class="small">${p.collected_by_name}</td>
                    <td>${statusBadge}</td>`;
                tbody.appendChild(tr);
            });
        }
        card.style.display = '';
    }

    // ── Open payment form for a specific fee ─────────────────────────────────
    function openPayForm(btn) {
        const feeType   = btn.dataset.feeType;
        const semFeeId  = btn.dataset.semFeeId   || '';
        const semNumber = btn.dataset.semNumber  || '';
        const monthNum  = btn.dataset.monthNumber || '';
        const incomeAcc = btn.dataset.incomeAccountId || '';
        const out       = parseFloat(btn.dataset.outstanding);
        const label     = btn.dataset.label;

        document.getElementById('hCollectionMode').value = 'single';
        document.getElementById('hFeeItems').value = '';
        document.getElementById('hStudentId').value    = currentStudent.id;
        document.getElementById('hPackageId').value    = currentStudent.package_id;
        document.getElementById('hFeeType').value      = feeType;
        document.getElementById('hSemFeeId').value     = semFeeId;
        document.getElementById('hSemNumber').value    = semNumber;
        document.getElementById('hMonthNumber').value  = monthNum;
        document.getElementById('hIncomeAccountId').value = incomeAcc || (incomeAccountsMap[feeType] ?? '');

        document.getElementById('payAmount').value     = out.toFixed(2);
        document.getElementById('payOutstanding').textContent = fmt(out);
        document.getElementById('payFormFeeLabel').textContent = label;
        selectedFeeItems.clear();
        document.querySelectorAll('.feeMultiChk').forEach(el => { el.checked = false; });
        syncMultiCollectBar();

        // Auto-fill narration
        const pkg  = currentSummary.package;
        const narr = label + ' – ' + pkg.student_name + ' (' + currentStudent.student_id + ')';
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
        openAccordionSection('paymentFormCollapse');
        card.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    document.getElementById('btnCancelPay').addEventListener('click', () => {
        document.getElementById('paymentFormCard').style.display = 'none';
        selectedFeeItems.clear();
        document.querySelectorAll('.feeMultiChk').forEach(el => { el.checked = false; });
        syncMultiCollectBar();
    });

    document.getElementById('btnCollectSelected').addEventListener('click', () => {
        if (selectedFeeItems.size === 0) return;

        const items = Array.from(selectedFeeItems.values());
        const total = items.reduce((sum, item) => sum + Number(item.amount || 0), 0);
        document.getElementById('hCollectionMode').value = 'multi';
        document.getElementById('hFeeItems').value = JSON.stringify(items);
        document.getElementById('hStudentId').value = currentStudent.id;
        document.getElementById('hPackageId').value = currentStudent.package_id;
        document.getElementById('hFeeType').value = 'other';
        document.getElementById('hSemFeeId').value = '';
        document.getElementById('hSemNumber').value = '';
        document.getElementById('hMonthNumber').value = '';
        document.getElementById('hIncomeAccountId').value = '';
        document.getElementById('payAmount').value = total.toFixed(2);
        document.getElementById('payOutstanding').textContent = fmt(total);
        document.getElementById('payFormFeeLabel').textContent = 'Multiple Fee Items';
        document.getElementById('payNarration').value = 'Multiple fee collection – ' + currentSummary.package.student_name + ' (' + currentStudent.student_id + ')';
        document.getElementById('incomeAccountLabel').textContent = 'Mapped Income Accounts';
        document.getElementById('paymentFormCard').style.display = '';
        openAccordionSection('paymentFormCollapse');
        document.getElementById('paymentFormCard').scrollIntoView({behavior: 'smooth', block: 'start'});
    });

    document.getElementById('payMethod').addEventListener('change', updateStudentPaymentMethodUI);
    document.getElementById('admPayMethod').addEventListener('change', updateAdmissionPaymentMethodUI);
    updateStudentPaymentMethodUI();
    updateAdmissionPaymentMethodUI();

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
                    (ap.present_contact ? '   |   ' + ap.present_contact : '') +
                    (ap.present_email   ? '   |   ' + ap.present_email   : '');

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
                    sidBadge.textContent = 'Student ID will be generated';
                }

                // Hidden fields
                document.getElementById('hAdmAppId').value  = ap.id;
                document.getElementById('hAdmIncomeId').value = data.income_account_id;

                // Fee breakdown display
                const admFeeBreakdown = document.getElementById('admFeeBreakdown');
                if (data.form_id_fee > 0) {
                    document.getElementById('admFeeAdmission').textContent = fmtAdm(data.admission_fee_base);
                    document.getElementById('admFeeFormId').textContent    = fmtAdm(data.form_id_fee);
                    document.getElementById('admFeeTotal').textContent     = fmtAdm(data.suggested_fee);
                    admFeeBreakdown.style.display = '';
                } else {
                    admFeeBreakdown.style.display = 'none';
                }

                // Show "already collected" banner if fee is fully paid / status is admission_complete
                const alreadyCollectedBanner = document.getElementById('admAlreadyCollectedBanner');
                const admPayFormWrap         = document.getElementById('admPayFormWrap');
                if (ap.status === 'admission_complete' || data.already_paid >= data.suggested_fee) {
                    alreadyCollectedBanner.style.display = '';
                    admPayFormWrap.style.display         = 'none';
                } else {
                    alreadyCollectedBanner.style.display = 'none';
                    admPayFormWrap.style.display         = '';
                }

                // Fee amounts
                document.getElementById('admSuggestedFee').textContent  = fmtAdm(data.suggested_fee);
                document.getElementById('admAlreadyPaid').textContent   = fmtAdm(data.already_paid);
                document.getElementById('admAmount').value = Math.max(0, data.suggested_fee - data.already_paid).toFixed(2);

                // Income account label
                const incLbl = incomeAccLabel[data.income_account_id] || 'Admission Fees income account';
                document.getElementById('admIncomeLabel').textContent = incLbl;

                // Auto-fill narration
                document.getElementById('admNarration').value =
                    'Admission, ID Card & Form Fee – ' + ap.student_name + ' (Form #' + ap.app_number + ')';

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

    // ── Disable submit buttons on form submit (prevent double-click / double-payment) ──
    function disableSubmitOnClick(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        form.addEventListener('submit', function () {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span><span>Processing…</span>';
            }
        });
    }
    disableSubmitOnClick('studentPayForm');
    disableSubmitOnClick('generalPayForm');
    disableSubmitOnClick('admPayForm');

    // Auto-popup invoice after a successful payment, then prepare next student flow
    if (INVOICE_POPUP_URL) {
        const popup = window.open(INVOICE_POPUP_URL, 'fee_invoice_print', 'width=980,height=920');
        if (!popup) {
            alert('Invoice popup was blocked. Please allow popups and use the Print Invoice link from the success message.');
        }
    }

    if (AUTO_STUDENT_SID) {
        searchInput.value = AUTO_STUDENT_SID;
        selectedSid = AUTO_STUDENT_SID;
        btnLoad.disabled = false;
        btnLoad.click();
    } else {
        searchInput.focus();
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
