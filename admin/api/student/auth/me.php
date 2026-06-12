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
$outstanding_balance = null;
try {
    require_once dirname(__DIR__, 3) . '/accounting/helpers.php';
    $pkg_stmt = db()->prepare('SELECT id FROM sfp_packages WHERE student_id = ? LIMIT 1');
    $pkg_stmt->execute([(int)$student['student_db_id']]);
    $pkg = $pkg_stmt->fetch();
    if ($pkg) {
        $summary = acc_student_fee_summary((int)$student['student_db_id']);
        if ($summary) {
            $total_due  = 0;
            $total_paid = 0;
            foreach ($summary['semesters'] ?? [] as $sem) {
                $total_due  += (float)($sem['total_due']   ?? 0);
                $total_paid += (float)($sem['total_paid']  ?? 0);
            }
            $outstanding_balance = round($total_due - $total_paid, 2);
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
