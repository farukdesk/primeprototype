<?php
/**
 * Staff API – GET /api/staff/notices.php
 * ========================================
 * University notices (all published) plus, for faculty members, their academic
 * department's notices. The response shape matches the student notices
 * endpoint so the mobile app reuses one notices UI for both roles.
 *
 * Query params:
 *   type   = "university" (default) | "department"
 *   page   = 1, 2, 3 … (default 1)
 *   limit  = 20 (default, max 50)
 *   id     = <notice id>  (returns single notice detail when provided)
 */

require_once __DIR__ . '/includes/auth_staff_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx = staff_api_auth();
$uid = (int)$ctx['user']['user_id'];

$type   = ($_GET['type'] ?? '') === 'department' ? 'department' : 'university';
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

function staff_format_university_notice(array $n): array
{
    $date = $n['published_at']
        ? date('Y-m-d', strtotime($n['published_at']))
        : date('Y-m-d', strtotime($n['created_at']));

    $attachment_url = null;
    if (!empty($n['attachment'])) {
        $attachment_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/notices/' . rawurlencode($n['attachment']);
    }

    return [
        'id'                 => (int)$n['id'],
        'type'               => 'university',
        'title'              => $n['title'],
        'content'            => $n['content'],
        'content_type'       => $n['content_type'] ?? 'text',
        'date'               => $date,
        'attachment_url'     => $attachment_url,
        'attachment_name'    => $n['attachment_original_name'] ?? null,
        'attachment_size_kb' => !empty($n['attachment_size']) ? (int)ceil($n['attachment_size'] / 1024) : null,
    ];
}

function staff_format_dept_notice(array $n, string $dept_name): array
{
    $date = $n['notice_date']
        ? date('Y-m-d', strtotime($n['notice_date']))
        : date('Y-m-d', strtotime($n['created_at']));

    $attachment_url = null;
    if (!empty($n['attachment'])) {
        $attachment_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/departments/' . rawurlencode($n['attachment']);
    }

    return [
        'id'              => (int)$n['id'],
        'type'            => 'department',
        'dept_name'       => $dept_name,
        'title'           => $n['title'],
        'content'         => $n['content'],
        'content_type'    => 'text',
        'date'            => $date,
        'attachment_url'  => $attachment_url,
        'attachment_name' => null,
    ];
}

// Resolve the employee's academic department (faculty only; may be 0).
$dept_id   = staff_academic_dept_id($uid);
$dept_name = '';
if ($dept_id > 0) {
    try {
        $stmt = db()->prepare('SELECT name FROM dept_departments WHERE id = ? LIMIT 1');
        $stmt->execute([$dept_id]);
        $dept_name = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {
    }
}

// ── Single notice detail ──────────────────────────────────────────────────────
if (isset($_GET['id'])) {
    $notice_id = (int)$_GET['id'];

    if ($type === 'university') {
        $stmt = db()->prepare(
            'SELECT id, title, content, content_type, attachment, attachment_original_name,
                    attachment_size, attachment_mime, published_at, created_at
             FROM cms_notices
             WHERE id = ? AND is_published = 1 AND is_approved = 1'
        );
        $stmt->execute([$notice_id]);
        $n = $stmt->fetch();
        if (!$n) api_error(404, 'Notice not found.');
        api_ok(['notice' => staff_format_university_notice($n)]);
    } else {
        if ($dept_id < 1) api_error(404, 'Notice not found.');
        $stmt = db()->prepare(
            'SELECT id, title, content, attachment, notice_date, created_at
             FROM dept_notices
             WHERE id = ? AND dept_id = ? AND is_active = 1'
        );
        $stmt->execute([$notice_id, $dept_id]);
        $n = $stmt->fetch();
        if (!$n) api_error(404, 'Notice not found.');
        api_ok(['notice' => staff_format_dept_notice($n, $dept_name)]);
    }
    exit;
}

// ── List notices ──────────────────────────────────────────────────────────────
if ($type === 'university') {
    try {
        $total = (int)db()->query(
            'SELECT COUNT(*) FROM cms_notices WHERE is_published = 1 AND is_approved = 1'
        )->fetchColumn();

        $stmt = db()->prepare(
            'SELECT id, title, content, content_type, attachment, attachment_original_name,
                    attachment_size, published_at, created_at
             FROM cms_notices
             WHERE is_published = 1 AND is_approved = 1
             ORDER BY published_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();

        api_ok([
            'type'     => 'university',
            'notices'  => array_map('staff_format_university_notice', $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $limit,
        ]);
    } catch (Throwable $e) {
        api_error(500, 'Failed to load notices. Please try again.');
    }
} else {
    // Department notices: only meaningful for faculty with an academic dept.
    if ($dept_id < 1) {
        api_ok([
            'type'      => 'department',
            'dept_name' => null,
            'notices'   => [],
            'total'     => 0,
            'page'      => $page,
            'per_page'  => $limit,
        ]);
        exit;
    }
    try {
        $cnt = db()->prepare('SELECT COUNT(*) FROM dept_notices WHERE dept_id = ? AND is_active = 1');
        $cnt->execute([$dept_id]);
        $total = (int)$cnt->fetchColumn();

        $stmt = db()->prepare(
            'SELECT id, title, content, attachment, notice_date, created_at
             FROM dept_notices
             WHERE dept_id = ? AND is_active = 1
             ORDER BY notice_date DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$dept_id, $limit, $offset]);
        $rows = $stmt->fetchAll();

        api_ok([
            'type'      => 'department',
            'dept_name' => $dept_name,
            'notices'   => array_map(fn($n) => staff_format_dept_notice($n, $dept_name), $rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $limit,
        ]);
    } catch (Throwable $e) {
        api_error(500, 'Failed to load notices. Please try again.');
    }
}
