<?php
/**
 * Printable / downloadable view of a completed (or active) notice with signature overlays.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid notice.'); redirect(APP_URL . '/notice-signing/index.php'); }

$stmt = db()->prepare('SELECT d.*, u.full_name AS creator_name FROM notice_documents d LEFT JOIN users u ON u.id = d.created_by WHERE d.id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { flash_set('error', 'Notice not found.'); redirect(APP_URL . '/notice-signing/index.php'); }

$positions = ns_get_positions($id);

// Collect signer signature images
$sig_map = [];
foreach ($positions as $pos) {
    if ($pos['sig_id']) {
        $s = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
        $s->execute([$pos['user_id']]);
        $sf = $s->fetchColumn();
        $sig_map[$pos['user_id']] = $sf ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notice – <?= h($doc['title']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #fff; color: #222; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
        .header-bar { border-bottom: 2px solid #1a1f36; padding-bottom: 12px; margin-bottom: 20px; }
        .header-bar h1 { font-size: 1.3rem; margin: 0 0 4px; color: #1a1f36; }
        .header-bar p  { font-size: .85rem; color: #666; margin: 0; }
        .doc-wrap { position: relative; display: inline-block; max-width: 100%; }
        .doc-wrap img { max-width: 100%; display: block; border: 1px solid #ddd; }
        .sig-overlay { position: absolute; transform: translate(-50%, -50%); text-align: center; }
        .sig-overlay img { max-height: 60px; max-width: 150px; object-fit: contain; background: rgba(255,255,255,.85); padding: 2px; border-radius: 3px; }
        .sig-overlay .sig-label { font-size: 9px; background: rgba(255,255,255,.8); padding: 1px 4px; border-radius: 3px; white-space: nowrap; }
        .sig-table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: .85rem; }
        .sig-table th { background: #1a1f36; color: #fff; padding: 8px 12px; text-align: left; }
        .sig-table td { padding: 8px 12px; border-bottom: 1px solid #eee; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: .75rem; font-weight: 700; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-active    { background: #dbeafe; color: #1e40af; }
        .badge-draft     { background: #f1f5f9; color: #475569; }
        .pdf-note { padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 20px; text-align: center; color: #64748b; }
        .btn-print { display: inline-block; padding: 8px 20px; background: #1a1f36; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-size: .9rem; margin-bottom: 16px; }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:16px;">
    <button class="btn-print" onclick="window.print()"><i>🖨</i> Print</button>
    <a href="<?= APP_URL ?>/notice-signing/view.php?id=<?= $id ?>" style="margin-left:12px;font-size:.9rem;">← Back to Notice</a>
</div>

<div class="header-bar">
    <h1><?= h($doc['title']) ?></h1>
    <p>
        Created by <?= h($doc['creator_name']) ?> on <?= date('d F Y', strtotime($doc['created_at'])) ?>
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <span class="status-badge badge-<?= $doc['status'] ?>"><?= ucfirst($doc['status']) ?></span>
        <?php if ($doc['status'] === 'completed'): ?>
        &nbsp;&nbsp;·&nbsp;&nbsp; Completed on <?= date('d F Y', strtotime($doc['completed_at'])) ?>
        <?php endif; ?>
    </p>
    <?php if ($doc['description']): ?>
    <p style="margin-top:6px;font-size:.85rem;color:#555;"><?= h($doc['description']) ?></p>
    <?php endif; ?>
</div>

<!-- Document with signature overlays -->
<?php if ($doc['document_type'] === 'pdf'): ?>
<div class="pdf-note">
    <p style="margin:0 0 8px;font-weight:700;">📄 PDF Document</p>
    <p style="margin:0;font-size:.8rem;">Open the original PDF:
        <a href="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>" target="_blank">
            <?= h($doc['original_name']) ?>
        </a>
    </p>
</div>
<?php else: ?>
<div class="doc-wrap">
    <img src="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>" alt="Notice Document">
    <?php foreach ($positions as $pos): ?>
    <?php if ($pos['sig_id'] && !empty($sig_map[$pos['user_id']])): ?>
    <div class="sig-overlay" style="left:<?= $pos['x_percent'] ?>%;top:<?= $pos['y_percent'] ?>%;">
        <img src="<?= UPLOAD_URL ?>/<?= NS_SIG_SUBDIR ?>/<?= h($sig_map[$pos['user_id']]) ?>" alt="Signature">
        <div class="sig-label"><?= h($pos['full_name']) ?></div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Signature record table -->
<table class="sig-table" style="margin-top:32px;">
    <thead>
        <tr>
            <th>#</th>
            <th>Signer</th>
            <th>Status</th>
            <th>Signed At</th>
            <th>Position (Page / X% / Y%)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($positions as $i => $pos): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td>
            <strong><?= h($pos['full_name']) ?></strong><br>
            <span style="font-size:.78rem;color:#888;"><?= h($pos['email']) ?></span>
        </td>
        <td>
            <?php if ($pos['sig_id']): ?>
            <span style="color:#065f46;font-weight:700;">✓ Signed</span>
            <?php else: ?>
            <span style="color:#b45309;">⌛ Pending</span>
            <?php endif; ?>
        </td>
        <td><?= $pos['sig_id'] ? date('d M Y, g:i A', strtotime($pos['signed_at'])) : '—' ?></td>
        <td>Page <?= $pos['page_num'] ?> / <?= round($pos['x_percent'], 1) ?>% / <?= round($pos['y_percent'], 1) ?>%</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top:30px;padding-top:16px;border-top:1px solid #ddd;font-size:.75rem;color:#aaa;text-align:center;">
    Generated from Prime University Admin Panel on <?= date('d F Y, g:i A') ?>
</div>

</body>
</html>
