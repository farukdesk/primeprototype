<?php
/**
 * Print-to-PDF view of all pages in a file.
 * Opens a print-optimised HTML page in a new tab.
 * Each page is rendered on a separate printed page.
 * Images are shown inline; PDFs show a download link.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$file_id = (int)($_GET['id'] ?? 0);
if ($file_id < 1) { flash_set('error', 'Invalid file.'); redirect(APP_URL . '/file-manager/index.php'); }

$f_stmt = db()->prepare(
    'SELECT f.*, u.full_name AS creator_name
     FROM file_manager_files f
     LEFT JOIN users u ON u.id = f.creator_id
     WHERE f.id = ?'
);
$f_stmt->execute([$file_id]);
$file = $f_stmt->fetch();
if (!$file) { flash_set('error', 'File not found.'); redirect(APP_URL . '/file-manager/index.php'); }
if (!fm_can_view_file($file)) { flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php'); }

$pages = fm_get_pages($file_id);
if (empty($pages)) {
    flash_set('error', 'This file has no pages to download.');
    redirect(APP_URL . '/file-manager/view.php?id=' . $file_id);
}

// Output a standalone printable HTML document
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($file['file_name']) ?> – All Pages</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f0f0f0;
    margin: 0; padding: 0;
    color: #222;
  }

  /* Screen: toolbar */
  .no-print {
    background: #1a1f36;
    color: #fff;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: sticky;
    top: 0;
    z-index: 999;
  }
  .no-print h1 { margin: 0; font-size: 1rem; flex-grow: 1; }
  .no-print button {
    background: #4f8ef7;
    color: #fff;
    border: none;
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: .95rem;
    font-weight: 600;
  }
  .no-print a {
    color: #aab4cc;
    font-size: .85rem;
    text-decoration: none;
  }

  /* File header (first page header) */
  .file-header {
    background: #fff;
    border-bottom: 3px solid #4f8ef7;
    padding: 24px 32px;
    margin-bottom: 16px;
  }
  .file-header h2 { margin: 0 0 6px; font-size: 1.4rem; }
  .file-meta { font-size: .83rem; color: #666; margin-top: 8px; }

  /* Each page block */
  .page-block {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    margin: 16px auto;
    max-width: 860px;
    overflow: hidden;
    page-break-after: always;
  }
  .page-block:last-child { page-break-after: auto; }
  .page-header {
    padding: 14px 24px;
    background: #f8f9ff;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .page-badge {
    background: #4f8ef7;
    color: #fff;
    font-weight: 700;
    border-radius: 6px;
    padding: 4px 12px;
    font-size: .82rem;
    white-space: nowrap;
  }
  .page-badge.notes { background: #e67e22; }
  .page-title { font-weight: 600; font-size: .95rem; }
  .page-subject { font-size: .8rem; color: #666; margin-top: 2px; }

  /* Page image */
  .page-img-wrap { text-align: center; padding: 16px; }
  .page-img-wrap img { max-width: 100%; border-radius: 4px; display: inline-block; }

  /* Signature overlay area */
  .sig-area { position: relative; display: inline-block; max-width: 100%; }
  .sig-overlay {
    position: absolute;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
  }
  .sig-overlay img { max-height: 48px; max-width: 140px; }

  /* No file / PDF notice */
  .page-link-area {
    padding: 20px 24px;
    font-size: .88rem;
    color: #555;
  }
  .page-link-area a { color: #4f8ef7; }

  /* Signatures list */
  .sig-list { padding: 12px 24px; border-top: 1px solid #f0f0f0; }
  .sig-list h6 { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: #888; margin-bottom: 8px; }
  .sig-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 12px;
    font-size: .82rem;
    color: #555;
  }
  .sig-item.done { color: #27ae60; }
  .sig-item.pending { color: #e67e22; }

  /* Print styles */
  @media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .page-block {
      box-shadow: none;
      border-radius: 0;
      margin: 0;
      border: none;
      max-width: 100%;
    }
    .file-header { border-bottom: 2px solid #000; }
  }
</style>
</head>
<body>

<!-- Toolbar (not printed) -->
<div class="no-print">
    <h1><i>📄</i> <?= h($file['file_name']) ?></h1>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>">← Back to file</a>
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<!-- File header on first page -->
<div class="file-header" style="max-width:860px;margin:16px auto 0;">
    <h2><?= h($file['file_name']) ?></h2>
    <div class="file-meta">
        <?php if ($file['initiator_name']): ?>
        <strong>Initiator:</strong> <?= h($file['initiator_name']) ?>
        <?php if ($file['initiator_designation']): ?> · <?= h($file['initiator_designation']) ?><?php endif; ?>
        <?php if ($file['initiator_department']): ?> · <?= h($file['initiator_department']) ?><?php endif; ?>
        <br>
        <?php endif; ?>
        <?php if ($file['file_location']): ?>
        <strong>Location:</strong> <?= h($file['file_location']) ?><br>
        <?php endif; ?>
        <?php if ($file['category']): ?>
        <strong>Category:</strong> <?= h($file['category']) ?> ·
        <?php endif; ?>
        <strong>Generated:</strong> <?= date('d M Y, g:i A') ?> ·
        <strong>Pages:</strong> <?= count($pages) ?>
    </div>
</div>

<?php foreach ($pages as $pg): ?>
<?php
$is_image = $pg['mime_type'] && str_starts_with($pg['mime_type'], 'image/');
$is_pdf   = $pg['mime_type'] === 'application/pdf';
$positions = $pg['requires_signature'] ? fm_get_page_positions($pg['id']) : [];
?>
<div class="page-block">
    <div class="page-header">
        <span class="page-badge <?= $pg['category'] === 'Notes' ? 'notes' : '' ?>">
            <?= $pg['category'] === 'Notes' ? '📝 Notes' : '📄 Doc' ?> P<?= $pg['page_number'] ?>
        </span>
        <div>
            <div class="page-title"><?= h($pg['title'] ?: 'Page ' . $pg['page_number']) ?></div>
            <?php if ($pg['subject']): ?>
            <div class="page-subject">Subject: <?= h($pg['subject']) ?></div>
            <?php endif; ?>
        </div>
        <div style="margin-left:auto;font-size:.78rem;color:#888;">
            <?php if ($pg['creator_name']): ?>
            Added by <?= h($pg['creator_name']) ?> · <?= date('d M Y', strtotime($pg['created_at'])) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_image && $pg['uploaded_file']): ?>
    <div class="page-img-wrap">
        <?php if (!empty($positions)): ?>
        <div class="sig-area">
            <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>"
                 alt="Page <?= $pg['page_number'] ?>">
            <?php foreach ($positions as $pos): if (!$pos['sig_id']) continue; ?>
            <?php
            // Get signer's signature image
            $sig_q = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
            $sig_q->execute([$pos['user_id']]);
            $signer_sig = $sig_q->fetchColumn();
            ?>
            <?php if ($signer_sig): ?>
            <div class="sig-overlay" style="left:<?= $pos['x_percent'] ?>%;top:<?= $pos['y_percent'] ?>%;">
                <img src="<?= UPLOAD_URL ?>/signatures/<?= h($signer_sig) ?>" alt="<?= h($pos['full_name']) ?>">
                <div style="font-size:9px;color:#555;"><?= h($pos['full_name']) ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>"
             alt="Page <?= $pg['page_number'] ?>">
        <?php endif; ?>
    </div>
    <?php elseif ($is_pdf && $pg['uploaded_file']): ?>
    <div class="page-link-area">
        <i>📎</i> PDF file: <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>" target="_blank">
            <?= h($pg['original_name']) ?>
        </a>
        <span style="color:#aaa;font-size:.8rem;">(PDF pages cannot be embedded; click to open separately)</span>
    </div>
    <?php elseif ($pg['uploaded_file']): ?>
    <div class="page-link-area">
        <i>📎</i> <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>" target="_blank">
            <?= h($pg['original_name']) ?>
        </a>
        <span style="color:#aaa;font-size:.8rem;">(<?= fm_format_size((int)$pg['file_size']) ?>)</span>
    </div>
    <?php else: ?>
    <div class="page-link-area" style="color:#aaa;">No file uploaded for this page.</div>
    <?php endif; ?>

    <!-- Signature status -->
    <?php if ($pg['requires_signature'] && !empty($positions)): ?>
    <div class="sig-list">
        <h6>Signatures</h6>
        <?php foreach ($positions as $pos): ?>
        <span class="sig-item <?= $pos['sig_id'] ? 'done' : 'pending' ?>">
            <?= $pos['sig_id'] ? '✓' : '○' ?>
            <?= h($pos['full_name']) ?>
            <?php if ($pos['signed_at']): ?>
            <span style="font-size:.75rem;color:#aaa;">(<?= date('d M Y', strtotime($pos['signed_at'])) ?>)</span>
            <?php endif; ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
// Auto-trigger print dialog after page fully loads (optional: only if ?print=1)
<?php if (!empty($_GET['print'])): ?>
window.addEventListener('load', function() { window.print(); });
<?php endif; ?>
</script>
</body>
</html>
