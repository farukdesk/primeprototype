<?php
/**
 * ID Card – Settings (Registrar Signature)
 *
 * Upload / change the Registrar's signature shown on the front of the card
 * and tune the signature area so it fits the SVG design.
 *
 * The uploaded file is stored VERSIONED (never overwritten) and each card
 * snapshots the signature that was current at CREATION time — an updated
 * signature is therefore distributed to NEWLY created IDs only; cards that
 * already exist keep the signature (or the original design artwork) they
 * were issued with.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'ID Card Settings';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $x = (float)($_POST['sig_x'] ?? 20);
    $y = (float)($_POST['sig_y'] ?? 155);
    $w = (float)($_POST['sig_w'] ?? 80);
    $h = (float)($_POST['sig_h'] ?? 28);
    $cover = isset($_POST['sig_cover']) ? '1' : '0';

    if ($w <= 0 || $h <= 0) {
        $errors[] = 'Signature area width and height must be greater than zero.';
    }

    if (!$errors) {
        try {
            $new_path = null;
            if (($_FILES['signature']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $new_path = idc_store_signature($_FILES['signature']);
            }

            idc_save_setting('signature_x', (string)$x);
            idc_save_setting('signature_y', (string)$y);
            idc_save_setting('signature_w', (string)$w);
            idc_save_setting('signature_h', (string)$h);
            idc_save_setting('signature_cover', $cover);

            if ($new_path !== null) {
                idc_save_setting('registrar_signature_path', $new_path);
                idc_save_setting('registrar_signature_updated_at', date('Y-m-d H:i:s'));
                flash_set('success', 'New Registrar signature saved. It will appear on ID cards created from now on — existing cards are NOT changed.');
            } else {
                flash_set('success', 'Signature area settings saved.');
            }
            redirect(APP_URL . '/id-card/settings.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage() . ' (If the settings table is missing, run admin/id-card-signature-v1.sql first.)';
        }
    }
}

$sig_path   = idc_current_signature_path();
$box        = idc_signature_box();
$updated_at = idc_setting('registrar_signature_updated_at', '');
$sig_url    = $sig_path !== '' ? APP_URL . '/' . $sig_path : '';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/id-card/index.php">ID Cards</a></li>
            <li class="breadcrumb-item active">Settings</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-light border small">
    <i class="fas fa-info-circle text-primary me-1"></i>
    <strong>How the Registrar signature works:</strong> every ID card snapshots the signature that is current
    at the moment the card is <strong>created</strong>. Uploading a new signature here therefore only affects
    <strong>newly created</strong> cards — cards that already exist keep the signature (or the original design
    artwork) they were issued with. Signature files are stored versioned and never overwritten.
    <br><i class="fas fa-lightbulb text-warning me-1"></i>
    Use a <strong>transparent PNG</strong> for the best result. The signature is scaled to fit the area below
    while keeping its aspect ratio.
</div>

<div class="row justify-content-center"><div class="col-lg-8">
    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-signature me-2 text-muted"></i>Registrar Signature</h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Current Signature</label>
                        <div>
                            <?php if ($sig_url !== ''): ?>
                            <div style="width:220px;padding:10px;border:1px solid #dee2e6;border-radius:6px;background:repeating-conic-gradient(#f1f3f5 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;">
                                <img src="<?= h($sig_url) ?>" alt="Registrar signature" style="max-width:100%;max-height:80px;display:block;margin:0 auto">
                            </div>
                            <?php if ($updated_at !== ''): ?>
                            <div class="text-muted mt-1" style="font-size:.72rem">Updated: <?= h($updated_at) ?></div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted"
                                 style="width:220px;height:80px;border:1px dashed #ccc;border-radius:6px;font-size:.8rem">
                                No signature uploaded yet —<br>cards use the original design
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-medium">Upload New Signature</label>
                        <input type="file" name="signature" class="form-control" accept=".png,.jpg,.jpeg,.webp">
                        <div class="form-text">PNG (transparent recommended), JPG or WEBP, max 1 MB. Applies to newly created cards only.</div>
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Signature Area on the Card <span class="text-muted small">(SVG units — the card is 331.2 wide × 212.16 high)</span></label>
                        <div class="form-text mb-2">The signature is placed inside this box, scaled to fit and bottom-aligned so it sits on the Registrar line. Adjust and re-print a test card until it matches the design.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">X (from left)</label>
                        <input type="number" step="0.1" name="sig_x" class="form-control" value="<?= h((string)$box['x']) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">Y (from top)</label>
                        <input type="number" step="0.1" name="sig_y" class="form-control" value="<?= h((string)$box['y']) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">Width</label>
                        <input type="number" step="0.1" name="sig_w" class="form-control" value="<?= h((string)$box['w']) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-medium">Height</label>
                        <input type="number" step="0.1" name="sig_h" class="form-control" value="<?= h((string)$box['h']) ?>">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="sig_cover" value="1"
                                   id="sigCover" <?= $box['cover'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sigCover">
                                Cover the area with white first <span class="text-muted small">(hides the signature baked into the design artwork)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?= APP_URL ?>/id-card/index.php" class="btn btn-outline-secondary">Back</a>
                    <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div></div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
