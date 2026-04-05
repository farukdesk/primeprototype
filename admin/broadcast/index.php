<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('broadcast');
require_once __DIR__ . '/helpers.php';

$page_title = 'Broadcast';
$user       = auth_user();

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_type   = $_GET['type']   ?? '';
$search        = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if (in_array($filter_status, ['draft','sent','partial','pending_approval','rejected'], true)) {
    $where[]  = 'b.status = ?';
    $params[] = $filter_status;
}
if (in_array($filter_type, ['individual','group','all'], true)) {
    $where[]  = 'b.recipient_type = ?';
    $params[] = $filter_type;
}
if ($search !== '') {
    $where[]  = 'b.subject LIKE ?';
    $params[] = '%' . $search . '%';
}

$sql = 'SELECT b.*,
               u.full_name  AS sender_name,
               ug.name      AS group_name,
               ru.full_name AS user_name
        FROM broadcasts b
        LEFT JOIN users      u  ON u.id  = b.sent_by
        LEFT JOIN user_groups ug ON ug.id = b.recipient_group_id
        LEFT JOIN users      ru ON ru.id = b.recipient_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY b.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$broadcasts = $stmt->fetchAll();

// Count broadcasts awaiting approval (for alert)
$pending_bc_count = (int)db()->query(
    "SELECT COUNT(*) FROM broadcasts WHERE status = 'pending_approval'"
)->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-bullhorn me-2 text-primary"></i>Broadcast</h1>
        <p class="text-muted small mb-0">Send emails to individual users, groups, or all registered users.</p>
    </div>
    <?php if (bc_is_staff()): ?>
    <a href="<?= APP_URL ?>/broadcast/compose.php" class="btn btn-primary">
        <i class="fas fa-paper-plane me-1"></i> Compose Broadcast
    </a>
    <?php endif; ?>
</div>

<?php flash_show(); ?>

<?php if ($pending_bc_count > 0 && is_super_admin()): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-4" style="border-radius:10px;">
    <i class="fas fa-clock fa-lg"></i>
    <div>
        <strong><?= $pending_bc_count ?> broadcast<?= $pending_bc_count > 1 ? 's' : '' ?> awaiting admin approval.</strong>
        <a href="<?= APP_URL ?>/cms/pending-changes/index.php?module=broadcast" class="ms-2 btn btn-sm btn-info text-white">
            Review Now
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="get" class="row g-2 mb-4">
    <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="Search by subject…" value="<?= h($search) ?>">
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <option value="sent"             <?= $filter_status === 'sent'             ? 'selected' : '' ?>>Sent</option>
            <option value="partial"          <?= $filter_status === 'partial'          ? 'selected' : '' ?>>Partial</option>
            <option value="pending_approval" <?= $filter_status === 'pending_approval' ? 'selected' : '' ?>>Pending Approval</option>
            <option value="rejected"         <?= $filter_status === 'rejected'         ? 'selected' : '' ?>>Rejected</option>
            <option value="draft"            <?= $filter_status === 'draft'            ? 'selected' : '' ?>>Draft</option>
        </select>
    </div>
    <div class="col-md-2">
        <select name="type" class="form-select">
            <option value="">All Types</option>
            <option value="all"        <?= $filter_type === 'all'        ? 'selected' : '' ?>>All Users</option>
            <option value="group"      <?= $filter_type === 'group'      ? 'selected' : '' ?>>Group</option>
            <option value="individual" <?= $filter_type === 'individual' ? 'selected' : '' ?>>Individual</option>
        </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-outline-secondary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="<?= APP_URL ?>/broadcast/index.php" class="btn btn-outline-secondary"><i class="fas fa-times"></i></a>
    </div>
</form>

<!-- Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($broadcasts)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-bullhorn fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No broadcasts found.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Subject</th>
                        <th>Recipients</th>
                        <th>Sent / Failed</th>
                        <th>Status</th>
                        <th>Sent By</th>
                        <th>Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($broadcasts as $bc): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/broadcast/view.php?id=<?= $bc['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= h($bc['subject']) ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        $icon = match($bc['recipient_type']) {
                            'all'   => '<i class="fas fa-users text-success me-1"></i>',
                            'group' => '<i class="fas fa-layer-group text-info me-1"></i>',
                            default => '<i class="fas fa-user text-warning me-1"></i>',
                        };
                        echo $icon . bc_recipient_label($bc);
                        ?>
                    </td>
                    <td>
                        <span class="text-success fw-semibold"><?= $bc['sent_count'] ?></span>
                        <?php if ($bc['failed_count'] > 0): ?>
                        / <span class="text-danger"><?= $bc['failed_count'] ?> failed</span>
                        <?php endif; ?>
                    </td>
                    <td><?= bc_status_badge($bc['status']) ?>
                        <?php if ($bc['status'] === 'rejected' && !empty($bc['review_note'])): ?>
                        <div class="text-muted mt-1" style="font-size:.75rem;" title="<?= h($bc['review_note']) ?>">
                            <?= h(mb_strimwidth($bc['review_note'], 0, 50, '…')) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($bc['sender_name'] ?? '—') ?></td>
                    <td>
                        <span title="<?= h($bc['created_at']) ?>">
                            <?= h(date('d M Y', strtotime($bc['created_at']))) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a href="<?= APP_URL ?>/broadcast/view.php?id=<?= $bc['id'] ?>"
                           class="btn btn-sm btn-outline-primary" title="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if (bc_is_staff()): ?>
                        <a href="<?= APP_URL ?>/broadcast/delete.php?id=<?= $bc['id'] ?>"
                           class="btn btn-sm btn-outline-danger"
                           title="Delete"
                           onclick="return confirm('Delete this broadcast? This cannot be undone.')">
                            <i class="fas fa-trash"></i>
                        </a>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
