<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$contact_id = (int)($_GET['contact_id'] ?? 0);
if ($contact_id <= 0) {
    flash_set('error', 'Invalid contact.');
    redirect(APP_URL . '/leads/fb-inbox.php');
}

$cq = db()->prepare(
    'SELECT c.*, l.first_name, l.last_name, l.lead_number, l.id AS linked_lead_id
     FROM lead_fb_contacts c
     LEFT JOIN leads l ON l.id = c.lead_id
     WHERE c.id = ?'
);
$cq->execute([$contact_id]);
$contact = $cq->fetch();

if (!$contact) {
    flash_set('error', 'Facebook contact not found.');
    redirect(APP_URL . '/leads/fb-inbox.php');
}

$page_title = 'Messenger – ' . ($contact['fb_name'] ?? 'Unknown');
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── POST handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Send reply ────────────────────────────────────────────────────────
    if ($action === 'send_reply' && $is_staff) {
        $text = trim($_POST['fb_reply'] ?? '');
        if ($text === '') {
            flash_set('error', 'Message cannot be empty.');
        } else {
            $sent = leads_fb_send($contact['psid'], $text);
            if ($sent) {
                db()->prepare(
                    'INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by)
                     VALUES (?,?,?,?)'
                )->execute([$contact_id, 'out', $text, $user['id']]);
                db()->prepare('UPDATE lead_fb_contacts SET last_message_at=NOW() WHERE id=?')
                    ->execute([$contact_id]);

                // Log on lead if linked
                if ($contact['lead_id']) {
                    leads_log((int)$contact['lead_id'], 'fb_message_sent', null, null, null,
                        'Facebook reply sent by ' . $user['full_name'] . ': ' . mb_substr($text, 0, 100));
                }
                flash_set('success', 'Message sent.');
            } else {
                flash_set('error', 'Failed to send. Check Facebook credentials in FB Settings.');
            }
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Link to lead ──────────────────────────────────────────────────────
    if ($action === 'link_lead' && $is_staff) {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id > 0) {
            // Verify lead exists
            $lchk = db()->prepare('SELECT id, first_name, last_name FROM leads WHERE id = ?');
            $lchk->execute([$lead_id]);
            $ldata = $lchk->fetch();
            if ($ldata) {
                db()->prepare('UPDATE lead_fb_contacts SET lead_id = ? WHERE id = ?')
                    ->execute([$lead_id, $contact_id]);
                leads_log($lead_id, 'fb_linked', 'facebook_contact', null, $contact['psid'],
                    'Facebook contact ' . ($contact['fb_name'] ?? $contact['psid']) . ' linked by ' . $user['full_name']);
                flash_set('success', 'Facebook contact linked to lead ' . $ldata['first_name'] . ' ' . $ldata['last_name'] . '.');
            } else {
                flash_set('error', 'Lead not found.');
            }
        } else {
            flash_set('error', 'Please select a lead.');
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Unlink from lead ──────────────────────────────────────────────────
    if ($action === 'unlink_lead' && $is_staff) {
        db()->prepare('UPDATE lead_fb_contacts SET lead_id = NULL WHERE id = ?')
            ->execute([$contact_id]);
        flash_set('success', 'Facebook contact unlinked from lead.');
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }
}

// ── Fetch messages ─────────────────────────────────────────────────────────────
$msgs_q = db()->prepare(
    'SELECT m.*, u.full_name AS sender_name
     FROM lead_fb_messages m
     LEFT JOIN users u ON u.id = m.sent_by
     WHERE m.contact_id = ?
     ORDER BY m.created_at ASC'
);
$msgs_q->execute([$contact_id]);
$messages = $msgs_q->fetchAll();

// All leads for link dropdown
$all_leads = db()->query(
    "SELECT id, lead_number, first_name, last_name FROM leads ORDER BY created_at DESC LIMIT 200"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i>
            <?= h($contact['fb_name'] ?? 'Unknown User') ?>
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a></li>
            <li class="breadcrumb-item active"><?= h($contact['fb_name'] ?? 'Conversation') ?></li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?= flash_show() ?>

<div class="row g-4">
    <!-- Conversation column -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm" style="display:flex;flex-direction:column">
            <!-- Contact header -->
            <div class="card-header bg-white d-flex align-items-center gap-3">
                <?php if ($contact['fb_picture']): ?>
                <img src="<?= h($contact['fb_picture']) ?>" class="rounded-circle" width="42" height="42" alt="" style="object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px;height:42px;background:#1877F2">
                    <i class="fab fa-facebook-messenger text-white"></i>
                </div>
                <?php endif; ?>
                <div>
                    <div class="fw-semibold"><?= h($contact['fb_name'] ?? 'Unknown') ?></div>
                    <div class="text-muted small">PSID: <?= h($contact['psid']) ?></div>
                </div>
                <?php if ($contact['linked_lead_id']): ?>
                <div class="ms-auto">
                    <a href="<?= APP_URL ?>/leads/view.php?id=<?= $contact['linked_lead_id'] ?>#facebook" class="btn btn-sm btn-success">
                        <i class="fas fa-user me-1"></i> <?= h($contact['first_name'] . ' ' . $contact['last_name']) ?> (<?= h($contact['lead_number']) ?>)
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Message thread -->
            <div class="card-body p-0">
                <div id="msg-thread" style="height:420px;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:10px;background:#f0f2f5">
                    <?php if ($messages): ?>
                    <?php foreach ($messages as $msg): ?>
                    <?php $is_out = $msg['direction'] === 'out'; ?>
                    <div class="d-flex <?= $is_out ? 'justify-content-end' : 'justify-content-start' ?> align-items-end gap-2">
                        <?php if (!$is_out && $contact['fb_picture']): ?>
                        <img src="<?= h($contact['fb_picture']) ?>" class="rounded-circle flex-shrink-0 mb-1" width="28" height="28" alt="" style="object-fit:cover">
                        <?php elseif (!$is_out): ?>
                        <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center mb-1" style="width:28px;height:28px;background:#1877F2">
                            <i class="fab fa-facebook-messenger text-white" style="font-size:.6rem"></i>
                        </div>
                        <?php endif; ?>
                        <div style="max-width:70%">
                            <div class="px-3 py-2 rounded-3" style="<?= $is_out ? 'background:#1877F2;color:#fff' : 'background:#fff;color:#000;border:1px solid #ddd' ?>">
                                <?php if ($msg['message_text']): ?>
                                <div style="font-size:.9rem;line-height:1.4"><?= nl2br(h($msg['message_text'])) ?></div>
                                <?php endif; ?>
                                <?php if ($msg['attachment_type']): ?>
                                <div class="mt-1">
                                    <?php if ($msg['attachment_type'] === 'image' && $msg['attachment_url']): ?>
                                    <a href="<?= h($msg['attachment_url']) ?>" target="_blank">
                                        <img src="<?= h($msg['attachment_url']) ?>" style="max-width:200px;border-radius:4px" alt="image">
                                    </a>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">[<?= h($msg['attachment_type']) ?>]</span>
                                    <?php if ($msg['attachment_url']): ?>
                                    <a href="<?= h($msg['attachment_url']) ?>" target="_blank" class="ms-1 <?= $is_out ? 'text-white' : 'text-primary' ?> small">View file</a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1 px-1 text-muted" style="font-size:.7rem;text-align:<?= $is_out ? 'right' : 'left' ?>">
                                <?= $is_out ? h($msg['sender_name'] ?? 'Staff') . ' · ' : '' ?><?= date('d M Y, h:i A', strtotime($msg['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fab fa-facebook-messenger fa-2x mb-2 d-block" style="color:#1877F2"></i>
                        No messages yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reply form -->
            <?php if ($is_staff): ?>
            <div class="card-footer bg-white border-top-0 pt-2">
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_reply">
                    <textarea name="fb_reply" id="fb_reply" class="form-control" rows="2" placeholder="Type a message… (Ctrl+Enter to send)" required style="resize:none"></textarea>
                    <button type="submit" class="btn btn-primary flex-shrink-0" style="background:#1877F2;border-color:#1877F2;height:56px">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-12 col-lg-4">
        <!-- Lead link -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-link me-2 text-info"></i>Linked Lead</div>
            <div class="card-body">
                <?php if ($contact['linked_lead_id']): ?>
                <div class="mb-3">
                    <div class="fw-semibold"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></div>
                    <div class="text-muted small"><?= h($contact['lead_number']) ?></div>
                    <a href="<?= APP_URL ?>/leads/view.php?id=<?= $contact['linked_lead_id'] ?>#facebook" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="fas fa-eye me-1"></i> View Lead
                    </a>
                </div>
                <?php if ($is_staff): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlink_lead">
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Unlink this contact from the lead?')">
                        <i class="fas fa-unlink me-1"></i> Unlink Lead
                    </button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted small mb-3">This Facebook contact is not linked to any lead yet.</p>
                <?php if ($is_staff): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="link_lead">
                    <label class="form-label small fw-semibold">Link to Lead</label>
                    <select name="lead_id" class="form-select form-select-sm mb-2" required>
                        <option value="">— Select Lead —</option>
                        <?php foreach ($all_leads as $ld): ?>
                        <option value="<?= $ld['id'] ?>"><?= h($ld['lead_number'] . ' – ' . $ld['first_name'] . ' ' . $ld['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="fas fa-link me-1"></i> Link Lead
                    </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contact details -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-info-circle me-2 text-secondary"></i>Contact Info</div>
            <div class="card-body">
                <table class="table table-sm mb-0 small">
                    <tr><td class="text-muted">Name</td><td><?= h($contact['fb_name'] ?? '–') ?></td></tr>
                    <tr><td class="text-muted">PSID</td><td class="font-monospace small"><?= h($contact['psid']) ?></td></tr>
                    <tr><td class="text-muted">First Seen</td><td><?= date('d M Y, h:i A', strtotime($contact['first_seen'])) ?></td></tr>
                    <tr><td class="text-muted">Last Message</td><td><?= $contact['last_message_at'] ? date('d M Y, h:i A', strtotime($contact['last_message_at'])) : '–' ?></td></tr>
                    <tr><td class="text-muted">Messages</td><td><?= number_format(count($messages)) ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-scroll message thread to bottom
const thread = document.getElementById('msg-thread');
if (thread) thread.scrollTop = thread.scrollHeight;

// Ctrl+Enter to submit reply
const textarea = document.getElementById('fb_reply');
if (textarea) {
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            this.closest('form').submit();
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
