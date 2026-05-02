<?php
/**
 * Partial: read-only grades table.
 * Expects $grades array (from wf_get_grades()) and $sheet array to be in scope.
 *
 * Dynamically shows mark columns based on cc_mark_distributions if available.
 */

// Build mark distribution for this sheet
$_dist = [];
if (!empty($sheet['curriculum_id'])) {
    try {
        $md_st = db()->prepare(
            'SELECT distribution_name, max_marks
               FROM cc_mark_distributions
              WHERE curriculum_id = ?
              ORDER BY sort_order ASC, id ASC'
        );
        $md_st->execute([$sheet['curriculum_id']]);
        $_dist = $md_st->fetchAll();
    } catch (Throwable $_e) {}
}
if (empty($_dist)) {
    $_dist = [
        ['distribution_name' => 'Att.',  'max_marks' => 10],
        ['distribution_name' => 'CT',    'max_marks' => 10],
        ['distribution_name' => 'Mid',   'max_marks' => 30],
        ['distribution_name' => 'Final', 'max_marks' => 50],
    ];
}
$_dist_count = count($_dist);
// Total static cols: #, Student ID, Name, Absent, [dynamic mark cols], Total, Grade, Point = 7 + N
$_total_cols = 7 + $_dist_count;

/**
 * Extract marks array from a grade row.
 * Prefers marks_json; falls back to 4 legacy columns.
 */
function _gr_get_marks(array $g): array {
    if (!empty($g['marks_json'])) {
        $marks = json_decode($g['marks_json'], true);
        if (is_array($marks)) return $marks;
    }
    return [
        $g['attendance'] ?? null,
        $g['class_test'] ?? null,
        $g['mid_term']   ?? null,
        $g['final_exam'] ?? null,
    ];
}

/**
 * Extract per-segment absent flags array from a grade row.
 * Returns array of booleans (indexed by dist position) or empty array.
 */
function _gr_get_absent_flags(array $g): array {
    if (!empty($g['absent_json'])) {
        $flags = json_decode($g['absent_json'], true);
        if (is_array($flags)) return $flags;
    }
    return [];
}
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
                        <?php foreach ($_dist as $_d): ?>
                        <th class="text-center" style="width:70px;">
                            <?= h($_d['distribution_name']) ?><br>
                            <small>/<?= h($_d['max_marks']) ?></small>
                        </th>
                        <?php endforeach; ?>
                        <th class="text-center" style="width:70px;">Total</th>
                        <th class="text-center" style="width:60px;">Grade</th>
                        <th class="text-center" style="width:60px;">Point</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($grades)): ?>
                    <tr><td colspan="<?= $_total_cols ?>" class="text-center text-muted py-4">No grades entered yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $idx => $g): ?>
                    <?php $_marks = _gr_get_marks($g); $_abs_flags = _gr_get_absent_flags($g); ?>
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
                        <?php foreach ($_dist as $di => $_d): ?>
                        <td class="text-center">
                            <?php if ($g['is_absent']): ?>
                            —
                            <?php elseif (!empty($_abs_flags[$di])): ?>
                            <span class="badge bg-warning text-dark" title="Absent for this component">Abs</span>
                            <?php else: ?>
                            <?= h($_marks[$di] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
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
