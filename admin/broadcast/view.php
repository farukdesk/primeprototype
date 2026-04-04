<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('broadcast');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { flash_set('error', 'Invalid broadcast.'); redirect(APP_URL . '/broadcast/index.php'); }

$pdo = db();

// Fetch broadcast with sender, group, user names
$stmt = $pdo->prepare(
    'SELECT b.*,
            u.full_name  AS sender_name,
            ug.name      AS group_name,
            ru.full_name AS user_name,
            ru.email     AS user_email
     FROM broadcasts b
     LEFT JOIN users       u  ON u.id  = b.sent_by
     LEFT JOIN user_groups ug ON ug.id = b.recipient_group_id
     LEFT JOIN users       ru ON ru.id = b.recipient_user_id
     WHERE b.id = ?'
);
$stmt->execute([$id]);
$bc = $stmt->fetch();
if (!$bc) { flash_set('error', 'Broadcast not found.'); redirect(APP_URL . '/broadcast/index.php'); }

// Attachments
$attachments = $pdo->prepare('SELECT * FROM broadcast_attachments WHERE broadcast_id = ? ORDER BY id');
$attachments->execute([$id]);
$attachments = $attachments->fetchAll();

// Recipient log with pagination
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$total_stmt = $pdo->prepare('SELECT COUNT(*) FROM broadcast_recipients WHERE broadcast_id = ?');
$total_stmt->execute([$id]);
$total = (int)$total_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));

$recipients = $pdo->prepare(
    'SELECT * FROM broadcast_recipients WHERE broadcast_id = ? ORDER BY id LIMIT ? OFFSET ?'
);
$recipients->execute([$id, $per_page, $offset]);
$recipients = $recipients->fetchAll();

$page_title = 'Broadcast: ' . mb_strimwidth($bc['subject'], 0, 60, '…');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Broadcast Detail</h1>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/broadcast/index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <?php if (bc_is_staff()): ?>
        <a href="<?= APP_URL ?>/broadcast/delete.php?id=<?= $bc['id'] ?>"
           class="btn btn-outline-danger"
           onclick="return confirm('Delete this broadcast? This cannot be undone.')">
            <i class="fas fa-trash me-1"></i> Delete
        </a>
        <?php endif; ?>
    </div>
</div>

<?php flash_show(); ?>

<div class="row g-4">
    <!-- Broadcast info -->
    <div class="col-lg-8">
        <!-- Meta card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fas fa-info-circle me-1"></i> Broadcast Info</span>
                <?= bc_status_badge($bc['status']) ?>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th style="width:140px" class="text-muted fw-normal">Subject</th>
                        <td class="fw-semibold"><?= h($bc['subject']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Recipients</th>
                        <td>
                            <?php
                            $icon = match($bc['recipient_type']) {
                                'all'   => '<i class="fas fa-users text-success me-1"></i>',
                                'group' => '<i class="fas fa-layer-group text-info me-1"></i>',
                                default => '<i class="fas fa-user text-warning me-1"></i>',
                            };
                            echo $icon . bc_recipient_label($bc);
                            if ($bc['recipient_type'] === 'individual' && $bc['user_email']) {
                                echo ' <small class="text-muted">(' . h($bc['user_email']) . ')</small>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Sent By</th>
                        <td><?= h($bc['sender_name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Sent At</th>
                        <td><?= $bc['sent_at'] ? h(date('d M Y, h:i A', strtotime($bc['sent_at']))) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Delivered</th>
                        <td>
                            <span class="text-success fw-semibold"><?= $bc['sent_count'] ?> sent</span>
                            <?php if ($bc['failed_count'] > 0): ?>
                            &nbsp;·&nbsp;<span class="text-danger"><?= $bc['failed_count'] ?> failed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Email body preview -->
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><i class="fas fa-eye me-1"></i> Email Body Preview</div>
            <div class="card-body p-0">
                <iframe id="body-preview"
                        style="width:100%;min-height:420px;border:none;border-radius:0 0 .375rem .375rem;"
                        sandbox="allow-same-origin"></iframe>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold"><i class="fas fa-paperclip me-1"></i> Attachments</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                <?php foreach ($attachments as $att): ?>
                    <a href="<?= UPLOAD_URL ?>/broadcast/<?= urlencode($att['stored_name']) ?>"
                       target="_blank"
                       class="btn btn-sm btn-outline-secondary"
                       download="<?= h($att['original_name']) ?>">
                        <i class="fas fa-download me-1"></i>
                        <?= h($att['original_name']) ?>
                        <small class="text-muted ms-1">(<?= bc_format_bytes((int)$att['file_size']) ?>)</small>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recipient log -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">
                <i class="fas fa-list-check me-1"></i> Recipient Log
                <span class="badge bg-secondary ms-1"><?= $total ?></span>
            </div>
            <div class="card-body p-0" style="max-height:600px;overflow-y:auto;">
                <?php if (empty($recipients)): ?>
                <p class="text-muted text-center py-4 mb-0">No recipients logged.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($recipients as $r): ?>
                <li class="list-group-item d-flex justify-content-between align-items-start py-2 px-3">
                    <div class="me-2" style="min-width:0">
                        <div class="fw-semibold text-truncate" style="max-width:180px" title="<?= h($r['full_name']) ?>">
                            <?= h($r['full_name']) ?>
                        </div>
                        <div class="text-muted small text-truncate" style="max-width:180px" title="<?= h($r['email']) ?>">
                            <?= h($r['email']) ?>
                        </div>
                    </div>
                    <span class="badge bg-<?= $r['status'] === 'sent' ? 'success' : 'danger' ?> flex-shrink-0">
                        <?= $r['status'] === 'sent' ? 'Sent' : 'Failed' ?>
                    </span>
                </li>
                <?php endforeach; ?>
                </ul>
                <?php if ($pages > 1): ?>
                <div class="p-2 border-top d-flex justify-content-center gap-2">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="?id=<?= $id ?>&page=<?= $p ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>">
                        <?= $p ?>
                    </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Render email body in sandboxed iframe
(function () {
    const iframe = document.getElementById('body-preview');
    const doc    = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(<?= json_encode($bc['body_html']) ?>);
    doc.close();

    // Auto-adjust height
    iframe.addEventListener('load', function () {
        const h = iframe.contentDocument.body.scrollHeight;
        if (h > 0) iframe.style.minHeight = h + 32 + 'px';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
