<?php
/**
 * Smart Bulk Upload – Save parsed grade data into the database  (AJAX endpoint)
 *
 * POST fields (application/x-www-form-urlencoded or multipart/form-data):
 *   exam_id          – target result exam
 *   students_json    – JSON-encoded array of student/grade objects from the parser
 *   create_subjects  – '1' to auto-create missing subjects, '0' to skip
 *   overwrite        – '1' to overwrite existing grades, '0' to skip duplicates
 *   _csrf_token
 *
 * Returns JSON:
 *   { saved, skipped, created_subjects, errors[], redirect }
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('results', 'can_create');
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

csrf_check();

// ── Input ─────────────────────────────────────────────────────────────────────

$exam_id         = (int)($_POST['exam_id']      ?? 0);
$create_subjects = ($_POST['create_subjects'] ?? '0') === '1';
$overwrite       = ($_POST['overwrite']       ?? '0') === '1';
$students_raw    = trim((string)($_POST['students_json'] ?? ''));

if (!$exam_id || $students_raw === '') {
    echo json_encode(['error' => 'Missing exam_id or students data.']);
    exit;
}

$students_data = json_decode($students_raw, true);
if (!is_array($students_data) || empty($students_data)) {
    echo json_encode(['error' => 'Invalid or empty students JSON.']);
    exit;
}

$exam = rm_get_exam($exam_id);   // redirects on not-found; we are JSON here but that's fine in error cases

// ── Pre-load existing subjects keyed by upper-case course code ────────────────

$subj_stmt = db()->prepare(
    'SELECT id, course_code, course_title FROM result_subjects WHERE exam_id = ?'
);
$subj_stmt->execute([$exam_id]);
$subjects_by_code = [];     // UPPER(course_code) → row
$subjects_by_id   = [];     // id → row
foreach ($subj_stmt->fetchAll() as $row) {
    $subjects_by_id[(int)$row['id']] = $row;
    if ($row['course_code'] !== null && $row['course_code'] !== '') {
        $subjects_by_code[strtoupper($row['course_code'])] = $row;
    }
}

// ── Pull course info from curriculum for auto-fill when creating subjects ─────

$curriculum_map = [];   // UPPER(course_code) → [ course_name, credit ]
if ($create_subjects) {
    $cc_rows = db()->query(
        'SELECT course_code, course_name, credit FROM course_curriculum
          WHERE course_code IS NOT NULL AND course_code != ""'
    )->fetchAll();
    foreach ($cc_rows as $cc) {
        $curriculum_map[strtoupper((string)$cc['course_code'])] = $cc;
    }
}

// ── Prepared statements ───────────────────────────────────────────────────────

$stmt_insert_subj = db()->prepare(
    'INSERT INTO result_subjects (exam_id, course_code, course_title, credits, sort_order)
     VALUES (?, ?, ?, ?, ?)'
);

$stmt_find_subj = db()->prepare(
    'SELECT id FROM result_subjects WHERE exam_id = ? AND course_code = ? LIMIT 1'
);

$stmt_check_grade = db()->prepare(
    'SELECT id FROM result_grades
      WHERE exam_id = ? AND subject_id = ? AND student_sid = ? LIMIT 1'
);

$stmt_upsert_grade = db()->prepare(
    'INSERT INTO result_grades
       (exam_id, subject_id, student_id, student_sid, student_name, marks, letter_grade, grade_point)
     VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
     ON DUPLICATE KEY UPDATE
       letter_grade = VALUES(letter_grade),
       grade_point  = VALUES(grade_point),
       student_name = VALUES(student_name)'
);

$stmt_lookup_student = db()->prepare(
    'SELECT id, full_name FROM students WHERE student_id = ? LIMIT 1'
);

// ── Process each student ──────────────────────────────────────────────────────

$saved            = 0;
$skipped          = 0;
$created_subjects = 0;
$errors           = [];

// Sort order counter starts after existing subjects
$sort_seq = (count($subjects_by_code) + 1) * 10;

foreach ($students_data as $stu) {
    $sid       = trim((string)($stu['sid']  ?? ''));
    $ocr_name  = trim((string)($stu['name'] ?? ''));
    $grades    = is_array($stu['grades'] ?? null) ? $stu['grades'] : [];

    if ($sid === '' || empty($grades)) continue;

    // Resolve student from the students table
    $stmt_lookup_student->execute([$sid]);
    $db_stu       = $stmt_lookup_student->fetch();
    $student_pk   = $db_stu ? (int)$db_stu['id'] : null;
    $student_name = $db_stu ? (string)$db_stu['full_name'] : $ocr_name;

    foreach ($grades as $g) {
        $code   = isset($g['code'])   ? trim((string)$g['code'])   : null;
        $title  = isset($g['title'])  ? trim((string)$g['title'])  : null;
        $letter = strtoupper(trim((string)($g['letter'] ?? '')));
        $gp     = isset($g['gp'])     ? (float)$g['gp']            : null;

        if ($letter === '' || $gp === null) { $skipped++; continue; }

        // ── Find or create the subject ────────────────────────────────────────
        $subject_id = null;
        $code_upper = $code !== null ? strtoupper($code) : null;

        if ($code_upper !== null && isset($subjects_by_code[$code_upper])) {
            $subject_id = (int)$subjects_by_code[$code_upper]['id'];
        } elseif ($create_subjects) {
            // Determine the best title to use
            $use_title = $title;
            if (($use_title === null || $use_title === '') && $code_upper !== null && isset($curriculum_map[$code_upper])) {
                $use_title = (string)$curriculum_map[$code_upper]['course_name'];
            }
            if ($use_title === null || $use_title === '') {
                $use_title = $code ?? 'Unknown Subject';
            }

            $credit = null;
            if ($code_upper !== null && isset($curriculum_map[$code_upper])) {
                $credit = $curriculum_map[$code_upper]['credit'];
            }

            // Check again in case another iteration already created it
            $stmt_find_subj->execute([$exam_id, $code]);
            $existing_subj = $stmt_find_subj->fetch();

            if ($existing_subj) {
                $subject_id = (int)$existing_subj['id'];
                // Also cache it
                if ($code_upper !== null) {
                    $subjects_by_code[$code_upper] = ['id' => $subject_id, 'course_code' => $code, 'course_title' => $use_title];
                }
            } else {
                try {
                    $stmt_insert_subj->execute([$exam_id, $code, $use_title, $credit, $sort_seq]);
                    $sort_seq += 10;
                    $new_id = (int)db()->lastInsertId();
                    if ($new_id > 0) {
                        if ($code_upper !== null) {
                            $subjects_by_code[$code_upper] = ['id' => $new_id, 'course_code' => $code, 'course_title' => $use_title];
                        }
                        $subject_id = $new_id;
                        $created_subjects++;
                    }
                } catch (PDOException $ex) {
                    $label = $code ?? $title ?? '?';
                    $errors[] = "Could not create subject '$label': " . $ex->getMessage();
                    $skipped++;
                    continue;
                }
            }
        } else {
            // Subject not found and auto-create is disabled
            $label = $code ?? $title ?? '?';
            if (!in_array("Subject '$label' not found in this exam.", $errors, true)) {
                $errors[] = "Subject '$label' not found in this exam. Enable 'Create missing subjects' to add it automatically.";
            }
            $skipped++;
            continue;
        }

        if (!$subject_id) { $skipped++; continue; }

        // ── Skip existing grade if overwrite is off ───────────────────────────
        if (!$overwrite) {
            $stmt_check_grade->execute([$exam_id, $subject_id, $sid]);
            if ($stmt_check_grade->fetch()) { $skipped++; continue; }
        }

        // ── Upsert grade ──────────────────────────────────────────────────────
        try {
            $stmt_upsert_grade->execute([
                $exam_id,
                $subject_id,
                $student_pk,
                $sid,
                $student_name,
                $letter,
                $gp,
            ]);
            $saved++;
        } catch (PDOException $ex) {
            $errors[] = "Error saving grade for $sid / " . ($code ?? '?') . ': ' . $ex->getMessage();
            $skipped++;
        }
    }
}

    echo json_encode([
    'saved'            => $saved,
    'skipped'          => $skipped,
    'created_subjects' => $created_subjects,
    'errors'           => array_values(array_slice($errors, 0, 30)),  // capped at 30 to keep response size reasonable
    'redirect'         => APP_URL . '/results/view.php?id=' . $exam_id,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
