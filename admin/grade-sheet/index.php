<?php
/**
 * Grade Sheet – filter & preview.
 *
 * Builds a department / batch / semester tabulation grade sheet for the
 * Semester Final Examination from published result mark sheets. The page
 * offers a filter form and an on-screen preview; the print-ready landscape
 * document is produced by print.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('grade-sheet');
require_once __DIR__ . '/helpers.php';

$page_title = 'Grade Sheet';

// ── Dropdown data ─────────────────────────────────────────────────────────────
$exams       = gs_exams();
$departments = gs_departments();
$programs    = gs_programs();
$batches     = gs_batches();
$semesters   = gs_semesters();

// ── Filters ───────────────────────────────────────────────────────────────────
$f = [
    'exam_id'    => (int)($_GET['exam_id']    ?? 0),
    'dept_id'    => (int)($_GET['dept_id']    ?? 0),
    'program_id' => (int)($_GET['program_id'] ?? 0),
    'batch_id'   => (int)($_GET['batch_id']   ?? 0),
    'semester'   => trim((string)($_GET['semester'] ?? '')),
    'shift'      => trim((string)($_GET['shift']    ?? '')),
    'section'    => trim((string)($_GET['section']  ?? '')),
];

// Default to the newest exam with published sheets so the page isn't empty.
if ($f['exam_id'] <= 0 && !empty($exams)) {
    $f['exam_id'] = (int)$exams[0]['id'];
}

$data     = $f['exam_id'] > 0 ? gs_build($f) : ['meta' => [], 'courses' => [], 'students' => []];
$courses  = $data['courses'];
$students = $data['students'];

$print_query = http_build_query(array_filter($f, static fn($v) => $v !== '' && $v !== 0));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.gs-table th, .gs-table td { vertical-align:middle; font-size:.82rem; white-space:nowrap; }
.gs-table th { background:#1e3a5f; color:#fff; text-align:center; }
.gs-table td.center { text-align:center; }
.gs-grade-dash { color:#bbb; }
.gs-sgpa { font-weight:700; color:#1e3a5f; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Grade Sheet</li>
        </ol>
    </nav>
    <?php if ($f['exam_id'] > 0 && !empty($students)): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/grade-sheet/print.php?<?= h($print_query) ?>" target="_blank" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-print me-1"></i> Print / Generate Grade Sheet
        </a>
    </div>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if (empty($exams)): ?>
<div class="alert alert-warning">
    No published results found. Publish result mark sheets for an exam first.
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-lg-4">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Exam <span class="text-danger">*</span></label>
                <select name="exam_id" class="form-select form-select-sm" style="border-radius:8px;" onchange="this.form.submit()">
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $f['exam_id'] ? 'selected' : '' ?>><?= h(gs_exam_label($e)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Department</label>
                <select name="dept_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $f['dept_id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Program</label>
                <select name="program_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All programs</option>
                    <?php foreach ($programs as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-dept="<?= (int)$p['dept_id'] ?>" <?= (int)$p['id'] === $f['program_id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Batch</label>
                <select name="batch_id" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All batches</option>
                    <?php foreach ($batches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $f['batch_id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Semester</label>
                <select name="semester" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All semesters</option>
                    <?php foreach ($semesters as $s): ?>
                    <option value="<?= h($s) ?>" <?= $f['semester'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Shift</label>
                <input type="text" name="shift" value="<?= h($f['shift']) ?>" class="form-control form-control-sm" style="border-radius:8px;" placeholder="e.g. Evening">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label fw-medium mb-1" style="font-size:.8rem;">Section</label>
                <input type="text" name="section" value="<?= h($f['section']) ?>" class="form-control form-control-sm" style="border-radius:8px;" placeholder="e.g. A">
            </div>
            <div class="col-12 col-lg-4 d-flex gap-2 justify-content-lg-end">
                <button class="btn btn-sm btn-primary" style="border-radius:8px;"><i class="fas fa-filter me-1"></i> Apply</button>
                <a href="<?= APP_URL ?>/grade-sheet/index.php?exam_id=<?= (int)$f['exam_id'] ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Preview ─────────────────────────────────────────────────────────────── -->
<?php if (empty($students)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i> No published grades match the selected filters.
</div>
<?php else: ?>
<div class="card" style="border-radius:12px;">
    <div class="card-body px-4 py-3">
        <div class="d-flex flex-wrap gap-3 mb-3" style="font-size:.85rem;">
            <?php if ($data['meta']['dept_name'] !== ''): ?><div><span class="text-muted">Department:</span> <strong><?= h($data['meta']['dept_name']) ?></strong></div><?php endif; ?>
            <?php if ($data['meta']['program_name'] !== ''): ?><div><span class="text-muted">Program:</span> <strong><?= h($data['meta']['program_name']) ?></strong></div><?php endif; ?>
            <?php if ($data['meta']['batch_name'] !== ''): ?><div><span class="text-muted">Batch:</span> <strong><?= h($data['meta']['batch_name']) ?></strong></div><?php endif; ?>
            <?php if ($data['meta']['semester'] !== ''): ?><div><span class="text-muted">Semester:</span> <strong><?= h($data['meta']['semester']) ?></strong></div><?php endif; ?>
            <div><span class="text-muted">Students:</span> <strong><?= count($students) ?></strong></div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm gs-table mb-0">
                <thead>
                    <tr>
                        <th>SL.</th>
                        <th>Student's ID</th>
                        <th class="text-start" style="min-width:160px;">Name of the Student</th>
                        <?php foreach ($courses as $c): ?>
                        <th title="<?= h($c['name']) ?>"><?= h($c['code']) ?></th>
                        <?php endforeach; ?>
                        <th>SGPA / Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sl = 1; foreach ($students as $sd): ?>
                    <tr>
                        <td class="center"><?= $sl++ ?></td>
                        <td class="center"><strong><?= h($sd['sid']) ?></strong></td>
                        <td><?= h($sd['name']) ?></td>
                        <?php foreach ($courses as $c): ?>
                        <?php $ge = $sd['grades'][$c['code']] ?? null; ?>
                        <td class="center">
                            <?php if ($ge === null): ?>
                                <span class="gs-grade-dash">−−−−−</span>
                            <?php elseif ($ge['is_absent']): ?>
                                <span class="gs-grade-dash">−−−−−</span>
                            <?php else: ?>
                                <?= h(gs_grade_label($ge['letter'])) ?>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="center">
                            <?php if ($sd['sgpa'] !== null): ?>
                                <span class="gs-sgpa"><?= h($sd['sgpa']) ?></span>
                            <?php elseif ($sd['remark'] === 'Absent'): ?>
                                <em class="text-muted">Absent</em>
                            <?php else: ?>
                                <em class="text-muted"><?= h((string)$sd['remark']) ?></em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
