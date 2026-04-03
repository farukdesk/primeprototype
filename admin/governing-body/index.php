<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('governing-body');

$page_title = 'Governing Body';

$types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];

$pages_data = [];
foreach ($types as $type) {
    $s = db()->prepare('SELECT * FROM governing_body_pages WHERE page_type = ? LIMIT 1');
    $s->execute([$type]);
    $row = $s->fetch() ?: [
        'page_type'   => $type,
        'title'       => ucwords(str_replace('-', ' ', $type)),
        'subtitle'    => '',
        'is_published' => 1,
    ];

    $c = db()->prepare('SELECT COUNT(*) FROM governing_body_members WHERE page_type = ?');
    $c->execute([$type]);
    $row['member_count'] = (int)$c->fetchColumn();

    $pages_data[$type] = $row;
}

$icons = [
    'board-of-trustees'   => 'fas fa-landmark',
    'pu-syndicates'       => 'fas fa-balance-scale',
    'deans'               => 'fas fa-user-tie',
    'head-of-departments' => 'fas fa-chalkboard-teacher',
];

$colors = [
    'board-of-trustees'   => '#002147',
    'pu-syndicates'       => '#1a3d6e',
    'deans'               => '#D21034',
    'head-of-departments' => '#2c6e49',
];

$frontend_base = rtrim(str_replace('/admin', '', APP_URL), '/');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Governing Body</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<div class="row g-4">
<?php foreach ($pages_data as $type => $pd): ?>
<div class="col-xl-6 col-lg-6">
    <div class="card h-100" style="border-top:4px solid <?= $colors[$type] ?>;border-radius:12px;">
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-3">
                <div style="width:54px;height:54px;border-radius:12px;background:<?= $colors[$type] ?>;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="<?= $icons[$type] ?>" style="color:#fff;font-size:1.4rem;"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h5 class="mb-0 fw-semibold" style="color:#002147;"><?= h($pd['title']) ?></h5>
                        <?= $pd['is_published']
                            ? '<span class="badge bg-success" style="font-size:.7rem;">Published</span>'
                            : '<span class="badge bg-warning text-dark" style="font-size:.7rem;">Unpublished</span>' ?>
                    </div>
                    <?php if (!empty($pd['subtitle'])): ?>
                    <div style="font-size:.82rem;color:#64748b;"><?= h($pd['subtitle']) ?></div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.78rem;font-weight:500;">
                            <i class="fas fa-users me-1"></i><?= $pd['member_count'] ?> member<?= $pd['member_count'] !== 1 ? 's' : '' ?>
                        </span>
                    </div>
                </div>
            </div>
            <hr style="border-color:#f1f5f9;margin:18px 0 14px;">
            <div class="d-flex gap-2 flex-wrap">
                <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($type) ?>"
                   class="btn btn-sm btn-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-users me-1"></i> Manage Members
                </a>
                <a href="<?= APP_URL ?>/governing-body/settings.php?page_type=<?= urlencode($type) ?>"
                   class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-cog me-1"></i> Settings
                </a>
                <a href="<?= $frontend_base ?>/<?= h($type) ?>.php" target="_blank"
                   class="btn btn-sm btn-outline-info" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-external-link-alt me-1"></i> View Page
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
