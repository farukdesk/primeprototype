<?php
/**
 * Shared helper: where is a user group still referenced?
 *
 * A group that is referenced anywhere must not be deleted — even by an admin
 * or super admin — because deleting it would orphan users, member
 * assignments, leave approval flows or historical approval steps.
 *
 * Pure permission/config rows (group_module_access, group_dept_scope,
 * leave_flow_priorities) do NOT block deletion; they are cleaned up together
 * with the group in delete.php.
 */

require_once __DIR__ . '/../includes/auth.php';

/**
 * Usage reasons for every group, computed in a handful of batch queries:
 * [group_id => ['3 user(s) have it as their primary group', ...]].
 * Tables that are not installed are skipped gracefully.
 */
function ug_usage_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];

    $add = function (string $sql, string $label) use (&$map): void {
        try {
            foreach (db()->query($sql) as $r) {
                $gid = (int)$r['gid'];
                $n   = (int)$r['n'];
                if ($gid > 0 && $n > 0) $map[$gid][] = sprintf($label, $n);
            }
        } catch (Throwable $e) {
            // Table not installed – nothing to count for this reference.
        }
    };

    $add('SELECT group_id AS gid, COUNT(*) AS n FROM users GROUP BY group_id',
         '%d user(s) have it as their primary group');
    $add('SELECT group_id AS gid, COUNT(*) AS n FROM user_group_assignments GROUP BY group_id',
         '%d group member assignment(s)');
    $add('SELECT requester_group_id AS gid, COUNT(*) AS n FROM leave_approval_flow GROUP BY requester_group_id',
         'requester group of %d leave approval flow step(s)');
    $add('SELECT group_id AS gid, COUNT(*) AS n FROM leave_approval_flow GROUP BY group_id',
         'approver in %d leave approval flow step(s)');
    $add('SELECT group_id AS gid, COUNT(*) AS n FROM leave_request_approvals GROUP BY group_id',
         'approver in %d leave request approval record(s)');

    return $map;
}

/** Usage reasons for a single group ([] when unused and safe to delete). */
function ug_group_usage(int $group_id): array
{
    return ug_usage_map()[$group_id] ?? [];
}
