<?php
/**
 * VC Approval – Revoke an approved scholarship (super-admin only).
 * POST-only.
 *
 * Workflow:
 *  1. Validate: request must be 'approved'; caller must be super-admin.
 *  2. Delete ALL sfp_semester_scholarships rows whose label matches AND whose
 *     sf_id belongs to the same package AND whose created_by is the reviewer
 *     (or match by support_doc_id to be precise).
 *     We use support_doc_id when available; otherwise we fall back to matching
 *     on label + package to cover the apply_to_all case.
 *  3. Recalculate semester totals for each affected sf_id.
 *  4. Mark the approval record as 'revoked'.
 *  5. Log the action.
 */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/vc-approval/index.php');
}

csrf_check();

$id            = (int)($_POST['id'] ?? 0);
$revoke_reason = trim($_POST['revoke_reason'] ?? '');
$user          = auth_user();

if ($revoke_reason === '') {
    flash_set('error', 'A reason for revocation is required.');
    redirect(APP_URL . '/vc-approval/index.php?tab=approved');
}

$req = vca_get_request($id);

if (!$req) {
    flash_set('error', 'Approval request not found.');
    redirect(APP_URL . '/vc-approval/index.php');
}

if ($req['status'] !== 'approved') {
    flash_set('error', 'Only approved scholarships can be revoked (current status: ' . h($req['status']) . ').');
    redirect(APP_URL . '/vc-approval/index.php?tab=approved');
}

$db          = db();
$package_id  = (int)$req['package_id'];
$apply_to_all = (int)$req['apply_to_all'];
$support_doc_id = $req['support_doc_id'] ? (int)$req['support_doc_id'] : null;
$label       = $req['label'];

$db->beginTransaction();

try {
    // Find the sfp_semester_scholarships rows to delete.
    // Prefer matching by support_doc_id (unique per scholarship upload);
    // fall back to label + package when no document was attached.
    if ($support_doc_id) {
        $find_stmt = $db->prepare(
            'SELECT ss.id AS ss_id, ss.sf_id
             FROM sfp_semester_scholarships ss
             JOIN sfp_semester_fees sf ON sf.id = ss.sf_id
             WHERE ss.support_doc_id = ? AND sf.package_id = ?'
        );
        $find_stmt->execute([$support_doc_id, $package_id]);
    } else {
        $find_stmt = $db->prepare(
            'SELECT ss.id AS ss_id, ss.sf_id
             FROM sfp_semester_scholarships ss
             JOIN sfp_semester_fees sf ON sf.id = ss.sf_id
             WHERE ss.label = ? AND sf.package_id = ?'
        );
        $find_stmt->execute([$label, $package_id]);
    }
    $sc_rows = $find_stmt->fetchAll();

    if (empty($sc_rows)) {
        throw new RuntimeException('No matching scholarship rows found to remove. They may have already been deleted.');
    }

    $delete_stmt = $db->prepare('DELETE FROM sfp_semester_scholarships WHERE id = ?');
    $affected_sf_ids = [];
    foreach ($sc_rows as $row) {
        $delete_stmt->execute([(int)$row['ss_id']]);
        $affected_sf_ids[] = (int)$row['sf_id'];
    }

    // Recalculate totals for each affected semester
    foreach (array_unique($affected_sf_ids) as $sf_id) {
        sfp_recalculate_semester($sf_id, $user['id']);
    }

    // Mark approval record as revoked
    $db->prepare(
        "UPDATE vc_scholarship_approvals
         SET status = 'revoked', revoked_by = ?, revoked_at = NOW(), revoke_reason = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$user['id'], $revoke_reason, $id]);

    $db->commit();

    log_change(
        'vc-approval', 'UPDATE', $id,
        $req['student_name'] . ' – ' . $label,
        'status',
        'approved',
        'revoked',
        'Admin revoked approved scholarship "' . $label . '" for ' . $req['student_name'] . '. Reason: ' . $revoke_reason
    );

    flash_set('success',
        'Scholarship <strong>' . h($label) . '</strong> has been revoked and removed from '
        . h($req['student_name']) . '\'s account.'
    );

} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Failed to revoke scholarship: ' . h($e->getMessage()));
}

redirect(APP_URL . '/vc-approval/index.php?tab=approved');
