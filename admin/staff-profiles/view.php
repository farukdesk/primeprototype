<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';

sp_ensure_view_module();

$id = (int)($_GET['id'] ?? 0);

// Admins (or super admins) and holders of the read-only Employee Profile View
// module can view any employee profile. Other staff may only view their own
// profile, provided they have staff-profile view access.
$is_admin = is_super_admin() || sp_is_admin();
if (!$is_admin && !can_access('staff-profile-view', 'can_view')) {
    if ($id !== (int)auth_user()['id'] || !can_access('staff-profile', 'can_view')) {
        require_access('staff-profile-view', 'can_view');
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

// Leave record + titled file attachments (Issues/Complaints, Evaluations).
$leave_summary = sp_leave_summary($id);
$profile_files = [];
foreach (SP_FILE_CATEGORIES as $cat_key => $cat_label) {
    $profile_files[$cat_key] = sp_profile_files($id, $cat_key);
}

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

<!-- ── Leave Record ─────────────────────────────────────────────────────── -->
<div class="card mt-4" id="sp-leave-record">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-umbrella-beach me-2 text-muted"></i>Leave Record
            <?php if ($leave_summary): ?>
            <span class="badge bg-secondary ms-1"><?= (int)$leave_summary['year'] ?></span>
            <?php endif; ?>
        </h6>
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/leave-management/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:8px;">
            <i class="fas fa-external-link-alt me-1"></i> Leave Management
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if ($leave_summary === null): ?>
        <p class="text-muted p-4 mb-0">The Leave Management module is not set up yet.</p>
        <?php elseif (empty($leave_summary['rows'])): ?>
        <p class="text-muted p-4 mb-0">No approved leave taken in <?= (int)$leave_summary['year'] ?>.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Leave Segment</th>
                        <th class="text-end">Taken This Month (<?= h(date('M Y')) ?>)</th>
                        <th class="text-end">Taken This Year (<?= (int)$leave_summary['year'] ?>)</th>
                        <th class="text-end">Yearly Quota</th>
                        <th class="text-end">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leave_summary['rows'] as $lr): ?>
                <tr>
                    <td><?= lm_category_badge($lr['category']) ?></td>
                    <td class="text-end"><?= h(rtrim(rtrim(number_format($lr['month'], 1), '0'), '.')) ?> day(s)</td>
                    <td class="text-end"><?= h(rtrim(rtrim(number_format($lr['year'], 1), '0'), '.')) ?> day(s)</td>
                    <td class="text-end">
                        <?= $lr['quota'] === null ? '<span class="text-muted">No limit</span>'
                            : h(rtrim(rtrim(number_format($lr['quota'], 1), '0'), '.')) . ' day(s)' ?>
                    </td>
                    <td class="text-end">
                        <?php if ($lr['remaining'] === null): ?>
                        <span class="text-muted">—</span>
                        <?php else: ?>
                        <span class="badge <?= $lr['remaining'] <= 0 ? 'bg-danger' : 'bg-success' ?>">
                            <?= h(rtrim(rtrim(number_format($lr['remaining'], 1), '0'), '.')) ?> day(s)
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Titled file attachments: Issues/Complaints & Evaluation Records ──── -->
<?php foreach (SP_FILE_CATEGORIES as $cat_key => $cat_label): ?>
<div class="card mt-4" id="sp-files-<?= h($cat_key) ?>">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas <?= $cat_key === 'issue' ? 'fa-exclamation-circle' : 'fa-clipboard-check' ?> me-2 text-muted"></i>
            <?= h($cat_label) ?>
            <span class="badge bg-secondary ms-1"><?= count($profile_files[$cat_key]) ?></span>
        </h6>
    </div>
    <div class="card-body p-0">
        <?php if ($can_edit): ?>
        <form method="POST" action="<?= APP_URL ?>/staff-profiles/file-action.php"
              enctype="multipart/form-data" class="row g-2 align-items-end p-3 border-bottom bg-light">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="user_id" value="<?= (int)$id ?>">
            <input type="hidden" name="category" value="<?= h($cat_key) ?>">
            <div class="col-md-5">
                <label class="form-label small fw-medium mb-1">Title</label>
                <input type="text" name="title" class="form-control" style="border-radius:10px;"
                       maxlength="255" required placeholder="e.g. <?= $cat_key === 'issue' ? 'Complaint letter – March' : 'Annual evaluation report' ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-medium mb-1">File</label>
                <input type="file" name="file" class="form-control" style="border-radius:10px;" required
                       accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx">
                <small class="text-muted">pdf, images, doc, xls. Max 10 MB.</small>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-upload me-1"></i> Upload
                </button>
            </div>
        </form>
        <?php endif; ?>
        <?php if (empty($profile_files[$cat_key])): ?>
        <p class="text-muted p-4 mb-0">No files uploaded yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>Uploaded By</th>
                        <th>Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($profile_files[$cat_key] as $f): ?>
                <tr>
                    <td class="fw-medium"><?= h($f['title']) ?></td>
                    <td class="text-muted small"><?= h($f['original_name'] ?: $f['file_name']) ?></td>
                    <td class="text-muted small"><?= h(sp_fmt_bytes($f['file_size'] !== null ? (int)$f['file_size'] : null)) ?></td>
                    <td class="text-muted small"><?= h($f['uploader_name'] ?? '—') ?></td>
                    <td class="text-muted small text-nowrap"><?= h(date('d M Y, h:i A', strtotime($f['created_at']))) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= APP_URL ?>/staff-profiles/file-download.php?id=<?= (int)$f['id'] ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:8px;" title="Download">
                            <i class="fas fa-download"></i>
                        </a>
                        <?php if ($can_edit): ?>
                        <form method="POST" action="<?= APP_URL ?>/staff-profiles/file-action.php" class="d-inline"
                              onsubmit="return confirm('Delete this file?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="category" value="<?= h($cat_key) ?>">
                            <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
