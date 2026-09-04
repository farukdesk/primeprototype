<?php
/**
 * AJAX: Does the CURRENT USER already have an editable (draft/returned) mark
 * sheet for this exam + offered subject?
 *
 * Used by mark-entry.php (new-sheet mode) to ask the teacher whether they want
 * to continue editing the existing draft instead of creating a duplicate.
 *
 * GET params:
 *   exam_id           (int, required)
 *   offer_subject_id  (int, required)
 *
 * Returns JSON:
 *   { exists:false }
 *   { exists:true, id, subject_code, subject_title, semester, status,
 *     student_count, updated_at }   // updated_at pre-formatted for display
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');

$exam_id  = (int)($_GET['exam_id'] ?? 0);
$offer_id = (int)($_GET['offer_subject_id'] ?? 0);
$user_id  = (int)(auth_user()['id'] ?? 0);

if ($exam_id <= 0 || $offer_id <= 0 || $user_id <= 0) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $st = db()->prepare(
        "SELECT ms.id, ms.subject_code, ms.subject_title, ms.semester,
                ms.workflow_status, ms.updated_at,
                (SELECT COUNT(*) FROM result_sheet_grades g WHERE g.sheet_id = ms.id) AS student_count
           FROM result_mark_sheets ms
          WHERE ms.created_by = ?
            AND ms.exam_id = ?
            AND ms.offer_subject_id = ?
            AND ms.workflow_status IN ('draft', 'returned')
          ORDER BY ms.updated_at DESC
          LIMIT 1"
    );
    $st->execute([$user_id, $exam_id, $offer_id]);
    $row = $st->fetch();

    if (!$row) {
        echo json_encode(['exists' => false]);
        exit;
    }

    $saved_ts = strtotime((string)$row['updated_at']) ?: null;

    echo json_encode([
        'exists'        => true,
        'id'            => (int)$row['id'],
        'subject_code'  => (string)($row['subject_code'] ?? ''),
        'subject_title' => (string)($row['subject_title'] ?? ''),
        'semester'      => (string)($row['semester'] ?? ''),
        'status'        => (string)$row['workflow_status'],
        'student_count' => (int)$row['student_count'],
        'updated_at'    => $saved_ts ? date('d M Y, h:i A', $saved_ts) : '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['exists' => false]);
}
