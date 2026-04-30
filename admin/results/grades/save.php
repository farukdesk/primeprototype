<?php
/**
 * Bulk save grades for one student in a result exam.
 * Called from the "Add Student" tab grade entry form.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(APP_URL . '/results/index.php'); }
csrf_check();

$exam_id      = (int)($_POST['exam_id']      ?? 0);
$student_id   = (int)($_POST['student_id']   ?? 0);
$student_sid  = trim($_POST['student_sid']   ?? '');
$student_name = trim($_POST['student_name']  ?? '');
$marks_post   = (array)($_POST['marks']      ?? []);
$cat_marks_post = (array)($_POST['cat_marks'] ?? []);  // cat_marks[subject_id][category_id]
$marked_by    = trim($_POST['marked_by']     ?? '');
$reviewed_by  = trim($_POST['reviewed_by']   ?? '');
$approved_by  = trim($_POST['approved_by']   ?? '');

if (!$exam_id || (!$student_id && !$student_sid)) {
    flash_set('error', 'Invalid submission.');
    redirect(APP_URL . '/results/index.php');
}

$exam = rm_get_exam($exam_id);
$subjects = rm_get_subjects($exam_id);

// Build subject_id set for security validation
$valid_subject_ids = array_column($subjects, 'id');

// Load all mark categories for this exam (keyed by subject_id)
$all_cats = rm_get_all_mark_categories($exam_id);

$upsert = db()->prepare(
    'INSERT INTO result_grades
       (exam_id, subject_id, student_id, student_sid, student_name, marks, letter_grade, grade_point, marked_by, reviewed_by, approved_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       marks        = VALUES(marks),
       letter_grade = VALUES(letter_grade),
       grade_point  = VALUES(grade_point),
       student_name = VALUES(student_name),
       marked_by    = VALUES(marked_by),
       reviewed_by  = VALUES(reviewed_by),
       approved_by  = VALUES(approved_by)'
);

// If student_id not provided but we have a sid, try to resolve from students table
if ($student_id <= 0 && $student_sid !== '') {
    $lookup = db()->prepare('SELECT id, full_name, student_id FROM students WHERE student_id = ? LIMIT 1');
    $lookup->execute([$student_sid]);
    $found = $lookup->fetch();
    if ($found) {
        $student_id   = (int)$found['id'];
        $student_name = $found['full_name'];
    }
}

$sid_final  = $student_sid ?: (string)$student_id;
$name_final = $student_name;

foreach ($subjects as $subj) {
    $subject_id = (int)$subj['id'];
    if (!in_array($subject_id, $valid_subject_ids, true)) continue;

    $subject_cats = $all_cats[$subject_id] ?? [];

    if (!empty($subject_cats) && isset($cat_marks_post[$subject_id])) {
        // Category-based: compute total from category inputs
        $cat_input = (array)$cat_marks_post[$subject_id];
        $total = 0;
        $any_provided = false;
        foreach ($subject_cats as $cat) {
            $cat_id = (int)$cat['id'];
            if (!isset($cat_input[$cat_id])) continue;
            $val = trim((string)$cat_input[$cat_id]);
            if ($val === '') continue;
            $any_provided = true;
            $obtained = (float)$val;
            $max = (float)$cat['max_marks'];
            if ($obtained < 0) $obtained = 0;
            if ($obtained > $max) $obtained = $max;
            $total += $obtained;
        }

        if (!$any_provided) {
            // All category inputs were blank → delete grade
            db()->prepare(
                'DELETE FROM result_grades
                 WHERE exam_id = ? AND subject_id = ? AND student_sid = ?'
            )->execute([$exam_id, $subject_id, $sid_final]);
            continue;
        }

        if ($total < RM_MARKS_MIN) $total = RM_MARKS_MIN;
        if ($total > RM_MARKS_MAX) $total = RM_MARKS_MAX;
        $grade = rm_compute_grade($total);

        $upsert->execute([
            $exam_id, $subject_id, $student_id ?: null, $sid_final, $name_final,
            $total, $grade['letter'], $grade['point'],
            $marked_by ?: null, $reviewed_by ?: null, $approved_by ?: null,
        ]);
        $grade_id = (int)db()->lastInsertId();
        if (!$grade_id) {
            // Was an UPDATE; fetch the id
            $gid_stmt = db()->prepare(
                'SELECT id FROM result_grades WHERE exam_id=? AND subject_id=? AND student_sid=? LIMIT 1'
            );
            $gid_stmt->execute([$exam_id, $subject_id, $sid_final]);
            $grade_id = (int)($gid_stmt->fetchColumn() ?: 0);
        }

        if ($grade_id) {
            $det_upsert = db()->prepare(
                'INSERT INTO result_grade_details (grade_id, category_id, marks_obtained)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained)'
            );
            foreach ($subject_cats as $cat) {
                $cat_id  = (int)$cat['id'];
                $val     = isset($cat_input[$cat_id]) ? trim((string)$cat_input[$cat_id]) : '';
                $obtained = ($val !== '') ? min(max((float)$val, 0), (float)$cat['max_marks']) : 0;
                $det_upsert->execute([$grade_id, $cat_id, $obtained]);
            }
        }

    } else {
        // No categories: fall back to plain marks input
        $marks_raw = trim((string)($marks_post[$subject_id] ?? ''));

        if ($marks_raw === '') {
            db()->prepare(
                'DELETE FROM result_grades
                 WHERE exam_id = ? AND subject_id = ? AND student_sid = ?'
            )->execute([$exam_id, $subject_id, $sid_final]);
            continue;
        }

        $marks = (float)$marks_raw;
        if ($marks < RM_MARKS_MIN) $marks = RM_MARKS_MIN;
        if ($marks > RM_MARKS_MAX) $marks = RM_MARKS_MAX;

        $grade = rm_compute_grade($marks);

        $upsert->execute([
            $exam_id, $subject_id, $student_id ?: null, $sid_final, $name_final,
            $marks, $grade['letter'], $grade['point'],
            $marked_by ?: null, $reviewed_by ?: null, $approved_by ?: null,
        ]);
    }
}

flash_set('success', 'Grades saved for <strong>' . h($name_final) . '</strong>.');
redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=add_student');
