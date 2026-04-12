<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('office-of-coe');

$page_title = 'Office of COE';

$s = [];
try {
    $rows = db()->query('SELECT setting_key, setting_val FROM coe_settings')->fetchAll();
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_val'];
} catch (Throwable $e) {}

$staff_count = 0;
try { $staff_count = (int)db()->query('SELECT COUNT(*) FROM coe_staff')->fetchColumn(); } catch (Throwable $e) {}

$is_published = ($s['is_published'] ?? '1') === '1';
$coe_name     = $s['coe_name'] ?? 'Not configured';
$coe_title    = $s['coe_title'] ?? '';
$coe_photo    = $s['coe_photo'] ?? '';

$frontend_base = rtrim(str_replace('/admin', '', APP_URL), '/');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Office of COE</li>
        </ol>
    </nav>
    <a href="<?= $frontend_base ?>/office-of-coe.php" target="_blank"
       class="btn btn-sm btn-outline-info" style="border-radius:8px;">
        <i class="fas fa-external-link-alt me-1"></i> View Public Page
    </a>
</div>

<?php flash_show(); ?>

<div class="alert <?= $is_published ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center gap-2 mb-4" style="border-radius:12px;">
    <i class="fas <?= $is_published ? 'fa-check-circle' : 'fa-eye-slash' ?> fa-lg"></i>
    <span>Page is currently <strong><?= $is_published ? 'Published' : 'Unpublished' ?></strong>.
        <?php if (!$is_published): ?>
        <a href="<?= APP_URL ?>/office-of-coe/settings.php" class="alert-link">Enable publishing in settings.</a>
        <?php endif; ?></span>
</div>

<div class="row g-4">

    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #002147;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <?php if ($coe_photo): ?>
                    <img src="<?= UPLOAD_URL ?>/office-of-coe/<?= h($coe_photo) ?>"
                         style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #e8eaf0;flex-shrink:0;"
                         alt="<?= h($coe_name) ?> – COE Photo">
                    <?php else: ?>
                    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#002147,#1a4faf);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-scroll" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#002147;"><i class="fas fa-scroll me-2 text-muted" style="font-size:.85rem;"></i>Controller of Examinations</h6>
                        <div class="fw-medium"><?= h($coe_name) ?></div>
                        <div style="font-size:.8rem;color:#64748b;"><?= h($coe_title) ?></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-coe', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-coe/settings.php?tab=coe" class="btn btn-sm btn-primary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit COE Profile
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #7c3aed;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#a855f7);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-users" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#7c3aed;">Office Staff</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= $staff_count ?> staff member<?= $staff_count !== 1 ? 's' : '' ?> on record</div>
                        <div class="mt-2"><span class="badge" style="background:#ede9fe;color:#5b21b6;font-size:.73rem;border-radius:20px;">Staff Directory</span></div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <a href="<?= APP_URL ?>/office-of-coe/staff-index.php" class="btn btn-sm btn-outline-secondary me-2" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-list me-1"></i> Manage Staff
                </a>
                <?php if (can_access('office-of-coe', 'can_create')): ?>
                <a href="<?= APP_URL ?>/office-of-coe/staff-create.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-plus me-1"></i> Add Staff
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #D21034;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#D21034,#e8475f);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-quote-left" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#D21034;">COE's Message</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= h($s['message_title'] ?? 'Message from the Controller of Examinations') ?></div>
                        <div class="mt-2" style="font-size:.78rem;color:#94a3b8;">
                            <?= strlen($s['message_body'] ?? '') > 0 ? number_format(str_word_count($s['message_body'] ?? '')) . ' words' : 'No message set' ?>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-coe', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-coe/settings.php?tab=message" class="btn btn-sm btn-outline-danger" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-edit me-1"></i> Edit Message
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-6 col-lg-6">
        <div class="card h-100" style="border-top:4px solid #2c6e49;border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#2c6e49,#40916c);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas fa-cog" style="color:#fff;font-size:1.3rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-1 fw-semibold" style="color:#2c6e49;">Page Settings</h6>
                        <div style="font-size:.82rem;color:#64748b;"><?= h($s['hero_title'] ?? 'Controller of Examinations') ?></div>
                        <div class="mt-2">
                            <span class="badge" style="background:<?= $is_published ? '#dcfce7' : '#fef9c3' ?>;color:<?= $is_published ? '#166534' : '#854d0e' ?>;font-size:.73rem;border-radius:20px;"><?= $is_published ? 'Published' : 'Unpublished' ?></span>
                        </div>
                    </div>
                </div>
                <hr style="border-color:#f1f5f9;margin:16px 0 12px;">
                <?php if (can_access('office-of-coe', 'can_edit')): ?>
                <a href="<?= APP_URL ?>/office-of-coe/settings.php?tab=page" class="btn btn-sm btn-outline-success" style="border-radius:8px;font-size:.8rem;">
                    <i class="fas fa-cog me-1"></i> Page Settings
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
