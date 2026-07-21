<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$page_title = 'FB Broadcast';
$user       = auth_user();

if (!leads_is_staff()) {
    flash_set('error', 'You do not have permission to send broadcasts.');
    redirect(APP_URL . '/leads/fb-inbox.php');
}

// ── Tags for the audience picker ─────────────────────────────────────────────
$all_tags = [];
try {
    $all_tags = db()->query('SELECT * FROM lead_fb_tags ORDER BY name ASC')->fetchAll();
} catch (Exception $e) { /* run fb-inbox-upgrade.sql */ }

// ── Resolve recipient contact ids for an audience selection ─────────────────
function fb_bc_recipient_ids(string $audience, array $tag_ids, bool $only_phone): array
{
    $where  = [];
    $params = [];
    if ($audience === 'tags') {
        if (!$tag_ids) return [];
        $ph = implode(',', array_fill(0, count($tag_ids), '?'));
        $where[] = "c.id IN (SELECT ct.contact_id FROM lead_fb_contact_tags ct WHERE ct.tag_id IN ($ph))";
        foreach ($tag_ids as $tid) $params[] = $tid;
    }
    if ($only_phone) {
        $where[] = "EXISTS(SELECT 1 FROM lead_fb_messages mp WHERE mp.contact_id = c.id AND mp.direction = 'in'
                    AND mp.message_text REGEXP '01[3-9][0-9]{8}')";
    }
    $sql = 'SELECT c.id FROM lead_fb_contacts c' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $q = db()->prepare($sql);
    $q->execute($params);
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

// ── AJAX: live recipient count for the audience picker ──────────────────────
if (($_GET['ajax'] ?? '') === 'count') {
    header('Content-Type: application/json');
    $audience   = ($_GET['audience'] ?? 'all') === 'tags' ? 'tags' : 'all';
    $tag_ids    = array_values(array_filter(array_map('intval', (array)($_GET['tags'] ?? []))));
    $only_phone = ($_GET['only_phone'] ?? '') === '1';
    try {
        echo json_encode(['count' => count(fb_bc_recipient_ids($audience, $tag_ids, $only_phone))]);
    } catch (Exception $e) {
        echo json_encode(['count' => 0]);
    }
    exit;
}

// ── AJAX: process a batch of pending recipients ──────────────────────────────
if (($_GET['ajax'] ?? '') === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    csrf_check();
    $bid = (int)($_GET['id'] ?? 0);
    try {
        $bq = db()->prepare('SELECT * FROM lead_fb_broadcasts WHERE id = ?');
        $bq->execute([$bid]);
        $bc = $bq->fetch();
    } catch (Exception $e) {
        echo json_encode(['error' => 'Broadcast tables missing – run admin/leads/fb-inbox-upgrade-3.sql first.']);
        exit;
    }
    if (!$bc) { echo json_encode(['error' => 'Broadcast not found.']); exit; }

    $batch = db()->prepare(
        "SELECT r.contact_id, c.psid
         FROM lead_fb_broadcast_recipients r
         JOIN lead_fb_contacts c ON c.id = r.contact_id
         WHERE r.broadcast_id = ? AND r.status = 'pending'
         ORDER BY r.contact_id ASC
         LIMIT 5"
    );
    $batch->execute([$bid]);

    $sent = 0; $failed = 0;
    foreach ($batch->fetchAll() as $r) {
        // Claim the row first so two parallel pollers never double-send
        $claim = db()->prepare(
            "UPDATE lead_fb_broadcast_recipients SET status = 'sending'
             WHERE broadcast_id = ? AND contact_id = ? AND status = 'pending'"
        );
        $claim->execute([$bid, (int)$r['contact_id']]);
        if ($claim->rowCount() === 0) continue;

        $ok = leads_fb_send($r['psid'], $bc['message']);
        db()->prepare('UPDATE lead_fb_broadcast_recipients SET status = ?, sent_at = NOW() WHERE broadcast_id = ? AND contact_id = ?')
            ->execute([$ok ? 'sent' : 'failed', $bid, (int)$r['contact_id']]);

        // Record the message in the conversation thread (is_auto = 1 so the
        // one-time follow-up nudge is never triggered by a broadcast)
        try {
            db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by, status, is_auto) VALUES (?,?,?,?,?,1)')
                ->execute([(int)$r['contact_id'], 'out', $bc['message'], $user['id'], $ok ? 'sent' : 'failed']);
        } catch (Exception $e) {
            db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by) VALUES (?,?,?,?)')
                ->execute([(int)$r['contact_id'], 'out', $bc['message'], $user['id']]);
        }
        if ($ok) {
            db()->prepare('UPDATE lead_fb_contacts SET last_message_at = NOW() WHERE id = ?')->execute([(int)$r['contact_id']]);
            $sent++;
        } else {
            $failed++;
        }
    }
    if ($sent || $failed) {
        db()->prepare('UPDATE lead_fb_broadcasts SET sent_count = sent_count + ?, failed_count = failed_count + ? WHERE id = ?')
            ->execute([$sent, $failed, $bid]);
    }

    $pq = db()->prepare("SELECT COUNT(*) FROM lead_fb_broadcast_recipients WHERE broadcast_id = ? AND status = 'pending'");
    $pq->execute([$bid]);
    $pending = (int)$pq->fetchColumn();
    if ($pending === 0) {
        db()->prepare("UPDATE lead_fb_broadcasts SET status = 'completed', completed_at = COALESCE(completed_at, NOW()) WHERE id = ?")->execute([$bid]);
    }
    $bq->execute([$bid]);
    $bc = $bq->fetch();
    echo json_encode([
        'pending' => $pending,
        'sent'    => (int)$bc['sent_count'],
        'failed'  => (int)$bc['failed_count'],
        'total'   => (int)$bc['total_recipients'],
        'done'    => $pending === 0,
    ]);
    exit;
}

// ── POST: create a broadcast ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_broadcast') {
    csrf_check();
    $message    = trim($_POST['message'] ?? '');
    $audience   = ($_POST['audience'] ?? 'all') === 'tags' ? 'tags' : 'all';
    $tag_ids    = array_values(array_filter(array_map('intval', (array)($_POST['tags'] ?? []))));
    $only_phone = !empty($_POST['only_phone']);

    if ($message === '') {
        flash_set('error', 'Broadcast message cannot be empty.');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }
    if (mb_strlen($message) > 2000) {
        flash_set('error', 'Message is too long (max 2000 characters).');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }
    if ($audience === 'tags' && !$tag_ids) {
        flash_set('error', 'Select at least one tag, or choose "All contacts".');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }
    if (leads_fb_setting('page_access_token') === '') {
        flash_set('error', 'Page Access Token is not configured. Set it in FB Settings first.');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }

    $ids = fb_bc_recipient_ids($audience, $tag_ids, $only_phone);
    if (!$ids) {
        flash_set('error', 'No contacts match the selected audience.');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }

    $tag_names = null;
    if ($audience === 'tags' && $all_tags) {
        $sel = array_filter($all_tags, fn($t) => in_array((int)$t['id'], $tag_ids, true));
        $tag_names = implode(', ', array_map(fn($t) => $t['name'], $sel)) ?: null;
    }

    try {
        db()->prepare('INSERT INTO lead_fb_broadcasts (message, audience, tag_names, total_recipients, status, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$message, $audience, $tag_names, count($ids), 'sending', $user['id']]);
        $bid = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT IGNORE INTO lead_fb_broadcast_recipients (broadcast_id, contact_id) VALUES (?,?)');
        foreach ($ids as $cid) { $ins->execute([$bid, $cid]); }
    } catch (Exception $e) {
        flash_set('error', 'Broadcast tables missing – run admin/leads/fb-inbox-upgrade-3.sql first.');
        redirect(APP_URL . '/leads/fb-broadcast.php');
    }
    redirect(APP_URL . '/leads/fb-broadcast.php?id=' . $bid);
}

// ── View data ─────────────────────────────────────────────────────────────────
$active_bc = null;
$view_id = (int)($_GET['id'] ?? 0);
if ($view_id > 0) {
    try {
        $q = db()->prepare('SELECT * FROM lead_fb_broadcasts WHERE id = ?');
        $q->execute([$view_id]);
        $active_bc = $q->fetch() ?: null;
    } catch (Exception $e) { /* ignore */ }
}
$tables_missing = false;
$history = [];
try {
    $history = db()->query('SELECT b.*, u.full_name FROM lead_fb_broadcasts b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 20')->fetchAll();
} catch (Exception $e) { $tables_missing = true; }

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-bullhorn me-2" style="color:#1877F2"></i>Messenger Broadcast</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a></li>
            <li class="breadcrumb-item active">Broadcast</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to Inbox</a>
    </div>
</div>

<?= flash_show() ?>

<?php if ($tables_missing && is_super_admin()): ?>
<div class="alert alert-warning py-2 small">
    <i class="fas fa-database me-1"></i>
    Broadcast tables are missing. Run <code>admin/leads/fb-inbox-upgrade-3.sql</code> once to enable broadcasts.
</div>
<?php endif; ?>

<div class="alert alert-info py-2 small">
    <i class="fas fa-info-circle me-1"></i>
    Facebook only delivers standard messages to people who interacted with your Page within the last <strong>24 hours</strong>.
    Messages to contacts outside this window may be rejected by Facebook and will show as <em>failed</em>.
</div>

<?php if ($active_bc): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="fas fa-paper-plane me-2 text-primary"></i>Broadcast #<?= (int)$active_bc['id'] ?></span>
        <span id="bc-status-badge" class="badge <?= $active_bc['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>">
            <?= $active_bc['status'] === 'completed' ? 'Completed' : 'Sending…' ?>
        </span>
    </div>
    <div class="card-body p-3">
        <div class="border rounded bg-light p-2 small mb-3" style="white-space:pre-wrap"><?= h($active_bc['message']) ?></div>
        <?php
            $done_n = (int)$active_bc['sent_count'] + (int)$active_bc['failed_count'];
            $pct    = (int)$active_bc['total_recipients'] > 0 ? (int)round($done_n * 100 / (int)$active_bc['total_recipients']) : 0;
        ?>
        <div class="progress mb-2" style="height:18px">
            <div id="bc-bar" class="progress-bar progress-bar-striped <?= $active_bc['status'] === 'completed' ? 'bg-success' : 'progress-bar-animated' ?>" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
        </div>
        <div class="d-flex gap-3 small text-muted flex-wrap">
            <span><i class="fas fa-users me-1"></i>Recipients: <strong id="bc-total"><?= (int)$active_bc['total_recipients'] ?></strong></span>
            <span class="text-success"><i class="fas fa-check me-1"></i>Sent: <strong id="bc-sent"><?= (int)$active_bc['sent_count'] ?></strong></span>
            <span class="text-danger"><i class="fas fa-times me-1"></i>Failed: <strong id="bc-failed"><?= (int)$active_bc['failed_count'] ?></strong></span>
            <span><i class="fas fa-hourglass-half me-1"></i>Pending: <strong id="bc-pending"><?= max(0, (int)$active_bc['total_recipients'] - $done_n) ?></strong></span>
        </div>
        <div class="mt-2 small text-muted">Audience: <?= $active_bc['audience'] === 'tags' ? 'Tags – ' . h((string)$active_bc['tag_names']) : 'All contacts' ?></div>
    </div>
</div>
<form id="bc-csrf" class="d-none"><?= csrf_field() ?></form>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-pen me-2 text-primary"></i>New Broadcast</div>
            <div class="card-body p-3">
                <form method="post" id="bc-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_broadcast">

                    <label class="form-label small text-muted mb-1 fw-semibold">Send to</label>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <label class="border rounded-3 p-2 d-flex align-items-center gap-2 mb-0" style="cursor:pointer">
                            <input class="form-check-input mt-0" type="radio" name="audience" value="all" checked>
                            <span><i class="fas fa-users me-1 text-primary"></i>All contacts</span>
                        </label>
                        <label class="border rounded-3 p-2 d-flex align-items-center gap-2 mb-0" style="cursor:pointer">
                            <input class="form-check-input mt-0" type="radio" name="audience" value="tags" <?= !$all_tags ? 'disabled' : '' ?>>
                            <span><i class="fas fa-tags me-1 text-warning"></i>Contacts with selected tag(s)</span>
                        </label>
                        <div id="bc-tags" class="ps-4 d-none">
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($all_tags as $tg): ?>
                                <label class="badge mb-0 d-flex align-items-center gap-1" style="cursor:pointer;background:#fff;color:<?= h($tg['color']) ?>;border:1px solid <?= h($tg['color']) ?>">
                                    <input type="checkbox" class="form-check-input mt-0 bc-tag" name="tags[]" value="<?= (int)$tg['id'] ?>">
                                    <?= h($tg['name']) ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-muted mt-1" style="font-size:.7rem">Contacts that have <em>any</em> of the selected tags will receive the message.</div>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="only_phone" name="only_phone" value="1">
                        <label class="form-check-label small" for="only_phone"><i class="fas fa-phone me-1 text-success"></i>Only contacts who shared a phone number</label>
                    </div>

                    <label class="form-label small text-muted mb-1 fw-semibold">Message</label>
                    <textarea name="message" id="bc-message" class="form-control mb-1" rows="5" maxlength="2000" placeholder="Write the message to broadcast…" required></textarea>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted" style="font-size:.7rem"><span id="bc-chars">0</span>/2000 characters</span>
                        <span class="badge bg-light text-dark border"><i class="fas fa-users me-1"></i><span id="bc-count">…</span> recipient(s)</span>
                    </div>

                    <button type="submit" class="btn text-white w-100" style="background:#1877F2" onclick="return confirm('Send this broadcast now? This cannot be undone.')">
                        <i class="fas fa-bullhorn me-1"></i> Send Broadcast
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-history me-2 text-secondary"></i>Recent Broadcasts</div>
            <?php if ($history): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($history as $hb): ?>
                <a href="?id=<?= (int)$hb['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small fw-semibold text-truncate" style="max-width:65%"><?= h(mb_substr($hb['message'], 0, 60)) ?></span>
                        <span class="badge <?= $hb['status'] === 'completed' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.6rem"><?= h(ucfirst($hb['status'])) ?></span>
                    </div>
                    <div class="text-muted d-flex gap-2 flex-wrap" style="font-size:.68rem">
                        <span><i class="fas fa-users me-1"></i><?= (int)$hb['total_recipients'] ?></span>
                        <span class="text-success"><i class="fas fa-check me-1"></i><?= (int)$hb['sent_count'] ?></span>
                        <span class="text-danger"><i class="fas fa-times me-1"></i><?= (int)$hb['failed_count'] ?></span>
                        <span><?= $hb['audience'] === 'tags' ? '<i class="fas fa-tags me-1"></i>' . h((string)$hb['tag_names']) : 'All contacts' ?></span>
                        <span class="ms-auto"><?= h(leads_time_ago($hb['created_at'])) ?> · <?= h($hb['full_name'] ?? 'Staff') ?></span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="card-body"><p class="text-muted small mb-0">No broadcasts sent yet.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Audience picker: live recipient count ──
    const tagBox  = document.getElementById('bc-tags');
    const countEl = document.getElementById('bc-count');
    const radios  = document.querySelectorAll('input[name="audience"]');
    const tags    = document.querySelectorAll('.bc-tag');
    const onlyPh  = document.getElementById('only_phone');

    function audienceVal() {
        const r = document.querySelector('input[name="audience"]:checked');
        return r ? r.value : 'all';
    }
    function refreshCount() {
        if (!countEl) return;
        countEl.textContent = '…';
        const params = new URLSearchParams();
        params.set('ajax', 'count');
        params.set('audience', audienceVal());
        tags.forEach(function (cb) { if (cb.checked) params.append('tags[]', cb.value); });
        if (onlyPh && onlyPh.checked) params.set('only_phone', '1');
        fetch('?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { countEl.textContent = Number(d.count || 0).toLocaleString(); })
            .catch(function () { countEl.textContent = '?'; });
    }
    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            if (tagBox) tagBox.classList.toggle('d-none', audienceVal() !== 'tags');
            refreshCount();
        });
    });
    tags.forEach(function (cb) { cb.addEventListener('change', refreshCount); });
    if (onlyPh) onlyPh.addEventListener('change', refreshCount);
    refreshCount();

    // ── Character counter ──
    const msg   = document.getElementById('bc-message');
    const chars = document.getElementById('bc-chars');
    if (msg && chars) {
        msg.addEventListener('input', function () { chars.textContent = msg.value.length; });
    }

    // ── Progress loop for an active broadcast ──
    const RUNNING = <?= ($active_bc && $active_bc['status'] !== 'completed') ? 'true' : 'false' ?>;
    if (!RUNNING) return;
    const csrfForm = document.getElementById('bc-csrf');
    const bar      = document.getElementById('bc-bar');
    function setNum(id, v) { const el = document.getElementById(id); if (el) el.textContent = Number(v).toLocaleString(); }
    function tick() {
        const fd = new FormData(csrfForm);
        fetch('?id=<?= (int)($active_bc['id'] ?? 0) ?>&ajax=process', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert(d.error); return; }
                setNum('bc-sent', d.sent); setNum('bc-failed', d.failed); setNum('bc-pending', d.pending); setNum('bc-total', d.total);
                const pct = d.total > 0 ? Math.round((d.sent + d.failed) * 100 / d.total) : 100;
                if (bar) { bar.style.width = pct + '%'; bar.textContent = pct + '%'; }
                if (d.done) {
                    const badge = document.getElementById('bc-status-badge');
                    if (badge) { badge.className = 'badge bg-success'; badge.textContent = 'Completed'; }
                    if (bar) { bar.classList.remove('progress-bar-animated'); bar.classList.add('bg-success'); }
                } else {
                    setTimeout(tick, 400);
                }
            })
            .catch(function () { setTimeout(tick, 2500); });
    }
    tick();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
