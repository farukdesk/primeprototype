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

if (!$exam_id || (!$student_id && !$student_sid)) {
    flash_set('error', 'Invalid submission.');
    redirect(APP_URL . '/results/index.php');
}

$exam = rm_get_exam($exam_id);
$subjects = rm_get_subjects($exam_id);

// Build subject_id set for security validation
$valid_subject_ids = array_column($subjects, 'id');

$upsert = db()->prepare(
    'INSERT INTO result_grades
       (exam_id, subject_id, student_id, student_sid, student_name, marks, letter_grade, grade_point)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       marks        = VALUES(marks),
       letter_grade = VALUES(letter_grade),
       grade_point  = VALUES(grade_point),
       student_name = VALUES(student_name)'
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

foreach ($marks_post as $subject_id => $marks_raw) {
    $subject_id = (int)$subject_id;
    if (!in_array($subject_id, $valid_subject_ids, true)) continue;

    $marks_raw = trim((string)$marks_raw);
    if ($marks_raw === '') {
        // Delete existing grade if marks cleared
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
        $exam_id,
        $subject_id,
        $student_id ?: null,
        $sid_final,
        $name_final,
        $marks,
        $grade['letter'],
        $grade['point'],
    ]);
}

flash_set('success', 'Grades saved for <strong>' . h($name_final) . '</strong>.');
redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=add_student');
