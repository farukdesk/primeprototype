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

// ── Leave balance (Casual / Sick) ────────────────────────────────────
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

// ── Today's clock in/out ─────────────────────────────────────────────
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

// ── Stats ────────────────────────────────────────────────────────────
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
$fp      = [];
if ($ctx['employee_type'] === 'educational') {
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

// ── Profile photo (staff photo first, then faculty photo) ────────────────────
$photo_url = null;
if (!empty($p['photo'])) {
    $photo_url = UPLOAD_URL . '/staff-profiles/' . rawurlencode($p['photo']);
} elseif (!empty($fp['photo'])) {
    $photo_url = UPLOAD_URL . '/faculty-profiles/' . rawurlencode($fp['photo']);
}

// ── Leave approval access ──────────────────────────────────────────────
// Employees who belong to a user group that appears in any active leave
// approval flow can approve / reject requests from the app.
$can_approve_leaves = false;
$pending_approvals  = 0;
try {
    $gids = [];
    try {
        $stmt = db()->prepare(
            'SELECT uga.group_id
               FROM user_group_assignments uga
               JOIN user_groups g ON g.id = uga.group_id AND g.is_active = 1
              WHERE uga.user_id = ?'
        );
        $stmt->execute([$uid]);
        $gids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        // Junction table missing (legacy install) – fall back to primary group.
    }
    $stmt = db()->prepare('SELECT group_id FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $primary = (int)$stmt->fetchColumn();
    if ($primary > 0 && !in_array($primary, $gids, true)) $gids[] = $primary;

    if (!empty($gids)) {
        $ph   = implode(',', array_fill(0, count($gids), '?'));
        $stmt = db()->prepare(
            "SELECT 1 FROM leave_approval_flow WHERE is_active = 1 AND group_id IN ($ph) LIMIT 1"
        );
        $stmt->execute($gids);
        $can_approve_leaves = (bool)$stmt->fetchColumn();

        if ($can_approve_leaves) {
            $stmt = db()->prepare(
                "SELECT COUNT(*)
                   FROM leave_requests r
                   JOIN leave_request_approvals a
                     ON a.request_id = r.id AND a.step_order = r.current_step
                  WHERE r.status = 'pending' AND a.status = 'pending'
                    AND a.group_id IN ($ph)
                    AND r.user_id <> ?"
            );
            $stmt->execute(array_merge($gids, [$uid]));
            $pending_approvals = (int)$stmt->fetchColumn();
        }
    }
} catch (Throwable $e) {
    // Leave Management migration not applied.
}

api_ok([
    'user' => [
        'id'        => $uid,
        'full_name' => $u['full_name'],
        'username'  => $u['username'],
        'email'     => $u['email'],
        'group'     => $u['group_name'],
        'photo_url' => $photo_url,
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
        'pending_approvals'  => $pending_approvals,
    ],
    'permissions' => [
        'can_approve_leaves' => $can_approve_leaves,
    ],
]);
