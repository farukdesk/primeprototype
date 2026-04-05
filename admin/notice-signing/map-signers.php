<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('notice-signing', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { flash_set('error', 'Invalid notice.'); redirect(APP_URL . '/notice-signing/index.php'); }

$stmt = db()->prepare('SELECT * FROM notice_documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { flash_set('error', 'Notice not found.'); redirect(APP_URL . '/notice-signing/index.php'); }

if ($doc['status'] === 'completed') {
    flash_set('error', 'Cannot edit a completed notice.');
    redirect(APP_URL . '/notice-signing/view.php?id=' . $id);
}

$page_title = 'Manage Signers – ' . $doc['title'];
$errors     = [];

// ── Handle POST: save signer positions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_signer') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $page_num  = max(1, (int)($_POST['page_num'] ?? 1));
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 50)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 80)));

        if ($user_id < 1) { $errors[] = 'Please select a user.'; }
        else {
            // Check user exists
            $u_stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
            $u_stmt->execute([$user_id]);
            if (!$u_stmt->fetchColumn()) {
                $errors[] = 'Selected user not found or inactive.';
            } else {
                // Upsert
                $order_stmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM notice_sign_positions WHERE document_id = ?');
                $order_stmt->execute([$id]);
                $sort_order = (int)$order_stmt->fetchColumn();

                db()->prepare(
                    'INSERT INTO notice_sign_positions (document_id, user_id, page_num, x_percent, y_percent, sort_order)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE page_num=VALUES(page_num), x_percent=VALUES(x_percent), y_percent=VALUES(y_percent)'
                )->execute([$id, $user_id, $page_num, $x_percent, $y_percent, $sort_order]);

                flash_set('success', 'Signer added/updated.');
                redirect(APP_URL . '/notice-signing/map-signers.php?id=' . $id);
            }
        }
    }

    if ($action === 'remove_signer') {
        $pos_id = (int)($_POST['pos_id'] ?? 0);
        db()->prepare('DELETE FROM notice_sign_positions WHERE id = ? AND document_id = ?')->execute([$pos_id, $id]);
        flash_set('success', 'Signer removed.');
        redirect(APP_URL . '/notice-signing/map-signers.php?id=' . $id);
    }

    if ($action === 'update_position') {
        $pos_id    = (int)($_POST['pos_id']    ?? 0);
        $x_percent = min(100, max(0, (float)($_POST['x_percent'] ?? 0)));
        $y_percent = min(100, max(0, (float)($_POST['y_percent'] ?? 0)));
        db()->prepare('UPDATE notice_sign_positions SET x_percent=?, y_percent=? WHERE id=? AND document_id=?')
            ->execute([$x_percent, $y_percent, $pos_id, $id]);
        // JSON response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

// All users (active)
$all_users = db()->query("SELECT id, full_name, email FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();

// Current signers
$positions = ns_get_positions($id);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/notice-signing/index.php">Notice Signing</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/notice-signing/view.php?id=<?= $id ?>"><?= h($doc['title']) ?></a></li>
            <li class="breadcrumb-item active">Manage Signers</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/notice-signing/view.php?id=<?= $id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Notice
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Document + visual mapper -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-map-marker-alt me-2 text-danger"></i>
                    <?php if ($doc['document_type'] === 'image'): ?>
                    Click on the document to place a signer's signature position
                    <?php else: ?>
                    Document Preview
                    <?php endif; ?>
                </h6>
            </div>
            <div class="card-body p-0">
                <?php if ($doc['document_type'] === 'pdf'): ?>
                <div class="p-3 bg-light text-muted text-center" style="font-size:.85rem;">
                    <i class="fas fa-file-pdf text-danger me-2"></i>
                    PDF document – set positions manually using the form on the right.
                </div>
                <iframe src="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>"
                        style="width:100%;height:600px;border:none;"></iframe>
                <?php else: ?>
                <div id="imgWrapper" style="position:relative;cursor:crosshair;user-select:none;">
                    <img id="noticeImg"
                         src="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>"
                         alt="Notice" style="width:100%;display:block;" onload="initOverlays()">
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
                    <input type="hidden" name="action" value="add_signer">
                    <div class="mb-3">
                        <label class="form-label fw-medium">User <span class="text-danger">*</span></label>
                        <select name="user_id" id="signerUser" class="form-select" required>
                            <option value="">— Select user —</option>
                            <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= h($u['full_name']) ?> <<?= h($u['email']) ?>></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Page Number</label>
                        <input type="number" name="page_num" id="pageNum" class="form-control" value="1" min="1">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-medium">X Position (%)</label>
                            <input type="number" name="x_percent" id="xPercent" class="form-control" value="50" min="0" max="100" step="0.1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-medium">Y Position (%)</label>
                            <input type="number" name="y_percent" id="yPercent" class="form-control" value="80" min="0" max="100" step="0.1">
                        </div>
                    </div>
                    <?php if ($doc['document_type'] === 'image'): ?>
                    <div class="alert alert-info py-2 px-3 mb-3" style="border-radius:8px;font-size:.82rem;">
                        <i class="fas fa-mouse-pointer me-1"></i> Click on the document image to set the X/Y position automatically.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i> Add Signer
                    </button>
                </form>
            </div>
        </div>

        <!-- Current signers list -->
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Current Signers</h6>
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
                                Page <?= $pos['page_num'] ?> · <?= round($pos['x_percent'], 1) ?>%, <?= round($pos['y_percent'], 1) ?>%
                            </div>
                            <?php if ($pos['sig_id']): ?>
                            <span class="badge bg-success bg-opacity-10 text-success mt-1">
                                <i class="fas fa-check me-1"></i>Signed
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
                            <input type="hidden" name="action" value="remove_signer">
                            <input type="hidden" name="pos_id" value="<?= $pos['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:6px;"
                                    onclick="return confirm('Remove <?= h(addslashes($pos['full_name'])) ?> as a signer?')">
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
    'id' => $p['id'], 'user_id' => $p['user_id'], 'name' => $p['full_name'],
    'x' => (float)$p['x_percent'], 'y' => (float)$p['y_percent'],
    'page' => $p['page_num'], 'signed' => !empty($p['sig_id']),
], $positions)) ?>;

var noticeImg = document.getElementById('noticeImg');
var overlayLayer = document.getElementById('overlayLayer');

function initOverlays() {
    renderOverlays();
    if (noticeImg) {
        noticeImg.addEventListener('click', function(e) {
            var rect = noticeImg.getBoundingClientRect();
            var x = ((e.clientX - rect.left) / rect.width * 100).toFixed(1);
            var y = ((e.clientY - rect.top)  / rect.height * 100).toFixed(1);
            document.getElementById('xPercent').value = x;
            document.getElementById('yPercent').value = y;
        });
    }
}

function renderOverlays() {
    if (!overlayLayer) return;
    overlayLayer.innerHTML = '';
    positionsData.forEach(function(pos) {
        var el = document.createElement('div');
        el.style.position  = 'absolute';
        el.style.left      = pos.x + '%';
        el.style.top       = pos.y + '%';
        el.style.transform = 'translate(-50%, -50%)';
        el.style.pointerEvents = 'none';
        el.style.textAlign = 'center';
        el.innerHTML = '<div style="background:' + (pos.signed ? 'rgba(0,200,100,.25)' : 'rgba(255,150,0,.25)') + ';'
            + 'border:2px ' + (pos.signed ? 'solid #27ae60' : 'dashed #e67e22') + ';'
            + 'border-radius:6px;padding:4px 8px;font-size:10px;white-space:nowrap;'
            + 'color:' + (pos.signed ? '#065f46' : '#7d3c00') + ';">'
            + (pos.signed ? '✓ ' : '○ ') + pos.name + '</div>';
        overlayLayer.appendChild(el);
    });
}

// If image already loaded before JS ran
if (noticeImg && noticeImg.complete) initOverlays();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
