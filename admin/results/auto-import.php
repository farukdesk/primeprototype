<?php
/**
 * Results – Auto Bulk Import  (AJAX endpoint)
 *
 * Receives OCR-parsed student/grade data, auto-detects department, program,
 * and batch from the students table, creates a new result_exam, and saves
 * all grades.  No manual selection required.
 *
 * POST fields:
 *   students_json    – JSON array produced by bulk-upload-parse.php
 *   exam_title       – optional; auto-generated if empty
 *   create_subjects  – '1' to auto-create missing subjects (default: '1')
 *   overwrite        – '1' to overwrite existing grades (default: '1')
 *   _csrf_token
 *
 * Returns JSON:
 *   { exam_id, exam_title, dept_name, batch, saved, skipped,
 *     created_subjects, errors[], redirect }
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

$students_raw    = trim((string)($_POST['students_json'] ?? ''));
$create_subjects = ($_POST['create_subjects'] ?? '1') === '1';
$overwrite       = ($_POST['overwrite']       ?? '1') === '1';
$exam_title_in   = trim((string)($_POST['exam_title'] ?? ''));

if ($students_raw === '') {
    echo json_encode(['error' => 'No student data received.']);
    exit;
}

$students_data = json_decode($students_raw, true);
if (!is_array($students_data) || empty($students_data)) {
    echo json_encode(['error' => 'Invalid or empty students JSON.']);
    exit;
}

// ── Step 1: Collect student IDs and look them up in the DB ────────────────────

$db = db();

$sid_list = array_values(array_filter(
    array_map(fn($s) => trim((string)($s['sid'] ?? '')), $students_data)
));

if (empty($sid_list)) {
    echo json_encode(['error' => 'No valid student IDs found in the parsed data.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($sid_list), '?'));
$lookup_stmt  = $db->prepare(
    "SELECT student_id, dept_id, program_id, batch
     FROM students
     WHERE student_id IN ($placeholders)"
);
$lookup_stmt->execute($sid_list);

$student_index = [];   // student_id => row
foreach ($lookup_stmt->fetchAll() as $r) {
    $student_index[$r['student_id']] = $r;
}

// ── Step 2: Tally dept / program / batch occurrences ─────────────────────────

$dept_counts    = [];
$program_counts = [];
$batch_counts   = [];

foreach ($sid_list as $sid) {
    if (isset($student_index[$sid])) {
        $r = $student_index[$sid];
        $d = (int)$r['dept_id'];
        $p = (int)($r['program_id'] ?? 0);
        $b = trim((string)($r['batch'] ?? ''));

        $dept_counts[$d]    = ($dept_counts[$d]    ?? 0) + 1;
        if ($p > 0) $program_counts[$p] = ($program_counts[$p] ?? 0) + 1;
        if ($b !== '') $batch_counts[$b] = ($batch_counts[$b]  ?? 0) + 1;
    }
}

// Fallback: extract dept from student ID encoding [YY][SS][DD][PP][NNNN]
if (empty($dept_counts)) {
    foreach ($sid_list as $sid) {
        if (strlen($sid) >= 8) {
            $d = (int)substr($sid, 4, 2);
            if ($d > 0) $dept_counts[$d] = ($dept_counts[$d] ?? 0) + 1;
        }
    }
}

if (empty($dept_counts)) {
    echo json_encode(['error' => 'Could not determine department. Make sure the students exist in the database.']);
    exit;
}

arsort($dept_counts);
arsort($program_counts);
arsort($batch_counts);

$dept_id    = (int)array_key_first($dept_counts);
$program_id = empty($program_counts) ? 0 : (int)array_key_first($program_counts);
$batch      = empty($batch_counts)   ? null : (string)array_key_first($batch_counts);

// ── Step 3: Validate dept is within the user's scope ─────────────────────────

$dept_scope = get_dept_scope();
if ($dept_scope !== null && !in_array($dept_id, $dept_scope, true)) {
    echo json_encode(['error' => 'You do not have access to the auto-detected department.']);
    exit;
}

// ── Step 4: Resolve dept name ─────────────────────────────────────────────────

$dept_stmt = $db->prepare('SELECT name FROM dept_departments WHERE id = ?');
$dept_stmt->execute([$dept_id]);
$dept_row = $dept_stmt->fetch();
if (!$dept_row) {
    echo json_encode(['error' => "Department ID $dept_id not found."]);
    exit;
}
$dept_name = (string)$dept_row['name'];

// ── Step 5: Create the result_exam ────────────────────────────────────────────

$exam_title = $exam_title_in !== ''
    ? $exam_title_in
    : $dept_name . ' – Bulk Import ' . date('d M Y, H:i');

$user = auth_user();

$ins = $db->prepare(
    'INSERT INTO result_exams
       (dept_id, program_id, batch, exam_title, is_published, created_by)
     VALUES (?, ?, ?, ?, 0, ?)'
);
$ins->execute([
    $dept_id,
    $program_id ?: null,
    $batch,
    $exam_title,
    $user['id'] ?? null,
]);
$exam_id = (int)$db->lastInsertId();

if (!$exam_id) {
    echo json_encode(['error' => 'Failed to create result exam record.']);
    exit;
}

// ── Step 6: Save grades (mirrors logic in bulk-upload-save.php) ───────────────

// Curriculum map for subject auto-fill
$curriculum_map = [];
if ($create_subjects) {
    $cc_rows = $db->query(
        'SELECT course_code, course_name, credit FROM course_curriculum
          WHERE course_code IS NOT NULL AND course_code != ""'
    )->fetchAll();
    foreach ($cc_rows as $cc) {
        $curriculum_map[strtoupper((string)$cc['course_code'])] = $cc;
    }
}

$subjects_by_code = [];
$sort_seq         = 10;

$stmt_insert_subj = $db->prepare(
    'INSERT INTO result_subjects (exam_id, course_code, course_title, credits, sort_order)
     VALUES (?, ?, ?, ?, ?)'
);
$stmt_find_subj = $db->prepare(
    'SELECT id FROM result_subjects WHERE exam_id = ? AND course_code = ? LIMIT 1'
);
$stmt_check_grade = $db->prepare(
    'SELECT id FROM result_grades
      WHERE exam_id = ? AND subject_id = ? AND student_sid = ? LIMIT 1'
);
$stmt_upsert_grade = $db->prepare(
    'INSERT INTO result_grades
       (exam_id, subject_id, student_id, student_sid, student_name, marks, letter_grade, grade_point)
     VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
     ON DUPLICATE KEY UPDATE
       letter_grade = VALUES(letter_grade),
       grade_point  = VALUES(grade_point),
       student_name = VALUES(student_name)'
);
$stmt_lookup_student = $db->prepare(
    'SELECT id, full_name FROM students WHERE student_id = ? LIMIT 1'
);

$saved            = 0;
$skipped          = 0;
$created_subjects = 0;
$errors           = [];

foreach ($students_data as $stu) {
    $sid      = trim((string)($stu['sid']  ?? ''));
    $ocr_name = trim((string)($stu['name'] ?? ''));
    $grades   = is_array($stu['grades'] ?? null) ? $stu['grades'] : [];

    if ($sid === '' || empty($grades)) continue;

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

        $subject_id = null;
        $code_upper = $code !== null ? strtoupper($code) : null;

        if ($code_upper !== null && isset($subjects_by_code[$code_upper])) {
            $subject_id = (int)$subjects_by_code[$code_upper];
        } elseif ($create_subjects) {
            $use_title = $title;
            if (($use_title === null || $use_title === '') && $code_upper !== null && isset($curriculum_map[$code_upper])) {
                $use_title = (string)$curriculum_map[$code_upper]['course_name'];
            }
            if ($use_title === null || $use_title === '') {
                $use_title = $code ?? 'Unknown Subject';
            }

            $credit = ($code_upper !== null && isset($curriculum_map[$code_upper]))
                    ? $curriculum_map[$code_upper]['credit']
                    : null;

            $stmt_find_subj->execute([$exam_id, $code]);
            $existing_subj = $stmt_find_subj->fetch();

            if ($existing_subj) {
                $subject_id = (int)$existing_subj['id'];
                if ($code_upper !== null) {
                    $subjects_by_code[$code_upper] = $subject_id;
                }
            } else {
                try {
                    $stmt_insert_subj->execute([$exam_id, $code, $use_title, $credit, $sort_seq]);
                    $sort_seq += 10;
                    $new_id = (int)$db->lastInsertId();
                    if ($new_id > 0) {
                        if ($code_upper !== null) {
                            $subjects_by_code[$code_upper] = $new_id;
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
            $label = $code ?? $title ?? '?';
            if (!in_array("Subject '$label' not found.", $errors, true)) {
                $errors[] = "Subject '$label' not found.";
            }
            $skipped++;
            continue;
        }

        if (!$subject_id) { $skipped++; continue; }

        if (!$overwrite) {
            $stmt_check_grade->execute([$exam_id, $subject_id, $sid]);
            if ($stmt_check_grade->fetch()) { $skipped++; continue; }
        }

        try {
            $stmt_upsert_grade->execute([
                $exam_id, $subject_id, $student_pk, $sid, $student_name, $letter, $gp,
            ]);
            $saved++;
        } catch (PDOException $ex) {
            $errors[] = 'Error saving grade for ' . $sid . ' / ' . ($code ?? '?') . ': ' . $ex->getMessage();
            $skipped++;
        }
    }
}

echo json_encode([
    'exam_id'          => $exam_id,
    'exam_title'       => $exam_title,
    'dept_name'        => $dept_name,
    'batch'            => $batch,
    'saved'            => $saved,
    'skipped'          => $skipped,
    'created_subjects' => $created_subjects,
    'errors'           => array_values(array_slice($errors, 0, 30)),
    'redirect'         => APP_URL . '/results/view.php?id=' . $exam_id,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
