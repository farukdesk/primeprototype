<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('gallery', 'can_edit');

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$album = $id ? $db->prepare('SELECT * FROM gallery_albums WHERE id = ?') : null;
if ($album) { $album->execute([$id]); $album = $album->fetch(); }
if (!$album) {
    flash_set('error', 'Album not found.');
    redirect(APP_URL . '/gallery/index.php');
}

$page_title = 'Edit Album';
$errors     = [];

$depts = $db->query("SELECT id, name FROM dept_departments ORDER BY name")->fetchAll();
$progs = $db->query("SELECT id, dept_id, program_name FROM dept_academic_programs ORDER BY program_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $dept_id     = (int)($_POST['dept_id']    ?? 0) ?: null;
    $program_id  = (int)($_POST['program_id'] ?? 0) ?: null;
    $event_date  = trim($_POST['event_date']  ?? '') ?: null;
    $sort_order  = (int)($_POST['sort_order'] ?? 0);
    $is_active   = isset($_POST['is_active'])  ? 1 : 0;

    if ($title === '') $errors[] = 'Album title is required.';

    $cover_photo = $album['cover_photo'];
    if (empty($errors) && !empty($_FILES['cover_photo']['name'])) {
        try {
            $new_cover = gallery_upload_image($_FILES['cover_photo'], GAL_UPLOAD_COVERS);
            gallery_delete_image(GAL_UPLOAD_COVERS, $cover_photo);
            $cover_photo = $new_cover;
        } catch (RuntimeException $e) {
            $errors[] = 'Cover photo: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $db->prepare(
            'UPDATE gallery_albums SET dept_id=?, program_id=?, title=?, description=?, event_date=?, cover_photo=?, sort_order=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$dept_id, $program_id, $title, $description ?: null, $event_date, $cover_photo, $sort_order, $is_active, $id]);

        log_change('gallery', 'UPDATE', $id, $title, null, null, null, "Album '$title' updated.");
        flash_set('success', 'Album <strong>' . h($title) . '</strong> updated.');
        redirect(APP_URL . '/gallery/view.php?id=' . $id);
    }

    save_old($_POST);
    $album = array_merge($album, $_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-edit me-2 text-primary"></i>Edit Album</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/index.php">Gallery</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/gallery/view.php?id=<?= $id ?>"><?= h($album['title']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/gallery/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3">
                    <i class="fas fa-info-circle me-2"></i>Album Information
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Album Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= h($album['title']) ?>" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="4"><?= h($album['description']) ?></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department</label>
                            <select name="dept_id" id="dept_id" class="form-select">
                                <option value="">— University-wide —</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $album['dept_id'] == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Academic Program</label>
                            <select name="program_id" id="program_id" class="form-select">
                                <option value="">— Select Program —</option>
                                <?php foreach ($progs as $p): ?>
                                <option value="<?= $p['id'] ?>" data-dept="<?= $p['dept_id'] ?>" <?= $album['program_id'] == $p['id'] ? 'selected' : '' ?>><?= h($p['program_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Event Date</label>
                        <input type="date" name="event_date" class="form-control" value="<?= h($album['event_date']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-semibold py-3">Settings</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                               <?= $album['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active (visible on public page)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= (int)$album['sort_order'] ?>" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cover Photo <small class="text-muted">(leave blank to keep current)</small></label>
                        <?php if ($album['cover_photo']): ?>
                        <div class="mb-2">
                            <img src="<?= GAL_URL_COVERS ?>/<?= h($album['cover_photo']) ?>" alt="" class="img-thumbnail" style="max-height:100px;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="cover_photo" class="form-control" accept="image/*">
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i>Save Changes</button>
                <a href="<?= APP_URL ?>/gallery/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
const deptSel    = document.getElementById('dept_id');
const progSel    = document.getElementById('program_id');
const allOptions = Array.from(progSel.options).map(o => ({val:o.value, text:o.text, dept:o.dataset.dept||''}));

function filterPrograms() {
    const deptVal  = deptSel.value;
    const selected = progSel.value;
    progSel.innerHTML = '<option value="">— Select Program —</option>';
    allOptions.filter(o => !o.val || !deptVal || o.dept === deptVal).forEach(o => {
        if (!o.val) return;
        const opt = document.createElement('option');
        opt.value = o.val; opt.text = o.text; opt.dataset.dept = o.dept;
        if (o.val === selected) opt.selected = true;
        progSel.appendChild(opt);
    });
}
deptSel.addEventListener('change', filterPrograms);
filterPrograms();
</script>

<?php clear_old(); require_once __DIR__ . '/../includes/footer.php'; ?>
