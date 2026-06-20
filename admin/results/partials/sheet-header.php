<?php
/**
 * Partial: Sheet header card (read-only summary).
 * Expects $sheet (from wf_get_sheet()) to be defined.
 * Uses chain-aware helpers — no hard-coded roles.
 */
?>
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3">
        <div class="row g-2">
            <div class="col-md-8">
                <h5 class="mb-1 fw-bold" style="color:#002147;">
                    <?php if ($sheet['subject_code']): ?>
                    <span class="badge bg-secondary me-2" style="font-size:.75rem;"><?= h($sheet['subject_code']) ?></span>
                    <?php endif; ?>
                    <?= h($sheet['subject_title']) ?>
                </h5>
                <div class="text-muted small">
                    <i class="fas fa-university me-1"></i>
                    <?php if (!empty($sheet['faculty_label'])): ?><?= h($sheet['faculty_label']) ?> &nbsp;·&nbsp;<?php endif; ?>
                    <strong><?= h($sheet['dept_name']) ?></strong>
                    <?php if (!empty($sheet['program_name'])): ?>&nbsp;·&nbsp;<?= h($sheet['program_name']) ?><?php endif; ?>
                </div>
                <div class="small text-muted mt-1">
                    <i class="fas fa-calendar me-1"></i><?= h($sheet['semester']) ?>
                    <?php if (!empty($sheet['academic_year'])): ?>&nbsp;(<?= h($sheet['academic_year']) ?>)<?php endif; ?>
                    <?php if (!empty($sheet['credits'])): ?>&nbsp;·&nbsp;<?= h($sheet['credits']) ?> Credits<?php endif; ?>
                </div>
                <?php if (!empty($sheet['chain_name'])): ?>
                <div class="small text-muted mt-1">
                    <i class="fas fa-sitemap me-1"></i>Chain: <?= h($sheet['chain_name']) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="mb-1">
                    <?= wf_status_badge($sheet['workflow_status'], $sheet['current_step_label'] ?? '') ?>
                </div>
                <?php if (!empty($sheet['creator_name'])): ?>
                <div class="small text-muted"><i class="fas fa-user me-1"></i>Teacher: <?= h($sheet['creator_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($sheet['current_step_label']) && $sheet['workflow_status'] === 'pending'): ?>
                <div class="small text-info">
                    <i class="fas fa-tasks me-1"></i>Awaiting: <?= h($sheet['current_step_label']) ?>
                    <?php if (!empty($sheet['current_group_name'])): ?>(<?= h($sheet['current_group_name']) ?>)<?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
