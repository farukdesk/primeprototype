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
define('LM_MATERNITY_DAYS', 120.0); // Maternity leave entitlement (days)
define('LM_PATERNITY_DAYS', 7.0);   // Paternity leave entitlement (days)

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
    'maternity' => 120.0,
    'paternity' => 7.0,
];

// ── Permission helpers ────────────────────────────────────────────────────────

/** Anyone with view access can see the module (their own leaves). */
function lm_can_view(): bool
{
    return is_super_admin() || can_access('leave-management', 'can_view');
}

/** Whether the current user can submit a leave request. */
function lm_can_request(): bool
{
    return is_super_admin() || can_access('leave-management', 'can_create');
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
