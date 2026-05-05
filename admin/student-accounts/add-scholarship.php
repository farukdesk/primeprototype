<?php
/**
 * Add a new scholarship entry to a single semester fee row (or all semesters).
 * A semester can hold multiple named scholarships; totals are recalculated
 * and written back into sfp_semester_fees after every change.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$package_id   = (int)($_POST['package_id']   ?? 0);
$sf_id        = (int)($_POST['sf_id']        ?? 0);
$label        = trim($_POST['sc_label']      ?? '');
$discount_pct = (float)($_POST['discount_pct'] ?? 0);
$sc_note      = trim($_POST['sc_note']       ?? '');
$apply_to_all = !empty($_POST['apply_to_all']);

$errors = [];

if ($package_id <= 0) $errors[] = 'Invalid package.';
if ($label === '') $errors[] = 'Scholarship label is required.';
if ($discount_pct < 0.01 || $discount_pct > 100) $errors[] = 'Discount must be between 0.01 and 100.';
if (!$apply_to_all && $sf_id <= 0) $errors[] = 'Invalid semester row.';

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
                       (sf_id, label, discount_pct, amount, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $row_id,
                    $label,
                    round($discount_pct, 2),
                    $amount,
                    $sc_note ?: null,
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
                   (sf_id, label, discount_pct, amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $sf_id,
                $label,
                round($discount_pct, 2),
                $amount,
                $sc_note ?: null,
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
