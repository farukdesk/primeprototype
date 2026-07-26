<?php
/**
 * Shared helpers for the Leave Management module.
 *
 * Leave categories:
 *   casual     – consumes the yearly Casual balance (default 10 days)
 *   sick       – consumes the yearly Sick balance (default 10 days)
 *   additional – outside the balance categories, marked paid/unpaid, no balance
 *   short      – always paid, single day with a start/end time, no balance
 *   maternity  – entitlement of 120 days, paid, no balance
 *   paternity  – entitlement of 7 days, paid, no balance
 *   study      – always unpaid, no balance
 *
 * Approval: a request is routed through an ordered list of user groups
 * (leave_approval_flow) that is scoped to the requester's user group, so
 * different groups / departments can have different approval systems. Each step
 * is approved by a member of the assigned group, who applies their uploaded
 * signature image. When every step is approved the request becomes "approved";
 * a rejection at any step rejects it.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';
// Reuse the signature upload directory / constants from Notice Signing.
require_once __DIR__ . '/../notice-signing/helpers.php';

// ── Constants ─────────────────────────────────────────────────────────────────
define('LM_DEFAULT_CASUAL', 10.0);
define('LM_DEFAULT_SICK',   10.0);
const LM_MATERNITY_DAYS = 120.0; // Maternity leave entitlement (days)
const LM_PATERNITY_DAYS = 7.0;   // Paternity leave entitlement (days)

const LM_CATEGORIES = ['casual', 'sick', 'additional', 'short', 'maternity', 'paternity', 'study'];
const LM_BALANCE_CATEGORIES = ['casual', 'sick'];   // consume a yearly balance
const LM_PAYTYPE_CATEGORIES = ['additional'];       // user chooses paid/unpaid

// Categories whose paid/unpaid status is fixed (not chosen by the requester).
const LM_FIXED_PAY = [
    'short'     => 'paid',
    'study'     => 'unpaid',
    'maternity' => 'paid',
    'paternity' => 'paid',
];

// Fixed day entitlement (cap) for certain categories; null = no fixed cap.
const LM_MAX_DAYS = [
    'maternity' => LM_MATERNITY_DAYS,
    'paternity' => LM_PATERNITY_DAYS,
];

// ── Permission helpers ────────────────────────────────────────────────────────

/** Anyone with view access can see the module (their own leaves). */
function lm_can_view(): bool
{
    return is_super_admin() || can_access('leave-management', 'can_view') || lm_self_service_allowed();
}

/** Whether the current user can submit a leave request. */
function lm_can_request(): bool
{
    return is_super_admin() || can_access('leave-management', 'can_create') || lm_self_service_allowed();
}

/**
 * Self-service: every employee whose Employee Type (staff_profiles.
 * department_type) is Administrative ('administrative') or Faculty
 * ('educational') can manage their OWN leaves from their portal — view their
 * balance / requests and submit new requests — even without Leave Management
 * module access. Admin features (Balances, Approval Flow, all requests) still
 * require module access via the Module Access page.
 */
function lm_self_service_allowed(): bool
{
    static $allowed = null;
    if ($allowed !== null) return $allowed;
    $user = auth_user();
    if (!$user) return $allowed = false;
    try {
        $stmt = db()->prepare('SELECT department_type FROM staff_profiles WHERE user_id = ?');
        $stmt->execute([(int)$user['id']]);
        $type = (string)$stmt->fetchColumn();
        return $allowed = in_array($type, ['administrative', 'educational'], true);
    } catch (Throwable $e) {
        return $allowed = false;
    }
}

/**
 * Whether the current user is a Leave Management administrator: they configure
 * the approval flow, set per-user balances and can see every request.
 */
function lm_is_admin(): bool
{
    return is_super_admin() || can_access('leave-management', 'can_edit');
}

// ── Presentation helpers ───────────────────────────────────────────────────────

function lm_category_label(string $cat): string
{
    return match ($cat) {
        'casual'     => 'Casual Leave',
        'sick'       => 'Sick Leave',
        'additional' => 'Additional Leave',
        'short'      => 'Short Leave',
        'maternity'  => 'Maternity Leave',
        'paternity'  => 'Paternity Leave',
        'study'      => 'Study Leave',
        default      => ucfirst($cat),
    };
}

function lm_category_badge(string $cat): string
{
    $map = [
        'casual'     => 'bg-info text-dark',
        'sick'       => 'bg-warning text-dark',
        'additional' => 'bg-primary',
        'short'      => 'bg-secondary',
        'maternity'  => 'bg-danger',
        'paternity'  => 'bg-success',
        'study'      => 'bg-dark',
    ];
    $cls = $map[$cat] ?? 'bg-light text-dark';
    return '<span class="badge ' . $cls . '">' . h(lm_category_label($cat)) . '</span>';
}

/** The fixed paid/unpaid value for a category, or null if the requester chooses. */
function lm_fixed_pay(string $cat): ?string
{
    return LM_FIXED_PAY[$cat] ?? null;
}

/** Whether the requester picks paid/unpaid for this category. */
function lm_category_needs_paytype(string $cat): bool
{
    return in_array($cat, LM_PAYTYPE_CATEGORIES, true);
}

/** Fixed day entitlement (cap) for a category, or null when there is none. */
function lm_category_max_days(string $cat): ?float
{
    return LM_MAX_DAYS[$cat] ?? null;
}

function lm_status_badge(string $status): string
{
    return match ($status) {
        'pending'   => '<span class="badge bg-warning text-dark">Pending</span>',
        'approved'  => '<span class="badge bg-success">Approved</span>',
        'rejected'  => '<span class="badge bg-danger">Rejected</span>',
        'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
        default     => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}

function lm_paytype_badge(?string $pay): string
{
    if ($pay === 'paid')   return '<span class="badge bg-success-subtle text-success border border-success">Paid</span>';
    if ($pay === 'unpaid') return '<span class="badge bg-danger-subtle text-danger border border-danger">Unpaid</span>';
    return '';
}

/** URL to a stored signature image (reuses Notice Signing signature store). */
function lm_signature_url(string $file): string
{
    return UPLOAD_URL . '/' . NS_SIG_SUBDIR . '/' . rawurlencode($file);
}

/**
 * A stored signature image as a base64 data URI for embedding in a PDF
 * (dompdf cannot fetch remote URLs). Returns '' when the file is missing.
 */
function lm_signature_data_uri(string $file): string
{
    if ($file === '') return '';
    $path = UPLOAD_DIR . '/' . NS_SIG_SUBDIR . '/' . basename($file);
    if (!is_file($path) || !is_readable($path)) return '';
    $bytes = file_get_contents($path);
    if ($bytes === false) return '';
    $mime = 'image/png';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') $mime = $detected;
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}

// ── Date / day helpers ──────────────────────────────────────────────────────────

/**
 * Inclusive whole-day count between two dates. Short leave returns 0 (it is
 * within a single day and does not consume any balance).
 */
function lm_calc_days(string $category, string $start, string $end): float
{
    if ($category === 'short') return 0.0;
    $s = strtotime($start);
    $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) return 0.0;
    return (float)(floor(($e - $s) / 86400) + 1);
}

// ── Balance helpers ─────────────────────────────────────────────────────────────

/**
 * Fetch a user's balance row for a year. Returns totals plus used/remaining
 * figures computed from approved requests.
 */
function lm_get_balance(int $user_id, ?int $year = null): array
{
    $year ??= (int)date('Y');

    $stmt = db()->prepare('SELECT casual_total, sick_total FROM leave_balances WHERE user_id = ? AND year = ?');
    $stmt->execute([$user_id, $year]);
    $row = $stmt->fetch();

    $casual_total = $row ? (float)$row['casual_total'] : LM_DEFAULT_CASUAL;
    $sick_total   = $row ? (float)$row['sick_total']   : LM_DEFAULT_SICK;

    $casual_used = lm_used_days($user_id, $year, 'casual');
    $sick_used   = lm_used_days($user_id, $year, 'sick');

    return [
        'year'             => $year,
        'exists'           => (bool)$row,
        'casual_total'     => $casual_total,
        'sick_total'       => $sick_total,
        'casual_used'      => $casual_used,
        'sick_used'        => $sick_used,
        'casual_remaining' => $casual_total - $casual_used,
        'sick_remaining'   => $sick_total - $sick_used,
    ];
}

/**
 * Days already consumed for a balance category in a given year, counting
 * approved requests and (optionally) pending ones so a user cannot over-book.
 */
function lm_used_days(int $user_id, int $year, string $category, bool $include_pending = false): float
{
    $statuses = $include_pending ? ['approved', 'pending'] : ['approved'];
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(days),0)
           FROM leave_requests
          WHERE user_id = ? AND category = ? AND status IN ($ph)
            AND YEAR(start_date) = ?"
    );
    $stmt->execute(array_merge([$user_id, $category], $statuses, [$year]));
    return (float)$stmt->fetchColumn();
}

/** Persist a user's yearly totals (used by the admin Balances page). */
function lm_set_balance(int $user_id, int $year, float $casual, float $sick): void
{
    $stmt = db()->prepare(
        'INSERT INTO leave_balances (user_id, year, casual_total, sick_total)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE casual_total = VALUES(casual_total), sick_total = VALUES(sick_total)'
    );
    $stmt->execute([$user_id, $year, $casual, $sick]);
}

// ── Approval-flow helpers ───────────────────────────────────────────────────────

/**
 * The configured, ordered, active approval steps for a given requester group.
 * Different requester groups can define different approval systems.
 */
function lm_active_flow_for_group(int $requester_group_id): array
{
    if ($requester_group_id < 1) return [];
    $stmt = db()->prepare(
        'SELECT f.*, g.name AS group_name
           FROM leave_approval_flow f
           JOIN user_groups g ON g.id = f.group_id
          WHERE f.is_active = 1 AND f.requester_group_id = ?
          ORDER BY f.step_order ASC, f.id ASC'
    );
    $stmt->execute([$requester_group_id]);
    return $stmt->fetchAll();
}

/** Whether a requester group has at least one active approval step. */
function lm_group_has_flow(int $requester_group_id): bool
{
    if ($requester_group_id < 1) return false;
    $stmt = db()->prepare(
        'SELECT 1 FROM leave_approval_flow WHERE requester_group_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$requester_group_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Resolve which of the user's groups drives the approval flow. Prefers the
 * user's primary group when it has a configured flow, otherwise the first of
 * their groups that does; falls back to the primary group id.
 */
function lm_flow_group_for_user(array $user): int
{
    $primary   = (int)($user['group_id'] ?? 0);
    $group_ids = array_map('intval', $user['group_ids'] ?? ($primary ? [$primary] : []));

    if ($primary > 0 && lm_group_has_flow($primary)) return $primary;
    foreach ($group_ids as $gid) {
        if (lm_group_has_flow($gid)) return $gid;
    }
    return $primary > 0 ? $primary : (int)($group_ids[0] ?? 0);
}

/** The active approval steps that apply to the given requesting user. */
function lm_active_flow_for_user(array $user): array
{
    return lm_active_flow_for_group(lm_flow_group_for_user($user));
}

/** Selectable user groups for building the flow (excludes super-admin groups). */
function lm_group_options(): array
{
    return db()->query(
        'SELECT id, name FROM user_groups WHERE is_active = 1 AND is_super = 0 ORDER BY name ASC'
    )->fetchAll();
}

/**
 * Snapshot the requester group's active flow into a request's approval steps.
 * Returns the number of steps created.
 */
function lm_snapshot_flow_for_request(int $request_id, int $requester_group_id): int
{
    $flow = lm_active_flow_for_group($requester_group_id);
    $ins = db()->prepare(
        'INSERT INTO leave_request_approvals (request_id, step_order, group_id, label)
         VALUES (?,?,?,?)'
    );
    $step = 0;
    foreach ($flow as $f) {
        $step++;
        $ins->execute([$request_id, $step, (int)$f['group_id'], $f['label'] ?: null]);
    }
    return $step;
}

/** All approval steps for a request, ordered, with group + approver info. */
function lm_request_approvals(int $request_id): array
{
    $stmt = db()->prepare(
        'SELECT a.*, g.name AS group_name, u.full_name AS approver_name
           FROM leave_request_approvals a
           JOIN user_groups g ON g.id = a.group_id
      LEFT JOIN users u ON u.id = a.approver_id
          WHERE a.request_id = ?
          ORDER BY a.step_order ASC'
    );
    $stmt->execute([$request_id]);
    return $stmt->fetchAll();
}

/** The current pending step row for a request, or null if none pending. */
function lm_current_step(int $request_id, int $current_step): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM leave_request_approvals WHERE request_id = ? AND step_order = ?'
    );
    $stmt->execute([$request_id, $current_step]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Whether the given user may act on the request's current pending step:
 * the request must be pending and the user must belong to the step's group.
 */
function lm_user_can_act(array $request, array $user): bool
{
    if ($request['status'] !== 'pending') return false;
    $step = lm_current_step((int)$request['id'], (int)$request['current_step']);
    if (!$step || $step['status'] !== 'pending') return false;
    $group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
    return in_array((int)$step['group_id'], $group_ids, true);
}

/** Count of requests currently awaiting the given user's approval. */
function lm_pending_for_user(array $user): int
{
    $group_ids = array_map('intval', $user['group_ids'] ?? [(int)$user['group_id']]);
    if (empty($group_ids)) return 0;
    $ph = implode(',', array_fill(0, count($group_ids), '?'));
    $stmt = db()->prepare(
        "SELECT COUNT(*)
           FROM leave_requests r
           JOIN leave_request_approvals a
             ON a.request_id = r.id AND a.step_order = r.current_step
          WHERE r.status = 'pending' AND a.status = 'pending'
            AND a.group_id IN ($ph)
            AND r.user_id <> ?"
    );
    $stmt->execute(array_merge($group_ids, [(int)$user['id']]));
    return (int)$stmt->fetchColumn();
}

// ── PDF (approved leave application) ─────────────────────────────────────────

/** University logo as a base64 data URI for embedding in a PDF, or '' if none. */
function lm_logo_data_uri(): string
{
    $logo = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
    if (is_file($logo) && is_readable($logo)) {
        $bytes = file_get_contents($logo);
        if ($bytes !== false) {
            return 'data:image/png;base64,' . base64_encode($bytes);
        }
    }
    return '';
}

/**
 * Build the printable "Leave Application" HTML for an approved leave request.
 *
 * @param array $req       Leave request row joined with requester name/email.
 * @param array $approvals Ordered approval steps (from lm_request_approvals()).
 * @param array $profile   Optional staff profile (employee_id, dept_name).
 */
function lm_build_pdf_html(array $req, array $approvals, array $profile = []): string
{
    $fmt = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

    $logo_uri = lm_logo_data_uri();
    $logo_html = $logo_uri
        ? '<img src="' . $logo_uri . '" style="width:150px;height:auto;max-height:70px;" alt="Prime University">'
        : '<span style="font-weight:bold;font-size:16px;">Prime University</span>';

    $generated = date('d M Y, g:i A');

    $employee_name = $req['requester_name'] ?? '';
    $employee_id   = $profile['employee_id'] ?? '';
    $department    = $profile['dept_name'] ?? '';

    $is_short = ($req['category'] === 'short');
    $start_disp = date('d M Y', strtotime($req['start_date']));
    $end_disp   = date('d M Y', strtotime($req['end_date']));
    $total_days = $fmt((float)$req['days']);

    // Remaining balance (Casual/Sick consume a yearly balance; otherwise N/A).
    $balance_line = 'N/A';
    if (in_array($req['category'], LM_BALANCE_CATEGORIES, true)) {
        $bal  = lm_get_balance((int)$req['user_id'], (int)date('Y', strtotime($req['start_date'])));
        $rem  = $req['category'] === 'casual' ? $bal['casual_remaining'] : $bal['sick_remaining'];
        $tot  = $req['category'] === 'casual' ? $bal['casual_total']     : $bal['sick_total'];
        $balance_line = $fmt((float)$rem) . ' of ' . $fmt((float)$tot) . ' day(s) ('
                      . lm_category_label($req['category']) . ')';
    }

    $row = fn(string $label, string $value) =>
        '<tr>'
        . '<td style="padding:6px 10px;font-weight:bold;width:200px;vertical-align:top;">' . h($label) . '</td>'
        . '<td style="padding:6px 10px;vertical-align:top;">' . $value . '</td>'
        . '</tr>';

    $details = '';
    $details .= $row('Employee Name:', h($employee_name));
    $details .= $row('Employee ID:', $employee_id !== '' ? h($employee_id) : '&mdash;');
    $details .= $row('Department:', $department !== '' ? h($department) : '&mdash;');
    $details .= $row('Leave Type:', h(lm_category_label($req['category'])));
    if ($is_short) {
        $details .= $row('Leave Date:', h($start_disp));
        $details .= $row('Time:',
            h(substr((string)$req['start_time'], 0, 5)) . ' &ndash; ' . h(substr((string)$req['end_time'], 0, 5)));
    } else {
        $details .= $row('Leave Start Date:', h($start_disp));
        $details .= $row('Leave End Date:', h($end_disp));
        $details .= $row('Total Leave Days:', '<strong>' . h($total_days) . '</strong> day(s)');
    }

    // Approved-by block: each approved step with approver name + signature image.
    $approved_rows = '';
    foreach ($approvals as $a) {
        if (($a['status'] ?? '') !== 'approved') continue;
        $sig_uri  = !empty($a['signature_file']) ? lm_signature_data_uri($a['signature_file']) : '';
        $sig_html = $sig_uri
            ? '<img src="' . $sig_uri . '" style="max-height:45px;max-width:150px;">'
            : '<span style="color:#888;">(signed)</span>';
        $acted = !empty($a['acted_at']) ? date('d M Y', strtotime($a['acted_at'])) : '';
        $label = $a['label'] ?: ($a['group_name'] ?? '');
        $approved_rows .=
            '<td style="width:33%;padding:10px;text-align:center;vertical-align:bottom;">'
            . '<div style="min-height:50px;">' . $sig_html . '</div>'
            . '<div style="border-top:1px solid #333;margin-top:4px;padding-top:4px;font-size:12px;">'
            . '<strong>' . h($a['approver_name'] ?? '—') . '</strong><br>'
            . h($label) . ($acted !== '' ? '<br>' . h($acted) : '')
            . '</div></td>';
    }
    $approved_block = $approved_rows !== ''
        ? '<table style="width:100%;border-collapse:collapse;"><tr>' . $approved_rows . '</tr></table>'
        : '<p style="color:#888;">No approvals recorded.</p>';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color:#222; font-size:13px; }
    .lm-header { width:100%; border-bottom:2px solid #333; padding-bottom:10px; margin-bottom:20px; }
    .lm-header td { vertical-align:middle; }
    .lm-title { font-size:20px; font-weight:bold; text-align:center; }
    .lm-meta { text-align:right; font-size:11px; color:#555; }
    .lm-section-title { font-weight:bold; font-size:14px; margin:18px 0 6px; border-bottom:1px solid #ccc; padding-bottom:4px; }
    table.lm-details { width:100%; border-collapse:collapse; }
    .lm-reason { border:1px solid #ddd; padding:10px; background:#fafafa; white-space:pre-wrap; }
    .lm-balance { padding:8px 10px; }
</style>
</head>
<body>
    <table class="lm-header">
        <tr>
            <td style="width:33%;text-align:left;"><?= $logo_html ?></td>
            <td style="width:34%;" class="lm-title">Leave Application</td>
            <td style="width:33%;" class="lm-meta"><?= h($generated) ?></td>
        </tr>
    </table>

    <table class="lm-details">
        <?= $details ?>
    </table>

    <div class="lm-section-title">Leave Reason</div>
    <div class="lm-reason"><?= nl2br(h($req['reason'])) ?></div>

    <div class="lm-section-title">Approved By</div>
    <?= $approved_block ?>

    <div class="lm-section-title">Remaining Leave Balance</div>
    <div class="lm-balance"><?= h($balance_line) ?></div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
