<?php
/**
 * AJAX: return marking distributions for a course_curriculum subject.
 * Returns entries from cc_mark_distributions ordered by sort_order.
 * If no distributions are configured for the curriculum, returns [].
 *
 * GET params:
 *   curriculum_id  (int, required)
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();

header('Content-Type: application/json');

$curriculum_id = (int)($_GET['curriculum_id'] ?? 0);
if ($curriculum_id <= 0) { echo '[]'; exit; }

try {
    $stmt = db()->prepare(
        'SELECT distribution_name, max_marks, sort_order
         FROM cc_mark_distributions
         WHERE curriculum_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$curriculum_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $_e) {
    // Table may not exist yet
    echo '[]';
}
