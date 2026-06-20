<?php
/**
 * API – GET /api/auth/me.php
 * ============================
 * Returns the authenticated user's profile, permissions, and unread counts.
 *
 * Request headers:
 *   Authorization: ******
 *
 * Success response:
 *   { "ok": true, "user": {...}, "permissions": [...], "unread": {...} }
 */

require_once __DIR__ . '/../includes/auth_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

$API_USER = api_auth();

$user_id  = (int)$API_USER['user_id'];
$group_id = (int)$API_USER['group_id'];
$is_super = (bool)$API_USER['is_super'];

$permissions = api_get_permissions($user_id, $group_id, $is_super);

// ── Unread / pending counts ───────────────────────────────────────────────────
$unread = [];

// Open support tickets assigned to or created by this user (or all for super admin)
try {
    if ($is_super || in_array('support-tickets', $permissions, true)) {
        if ($is_super) {
            $unread['open_tickets'] = (int)db()->query(
                "SELECT COUNT(*) FROM support_tickets WHERE status = 'Open'"
            )->fetchColumn();
        } else {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM support_tickets
                 WHERE status = 'Open' AND created_by = ?"
            );
            $stmt->execute([$user_id]);
            $unread['open_tickets'] = (int)$stmt->fetchColumn();
        }
    }
} catch (Throwable $e) {
    $unread['open_tickets'] = 0;
}

// Pending broadcast approvals
try {
    if ($is_super || in_array('broadcast', $permissions, true)) {
        $unread['pending_broadcasts'] = (int)db()->query(
            "SELECT COUNT(*) FROM broadcasts WHERE status = 'pending_approval'"
        )->fetchColumn();
    }
} catch (Throwable $e) {
    $unread['pending_broadcasts'] = 0;
}

api_ok([
    'user' => [
        'id'         => $user_id,
        'full_name'  => $API_USER['full_name'],
        'username'   => $API_USER['username'],
        'email'      => $API_USER['email'],
        'group'      => $API_USER['group_name'],
        'is_super'   => $is_super,
        'avatar_url' => null,
    ],
    'permissions' => $permissions,
    'unread'      => $unread,
    'server_time' => date('c'),
]);
