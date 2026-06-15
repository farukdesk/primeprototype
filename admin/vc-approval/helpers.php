<?php
/**
 * VC Approval – shared helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────

/** Returns true for VC users and super-admins. */
function vca_can_review(): bool
{
    return is_super_admin() || can_access('vc-approval', 'can_edit');
}

/** Returns true for super-admins only (revoke is destructive). */
function vca_can_revoke(): bool
{
    return is_super_admin();
}

// ── Data fetchers ─────────────────────────────────────────────────────────────

function vca_get_request(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT r.*,
                s.full_name  AS student_name,
                s.student_id AS student_sid,
                p.program_name,
                req.full_name AS requested_by_name,
                rev.full_name AS reviewed_by_name,
                rvk.full_name AS revoked_by_name,
                sf.semester_number,
                sf.semester_label,
                stf.stored_name   AS doc_stored_name,
                stf.original_name AS doc_original_name
         FROM vc_scholarship_approvals r
         JOIN students s          ON s.id    = r.student_id
         LEFT JOIN sfp_packages p ON p.id    = r.package_id
         LEFT JOIN sfp_semester_fees sf ON sf.id = r.sf_id
         JOIN users req           ON req.id  = r.requested_by
         LEFT JOIN users rev      ON rev.id  = r.reviewed_by
         LEFT JOIN users rvk      ON rvk.id  = r.revoked_by
         LEFT JOIN student_files stf ON stf.id = r.support_doc_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function vca_get_pending_count(): int
{
    try {
        return (int)db()->query(
            "SELECT COUNT(*) FROM vc_scholarship_approvals WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function vca_list(string $status = 'pending', int $limit = 200): array
{
    $allowed = ['pending', 'approved', 'rejected', 'revoked', 'all'];
    if (!in_array($status, $allowed, true)) $status = 'pending';

    $where_clause = ($status === 'all') ? '' : "WHERE r.status = ?";

    $sql = "SELECT r.*,
                s.full_name  AS student_name,
                s.student_id AS student_sid,
                p.program_name,
                req.full_name AS requested_by_name,
                rev.full_name AS reviewed_by_name,
                sf.semester_number,
                sf.semester_label,
                stf.stored_name   AS doc_stored_name,
                stf.original_name AS doc_original_name
         FROM vc_scholarship_approvals r
         JOIN students s          ON s.id    = r.student_id
         LEFT JOIN sfp_packages p ON p.id    = r.package_id
         LEFT JOIN sfp_semester_fees sf ON sf.id = r.sf_id
         JOIN users req           ON req.id  = r.requested_by
         LEFT JOIN users rev      ON rev.id  = r.reviewed_by
         LEFT JOIN student_files stf ON stf.id = r.support_doc_id
         $where_clause
         ORDER BY r.created_at DESC
         LIMIT ?";

    $stmt = db()->prepare($sql);
    if ($status === 'all') {
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(1, $status, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

// ── Status badge helper ───────────────────────────────────────────────────────

function vca_status_badge(string $status): string
{
    return match ($status) {
        'pending'  => '<span class="badge bg-warning text-dark">Pending VC Approval</span>',
        'approved' => '<span class="badge bg-success">Approved</span>',
        'rejected' => '<span class="badge bg-danger">Rejected</span>',
        'revoked'  => '<span class="badge bg-secondary">Revoked</span>',
        default    => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}
