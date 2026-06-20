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

// Count PDF pages so we know whether to disable the print button until rendering completes
$pdf_page_count = 0;
foreach ($pages as $_pg) {
    if (($_pg['mime_type'] ?? '') === 'application/pdf' && $_pg['uploaded_file']) {
        $pdf_page_count++;
    }
}
unset($_pg);

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
    .pdf-page-wrap + .pdf-page-wrap { page-break-before: always; }
  }

  /* PDF rendering */
  .pdf-render-wrap { padding: 16px; text-align: center; }
  .pdf-loading-msg { padding: 24px; color: #666; font-size: .88rem; }
  .pdf-page-wrap { position: relative; display: block; width: 100%; }
  .pdf-page-wrap + .pdf-page-wrap { margin-top: 24px; }
  .pdf-page-wrap canvas { width: 100%; display: block; }
  .no-print button:disabled { background: #888 !important; cursor: not-allowed; }
  #pdfLoadingBanner {
    background: #fff3cd;
    border: 1px solid #ffc107;
    color: #856404;
    padding: 10px 24px;
    font-size: .85rem;
    text-align: center;
  }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body>

<!-- Toolbar (not printed) -->
<div class="no-print">
    <h1><i>📄</i> <?= h($file['file_name']) ?></h1>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $file_id ?>">← Back to file</a>
    <?php if ($pdf_page_count > 0): ?>
    <span id="renderStatus" style="font-size:.82rem;color:#aab4cc;">Rendering PDF pages…</span>
    <?php endif; ?>
    <button id="btnPrint" onclick="window.print()"
            <?= $pdf_page_count > 0 ? 'disabled' : '' ?>>
        🖨 Print / Save as PDF
    </button>
</div>
<?php if ($pdf_page_count > 0): ?>
<div id="pdfLoadingBanner">
    ⏳ Rendering <?= $pdf_page_count ?> PDF page<?= $pdf_page_count > 1 ? 's' : '' ?> — please wait before printing.
</div>
<?php endif; ?>

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
$positions  = $pg['requires_signature'] ? fm_get_page_positions($pg['id']) : [];
$text_notes = fm_get_page_text_notes($pg['id']);
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
        <?php if (!empty($positions) || !empty($text_notes)): ?>
        <div class="sig-area">
            <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>"
                 alt="Page <?= $pg['page_number'] ?>">
            <?php foreach ($positions as $pos): if (!$pos['sig_id']) continue; ?>
            <?php
            $sig_q = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
            $sig_q->execute([$pos['user_id']]);
            $signer_sig = $sig_q->fetchColumn();
            ?>
            <?php if ($signer_sig): ?>
            <div class="sig-overlay" style="left:<?= $pos['x_percent'] ?>%;top:<?= $pos['y_percent'] ?>%;">
                <img src="<?= UPLOAD_URL ?>/signatures/<?= h($signer_sig) ?>" alt="<?= h($pos['full_name']) ?>">
                <div style="font-size:9px;color:#555;"><?= h($pos['full_name']) ?></div>
                <?php if (!empty($pos['show_datetime']) && $pos['signed_at']): ?>
                <div style="font-size:8px;color:#777;"><?= date('d M Y, g:i A', strtotime($pos['signed_at'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ($text_notes as $tn): ?>
            <div style="position:absolute;transform:translate(-50%,-50%);pointer-events:none;white-space:pre-line;font-weight:600;line-height:1.3;
                        left:<?= $tn['x_percent'] ?>%;top:<?= $tn['y_percent'] ?>%;
                        font-size:<?= (int)$tn['font_size'] ?>px;color:<?= h($tn['color']) ?>;">
                <?= h($tn['content']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>"
             alt="Page <?= $pg['page_number'] ?>">
        <?php endif; ?>
    </div>
    <?php elseif ($is_pdf && $pg['uploaded_file']): ?>
    <?php
    // Build signed-positions JSON for JS rendering
    $pdf_pos_js = [];
    foreach ($positions as $pos) {
        if (!$pos['sig_id']) continue;
        $sig_q = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
        $sig_q->execute([$pos['user_id']]);
        $sf = $sig_q->fetchColumn();
        if ($sf) {
            $pdf_pos_js[] = [
                'x_percent'     => $pos['x_percent'],
                'y_percent'     => $pos['y_percent'],
                'full_name'     => $pos['full_name'],
                'sig_url'       => UPLOAD_URL . '/signatures/' . $sf,
                'show_datetime' => !empty($pos['show_datetime']),
                'signed_at'     => $pos['signed_at'],
            ];
        }
    }
    $pdf_notes_js = array_map(fn($n) => [
        'x_percent' => $n['x_percent'],
        'y_percent' => $n['y_percent'],
        'content'   => $n['content'],
        'font_size' => $n['font_size'],
        'color'     => $n['color'],
    ], $text_notes);
    ?>
    <div class="pdf-render-wrap"
         data-pdf-url="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($pg['uploaded_file']) ?>"
         data-positions="<?= h(json_encode($pdf_pos_js)) ?>"
         data-notes="<?= h(json_encode($pdf_notes_js)) ?>">
        <div class="pdf-loading-msg">⏳ Rendering PDF pages…</div>
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
(function () {
    'use strict';
    var blocks = document.querySelectorAll('.pdf-render-wrap');
    if (!blocks.length) {
        <?php if (!empty($_GET['print'])): ?>
        window.addEventListener('load', function () { window.print(); });
        <?php endif; ?>
        return;
    }

    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    var pending       = blocks.length;
    var btnPrint      = document.getElementById('btnPrint');
    var renderStatus  = document.getElementById('renderStatus');
    var loadingBanner = document.getElementById('pdfLoadingBanner');

    function onAllDone() {
        pending--;
        if (pending > 0) return;
        if (btnPrint)      { btnPrint.disabled = false; }
        if (renderStatus)  { renderStatus.textContent = 'Ready'; }
        if (loadingBanner) { loadingBanner.style.display = 'none'; }
        <?php if (!empty($_GET['print'])): ?>
        window.print();
        <?php endif; ?>
    }

    blocks.forEach(function (block) {
        var pdfUrl    = block.dataset.pdfUrl;
        var positions = JSON.parse(block.dataset.positions || '[]');
        var notes     = JSON.parse(block.dataset.notes     || '[]');

        pdfjsLib.getDocument(pdfUrl).promise.then(function (pdf) {
            block.innerHTML = '';
            var renders = [];
            for (var n = 1; n <= pdf.numPages; n++) {
                renders.push(renderPage(pdf, n, block, positions, notes));
            }
            return Promise.all(renders);
        }).then(onAllDone).catch(function (err) {
            console.error('PDF render error:', err);
            block.innerHTML =
                '<div class="page-link-area">⚠️ Could not render PDF. ' +
                '<a href="' + pdfUrl + '" target="_blank">Open PDF directly</a></div>';
            onAllDone();
        });
    });

    function renderPage(pdf, pageNum, container, positions, notes) {
        return pdf.getPage(pageNum).then(function (page) {
            var scale    = container.clientWidth > 0
                ? (container.clientWidth / page.getViewport({ scale: 1 }).width)
                : 1.5;
            var viewport = page.getViewport({ scale: scale });

            var canvas   = document.createElement('canvas');
            canvas.width  = viewport.width;
            canvas.height = viewport.height;
            canvas.style.width   = '100%';
            canvas.style.display = 'block';

            var wrap = document.createElement('div');
            wrap.className = 'pdf-page-wrap';
            wrap.appendChild(canvas);
            container.appendChild(wrap);

            return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise
                .then(function () {
                    // Overlays apply only on page 1 (where sign-map places them)
                    if (pageNum !== 1) return;

                    positions.forEach(function (pos) {
                        var ov  = document.createElement('div');
                        ov.className = 'sig-overlay';
                        ov.style.left = pos.x_percent + '%';
                        ov.style.top  = pos.y_percent + '%';

                        var img = document.createElement('img');
                        img.src           = pos.sig_url;
                        img.style.maxHeight = '48px';
                        img.style.maxWidth  = '140px';
                        ov.appendChild(img);

                        var lbl = document.createElement('div');
                        lbl.textContent    = pos.full_name;
                        lbl.style.fontSize = '9px';
                        lbl.style.color    = '#555';
                        ov.appendChild(lbl);

                        if (pos.show_datetime && pos.signed_at) {
                            var dt = document.createElement('div');
                            dt.textContent    = new Date(pos.signed_at).toLocaleString();
                            dt.style.fontSize = '8px';
                            dt.style.color    = '#777';
                            ov.appendChild(dt);
                        }
                        wrap.appendChild(ov);
                    });

                    notes.forEach(function (note) {
                        var nv = document.createElement('div');
                        nv.style.position  = 'absolute';
                        nv.style.transform = 'translate(-50%,-50%)';
                        nv.style.left      = note.x_percent + '%';
                        nv.style.top       = note.y_percent + '%';
                        nv.style.fontSize  = note.font_size + 'px';
                        nv.style.color     = note.color;
                        nv.style.fontWeight = '600';
                        nv.style.lineHeight = '1.3';
                        nv.style.whiteSpace = 'pre-line';
                        nv.style.pointerEvents = 'none';
                        nv.textContent = note.content;
                        wrap.appendChild(nv);
                    });
                });
        });
    }
}());
</script>
</body>
</html>
