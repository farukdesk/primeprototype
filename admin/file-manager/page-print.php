<?php
/**
 * Print-ready signed copy of a single file manager page.
 * Images are shown inline with signature and text-note overlays.
 * PDFs are rendered via PDF.js with signature overlays on the first page.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager');
require_once __DIR__ . '/helpers.php';

$page_id = (int)($_GET['page_id'] ?? 0);
if ($page_id < 1) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/file-manager/index.php'); }

$pg_stmt = db()->prepare(
    'SELECT p.*, f.file_name, f.id AS file_id, u.full_name AS creator_name
     FROM file_manager_pages p
     JOIN file_manager_files f ON f.id = p.file_id
     LEFT JOIN users u ON u.id = p.created_by
     WHERE p.id = ?'
);
$pg_stmt->execute([$page_id]);
$page = $pg_stmt->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/file-manager/index.php'); }

$file_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
$file_stmt->execute([$page['file_id']]);
$file = $file_stmt->fetch();
if (!$file || !fm_can_view_file($file)) {
    flash_set('error', 'Access denied.'); redirect(APP_URL . '/file-manager/index.php');
}

$positions  = $page['requires_signature'] ? fm_get_page_positions($page_id) : [];
$text_notes = fm_get_page_text_notes($page_id);

$is_image = $page['mime_type'] && str_starts_with($page['mime_type'], 'image/');
$is_pdf   = $page['mime_type'] === 'application/pdf';

// Build signed-positions with resolved signature image URLs (signed only)
$signed_positions = [];
foreach ($positions as $pos) {
    if (!$pos['sig_id']) continue;
    $sig_q = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
    $sig_q->execute([$pos['user_id']]);
    $sf = $sig_q->fetchColumn();
    if ($sf) {
        $signed_positions[] = [
            'x_percent'     => $pos['x_percent'],
            'y_percent'     => $pos['y_percent'],
            'full_name'     => $pos['full_name'],
            'sig_url'       => UPLOAD_URL . '/signatures/' . $sf,
            'show_datetime' => !empty($pos['show_datetime']),
            'signed_at'     => $pos['signed_at'],
        ];
    }
}

// Lightweight note data for JS
$notes_js = array_map(fn($n) => [
    'x_percent' => $n['x_percent'],
    'y_percent' => $n['y_percent'],
    'content'   => $n['content'],
    'font_size' => $n['font_size'],
    'color'     => $n['color'],
], $text_notes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($file['file_name']) ?> – Page <?= $page['page_number'] ?> (Signed Copy)</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f0f0f0;
    margin: 0; padding: 0;
    color: #222;
  }
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
  .no-print button:disabled { background: #888; cursor: not-allowed; }
  .no-print a { color: #aab4cc; font-size: .85rem; text-decoration: none; }
  .no-print .render-status { font-size: .82rem; color: #aab4cc; }

  .page-block {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    margin: 16px auto;
    max-width: 860px;
    overflow: hidden;
  }
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

  .page-img-wrap { text-align: center; padding: 16px; }
  .page-img-wrap img { max-width: 100%; border-radius: 4px; display: inline-block; }

  .sig-area {
    position: relative;
    display: inline-block;
    max-width: 100%;
    width: 100%;
  }
  .sig-overlay {
    position: absolute;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
  }
  .sig-overlay img { max-height: 48px; max-width: 140px; }
  .sig-overlay .sig-dt { font-size: 8px; color: #777; margin-top: 2px; }

  .text-note-overlay {
    position: absolute;
    transform: translate(-50%, -50%);
    pointer-events: none;
    white-space: pre-line;
    font-weight: 600;
    line-height: 1.3;
  }

  .pdf-loading-msg { padding: 32px; text-align: center; color: #666; font-size: .9rem; }
  .pdf-page-wrap { position: relative; display: block; width: 100%; }
  .pdf-page-wrap + .pdf-page-wrap { margin-top: 24px; }
  .pdf-page-wrap canvas { width: 100%; display: block; }

  .page-link-area { padding: 20px 24px; font-size: .88rem; color: #555; }
  .page-link-area a { color: #4f8ef7; }

  .sig-list { padding: 12px 24px; border-top: 1px solid #f0f0f0; }
  .sig-list h6 { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: #888; margin-bottom: 8px; }
  .sig-item { display: inline-flex; align-items: center; gap: 6px; margin-right: 12px; font-size: .82rem; color: #555; }
  .sig-item.done { color: #27ae60; }
  .sig-item.pending { color: #e67e22; }

  .page-footer { max-width: 860px; margin: 8px auto; font-size: .75rem; color: #aaa; text-align: right; padding: 0 8px; }

  @media print {
    .no-print { display: none !important; }
    body { background: #fff; }
    .page-block { box-shadow: none; border-radius: 0; margin: 0; border: none; max-width: 100%; }
    .pdf-page-wrap + .pdf-page-wrap { page-break-before: always; }
  }
</style>
</head>
<body>

<!-- Toolbar (not printed) -->
<div class="no-print">
    <h1>📄 <?= h($file['file_name']) ?> – Page <?= $page['page_number'] ?></h1>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $page['file_id'] ?>">← Back to file</a>
    <?php if ($is_pdf && $page['uploaded_file']): ?>
    <span class="render-status" id="renderStatus">Rendering PDF…</span>
    <?php endif; ?>
    <button id="btnPrint" onclick="window.print()"
            <?= ($is_pdf && $page['uploaded_file']) ? 'disabled' : '' ?>>
        🖨 Print / Save as PDF
    </button>
</div>

<div class="page-block" style="margin-top:16px;">
    <!-- Page header -->
    <div class="page-header">
        <span class="page-badge <?= $page['category'] === 'Notes' ? 'notes' : '' ?>">
            <?= $page['category'] === 'Notes' ? '📝 Notes' : '📄 Doc' ?> P<?= $page['page_number'] ?>
        </span>
        <div>
            <div class="page-title"><?= h($page['title'] ?: 'Page ' . $page['page_number']) ?></div>
            <?php if ($page['subject']): ?>
            <div class="page-subject">Subject: <?= h($page['subject']) ?></div>
            <?php endif; ?>
        </div>
        <div style="margin-left:auto;font-size:.78rem;color:#888;">
            <?= h($file['file_name']) ?>
            <?php if ($page['creator_name']): ?> · Added by <?= h($page['creator_name']) ?><?php endif; ?>
            · <?= date('d M Y', strtotime($page['created_at'])) ?>
        </div>
    </div>

    <!-- Page content -->
    <?php if ($is_image && $page['uploaded_file']): ?>
    <div class="page-img-wrap">
        <div class="sig-area">
            <img src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>"
                 alt="Page <?= $page['page_number'] ?>">
            <?php foreach ($signed_positions as $pos): ?>
            <div class="sig-overlay" style="left:<?= $pos['x_percent'] ?>%;top:<?= $pos['y_percent'] ?>%;">
                <img src="<?= h($pos['sig_url']) ?>" alt="<?= h($pos['full_name']) ?>">
                <div style="font-size:9px;color:#555;"><?= h($pos['full_name']) ?></div>
                <?php if ($pos['show_datetime'] && $pos['signed_at']): ?>
                <div class="sig-dt"><?= date('d M Y, g:i A', strtotime($pos['signed_at'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php foreach ($text_notes as $note): ?>
            <div class="text-note-overlay"
                 style="left:<?= $note['x_percent'] ?>%;top:<?= $note['y_percent'] ?>%;font-size:<?= (int)$note['font_size'] ?>px;color:<?= h($note['color']) ?>;">
                <?= h($note['content']) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php elseif ($is_pdf && $page['uploaded_file']): ?>
    <div class="page-img-wrap">
        <div class="sig-area" id="pdfRenderArea"
             data-pdf-url="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>"
             data-positions="<?= h(json_encode($signed_positions)) ?>"
             data-notes="<?= h(json_encode($notes_js)) ?>">
            <div class="pdf-loading-msg" id="pdfLoadMsg">⏳ Rendering PDF pages, please wait…</div>
        </div>
    </div>

    <?php elseif ($page['uploaded_file']): ?>
    <div class="page-link-area">
        📎 <a href="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>" target="_blank">
            <?= h($page['original_name']) ?>
        </a>
        <span style="color:#aaa;font-size:.8rem;">(<?= fm_format_size((int)$page['file_size']) ?>)</span>
    </div>

    <?php else: ?>
    <div class="page-link-area" style="color:#aaa;">No file uploaded for this page.</div>
    <?php endif; ?>

    <!-- Signature status list -->
    <?php if ($page['requires_signature'] && !empty($positions)): ?>
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

<div class="page-footer">Generated on <?= date('d M Y, g:i A') ?></div>

<?php if ($is_pdf && $page['uploaded_file']): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    'use strict';
    var area         = document.getElementById('pdfRenderArea');
    var loadMsg      = document.getElementById('pdfLoadMsg');
    var btnPrint     = document.getElementById('btnPrint');
    var renderStatus = document.getElementById('renderStatus');
    if (!area) return;

    var pdfUrl    = area.dataset.pdfUrl;
    var positions = JSON.parse(area.dataset.positions || '[]');
    var notes     = JSON.parse(area.dataset.notes     || '[]');

    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    pdfjsLib.getDocument(pdfUrl).promise.then(function (pdf) {
        if (loadMsg) loadMsg.style.display = 'none';
        var renders = [];
        for (var n = 1; n <= pdf.numPages; n++) {
            renders.push(renderPage(pdf, n));
        }
        return Promise.all(renders);
    }).then(function () {
        if (btnPrint)     { btnPrint.disabled = false; }
        if (renderStatus) { renderStatus.textContent = 'Ready'; }
    }).catch(function (err) {
        console.error('PDF render error:', err);
        if (loadMsg) {
            loadMsg.innerHTML = '⚠️ Could not render PDF. <a href="' + pdfUrl + '" target="_blank">Open PDF directly</a>';
            loadMsg.style.display = '';
        }
        if (btnPrint)     { btnPrint.disabled = false; }
        if (renderStatus) { renderStatus.textContent = ''; }
    });

    function renderPage(pdf, pageNum) {
        return pdf.getPage(pageNum).then(function (page) {
            var scale    = area.clientWidth > 0
                ? (area.clientWidth / page.getViewport({ scale: 1 }).width)
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
            area.appendChild(wrap);

            return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise
                .then(function () {
                    // Overlays apply only to page 1 (where sign-map places them)
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
                        lbl.textContent   = pos.full_name;
                        lbl.style.fontSize = '9px';
                        lbl.style.color    = '#555';
                        ov.appendChild(lbl);

                        if (pos.show_datetime && pos.signed_at) {
                            var dt = document.createElement('div');
                            dt.textContent   = new Date(pos.signed_at).toLocaleString();
                            dt.style.fontSize = '8px';
                            dt.style.color    = '#777';
                            ov.appendChild(dt);
                        }
                        wrap.appendChild(ov);
                    });

                    notes.forEach(function (note) {
                        var nv = document.createElement('div');
                        nv.className      = 'text-note-overlay';
                        nv.style.left     = note.x_percent + '%';
                        nv.style.top      = note.y_percent + '%';
                        nv.style.fontSize = note.font_size + 'px';
                        nv.style.color    = note.color;
                        nv.textContent    = note.content;
                        wrap.appendChild(nv);
                    });
                });
        });
    }
}());
</script>
<?php endif; ?>
<?php if (!empty($_GET['print'])): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
