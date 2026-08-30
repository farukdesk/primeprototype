<?php
/**
 * Student Accounts – Save OLD ERP Payable Amount (AJAX backend)
 *
 * Persists the "Payable Amount" read from the OLD ERP proof screenshot,
 * either automatically (client-side OCR on view.php) or manually.
 * The stored value drives the cross-check badge on view.php and the
 * mismatch highlighting on index.php.
 *
 * Also persists the "Registration Fee" row read from the proof's
 * transaction history (Head of A/C: Registration Fee → Payable Amount /
 * Received Amount). The RECEIVED amount drives the Old ERP Totals Merge:
 * only the actually-paid registration is marked paid there — the rest of
 * the registration fees stay as dues.
 */

// Buffer all output so PHP warnings cannot corrupt the JSON response.
ob_start();
ini_set('display_errors', '0');

require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=UTF-8');

function sep_json(array $data): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sep_json(['success' => false, 'error' => 'POST required.']);
}

csrf_check();

$package_id = (int)($_POST['package_id'] ?? 0);
$source     = (($_POST['source'] ?? 'ocr') === 'manual') ? 'manual' : 'ocr';

// Payable Amount: only touched when the 'amount' field is present in the
// request, so a monthly-only save cannot clear a stored payable value.
$has_amount = array_key_exists('amount', $_POST);
$raw        = trim((string)($_POST['amount'] ?? ''));
$amount     = ($raw === '') ? null : (float)str_replace(',', '', $raw);

// Optional: Monthly Payment read from the proof. Only saved when the
// 'monthly' field is present in the request; otherwise left untouched.
$has_monthly = array_key_exists('monthly', $_POST);
$monthly_raw = trim((string)($_POST['monthly'] ?? ''));
$monthly     = ($monthly_raw === '') ? null : (float)str_replace(',', '', $monthly_raw);

// Optional: Registration Fee row (transaction history) — Payable / Received.
// Only saved when the fields are present in the request.
$has_reg_received = array_key_exists('reg_received', $_POST);
$reg_received_raw = trim((string)($_POST['reg_received'] ?? ''));
$reg_received     = ($reg_received_raw === '') ? null : (float)str_replace(',', '', $reg_received_raw);

$has_reg_payable = array_key_exists('reg_payable', $_POST);
$reg_payable_raw = trim((string)($_POST['reg_payable'] ?? ''));
$reg_payable     = ($reg_payable_raw === '') ? null : (float)str_replace(',', '', $reg_payable_raw);

if ($package_id <= 0) {
    sep_json(['success' => false, 'error' => 'Invalid package.']);
}
if (!$has_amount && !$has_monthly && !$has_reg_received && !$has_reg_payable) {
    sep_json(['success' => false, 'error' => 'Nothing to save.']);
}
if ($amount !== null && ($amount < 0 || $amount > 99999999)) {
    sep_json(['success' => false, 'error' => 'Invalid amount.']);
}
if ($has_monthly && $monthly !== null && ($monthly < 0 || $monthly > 9999999)) {
    sep_json(['success' => false, 'error' => 'Invalid monthly amount.']);
}
if ($has_reg_received && $reg_received !== null && ($reg_received < 0 || $reg_received > 99999999)) {
    sep_json(['success' => false, 'error' => 'Invalid registration received amount.']);
}
if ($has_reg_payable && $reg_payable !== null && ($reg_payable < 0 || $reg_payable > 99999999)) {
    sep_json(['success' => false, 'error' => 'Invalid registration payable amount.']);
}
// Manual entry / clearing is an explicit edit; OCR auto-save only needs view access.
if ($source === 'manual' && !sfp_can_edit()) {
    sep_json(['success' => false, 'error' => 'You do not have permission to edit this value.']);
}

// Make sure the Registration Fee (proof) columns exist before reading/writing.
sfp_ensure_old_erp_reg_columns();

$pkg = sfp_get_package($package_id);
if (!$pkg) {
    sep_json(['success' => false, 'error' => 'Student account not found.']);
}

// An OCR auto-save must never overwrite a manually entered payable value.
// The Monthly Payment (if sent) is still saved in that case.
$payable_skipped = ($has_amount
    && $source === 'ocr'
    && ($pkg['old_erp_payable_source'] ?? null) === 'manual');

// Same protection for the Registration Fee reading: OCR never overwrites
// a manually entered registration value.
$reg_skipped = (($has_reg_received || $has_reg_payable)
    && $source === 'ocr'
    && ($pkg['old_erp_reg_source'] ?? null) === 'manual');

$old_value        = $pkg['old_erp_payable_amount'] ?? null;
$old_monthly      = $pkg['old_erp_monthly_amount'] ?? null;
$old_reg_received = $pkg['old_erp_reg_received_amount'] ?? null;
$old_reg_payable  = $pkg['old_erp_reg_payable_amount'] ?? null;

$sets = [];
$vals = [];
if ($has_amount && !$payable_skipped) {
    $sets[] = 'old_erp_payable_amount = ?';
    $vals[] = $amount;
    $sets[] = 'old_erp_payable_source = ?';
    $vals[] = $amount === null ? null : $source;
}
if ($has_monthly) {
    $sets[] = 'old_erp_monthly_amount = ?';
    $vals[] = $monthly;
}
if (!$reg_skipped) {
    if ($has_reg_received) {
        $sets[] = 'old_erp_reg_received_amount = ?';
        $vals[] = $reg_received;
        $sets[] = 'old_erp_reg_source = ?';
        $vals[] = $reg_received === null ? null : $source;
    }
    if ($has_reg_payable) {
        $sets[] = 'old_erp_reg_payable_amount = ?';
        $vals[] = $reg_payable;
    }
}

if (!empty($sets)) {
    $sets[] = 'old_erp_checked_at = NOW()';
    $vals[] = $package_id;
    db()->prepare(
        'UPDATE sfp_packages SET ' . implode(', ', $sets) . ' WHERE id = ?'
    )->execute($vals);
}

if ($has_amount && !$payable_skipped) {
    log_change(
        'student-accounts', 'UPDATE', $package_id,
        (string)($pkg['student_name'] ?? ('Package #' . $package_id)),
        'old_erp_payable_amount',
        $old_value === null ? null : (string)$old_value,
        $amount === null ? null : (string)$amount,
        'OLD ERP Payable Amount ' . ($amount === null
            ? 'cleared'
            : 'set to ' . number_format($amount, 2) . ' BDT (' . $source . ')')
    );
}
if ($has_monthly) {
    log_change(
        'student-accounts', 'UPDATE', $package_id,
        (string)($pkg['student_name'] ?? ('Package #' . $package_id)),
        'old_erp_monthly_amount',
        $old_monthly === null ? null : (string)$old_monthly,
        $monthly === null ? null : (string)$monthly,
        'OLD ERP Monthly Payment ' . ($monthly === null
            ? 'cleared'
            : 'set to ' . number_format($monthly, 2) . ' BDT (' . $source . ')')
    );
}
if ($has_reg_received && !$reg_skipped) {
    log_change(
        'student-accounts', 'UPDATE', $package_id,
        (string)($pkg['student_name'] ?? ('Package #' . $package_id)),
        'old_erp_reg_received_amount',
        $old_reg_received === null ? null : (string)$old_reg_received,
        $reg_received === null ? null : (string)$reg_received,
        'OLD ERP Registration Fee Received Amount (proof transaction history) ' . ($reg_received === null
            ? 'cleared'
            : 'set to ' . number_format($reg_received, 2) . ' BDT (' . $source . ')')
    );
}
if ($has_reg_payable && !$reg_skipped) {
    log_change(
        'student-accounts', 'UPDATE', $package_id,
        (string)($pkg['student_name'] ?? ('Package #' . $package_id)),
        'old_erp_reg_payable_amount',
        $old_reg_payable === null ? null : (string)$old_reg_payable,
        $reg_payable === null ? null : (string)$reg_payable,
        'OLD ERP Registration Fee Payable Amount (proof transaction history) ' . ($reg_payable === null
            ? 'cleared'
            : 'set to ' . number_format($reg_payable, 2) . ' BDT (' . $source . ')')
    );
}

if ($payable_skipped) {
    sep_json([
        'success'      => true,
        'skipped'      => true,
        'amount'       => (float)$pkg['old_erp_payable_amount'],
        'monthly'      => $has_monthly ? $monthly : $old_monthly,
        'reg_received' => ($has_reg_received && !$reg_skipped) ? $reg_received : $old_reg_received,
        'reg_payable'  => ($has_reg_payable && !$reg_skipped) ? $reg_payable : $old_reg_payable,
        'reg_skipped'  => $reg_skipped,
    ]);
}

sep_json([
    'success'      => true,
    'amount'       => $has_amount ? $amount : $old_value,
    'source'       => $source,
    'monthly'      => $has_monthly ? $monthly : $old_monthly,
    'reg_received' => ($has_reg_received && !$reg_skipped) ? $reg_received : $old_reg_received,
    'reg_payable'  => ($has_reg_payable && !$reg_skipped) ? $reg_payable : $old_reg_payable,
    'reg_skipped'  => $reg_skipped,
]);
