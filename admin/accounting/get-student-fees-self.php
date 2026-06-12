<?php
/**
 * Accounting – AJAX: Load fee summary for the currently logged-in student.
 * Requires the user's account to have student_sid set.
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts-portal');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

try {
    $user = auth_user();
    $student_sid = trim((string)($user['student_sid'] ?? ''));

    if ($student_sid === '') {
        echo json_encode(['error' => 'Your account is not linked to a student record. Please contact the accounts office.']);
        exit;
    }

    $student = acc_get_student_by_sid($student_sid);
    if (!$student) {
        echo json_encode(['error' => 'Student record not found. Please contact the accounts office.']);
        exit;
    }

    if (!$student['package_id']) {
        echo json_encode(['error' => 'A fee package has not been assigned to your account yet. Please contact the accounts office.']);
        exit;
    }

    $summary = acc_student_fee_summary((int)$student['id']);
    if (!$summary) {
        echo json_encode(['error' => 'Could not load fee summary. Please try again or contact the accounts office.']);
        exit;
    }

    $month_labels_map = [];
    foreach ($summary['semesters'] as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $month_labels_map[(int)$sem['semester_number'] . ':' . (int)$mr['month_number']] = $mr['month_label'] ?? '';
        }
    }

    // Payment transaction history for this student
    $raw_payments = acc_get_student_payments((int)$student['package_id']);
    $payments = array_map(function ($p) use ($month_labels_map) {
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
            'month_label'       => (!empty($p['semester_number']) && !empty($p['month_number']))
                ? ($month_labels_map[(int)$p['semester_number'] . ':' . (int)$p['month_number']] ?? '')
                : '',
            'payment_method'    => $p['payment_method'] ?? 'cash',
            'mobile_banking_provider' => $p['mobile_banking_provider'] ?? null,
            'transaction_number' => $p['transaction_number'] ?? null,
            'payment_method_label' => acc_payment_method_label(
                (string)($p['payment_method'] ?? 'cash'),
                $p['mobile_banking_provider'] ?? null
            ),
            'amount'            => (float)$p['amount'],
        ];
    }, $raw_payments);

    echo json_encode([
        'student' => [
            'id'         => $student['id'],
            'student_id' => $student['student_id'],
            'full_name'  => $student['full_name'],
            'package_id' => $student['package_id'],
        ],
        'summary'  => $summary,
        'payments' => $payments,
    ]);
} catch (Throwable $e) {
    error_log('get-student-fees-self.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A server error occurred. Please try again or contact the accounts office.']);
}
