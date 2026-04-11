<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Facebook Messenger Inbox';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Search / filter ───────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$f_linked = $_GET['linked'] ?? '';   // 'yes' | 'no' | ''
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$where  = [];
$params = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(c.fb_name LIKE ? OR c.psid LIKE ? OR l.first_name LIKE ? OR l.last_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($f_linked === 'yes') {
    $where[] = 'c.lead_id IS NOT NULL';
} elseif ($f_linked === 'no') {
    $where[] = 'c.lead_id IS NULL';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$count_q = db()->prepare(
    'SELECT COUNT(*) FROM lead_fb_contacts c LEFT JOIN leads l ON l.id = c.lead_id ' . $where_sql
);
$count_q->execute($params);
$total_rows  = (int)$count_q->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$contacts_q = db()->prepare(
    'SELECT c.*,
            l.first_name, l.last_name, l.lead_number,
            (SELECT COUNT(*) FROM lead_fb_messages m WHERE m.contact_id = c.id) AS msg_count,
            (SELECT COUNT(*) FROM lead_fb_messages m WHERE m.contact_id = c.id AND m.direction = "in") AS unread_count
     FROM lead_fb_contacts c
     LEFT JOIN leads l ON l.id = c.lead_id
     ' . $where_sql . '
     ORDER BY c.last_message_at DESC, c.first_seen DESC
     LIMIT ' . $per_page . ' OFFSET ' . $offset
);
$contacts_q->execute($params);
$contacts = $contacts_q->fetchAll();

$total_contacts  = (int)db()->query('SELECT COUNT(*) FROM lead_fb_contacts')->fetchColumn();
$total_unlinked  = (int)db()->query('SELECT COUNT(*) FROM lead_fb_contacts WHERE lead_id IS NULL')->fetchColumn();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i>Facebook Messenger Inbox</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item active">FB Inbox</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (is_super_admin()): ?>
        <a href="<?= APP_URL ?>/leads/fb-settings.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1"></i> FB Settings</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Leads</a>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background:#e7f3ff"><i class="fab fa-facebook-messenger fa-lg" style="color:#1877F2"></i></div>
                <div>
                    <div class="fw-bold fs-5"><?= number_format($total_contacts) ?></div>
                    <div class="text-muted small">Total Contacts</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background:#fff3e0"><i class="fas fa-unlink fa-lg text-warning"></i></div>
                <div>
                    <div class="fw-bold fs-5"><?= number_format($total_unlinked) ?></div>
                    <div class="text-muted small">Unlinked</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-3 d-flex align-items-center gap-2">
                <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-link fa-lg text-success"></i></div>
                <div>
                    <div class="fw-bold fs-5"><?= number_format($total_contacts - $total_unlinked) ?></div>
                    <div class="text-muted small">Linked to Leads</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or PSID…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="linked" class="form-select form-select-sm">
                    <option value="" <?= $f_linked === '' ? 'selected' : '' ?>>All Contacts</option>
                    <option value="yes" <?= $f_linked === 'yes' ? 'selected' : '' ?>>Linked to Lead</option>
                    <option value="no" <?= $f_linked === 'no' ? 'selected' : '' ?>>Unlinked</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-search me-1"></i> Filter</button>
            </div>
            <?php if ($search || $f_linked): ?>
            <div class="col-6 col-md-2">
                <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Contacts list -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between">
        <span>Facebook Contacts</span>
        <span class="badge bg-secondary"><?= number_format($total_rows) ?></span>
    </div>
    <?php if ($contacts): ?>
    <div class="list-group list-group-flush">
        <?php foreach ($contacts as $c): ?>
        <a href="<?= APP_URL ?>/leads/fb-conversation.php?contact_id=<?= $c['id'] ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
            <!-- Avatar -->
            <?php if ($c['fb_picture']): ?>
            <img src="<?= h($c['fb_picture']) ?>" class="rounded-circle flex-shrink-0" width="44" height="44" alt="" style="object-fit:cover">
            <?php else: ?>
            <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width:44px;height:44px;background:#1877F2">
                <i class="fab fa-facebook-messenger text-white"></i>
            </div>
            <?php endif; ?>

            <!-- Info -->
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-truncate"><?= h($c['fb_name'] ?? 'Unknown User') ?></span>
                    <small class="text-muted flex-shrink-0 ms-2">
                        <?= $c['last_message_at'] ? date('d M Y', strtotime($c['last_message_at'])) : date('d M Y', strtotime($c['first_seen'])) ?>
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <?php if ($c['lead_id']): ?>
                    <span class="badge bg-success">
                        <i class="fas fa-link me-1"></i>
                        <?= h($c['first_name'] . ' ' . $c['last_name']) ?> (<?= h($c['lead_number']) ?>)
                    </span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="fas fa-unlink me-1"></i>Not linked</span>
                    <?php endif; ?>
                    <span class="badge bg-light text-dark border"><i class="fas fa-comments me-1"></i><?= number_format($c['msg_count']) ?> msgs</span>
                </div>
            </div>
            <i class="fas fa-chevron-right text-muted flex-shrink-0"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card-body">
        <p class="text-muted small mb-1">No Facebook contacts found.</p>
        <?php if (!$search && !$f_linked): ?>
        <p class="text-muted small mb-0">Once you configure the webhook and someone messages your Page, contacts will appear here.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= http_build_query(array_filter(['search' => $search, 'linked' => $f_linked, 'page' => $p])) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
