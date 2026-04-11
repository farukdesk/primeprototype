<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/helpers.php';
require_access('cms-glance', 'can_view');

$page_title = 'PU At a Glance';

$db = db();

try { $stats      = $db->query('SELECT * FROM glance_stats     ORDER BY sort_order, id')->fetchAll(); } catch (Throwable $e) { $stats = []; }
try { $highlights = $db->query('SELECT * FROM glance_highlights ORDER BY sort_order, id')->fetchAll(); } catch (Throwable $e) { $highlights = []; }
try { $milestones = $db->query('SELECT * FROM glance_milestones ORDER BY sort_order, id')->fetchAll(); } catch (Throwable $e) { $milestones = []; }

// Officers and messages sourced from Board of Trustees members
try {
    $bot_officers = $db->query(
        "SELECT * FROM governing_body_members
         WHERE page_type='board-of-trustees' AND glance_officer=1
         ORDER BY sort_order, id"
    )->fetchAll();
} catch (Throwable $e) { $bot_officers = []; }

try {
    $bot_messages = $db->query(
        "SELECT * FROM governing_body_members
         WHERE page_type='board-of-trustees'
           AND glance_msg_tab IS NOT NULL AND glance_msg_tab != ''
         ORDER BY sort_order, id"
    )->fetchAll();
} catch (Throwable $e) { $bot_messages = []; }

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">PU At a Glance</li>
        </ol>
    </nav>
    <a href="<?= SITE_URL ?>/pu-at-a-glance.php" target="_blank" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> View Page
    </a>
</div>

<?php flash_show(); ?>

<!-- Quick action cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card h-100" style="border-left:4px solid #002147;">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(0,33,71,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-sliders-h" style="color:#002147;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:.9rem;">Page Settings</div>
                    <small class="text-muted">Hero, About, CTA text</small>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
                <a href="<?= APP_URL ?>/cms/glance/settings-edit.php" class="btn btn-sm btn-primary w-100" style="border-radius:8px;">
                    <i class="fas fa-edit me-1"></i> Edit Settings
                </a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card h-100" style="border-left:4px solid #FFB81C;">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(255,184,28,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-hashtag" style="color:#b45309;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:.9rem;">Quick Stats</div>
                    <small class="text-muted"><?= count($stats) ?> stat items</small>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
                <a href="<?= APP_URL ?>/cms/glance/stats-create.php" class="btn btn-sm btn-warning w-100" style="border-radius:8px;color:#000;">
                    <i class="fas fa-plus me-1"></i> Add Stat
                </a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card h-100" style="border-left:4px solid #1a4faf;">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(26,79,175,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-user-tie" style="color:#1a4faf;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:.9rem;">Key Officers</div>
                    <small class="text-muted"><?= count($bot_officers) ?> selected</small>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
                <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees" class="btn btn-sm btn-primary w-100" style="border-radius:8px;">
                    <i class="fas fa-edit me-1"></i> Manage
                </a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(16,185,129,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-building" style="color:#047857;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:.9rem;">Highlights</div>
                    <small class="text-muted"><?= count($highlights) ?> items</small>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0 pb-3 px-3">
                <a href="<?= APP_URL ?>/cms/glance/highlights-create.php" class="btn btn-sm btn-success w-100" style="border-radius:8px;">
                    <i class="fas fa-plus me-1"></i> Add Highlight
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick Stats ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-hashtag me-2 text-muted"></i>Quick Stats Bar</h6>
        <a href="<?= APP_URL ?>/cms/glance/stats-create.php" class="btn btn-sm btn-primary" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Icon</th>
                        <th>Value</th>
                        <th>Label</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($stats)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No stats yet. <a href="<?= APP_URL ?>/cms/glance/stats-create.php">Add one.</a></td></tr>
                <?php else: ?>
                    <?php foreach ($stats as $i => $s): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><i class="<?= h($s['icon']) ?> fa-fw me-1"></i><small class="text-muted"><?= h($s['icon']) ?></small></td>
                        <td><strong><?= h($s['value']) ?></strong></td>
                        <td><?= h($s['label']) ?></td>
                        <td><?= (int)$s['sort_order'] ?></td>
                        <td><?= $s['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/glance/stats-edit.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"><i class="fas fa-edit"></i></a>
                                <form method="POST" action="<?= APP_URL ?>/cms/glance/stats-delete.php" onsubmit="return confirm('Delete this stat?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;"><i class="fas fa-trash"></i></button>
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

<!-- ── Key Administrative Officers ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <div>
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user-tie me-2 text-muted"></i>Key Administrative Officers</h6>
            <div class="form-text mt-1">Sourced from Board of Trustees. Toggle "Show as Key Administrative Officer" on any member to include them here.</div>
        </div>
        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees"
           class="btn btn-sm btn-primary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Manage Members
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bot_officers)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        No officers selected yet.
                        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees">
                            Go to Board of Trustees members and enable "Show as Key Administrative Officer".</a>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bot_officers as $i => $l): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <?php if ($l['photo']): ?>
                                <img src="<?= UPLOAD_URL ?>/governing-body/<?= h($l['photo']) ?>" alt=""
                                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">No photo</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= h($l['full_name']) ?></strong></td>
                        <td><?= h($l['designation'] ?? '') ?></td>
                        <td><?= (int)$l['sort_order'] ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/governing-body/members/edit.php?id=<?= $l['id'] ?>&page_type=board-of-trustees"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Words from Our Leadership ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <div>
            <h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>Words from Our Leadership</h6>
            <div class="form-text mt-1">Sourced from Board of Trustees. Fill in the "Tab Label" and "Leadership Message" on any member to include them here.</div>
        </div>
        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees"
           class="btn btn-sm btn-primary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Manage Members
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Tab Label</th>
                        <th>Message</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bot_messages)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        No messages added yet.
                        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees">
                            Go to Board of Trustees members and fill in their leadership message.</a>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bot_messages as $i => $m): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td>
                            <?php if ($m['photo']): ?>
                                <img src="<?= UPLOAD_URL ?>/governing-body/<?= h($m['photo']) ?>" alt=""
                                     style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">No photo</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= h($m['full_name']) ?></strong></td>
                        <td><?= h($m['designation'] ?? '') ?></td>
                        <td><span class="badge bg-primary bg-opacity-10 text-primary" style="border-radius:20px;"><?= h($m['glance_msg_tab']) ?></span></td>
                        <td><span class="text-muted" style="font-size:.8rem;"><?= h(mb_strimwidth($m['glance_message'] ?? '', 0, 60, '…')) ?></span></td>
                        <td>
                            <a href="<?= APP_URL ?>/governing-body/members/edit.php?id=<?= $m['id'] ?>&page_type=board-of-trustees"
                               class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Campus Highlights ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-building me-2 text-muted"></i>Campus Highlights</h6>
        <a href="<?= APP_URL ?>/cms/glance/highlights-create.php" class="btn btn-sm btn-primary" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Icon</th>
                        <th>Title</th>
                        <th>Theme</th>
                        <th>Tag</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($highlights)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No highlights yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($highlights as $i => $hl): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><i class="<?= h($hl['icon']) ?> fa-fw"></i></td>
                        <td><strong><?= h($hl['title']) ?></strong></td>
                        <td><code style="font-size:.75rem;"><?= h($hl['color_theme']) ?></code></td>
                        <td><?= h($hl['tag_label']) ?></td>
                        <td><?= (int)$hl['sort_order'] ?></td>
                        <td><?= $hl['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/glance/highlights-edit.php?id=<?= $hl['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"><i class="fas fa-edit"></i></a>
                                <form method="POST" action="<?= APP_URL ?>/cms/glance/highlights-delete.php" onsubmit="return confirm('Delete this highlight?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $hl['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;"><i class="fas fa-trash"></i></button>
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

<!-- ── Milestones ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-history me-2 text-muted"></i>Timeline / Milestones</h6>
        <a href="<?= APP_URL ?>/cms/glance/milestones-create.php" class="btn btn-sm btn-primary" style="border-radius:8px;">
            <i class="fas fa-plus me-1"></i> Add
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Year / Label</th>
                        <th>Title</th>
                        <th>Order</th>
                        <th>Active</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($milestones)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No milestones yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($milestones as $i => $ms): ?>
                    <tr>
                        <td class="px-4"><?= $i + 1 ?></td>
                        <td><span class="badge bg-primary"><?= h($ms['year_label']) ?></span></td>
                        <td><strong><?= h($ms['title']) ?></strong></td>
                        <td><?= (int)$ms['sort_order'] ?></td>
                        <td><?= $ms['is_active'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= APP_URL ?>/cms/glance/milestones-edit.php?id=<?= $ms['id'] ?>" class="btn btn-sm btn-outline-primary" style="border-radius:7px;"><i class="fas fa-edit"></i></a>
                                <form method="POST" action="<?= APP_URL ?>/cms/glance/milestones-delete.php" onsubmit="return confirm('Delete this milestone?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $ms['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;"><i class="fas fa-trash"></i></button>
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
