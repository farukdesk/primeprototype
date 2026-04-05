<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$programs = db()->prepare(
    'SELECT * FROM dept_academic_programs WHERE dept_id = ? ORDER BY sort_order ASC, id ASC'
);
$programs->execute([$dept_id]);
$programs = $programs->fetchAll();

$page_title = 'Academic Programs – ' . $dept['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Academic Programs</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/academic-programs/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Program
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-book-open me-2 text-muted"></i>Academic Programs</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($programs) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Program Name</th>
                        <th>Degree Type</th>
                        <th>Duration</th>
                        <th>Total Credit</th>
                        <th>Semester Type</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No academic programs found.</td></tr>
                <?php else: ?>
                    <?php foreach ($programs as $i => $p): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($p['program_name']) ?></td>
                        <td><?= h($p['degree_type'] ?? '—') ?></td>
                        <td><?= h($p['duration'] ?? '—') ?></td>
                        <td><?= h($p['total_credit'] ?? '—') ?></td>
                        <td>
                            <?php
                            $st_labels = ['trimester' => 'Trimester', 'semester' => 'Semester', 'annual' => 'Annual'];
                            $st = $p['semester_type'] ?? '';
                            echo isset($st_labels[$st])
                                ? '<span class="badge bg-info text-dark">' . h($st_labels[$st]) . '</span>'
                                : '<span class="text-muted">—</span>';
                            ?>
                        </td>
                        <td><?= (int)$p['sort_order'] ?></td>
                        <td>
                            <span class="badge <?= $p['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                                <a href="<?= SITE_URL ?>/academic-program-detail.php?id=<?= $p['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" title="View Public Page">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <a href="<?= APP_URL ?>/departments/academic-programs/intake-periods/index.php?program_id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-info" style="border-radius:7px;" title="Intake Periods">
                                    <i class="fas fa-calendar-alt"></i>
                                </a>
                                <a href="<?= APP_URL ?>/departments/academic-programs/eligibility/index.php?program_id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-success" style="border-radius:7px;" title="Eligibility Criteria">
                                    <i class="fas fa-check-circle"></i>
                                </a>
                                <a href="<?= APP_URL ?>/departments/academic-programs/edit.php?id=<?= $p['id'] ?>&dept_id=<?= $dept_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/academic-programs/delete.php"
                                      onsubmit="return confirm('Delete program &quot;<?= h(addslashes($p['program_name'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="dept_id" value="<?= $dept_id ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
