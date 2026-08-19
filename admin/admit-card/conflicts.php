<?php
/**
 * Admit Card – Exam Clash (Flag) Report
 *
 * Flags every student who has TWO OR MORE different courses scheduled on
 * the SAME exam date and the SAME time slot across active admit cards.
 *
 * The student's courses are resolved through their course-offer
 * registrations (co_registrations → ac_admit_card_courses.offer_subject_id),
 * so the report reflects what actually ends up on each student's card —
 * including retakes registered under other batches / sections. The same
 * course code repeated on sibling cards is NOT a clash (deduplicated).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('admit-card');
require_once __DIR__ . '/helpers.php';

$page_title = 'Admit Card – Exam Clash Report';
$db = db();

$f_exam = trim((string)($_GET['exam'] ?? ''));

// The report needs course rows linked to course-offer subjects.
$has_subject_col = false;
try { $db->query('SELECT offer_subject_id FROM ac_admit_card_courses LIMIT 1'); $has_subject_col = true; } catch (Throwable $e) {}

$conflicts = [];
if ($has_subject_col) {
    $where  = "ac.is_active = 1\n              AND cc.offer_subject_id IS NOT NULL\n              AND cc.exam_date IS NOT NULL";
    $params = [];
    if ($f_exam !== '') {
        $where   .= ' AND ac.exam_name LIKE ?';
        $params[] = '%' . $f_exam . '%';
    }
    $st = $db->prepare(
        "SELECT s.id AS sid, s.student_id, s.full_name, s.status,
                cc.exam_date,
                COALESCE(cc.time_slot, '') AS time_slot,
                COUNT(DISTINCT cc.course_code) AS clash_count,
                GROUP_CONCAT(DISTINCT CONCAT(cc.course_code, ' \u2014 ', cc.course_title)
                             ORDER BY cc.course_code SEPARATOR '||') AS courses,
                GROUP_CONCAT(DISTINCT ac.exam_name ORDER BY ac.exam_name SEPARATOR '||') AS exams,
                GROUP_CONCAT(DISTINCT ac.id) AS card_ids
           FROM ac_admit_card_courses cc
           JOIN ac_admit_cards ac  ON ac.id = cc.admit_card_id
           JOIN co_registrations r ON r.offer_subject_id = cc.offer_subject_id
           JOIN students s         ON s.id = r.student_id
          WHERE $where
            AND s.status NOT IN ('Withdrawn','Expelled')
          GROUP BY s.id, cc.exam_date, COALESCE(cc.time_slot, '')
         HAVING COUNT(DISTINCT cc.course_code) > 1
          ORDER BY cc.exam_date ASC, s.full_name ASC"
    );
    $st->execute($params);
    $conflicts = $st->fetchAll();
}

$students_flagged = count(array_unique(array_map(static fn($c) => (int)$c['sid'], $conflicts)));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-flag me-2 text-warning"></i>Exam Clash Report</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admit-card/index.php">Admit Cards</a></li>
            <li class="breadcrumb-item active">Clash Report</li>
        </ol></nav>
    </div>
</div>

<?php flash_show(); ?>

<?php if (!$has_subject_col): ?>
<div class="alert alert-warning">
    This report needs the <code>offer_subject_id</code> column on
    <code>ac_admit_card_courses</code> (cards built by the generator). The column
    was not found, so clashes cannot be computed on this installation.
</div>
<?php else: ?>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="exam" class="form-control" placeholder="Filter by exam name…"
                       value="<?= h($f_exam) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary"><i class="fas fa-search me-1"></i>Filter</button>
                <?php if ($f_exam !== ''): ?>
                    <a href="?" class="btn btn-outline-secondary ms-1">Clear</a>
                <?php endif; ?>
            </div>
            <div class="col text-md-end small text-muted">
                Only <strong>active</strong> admit cards are checked. A clash = the same student,
                the same date and the same time slot with <strong>2+ different courses</strong>.
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?php if ($conflicts): ?>
    <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">
        <i class="fas fa-flag me-1"></i><?= count($conflicts) ?> clash(es) flagged
    </span>
    <span class="badge bg-light text-dark border"><?= $students_flagged ?> student(s) affected</span>
    <?php else: ?>
    <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
        <i class="fas fa-check me-1"></i>No clashes found
    </span>
    <?php endif; ?>
</div>

<!-- Clash table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.9rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Student</th>
                        <th>Date</th>
                        <th>Time Slot</th>
                        <th>Conflicting Courses</th>
                        <th>Exam / Card</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($conflicts)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-5">
                        No student has two courses on the same date and time slot. 🎉
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($conflicts as $c): ?>
                    <tr>
                        <td class="px-4">
                            <div class="fw-semibold"><?= h($c['full_name']) ?></div>
                            <small class="text-muted"><?= h($c['student_id']) ?> · <?= h($c['status']) ?></small>
                        </td>
                        <td class="text-nowrap"><?= h(date('d M Y (D)', strtotime($c['exam_date']))) ?></td>
                        <td class="text-nowrap">
                            <?= $c['time_slot'] !== '' ? h($c['time_slot']) : '<span class="text-muted">— (no time)</span>' ?>
                        </td>
                        <td>
                            <ul class="mb-0 ps-3">
                                <?php foreach (explode('||', (string)$c['courses']) as $course): ?>
                                <li><?= h($course) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td>
                            <?php foreach (explode('||', (string)$c['exams']) as $ex): ?>
                            <div class="small"><?= h($ex) ?></div>
                            <?php endforeach; ?>
                            <div class="d-flex gap-1 flex-wrap mt-1">
                                <?php foreach (explode(',', (string)$c['card_ids']) as $cid): ?>
                                <a href="<?= APP_URL ?>/admit-card/view.php?id=<?= (int)$cid ?>"
                                   class="badge bg-light text-dark border text-decoration-none">Card #<?= (int)$cid ?></a>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
