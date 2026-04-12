<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-chairman');

$page_title = 'Office of the Chairman';

// Load quick summary from settings
$s = [];
try {
    $rows = db()->query('SELECT setting_key, setting_val FROM ch_settings')->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
} catch (Throwable $e) {}

$is_published = ($s['is_published'] ?? '1') === '1';
$ch_name      = $s['ch_name'] ?? 'Not configured';
$ch_title     = $s['ch_title'] ?? '';
$ps_name      = $s['ps_name'] ?? 'Not configured';
$ps_title     = $s['ps_title'] ?? '';
$ch_photo     = $s['ch_photo'] ?? '';

$frontend_base = rtrim(str_replace('/admin', '', APP_URL), '/');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Office of the Chairman</li>
        </ol>
    </nav>
    <a href="<?= $frontend_base ?>/office-of-chairman.php" target="_blank"
       class="btn btn-sm btn-outline-info" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> View Public Page
    </a>
</div>

<?php flash_show(); ?>

<!-- Status Banner -->
<div class="alert <?= $is_published ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center gap-2 mb-4"
     style="border-radius:12px;">
    <i class="fas <?= $is_published ? 'fa-check-circle' : 'fa-eye-slash' ?> fa-lg"></i>
    <span>
        Page is currently <strong><?= $is_published ? 'Published' : 'Unpublished' ?></strong>.
        <?php if (!$is_published): ?>
        <a href="<?= APP_URL ?>/office-of-chairman/settings.php" class="alert-link">Enable publishing in settings.</a>
        <?php endif; ?>
    </span>
</div>

<!-- Section Cards -->
<div class="row g-4">

    <!-- Chairman Profile -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #002147;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <?php if ($ch_photo): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-chairman/<?= h($ch_photo) ?>"
                         style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#002147,#1a4faf);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-gavel" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#002147;">
                            <i class="fas fa-gavel me-2 text-muted" style="font-size:.85rem;"></i>Chairman
                        </h6>
                        <div class="fw-medium"><?= h($ch_name) ?></div>
                        <div style="font-size:.8rem;color:#64748b;"><?= h($ch_title) ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-chairman', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-chairman/settings.php?tab=chairman"
                   class="btn btn-sm btn-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Chairman Profile
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PS Profile -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #1a4faf;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#1a4faf,#3b82f6);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-user" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#1a4faf;">
                            <i class="fas fa-id-badge me-2 text-muted" style="font-size:.85rem;"></i>PS to Chairman
                        </h6>
                        <div class="fw-medium"><?= h($ps_name) ?></div>
                        <div style="font-size:.8rem;color:#64748b;"><?= h($ps_title) ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-chairman', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-chairman/settings.php?tab=ps"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit PS Profile
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Chairman Message -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #D21034;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#D21034,#e8475f);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-quote-left" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#D21034;">Chairman's Message</h6>
                        <div style="font-size:.82rem;color:#64748b;">
                            <?= h($s['message_title'] ?? 'Message from the Chairman') ?>
                        </div>
                        <div class="mt-2" style="font-size:.78rem;color:#94a3b8;">
                            <?= strlen($s['message_body'] ?? '') > 0
                                ? number_format(str_word_count($s['message_body'] ?? '')) . ' words'
                                : 'No message set' ?>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-chairman', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-chairman/settings.php?tab=message"
                   class="btn btn-sm btn-outline-danger" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Message
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Page Settings -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #2c6e49;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#2c6e49,#40916c);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-cog" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#2c6e49;">Page Settings</h6>
                        <div style="font-size:.82rem;color:#64748b;">
                            <?= h($s['hero_title'] ?? 'Office of the Chairman') ?>
                        </div>
                        <div class="mt-2">
                            <span class="badge" style="background:<?= $is_published ? '#dcfce7' : '#fef9c3' ?>;color:<?= $is_published ? '#166534' : '#854d0e' ?>;font-size:.73rem;border-radius:20px;">
                                <?= $is_published ? 'Published' : 'Unpublished' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-chairman', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-chairman/settings.php?tab=page"
                   class="btn btn-sm btn-outline-success" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-cog me-1"></i> Page Settings
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
