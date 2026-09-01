<?php
/**
 * Student Portal API – GET /api/student/finances.php
 * =====================================================
 * Returns the student's fee schedule, outstanding balance, and payment history.
 *
 * Delegates to the existing accounting helpers used by the web portal.
 *
 * Success response:
 *   { "ok": true, "student": {...}, "summary": {...}, "schedule": [...], "payments": [...] }
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
        'schedule' => [],
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
        'schedule' => [],
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
        'fee_type'       => function_exists('acc_fee_type_label')
            ? acc_fee_type_label((string)$p['fee_type'])
            : $p['fee_type'],
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

// ── Fee Schedule & Outstanding Balance breakdown ─────────────────────────────
// Mirrors the web collect-payment.php "Fee Schedule & Outstanding Balance" table:
// Admission-day fees, then per-semester Registration + monthly obligations, and
// finally any additional / examination fees. Grand totals are accumulated so the
// mobile summary reflects the true programme totals (not a hard-coded "Cleared").
$schedule   = [];
$grand_due  = 0.0;
$grand_paid = 0.0;
$grand_out  = 0.0;

$make_row = function (string $label, float $due, float $paid, float $out)
        use (&$grand_due, &$grand_paid, &$grand_out): array {
    $grand_due  += $due;
    $grand_paid += $paid;
    $grand_out  += $out;
    return [
        'label' => $label,
        'due'   => round($due, 2),
        'paid'  => round($paid, 2),
        'out'   => round($out, 2),
    ];
};

$totals = $summary['totals'] ?? [];

// Admission-day one-time fees (Admission Fee, Form Fee, ID Card Fee)
$admission_rows = [];
foreach ([
    'admission'   => 'Admission Fee',
    'form_fee'    => 'Form Fee',
    'id_card_fee' => 'ID Card Fee',
] as $key => $label) {
    $head = $totals[$key] ?? null;
    if (!$head) continue;
    $due  = (float)($head['due']  ?? 0);
    $paid = (float)($head['paid'] ?? 0);
    if ($due <= 0 && $paid <= 0) continue;
    $admission_rows[] = $make_row($label, $due, $paid, (float)($head['out'] ?? max(0.0, $due - $paid)));
}
if ($admission_rows) {
    $schedule[] = ['title' => 'Admission', 'rows' => $admission_rows];
}

// Per-semester: Registration fee + monthly overall fees (tuition + fixed + English)
foreach ($summary['semesters'] ?? [] as $sf) {
    $sem_label = $sf['semester_label'] ?? ('Semester ' . ($sf['semester_number'] ?? ''));
    $rows = [];

    $reg_fee = (float)($sf['reg_fee'] ?? 0);
    if ($reg_fee > 0) {
        $rows[] = $make_row(
            'Registration Fee',
            $reg_fee,
            (float)($sf['reg_paid'] ?? 0),
            (float)($sf['reg_out'] ?? 0)
        );
    }

    foreach (($sf['monthly_rows'] ?? []) as $mr) {
        $month_label = 'Month ' . ($mr['month_number'] ?? '');
        if (!empty($mr['month_label'])) {
            $month_label .= ' (' . $mr['month_label'] . ')';
        }
        $rows[] = $make_row(
            $month_label,
            (float)($mr['due']  ?? 0),
            (float)($mr['paid'] ?? 0),
            (float)($mr['out']  ?? 0)
        );
    }

    if ($rows) {
        $schedule[] = ['title' => $sem_label, 'rows' => $rows];
    }
}

// Additional / examination fees (variable amount, no due – only paid matters)
$additional_rows = [];
foreach (($summary['additional']['items'] ?? []) as $it) {
    $paid = (float)($it['paid'] ?? 0);
    if ($paid <= 0) continue;
    $additional_rows[] = $make_row((string)($it['label'] ?? 'Additional Fee'), 0.0, $paid, 0.0);
}
if ($additional_rows) {
    $schedule[] = ['title' => 'Additional / Examination Fees', 'rows' => $additional_rows];
}

// Outstanding that has actually fallen due as of today (obligations up to the
// current calendar month). Future months are excluded.
$due_as_of_today = null;
try {
    $due_as_of_today = acc_outstanding_through_current_month((int)$rec['package_id']);
} catch (Throwable $e) {}

sp_api_ok([
    'student' => [
        'id'         => (int)$rec['id'],
        'student_id' => $rec['student_id'],
        'full_name'  => $rec['full_name'],
        'status'     => $rec['status'],
    ],
    'summary' => [
        'total_due'       => round($grand_due, 2),
        'total_paid'      => round($grand_paid, 2),
        'outstanding'     => round($grand_out, 2),
        // Balance due right now (obligations up to the current month, future
        // installments excluded).
        'due_as_of_today' => $due_as_of_today !== null ? round($due_as_of_today, 2) : round($grand_out, 2),
        'as_of_date'      => date('d M Y'),
        // Dues count from the 1st of the month; the 10th is the payment deadline.
        'payment_deadline_day' => defined('ACC_MONTHLY_LAST_PAYMENT_DAY') ? ACC_MONTHLY_LAST_PAYMENT_DAY : 10,
        'payment_deadline'     => 'Last date of payment: 10th of every month',
    ],
    'schedule' => $schedule,
    'payments' => $payments,
]);
