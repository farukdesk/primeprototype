<?php
/**
 * Add a new scholarship entry – routes through VC Approval.
 *
 * Instead of applying the scholarship immediately, a pending approval request
 * is created in vc_scholarship_approvals.  The VC reviews and approves via
 * admin/vc-approval/index.php; only then are the amounts applied and deducted
 * from the student's statement.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../students/helpers.php';  // sm_upload_file()
require_once __DIR__ . '/../change-log/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$package_id      = (int)($_POST['package_id']      ?? 0);
$sf_id           = (int)($_POST['sf_id']           ?? 0);
$label           = trim($_POST['sc_label']         ?? '');
$discount_type   = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'])
                       ? $_POST['discount_type'] : 'percentage';
$discount_pct    = ($discount_type === 'percentage') ? (float)($_POST['discount_pct'] ?? 0) : 0.0;
$fixed_amount    = ($discount_type === 'fixed')      ? (float)($_POST['fixed_amount']  ?? 0) : null;
$sc_note         = trim($_POST['sc_note']          ?? '');
$apply_to_all    = !empty($_POST['apply_to_all']);
$is_from_policy  = !empty($_POST['is_from_policy']) ? 1 : 0;
// Fixed-amount scholarships only apply to tuition; ignore scope checkboxes for fixed type
$applies_to_fixed   = ($discount_type === 'percentage' && !empty($_POST['applies_to_fixed']))   ? 1 : 0;
$applies_to_english = ($discount_type === 'percentage' && !empty($_POST['applies_to_english'])) ? 1 : 0;

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($label === '') $errors[] = 'Scholarship label is required.';
if ($discount_type === 'percentage') {
    if ($discount_pct < 0.01 || $discount_pct > 100) $errors[] = 'Discount percentage must be between 0.01 and 100.';
} else {
    if ($fixed_amount === null || $fixed_amount < 0.01) $errors[] = 'Fixed scholarship amount must be greater than 0.';
}
if (!$apply_to_all && $sf_id <= 0) $errors[] = 'Invalid semester row.';

// Resolve student_id for file storage (needed if uploading a support doc)
$support_doc_id = null;
if (empty($errors)) {
    $pkg_row = db()->prepare('SELECT student_id FROM sfp_packages WHERE id = ?');
    $pkg_row->execute([$package_id]);
    $pkg_row = $pkg_row->fetch();
    $student_id = (int)($pkg_row['student_id'] ?? 0);

    // Support document handling
    $has_file = !empty($_FILES['support_doc']['name']);

    if (!$is_from_policy) {
        // Manual scholarship: document is mandatory
        if (!$has_file) {
            $errors[] = 'A supporting document is required for non-policy scholarships.';
        }
    }

    if (empty($errors) && $has_file) {
        $uploaded = sm_upload_file($_FILES['support_doc']);
        if ($uploaded === false) {
            $errors[] = 'Invalid file type or size (max 20 MB). Allowed: images, PDF, Word, Excel, PPT, ZIP, TXT.';
        } else {
            $user = auth_user();
            // Derive a descriptive file name for the student files record
            $doc_file_name = 'Scholarship Support - ' . $label;

            db()->prepare(
                'INSERT INTO student_files
                   (student_id, file_name, description, stored_name, original_name, mime_type, file_size, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $student_id,
                $doc_file_name,
                'Supporting document for scholarship: ' . $label,
                $uploaded['stored_name'],
                $uploaded['original_name'],
                $uploaded['mime_type'],
                $uploaded['file_size'],
                $user['id'],
            ]);
            $support_doc_id = (int)db()->lastInsertId();
        }
    }
}

if (empty($errors)) {
    $user = auth_user();

    // Validate the semester row when not applying to all
    if (!$apply_to_all) {
        $sf_chk = db()->prepare('SELECT id FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
        $sf_chk->execute([$sf_id, $package_id]);
        if (!$sf_chk->fetch()) {
            $errors[] = 'Semester fee record not found.';
        }
    }

    if (empty($errors)) {
        if ($is_from_policy) {
            // ── Policy scholarship: apply directly without VC approval ────────
            $insert_sc = db()->prepare(
                'INSERT INTO sfp_semester_scholarships
                   (sf_id, label, discount_pct, discount_type, fixed_amount, amount, note,
                    is_from_policy, applies_to_fixed, applies_to_english,
                    support_doc_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if ($apply_to_all) {
                $sf_rows = sfp_get_semester_fees($package_id);
                foreach ($sf_rows as $sf_row) {
                    $row_id          = (int)$sf_row['id'];
                    $tuition_payable = (float)$sf_row['tuition_payable'];
                    $amount = ($discount_type === 'fixed')
                        ? round(min((float)$fixed_amount, $tuition_payable), 2)
                        : round($tuition_payable * $discount_pct / 100, 2);

                    $insert_sc->execute([
                        $row_id, $label, round($discount_pct, 2), $discount_type,
                        $fixed_amount, $amount, $sc_note ?: null,
                        $is_from_policy, $applies_to_fixed, $applies_to_english,
                        $support_doc_id, $user['id'],
                    ]);
                    sfp_recalculate_semester($row_id, $user['id']);
                }
            } else {
                $sf_stmt2 = db()->prepare('SELECT tuition_payable FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
                $sf_stmt2->execute([$sf_id, $package_id]);
                $sf_row = $sf_stmt2->fetch();
                if (!$sf_row) {
                    $errors[] = 'Semester fee record not found.';
                } else {
                    $tuition_payable = (float)$sf_row['tuition_payable'];
                    $amount = ($discount_type === 'fixed')
                        ? round(min((float)$fixed_amount, $tuition_payable), 2)
                        : round($tuition_payable * $discount_pct / 100, 2);

                    $insert_sc->execute([
                        $sf_id, $label, round($discount_pct, 2), $discount_type,
                        $fixed_amount, $amount, $sc_note ?: null,
                        $is_from_policy, $applies_to_fixed, $applies_to_english,
                        $support_doc_id, $user['id'],
                    ]);
                    sfp_recalculate_semester($sf_id, $user['id']);
                }
            }

            if (empty($errors)) {
                $sc_display = ($discount_type === 'fixed')
                    ? 'BDT ' . number_format((float)$fixed_amount, 2)
                    : number_format($discount_pct, 2) . '%';

                log_change(
                    'student-accounts', 'UPDATE', $package_id,
                    $apply_to_all ? 'All Semesters' : 'Semester #' . $sf_id,
                    'scholarship_applied',
                    null,
                    $label . ' (' . $sc_display . ')',
                    'Policy scholarship "' . $label . '" (' . $sc_display . ') applied directly – '
                        . ($apply_to_all ? 'all semesters' : 'Semester #' . $sf_id)
                );

                flash_set('success',
                    'Scholarship <strong>' . h($label) . '</strong> ('
                    . $sc_display . ') applied successfully.'
                );
            }
        } else {
            // ── Non-policy scholarship: route through VC approval ─────────────
            db()->prepare(
                'INSERT INTO vc_scholarship_approvals
                   (package_id, student_id, sf_id, apply_to_all,
                    label, discount_type, discount_pct, fixed_amount, sc_note,
                    is_from_policy, applies_to_fixed, applies_to_english,
                    support_doc_id, status, requested_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?)'
            )->execute([
                $package_id, $student_id,
                $apply_to_all ? null : $sf_id,
                $apply_to_all ? 1 : 0,
                $label, $discount_type,
                round($discount_pct, 2), $fixed_amount, $sc_note ?: null,
                0, $applies_to_fixed, $applies_to_english,
                $support_doc_id, $user['id'],
            ]);

            $sc_display = ($discount_type === 'fixed')
                ? 'BDT ' . number_format((float)$fixed_amount, 2)
                : number_format($discount_pct, 2) . '%';

            log_change(
                'student-accounts', 'UPDATE', $package_id,
                $apply_to_all ? 'All Semesters' : 'Semester #' . $sf_id,
                'scholarship_pending_vc',
                null,
                $label . ' (' . $sc_display . ')',
                'Scholarship "' . $label . '" (' . $sc_display . ') submitted for VC approval – '
                    . ($apply_to_all ? 'all semesters' : 'Semester #' . $sf_id)
            );

            flash_set('success',
                'Scholarship <strong>' . h($label) . '</strong> ('
                . $sc_display . ') has been submitted for <strong>VC Approval</strong>. '
                . 'It will be applied to the student\'s account once the Vice Chancellor approves it.'
            );
        }
    }
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
