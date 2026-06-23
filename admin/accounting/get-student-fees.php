<?php
/**
 * Accounting – AJAX: Load student fee summary for the Collect Payment form.
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json');

try {
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

    // Full calendar-month names so the transaction history can show the real
    // month (e.g. "January 2026") next to the sequential "Month N" label.
    $full_month_names = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];
    $month_labels_map = [];
    foreach ($summary['semesters'] as $sem) {
        foreach (($sem['monthly_rows'] ?? []) as $mr) {
            $cal_month = (int)($mr['cal_month'] ?? 0);
            $cal_year  = (int)($mr['cal_year'] ?? 0);
            $label = ($cal_month >= 1 && $cal_month <= 12 && $cal_year > 0)
                ? $full_month_names[$cal_month] . ' ' . $cal_year
                : ($mr['month_label'] ?? '');
            $month_labels_map[(int)$sem['semester_number'] . ':' . (int)$mr['month_number']] = $label;
        }
    }

    // Retrieve configured income-account mappings for each fee type
    $income_accounts = acc_income_account_map_for_fee_types();

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
} catch (Throwable $e) {
    error_log('get-student-fees.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A server error occurred while loading student fees. Please contact your administrator.']);
}
