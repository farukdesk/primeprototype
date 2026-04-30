<?php
/**
 * Partial: read-only grades table.
 * Expects $grades array (from wf_get_grades()) to be defined in the including file.
 */
?>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Student Marks</h6>
        <span class="badge bg-secondary"><?= count($grades) ?> student<?= count($grades) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3" style="width:40px;">#</th>
                        <th style="min-width:120px;">Student ID</th>
                        <th style="min-width:180px;">Name</th>
                        <th class="text-center" style="width:70px;">Absent</th>
                        <th class="text-center" style="width:70px;">Att.<br><small>/10</small></th>
                        <th class="text-center" style="width:70px;">CT<br><small>/10</small></th>
                        <th class="text-center" style="width:70px;">Mid<br><small>/30</small></th>
                        <th class="text-center" style="width:70px;">Final<br><small>/50</small></th>
                        <th class="text-center" style="width:70px;">Total</th>
                        <th class="text-center" style="width:60px;">Grade</th>
                        <th class="text-center" style="width:60px;">Point</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($grades)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No grades entered yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $idx => $g): ?>
                    <tr class="<?= $g['is_absent'] ? 'table-warning' : '' ?>">
                        <td class="px-3"><?= $idx + 1 ?></td>
                        <td><code><?= h($g['s_student_id'] ?? $g['student_sid']) ?></code></td>
                        <td><?= h($g['s_full_name'] ?? $g['student_name']) ?></td>
                        <td class="text-center">
                            <?php if ($g['is_absent']): ?>
                            <span class="badge bg-danger">Absent</span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= $g['is_absent'] ? '—' : h($g['attendance'] ?? '—') ?></td>
                        <td class="text-center"><?= $g['is_absent'] ? '—' : h($g['class_test']  ?? '—') ?></td>
                        <td class="text-center"><?= $g['is_absent'] ? '—' : h($g['mid_term']    ?? '—') ?></td>
                        <td class="text-center"><?= $g['is_absent'] ? '—' : h($g['final_exam']  ?? '—') ?></td>
                        <td class="text-center fw-semibold"><?= $g['is_absent'] ? '—' : h($g['total_marks'] ?? '—') ?></td>
                        <td class="text-center fw-bold"><?= h($g['letter_grade'] ?? '—') ?></td>
                        <td class="text-center"><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'], 2) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
