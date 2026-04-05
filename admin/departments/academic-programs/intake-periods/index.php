<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$program_id = (int)($_GET['program_id'] ?? 0);
if (!$program_id) { flash_set('error', 'Invalid program.'); redirect(APP_URL . '/departments/index.php'); }

$program = db()->prepare('SELECT p.*, d.id AS dept_id, d.name AS dept_name FROM dept_academic_programs p JOIN dept_departments d ON d.id = p.dept_id WHERE p.id = ?');
$program->execute([$program_id]);
$program = $program->fetch();
if (!$program) { flash_set('error', 'Program not found.'); redirect(APP_URL . '/departments/index.php'); }

$dept_id = (int)$program['dept_id'];

$items = db()->prepare('SELECT * FROM program_intake_periods WHERE program_id = ? ORDER BY sort_order ASC, id DESC');
$items->execute([$program_id]);
$items = $items->fetchAll();

$page_title = 'Intake Periods – ' . $program['program_name'];
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($program['dept_name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>">Academic Programs</a></li>
            <li class="breadcrumb-item active">Intake Periods</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/academic-programs/intake-periods/create.php?program_id=<?= $program_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Intake Period
        </a>
    </div>
</div>

<?= flash_html() ?>

<div class="card">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-alt me-2 text-muted"></i>Intake Periods – <span class="text-primary"><?= h($program['program_name']) ?></span></h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($items) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Intake Name</th>
                        <th>Open Date</th>
                        <th>Close Date</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No intake periods found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td class="fw-medium"><?= h($item['intake_name']) ?></td>
                        <td><?= $item['open_date'] ? h(date('d M Y', strtotime($item['open_date']))) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $item['close_date'] ? h(date('d M Y', strtotime($item['close_date']))) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php
                            $status_classes = ['open' => 'bg-success', 'upcoming' => 'bg-warning text-dark', 'closed' => 'bg-secondary'];
                            $sc = $status_classes[$item['intake_status']] ?? 'bg-secondary';
                            ?>
                            <span class="badge <?= $sc ?>"><?= ucfirst(h($item['intake_status'])) ?></span>
                        </td>
                        <td><?= $item['notes'] ? '<span class="text-truncate d-inline-block" style="max-width:180px;" title="' . h($item['notes']) . '">' . h(mb_substr($item['notes'], 0, 60)) . (mb_strlen($item['notes']) > 60 ? '…' : '') . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= (int)$item['sort_order'] ?></td>
                        <td>
                            <span class="badge <?= $item['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $item['is_active'] ? 'Yes' : 'No' ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= APP_URL ?>/departments/academic-programs/intake-periods/edit.php?id=<?= $item['id'] ?>&program_id=<?= $program_id ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" action="<?= APP_URL ?>/departments/academic-programs/intake-periods/delete.php"
                                      onsubmit="return confirm('Delete intake period &quot;<?= h(addslashes($item['intake_name'])) ?>&quot;?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="program_id" value="<?= $program_id ?>">
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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
