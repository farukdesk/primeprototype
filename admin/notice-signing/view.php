<?php
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

$user      = auth_user();
$positions = ns_get_positions($id);
$pending   = ns_pending_count($id);
$can_sign  = ns_needs_to_sign($id, $user['id']) && $doc['status'] === 'active';

// Check if current user has a signature image
$sig_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
$sig_stmt->execute([$user['id']]);
$my_sig = $sig_stmt->fetchColumn();

$page_title = h($doc['title']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/notice-signing/index.php">Notice Signing</a></li>
            <li class="breadcrumb-item active"><?= h($doc['title']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($doc['status'] === 'completed'): ?>
        <a href="<?= APP_URL ?>/notice-signing/print.php?id=<?= $id ?>" target="_blank" class="btn btn-success" style="border-radius:10px;">
            <i class="fas fa-print me-1"></i> Print / Download
        </a>
        <?php endif; ?>
        <?php if (ns_can_edit() && $doc['status'] !== 'completed'): ?>
        <a href="<?= APP_URL ?>/notice-signing/map-signers.php?id=<?= $id ?>" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-users-cog me-1"></i> Manage Signers
        </a>
        <?php endif; ?>
        <?php if ($can_sign): ?>
        <button type="button" class="btn btn-primary" style="border-radius:10px;" onclick="openSignModal()">
            <i class="fas fa-pen-nib me-1"></i> Sign This Notice
        </button>
        <?php endif; ?>
        <?php if (ns_can_delete()): ?>
        <button type="button" class="btn btn-outline-danger" style="border-radius:10px;"
                onclick="new bootstrap.Modal(document.getElementById('deleteModal')).show()">
            <i class="fas fa-trash me-1"></i> Delete
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Status banner -->
<?php if ($doc['status'] === 'completed'): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-4" style="border-radius:12px;">
    <i class="fas fa-check-circle fa-lg"></i>
    <div>All signatures collected. Completed on <strong><?= date('d M Y, g:i A', strtotime($doc['completed_at'])) ?></strong>.</div>
</div>
<?php elseif ($doc['status'] === 'draft'): ?>
<div class="alert alert-secondary d-flex align-items-center gap-2 mb-4" style="border-radius:12px;">
    <i class="fas fa-pencil-ruler fa-lg"></i>
    <div>This notice is a <strong>draft</strong>. Signers cannot sign until it is set to <em>Active</em>.</div>
</div>
<?php elseif ($can_sign && !$my_sig): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4" style="border-radius:12px;">
    <i class="fas fa-exclamation-triangle fa-lg"></i>
    <div>You need to sign this notice but you haven't uploaded a signature image yet.
        <a href="<?= APP_URL ?>/my-signature/index.php" class="alert-link">Upload your signature →</a>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Document viewer -->
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;overflow:hidden;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file me-2 text-muted"></i>Document</h6>
            </div>
            <div class="card-body p-0" id="docContainer" style="position:relative;">
                <?php if ($doc['document_type'] === 'pdf'): ?>
                <iframe src="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>"
                        style="width:100%;height:700px;border:none;"></iframe>
                <?php else: ?>
                <div style="position:relative;display:inline-block;width:100%;">
                    <img id="noticeImg" src="<?= UPLOAD_URL ?>/<?= NS_UPLOAD_SUBDIR ?>/<?= h($doc['document_file']) ?>"
                         alt="Notice Document" style="width:100%;display:block;" onload="renderSignatureOverlays()">
                    <!-- Signature overlays rendered by JS -->
                    <div id="sigOverlays"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Signature trail for PDF (shown below) -->
        <?php if ($doc['document_type'] === 'pdf' && !empty($positions)): ?>
        <div class="card mt-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-signature me-2 text-muted"></i>Signature Placements</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <?php foreach ($positions as $pos): ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 border rounded-3 text-center <?= $pos['sig_id'] ? 'border-success bg-success bg-opacity-5' : 'border-secondary' ?>">
                            <?php if ($pos['sig_id']): ?>
                            <?php
                                $signer_stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
                                $signer_stmt->execute([$pos['user_id']]);
                                $signer_sig = $signer_stmt->fetchColumn();
                            ?>
                            <?php if ($signer_sig): ?>
                            <img src="<?= UPLOAD_URL ?>/<?= NS_SIG_SUBDIR ?>/<?= h($signer_sig) ?>"
                                 alt="Signature" style="max-height:60px;max-width:100%;object-fit:contain;">
                            <?php endif; ?>
                            <div class="text-success fw-medium mt-1" style="font-size:.82rem;"><i class="fas fa-check me-1"></i><?= h($pos['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= date('d M Y g:i A', strtotime($pos['signed_at'])) ?></div>
                            <?php else: ?>
                            <div style="height:40px;display:flex;align-items:center;justify-content:center;">
                                <span class="text-muted"><i class="fas fa-clock me-1"></i>Pending</span>
                            </div>
                            <div class="fw-medium mt-1" style="font-size:.82rem;"><?= h($pos['full_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;">Page <?= $pos['page_num'] ?> · <?= $pos['x_percent'] ?>%, <?= $pos['y_percent'] ?>%</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar info -->
    <div class="col-lg-4">

        <!-- Notice info -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-info-circle me-2 text-muted"></i>Details</h6>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Status</span>
                    <?= ns_status_badge($doc['status']) ?>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Type</span>
                    <span style="font-size:.85rem"><?= strtoupper($doc['document_type']) ?></span>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Created by</span>
                    <span style="font-size:.85rem"><?= h($doc['creator_name']) ?></span>
                </li>
                <li class="list-group-item px-4 py-3 d-flex justify-content-between">
                    <span class="text-muted" style="font-size:.85rem">Created</span>
                    <span style="font-size:.85rem"><?= date('d M Y', strtotime($doc['created_at'])) ?></span>
                </li>
            </ul>
            <?php if ($doc['description']): ?>
            <div class="card-body pt-0 px-4 pb-3">
                <p class="mb-0 text-muted" style="font-size:.85rem"><?= nl2br(h($doc['description'])) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Signer status -->
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-users me-2 text-muted"></i>Signers</h6>
                <?php if (!empty($positions)): ?>
                <span class="badge <?= $pending === 0 ? 'bg-success' : 'bg-warning text-dark' ?>">
                    <?= $pending === 0 ? 'All signed' : "$pending pending" ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if (empty($positions)): ?>
            <div class="card-body px-4 py-3 text-muted" style="font-size:.85rem;">
                No signers mapped yet.
                <?php if (ns_can_edit()): ?>
                <a href="<?= APP_URL ?>/notice-signing/map-signers.php?id=<?= $id ?>">Add signers →</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush" style="border-radius:0 0 12px 12px;">
                <?php foreach ($positions as $pos): ?>
                <li class="list-group-item px-4 py-3 d-flex align-items-center gap-3">
                    <div style="width:36px;height:36px;border-radius:50%;background:<?= $pos['sig_id'] ? '#d1fae5' : '#f1f5f9' ?>;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;color:<?= $pos['sig_id'] ? '#065f46' : '#64748b' ?>;">
                        <?= $pos['sig_id'] ? '<i class="fas fa-check"></i>' : strtoupper(substr($pos['full_name'], 0, 1)) ?>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-medium" style="font-size:.87rem;"><?= h($pos['full_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">
                            <?php if ($pos['sig_id']): ?>
                            <span class="text-success"><i class="fas fa-check me-1"></i>Signed <?= date('d M Y', strtotime($pos['signed_at'])) ?></span>
                            <?php else: ?>
                            <span><i class="fas fa-clock me-1"></i>Pending · Page <?= $pos['page_num'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Status control for admins -->
        <?php if (ns_can_edit() && $doc['status'] !== 'completed'): ?>
        <div class="card mt-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-cog me-2 text-muted"></i>Status Control</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= APP_URL ?>/notice-signing/sign.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="mb-3">
                        <select name="new_status" class="form-select">
                            <option value="draft"  <?= $doc['status'] === 'draft'  ? 'selected' : '' ?>>Draft</option>
                            <option value="active" <?= $doc['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-save me-1"></i> Update Status
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sign modal -->
<?php if ($can_sign && $my_sig): ?>
<div class="modal fade" id="signModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title"><i class="fas fa-pen-nib me-2 text-primary"></i>Confirm Signature</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted mb-3">Your signature will be applied to this notice.</p>
                <div class="p-4 bg-light rounded-3 d-inline-block mb-3">
                    <img src="<?= UPLOAD_URL ?>/<?= NS_SIG_SUBDIR ?>/<?= h($my_sig) ?>"
                         alt="My Signature" style="max-height:80px;max-width:300px;object-fit:contain;">
                </div>
                <p class="mb-0" style="font-size:.85rem;">Signing as <strong><?= h($user['full_name']) ?></strong> on <?= date('d F Y') ?></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/notice-signing/sign.php" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="sign">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;">
                        <i class="fas fa-pen-nib me-1"></i> Apply Signature
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete modal -->
<?php if (ns_can_delete()): ?>
<form id="deleteForm" method="POST" action="<?= APP_URL ?>/notice-signing/delete.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
</form>
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title">Delete Notice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Delete <strong><?= h($doc['title']) ?></strong>? All signatures and positions will be removed.</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" style="border-radius:8px;"
                        onclick="document.getElementById('deleteForm').submit()">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openSignModal() {
    new bootstrap.Modal(document.getElementById('signModal')).show();
}

// Render signature overlays on image documents
function renderSignatureOverlays() {
    var container = document.getElementById('sigOverlays');
    if (!container) return;
    var img = document.getElementById('noticeImg');
    if (!img) return;

    var positions = <?php
        $js_positions = array_map(function($p) {
            $sig_file = null;
            if ($p['sig_id']) {
                $s = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
                $s->execute([$p['user_id']]);
                $sig_file = $s->fetchColumn() ?: null;
            }
            return [
                'x'        => (float)$p['x_percent'],
                'y'        => (float)$p['y_percent'],
                'name'     => $p['full_name'],
                'signed'   => !empty($p['sig_id']),
                'sig_file' => $sig_file,
            ];
        }, $positions);
        echo json_encode($js_positions);
    ?>;

    container.style.position = 'absolute';
    container.style.top      = '0';
    container.style.left     = '0';
    container.style.width    = '100%';
    container.style.height   = '100%';
    container.style.pointerEvents = 'none';

    positions.forEach(function(pos) {
        var el = document.createElement('div');
        el.style.position  = 'absolute';
        el.style.left      = pos.x + '%';
        el.style.top       = pos.y + '%';
        el.style.transform = 'translate(-50%, -50%)';
        el.style.textAlign = 'center';

        if (pos.signed && pos.sig_file) {
            el.innerHTML = '<img src="<?= UPLOAD_URL ?>/<?= NS_SIG_SUBDIR ?>/' + pos.sig_file + '" '
                + 'style="max-height:50px;max-width:120px;object-fit:contain;background:rgba(255,255,255,.7);border-radius:4px;padding:2px;">'
                + '<div style="font-size:9px;background:rgba(255,255,255,.8);border-radius:3px;padding:1px 4px;margin-top:2px;">' + pos.name + '</div>';
        } else if (pos.signed) {
            el.innerHTML = '<div style="background:rgba(0,200,100,.2);border:1.5px solid #27ae60;border-radius:6px;padding:4px 8px;font-size:10px;color:#065f46;">'
                + '<i class="fas fa-check"></i> ' + pos.name + '</div>';
        } else {
            el.innerHTML = '<div style="background:rgba(255,200,0,.25);border:1.5px dashed #e67e22;border-radius:6px;padding:4px 8px;font-size:10px;color:#7d3c00;">'
                + '<i class="fas fa-clock"></i> ' + pos.name + '</div>';
        }
        container.appendChild(el);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
