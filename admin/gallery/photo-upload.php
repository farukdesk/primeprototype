<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery', 'can_edit');

$db       = db();
$album_id = (int)($_GET['album_id'] ?? 0);

$st = $db->prepare('SELECT id, title FROM gallery_albums WHERE id = ?');
$st->execute([$album_id]);
$album = $st->fetch();

if (!$album) {
    flash_set('error', 'Album not found.');
    redirect(APP_URL . '/gallery/index.php');
}

$page_title = 'Upload Photos – ' . h($album['title']);
$errors     = [];
$uploaded   = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $captions = $_POST['captions'] ?? [];
    $files    = $_FILES['photos'] ?? null;

    if (empty($files) || empty($files['name'][0])) {
        $errors[] = 'Please select at least one photo.';
    }

    if (empty($errors)) {
        $user = auth_user();
        // Determine the initial status: super admin / edit perm => approved, else pending
        $initial_status = gallery_can_edit() ? 'approved' : 'pending';
        $max_sort = (int)$db->prepare('SELECT COALESCE(MAX(sort_order),0) FROM gallery_photos WHERE album_id = ?')
                              ->execute([$album_id]) ? $db->query("SELECT COALESCE(MAX(sort_order),0) FROM gallery_photos WHERE album_id = $album_id")->fetchColumn() : 0;

        $count = count($files['name']);
        $sort  = (int)$max_sort + 1;

        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i])) continue;
            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            try {
                $stored  = gallery_upload_image($file, GAL_UPLOAD_PHOTOS);
                $caption = trim($captions[$i] ?? '');

                $db->prepare(
                    'INSERT INTO gallery_photos (album_id, stored_name, original_name, caption, sort_order, status, uploaded_by)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$album_id, $stored, $files['name'][$i], $caption ?: null, $sort++, $initial_status, $user['id']]);

                $uploaded++;
            } catch (RuntimeException $e) {
                $errors[] = h($files['name'][$i]) . ': ' . $e->getMessage();
            }
        }

        if ($uploaded > 0) {
            log_change('gallery', 'UPDATE', $album_id, $album['title'], null, null, null, "$uploaded photo(s) uploaded.");
            $msg = "$uploaded photo(s) uploaded successfully.";
            if ($initial_status === 'pending') {
                $msg .= ' They are pending admin approval.';
            }
            flash_set('success', $msg);
            redirect(APP_URL . '/gallery/view.php?id=' . $album_id);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-upload me-2 text-success"></i>Upload Photos</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/index.php">Gallery</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/view.php?id=<?= $album_id ?>"><?= h($album['title']) ?></a></li>
            <li class="breadcrumb-item active">Upload Photos</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/gallery/view.php?id=<?= $album_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-header bg-success text-white fw-semibold py-3">
        <i class="fas fa-cloud-upload-alt me-2"></i>Upload to: <?= h($album['title']) ?>
    </div>
    <div class="card-body">
        <p class="text-muted small">You can select multiple photos at once. Each file must be JPG, PNG, GIF or WebP, max 8 MB.
        <?php if (!gallery_can_edit()): ?>
        <strong class="text-warning">Note: Uploaded photos require admin approval before appearing on the public gallery.</strong>
        <?php endif; ?>
        </p>

        <form method="post" enctype="multipart/form-data" novalidate id="uploadForm">
            <?= csrf_field() ?>
            <div class="mb-4">
                <label class="form-label fw-semibold">Select Photos <span class="text-danger">*</span></label>
                <input type="file" name="photos[]" id="photosInput" class="form-control" accept="image/*" multiple required>
                <div class="form-text">Hold Ctrl/Cmd to select multiple files.</div>
            </div>

            <!-- Dynamic caption fields -->
            <div id="captionArea"></div>

            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-upload me-2"></i>Upload</button>
        </form>
    </div>
</div>

<script>
document.getElementById('photosInput').addEventListener('change', function() {
    const area = document.getElementById('captionArea');
    area.innerHTML = '';
    const files = Array.from(this.files);
    if (files.length === 0) return;

    const heading = document.createElement('h6');
    heading.className = 'fw-semibold mb-3 mt-3';
    heading.textContent = 'Add Captions (optional)';
    area.appendChild(heading);

    files.forEach((file, i) => {
        const row = document.createElement('div');
        row.className = 'mb-3 d-flex align-items-center gap-3';

        const preview = document.createElement('img');
        preview.style.cssText = 'width:64px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;';
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; };
        reader.readAsDataURL(file);

        const wrap = document.createElement('div');
        wrap.style.flexGrow = '1';
        wrap.innerHTML = `<div class="small text-muted mb-1">${file.name}</div>
            <input type="text" name="captions[]" class="form-control form-control-sm" placeholder="Caption for this photo…" maxlength="500">`;

        row.appendChild(preview);
        row.appendChild(wrap);
        area.appendChild(row);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
