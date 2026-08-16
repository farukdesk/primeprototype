<?php
/**
 * Student Accounts Portal – submit an online payment for review.
 *
 * The student pays through a bank deposit / transfer or mobile banking, then
 * submits the payment details here together with a receipt / screenshot.
 * The claim is stored as PENDING in acc_online_payments; accounts staff
 * review it from Accounting → Online Payments. Verification is normally
 * completed within 24 hours (occasionally up to 48 hours).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/payment-methods-helpers.php';

// Accessible to student portal users (Students → My Finances) and to users of
// the student-accounts-portal module (Accounting → Accounts page).
$is_portal = function_exists('is_portal_student') && is_portal_student();
if (!$is_portal && !can_access('student-accounts-portal')) {
    flash_set('error', 'You do not have permission to access this section.');
    redirect(APP_URL . '/index.php');
}

$portal_url = $is_portal
    ? APP_URL . '/students/my-finances.php'
    : APP_URL . '/accounting/student-portal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($portal_url, 303);
}
csrf_check();
opm_ensure_tables();

// ── Resolve the student: portal-linked account first, then Student ID ──────
$user    = auth_user();
$student = null;
if ($user) {
    $stmt = db()->prepare('SELECT id, student_id, full_name FROM students WHERE portal_user_id = ? LIMIT 1');
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $student = $stmt->fetch() ?: null;
}
if (!$student) {
    $sid = trim((string)($user['student_sid'] ?? ''));
    if ($sid !== '') {
        $student = acc_get_student_by_sid($sid) ?: null;
    }
}
if (!$student) {
    flash_set('danger', 'Your login is not linked to a student record. Please contact the Accounts Office.');
    redirect($portal_url, 303);
}

// ── Validate the submission ────────────────────────────────────────────
$method_id = (int)($_POST['method_id'] ?? 0);
$paid_from = trim((string)($_POST['paid_from'] ?? ''));
$payer_bank_name      = trim((string)($_POST['payer_bank_name'] ?? ''));
$payer_account_name   = trim((string)($_POST['payer_account_name'] ?? ''));
$payer_account_number = trim((string)($_POST['payer_account_number'] ?? ''));
$amount    = (float)($_POST['amount'] ?? 0);
$paid_date = trim((string)($_POST['paid_date'] ?? ''));
$paid_time = trim((string)($_POST['paid_time'] ?? ''));
$txn       = trim((string)($_POST['transaction_number'] ?? ''));

$errors = [];
$method = $method_id > 0 ? opm_get_method($method_id) : null;
if (!$method || (int)$method['is_active'] !== 1) {
    $errors[] = 'Please select a valid payment method.';
}
$method_type = $method ? (string)$method['method_type'] : '';
if ($method_type === 'bank') {
    // Bank transfers: the payer's own bank details are collected as three
    // structured fields (Bank Name, Account Name, Account Number).
    if ($payer_bank_name === '')      { $errors[] = 'Please enter the bank name you paid from.'; }
    if ($payer_account_name === '')   { $errors[] = 'Please enter the account name you paid from.'; }
    if ($payer_account_number === '') { $errors[] = 'Please enter the account number you paid from.'; }
    $paid_from = 'Bank: ' . $payer_bank_name . ' | A/C Name: ' . $payer_account_name . ' | A/C No: ' . $payer_account_number;
} else {
    $payer_bank_name = $payer_account_name = $payer_account_number = '';
    if ($paid_from === '') {
        $errors[] = 'Please enter the wallet name (or number) you paid from.';
    }
}
if ($amount <= 0) {
    $errors[] = 'Please enter the amount you paid.';
}
$dt = $paid_date !== '' ? DateTime::createFromFormat('Y-m-d', $paid_date) : false;
if (!($dt instanceof DateTime) || $dt->format('Y-m-d') !== $paid_date) {
    $errors[] = 'Please select the payment date.';
} elseif ($paid_date > date('Y-m-d')) {
    $errors[] = 'The payment date cannot be in the future.';
}
if ($paid_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $paid_time)) {
    $paid_time = ''; // optional field — drop an unparseable value silently
}
if ($txn === '') {
    $errors[] = 'The transaction / reference number is required.';
}

// Duplicate guards: the same transaction number must not be pending/approved
// already, nor already recorded as a payment in the books.
if ($txn !== '' && !$errors) {
    $dup = db()->prepare(
        "SELECT COUNT(*) FROM acc_online_payments WHERE transaction_number = ? AND status <> 'rejected'"
    );
    $dup->execute([$txn]);
    if ((int)$dup->fetchColumn() > 0) {
        $errors[] = 'This transaction number has already been submitted and is pending or approved. If you believe this is a mistake, please contact the Accounts Office.';
    } elseif (function_exists('acc_transaction_number_exists') && acc_transaction_number_exists($txn)) {
        $errors[] = 'This transaction number is already recorded in your payment history.';
    }
}

// ── Receipt / screenshot upload (required) ──────────────────────────────
$receipt_file = null;
if (!isset($_FILES['receipt']) || ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errors[] = 'Please upload the payment receipt / screenshot.';
} elseif (!$errors) {
    $size = (int)($_FILES['receipt']['size'] ?? 0);
    $ext  = strtolower(pathinfo((string)$_FILES['receipt']['name'], PATHINFO_EXTENSION));
    if ($size <= 0 || $size > OPM_MAX_UPLOAD_BYTES) {
        $errors[] = 'The receipt file is too large (max 5 MB).';
    } elseif (!in_array($ext, OPM_ALLOWED_EXTENSIONS, true)) {
        $errors[] = 'The receipt must be a JPG, PNG or WEBP image, or a PDF.';
    } else {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file((string)$_FILES['receipt']['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            $errors[] = 'The uploaded receipt file type could not be verified. Please upload a JPG, PNG, WEBP or PDF file.';
        } else {
            $receipt_file = 'opm-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file((string)$_FILES['receipt']['tmp_name'], opm_upload_dir() . '/' . $receipt_file)) {
                $errors[]     = 'The receipt could not be saved. Please try again.';
                $receipt_file = null;
            }
        }
    }
}

if ($errors) {
    flash_set('danger', implode(' ', $errors));
    redirect($portal_url, 303);
}

db()->prepare(
    'INSERT INTO acc_online_payments
        (student_id, method_id, method_type, amount, paid_from, payer_bank_name, payer_account_name, payer_account_number, paid_date, paid_time, transaction_number, receipt_file)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    (int)$student['id'],
    (int)$method['id'],
    (string)$method['method_type'],
    round($amount, 2),
    $paid_from,
    $payer_bank_name !== '' ? $payer_bank_name : null,
    $payer_account_name !== '' ? $payer_account_name : null,
    $payer_account_number !== '' ? $payer_account_number : null,
    $paid_date,
    $paid_time !== '' ? $paid_time : null,
    $txn,
    $receipt_file,
]);

flash_set(
    'success',
    'Your payment details were submitted for verification. Verification is normally completed within 24 hours, '
    . 'but occasionally it may take up to 48 hours. You can track the status under “My Online Payment Submissions” below.'
);
redirect($portal_url, 303);
