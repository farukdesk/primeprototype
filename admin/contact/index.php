<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('contact');

$page_title = 'Contact Messages';

// ── Filters ──────────────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';       // '' | 'unread' | 'read'

$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = '(name LIKE ? OR email LIKE ? OR subject LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($status === 'unread') {
    $where[]  = 'is_read = 0';
} elseif ($status === 'read') {
    $where[]  = 'is_read = 1';
}

$sql = 'SELECT * FROM contact_messages'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// Unread count for badge
$unread_count = (int)db()->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Contact Messages</li>
        </ol>
    </nav>
    <?php if ($unread_count > 0): ?>
    <span class="badge bg-danger" style="font-size:.85rem;padding:8px 14px;border-radius:50px;">
        <i class="fas fa-envelope me-1"></i><?= $unread_count ?> unread
    </span>
    <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3 px-4">
        <form method="GET" class="d-flex gap-3 flex-wrap align-items-end">
            <div>
                <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" style="min-width:220px;border-radius:8px;"
                       placeholder="Name, email or subject…"
                       value="<?= h($search) ?>">
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">Status</label>
                <select name="status" class="form-select form-select-sm" style="border-radius:8px;">
                    <option value="">All</option>
                    <option value="unread" <?= $status === 'unread' ? 'selected' : '' ?>>Unread</option>
                    <option value="read"   <?= $status === 'read'   ? 'selected' : '' ?>>Read</option>
                </select>
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm" style="border-radius:8px;padding:7px 18px;">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
                <?php if ($search !== '' || $status !== ''): ?>
                <a href="<?= APP_URL ?>/contact/index.php" class="btn btn-outline-secondary btn-sm ms-1" style="border-radius:8px;padding:7px 14px;">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Messages table -->
<div class="card" style="border-radius:12px;">
    <div class="card-header d-flex justify-content-between align-items-center" style="border-radius:12px 12px 0 0;background:#fff;border-bottom:1px solid #f0f0f0;padding:16px 20px;">
        <span style="font-weight:600;font-size:.9rem;color:#374151;">
            <?= count($messages) ?> message<?= count($messages) !== 1 ? 's' : '' ?>
        </span>
    </div>
    <?php if (empty($messages)): ?>
    <div class="card-body text-center py-5">
        <i class="fas fa-inbox" style="font-size:2.5rem;color:#d1d5db;display:block;margin-bottom:12px;"></i>
        <p class="mb-0" style="color:#9ca3af;">No messages found.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
            <thead style="background:#f9fafb;border-bottom:2px solid #f0f0f0;">
                <tr>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Name / Email</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Subject</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Date</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Status</th>
                    <th style="padding:12px 16px;font-weight:600;color:#6b7280;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $msg): ?>
            <tr style="<?= !$msg['is_read'] ? 'background:#fafbff;' : '' ?>">
                <td style="padding:14px 16px;">
                    <div style="font-weight:<?= !$msg['is_read'] ? '700' : '500' ?>;color:#1a2e5a;">
                        <?= h($msg['name']) ?>
                    </div>
                    <div style="font-size:.82rem;color:#6b7280;">
                        <a href="mailto:<?= h($msg['email']) ?>" style="color:#2563eb;text-decoration:none;"><?= h($msg['email']) ?></a>
                    </div>
                    <?php if ($msg['phone']): ?>
                    <div style="font-size:.8rem;color:#9ca3af;"><?= h($msg['phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;max-width:240px;">
                    <span style="font-weight:<?= !$msg['is_read'] ? '600' : '400' ?>;color:#374151;">
                        <?= h(mb_strlen($msg['subject']) > 60 ? mb_substr($msg['subject'], 0, 60) . '…' : $msg['subject']) ?>
                    </span>
                </td>
                <td style="padding:14px 16px;white-space:nowrap;color:#6b7280;">
                    <?= date('d M Y', strtotime($msg['created_at'])) ?><br>
                    <span style="font-size:.8rem;"><?= date('h:i A', strtotime($msg['created_at'])) ?></span>
                </td>
                <td style="padding:14px 16px;">
                    <?php if (!$msg['is_read']): ?>
                    <span class="badge" style="background:#eff6ff;color:#2563eb;border-radius:50px;padding:5px 12px;font-size:.75rem;font-weight:600;">
                        <i class="fas fa-circle me-1" style="font-size:.5rem;vertical-align:middle;"></i> Unread
                    </span>
                    <?php else: ?>
                    <span class="badge" style="background:#f3f4f6;color:#6b7280;border-radius:50px;padding:5px 12px;font-size:.75rem;font-weight:600;">
                        Read
                    </span>
                    <?php endif; ?>
                </td>
                <td style="padding:14px 16px;">
                    <div class="d-flex gap-2">
                        <a href="<?= APP_URL ?>/contact/view.php?id=<?= $msg['id'] ?>"
                           class="btn btn-sm btn-outline-primary" style="border-radius:8px;font-size:.8rem;">
                            <i class="fas fa-eye me-1"></i> View
                        </a>
                        <?php if (is_super_admin() || can_access('contact', 'can_delete')): ?>
                        <form method="POST" action="<?= APP_URL ?>/contact/delete.php" class="d-inline"
                              onsubmit="return confirm('Delete this message?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $msg['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;font-size:.8rem;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
