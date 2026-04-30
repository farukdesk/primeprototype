<?php
/**
 * Partial: Sheet header card (read-only summary of the mark sheet).
 * Expects $sheet array (from wf_get_sheet()) to be defined in the including file.
 */
?>
<div class="card mb-4" style="border-radius:12px; border-left:4px solid #002147;">
    <div class="card-body px-4 py-3">
        <div class="row g-2">
            <div class="col-md-8">
                <h5 class="mb-1 fw-bold" style="color:#002147;">
                    <?php if ($sheet['subject_code']): ?><span class="badge bg-secondary me-2" style="font-size:.75rem;"><?= h($sheet['subject_code']) ?></span><?php endif; ?>
                    <?= h($sheet['subject_title']) ?>
                </h5>
                <div class="text-muted small">
                    <i class="fas fa-university me-1"></i><?= h($sheet['faculty_label'] ?? '') ?>
                    &nbsp;·&nbsp;<strong><?= h($sheet['dept_name']) ?></strong>
                    <?php if ($sheet['program_name']): ?>&nbsp;·&nbsp;<?= h($sheet['program_name']) ?><?php endif; ?>
                </div>
                <div class="small text-muted mt-1">
                    <i class="fas fa-calendar me-1"></i><?= h($sheet['semester']) ?>
                    <?php if ($sheet['academic_year']): ?>&nbsp;(<?= h($sheet['academic_year']) ?>)<?php endif; ?>
                    <?php if ($sheet['credits']): ?>&nbsp;·&nbsp;<?= h($sheet['credits']) ?> Credits<?php endif; ?>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="mb-1"><?= wf_status_badge($sheet['workflow_status']) ?></div>
                <?php if ($sheet['creator_name']): ?>
                <div class="small text-muted"><i class="fas fa-user me-1"></i>Teacher: <?= h($sheet['creator_name']) ?></div>
                <?php endif; ?>
                <?php if ($sheet['submitted_at']): ?>
                <div class="small text-muted">Submitted: <?= date('d M Y', strtotime($sheet['submitted_at'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Workflow trail -->
        <?php if ($sheet['reviewer_remarks'] || $sheet['reviewed_by']): ?>
        <hr class="my-2">
        <div class="small">
            <strong><i class="fas fa-search me-1 text-info"></i>Reviewer:</strong>
            <?= h($sheet['reviewer_name'] ?? '') ?>
            <?php if ($sheet['reviewed_at']): ?><span class="text-muted">(<?= date('d M Y', strtotime($sheet['reviewed_at'])) ?>)</span><?php endif; ?>
            <?php if ($sheet['reviewer_remarks']): ?>— <?= h($sheet['reviewer_remarks']) ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($sheet['hod_remarks'] || $sheet['hod_approved_by']): ?>
        <div class="small mt-1">
            <strong><i class="fas fa-user-tie me-1 text-warning"></i>HOD:</strong>
            <?= h($sheet['hod_name'] ?? '') ?>
            <?php if ($sheet['hod_approved_at']): ?><span class="text-muted">(<?= date('d M Y', strtotime($sheet['hod_approved_at'])) ?>)</span><?php endif; ?>
            <?php if ($sheet['hod_remarks']): ?>— <?= h($sheet['hod_remarks']) ?><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($sheet['return_remarks']): ?>
        <div class="small mt-1 text-danger">
            <strong><i class="fas fa-undo me-1"></i>Returned:</strong>
            <?= h($sheet['return_remarks']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
