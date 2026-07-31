<?php
/**
 * Staff API – GET /api/staff/me.php
 * ===================================
 * Returns the signed-in employee's profile, leave-balance snapshot, today's
 * clock in/out and dashboard stats for the mobile app staff view.
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx = staff_api_auth();
$u   = $ctx['user'];
$p   = $ctx['profile'];
$uid = (int)$u['user_id'];

// ── Leave balance (Casual / Sick) ────────────────────────────────────────────
$year = (int)date('Y');
$casual_total = 10.0;
$sick_total   = 10.0;
try {
    $stmt = db()->prepare('SELECT casual_total, sick_total FROM leave_balances WHERE user_id = ? AND year = ?');
    $stmt->execute([$uid, $year]);
    if ($row = $stmt->fetch()) {
        $casual_total = (float)$row['casual_total'];
        $sick_total   = (float)$row['sick_total'];
    }
} catch (Throwable $e) {
    // Leave Management migration not applied – defaults stand.
}

$used = function (string $cat) use ($uid, $year): float {
    try {
        $stmt = db()->prepare(
            "SELECT COALESCE(SUM(days),0) FROM leave_requests
              WHERE user_id = ? AND category = ? AND status = 'approved' AND YEAR(start_date) = ?"
        );
        $stmt->execute([$uid, $cat, $year]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
};
$casual_used = $used('casual');
$sick_used   = $used('sick');

// ── Today's clock in/out ─────────────────────────────────────────────────────
$today = ['date' => date('Y-m-d'), 'in_time' => null, 'out_time' => null];
try {
    $stmt = db()->prepare('SELECT in_time, out_time FROM att_records WHERE user_id = ? AND work_date = ? LIMIT 1');
    $stmt->execute([$uid, $today['date']]);
    if ($r = $stmt->fetch()) {
        $today['in_time']  = $r['in_time']  ? substr((string)$r['in_time'], 0, 5)  : null;
        $today['out_time'] = $r['out_time'] ? substr((string)$r['out_time'], 0, 5) : null;
    }
} catch (Throwable $e) {
    // Staff Attendance migration not applied – no punch data.
}

// ── Stats ────────────────────────────────────────────────────────────────────
$notices = 0;
try {
    $notices = (int)db()->query(
        'SELECT COUNT(*) FROM cms_notices WHERE is_published = 1 AND is_approved = 1'
    )->fetchColumn();
} catch (Throwable $e) {
}

$pending_leaves = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $pending_leaves = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}

// ── Faculty academic profile (educational employees only) ────────────────────
$faculty = null;
if ($ctx['employee_type'] === 'educational') {
    $fp = [];
    try {
        $stmt = db()->prepare('SELECT * FROM faculty_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $fp = $stmt->fetch() ?: [];
    } catch (Throwable $e) {
        $fp = [];
    }

    $academic_dept = null;
    try {
        $stmt = db()->prepare(
            'SELECT dd.name
               FROM dept_faculty df
               JOIN dept_departments dd ON dd.id = df.dept_id
              WHERE df.user_id = ? AND df.is_active = 1
              ORDER BY df.is_head DESC, df.id ASC
              LIMIT 1'
        );
        $stmt->execute([$uid]);
        $academic_dept = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
    }

    $office_parts = array_filter([
        $fp['office_location'] ?? null,
        !empty($fp['room_number']) ? 'Room ' . $fp['room_number'] : null,
    ]);

    $faculty = [
        'designation'         => $fp['designation'] ?? null,
        'academic_department' => $academic_dept,
        'official_email'      => $fp['official_email'] ?? null,
        'office'              => $office_parts ? implode(', ', $office_parts) : null,
        'office_hours'        => $fp['office_hours'] ?? null,
        'qualification'       => $fp['qualification'] ?? null,
        'research_interest'   => $fp['research_interest'] ?? null,
    ];
}

api_ok([
    'user' => [
        'id'        => $uid,
        'full_name' => $u['full_name'],
        'username'  => $u['username'],
        'email'     => $u['email'],
        'group'     => $u['group_name'],
    ],
    'employee' => [
        'employee_type'       => $ctx['employee_type'],
        'employee_type_label' => staff_employee_type_label($ctx['employee_type']),
        'employee_id'         => $p['employee_id']     ?? null,
        'designation'         => $p['designation']     ?? null,
        'department'          => $p['staff_dept_name'] ?? null,
        'phone'               => $p['phone']           ?? null,
        'blood_group'         => $p['blood_group']     ?? null,
        'job_type'            => $p['job_type']        ?? null,
        'joining_date'        => $p['joining_date']    ?? null,
        'employee_status'     => $p['employee_status'] ?? null,
        'father_name'         => $p['father_name']     ?? null,
        'mother_name'         => $p['mother_name']     ?? null,
        'gender'              => $p['gender']          ?? null,
        'religion'            => $p['religion']        ?? null,
        'national_id'         => $p['national_id']     ?? null,
        'date_of_birth'       => $p['date_of_birth']   ?? null,
        'nationality'         => $p['nationality']     ?? null,
        'birth_place'         => $p['birth_place']     ?? null,
    ],
    'faculty' => $faculty,
    'leave_balance' => [
        'year'             => $year,
        'casual_total'     => $casual_total,
        'casual_used'      => $casual_used,
        'casual_remaining' => $casual_total - $casual_used,
        'sick_total'       => $sick_total,
        'sick_used'        => $sick_used,
        'sick_remaining'   => $sick_total - $sick_used,
    ],
    'today' => $today,
    'stats' => [
        'notices_university' => $notices,
        'pending_leaves'     => $pending_leaves,
    ],
]);
