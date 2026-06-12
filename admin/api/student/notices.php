<?php
/**
 * Student Portal API – GET /api/student/notices.php
 * ====================================================
 * Returns paginated university and department notices for the student.
 *
 * Query params:
 *   type   = "university" (default) | "department"
 *   page   = 1, 2, 3 … (default 1)
 *   limit  = 20 (default, max 50)
 *   id     = <notice id>  (returns single notice detail when provided)
 *
 * Success response:
 *   { "ok": true, "type": "...", "notices": [...], "total": N, "page": N, "per_page": N }
 */

require_once __DIR__ . '/../includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sp_api_error(405, 'Method Not Allowed. Use GET.');
}

$ctx     = sp_api_auth();
$student = $ctx['student'];

$type    = in_array($_GET['type'] ?? '', ['department'], true) ? 'department' : 'university';
$page    = max(1, (int)($_GET['page']  ?? 1));
$limit   = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset  = ($page - 1) * $limit;

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
        if (!$n) sp_api_error(404, 'Notice not found.');
        sp_api_ok(['notice' => _format_university_notice($n)]);
    } else {
        $stmt = db()->prepare(
            'SELECT id, title, content, attachment, notice_date, created_at
             FROM dept_notices
             WHERE id = ? AND dept_id = ? AND is_active = 1'
        );
        $stmt->execute([$notice_id, (int)$student['dept_id']]);
        $n = $stmt->fetch();
        if (!$n) sp_api_error(404, 'Notice not found.');
        sp_api_ok(['notice' => _format_dept_notice($n, $student['dept_name'])]);
    }
    return;
}

// ── List notices ──────────────────────────────────────────────────────────────
if ($type === 'university') {
    try {
        $total = (int)db()->query(
            "SELECT COUNT(*) FROM cms_notices WHERE is_published = 1 AND is_approved = 1"
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

        sp_api_ok([
            'type'     => 'university',
            'notices'  => array_map('_format_university_notice', $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $limit,
        ]);
    } catch (Throwable $e) {
        sp_api_error(500, 'Failed to load notices. Please try again.');
    }
} else {
    try {
        $cnt_stmt = db()->prepare(
            "SELECT COUNT(*) FROM dept_notices WHERE dept_id = ? AND is_active = 1"
        );
        $cnt_stmt->execute([(int)$student['dept_id']]);
        $total = (int)$cnt_stmt->fetchColumn();

        $stmt = db()->prepare(
            'SELECT id, title, content, attachment, notice_date, created_at
             FROM dept_notices
             WHERE dept_id = ? AND is_active = 1
             ORDER BY notice_date DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([(int)$student['dept_id'], $limit, $offset]);
        $rows = $stmt->fetchAll();

        $dept_name = $student['dept_name'];
        sp_api_ok([
            'type'      => 'department',
            'dept_name' => $dept_name,
            'notices'   => array_map(fn($n) => _format_dept_notice($n, $dept_name), $rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $limit,
        ]);
    } catch (Throwable $e) {
        sp_api_error(500, 'Failed to load notices. Please try again.');
    }
}

// ── Formatters ────────────────────────────────────────────────────────────────

function _format_university_notice(array $n): array
{
    $date = $n['published_at']
        ? date('Y-m-d', strtotime($n['published_at']))
        : date('Y-m-d', strtotime($n['created_at']));

    $attachment_url = null;
    if (!empty($n['attachment'])) {
        $attachment_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/notices/' . rawurlencode($n['attachment']);
    }

    return [
        'id'                   => (int)$n['id'],
        'type'                 => 'university',
        'title'                => $n['title'],
        'content'              => $n['content'],
        'content_type'         => $n['content_type'] ?? 'text',
        'date'                 => $date,
        'attachment_url'       => $attachment_url,
        'attachment_name'      => $n['attachment_original_name'] ?? null,
        'attachment_size_kb'   => $n['attachment_size'] ? (int)ceil($n['attachment_size'] / 1024) : null,
    ];
}

function _format_dept_notice(array $n, string $dept_name): array
{
    $date = $n['notice_date']
        ? date('Y-m-d', strtotime($n['notice_date']))
        : date('Y-m-d', strtotime($n['created_at']));

    $attachment_url = null;
    if (!empty($n['attachment'])) {
        $attachment_url = (defined('UPLOAD_URL') ? UPLOAD_URL : '') . '/departments/' . rawurlencode($n['attachment']);
    }

    return [
        'id'             => (int)$n['id'],
        'type'           => 'department',
        'dept_name'      => $dept_name,
        'title'          => $n['title'],
        'content'        => $n['content'],
        'content_type'   => 'text',
        'date'           => $date,
        'attachment_url' => $attachment_url,
        'attachment_name'=> null,
    ];
}
