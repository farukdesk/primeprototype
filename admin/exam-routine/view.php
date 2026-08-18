<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('exam-routine');
require_once __DIR__ . '/helpers.php';

$routine = er_get_routine((int)($_GET['id'] ?? 0));
if (!$routine) { flash_set('error', 'Routine not found.'); redirect(APP_URL . '/exam-routine/index.php'); }
$items = er_get_items((int)$routine['id']);
$teacher_map = er_subject_teacher_map(array_column($items, 'offer_subject_id'));

$page_title = 'Exam Routine – ' . $routine['exam_name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-routine/index.php">Exam Routine</a></li>
            <li class="breadcrumb-item active">View</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/exam-routine/print.php?id=<?= $routine['id'] ?>" target="_blank"
           class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-print me-1"></i> Print</a>
        <?php if (is_super_admin() || can_access('exam-routine', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/exam-routine/create.php?id=<?= $routine['id'] ?>"
           class="btn btn-primary" style="border-radius:10px;"><i class="fas fa-pen me-1"></i> Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body p-4">
        <h5 class="fw-bold mb-1">
            <?= h($routine['exam_name']) ?><?= $routine['exam_year'] ? ' – ' . h($routine['exam_year']) : '' ?>
        </h5>
        <div class="text-muted mb-3">
            <?= h($routine['dept_name']) ?>
            <?= $routine['program_name'] ? ' — ' . h($routine['program_name']) : '' ?>
        </div>
        <div>
            <?php
            $ctx = [
                'Batch'    => $routine['batch_name'],
                'Semester' => $routine['semester'],
                'Section'  => $routine['section'],
                'Shift'    => $routine['shift'],
                'Intake'   => $routine['academic_intake'],
            ];
            foreach ($ctx as $label => $val): if (!$val) continue; ?>
            <span class="badge bg-light text-dark border me-2 mb-1"><?= h($label) ?>: <strong><?= h($val) ?></strong></span>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($routine['notes'])): ?>
        <div class="small text-muted mt-3"><i class="fas fa-sticky-note me-1"></i><?= nl2br(h($routine['notes'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Code</th>
                        <th>Course Title</th>
                        <th>Course Teacher</th>
                        <th class="text-center">Students</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Room</th>
                        <th class="pe-4">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No subjects in this routine.</td></tr>
                <?php else: $total = 0; foreach ($items as $n => $it): $total += (int)$it['student_count']; ?>
                    <tr>
                        <td class="ps-4"><?= $n + 1 ?></td>
                        <td class="fw-medium"><?= h($it['course_code'] ?? '—') ?></td>
                        <td><?= h($it['course_title']) ?></td>
                        <td class="small"><?= h($teacher_map[(int)($it['offer_subject_id'] ?? 0)] ?? '—') ?></td>
                        <td class="text-center"><?= (int)$it['student_count'] ?></td>
                        <td><?= h(date('d M Y (D)', strtotime($it['exam_date']))) ?></td>
                        <td class="small">
                            <?php if ($it['start_time'] || $it['end_time']): ?>
                                <?= h(er_fmt_time($it['start_time'])) ?><?= $it['end_time'] ? ' – ' . h(er_fmt_time($it['end_time'])) : '' ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><?= $it['room_number'] !== null && $it['room_number'] !== '' ? h($it['room_number']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="pe-4 small"><?= $it['remarks'] !== null && $it['remarks'] !== '' ? h($it['remarks']) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr class="table-light fw-semibold">
                        <td colspan="4" class="ps-4 text-end">Total students</td>
                        <td class="text-center"><?= $total ?></td>
                        <td colspan="4"></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
