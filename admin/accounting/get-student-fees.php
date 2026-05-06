<?php
/**
 * Accounting – AJAX: Load student fee summary for the Collect Payment form.
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

$student_sid = trim($_GET['student_sid'] ?? '');
if ($student_sid === '') {
    echo json_encode(['error' => 'Student ID is required.']);
    exit;
}

$student = acc_get_student_by_sid($student_sid);
if (!$student) {
    echo json_encode(['error' => 'Student not found.']);
    exit;
}

if (!$student['package_id']) {
    echo json_encode(['error' => 'This student does not have a fee package assigned yet. Please assign one from Student Accounts first.']);
    exit;
}

$summary = acc_student_fee_summary((int)$student['id']);
if (!$summary) {
    echo json_encode(['error' => 'Could not load fee summary.']);
    exit;
}

// Retrieve income account IDs for each fee type (used by JS to auto-select)
$income_accounts = [
    'admission'        => acc_income_account_id_by_code('4200'), // Admission Fees
    'registration'     => acc_income_account_id_by_code('4100'), // Tuition Fees (reg)
    'semester_tuition' => acc_income_account_id_by_code('4100'), // Tuition Fees
    'fixed_fee'        => acc_income_account_id_by_code('4100'), // Tuition Fees
    'english_fee'      => acc_income_account_id_by_code('4100'), // Tuition Fees
    'other'            => acc_income_account_id_by_code('4700'), // Miscellaneous Income
];

// Payment transaction history for this student
$raw_payments = acc_get_student_payments((int)$student['package_id']);
$payments = array_map(function ($p) {
    return [
        'id'                => (int)$p['id'],
        'voucher_id'        => (int)$p['voucher_id'],
        'collected_at'      => $p['collected_at'],
        'voucher_date'      => $p['voucher_date'],
        'voucher_number'    => $p['voucher_number'],
        'voucher_status'    => $p['voucher_status'],
        'fee_type'          => $p['fee_type'],
        'semester_number'   => $p['semester_number'] ?? null,
        'month_number'      => $p['month_number']    ?? null,
        'amount'            => (float)$p['amount'],
        'note'              => $p['note'] ?? '',
        'collected_by_name' => $p['collected_by_name'] ?? '—',
    ];
}, $raw_payments);

echo json_encode([
    'student'         => [
        'id'         => $student['id'],
        'student_id' => $student['student_id'],
        'full_name'  => $student['full_name'],
        'package_id' => $student['package_id'],
    ],
    'summary'         => $summary,
    'income_accounts' => $income_accounts,
    'payments'        => $payments,
]);
