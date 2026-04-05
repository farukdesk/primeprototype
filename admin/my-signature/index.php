<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/../notice-signing/helpers.php';

$page_title = 'My Signature';
$user       = auth_user();
$errors     = [];

// Fetch current signature
$stmt = db()->prepare('SELECT signature_file FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$current_sig = $stmt->fetchColumn() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'upload';

    if ($action === 'remove') {
        if ($current_sig) {
            ns_delete_signature($current_sig);
            db()->prepare('UPDATE users SET signature_file = NULL WHERE id = ?')->execute([$user['id']]);
            flash_set('success', 'Signature removed.');
            redirect(APP_URL . '/my-signature/index.php');
        }
    }

    if ($action === 'upload' || empty($action)) {
        if (empty($_FILES['signature_file']['name'])) {
            $errors[] = 'Please select an image file.';
        } else {
            $result = ns_upload_signature($_FILES['signature_file']);
            if ($result === false) {
                $errors[] = 'Invalid file. Please upload a PNG or JPG image, max 2 MB. A transparent PNG is recommended.';
            } else {
                // Remove old signature
                if ($current_sig) ns_delete_signature($current_sig);

                db()->prepare('UPDATE users SET signature_file = ? WHERE id = ?')->execute([$result, $user['id']]);
                log_change('my-signature', 'UPDATE', $user['id'], $user['full_name']);
                flash_set('success', 'Signature uploaded successfully.');
                redirect(APP_URL . '/my-signature/index.php');
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">My Signature</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-6">

        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-signature me-2 text-primary"></i>My Signature Image</h6>
            </div>
            <div class="card-body p-4">
                <?php if ($current_sig): ?>
                <div class="text-center mb-4">
                    <div class="p-4 bg-light rounded-3 d-inline-block" style="min-width:280px;">
                        <img src="<?= UPLOAD_URL ?>/<?= NS_SIG_SUBDIR ?>/<?= h($current_sig) ?>"
                             alt="My Signature" style="max-height:100px;max-width:100%;object-fit:contain;">
                    </div>
                    <p class="text-muted mt-2 mb-0" style="font-size:.82rem;">Current signature</p>
                </div>
                <?php else: ?>
                <div class="text-center py-4 mb-3">
                    <div style="width:80px;height:80px;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                        <i class="fas fa-pen-nib text-muted fa-2x"></i>
                    </div>
                    <p class="text-muted mb-0">No signature uploaded yet.</p>
                </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload">
                    <div class="mb-3">
                        <label class="form-label fw-medium"><?= $current_sig ? 'Replace Signature' : 'Upload Signature' ?></label>
                        <input type="file" name="signature_file" class="form-control"
                               accept=".png,.jpg,.jpeg"
                               onchange="previewSig(this)" required>
                        <div class="form-text mt-1">
                            PNG or JPG, max 2 MB. <strong>Tip:</strong> Use a transparent-background PNG for the best look when overlaid on documents.
                        </div>
                    </div>
                    <div id="sigPreviewWrap" style="display:none;" class="text-center mb-3">
                        <div class="p-4 bg-light rounded-3 d-inline-block">
                            <img id="sigPreview" src="" alt="Preview" style="max-height:80px;max-width:100%;object-fit:contain;">
                        </div>
                        <p class="text-muted mt-1 mb-0" style="font-size:.8rem;">Preview</p>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-upload me-1"></i> <?= $current_sig ? 'Replace Signature' : 'Upload Signature' ?>
                        </button>
                    </div>
                </form>

                <?php if ($current_sig): ?>
                <hr>
                <form method="POST" onsubmit="return confirm('Remove your signature image?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <button type="submit" class="btn btn-outline-danger w-100" style="border-radius:10px;">
                        <i class="fas fa-trash me-1"></i> Remove Signature
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="border-radius:12px;">
            <div class="card-body p-4">
                <h6 class="fw-semibold mb-3"><i class="fas fa-info-circle me-2 text-muted"></i>How it works</h6>
                <ol class="mb-0" style="font-size:.87rem;color:#666;padding-left:1.2rem;">
                    <li class="mb-2">Upload a clear image of your signature here.</li>
                    <li class="mb-2">When an admin creates a notice and assigns you as a signer, you will see a notification on the Notice Signing page.</li>
                    <li class="mb-2">Open the notice, review it, and click <strong>Sign This Notice</strong>.</li>
                    <li class="mb-2">Your signature image will be placed at the mapped position on the document.</li>
                    <li>Once all required signers have signed, the notice is marked as <strong>Completed</strong> and can be printed.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function previewSig(input) {
    var wrap    = document.getElementById('sigPreviewWrap');
    var preview = document.getElementById('sigPreview');
    var file    = input.files[0];
    if (!file) { wrap.style.display = 'none'; return; }
    var reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        wrap.style.display = '';
    };
    reader.readAsDataURL(file);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
