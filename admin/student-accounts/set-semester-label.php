<?php
/**
 * Set a human-readable label on a single semester fee row (e.g. "Summer 2026").
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('student-accounts', 'can_edit');
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/student-accounts/index.php');
}

csrf_check();

$sf_id          = (int)($_POST['sf_id']         ?? 0);
$package_id     = (int)($_POST['package_id']    ?? 0);
$semester_label = trim($_POST['semester_label'] ?? '');
$auto_fill      = isset($_POST['auto_fill']) && $_POST['auto_fill'] === '1';

if ($sf_id > 0 && $package_id > 0) {
    $sf_stmt = db()->prepare('SELECT id, semester_number, semester_label FROM sfp_semester_fees WHERE id = ? AND package_id = ?');
    $sf_stmt->execute([$sf_id, $package_id]);
    $sf = $sf_stmt->fetch();

    if ($sf) {
        $user = auth_user();
        $old_label = $sf['semester_label'] ?? null;
        
        // Update the current semester label
        db()->prepare(
            'UPDATE sfp_semester_fees SET semester_label = ?, updated_by = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$semester_label ?: null, $user['id'], $sf_id]);

        // If auto_fill is enabled and this is the first semester with a label, auto-generate others
        if ($auto_fill && $semester_label && (int)$sf['semester_number'] === 1) {
            // Get the student's semester type and total semesters
            $pkg_stmt = db()->prepare(
                'SELECT p.id, s.semester_type, p.total_semesters 
                 FROM sfp_packages p
                 JOIN students s ON s.id = p.student_id
                 WHERE p.id = ?'
            );
            $pkg_stmt->execute([$package_id]);
            $pkg = $pkg_stmt->fetch();
            
            if ($pkg && $pkg['semester_type'] && $pkg['total_semesters']) {
                // Generate semester names
                $semester_names = sfp_generate_semester_names(
                    $pkg['semester_type'],
                    $semester_label,
                    (int)$pkg['total_semesters']
                );
                
                // Update all semester labels (skip the first one as it's already updated)
                $update_stmt = db()->prepare(
                    'UPDATE sfp_semester_fees SET semester_label = ?, updated_by = ?, updated_at = NOW() 
                     WHERE package_id = ? AND semester_number = ?'
                );
                
                for ($i = 1; $i < count($semester_names); $i++) {
                    $sem_num = $i + 1; // semester_number starts at 1
                    $update_stmt->execute([$semester_names[$i], $user['id'], $package_id, $sem_num]);
                }
            }
        }

        log_change(
            'student-accounts', 'UPDATE', $package_id,
            'Semester #' . $sf['semester_number'],
            'semester_label',
            $old_label,
            $semester_label ?: null,
            'Semester #' . $sf['semester_number'] . ' label ' . ($semester_label ? 'set to "' . $semester_label . '"' : 'cleared')
        );

        flash_set('success', 'Label updated for Semester #' . $sf['semester_number'] . '.');
    } else {
        flash_set('error', 'Semester fee record not found.');
    }
}

redirect(APP_URL . '/student-accounts/view.php?id=' . $package_id);
