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

echo json_encode([
    'student'         => [
        'id'         => $student['id'],
        'student_id' => $student['student_id'],
        'full_name'  => $student['full_name'],
        'package_id' => $student['package_id'],
    ],
    'summary'         => $summary,
    'income_accounts' => $income_accounts,
]);
