<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('course-fees');

$id   = (int)($_GET['id'] ?? 0);
$prog = cf_get_program($id);
if (!$prog) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/course-fees/index.php'); }

$page_title   = $prog['program_name'];
$requirements = cf_get_requirements($id);
$is_masters   = cf_is_masters($prog);
$is_diploma   = cf_is_diploma($prog);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0">
            <i class="fas fa-calculator me-2 text-warning"></i>
            <?= h($prog['program_name']) ?>
            <?= cf_type_badge($prog) ?>
        </h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/course-fees/index.php">Course Fees</a></li>
            <li class="breadcrumb-item active">View</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (cf_can_edit()): ?>
        <a href="<?= APP_URL ?>/course-fees/edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-pencil me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (cf_can_delete()): ?>
        <a href="<?= APP_URL ?>/course-fees/delete.php?id=<?= $id ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('Delete this program? This cannot be undone.')">
            <i class="fas fa-trash me-1"></i> Delete
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/course-fees/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?= flash_show() ?>

<!-- Hero Card -->
<div class="card border-0 shadow-sm mb-4"
     style="background:linear-gradient(135deg,#1a2e5a,#2563eb);color:#fff;border-radius:16px;">
    <div class="card-body p-4">
        <div class="row align-items-center g-3">
            <div class="col">
                <div class="small opacity-75 mb-1">
                    <?= h($prog['degree_type_name']) ?> &nbsp;·&nbsp;
                    <?= $prog['duration_years'] ? h($prog['duration_years']) . ' yrs' : '' ?>
                    <?= $prog['total_credits']  ? '&nbsp;·&nbsp; ' . h($prog['total_credits']) . ' credits' : '' ?>
                </div>
                <h2 class="mb-0 fw-bold fs-4"><?= h($prog['program_name']) ?></h2>
                <code class="text-white-50 small"><?= h($prog['program_slug']) ?></code>
            </div>
            <div class="col-auto text-end">
                <?php if ($prog['is_active']): ?>
                    <span class="badge bg-success fs-6 px-3 py-2">Active</span>
                <?php else: ?>
                    <span class="badge bg-secondary fs-6 px-3 py-2">Inactive</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="progTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOverview">Overview</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabFees">Fee Constants</button></li>
    <?php if (!$is_masters): ?>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabScholarship">Scholarship Tiers</button></li>
    <?php endif; ?>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabReqs">Admission Requirements</button></li>
</ul>

<div class="tab-content">

    <!-- Overview Tab -->
    <div class="tab-pane fade show active" id="tabOverview">
        <div class="row g-3 mb-3">
            <?php foreach ([
                'Total Credits'   => $prog['total_credits'],
                'Duration'        => $prog['duration_years'] ? $prog['duration_years'] . ' yrs' : null,
                'Total Semesters' => $prog['total_semesters'],
                'Total Months'    => $prog['total_months'],
            ] as $label => $val): ?>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?= $val ?? '—' ?></div>
                    <div class="small text-muted"><?= $label ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><th class="w-33">ID</th><td><?= $prog['id'] ?></td></tr>
                        <tr><th>Slug</th><td><code><?= h($prog['program_slug']) ?></code></td></tr>
                        <tr><th>Degree Type</th><td><?= cf_type_badge($prog) ?></td></tr>
                        <tr><th>Sort Order</th><td><?= $prog['sort_order'] ?></td></tr>
                        <tr><th>Status</th><td><?= $prog['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td></tr>
                        <tr><th>Updated</th><td><?= h($prog['updated_at']) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Fee Constants Tab -->
    <div class="tab-pane fade" id="tabFees">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if ($is_masters): ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Field</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php foreach ([
                            'Tuition (Full)'       => $prog['tuition_full'],
                            'Admission Fee'        => $prog['admission_fee_m'],
                            'Registration Fee'     => $prog['registration_fee'],
                            'Institutional Fees'   => $prog['institutional_fees'],
                            'Campaign Waiver'      => $prog['campaign_waiver'],
                            'Total Program Cost'   => $prog['total_program_cost'],
                            'Total After Waiver'   => $prog['total_after_waiver'],
                            'Monthly Fixed'        => $prog['monthly_fixed'],
                            'External Waiver'      => $prog['external_waiver'],
                            'External Final'       => $prog['external_final'],
                            'External Monthly'     => $prog['external_monthly'],
                            'Internal Waiver'      => $prog['internal_waiver'],
                            'Internal Final'       => $prog['internal_final'],
                            'Internal Monthly'     => $prog['internal_monthly'],
                        ] as $label => $val): ?>
                        <tr>
                            <th><?= h($label) ?></th>
                            <td><?= $val !== null ? cf_money((float)$val) : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Field</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php foreach ([
                            'Standard Tuition (Full)'    => $prog['standard_tuition_full'],
                            'Tuition Per Semester'       => $prog['tuition_per_semester'],
                            'Admission Fees (Day Total)' => $prog['admission_fees'],
                            'Fixed Institutional Fees'   => $prog['fixed_institutional_fees'],
                            'English Course Fee'         => $prog['english_course_fee'],
                            'Safety Net Cap'             => $prog['safety_net_cap'],
                            'Safety Net Per Semester'    => $prog['safety_net_per_semester'],
                        ] as $label => $val): ?>
                        <tr>
                            <th><?= h($label) ?></th>
                            <td><?= $val !== null ? cf_money((float)$val) : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr><th>Attendance Requirement</th><td><?= $prog['attendance_requirement'] ?>%</td></tr>
                        <tr><th>Safety Net GPA Threshold</th><td><?= $prog['safety_net_gpa_threshold'] ?></td></tr>
                        <tr><th>Scholarship Type</th><td><?= h($prog['scholarship_type']) ?></td></tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scholarship Tiers Tab (bachelor/diploma only) -->
    <?php if (!$is_masters): ?>
    <div class="tab-pane fade" id="tabScholarship">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold py-3">Initial Waiver Tiers (SSC+HSC/Diploma GPA)</div>
                    <div class="card-body">
                        <?php if ($prog['initial_waiver_tiers']): ?>
                        <?php $tiers = json_decode($prog['initial_waiver_tiers'], true); ?>
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Min GPA</th><th>Max GPA</th><th>Waiver %</th></tr></thead>
                            <tbody>
                            <?php foreach ($tiers as $t): ?>
                            <tr>
                                <td><?= $t['min'] ?></td>
                                <td><?= $t['max'] ?></td>
                                <td><strong><?= $t['pct'] ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="text-muted">No tiers defined.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header fw-semibold py-3">Merit Waiver Tiers (Semester GPA)</div>
                    <div class="card-body">
                        <?php if ($prog['merit_waiver_tiers']): ?>
                        <?php $tiers = json_decode($prog['merit_waiver_tiers'], true); ?>
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Min GPA</th><th>Max GPA</th><th>Waiver %</th></tr></thead>
                            <tbody>
                            <?php foreach ($tiers as $t): ?>
                            <tr>
                                <td><?= $t['min'] ?></td>
                                <td><?= $t['max'] ?></td>
                                <td><strong><?= $t['pct'] ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="text-muted">No tiers defined.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Admission Requirements Tab -->
    <div class="tab-pane fade" id="tabReqs">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <span class="fw-semibold">Admission Requirements</span>
            </div>
            <div class="card-body">
                <?php if (empty($requirements)): ?>
                <p class="text-muted mb-0">No requirements added yet.</p>
                <?php else: ?>
                <ol class="mb-0">
                    <?php foreach ($requirements as $req): ?>
                    <li class="d-flex justify-content-between align-items-start py-1">
                        <span><?= $req['requirement_text'] ?></span>
                        <?php if (cf_can_delete()): ?>
                        <a href="<?= APP_URL ?>/course-fees/req-delete.php?id=<?= $req['id'] ?>&prog=<?= $id ?>"
                           class="btn btn-sm btn-outline-danger ms-3"
                           onclick="return confirm('Remove this requirement?')">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>

        <?php if (cf_can_edit()): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header fw-semibold py-3">Add Requirement</div>
            <div class="card-body">
                <form method="post" action="<?= APP_URL ?>/course-fees/req-create.php" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="program_id" value="<?= $id ?>">
                    <div class="col">
                        <input type="text" name="requirement_text" class="form-control"
                               placeholder="e.g. Minimum GPA 2.5 in SSC and HSC" required maxlength="500">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus me-1"></i> Add
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /tab-content -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
