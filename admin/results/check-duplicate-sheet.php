<?php
/**
 * AJAX: Check whether a mark sheet for the same exam + offered subject has
 * already been submitted (pending review) or published.
 *
 * Used by mark-entry.php to warn the teacher before they enter marks for a
 * batch/exam that has already been marked. A sheet that was *returned* to the
 * teacher does not count as a duplicate (it can be revised and re-submitted).
 *
 * GET params:
 *   exam_id           (int, required)
 *   offer_subject_id  (int, required)
 *   exclude_id        (int, optional) — the sheet currently being edited
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');

$exam_id          = (int)($_GET['exam_id'] ?? 0);
$offer_subject_id = (int)($_GET['offer_subject_id'] ?? 0);
$exclude_id       = (int)($_GET['exclude_id'] ?? 0);

if ($exam_id <= 0 || $offer_subject_id <= 0) {
    echo json_encode(['duplicate' => false]);
    exit;
}

try {
    $stmt = db()->prepare(
        "SELECT id, workflow_status, subject_title, semester
           FROM result_mark_sheets
          WHERE exam_id = ? AND offer_subject_id = ?
            AND workflow_status IN ('pending', 'published')
            AND id <> ?
          LIMIT 1"
    );
    $stmt->execute([$exam_id, $offer_subject_id, $exclude_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['duplicate' => false]);
        exit;
    }

    echo json_encode([
        'duplicate'       => true,
        'workflow_status' => $row['workflow_status'],
        'subject_title'   => $row['subject_title'],
        'semester'        => $row['semester'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['duplicate' => false]);
}
