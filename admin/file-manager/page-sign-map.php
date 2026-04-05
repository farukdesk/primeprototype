<?php
/**
 * Drag & drop signature placement + text notes for a Notes page.
 * v4: PDF.js viewer, live drag repositioning, auto date/time, text notes.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('file-manager', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_id = (int)($_GET['page_id'] ?? $_POST['page_id'] ?? 0);
if ($page_id < 1) { flash_set('error', 'Invalid page.'); redirect(APP_URL . '/file-manager/index.php'); }

$pg_stmt = db()->prepare(
    'SELECT p.*, f.file_name, f.id AS file_id
     FROM file_manager_pages p
     JOIN file_manager_files f ON f.id = p.file_id
     WHERE p.id = ?'
);
$pg_stmt->execute([$page_id]);
$page = $pg_stmt->fetch();
if (!$page) { flash_set('error', 'Page not found.'); redirect(APP_URL . '/file-manager/index.php'); }

if ($page['category'] !== 'Notes') {
    flash_set('error', 'Signature placement is only available for Notes pages.');
    redirect(APP_URL . '/file-manager/view.php?id=' . $page['file_id']);
}

$page_title = 'Manage Signers – ' . h($page['file_name']);
$errors     = [];
$user       = auth_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Add signer ────────────────────────────────────────────────────────────
    if ($action === 'add_signer') {
        $user_id       = (int)($_POST['user_id']       ?? 0);
        $x_percent     = min(100, max(0, (float)($_POST['x_percent'] ?? 50)));
        $y_percent     = min(100, max(0, (float)($_POST['y_percent'] ?? 80)));
        $show_datetime = !empty($_POST['show_datetime']) ? 1 : 0;

        if ($user_id < 1) {
            $errors[] = 'Please select a user.';
        } else {
            $u_stmt = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ? AND is_active = 1');
            $u_stmt->execute([$user_id]);
            $signer = $u_stmt->fetch();
            if (!$signer) {
                $errors[] = 'User not found or inactive.';
            } else {
                $order_stmt = db()->prepare(
                    'SELECT COALESCE(MAX(sort_order),0)+1 FROM file_manager_page_sign_positions WHERE page_id = ?'
                );
                $order_stmt->execute([$page_id]);
                $sort_order = (int)$order_stmt->fetchColumn();

                db()->prepare(
                    'INSERT INTO file_manager_page_sign_positions
                        (page_id, user_id, x_percent, y_percent, sort_order, show_datetime)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        x_percent=VALUES(x_percent),
                        y_percent=VALUES(y_percent),
                        show_datetime=VALUES(show_datetime)'
                )->execute([$page_id, $user_id, $x_percent, $y_percent, $sort_order, $show_datetime]);

                $already = db()->prepare(
                    'SELECT 1 FROM file_manager_page_signatures WHERE page_id = ? AND user_id = ? LIMIT 1'
                );
                $already->execute([$page_id, $user_id]);
                if (!$already->fetchColumn()) {
                    $file_stmt = db()->prepare('SELECT * FROM file_manager_files WHERE id = ?');
                    $file_stmt->execute([$page['file_id']]);
                    $file_data = $file_stmt->fetch();
                    fm_notify_sign_request($file_data, $page, $signer, $user);
                }

                flash_set('success', 'Signer added.');
                redirect(APP_URL . '/file-manager/page-sign-map.php?page_id=' . $page_id);
            }
        }
    }

    // ── Remove signer ─────────────────────────────────────────────────────────
    if ($action === 'remove_signer') {
        $pos_id = (int)($_POST['pos_id'] ?? 0);
        db()->prepare(
            'DELETE FROM file_manager_page_sign_positions WHERE id = ? AND page_id = ?'
        )->execute([$pos_id, $page_id]);
        flash_set('success', 'Signer removed.');
        redirect(APP_URL . '/file-manager/page-sign-map.php?page_id=' . $page_id);
    }

    // ── Update signer position (AJAX drag) ────────────────────────────────────
    if ($action === 'update_position') {
        $pos_id    = (int)($_POST['pos_id']    ?? 0);
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 0)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 0)));
        db()->prepare(
            'UPDATE file_manager_page_sign_positions SET x_percent=?, y_percent=? WHERE id=? AND page_id=?'
        )->execute([$x_percent, $y_percent, $pos_id, $page_id]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Add text note ─────────────────────────────────────────────────────────
    if ($action === 'add_text_note') {
        $content   = trim($_POST['content']   ?? '');
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 10)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 10)));
        $font_size = min(48, max(8, (int)($_POST['font_size']   ?? 12)));
        $color     = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '')
                        ? $_POST['color'] : '#000000';

        if ($content === '') {
            $errors[] = 'Note content cannot be empty.';
        } else {
            db()->prepare(
                'INSERT INTO file_manager_page_text_notes
                    (page_id, content, x_percent, y_percent, font_size, color, created_by)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$page_id, $content, $x_percent, $y_percent, $font_size, $color, $user['id']]);
            flash_set('success', 'Text note added.');
            redirect(APP_URL . '/file-manager/page-sign-map.php?page_id=' . $page_id);
        }
    }

    // ── Remove text note ──────────────────────────────────────────────────────
    if ($action === 'remove_text_note') {
        $note_id = (int)($_POST['note_id'] ?? 0);
        db()->prepare(
            'DELETE FROM file_manager_page_text_notes WHERE id = ? AND page_id = ?'
        )->execute([$note_id, $page_id]);
        flash_set('success', 'Text note removed.');
        redirect(APP_URL . '/file-manager/page-sign-map.php?page_id=' . $page_id);
    }

    // ── Update text note position (AJAX drag) ─────────────────────────────────
    if ($action === 'update_note_position') {
        $note_id   = (int)($_POST['note_id']   ?? 0);
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 0)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 0)));
        db()->prepare(
            'UPDATE file_manager_page_text_notes SET x_percent=?, y_percent=? WHERE id=? AND page_id=?'
        )->execute([$x_percent, $y_percent, $note_id, $page_id]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

$all_users  = db()->query(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();
$positions  = fm_get_page_positions($page_id);
$text_notes = fm_get_page_text_notes($page_id);

$doc_type = null;
if ($page['mime_type']) {
    $doc_type = str_starts_with($page['mime_type'], 'image/') ? 'image' : 'other';
    if ($page['mime_type'] === 'application/pdf') $doc_type = 'pdf';
}
$doc_url = $page['uploaded_file']
    ? UPLOAD_URL . '/' . FM_UPLOAD_SUBDIR . '/' . rawurlencode($page['uploaded_file'])
    : null;

require_once __DIR__ . '/../includes/header.php';
?>

<!-- PDF.js (loaded only when needed) -->
<?php if ($doc_type === 'pdf'): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/index.php">File Manager</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $page['file_id'] ?>"><?= h($page['file_name']) ?></a></li>
            <li class="breadcrumb-item active">Manage Signers – Page <?= $page['page_number'] ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/file-manager/view.php?id=<?= $page['file_id'] ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to File
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($page['subject']): ?>
<div class="alert alert-light border mb-4" style="border-radius:10px;">
    <i class="fas fa-tag me-2 text-warning"></i>
    <strong>Subject:</strong> <?= h($page['subject']) ?>
</div>
<?php endif; ?>

<!-- Mode toolbar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <span class="fw-medium text-muted me-1" style="font-size:.87rem;">Placement mode:</span>
    <button type="button" id="modeSignerBtn" class="btn btn-primary btn-sm" style="border-radius:8px;"
            onclick="setMode('signer')">
        <i class="fas fa-user-edit me-1"></i> Place Signer
    </button>
    <button type="button" id="modeNoteBtn" class="btn btn-outline-warning btn-sm" style="border-radius:8px;"
            onclick="setMode('note')">
        <i class="fas fa-sticky-note me-1"></i> Place Text Note
    </button>
    <span id="placementHint" class="text-muted ms-2" style="font-size:.82rem;">
        <i class="fas fa-mouse-pointer me-1"></i>
        <?php if (!$doc_url): ?>
            No document uploaded – add a file to this page first.
        <?php else: ?>
            Select a signer or enter note text, then click the document to place.
        <?php endif; ?>
    </span>
</div>

<div class="row g-4">
    <!-- Document canvas / image -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;overflow:hidden;">
            <div class="card-body p-0">
                <?php if (!$doc_url): ?>
                <div class="p-5 text-center text-muted">
                    <i class="fas fa-file-upload fa-2x mb-3 d-block opacity-50"></i>
                    No file uploaded for this page. Upload a file to enable visual placement.
                </div>
                <?php else: ?>
                <div id="docWrapper"
                     style="position:relative;cursor:crosshair;user-select:none;background:#e9ecef;min-height:300px;">
                    <?php if ($doc_type === 'image'): ?>
                    <img id="docImg"
                         src="<?= $doc_url ?>"
                         alt="Page" style="width:100%;display:block;border-radius:0;">
                    <?php elseif ($doc_type === 'pdf'): ?>
                    <div id="pdfLoadMsg" class="p-3 text-center text-muted" style="font-size:.85rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading PDF…
                    </div>
                    <canvas id="pdfCanvas" style="width:100%;display:block;"></canvas>
                    <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-file me-2"></i>
                        Preview not available for this file type.
                        Positions will still be saved and used during signing.
                    </div>
                    <?php endif; ?>
                    <!-- Overlay: pins and text notes rendered here -->
                    <div id="overlayLayer"
                         style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right panel -->
    <div class="col-lg-4">

        <!-- Add signer form -->
        <div class="card mb-4" style="border-radius:12px;" id="signerPanel">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-primary"></i>Add / Update Signer</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="addSignerForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="add_signer">
                    <input type="hidden" name="page_id"   value="<?= $page_id ?>">
                    <input type="hidden" name="x_percent" id="signerX" value="50">
                    <input type="hidden" name="y_percent" id="signerY" value="80">
                    <div class="mb-3">
                        <label class="form-label fw-medium">User <span class="text-danger">*</span></label>
                        <select name="user_id" id="signerUserSel" class="form-select" required
                                onfocus="setMode('signer')">
                            <option value="">— Select user —</option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?> &lt;<?= h($u['email']) ?>&gt;</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="show_datetime"
                                   id="showDatetime" value="1" checked>
                            <label class="form-check-label" for="showDatetime">
                                Show date &amp; time under signature
                            </label>
                        </div>
                    </div>
                    <div class="alert alert-primary py-2 px-3 mb-3" id="signerPlaceHint" style="border-radius:8px;font-size:.82rem;">
                        <i class="fas fa-map-marker-alt me-1"></i>
                        <span id="signerPlaceStatus">Click on the document to set position, then click Add Signer.</span>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i> Add Signer
                    </button>
                </form>
            </div>
        </div>

        <!-- Add text note form -->
        <div class="card mb-4" style="border-radius:12px;" id="notePanel">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-sticky-note me-2 text-warning"></i>Add Text Note</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="addNoteForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="add_text_note">
                    <input type="hidden" name="page_id"   value="<?= $page_id ?>">
                    <input type="hidden" name="x_percent" id="noteX" value="10">
                    <input type="hidden" name="y_percent" id="noteY" value="10">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Note Text <span class="text-danger">*</span></label>
                        <textarea name="content" id="noteContent" class="form-control" rows="3"
                                  maxlength="500" placeholder="Type your note here…"
                                  onfocus="setMode('note')"></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium" style="font-size:.82rem;">Font Size (px)</label>
                            <input type="number" name="font_size" id="noteFontSize" class="form-control form-control-sm"
                                   value="12" min="8" max="48">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium" style="font-size:.82rem;">Color</label>
                            <input type="color" name="color" id="noteColor" class="form-control form-control-sm form-control-color"
                                   value="#000000" style="height:calc(1.5em + .5rem + 2px);">
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 px-3 mb-3" id="notePlaceHint" style="border-radius:8px;font-size:.82rem;">
                        <i class="fas fa-map-marker-alt me-1"></i>
                        <span id="notePlaceStatus">Click on the document to set note position, then click Add Note.</span>
                    </div>
                    <button type="submit" class="btn btn-warning w-100 text-dark" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i> Add Note
                    </button>
                </form>
            </div>
        </div>

        <!-- Signers list -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Signers</h6>
                <span class="badge bg-secondary"><?= count($positions) ?></span>
            </div>
            <?php if (empty($positions)): ?>
            <div class="card-body px-4 py-3 text-muted" style="font-size:.85rem;">No signers added yet.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <?php foreach ($positions as $pos): ?>
                <li class="list-group-item px-4 py-3">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-medium" style="font-size:.87rem;"><?= h($pos['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;">
                                <?= round($pos['x_percent'], 1) ?>%, <?= round($pos['y_percent'], 1) ?>%
                                <?php if ($pos['show_datetime'] ?? 1): ?>
                                · <i class="fas fa-clock ms-1" title="Date/time shown"></i>
                                <?php endif; ?>
                            </div>
                            <?php if ($pos['sig_id']): ?>
                            <span class="badge bg-success bg-opacity-10 text-success mt-1">
                                <i class="fas fa-check me-1"></i>Signed <?= date('d M', strtotime($pos['signed_at'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-warning bg-opacity-10 text-warning mt-1">
                                <i class="fas fa-clock me-1"></i>Pending
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$pos['sig_id']): ?>
                        <form method="POST" class="flex-shrink-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"  value="remove_signer">
                            <input type="hidden" name="page_id" value="<?= $page_id ?>">
                            <input type="hidden" name="pos_id"  value="<?= $pos['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:6px;"
                                    onclick="return confirm(<?= h(json_encode('Remove ' . $pos['full_name'] . ' as a signer?')) ?>)">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Text notes list -->
        <?php if (!empty($text_notes)): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-sticky-note me-2 text-warning"></i>Text Notes</h6>
                <span class="badge bg-secondary"><?= count($text_notes) ?></span>
            </div>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <?php foreach ($text_notes as $note): ?>
                <li class="list-group-item px-4 py-3">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div style="font-size:.85rem;word-break:break-word;max-width:180px;">
                            <?php $safe_color = preg_match('/^#[0-9a-fA-F]{6}$/', $note['color']) ? $note['color'] : '#000000'; ?>
                            <span style="color:<?= $safe_color ?>;font-size:<?= (int)$note['font_size'] ?>px;line-height:1.3;">
                                <?= h($note['content']) ?>
                            </span>
                            <div class="text-muted mt-1" style="font-size:.72rem;">
                                <?= round($note['x_percent'], 1) ?>%, <?= round($note['y_percent'], 1) ?>%
                            </div>
                        </div>
                        <form method="POST" class="flex-shrink-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action"  value="remove_text_note">
                            <input type="hidden" name="page_id" value="<?= $page_id ?>">
                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:6px;"
                                    onclick="return confirm('Remove this text note?')">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div><!-- /col-lg-4 -->
</div>

<script>
// ── Global config ──────────────────────────────────────────────────────────────
var PAGE_ID    = <?= $page_id ?>;
var CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
var SAVE_URL   = <?= json_encode(APP_URL . '/file-manager/page-sign-map.php') ?>;
var DOC_TYPE   = <?= json_encode($doc_type) ?>;
var DOC_URL    = <?= json_encode($doc_url) ?>;
var HAS_DOC    = <?= $doc_url ? 'true' : 'false' ?>;

var positionsData = <?= json_encode(array_map(fn($p) => [
    'id'            => $p['id'],
    'user_id'       => $p['user_id'],
    'name'          => $p['full_name'],
    'x'             => (float)$p['x_percent'],
    'y'             => (float)$p['y_percent'],
    'signed'        => !empty($p['sig_id']),
    'show_datetime' => !empty($p['show_datetime']),
], $positions)) ?>;

var notesData = <?= json_encode(array_map(fn($n) => [
    'id'        => $n['id'],
    'content'   => $n['content'],
    'x'         => (float)$n['x_percent'],
    'y'         => (float)$n['y_percent'],
    'font_size' => (int)$n['font_size'],
    'color'     => $n['color'],
], $text_notes)) ?>;

// ── State ──────────────────────────────────────────────────────────────────────
var currentMode  = 'signer';
var previewPin   = null;
var previewNote  = null;

// ── Mode switching ─────────────────────────────────────────────────────────────
function setMode(mode) {
    currentMode = mode;
    var sigBtn  = document.getElementById('modeSignerBtn');
    var noteBtn = document.getElementById('modeNoteBtn');
    var sigPanel  = document.getElementById('signerPanel');
    var notePanel = document.getElementById('notePanel');

    if (mode === 'signer') {
        sigBtn.className  = 'btn btn-primary btn-sm';
        noteBtn.className = 'btn btn-outline-warning btn-sm';
        sigPanel.style.boxShadow  = '0 0 0 2px #0d6efd44';
        notePanel.style.boxShadow = '';
        document.getElementById('placementHint').innerHTML =
            '<i class="fas fa-mouse-pointer me-1 text-primary"></i>' +
            '<span class="text-primary fw-medium">Signer mode</span> – click document to place pin, then drag to fine-tune.';
    } else {
        noteBtn.className = 'btn btn-warning btn-sm';
        sigBtn.className  = 'btn btn-outline-primary btn-sm';
        notePanel.style.boxShadow = '0 0 0 2px #ffc10744';
        sigPanel.style.boxShadow  = '';
        document.getElementById('placementHint').innerHTML =
            '<i class="fas fa-mouse-pointer me-1 text-warning"></i>' +
            '<span class="text-warning fw-medium">Note mode</span> – click document to place text note, then drag to fine-tune.';
    }
}

// ── Coordinate helper ──────────────────────────────────────────────────────────
function getDocPercent(e) {
    var wrapper = document.getElementById('docWrapper');
    if (!wrapper) return { x: 50, y: 50 };
    var rect = wrapper.getBoundingClientRect();
    return {
        x: Math.min(100, Math.max(0, (e.clientX - rect.left) / rect.width * 100)),
        y: Math.min(100, Math.max(0, (e.clientY - rect.top)  / rect.height * 100)),
    };
}

// ── Preview helpers ────────────────────────────────────────────────────────────
function showSignerPreview(x, y) {
    removePreview();
    var ol = document.getElementById('overlayLayer');
    if (!ol) return;

    var nameEl = document.getElementById('signerUserSel');
    var label  = nameEl && nameEl.selectedIndex > 0
        ? nameEl.options[nameEl.selectedIndex].text.split('<')[0].trim()
        : '(signer)';

    previewPin = document.createElement('div');
    previewPin.id = 'previewPin';
    previewPin.style.cssText =
        'position:absolute;left:' + x + '%;top:' + y + '%;' +
        'transform:translate(-50%,-100%);pointer-events:none;z-index:100;' +
        'text-align:center;animation:pulseDrop .3s ease;';
    previewPin.innerHTML =
        '<div style="background:#0d6efd;color:#fff;border-radius:6px;' +
        'padding:4px 10px;font-size:11px;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.2);">' +
        '<i class="fas fa-map-marker-alt me-1"></i>' + escHtml(label) +
        '</div>' +
        '<div style="width:2px;height:12px;background:#0d6efd;margin:0 auto;"></div>';
    ol.appendChild(previewPin);

    document.getElementById('signerPlaceStatus').textContent =
        'Position set (' + x.toFixed(1) + '%, ' + y.toFixed(1) + '%) – drag pin or click again to adjust.';
}

function showNotePreview(x, y) {
    removePreview();
    var ol = document.getElementById('overlayLayer');
    if (!ol) return;

    var content = (document.getElementById('noteContent').value || '(note)').substring(0, 40);
    var color   = safeColor(document.getElementById('noteColor').value || '#000000');
    var fs      = parseInt(document.getElementById('noteFontSize').value) || 12;

    previewNote = document.createElement('div');
    previewNote.id = 'previewNote';
    previewNote.style.cssText =
        'position:absolute;left:' + x + '%;top:' + y + '%;' +
        'transform:translate(-50%,-50%);pointer-events:none;z-index:100;' +
        'background:rgba(255,240,150,.9);border:2px dashed ' + color + ';' +
        'border-radius:4px;padding:4px 8px;max-width:200px;word-break:break-word;' +
        'font-size:' + fs + 'px;color:' + color + ';box-shadow:0 2px 6px rgba(0,0,0,.15);';
    previewNote.textContent = content;
    ol.appendChild(previewNote);

    document.getElementById('notePlaceStatus').textContent =
        'Position set (' + x.toFixed(1) + '%, ' + y.toFixed(1) + '%) – drag note or click again to adjust.';
}

function removePreview() {
    if (previewPin)  { previewPin.remove();  previewPin  = null; }
    if (previewNote) { previewNote.remove(); previewNote = null; }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function safeColor(c) {
    return /^#[0-9a-fA-F]{6}$/.test(String(c)) ? c : '#000000';
}

// ── Click on document ──────────────────────────────────────────────────────────
function initDocWrapper() {
    var wrapper = document.getElementById('docWrapper');
    if (!wrapper) return;

    wrapper.addEventListener('click', function(e) {
        // Skip if click was on an overlay element
        if (e.target.closest('.overlay-pin') || e.target.closest('.overlay-note')) return;
        if (!HAS_DOC) return;

        var pct = getDocPercent(e);

        if (currentMode === 'signer') {
            document.getElementById('signerX').value = pct.x.toFixed(2);
            document.getElementById('signerY').value = pct.y.toFixed(2);
            showSignerPreview(pct.x, pct.y);
        } else {
            document.getElementById('noteX').value = pct.x.toFixed(2);
            document.getElementById('noteY').value = pct.y.toFixed(2);
            showNotePreview(pct.x, pct.y);
        }
    });

    renderOverlays();
}

// ── Render overlays ────────────────────────────────────────────────────────────
function renderOverlays() {
    var ol = document.getElementById('overlayLayer');
    if (!ol) return;
    ol.innerHTML = '';

    // Signer pins
    positionsData.forEach(function(pos) {
        var pin = document.createElement('div');
        pin.className = 'overlay-pin';
        pin.dataset.posId = pos.id;
        pin.dataset.type  = 'signer';
        pin.style.cssText =
            'position:absolute;left:' + pos.x + '%;top:' + pos.y + '%;' +
            'transform:translate(-50%,-100%);text-align:center;z-index:50;' +
            'cursor:' + (pos.signed ? 'default' : 'grab') + ';';

        var dtLabel = (pos.show_datetime && pos.signed)
            ? '<div style="font-size:9px;color:#065f46;margin-top:1px;">✓ signed</div>' : '';

        pin.innerHTML =
            '<div style="background:' + (pos.signed ? 'rgba(39,174,96,.9)' : 'rgba(230,126,34,.9)') + ';' +
            'color:#fff;border-radius:6px;padding:4px 10px;font-size:11px;white-space:nowrap;' +
            'box-shadow:0 2px 6px rgba(0,0,0,.2);">' +
            (pos.signed ? '<i class="fas fa-check me-1"></i>' : '<i class="fas fa-pen me-1"></i>') +
            escHtml(pos.name) + dtLabel +
            '</div>' +
            '<div style="width:2px;height:12px;background:' +
            (pos.signed ? '#27ae60' : '#e67e22') + ';margin:0 auto;"></div>';

        if (!pos.signed) {
            makeDraggable(pin, pos.id, 'signer');
        }
        ol.appendChild(pin);
    });

    // Text note boxes
    notesData.forEach(function(note) {
        var box = document.createElement('div');
        box.className = 'overlay-note';
        box.dataset.noteId = note.id;
        box.dataset.type   = 'note';
        var sc = safeColor(note.color);
        box.style.cssText =
            'position:absolute;left:' + note.x + '%;top:' + note.y + '%;' +
            'transform:translate(-50%,-50%);z-index:50;cursor:grab;' +
            'background:rgba(255,240,150,.92);border:1.5px solid ' + sc + ';' +
            'border-radius:4px;padding:4px 8px;max-width:220px;word-break:break-word;' +
            'font-size:' + note.font_size + 'px;color:' + sc + ';' +
            'box-shadow:0 2px 6px rgba(0,0,0,.15);white-space:pre-wrap;';
        box.textContent = note.content;

        makeDraggable(box, note.id, 'note');
        ol.appendChild(box);
    });
}

// ── Drag existing overlay items ────────────────────────────────────────────────
function makeDraggable(el, itemId, type) {
    var dragging  = false;
    var startPctX = 0, startPctY = 0;
    var startEvX  = 0, startEvY  = 0;

    el.style.pointerEvents = 'auto';

    el.addEventListener('pointerdown', function(e) {
        e.stopPropagation();
        dragging = true;
        el.setPointerCapture(e.pointerId);
        el.style.cursor = 'grabbing';
        el.style.opacity = '0.85';
        el.style.zIndex  = '200';

        var wrapper = document.getElementById('docWrapper');
        var rect    = wrapper.getBoundingClientRect();
        var curLeft = parseFloat(el.style.left);
        var curTop  = parseFloat(el.style.top);
        startPctX = curLeft;
        startPctY = curTop;
        startEvX  = (e.clientX - rect.left) / rect.width * 100;
        startEvY  = (e.clientY - rect.top)  / rect.height * 100;
    });

    el.addEventListener('pointermove', function(e) {
        if (!dragging) return;
        var wrapper = document.getElementById('docWrapper');
        var rect    = wrapper.getBoundingClientRect();
        var evX = (e.clientX - rect.left) / rect.width * 100;
        var evY = (e.clientY - rect.top)  / rect.height * 100;
        var newX = Math.min(100, Math.max(0, startPctX + (evX - startEvX)));
        var newY = Math.min(100, Math.max(0, startPctY + (evY - startEvY)));
        el.style.left = newX + '%';
        el.style.top  = newY + '%';
    });

    el.addEventListener('pointerup', function(e) {
        if (!dragging) return;
        dragging = false;
        el.releasePointerCapture(e.pointerId);
        el.style.cursor  = 'grab';
        el.style.opacity = '';
        el.style.zIndex  = '50';

        var x = parseFloat(el.style.left);
        var y = parseFloat(el.style.top);

        // Update local data
        if (type === 'signer') {
            positionsData.forEach(function(p) { if (p.id === itemId) { p.x = x; p.y = y; } });
        } else {
            notesData.forEach(function(n) { if (n.id === itemId) { n.x = x; n.y = y; } });
        }

        saveItemPosition(itemId, x, y, type);
    });

    // Prevent click from triggering doc placement
    el.addEventListener('click', function(e) { e.stopPropagation(); });
}

// ── AJAX position save ─────────────────────────────────────────────────────────
function saveItemPosition(id, x, y, type) {
    var fd = new FormData();
    fd.append('action',    type === 'signer' ? 'update_position' : 'update_note_position');
    fd.append(type === 'signer' ? 'pos_id' : 'note_id', id);
    fd.append('x_percent', x.toFixed(2));
    fd.append('y_percent', y.toFixed(2));
    fd.append('page_id',   PAGE_ID);
    fd.append('_csrf_token', CSRF_TOKEN);
    fetch(SAVE_URL, { method: 'POST', body: fd }).catch(function() {});
}

// ── PDF.js rendering ───────────────────────────────────────────────────────────
<?php if ($doc_type === 'pdf' && $doc_url): ?>
(function() {
    if (typeof pdfjsLib === 'undefined') return;
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    pdfjsLib.getDocument(DOC_URL).promise.then(function(pdf) {
        return pdf.getPage(1);
    }).then(function(page) {
        var wrapper = document.getElementById('docWrapper');
        var scale   = wrapper.clientWidth > 0 ? (wrapper.clientWidth / page.getViewport({scale:1}).width) : 1.5;
        var viewport = page.getViewport({ scale: scale });
        var canvas   = document.getElementById('pdfCanvas');
        var ctx      = canvas.getContext('2d');

        canvas.width  = viewport.width;
        canvas.height = viewport.height;
        canvas.style.width  = '100%';
        canvas.style.height = 'auto';

        var msg = document.getElementById('pdfLoadMsg');
        if (msg) msg.style.display = 'none';

        return page.render({ canvasContext: ctx, viewport: viewport }).promise;
    }).then(function() {
        initDocWrapper();
    }).catch(function(err) {
        var msg = document.getElementById('pdfLoadMsg');
        if (msg) {
            msg.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-2"></i>' +
                'Could not render PDF preview. You can still drag position markers using the canvas area above.';
        }
        initDocWrapper();
    });
})();
<?php elseif ($doc_type === 'image'): ?>
(function() {
    var img = document.getElementById('docImg');
    if (img && img.complete) {
        initDocWrapper();
    } else if (img) {
        img.addEventListener('load', initDocWrapper);
        img.addEventListener('error', initDocWrapper);
    }
})();
<?php else: ?>
window.addEventListener('DOMContentLoaded', initDocWrapper);
<?php endif; ?>

// ── Init ───────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    setMode('signer');

    // Update preview note box live when note text/color/size changes
    ['noteContent','noteColor','noteFontSize'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', function() {
            var noteX = parseFloat(document.getElementById('noteX').value);
            var noteY = parseFloat(document.getElementById('noteY').value);
            if (previewNote) showNotePreview(noteX, noteY);
        });
    });
});
</script>

<style>
@keyframes pulseDrop {
    from { opacity: 0; transform: translate(-50%,-80%); }
    to   { opacity: 1; transform: translate(-50%,-100%); }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

