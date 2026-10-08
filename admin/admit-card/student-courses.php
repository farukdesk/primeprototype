<?php
/**
 * Admit Card – AJAX fragment: courses printed on ONE student's admit card,
 * each traced back to the course offer it comes from.
 *
 * The PDF course list is resolved from the student's course-offer
 * registrations (ac_get_merged_courses_for_student), so a card that lists
 * 3 courses can print 5 for a student — the extras come from OTHER offers
 * (retakes, service courses, sibling cards). This fragment shows exactly
 * which offer each printed course belongs to, linked to that offer's
 * registrations page.
 *
 * GET params:
 *   card    (int, required) – admit card id
 *   student (int, required) – student id
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

if (!ac_can_view()) {
    http_response_code(403);
    exit('Access denied.');
}

$card_id    = (int)($_GET['card']    ?? 0);
$student_id = (int)($_GET['student'] ?? 0);

$card = $card_id > 0 ? ac_get_card($card_id) : null;
if (!$card || $student_id <= 0) {
    http_response_code(404);
    exit('Not found.');
}
if (!ac_can_access_card_dept((int)$card['dept_id'])) {
    http_response_code(403);
    exit('Access denied: this admit card belongs to another department.');
}

$db = db();

// Same resolution as the PDF (download.php) so the list matches what the
// student actually sees on their printed admit card.
$courses = ac_get_merged_courses_for_student($card_id, $student_id);

// Map each printed course's offer_subject_id to its course offer.
$offer_by_osid = [];
$osids = array_values(array_unique(array_filter(array_map(
    static fn($c) => (int)($c['offer_subject_id'] ?? 0),
    $courses
))));
if ($osids) {
    $ph = implode(',', array_fill(0, count($osids), '?'));
    $st = $db->prepare(
        "SELECT os.id AS osid, o.id AS offer_id, o.semester, o.academic_intake
           FROM co_offer_subjects os
           JOIN co_offers o ON o.id = os.offer_id
          WHERE os.id IN ($ph)"
    );
    $st->execute($osids);
    foreach ($st->fetchAll() as $row) {
        $offer_by_osid[(int)$row['osid']] = $row;
    }
}
?>
<?php if (empty($courses)): ?>
<div class="text-muted small px-3 py-2">No courses would be printed on this student's admit card.</div>
<?php else: ?>
<table class="table table-sm table-bordered mb-0 small">
    <thead class="table-light">
        <tr>
            <th class="px-2">#</th>
            <th>Code</th>
            <th>Title</th>
            <th>Date</th>
            <th>Comes from</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($courses as $i => $c):
        $osid  = (int)($c['offer_subject_id'] ?? 0);
        $offer = $osid > 0 ? ($offer_by_osid[$osid] ?? null) : null;
    ?>
        <tr>
            <td class="px-2 text-muted"><?= $i + 1 ?></td>
            <td><?= h($c['course_code']) ?></td>
            <td><?= h($c['course_title']) ?></td>
            <td class="text-nowrap"><?= !empty($c['exam_date']) ? date('d/m/Y', strtotime($c['exam_date'])) : '—' ?></td>
            <td>
                <?php if ($offer):
                    $lbl = trim((string)($offer['semester'] ?? '')
                        . (($offer['academic_intake'] ?? '') !== '' ? ' · ' . $offer['academic_intake'] : ''));
                    if ($lbl === '') { $lbl = 'Offer #' . (int)$offer['offer_id']; }
                ?>
                <a href="<?= APP_URL ?>/course-offer/registrations.php?offer_id=<?= (int)$offer['offer_id'] ?>&student=<?= $student_id ?>"
                   class="badge bg-primary-subtle text-primary border text-decoration-none"
                   title="Open this course offer's registrations and locate this student">
                    <i class="fas fa-external-link-alt me-1"></i><?= h($lbl) ?>
                </a>
                <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border"
                      title="This row was added manually on the card and is not linked to a course offer">Manual (card)</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
