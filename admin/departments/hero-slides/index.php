<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$slides = db()->prepare(
    'SELECT * FROM dept_hero_slides WHERE dept_id = ? ORDER BY sort_order ASC, id ASC'
);
$slides->execute([$dept_id]);
$slides = $slides->fetchAll();

$page_title = 'Hero Slides – ' . $dept['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item active">Hero Slides</li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <a href="<?= APP_URL ?>/departments/hero-slides/create.php?dept_id=<?= $dept_id ?>" class="btn btn-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-plus me-1"></i> Add Slide
        </a>
    </div>
</div>

<?php if (empty($slides)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-images fa-3x mb-3 d-block" style="opacity:.3"></i>
        No hero slides yet. <a href="<?= APP_URL ?>/departments/hero-slides/create.php?dept_id=<?= $dept_id ?>">Add the first slide</a>.
        <p class="mt-2 small">When no slides are added, the department icon is shown instead.</p>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($slides as $sl): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card h-100">
            <div style="position:relative;background:#e8eaf0;border-radius:12px 12px 0 0;overflow:hidden;padding-top:60%;">
                <img src="<?= UPLOAD_URL ?>/departments/<?= h($sl['image']) ?>"
                     alt="<?= h($sl['caption'] ?? '') ?>"
                     style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;"
                     onerror="this.parentElement.style.background='#d1d5db'">
                <span style="position:absolute;top:8px;right:8px;"
                      class="badge <?= $sl['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $sl['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
                <span style="position:absolute;top:8px;left:8px;background:rgba(0,0,0,.5);"
                      class="badge text-white">
                    #<?= (int)$sl['sort_order'] ?>
                </span>
            </div>
            <?php if ($sl['caption']): ?>
            <div class="card-body py-2 px-3">
                <p class="mb-0 small text-muted"><?= h($sl['caption']) ?></p>
            </div>
            <?php endif; ?>
            <div class="card-footer bg-transparent d-flex gap-2 py-2">
                <a href="<?= APP_URL ?>/departments/hero-slides/edit.php?id=<?= $sl['id'] ?>&dept_id=<?= $dept_id ?>"
                   class="btn btn-sm btn-outline-primary flex-fill" style="border-radius:7px;">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <form method="POST" action="<?= APP_URL ?>/departments/hero-slides/delete.php"
                      onsubmit="return confirm('Delete this slide?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $sl['id'] ?>">
                    <input type="hidden" name="dept_id" value="<?= $dept_id ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
