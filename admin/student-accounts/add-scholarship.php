<?php
/**
 * Add a new scholarship entry to a single semester fee row (or all semesters).
 * A semester can hold multiple named scholarships; totals are recalculated
 * and written back into sfp_semester_fees after every change.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../students/helpers.php';  // sm_upload_file()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$package_id      = (int)($_POST['package_id']      ?? 0);
$sf_id           = (int)($_POST['sf_id']           ?? 0);
$label           = trim($_POST['sc_label']         ?? '');
$discount_pct    = (float)($_POST['discount_pct']  ?? 0);
$sc_note         = trim($_POST['sc_note']          ?? '');
$apply_to_all    = !empty($_POST['apply_to_all']);
$is_from_policy  = !empty($_POST['is_from_policy']) ? 1 : 0;
$applies_to_fixed   = !empty($_POST['applies_to_fixed'])   ? 1 : 0;
$applies_to_english = !empty($_POST['applies_to_english']) ? 1 : 0;

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($label === '') $errors[] = 'Scholarship label is required.';
if ($discount_pct < 0.01 || $discount_pct > 100) $errors[] = 'Discount must be between 0.01 and 100.';
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

    if ($apply_to_all) {
        // Apply to every semester in the package
        $sf_rows = sfp_get_semester_fees($package_id);
        if (empty($sf_rows)) {
            $errors[] = 'No semester fee rows found for this package.';
        } else {
            $count = 0;
            foreach ($sf_rows as $sf) {
                $row_id          = (int)$sf['id'];
                $tuition_payable = (float)$sf['tuition_payable'];
                $amount          = round($tuition_payable * $discount_pct / 100, 2);

                db()->prepare(
                    'INSERT INTO sfp_semester_scholarships
                       (sf_id, label, discount_pct, amount, note,
                        is_from_policy, applies_to_fixed, applies_to_english,
                        support_doc_id, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $row_id,
                    $label,
                    round($discount_pct, 2),
                    $amount,
                    $sc_note ?: null,
                    $is_from_policy,
                    $applies_to_fixed,
                    $applies_to_english,
                    $support_doc_id,
                    $user['id'],
                ]);

                sfp_recalculate_semester($row_id, $user['id']);
                $count++;
            }

            log_change(
                'student-accounts', 'UPDATE', $package_id,
                'All Semesters',
                'scholarship_added_all',
                null,
                $label . ' (' . number_format($discount_pct, 2) . '%) – all ' . $count . ' semesters',
                'Scholarship "' . $label . '" (' . number_format($discount_pct, 2) . '%) applied to all ' . $count . ' semesters'
            );

            flash_set('success',
                'Scholarship <strong>' . h($label) . '</strong> ('
                . number_format($discount_pct, 2) . '%) applied to all '
                . $count . ' semester' . ($count !== 1 ? 's' : '') . '.'
            );
        }
    } else {
        // Verify the semester row belongs to this package
        $sf_stmt = db()->prepare('SELECT * FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
        $sf_stmt->execute([$sf_id, $package_id]);
        $sf = $sf_stmt->fetch();

        if (!$sf) {
            $errors[] = 'Semester fee record not found.';
        } else {
            // Apply discount to the *remaining* payable balance, not the original tuition fee.
            // sfp_recalculate_semester() will also recompute cascaded amounts for all rows.
            $tuition_payable = (float)$sf['tuition_payable'];
            $amount          = round($tuition_payable * $discount_pct / 100, 2);

            db()->prepare(
                'INSERT INTO sfp_semester_scholarships
                   (sf_id, label, discount_pct, amount, note,
                    is_from_policy, applies_to_fixed, applies_to_english,
                    support_doc_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sf_id,
                $label,
                round($discount_pct, 2),
                $amount,
                $sc_note ?: null,
                $is_from_policy,
                $applies_to_fixed,
                $applies_to_english,
                $support_doc_id,
                $user['id'],
            ]);

            // Recalculate totals in the semester row
            sfp_recalculate_semester($sf_id, $user['id']);

            log_change(
                'student-accounts', 'UPDATE', $package_id,
                'Semester #' . $sf['semester_number'],
                'scholarship_added',
                null,
                $label . ' (' . number_format($discount_pct, 2) . '%)',
                'Scholarship "' . $label . '" (' . number_format($discount_pct, 2) . '%) added to semester #' . $sf['semester_number']
            );

            flash_set('success',
                'Scholarship <strong>' . h($label) . '</strong> ('
                . number_format($discount_pct, 2) . '%) added to Semester #'
                . $sf['semester_number'] . '.'
            );
        }
    }
}

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
}

redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
