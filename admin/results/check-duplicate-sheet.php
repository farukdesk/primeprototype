<?php
/**
 * AJAX: Check how much of a batch has already been marked for an exam+subject.
 *
 * Multiple teachers can be assigned to one subject; each student's marks for a
 * given exam may only be entered once. So:
 *   - duplicate = true  -> EVERY active registered student is already marked in
 *                          a submitted (pending) or published sheet - block entry.
 *   - partial   = true  -> some students are marked; the remaining students can
 *                          still be marked by another teacher (marked rows are
 *                          locked in the UI, entry stays enabled).
 * A sheet that was *returned* to the teacher does not count (it can be revised
 * and re-submitted).
 *
 * GET params:
 *   exam_id           (int, required)
 *   offer_subject_id  (int, required)
 *   exclude_id        (int, optional) - the sheet currently being edited
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');

$exam_id          = (int)($_GET['exam_id'] ?? 0);
$offer_subject_id = (int)($_GET['offer_subject_id'] ?? 0);
$exclude_id       = (int)($_GET['exclude_id'] ?? 0);

if ($exam_id <= 0 || $offer_subject_id <= 0) {
    echo json_encode(['duplicate' => false, 'partial' => false]);
    exit;
}

try {
    // Students already marked (grade rows that actually carry marks / absence)
    $mq = db()->prepare(
        "SELECT DISTINCT g.student_sid, u.full_name AS marked_by
           FROM result_mark_sheets ms
           JOIN result_sheet_grades g ON g.sheet_id = ms.id
           LEFT JOIN users u ON u.id = ms.created_by
          WHERE ms.exam_id = ? AND ms.offer_subject_id = ?
            AND ms.workflow_status IN ('pending', 'published')
            AND ms.id <> ?
            AND (g.marks_json IS NOT NULL OR g.is_absent = 1)"
    );
    $mq->execute([$exam_id, $offer_subject_id, $exclude_id]);

    $marked_sids = [];
    $names       = [];
    foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $sid = trim((string)$m['student_sid']);
        if ($sid !== '') $marked_sids[$sid] = true;
        $n = trim((string)($m['marked_by'] ?? ''));
        if ($n !== '') $names[$n] = true;
    }
    $marked_count = count($marked_sids);

    if ($marked_count === 0) {
        echo json_encode(['duplicate' => false, 'partial' => false]);
        exit;
    }

    // Active registered students of the offered course
    $tq = db()->prepare(
        "SELECT COUNT(*) FROM co_registrations r
           JOIN students s ON s.id = r.student_id
          WHERE r.offer_subject_id = ? AND s.status = 'Active'"
    );
    $tq->execute([$offer_subject_id]);
    $total_registered = (int)$tq->fetchColumn();

    // All students covered (or roster unknown) -> hard duplicate; otherwise partial
    $all_marked = ($total_registered === 0) || ($marked_count >= $total_registered);

    echo json_encode([
        'duplicate'        => $all_marked,
        'partial'          => !$all_marked,
        'marked_count'     => $marked_count,
        'total_registered' => $total_registered,
        'marked_by'        => array_keys($names),
    ]);
} catch (Throwable $e) {
    echo json_encode(['duplicate' => false, 'partial' => false]);
}
