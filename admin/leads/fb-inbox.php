<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'Facebook Messenger Inbox';
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── AJAX: real-time check (new messages + live stats) ───────────────────────
if (($_GET['ajax'] ?? '') === 'check') {
    header('Content-Type: application/json');
    leads_fb_run_followups_throttled();
    $latest = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM lead_fb_messages')->fetchColumn();
    $s = db()->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(t.last_dir = 'out'), 0) AS responded,
                COALESCE(SUM(t.last_dir = 'in'), 0)  AS waiting,
                COALESCE(SUM(t.has_lead), 0)         AS converted
         FROM (SELECT (c.lead_id IS NOT NULL) AS has_lead,
                      (SELECT m.direction FROM lead_fb_messages m WHERE m.contact_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_dir
               FROM lead_fb_contacts c) t"
    )->fetch();
    echo json_encode([
        'latest_id' => $latest,
        'stats'     => [
            'total'     => (int)$s['total'],
            'responded' => (int)$s['responded'],
            'waiting'   => (int)$s['waiting'],
            'converted' => (int)$s['converted'],
        ],
    ]);
    exit;
}

// ── POST: backfill missing profile names from Meta's User Profile API ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh_names' && $is_staff) {
    csrf_check();
    if (leads_fb_setting('page_access_token') === '') {
        flash_set('error', 'Page Access Token is not configured. Set it in FB Settings first.');
    } else {
        $pending = db()->query(
            "SELECT id, psid FROM lead_fb_contacts WHERE fb_name IS NULL OR fb_name = '' ORDER BY last_message_at DESC LIMIT 50"
        )->fetchAll();
        $updated = 0;
        foreach ($pending as $row) {
            [$name, $picture] = leads_fb_fetch_profile($row['psid']);
            if ($name !== null) {
                db()->prepare('UPDATE lead_fb_contacts SET fb_name=?, fb_picture=COALESCE(?, fb_picture) WHERE id=?')
                    ->execute([$name, $picture, (int)$row['id']]);
                $updated++;
            }
        }
        if (count($pending) === 0) {
            flash_set('success', 'All contacts already have names.');
        } elseif ($updated > 0) {
            flash_set('success', 'Fetched names for ' . $updated . ' of ' . count($pending) . ' contact(s).');
        } else {
            flash_set('error', 'Could not fetch any names. Check that the Page Access Token is valid and has the pages_messaging permission.');
        }
    }
    redirect(APP_URL . '/leads/fb-inbox.php');
}

// ── Search / filter ───────────────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$f_linked  = $_GET['linked'] ?? '';   // 'yes' | 'no' | ''
$f_state   = $_GET['state']  ?? '';   // '' | 'waiting' | 'responded' | 'converted'
$f_tag     = (int)($_GET['tag'] ?? 0);
$f_phone   = $_GET['phone'] ?? '';    // '' | 'yes' | 'no'
if ($f_phone !== 'yes' && $f_phone !== 'no') $f_phone = '';
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$last_dir_sql = '(SELECT m.direction FROM lead_fb_messages m WHERE m.contact_id = c.id ORDER BY m.id DESC LIMIT 1)';

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
if ($f_state === 'waiting') {
    $where[] = $last_dir_sql . " = 'in'";
} elseif ($f_state === 'responded') {
    $where[] = $last_dir_sql . " = 'out'";
} elseif ($f_state === 'converted') {
    $where[] = 'c.lead_id IS NOT NULL';
}
if ($f_tag > 0) {
    $where[]  = 'c.id IN (SELECT ct.contact_id FROM lead_fb_contact_tags ct WHERE ct.tag_id = ?)';
    $params[] = $f_tag;
}
$phone_exists_sql = "EXISTS(SELECT 1 FROM lead_fb_messages mp WHERE mp.contact_id = c.id AND mp.direction = 'in'
                    AND mp.message_text REGEXP '01[3-9][0-9]{8}')";
if ($f_phone === 'yes') {
    $where[] = $phone_exists_sql;
} elseif ($f_phone === 'no') {
    $where[] = 'NOT ' . $phone_exists_sql;
}
if ($date_from !== '') {
    $where[]  = 'DATE(COALESCE(c.last_message_at, c.first_seen)) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[]  = 'DATE(COALESCE(c.last_message_at, c.first_seen)) <= ?';
    $params[] = $date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$filters_active = ($search !== '' || $f_linked !== '' || $f_state !== '' || $f_tag > 0 || $f_phone !== '' || $date_from !== '' || $date_to !== '');

$count_q = db()->prepare(
    'SELECT COUNT(*) FROM lead_fb_contacts c LEFT JOIN leads l ON l.id = c.lead_id ' . $where_sql
);
$count_q->execute($params);
$total_rows  = (int)$count_q->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$contacts_q = db()->prepare(
    "SELECT c.*,
            l.first_name, l.last_name, l.lead_number,
            (SELECT COUNT(*) FROM lead_fb_messages m WHERE m.contact_id = c.id) AS msg_count,
            (SELECT COUNT(*) FROM lead_fb_messages m WHERE m.contact_id = c.id AND m.direction = 'in') AS incoming_count,
            $last_dir_sql AS last_direction,
            EXISTS(SELECT 1 FROM lead_fb_messages mp WHERE mp.contact_id = c.id AND mp.direction = 'in'
                   AND mp.message_text REGEXP '01[3-9][0-9]{8}') AS has_phone
     FROM lead_fb_contacts c
     LEFT JOIN leads l ON l.id = c.lead_id
     $where_sql
     ORDER BY c.last_message_at DESC, c.first_seen DESC
     LIMIT $per_page OFFSET $offset"
);
$contacts_q->execute($params);
$contacts = $contacts_q->fetchAll();

// Unread counts per contact (requires fb-inbox-upgrade.sql; degrades to none)
$unread_map = [];
try {
    $ids = array_column($contacts, 'id');
    if ($ids) {
        $in_list = implode(',', array_map('intval', $ids));
        $unread_map = db()->query(
            "SELECT c.id, COUNT(m.id) AS unread
             FROM lead_fb_contacts c
             JOIN lead_fb_messages m ON m.contact_id = c.id AND m.direction = 'in'
                  AND (c.last_read_at IS NULL OR m.created_at > c.last_read_at)
             WHERE c.id IN ($in_list)
             GROUP BY c.id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (Exception $e) {
    $unread_map = [];
}

// Tags (list for the filter dropdown + per-contact badges)
$all_tags = [];
try {
    $all_tags = db()->query('SELECT * FROM lead_fb_tags ORDER BY name ASC')->fetchAll();
} catch (Exception $e) { /* run fb-inbox-upgrade.sql */ }

$contact_tags_map = [];
try {
    $ids = array_column($contacts, 'id');
    if ($ids && $all_tags) {
        $in_list = implode(',', array_map('intval', $ids));
        $trows = db()->query(
            "SELECT ct.contact_id, t.name, t.color
             FROM lead_fb_contact_tags ct
             JOIN lead_fb_tags t ON t.id = ct.tag_id
             WHERE ct.contact_id IN ($in_list)
             ORDER BY t.name ASC"
        )->fetchAll();
        foreach ($trows as $tr) {
            $contact_tags_map[(int)$tr['contact_id']][] = $tr;
        }
    }
} catch (Exception $e) { /* ignore */ }

// ── Headline stats ───────────────────────────────────────────────────────────
$stats_row = db()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(t.last_dir = 'out'), 0) AS responded,
            COALESCE(SUM(t.last_dir = 'in'), 0)  AS waiting,
            COALESCE(SUM(t.has_lead), 0)         AS converted
     FROM (SELECT (c.lead_id IS NOT NULL) AS has_lead,
                  (SELECT m.direction FROM lead_fb_messages m WHERE m.contact_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_dir
           FROM lead_fb_contacts c) t"
)->fetch();

$total_contacts  = (int)$stats_row['total'];
$total_responded = (int)$stats_row['responded'];
$total_waiting   = (int)$stats_row['waiting'];
$total_converted = (int)$stats_row['converted'];
$latest_msg_id   = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM lead_fb_messages')->fetchColumn();

$qs_base = array_filter([
    'search'    => $search,
    'linked'    => $f_linked,
    'state'     => $f_state,
    'tag'       => $f_tag ?: null,
    'phone'     => $f_phone,
    'date_from' => $date_from,
    'date_to'   => $date_to,
], static fn($v) => $v !== null && $v !== '');

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
        <?php if ($is_staff): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="refresh_names">
            <button type="submit" class="btn btn-outline-primary btn-sm" title="Fetch missing contact names from Facebook">
                <i class="fas fa-sync-alt me-1"></i> Refresh Names
            </button>
        </form>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/leads/fb-analytics.php" class="btn btn-outline-info btn-sm"><i class="fas fa-chart-line me-1"></i> Analytics</a>
        <?php if (is_super_admin()): ?>
        <a href="<?= APP_URL ?>/leads/fb-settings.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1"></i> FB Settings</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Leads</a>
    </div>
</div>

<?= flash_show() ?>

<!-- Stats (live – refreshed automatically) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="text-decoration-none text-reset">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3 d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e7f3ff"><i class="fab fa-facebook-messenger fa-lg" style="color:#1877F2"></i></div>
                    <div>
                        <div class="fw-bold fs-5" id="stat-total"><?= number_format($total_contacts) ?></div>
                        <div class="text-muted small">Total Contacts</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?state=responded" class="text-decoration-none text-reset">
            <div class="card border-0 shadow-sm h-100 <?= $f_state === 'responded' ? 'border border-primary' : '' ?>">
                <div class="card-body p-3 d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#e8f5e9"><i class="fas fa-reply fa-lg text-success"></i></div>
                    <div>
                        <div class="fw-bold fs-5" id="stat-responded"><?= number_format($total_responded) ?></div>
                        <div class="text-muted small">Total Response</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?state=waiting" class="text-decoration-none text-reset">
            <div class="card border-0 shadow-sm h-100 <?= $f_state === 'waiting' ? 'border border-danger' : '' ?>">
                <div class="card-body p-3 d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#fdecea"><i class="fas fa-hourglass-half fa-lg text-danger"></i></div>
                    <div>
                        <div class="fw-bold fs-5 text-danger" id="stat-waiting"><?= number_format($total_waiting) ?></div>
                        <div class="text-muted small">Waiting for Response</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?state=converted" class="text-decoration-none text-reset">
            <div class="card border-0 shadow-sm h-100 <?= $f_state === 'converted' ? 'border border-dark' : '' ?>">
                <div class="card-body p-3 d-flex align-items-center gap-2">
                    <div class="rounded-3 p-2" style="background:#f3e8ff"><i class="fas fa-user-check fa-lg" style="color:#6f42c1"></i></div>
                    <div>
                        <div class="fw-bold fs-5" id="stat-converted"><?= number_format($total_converted) ?></div>
                        <div class="text-muted small">Converted to Leads</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or PSID…" value="<?= h($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="state" class="form-select form-select-sm">
                    <option value="" <?= $f_state === '' ? 'selected' : '' ?>>All</option>
                    <option value="waiting" <?= $f_state === 'waiting' ? 'selected' : '' ?>>Waiting for Response</option>
                    <option value="responded" <?= $f_state === 'responded' ? 'selected' : '' ?>>Responded</option>
                    <option value="converted" <?= $f_state === 'converted' ? 'selected' : '' ?>>Converted to Lead</option>
                </select>
            </div>
            <?php if ($all_tags): ?>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Tag</label>
                <select name="tag" class="form-select form-select-sm">
                    <option value="">All Tags</option>
                    <?php foreach ($all_tags as $tg): ?>
                    <option value="<?= (int)$tg['id'] ?>" <?= $f_tag === (int)$tg['id'] ? 'selected' : '' ?>><?= h($tg['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">From date</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">To date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Linked</label>
                <select name="linked" class="form-select form-select-sm">
                    <option value="" <?= $f_linked === '' ? 'selected' : '' ?>>All Contacts</option>
                    <option value="yes" <?= $f_linked === 'yes' ? 'selected' : '' ?>>Linked to Lead</option>
                    <option value="no" <?= $f_linked === 'no' ? 'selected' : '' ?>>Unlinked</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-search me-1"></i> Filter</button>
            </div>
            <?php if ($filters_active): ?>
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
        <span>Facebook Contacts <span class="text-muted fw-normal small" id="live-indicator" title="This list refreshes automatically when a new message arrives"><i class="fas fa-circle text-success" style="font-size:.5rem"></i> live</span></span>
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
                    <span class="fw-semibold text-truncate"><?= h($c['fb_name'] ?: 'Facebook User #' . substr((string)$c['psid'], -6)) ?></span>
                    <small class="text-muted flex-shrink-0 ms-2">
                        <?= $c['last_message_at'] ? date('d M Y', strtotime($c['last_message_at'])) : date('d M Y', strtotime($c['first_seen'])) ?>
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <?php if ($c['lead_id']): ?>
                    <span class="badge bg-success">
                        <i class="fas fa-link me-1"></i>
                        <?= h($c['first_name'] . ' ' . $c['last_name']) ?> (<?= h($c['lead_number']) ?>)
                    </span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="fas fa-unlink me-1"></i>Not linked</span>
                    <?php endif; ?>
                    <?php if (($c['last_direction'] ?? '') === 'in'): ?>
                    <span class="badge bg-danger"><i class="fas fa-hourglass-half me-1"></i>Awaiting reply</span>
                    <?php elseif (($c['last_direction'] ?? '') === 'out'): ?>
                    <span class="badge bg-light text-success border"><i class="fas fa-reply me-1"></i>Responded</span>
                    <?php endif; ?>
                    <?php if (!empty($c['has_phone'])): ?>
                    <span class="badge" style="background:#198754"><i class="fas fa-phone me-1"></i>Has phone number</span>
                    <?php endif; ?>
                    <?php foreach ($contact_tags_map[(int)$c['id']] ?? [] as $tg): ?>
                    <span class="badge" style="background:<?= h($tg['color']) ?>"><i class="fas fa-tag me-1" style="font-size:.55rem"></i><?= h($tg['name']) ?></span>
                    <?php endforeach; ?>
                    <span class="badge bg-light text-dark border"><i class="fas fa-comments me-1"></i><?= number_format($c['msg_count']) ?> msgs</span>
                    <?php $u_cnt = (int)($unread_map[$c['id']] ?? 0); $manual_unread = (int)($c['marked_unread'] ?? 0); ?>
                    <?php if ($u_cnt > 0 || $manual_unread): ?>
                    <span class="badge bg-danger"><i class="fas fa-envelope me-1"></i><?= $u_cnt > 0 ? $u_cnt . ' new' : 'unread' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <i class="fas fa-chevron-right text-muted flex-shrink-0"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card-body">
        <p class="text-muted small mb-1">No Facebook contacts found.</p>
        <?php if (!$filters_active): ?>
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
            <a class="page-link" href="?<?= http_build_query($qs_base + ['page' => $p]) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<script>
(function () {
    let latest = <?= $latest_msg_id ?>;
    setInterval(function () {
        fetch('<?= APP_URL ?>/leads/fb-inbox.php?ajax=check', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.stats) {
                    const map = { 'stat-total': d.stats.total, 'stat-responded': d.stats.responded, 'stat-waiting': d.stats.waiting, 'stat-converted': d.stats.converted };
                    Object.keys(map).forEach(function (id) {
                        const el = document.getElementById(id);
                        if (el) el.textContent = Number(map[id]).toLocaleString();
                    });
                }
                if (d.latest_id && d.latest_id > latest) {
                    // A new message arrived somewhere – refresh the list (filters preserved)
                    location.reload();
                }
            })
            .catch(function () { /* network hiccup – retry next tick */ });
    }, 8000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
