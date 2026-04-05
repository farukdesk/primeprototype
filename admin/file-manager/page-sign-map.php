<?php
/**
 * Drag & drop signature placement for a Notes page.
 * Mirrors the notice-signing/map-signers.php pattern.
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

    if ($action === 'add_signer') {
        $user_id   = (int)($_POST['user_id']   ?? 0);
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 50)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 80)));

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
                    'INSERT INTO file_manager_page_sign_positions (page_id, user_id, x_percent, y_percent, sort_order)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE x_percent=VALUES(x_percent), y_percent=VALUES(y_percent)'
                )->execute([$page_id, $user_id, $x_percent, $y_percent, $sort_order]);

                // Notify signer if they don't already have a signature
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

    if ($action === 'remove_signer') {
        $pos_id = (int)($_POST['pos_id'] ?? 0);
        db()->prepare(
            'DELETE FROM file_manager_page_sign_positions WHERE id = ? AND page_id = ?'
        )->execute([$pos_id, $page_id]);
        flash_set('success', 'Signer removed.');
        redirect(APP_URL . '/file-manager/page-sign-map.php?page_id=' . $page_id);
    }

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
}

$all_users = db()->query(
    'SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name'
)->fetchAll();

$positions = fm_get_page_positions($page_id);

$doc_type = null;
if ($page['mime_type']) {
    $doc_type = str_starts_with($page['mime_type'], 'image/') ? 'image' : 'other';
    if ($page['mime_type'] === 'application/pdf') $doc_type = 'pdf';
}

require_once __DIR__ . '/../includes/header.php';
?>

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

<div class="row g-4">
    <!-- Document preview + click to place -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-map-marker-alt me-2 text-danger"></i>
                    <?= $doc_type === 'image'
                        ? 'Click on the document to place a signer\'s position'
                        : 'Document Preview (set positions manually)' ?>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if (!$page['uploaded_file']): ?>
                <div class="p-4 text-muted text-center">
                    <i class="fas fa-file me-2"></i>No file uploaded for this page.
                    Use the form to set positions manually.
                </div>
                <?php elseif ($doc_type === 'pdf'): ?>
                <div class="p-3 bg-light text-muted text-center" style="font-size:.85rem;">
                    <i class="fas fa-file-pdf text-danger me-2"></i>
                    PDF – use the form to set positions manually.
                </div>
                <iframe src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>"
                        style="width:100%;height:600px;border:none;"></iframe>
                <?php else: ?>
                <div id="imgWrapper" style="position:relative;cursor:crosshair;user-select:none;">
                    <img id="pageImg"
                         src="<?= UPLOAD_URL ?>/<?= FM_UPLOAD_SUBDIR ?>/<?= h($page['uploaded_file']) ?>"
                         alt="Page" style="width:100%;display:block;" onload="initOverlays()">
                    <div id="overlayLayer" style="position:absolute;top:0;left:0;width:100%;height:100%;"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right panel -->
    <div class="col-lg-4">
        <!-- Add signer form -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-user-plus me-2 text-primary"></i>Add / Update Signer</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="addSignerForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"  value="add_signer">
                    <input type="hidden" name="page_id" value="<?= $page_id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">User <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select" required>
                            <option value="">— Select user —</option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?> &lt;<?= h($u['email']) ?>&gt;</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">X (%)</label>
                            <input type="number" name="x_percent" id="xPercent" class="form-control"
                                   value="50" min="0" max="100" step="0.1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Y (%)</label>
                            <input type="number" name="y_percent" id="yPercent" class="form-control"
                                   value="80" min="0" max="100" step="0.1">
                        </div>
                    </div>
                    <?php if ($doc_type === 'image'): ?>
                    <div class="alert alert-info py-2 px-3 mb-3" style="border-radius:8px;font-size:.82rem;">
                        <i class="fas fa-mouse-pointer me-1"></i> Click on the document image to set position.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i> Add Signer
                    </button>
                </form>
            </div>
        </div>

        <!-- Current signers -->
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Signers</h6>
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
                                    onclick="return confirm('Remove <?= h(addslashes($pos['full_name'])) ?>?')">
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
    </div>
</div>

<script>
var positionsData = <?= json_encode(array_map(fn($p) => [
    'id'     => $p['id'],
    'user_id'=> $p['user_id'],
    'name'   => $p['full_name'],
    'x'      => (float)$p['x_percent'],
    'y'      => (float)$p['y_percent'],
    'signed' => !empty($p['sig_id']),
], $positions)) ?>;

var pageImg     = document.getElementById('pageImg');
var overlayLayer = document.getElementById('overlayLayer');

function initOverlays() {
    renderOverlays();
    if (pageImg) {
        pageImg.addEventListener('click', function(e) {
            var rect = pageImg.getBoundingClientRect();
            document.getElementById('xPercent').value = ((e.clientX - rect.left) / rect.width * 100).toFixed(1);
            document.getElementById('yPercent').value = ((e.clientY - rect.top)  / rect.height * 100).toFixed(1);
        });
    }
}

function renderOverlays() {
    if (!overlayLayer) return;
    overlayLayer.innerHTML = '';
    positionsData.forEach(function(pos) {
        var el = document.createElement('div');
        el.style.cssText = 'position:absolute;left:' + pos.x + '%;top:' + pos.y + '%;'
            + 'transform:translate(-50%,-50%);pointer-events:none;text-align:center;';
        el.innerHTML = '<div style="background:' + (pos.signed ? 'rgba(0,200,100,.25)' : 'rgba(255,150,0,.25)') + ';'
            + 'border:2px ' + (pos.signed ? 'solid #27ae60' : 'dashed #e67e22') + ';'
            + 'border-radius:6px;padding:4px 8px;font-size:10px;white-space:nowrap;'
            + 'color:' + (pos.signed ? '#065f46' : '#7d3c00') + ';">'
            + (pos.signed ? '✓ ' : '○ ') + pos.name + '</div>';
        overlayLayer.appendChild(el);
    });
}

if (pageImg && pageImg.complete) initOverlays();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
