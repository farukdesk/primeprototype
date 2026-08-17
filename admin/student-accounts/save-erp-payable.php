<?php
/**
 * Student Accounts – Save OLD ERP Payable Amount (AJAX backend)
 *
 * Persists the "Payable Amount" read from the OLD ERP proof screenshot,
 * either automatically (client-side OCR on view.php) or manually.
 * The stored value drives the cross-check badge on view.php and the
 * mismatch highlighting on index.php.
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
$raw        = trim((string)($_POST['amount'] ?? ''));
$amount     = ($raw === '') ? null : (float)str_replace(',', '', $raw);

if ($package_id <= 0) {
    sep_json(['success' => false, 'error' => 'Invalid package.']);
}
if ($amount !== null && ($amount < 0 || $amount > 99999999)) {
    sep_json(['success' => false, 'error' => 'Invalid amount.']);
}
// Manual entry / clearing is an explicit edit; OCR auto-save only needs view access.
if ($source === 'manual' && !sfp_can_edit()) {
    sep_json(['success' => false, 'error' => 'You do not have permission to edit this value.']);
}

$pkg = sfp_get_package($package_id);
if (!$pkg) {
    sep_json(['success' => false, 'error' => 'Student account not found.']);
}

// An OCR auto-save must never overwrite a manually entered value.
if ($source === 'ocr' && ($pkg['old_erp_payable_source'] ?? null) === 'manual') {
    sep_json([
        'success' => true,
        'skipped' => true,
        'amount'  => (float)$pkg['old_erp_payable_amount'],
    ]);
}

$old_value = $pkg['old_erp_payable_amount'] ?? null;

db()->prepare(
    'UPDATE sfp_packages
        SET old_erp_payable_amount = ?,
            old_erp_payable_source = ?,
            old_erp_checked_at     = NOW()
      WHERE id = ?'
)->execute([
    $amount,
    $amount === null ? null : $source,
    $package_id,
]);

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

sep_json(['success' => true, 'amount' => $amount, 'source' => $source]);
