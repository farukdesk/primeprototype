<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('law-legal');
require_once __DIR__ . '/helpers.php';

$page_title = 'Law & Legal Affairs';

$s = ll_load_settings();
$is_published = ll_s($s, 'is_published', '1') === '1';

$adviser_name   = ll_s($s, 'adviser_name',   'Not configured');
$adviser_title  = ll_s($s, 'adviser_title',  '');
$assistant_name = ll_s($s, 'assistant_name', 'Not configured');
$asst_title     = ll_s($s, 'assistant_title', '');

$staff_count   = 0;
$notice_count  = 0;
$service_count = 0;
try {
    $staff_count   = (int)db()->query('SELECT COUNT(*) FROM ll_staff   WHERE is_active = 1')->fetchColumn();
    $notice_count  = (int)db()->query('SELECT COUNT(*) FROM ll_notices WHERE is_active = 1')->fetchColumn();
    $service_count = (int)db()->query('SELECT COUNT(*) FROM ll_services WHERE is_active = 1')->fetchColumn();
} catch (Throwable $e) {}

$frontend_base = rtrim(str_replace('/admin', '', APP_URL), '/');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Law &amp; Legal Affairs</li>
        </ol>
    </nav>
    <a href="<?= $frontend_base ?>/law-legal.php" target="_blank"
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
        <a href="<?= APP_URL ?>/law-legal/settings.php" class="alert-link">Enable publishing in settings.</a>
        <?php endif; ?>
    </span>
</div>

<!-- Overview Cards -->
<div class="row g-4">

    <!-- Adviser -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #1e3a5f;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <?php $ap = ll_s($s, 'adviser_photo', ''); ?>
                    <?php if ($ap): ?>
                    <img src="<?= UPLOAD_URL ?>/law-legal/<?= h($ap) ?>"
                         style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#2563eb);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-user-tie" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#1e3a5f;">
                            <i class="fas fa-gavel me-2 text-muted" style="font-size:.85rem;"></i>Adviser
                        </h6>
                        <div class="fw-medium"><?= h($adviser_name) ?></div>
                        <div style="font-size:.8rem;color:#64748b;"><?= h($adviser_title) ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (ll_can_edit()): ?>
                <a href="<?= APP_URL ?>/law-legal/settings.php?tab=adviser"
                   class="btn btn-sm btn-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Adviser
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Assistant Adviser -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #2563eb;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <?php $aph = ll_s($s, 'assistant_photo', ''); ?>
                    <?php if ($aph): ?>
                    <img src="<?= UPLOAD_URL ?>/law-legal/<?= h($aph) ?>"
                         style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;" alt="">
                    <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-user" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#2563eb;">
                            <i class="fas fa-id-badge me-2 text-muted" style="font-size:.85rem;"></i>Assistant Adviser
                        </h6>
                        <div class="fw-medium"><?= h($assistant_name) ?></div>
                        <div style="font-size:.8rem;color:#64748b;"><?= h($asst_title) ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (ll_can_edit()): ?>
                <a href="<?= APP_URL ?>/law-legal/settings.php?tab=assistant"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Assistant
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Staff Members -->
    <div class="col-xl-4 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #7c3aed;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#a855f7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-users" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#7c3aed;">Staff Members</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= $staff_count ?> active member<?= $staff_count !== 1 ? 's' : '' ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <a href="<?= APP_URL ?>/law-legal/staff-index.php"
                   class="btn btn-sm btn-outline-secondary me-2" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-list me-1"></i> Manage
                </a>
                <?php if (ll_can_create()): ?>
                <a href="<?= APP_URL ?>/law-legal/staff-create.php"
                   class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-plus me-1"></i> Add
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notices -->
    <div class="col-xl-4 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #D21034;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#D21034,#e8475f);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-bell" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#D21034;">Notices &amp; Circulars</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= $notice_count ?> active notice<?= $notice_count !== 1 ? 's' : '' ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <a href="<?= APP_URL ?>/law-legal/notice-index.php"
                   class="btn btn-sm btn-outline-danger me-2" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-list me-1"></i> Manage
                </a>
                <?php if (ll_can_create()): ?>
                <a href="<?= APP_URL ?>/law-legal/notice-create.php"
                   class="btn btn-sm btn-outline-danger" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-plus me-1"></i> Add
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Services -->
    <div class="col-xl-4 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #2c6e49;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#2c6e49,#40916c);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-balance-scale" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#2c6e49;">Legal Services</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= $service_count ?> active service<?= $service_count !== 1 ? 's' : '' ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <a href="<?= APP_URL ?>/law-legal/service-index.php"
                   class="btn btn-sm btn-outline-success me-2" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-list me-1"></i> Manage
                </a>
                <?php if (ll_can_create()): ?>
                <a href="<?= APP_URL ?>/law-legal/service-create.php"
                   class="btn btn-sm btn-outline-success" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-plus me-1"></i> Add
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Page Settings -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #475569;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#475569,#64748b);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-cog" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#475569;">Page Settings</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= h(ll_s($s, 'hero_title', 'Law & Legal Affairs')) ?></div>
                        <div class="mt-2">
                            <span class="badge" style="background:<?= $is_published ? '#dcfce7' : '#fef9c3' ?>;color:<?= $is_published ? '#166534' : '#854d0e' ?>;font-size:.73rem;border-radius:20px;">
                                <?= $is_published ? 'Published' : 'Unpublished' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (ll_can_edit()): ?>
                <a href="<?= APP_URL ?>/law-legal/settings.php"
                   class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-cog me-1"></i> Page Settings
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Message -->
    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #0369a1;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#0369a1,#0ea5e9);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-quote-left" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#0369a1;">Adviser's Message</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= h(ll_s($s, 'message_title', 'Message from the Adviser')) ?></div>
                        <div class="mt-2" style="font-size:.78rem;color:#94a3b8;">
                            <?= strlen(ll_s($s, 'message_body', '')) > 0
                                ? number_format(str_word_count(ll_s($s, 'message_body', ''))) . ' words'
                                : 'No message set' ?>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (ll_can_edit()): ?>
                <a href="<?= APP_URL ?>/law-legal/settings.php?tab=message"
                   class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Message
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
