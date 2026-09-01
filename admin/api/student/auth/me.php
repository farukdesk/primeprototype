<?php
/**
 * Student Portal API – GET /api/student/auth/me.php
 * ====================================================
 * Returns the authenticated student's full profile and summary stats.
 *
 * Success response:
 *   { "ok": true, "user": {...}, "student": {...}, "stats": {...} }
 */

require_once __DIR__ . '/../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx = sp_api_auth();
$user    = $ctx['user'];
$student = $ctx['student'];

// ── Unread notices count ──────────────────────────────────────────────────────
$unread_university = 0;
$unread_dept       = 0;

try {
    $unread_university = (int)db()->query(
        "SELECT COUNT(*) FROM cms_notices
         WHERE is_published = 1 AND is_approved = 1"
    )->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM dept_notices
         WHERE dept_id = ? AND is_active = 1"
    );
    $stmt->execute([(int)$student['dept_id']]);
    $unread_dept = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// ── Outstanding balance (lightweight query) ────────────────────────────────────
// Same figure as the Finances tab's "Due as of today": obligations up to the
// current calendar month; future installments are excluded. (The previous
// implementation summed $sem['total_due'] / $sem['total_paid'] – keys that do
// not exist in acc_student_fee_summary() – so the dashboard always showed 0.)
$outstanding_balance = null;
try {
    require_once dirname(__DIR__, 3) . '/accounting/helpers.php';
    $pkg_stmt = db()->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
    $pkg_stmt->execute([(int)$student['student_db_id']]);
    $pkg = $pkg_stmt->fetch();
    if ($pkg) {
        if (function_exists('acc_outstanding_through_current_month')) {
            $due = acc_outstanding_through_current_month((int)$pkg['id']);
            if ($due !== null) {
                $outstanding_balance = round((float)$due, 2);
            }
        }
        if ($outstanding_balance === null) {
            // Fallback: total outstanding across the whole fee summary, using
            // the REAL summary keys (admission heads, registration, months).
            $summary = acc_student_fee_summary((int)$student['student_db_id']);
            if ($summary) {
                $out = 0.0;
                foreach (['admission', 'form_fee', 'id_card_fee'] as $key) {
                    $head = $summary['totals'][$key] ?? null;
                    if (!$head) {
                        continue;
                    }
                    $due  = (float)($head['due']  ?? 0);
                    $paid = (float)($head['paid'] ?? 0);
                    $out += (float)($head['out'] ?? max(0.0, $due - $paid));
                }
                foreach ($summary['semesters'] ?? [] as $sem) {
                    $out += (float)($sem['reg_out'] ?? 0);
                    foreach (($sem['monthly_rows'] ?? []) as $mr) {
                        $out += (float)($mr['out'] ?? 0);
                    }
                }
                $outstanding_balance = round($out, 2);
            }
        }
    }
} catch (Throwable $e) {
    // Accounting module may not be set up; silently skip
}

$photo_url = null;
if (!empty($student['photo'])) {
    $photo_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/students/' . $student['photo'];
}

sp_api_ok([
    'user' => [
        'id'        => (int)$user['user_id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'email'     => $user['email'],
    ],
    'student' => [
        'id'           => (int)$student['student_db_id'],
        'student_id'   => $student['student_id'],
        'full_name'    => $student['student_name'],
        'photo_url'    => $photo_url,
        'phone'        => $student['phone'],
        'email'        => $student['student_email'],
        'status'       => $student['status'],
        'dept_name'    => $student['dept_name'],
        'dept_code'    => $student['dept_code'],
        'program_name' => $student['program_name'],
        'program_type' => $student['program_type'],
        'batch_name'   => $student['batch_name'],
    ],
    'stats' => [
        'notices_university'  => $unread_university,
        'notices_department'  => $unread_dept,
        'outstanding_balance' => $outstanding_balance,
    ],
    'server_time' => date('c'),
]);
