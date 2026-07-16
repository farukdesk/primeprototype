<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

$id = (int)($_GET['id'] ?? 0);

// Admins (or super admins) can view any employee profile. Other staff may only
// view their own profile, provided they have staff-profile view access.
$is_admin = is_super_admin() || sp_is_admin();
if (!$is_admin) {
    if ($id !== (int)auth_user()['id'] || !can_access('staff-profile', 'can_view')) {
        require_access('staff-profile', 'can_view');
    }
}

$data = $id > 0 ? sp_load_full_profile($id) : null;
if (!$data || empty($data['staff']) || empty($data['staff']['department_type'])) {
    http_response_code(404);
    $page_title = 'Employee Profile';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">Employee profile not found.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$page_title = 'Employee Profile – ' . ($data['user']['full_name'] ?? '');
$can_edit   = is_super_admin() || can_access('staff-profile', 'can_edit');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-profiles/index.php">Employee Profiles</a></li>
            <li class="breadcrumb-item active"><?= h($data['user']['full_name'] ?? '') ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/staff-profiles/cv-pdf.php?id=<?= (int)$id ?>"
           class="btn btn-danger btn-sm" style="border-radius:8px;">
            <i class="fas fa-file-pdf me-1"></i> Download CV (PDF)
        </a>
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/users/edit.php?id=<?= (int)$id ?>"
           class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-4">
        <?= sp_render_cv_html($data, false) ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
