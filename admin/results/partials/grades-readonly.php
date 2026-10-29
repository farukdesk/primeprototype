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
// Total static cols: #, Student ID, Name, [dynamic mark cols], Total, Grade, Point, Remarks = 7 + N
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

// Sub-exam diff context (set by workflow-review.php; defaults keep partial reusable)
$_sub       = !empty($is_sub_exam);
$_base_pk   = $base_marks_by_pk  ?? [];
$_base_sid  = $base_marks_by_sid ?? [];

/**
 * Compare a sub-exam mark against the base-exam mark for one component.
 * Returns [status, base_value]: status = 'same' | 'modified' | 'new' | null (no mark).
 */
function _gr_diff($cur, array $base, int $di): array {
    $has_cur  = $cur !== null && $cur !== '';
    $has_base = array_key_exists($di, $base);
    if (!$has_cur)  return [null, $has_base ? $base[$di] : null];
    if (!$has_base) return ['new', null];
    return [abs((float)$cur - (float)$base[$di]) < 0.001 ? 'same' : 'modified', $base[$di]];
}
?>
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Student Marks</h6>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($_sub): ?>
            <span class="small text-muted me-1"><i class="fas fa-rotate-left me-1"></i>vs base exam:</span>
            <span class="badge" style="background:#fff3cd; color:#664d03; border:1px solid #ffe69c;">Modified</span>
            <span class="badge" style="background:#d1e7dd; color:#0f5132; border:1px solid #a3cfbb;">Newly entered</span>
            <?php endif; ?>
            <span class="badge bg-secondary"><?= count($grades) ?> student<?= count($grades) !== 1 ? 's' : '' ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-3" style="width:40px;">#</th>
                        <th style="min-width:90px;">Student ID</th>
                        <th style="min-width:150px;">Name</th>
                        <?php foreach ($_dist as $_d): ?>
                        <th class="text-center" style="width:70px;">
                            <?= h($_d['distribution_name']) ?><br>
                            <small>/<?= h($_d['max_marks']) ?></small>
                        </th>
                        <?php endforeach; ?>
                        <th class="text-center" style="width:70px;">Total</th>
                        <th class="text-center" style="width:60px;">Grade</th>
                        <th class="text-center" style="width:60px;">Point</th>
                        <th style="min-width:160px;">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($grades)): ?>
                    <tr><td colspan="<?= $_total_cols ?>" class="text-center text-muted py-4">No grades entered yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($grades as $idx => $g): ?>
                    <?php $_marks = _gr_get_marks($g); $_abs_flags = _gr_get_absent_flags($g); ?>
                    <?php
                    // Compute remarks from per-segment absent flags for high-value (≥ WF_HIGH_VALUE_THRESHOLD) distributions
                    $_remarks_parts = [];
                    foreach ($_dist as $di => $_d) {
                        if ((float)$_d['max_marks'] >= WF_HIGH_VALUE_THRESHOLD && !empty($_abs_flags[$di])) {
                            $_remarks_parts[] = h($_d['distribution_name']) . ' marked as absent';
                        }
                    }
                    if ($g['is_absent'] && empty($_remarks_parts)) {
                        $_remarks_parts[] = 'Marked as absent';
                    }
                    ?>
                    <?php
                    $_base_row = $_sub
                        ? ($_base_pk[(int)($g['student_id'] ?? 0)]
                            ?? ($_base_sid[trim((string)($g['s_student_id'] ?? $g['student_sid'] ?? ''))] ?? []))
                        : [];
                    ?>
                    <tr class="<?= $g['is_absent'] ? 'table-warning' : '' ?>">
                        <td class="px-3"><?= $idx + 1 ?></td>
                        <td><code><?= h($g['s_student_id'] ?? $g['student_sid']) ?></code></td>
                        <td><?= h($g['s_full_name'] ?? $g['student_name']) ?></td>
                        <?php foreach ($_dist as $di => $_d): ?>
                        <?php
                        $_cell_style = ''; $_cell_title = ''; $_base_val = null; $_diff = null;
                        if ($_sub && !$g['is_absent'] && empty($_abs_flags[$di])) {
                            [$_diff, $_base_val] = _gr_diff($_marks[$di] ?? null, $_base_row, $di);
                            if ($_diff === 'modified') {
                                $_cell_style = 'background:#fff3cd; box-shadow:inset 0 0 0 1px #ffc107;';
                                $_cell_title = 'Modified — base exam mark: ' . $_base_val;
                            } elseif ($_diff === 'new') {
                                $_cell_style = 'background:#d1e7dd; box-shadow:inset 0 0 0 1px #198754;';
                                $_cell_title = 'Newly entered — no mark in base exam';
                            }
                        }
                        ?>
                        <td class="text-center" style="<?= $_cell_style ?>"<?= $_cell_title !== '' ? ' title="' . h($_cell_title) . '"' : '' ?>>
                            <?php if ($g['is_absent']): ?>
                            —
                            <?php elseif (!empty($_abs_flags[$di])): ?>
                            <span class="badge bg-warning text-dark" title="Absent for this component">Abs</span>
                            <?php elseif ($_diff === 'modified'): ?>
                            <span class="text-muted text-decoration-line-through small me-1"><?= h($_base_val) ?></span><strong><?= h($_marks[$di]) ?></strong>
                            <?php elseif ($_diff === 'new'): ?>
                            <strong><?= h($_marks[$di]) ?></strong>
                            <?php else: ?>
                            <?= h($_marks[$di] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold"><?= $g['is_absent'] ? '—' : h($g['total_marks'] ?? '—') ?></td>
                        <td class="text-center fw-bold"><?= h($g['letter_grade'] ?? '—') ?></td>
                        <td class="text-center"><?= $g['grade_point'] !== null ? number_format((float)$g['grade_point'], 2) : '—' ?></td>
                        <td>
                            <?php if (!empty($_remarks_parts)): ?>
                            <small class="text-danger"><?= implode('; ', $_remarks_parts) ?> – no grade shown</small>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
