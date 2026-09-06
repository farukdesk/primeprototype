<?php
/**
 * Accounting – AJAX: Load fee summary for the currently logged-in student portal user.
 * Requires the user to be a student portal user (linked via students.portal_user_id).
 * Returns JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php'; // sfp_get_old_erp_proofs()

header('Content-Type: application/json');

try {
    if (!is_portal_student()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }

    $user = auth_user();

    // Look up the student linked to this portal user account
    $stmt = db()->prepare(
        'SELECT s.id, s.student_id, s.full_name, s.status,
                p.id AS package_id
         FROM students s
         LEFT JOIN sfp_packages p ON p.student_id = s.id
         WHERE s.portal_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$user['id']]);
    $student = $stmt->fetch() ?: null;

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

    // Fetch per-semester scholarships for this package
    $sc_stmt = db()->prepare(
        'SELECT ss.sf_id, ss.label, ss.discount_pct, ss.discount_type,
                ss.fixed_amount, ss.amount, ss.applies_to_fixed, ss.applies_to_english
         FROM sfp_semester_scholarships ss
         JOIN sfp_semester_fees sf ON sf.id = ss.sf_id
         WHERE sf.package_id = ?
         ORDER BY ss.sf_id, ss.created_at ASC, ss.id ASC'
    );
    $sc_stmt->execute([(int)$student['package_id']]);
    $sc_rows = $sc_stmt->fetchAll();
    $sc_by_sfid = [];
    foreach ($sc_rows as $sc) {
        $sc_by_sfid[(int)$sc['sf_id']][] = [
            'label'             => $sc['label'],
            'discount_type'     => $sc['discount_type'] ?? 'percentage',
            'discount_pct'      => (float)$sc['discount_pct'],
            'fixed_amount'      => (float)$sc['fixed_amount'],
            'amount'            => (float)$sc['amount'],
            'applies_to_fixed'  => (bool)$sc['applies_to_fixed'],
            'applies_to_english'=> (bool)$sc['applies_to_english'],
        ];
    }

    // Attach scholarships to each semester entry in the summary
    foreach ($summary['semesters'] as &$sem) {
        $sf_id = (int)($sem['id'] ?? 0);
        $sem['scholarships'] = $sc_by_sfid[$sf_id] ?? [];
    }
    unset($sem);

    // Payment transaction history for this student only
    $raw_payments = acc_get_student_payments((int)$student['package_id']);
    $payments = array_map(function ($p) use ($month_labels_map) {
        // Scholarship memo rows are waivers that clear dues. Flag them so the
        // portal UI can badge the rows; their amounts ARE counted in the
        // student's paid totals. STRICT match, same as get-student-fees.php:
        // receipt/transaction number exactly OLD-ERP-SCHOLARSHIP, or a note
        // starting "SCHOLARSHIP (old ERP)".
        $txn_upper = strtoupper(trim((string)($p['transaction_number'] ?? '')));
        $note_str  = trim((string)($p['note'] ?? ''));
        $is_scholarship = ($txn_upper === 'OLD-ERP-SCHOLARSHIP')
            || (stripos($note_str, 'SCHOLARSHIP (old ERP)') === 0);
        return [
            'id'                      => (int)$p['id'],
            'voucher_id'              => (int)$p['voucher_id'],
            'collected_at'            => $p['collected_at'],
            'voucher_date'            => $p['voucher_date'],
            'voucher_number'          => $p['voucher_number'],
            'voucher_status'          => $p['voucher_status'],
            'fee_type'                => $p['fee_type'],
            'semester_number'         => $p['semester_number'] ?? null,
            'month_number'            => $p['month_number']    ?? null,
            'month_label'             => (!empty($p['semester_number']) && !empty($p['month_number']))
                ? ($month_labels_map[(int)$p['semester_number'] . ':' . (int)$p['month_number']] ?? '')
                : '',
            'payment_method'          => $p['payment_method'] ?? 'cash',
            'mobile_banking_provider' => $p['mobile_banking_provider'] ?? null,
            'transaction_number'      => $p['transaction_number'] ?? null,
            'payment_method_label'    => acc_payment_method_label(
                (string)($p['payment_method'] ?? 'cash'),
                $p['mobile_banking_provider'] ?? null
            ),
            'amount'                  => (float)$p['amount'],
            'is_scholarship'          => $is_scholarship,
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
        // Old ERP proof images attached to this student (via Student Accounts
        // → Bulk OLD ERP Proof Upload) so the portal can show them read-only.
        'old_erp_proofs' => array_map(static function ($f) {
            return [
                'id'          => (int)$f['id'],
                'name'        => (string)(($f['original_name'] ?? '') !== '' ? $f['original_name'] : 'OLD ERP Proof'),
                'url'         => UPLOAD_URL . '/students/files/' . rawurlencode((string)$f['stored_name']),
                'uploaded_at' => (string)($f['created_at'] ?? ''),
            ];
        }, sfp_get_old_erp_proofs((int)$student['id'])),
    ]);
} catch (Throwable $e) {
    error_log('get-student-fees-portal.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A server error occurred. Please try again or contact the accounts office.']);
}
