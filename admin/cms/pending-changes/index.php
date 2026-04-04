<?php
require_once __DIR__ . '/../../includes/auth.php';
require_super_admin();

$page_title = 'Pending Approvals';

// Filters
$filter_module = $_GET['module']    ?? '';
$filter_action = $_GET['action']    ?? '';
$filter_status = $_GET['status']    ?? 'pending';
$filter_record = (int)($_GET['record_id'] ?? 0);

$where  = [];
$params = [];

if (in_array($filter_module, ['news','notice'], true)) {
    $where[]  = 'pc.module = ?';
    $params[] = $filter_module;
}
if (in_array($filter_action, ['EDIT','DELETE'], true)) {
    $where[]  = 'pc.action = ?';
    $params[] = $filter_action;
}
if (in_array($filter_status, ['pending','approved','rejected'], true)) {
    $where[]  = 'pc.status = ?';
    $params[] = $filter_status;
}
if ($filter_record > 0) {
    $where[]  = 'pc.record_id = ?';
    $params[] = $filter_record;
}

$sql = 'SELECT pc.*, u.name AS requester_name, r.name AS reviewer_name
        FROM cms_pending_changes pc
        LEFT JOIN users u ON u.id = pc.requested_by
        LEFT JOIN users r ON r.id = pc.reviewed_by'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY pc.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$changes = $stmt->fetchAll();

// Summary counts
$counts = db()->query(
    "SELECT status, COUNT(*) AS cnt FROM cms_pending_changes GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Pending new-post approvals
$pending_news_creates    = (int)db()->query("SELECT COUNT(*) FROM cms_news    WHERE is_approved=0")->fetchColumn();
$pending_notice_creates  = (int)db()->query("SELECT COUNT(*) FROM cms_notices WHERE is_approved=0")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Pending Approvals</li>
        </ol>
    </nav>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center border-0" style="background:#fff3cd;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#856404;"><?= (int)($counts['pending'] ?? 0) ?></div>
                <div style="font-size:.8rem;color:#856404;">Pending Changes</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0" style="background:#d1e7dd;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#0a3622;"><?= (int)($counts['approved'] ?? 0) ?></div>
                <div style="font-size:.8rem;color:#0a3622;">Approved</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0" style="background:#f8d7da;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#58151c;"><?= (int)($counts['rejected'] ?? 0) ?></div>
                <div style="font-size:.8rem;color:#58151c;">Rejected</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center border-0" style="background:#cfe2ff;">
            <div class="card-body py-3">
                <div style="font-size:1.6rem;font-weight:700;color:#084298;"><?= $pending_news_creates + $pending_notice_creates ?></div>
                <div style="font-size:.8rem;color:#084298;">New Posts Pending</div>
            </div>
        </div>
    </div>
</div>

<?php if ($pending_news_creates > 0 || $pending_notice_creates > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4" style="border-radius:10px;">
    <i class="fas fa-clock fa-lg"></i>
    <div>
        <strong>New post approvals needed:</strong>
        <?php if ($pending_news_creates > 0): ?>
            <a href="<?= APP_URL ?>/cms/news/index.php?approval=pending" class="badge bg-warning text-dark text-decoration-none ms-1">
                <?= $pending_news_creates ?> news article<?= $pending_news_creates > 1 ? 's' : '' ?>
            </a>
        <?php endif; ?>
        <?php if ($pending_notice_creates > 0): ?>
            <a href="<?= APP_URL ?>/cms/notice-board/index.php?approval=pending" class="badge bg-warning text-dark text-decoration-none ms-1">
                <?= $pending_notice_creates ?> notice<?= $pending_notice_creates > 1 ? 's' : '' ?>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-center">
            <select name="module" class="form-select" style="max-width:150px;border-radius:10px;">
                <option value="">All modules</option>
                <option value="news"   <?= $filter_module === 'news'   ? 'selected' : '' ?>>News</option>
                <option value="notice" <?= $filter_module === 'notice' ? 'selected' : '' ?>>Notice Board</option>
            </select>
            <select name="action" class="form-select" style="max-width:150px;border-radius:10px;">
                <option value="">All actions</option>
                <option value="EDIT"   <?= $filter_action === 'EDIT'   ? 'selected' : '' ?>>Edit</option>
                <option value="DELETE" <?= $filter_action === 'DELETE' ? 'selected' : '' ?>>Delete</option>
            </select>
            <select name="status" class="form-select" style="max-width:160px;border-radius:10px;">
                <option value="">All statuses</option>
                <option value="pending"  <?= $filter_status === 'pending'  ? 'selected' : '' ?>>Pending</option>
                <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                <option value="rejected" <?= $filter_status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <button class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($filter_module || $filter_action || $filter_status !== 'pending' || $filter_record): ?>
            <a href="<?= APP_URL ?>/cms/pending-changes/index.php" class="btn btn-light" style="border-radius:10px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Record</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Requested By</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($changes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-2x mb-3 d-block text-muted opacity-50"></i>
                        No pending changes found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($changes as $idx => $ch): ?>
                    <tr>
                        <td class="px-4"><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= h($ch['record_title']) ?></strong>
                            <div style="font-size:.75rem;color:#9ca3af;">ID #<?= $ch['record_id'] ?></div>
                        </td>
                        <td>
                            <?php if ($ch['module'] === 'news'): ?>
                                <span class="badge bg-primary"><i class="fas fa-newspaper me-1"></i>News</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark"><i class="fas fa-bullhorn me-1"></i>Notice</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ch['action'] === 'DELETE'): ?>
                                <span class="badge bg-danger"><i class="fas fa-trash me-1"></i>Delete</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><i class="fas fa-edit me-1"></i>Edit</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($ch['requester_name'] ?? '—') ?></td>
                        <td style="white-space:nowrap;"><?= date('M d, Y H:i', strtotime($ch['created_at'])) ?></td>
                        <td>
                            <?php if ($ch['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>
                            <?php elseif ($ch['status'] === 'approved'): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Approved</span>
                                <?php if ($ch['reviewer_name']): ?>
                                <div style="font-size:.72rem;color:#9ca3af;">by <?= h($ch['reviewer_name']) ?><br><?= date('M d, Y', strtotime($ch['reviewed_at'])) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Rejected</span>
                                <?php if ($ch['review_note']): ?>
                                <div style="font-size:.72rem;color:#9ca3af;" title="<?= h($ch['review_note']) ?>">
                                    <?= h(mb_strimwidth($ch['review_note'], 0, 40, '…')) ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ch['status'] === 'pending'): ?>
                            <div class="d-flex gap-1 flex-wrap">
                                <!-- View change details (modal trigger) -->
                                <?php if ($ch['action'] === 'EDIT' && $ch['payload']): ?>
                                <button class="btn btn-sm btn-outline-secondary" style="border-radius:7px;"
                                        data-bs-toggle="modal" data-bs-target="#diffModal<?= $ch['id'] ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Approve -->
                                <form method="POST" action="<?= APP_URL ?>/cms/pending-changes/review.php"
                                      onsubmit="return confirm('Approve this <?= strtolower($ch['action']) ?> request?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id"     value="<?= $ch['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-sm btn-success" style="border-radius:7px;" title="Approve">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>

                                <!-- Reject -->
                                <button class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                                        onclick="showRejectForm(<?= $ch['id'] ?>)" title="Reject">
                                    <i class="fas fa-times"></i>
                                </button>
                                <!-- Inline reject note form -->
                                <form method="POST" action="<?= APP_URL ?>/cms/pending-changes/review.php"
                                      id="rejectForm<?= $ch['id'] ?>" style="display:none;width:100%;margin-top:.4rem;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id"     value="<?= $ch['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="review_note" class="form-control"
                                               placeholder="Reason (optional)" style="border-radius:7px 0 0 7px;">
                                        <button type="submit" class="btn btn-danger" style="border-radius:0 7px 7px 0;">Reject</button>
                                    </div>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($ch['action'] === 'EDIT' && $ch['payload']): ?>
                    <!-- Diff modal for this change -->
                    <div class="modal fade" id="diffModal<?= $ch['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Proposed Edit — <?= h($ch['record_title']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <?php
                                    $p = json_decode($ch['payload'], true) ?? [];
                                    $fields = [
                                        'title'          => 'Title',
                                        'content_type'   => 'Content Type',
                                        'is_published'   => 'Published',
                                        'published_at'   => 'Publish Date',
                                        'publish_as_news'=> 'Also as News',
                                    ];
                                    ?>
                                    <table class="table table-sm table-bordered mb-3">
                                        <thead class="table-light">
                                            <tr><th>Field</th><th>Proposed Value</th></tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($fields as $fk => $fl): if (!isset($p[$fk])) continue; ?>
                                        <tr>
                                            <td class="fw-medium"><?= $fl ?></td>
                                            <td><?= h((string)$p[$fk]) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (!empty($p['featured_image_new'])): ?>
                                        <tr>
                                            <td class="fw-medium">New Featured Image</td>
                                            <td>
                                                <img src="<?= UPLOAD_URL ?>/news/<?= h($p['featured_image_new']) ?>"
                                                     style="max-height:80px;border-radius:4px;" alt="">
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if (!empty($p['featured_image_remove'])): ?>
                                        <tr><td class="fw-medium">Featured Image</td><td class="text-danger">Will be removed</td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($p['attachment_new'])): ?>
                                        <tr>
                                            <td class="fw-medium">New Attachment</td>
                                            <td><?= h($p['attachment_original_name'] ?? $p['attachment_new']) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if (!empty($p['attachment_remove'])): ?>
                                        <tr><td class="fw-medium">Attachment</td><td class="text-danger">Will be removed</td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($p['new_attachments'])): ?>
                                        <tr>
                                            <td class="fw-medium">New Attachments</td>
                                            <td>
                                                <?php foreach ($p['new_attachments'] as $na): ?>
                                                    <div><?= h($na['original_name']) ?> <small class="text-muted">(<?= number_format($na['size']/1024,1) ?> KB)</small></div>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <?php if (!empty($p['content'])): ?>
                                    <label class="fw-medium mb-1">Proposed Content:</label>
                                    <div class="border rounded p-3" style="max-height:300px;overflow-y:auto;background:#f9fafb;font-size:.875rem;">
                                        <?php if (($p['content_type'] ?? 'text') === 'html'): ?>
                                            <?= $p['content'] ?>
                                        <?php else: ?>
                                            <?= nl2br(h($p['content'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function showRejectForm(id) {
    var form = document.getElementById('rejectForm' + id);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
