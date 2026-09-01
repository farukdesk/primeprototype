<?php
/**
 * ID Card – Settings
 *
 * • Registrar Signature: upload / change the signature shown on the front of
 *   the card. Position is AUTO-DETECTED from the card design (the Registrar
 *   line) by default; a manual X/Y/W/H mode is still available.
 * • Back Side: edit the university information printed on the back of the
 *   card (address, phone numbers, website, email, Facebook link).
 *
 * The uploaded signature file is stored VERSIONED (never overwritten) and
 * each card snapshots the signature that was current at CREATION time — an
 * updated signature is therefore distributed to NEWLY created IDs only;
 * cards that already exist keep the signature (or the original design
 * artwork) they were issued with.
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
    $cover    = isset($_POST['sig_cover']) ? '1' : '0';
    $pos_mode = (($_POST['sig_pos_mode'] ?? 'auto') === 'manual') ? 'manual' : 'auto';

    if ($pos_mode === 'manual' && ($w <= 0 || $h <= 0)) {
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
            idc_save_setting('signature_pos_mode', $pos_mode);

            // Back-side university information (address, phones, website, email, facebook)
            foreach (array_keys(IDC_BACK_FIELDS) as $bkey) {
                if (isset($_POST[$bkey])) {
                    idc_save_setting($bkey, trim((string)$_POST[$bkey]));
                }
            }

            if ($new_path !== null) {
                idc_save_setting('registrar_signature_path', $new_path);
                idc_save_setting('registrar_signature_updated_at', date('Y-m-d H:i:s'));
                flash_set('success', 'New Registrar signature saved. It will appear on ID cards created from now on — existing cards are NOT changed.');
            } else {
                flash_set('success', 'Settings saved.');
            }
            redirect(APP_URL . '/id-card/settings.php');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage() . ' (If the settings table is missing, run admin/id-card-signature-v1.sql first.)';
        }
    }
}

$sig_path   = idc_current_signature_path();
$box        = [
    'x'     => (float)idc_setting('signature_x', '20'),
    'y'     => (float)idc_setting('signature_y', '155'),
    'w'     => (float)idc_setting('signature_w', '80'),
    'h'     => (float)idc_setting('signature_h', '28'),
    'cover' => idc_setting('signature_cover', '1') === '1',
];
$pos_mode   = idc_setting('signature_pos_mode', 'auto');
$auto_box   = idc_detect_signature_box();
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
    Use a <strong>transparent PNG</strong> for the best result. The signature is scaled to fit the area
    while keeping its aspect ratio.
</div>

<div class="row justify-content-center"><div class="col-lg-8">
    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-signature me-2 text-muted"></i>Registrar Signature &amp; Back Side</h6>
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
                        <label class="form-label fw-medium">Signature Position</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="sig_pos_mode" id="sigPosAuto" value="auto" <?= $pos_mode !== 'manual' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sigPosAuto">
                                <strong>Automatic (recommended)</strong> — detect the Registrar signature line in the current card design and place the new signature exactly there
                                <?php if ($auto_box): ?>
                                <span class="text-muted small d-block">Detected position: x=<?= h((string)round($auto_box['x'], 1)) ?>, y=<?= h((string)round($auto_box['y'], 1)) ?>, width=<?= h((string)round($auto_box['w'], 1)) ?>, height=<?= h((string)round($auto_box['h'], 1)) ?></span>
                                <?php else: ?>
                                <span class="text-danger small d-block">Could not detect the signature line in the design — the manual values below will be used.</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="sig_pos_mode" id="sigPosManual" value="manual" <?= $pos_mode === 'manual' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sigPosManual">Manual — use the X / Y / Width / Height values below</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Signature Area on the Card <span class="text-muted small">(SVG units — the card is 331.2 wide × 212.16 high; used in Manual mode)</span></label>
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

                <hr class="my-4">
                <h6 class="fw-semibold mb-1"><i class="fas fa-address-card me-2 text-muted"></i>ID Card Back – University Information</h6>
                <div class="form-text mb-3">These values replace the static texts on the back of the ID card (address, phone numbers, website, email and Facebook link). They apply whenever a card is previewed or printed.</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Address line 1</label>
                        <input type="text" name="back_address1" class="form-control" value="<?= h(idc_back_field('back_address1')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Address line 2</label>
                        <input type="text" name="back_address2" class="form-control" value="<?= h(idc_back_field('back_address2')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Phone line 1</label>
                        <input type="text" name="back_phone1" class="form-control" value="<?= h(idc_back_field('back_phone1')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Phone line 2</label>
                        <input type="text" name="back_phone2" class="form-control" value="<?= h(idc_back_field('back_phone2')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Website</label>
                        <input type="text" name="back_website" class="form-control" value="<?= h(idc_back_field('back_website')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-medium">Email</label>
                        <input type="text" name="back_email" class="form-control" value="<?= h(idc_back_field('back_email')) ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-medium">Facebook link</label>
                        <input type="text" name="back_facebook" class="form-control" value="<?= h(idc_back_field('back_facebook')) ?>">
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
