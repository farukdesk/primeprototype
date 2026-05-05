<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

$result_id = (int)($_POST['result_id'] ?? 0);
$entry_id  = (int)($_POST['entry_id']  ?? 0);

// Require create or edit permission
if ($entry_id > 0) {
    require_access('spring-result', 'can_edit');
} else {
    require_access('spring-result', 'can_create');
}

csrf_check();

// Validate result
$result = sr_get_result($result_id);

$student_id   = trim($_POST['student_id']   ?? '');
$student_name = trim($_POST['student_name'] ?? '');
$course_code  = trim($_POST['course_code']  ?? '');
$course_title = trim($_POST['course_title'] ?? '');
$letter_grade = strtoupper(trim($_POST['letter_grade'] ?? ''));
$grade_point  = trim($_POST['grade_point']  ?? '');

$errors = [];

if ($student_id   === '') $errors[] = 'Student ID is required.';
if ($course_title === '') $errors[] = 'Course Title is required.';
if (!sr_valid_letter_grade($letter_grade)) $errors[] = 'Invalid letter grade.';

if (!empty($errors)) {
    flash_set('error', implode(' ', $errors));
    redirect(APP_URL . '/spring-result/view.php?id=' . $result_id);
}

// Auto-compute grade point if not provided or invalid
$gp_float = ($grade_point !== '') ? (float)$grade_point : null;
if ($gp_float === null) {
    $gp_float = sr_grade_point_from_letter($letter_grade);
}

if ($entry_id > 0) {
    // Update
    $stmt = db()->prepare(
        'UPDATE sr_result_entries
         SET student_id=?, student_name=?, course_code=?, course_title=?, letter_grade=?, grade_point=?
         WHERE id=? AND result_id=?'
    );
    $stmt->execute([
        $student_id,
        $student_name ?: null,
        $course_code  ?: null,
        $course_title,
        $letter_grade,
        $gp_float,
        $entry_id,
        $result_id,
    ]);
    flash_set('success', 'Entry updated successfully.');
} else {
    // Insert
    $stmt = db()->prepare(
        'INSERT INTO sr_result_entries (result_id, student_id, student_name, course_code, course_title, letter_grade, grade_point)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $result_id,
        $student_id,
        $student_name ?: null,
        $course_code  ?: null,
        $course_title,
        $letter_grade,
        $gp_float,
    ]);
    flash_set('success', 'Entry added successfully.');
}

redirect(APP_URL . '/spring-result/view.php?id=' . $result_id);
