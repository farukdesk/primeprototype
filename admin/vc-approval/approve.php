<?php
/**
 * VC Approval – Approve a pending scholarship request.
 * POST-only. Accessible to users with vc-approval can_edit, and super-admins.
 *
 * Workflow:
 *  1. Validate the request (must be pending).
 *  2. Insert into sfp_semester_scholarships (one row per target semester).
 *  3. Recalculate semester totals.
 *  4. Mark the approval record as 'approved'.
 *  5. Log the action.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('vc-approval', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../student-accounts/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/vc-approval/index.php');
}

csrf_check();

$id          = (int)($_POST['id'] ?? 0);
$review_note = trim($_POST['review_note'] ?? '');
$user        = auth_user();

$req = vca_get_request($id);

if (!$req) {
    flash_set('error', 'Approval request not found.');
    redirect(APP_URL . '/vc-approval/index.php');
}

if ($req['status'] !== 'pending') {
    flash_set('error', 'This request is no longer pending (status: ' . h($req['status']) . ').');
    redirect(APP_URL . '/vc-approval/index.php');
}

$db = db();
$db->beginTransaction();

try {
    $package_id         = (int)$req['package_id'];
    $apply_to_all       = (int)$req['apply_to_all'];
    $discount_type      = $req['discount_type'];
    $discount_pct       = (float)$req['discount_pct'];
    $fixed_amount       = ($req['fixed_amount'] !== null) ? (float)$req['fixed_amount'] : null;
    $label              = $req['label'];
    $sc_note            = $req['sc_note'];
    $is_from_policy     = (int)$req['is_from_policy'];
    $applies_to_fixed   = (int)$req['applies_to_fixed'];
    $applies_to_english = (int)$req['applies_to_english'];
    $support_doc_id     = $req['support_doc_id'] ? (int)$req['support_doc_id'] : null;

    $insert_sc = $db->prepare(
        'INSERT INTO sfp_semester_scholarships
           (sf_id, label, discount_pct, discount_type, fixed_amount, amount, note,
            is_from_policy, applies_to_fixed, applies_to_english,
            support_doc_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    if ($apply_to_all) {
        // Apply to every semester in the package
        $sf_rows = sfp_get_semester_fees($package_id);
        foreach ($sf_rows as $sf) {
            $row_id          = (int)$sf['id'];
            $tuition_payable = (float)$sf['tuition_payable'];
            if ($discount_type === 'fixed') {
                $amount = round(min((float)$fixed_amount, $tuition_payable), 2);
            } else {
                $amount = round($tuition_payable * $discount_pct / 100, 2);
            }

            $insert_sc->execute([
                $row_id,
                $label,
                round($discount_pct, 2),
                $discount_type,
                $fixed_amount,
                $amount,
                $sc_note ?: null,
                $is_from_policy,
                $applies_to_fixed,
                $applies_to_english,
                $support_doc_id,
                $user['id'],
            ]);

            sfp_recalculate_semester($row_id, $user['id']);
        }
    } else {
        $sf_id = (int)$req['sf_id'];
        // Re-fetch fresh row for current payable balance
        $sf_stmt = $db->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
        $sf_stmt->execute([$sf_id, $package_id]);
        $sf = $sf_stmt->fetch();

        if (!$sf) {
            throw new RuntimeException('Semester fee record no longer exists.');
        }

        $tuition_payable = (float)$sf['tuition_payable'];
        if ($discount_type === 'fixed') {
            $amount = round(min((float)$fixed_amount, $tuition_payable), 2);
        } else {
            $amount = round($tuition_payable * $discount_pct / 100, 2);
        }

        $insert_sc->execute([
            $sf_id,
            $label,
            round($discount_pct, 2),
            $discount_type,
            $fixed_amount,
            $amount,
            $sc_note ?: null,
            $is_from_policy,
            $applies_to_fixed,
            $applies_to_english,
            $support_doc_id,
            $user['id'],
        ]);

        sfp_recalculate_semester($sf_id, $user['id']);
    }

    // Mark the approval record as approved
    $db->prepare(
        "UPDATE vc_scholarship_approvals
         SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
         WHERE id = ?"
    )->execute([$user['id'], $review_note ?: null, $id]);

    $db->commit();

    log_change(
        'vc-approval', 'UPDATE', $id,
        $req['student_name'] . ' – ' . $label,
        'status',
        'pending',
        'approved',
        'VC approved scholarship "' . $label . '" for ' . $req['student_name']
    );

    $sc_display = ($discount_type === 'fixed')
        ? 'BDT ' . number_format((float)$fixed_amount, 2)
        : number_format($discount_pct, 2) . '%';

    flash_set('success',
        'Scholarship <strong>' . h($label) . '</strong> (' . $sc_display . ') approved and applied to '
        . h($req['student_name']) . '\'s account.'
    );

} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Failed to approve scholarship: ' . h($e->getMessage()));
}

redirect(APP_URL . '/vc-approval/index.php?tab=pending');
