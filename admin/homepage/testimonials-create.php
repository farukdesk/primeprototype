<?php
require_once __DIR__ . '/helpers.php';
auth_check();
require_access('homepage', 'can_edit');

$page_title = 'Add Testimonial';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name        = trim($_POST['name']        ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $quote       = trim($_POST['quote']       ?? '');
    $rating      = max(1, min(5, (int)($_POST['rating'] ?? 5)));
    $sort_order  = (int)($_POST['sort_order']  ?? 0);
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name  === '') $errors[] = 'Name is required.';
    if ($quote === '') $errors[] = 'Quote/testimonial text is required.';

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $result = hp_upload_photo($_FILES['photo']);
        if ($result === false) {
            $errors[] = 'Photo: unsupported format. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $photo = $result;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO homepage_testimonials (name, designation, quote, photo, rating, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $designation ?: null, $quote, $photo, $rating, $sort_order, $is_active]);

        flash_set('success', 'Testimonial added successfully.');
        redirect(APP_URL . '/homepage/index.php');
    }

    save_old(compact('name', 'designation', 'quote', 'rating', 'sort_order'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/homepage/index.php">Homepage</a></li>
            <li class="breadcrumb-item active">Add Testimonial</li>
        </ol>
    </nav>
</div>

<div class="row">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-quote-left me-2 text-muted"></i>New Testimonial</h6>
    </div>
    <div class="card-body p-4">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3 mb-3">
                <div class="col">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" style="border-radius:10px;"
                           placeholder="e.g. John Smith"
                           value="<?= h(old('name', '')) ?>" required>
                </div>
                <div class="col">
                    <label class="form-label fw-medium">Designation / Program</label>
                    <input type="text" name="designation" class="form-control" style="border-radius:10px;"
                           placeholder="e.g. BBA Graduate, Batch 2023"
                           value="<?= h(old('designation', '')) ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Quote / Testimonial <span class="text-danger">*</span></label>
                <textarea name="quote" class="form-control" style="border-radius:10px;resize:vertical;" rows="4"
                          placeholder="Write the testimonial text here…" required><?= h(old('quote', '')) ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label fw-medium">Star Rating</label>
                    <select name="rating" class="form-select" style="border-radius:10px;">
                        <?php for ($r = 5; $r >= 1; $r--): ?>
                        <option value="<?= $r ?>" <?= (int)old('rating', 5) === $r ? 'selected' : '' ?>>
                            <?= $r ?> Star<?= $r > 1 ? 's' : '' ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-3">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" style="border-radius:10px;"
                           value="<?= (int)old('sort_order', 0) ?>" min="0">
                </div>
                <div class="col-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active"
                               id="is_active" <?= old('is_active', '1') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-medium">Photo <span class="text-muted">(optional)</span></label>
                <input type="file" name="photo" class="form-control" style="border-radius:10px;"
                       accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Square crop recommended. Max 2MB. Formats: JPG, PNG, GIF, WebP.</div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Testimonial
                </button>
                <a href="<?= APP_URL ?>/homepage/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
