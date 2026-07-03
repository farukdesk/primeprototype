<?php
/**
 * Student Portal API – GET /api/student/finances.php
 * =====================================================
 * Returns the student's fee schedule, outstanding balance, and payment history.
 *
 * Delegates to the existing accounting helpers used by the web portal.
 *
 * Success response:
 *   { "ok": true, "student": {...}, "summary": {...}, "payments": [...] }
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];

try {
    require_once dirname(__DIR__, 2) . '/accounting/helpers.php';
} catch (Throwable $e) {
    sp_api_error(503, 'Accounting module is not available.');
}

// Look up student + fee package
$stmt = db()->prepare(
    'SELECT s.id, s.student_id, s.full_name, s.status,
            p.id AS package_id
     FROM students s
     LEFT JOIN sfp_packages p ON p.student_id = s.id
     WHERE s.id = ?
     LIMIT 1'
);
$stmt->execute([(int)$student['student_db_id']]);
$rec = $stmt->fetch();

if (!$rec || !$rec['package_id']) {
    sp_api_ok([
        'student'  => [
            'id'         => (int)$student['student_db_id'],
            'student_id' => $student['student_id'],
            'full_name'  => $student['student_name'],
        ],
        'summary'  => null,
        'payments' => [],
        'message'  => 'No fee package has been assigned to your account yet. Please contact the Accounts Office.',
    ]);
    return;
}

try {
    $summary = acc_student_fee_summary((int)$rec['id']);
} catch (Throwable $e) {
    sp_api_error(500, 'Could not load fee summary. Please try again.');
}

if (!$summary) {
    sp_api_ok([
        'student'  => [
            'id'         => (int)$rec['id'],
            'student_id' => $rec['student_id'],
            'full_name'  => $rec['full_name'],
        ],
        'summary'  => null,
        'payments' => [],
        'message'  => 'Fee summary is not available. Please contact the Accounts Office.',
    ]);
    return;
}

// Build month-label map for payment history display
$month_labels_map = [];
foreach ($summary['semesters'] ?? [] as $sem) {
    foreach (($sem['monthly_rows'] ?? []) as $mr) {
        $month_labels_map[(int)$sem['semester_number'] . ':' . (int)$mr['month_number']] = $mr['month_label'] ?? '';
    }
}

// Payment history
$raw_payments = [];
try {
    $raw_payments = acc_get_student_payments((int)$rec['package_id']);
} catch (Throwable $e) {}

$payments = array_map(function ($p) use ($month_labels_map) {
    return [
        'id'             => (int)$p['id'],
        'voucher_number' => $p['voucher_number'],
        'date'           => $p['voucher_date'] ?? $p['collected_at'] ?? null,
        'fee_type'       => $p['fee_type'],
        'semester'       => $p['semester_number'] ?? null,
        'month_label'    => (!empty($p['semester_number']) && !empty($p['month_number']))
            ? ($month_labels_map[(int)$p['semester_number'] . ':' . (int)$p['month_number']] ?? '')
            : '',
        'amount'         => (float)$p['amount'],
        'method'         => acc_payment_method_label(
            (string)($p['payment_method'] ?? 'cash'),
            $p['mobile_banking_provider'] ?? null
        ),
        'status'         => $p['voucher_status'] ?? 'paid',
    ];
}, $raw_payments);

// Compact summary for the mobile dashboard
$total_due  = 0;
$total_paid = 0;
$semesters  = [];
foreach ($summary['semesters'] ?? [] as $sem) {
    $total_due  += (float)($sem['total_due']  ?? 0);
    $total_paid += (float)($sem['total_paid'] ?? 0);
    $semesters[] = [
        'label'         => $sem['semester_label'] ?? 'Semester ' . $sem['semester_number'],
        'total_due'     => (float)($sem['total_due']  ?? 0),
        'total_paid'    => (float)($sem['total_paid'] ?? 0),
        'outstanding'   => round((float)($sem['total_due'] ?? 0) - (float)($sem['total_paid'] ?? 0), 2),
    ];
}

sp_api_ok([
    'student' => [
        'id'         => (int)$rec['id'],
        'student_id' => $rec['student_id'],
        'full_name'  => $rec['full_name'],
        'status'     => $rec['status'],
    ],
    'summary' => [
        'total_due'   => round($total_due, 2),
        'total_paid'  => round($total_paid, 2),
        'outstanding' => round($total_due - $total_paid, 2),
        'semesters'   => $semesters,
    ],
    'payments' => $payments,
]);
