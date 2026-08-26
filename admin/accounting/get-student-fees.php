<?php
/**
 * Accounting – AJAX: Load student fee summary for the Collect Payment form.
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting', 'can_create');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php'; // sfp_get_old_erp_proofs()

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
        // Scholarship memo rows are waivers, not cash — flag them so the UI
        // can badge the rows and keep them out of the money totals.
        // STRICT match: only rows explicitly recorded as scholarship by the
        // Old ERP merge qualify — receipt/transaction number exactly
        // OLD-ERP-SCHOLARSHIP, or a note starting "SCHOLARSHIP (old ERP)".
        // A note that merely mentions the word "scholarship" somewhere (e.g.
        // an old-ERP fee-head name copied onto a normal cash payment) must
        // NOT count as scholarship.
        $txn_upper = strtoupper(trim((string)($p['transaction_number'] ?? '')));
        $note_str  = trim((string)($p['note'] ?? ''));
        $is_scholarship = ($txn_upper === 'OLD-ERP-SCHOLARSHIP')
            || (stripos($note_str, 'SCHOLARSHIP (old ERP)') === 0);
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
            'is_scholarship'    => $is_scholarship,
            'collected_by_name' => $p['collected_by_name'] ?? '—',
        ];
    }, $raw_payments);

    // Old ERP uploaded proof images attached to this student (via Student
    // Accounts → Bulk OLD ERP Proof Upload) so Collect Payment can show them.
    $old_erp_proofs = array_map(static function ($f) {
        return [
            'id'          => (int)$f['id'],
            'name'        => (string)(($f['original_name'] ?? '') !== '' ? $f['original_name'] : 'OLD ERP Proof'),
            'url'         => UPLOAD_URL . '/students/files/' . rawurlencode((string)$f['stored_name']),
            'uploaded_at' => (string)($f['created_at'] ?? ''),
        ];
    }, sfp_get_old_erp_proofs((int)$student['id']));

    echo json_encode([
        'student'         => [
            'id'         => $student['id'],
            'student_id' => $student['student_id'],
            'full_name'  => $student['full_name'],
            'package_id' => $student['package_id'],
        ],
        'semester_drop'   => (function_exists('sd_student_on_drop_now')
            ? (function () use ($student) {
                $row = sd_student_on_drop_now((int)$student['id']);
                if (!$row) { return null; }
                return [
                    'type'       => $row['semester_type'],
                    'type_label' => sd_type_label($row['semester_type']),
                    'drop_start' => $row['drop_start'],
                    'drop_end'   => $row['drop_end'],
                ];
              })()
            : null),
        'dropout'         => (function_exists('sd_active_dropout_for_student')
            ? (function () use ($student) {
                $row = sd_active_dropout_for_student((int)$student['id']);
                if (!$row) { return null; }
                return [
                    'effective_date' => $row['drop_start'],
                    'reason'         => $row['reason'] ?? null,
                ];
              })()
            : null),
        'summary'         => $summary,
        'income_accounts' => $income_accounts,
        'payments'        => $payments,
        'old_erp_proofs'  => $old_erp_proofs,
    ]);
} catch (Throwable $e) {
    error_log('get-student-fees.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A server error occurred while loading student fees. Please contact your administrator.']);
}
