<?php
require_once __DIR__ . '/helpers.php';
auth_check();
require_access('homepage', 'can_view');

$page_title = 'Homepage Management';

$stats        = db()->query('SELECT * FROM homepage_stats ORDER BY sort_order, id')->fetchAll();
$testimonials = db()->query('SELECT * FROM homepage_testimonials ORDER BY sort_order, id')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Homepage Management</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<!-- ── Stats/Counters ─────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-muted"></i>Stats / Counters</h5>
    <?php if (can_access('homepage', 'can_edit')): ?>
    <a href="<?= APP_URL ?>/homepage/stats-create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Stat
    </a>
    <?php endif; ?>
</div>

<?php if (empty($stats)): ?>
<div class="card mb-5">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-chart-bar fa-3x mb-3 d-block" style="opacity:.3"></i>
        No stats configured yet.
        <?php if (can_access('homepage', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/homepage/stats-create.php" class="d-block mt-2">Add the first stat</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="row g-3 mb-5">
    <?php foreach ($stats as $i => $st): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div style="width:48px;height:48px;background:linear-gradient(135deg,#eef2ff,#dde6ff);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#1a3a5c;font-size:1.2rem;flex-shrink:0;">
                    <i class="<?= h($st['icon']) ?>"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-bold" style="font-size:1.1rem;color:#1a3a5c;"><?= h($st['value']) ?><?= h($st['suffix']) ?></div>
                    <div class="text-muted" style="font-size:.82rem;"><?= h($st['label']) ?></div>
                </div>
                <span class="badge <?= $st['is_active'] ? 'bg-success' : 'bg-secondary' ?> ms-auto" style="font-size:.7rem;">
                    <?= $st['is_active'] ? 'On' : 'Off' ?>
                </span>
            </div>
            <?php if (can_access('homepage', 'can_edit') || can_access('homepage', 'can_delete')): ?>
            <div class="card-footer bg-transparent d-flex gap-2 py-2">
                <?php if (can_access('homepage', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/homepage/stats-edit.php?id=<?= $st['id'] ?>"
                   class="btn btn-sm btn-outline-primary flex-fill" style="border-radius:7px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit
                </a>
                <?php endif; ?>
                <?php if (can_access('homepage', 'can_delete')): ?>
                <form method="POST" action="<?= APP_URL ?>/homepage/stats-delete.php"
                      onsubmit="return confirm('Delete this stat?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $st['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete" style="border-radius:7px;">
                        <i class="fas fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Testimonials ───────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0"><i class="fas fa-quote-left me-2 text-muted"></i>Testimonials</h5>
    <?php if (can_access('homepage', 'can_edit')): ?>
    <a href="<?= APP_URL ?>/homepage/testimonials-create.php" class="btn btn-primary" style="border-radius:10px;font-size:.875rem;">
        <i class="fas fa-plus me-1"></i> Add Testimonial
    </a>
    <?php endif; ?>
</div>

<?php if (empty($testimonials)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-quote-left fa-3x mb-3 d-block" style="opacity:.3"></i>
        No testimonials yet.
        <?php if (can_access('homepage', 'can_edit')): ?>
        <a href="<?= APP_URL ?>/homepage/testimonials-create.php" class="d-block mt-2">Add the first testimonial</a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Person</th>
                        <th>Quote</th>
                        <th>Rating</th>
                        <th>Order</th>
                        <th>Status</th>
                        <?php if (can_access('homepage', 'can_edit') || can_access('homepage', 'can_delete')): ?>
                        <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($testimonials as $i => $t): ?>
                    <tr>
                        <td class="px-4 text-muted"><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($t['photo']): ?>
                                <img src="<?= HP_UPLOAD_URL . '/' . h($t['photo']) ?>"
                                     alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;"
                                     onerror="this.style.display='none'">
                                <?php else: ?>
                                <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1a3a5c,#253f60);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.5);font-size:.9rem;flex-shrink:0;">
                                    <i class="fas fa-user"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold" style="font-size:.88rem;"><?= h($t['name']) ?></div>
                                    <?php if ($t['designation']): ?>
                                    <div class="text-muted" style="font-size:.75rem;"><?= h($t['designation']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td style="max-width:280px;">
                            <div class="text-muted" style="font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px;">
                                <?= h($t['quote']) ?>
                            </div>
                        </td>
                        <td>
                            <div style="color:#FFB81C;font-size:.75rem;letter-spacing:1px;">
                                <?php for ($s = 1; $s <= 5; $s++): ?>
                                    <i class="<?= $s <= $t['rating'] ? 'fas' : 'far' ?> fa-star"></i>
                                <?php endfor; ?>
                            </div>
                        </td>
                        <td><?= (int)$t['sort_order'] ?></td>
                        <td>
                            <span class="badge <?= $t['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <?php if (can_access('homepage', 'can_edit') || can_access('homepage', 'can_delete')): ?>
                        <td>
                            <div class="d-flex gap-2">
                                <?php if (can_access('homepage', 'can_edit')): ?>
                                <a href="<?= APP_URL ?>/homepage/testimonials-edit.php?id=<?= $t['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (can_access('homepage', 'can_delete')): ?>
                                <form method="POST" action="<?= APP_URL ?>/homepage/testimonials-delete.php"
                                      onsubmit="return confirm('Delete this testimonial?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
