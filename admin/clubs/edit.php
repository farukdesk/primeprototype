<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_edit');

$db     = db();
$id     = (int)($_GET['id'] ?? 0);
$club   = $db->prepare('SELECT * FROM clubs WHERE id = ?')->execute([$id]) ? null : null;
$stmt   = $db->prepare('SELECT * FROM clubs WHERE id = ?');
$stmt->execute([$id]);
$club   = $stmt->fetch();
if (!$club) { flash_set('error', 'Club not found.'); redirect(APP_URL . '/clubs/index.php'); }

$page_title = 'Edit Club – ' . $club['name'];
$errors     = [];

$depts = $db->query("SELECT id, name FROM dept_departments ORDER BY name")->fetchAll();
$progs = $db->query("SELECT id, dept_id, name FROM dept_academic_programs ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name       = trim($_POST['name']        ?? '');
    $dept_id    = (int)($_POST['dept_id']    ?? 0) ?: null;
    $program_id = (int)($_POST['program_id'] ?? 0) ?: null;
    $goal       = trim($_POST['goal']        ?? '');
    $facilities = trim($_POST['facilities']  ?? '');
    $notice     = trim($_POST['notice']      ?? '');
    $is_active  = isset($_POST['is_active'])  ? 1 : 0;

    if ($name === '') $errors[] = 'Club name is required.';

    $cover_photo = $club['cover_photo'];
    $logo        = $club['logo'];

    if (empty($errors) && !empty($_FILES['cover_photo']['name'])) {
        try {
            clubs_delete_image(CLUB_UPLOAD_COVERS, $club['cover_photo']);
            $cover_photo = clubs_upload_image($_FILES['cover_photo'], CLUB_UPLOAD_COVERS);
        } catch (RuntimeException $e) { $errors[] = 'Cover photo: ' . $e->getMessage(); }
    }
    if (empty($errors) && !empty($_FILES['logo']['name'])) {
        try {
            clubs_delete_image(CLUB_UPLOAD_LOGOS, $club['logo']);
            $logo = clubs_upload_image($_FILES['logo'], CLUB_UPLOAD_LOGOS);
        } catch (RuntimeException $e) { $errors[] = 'Logo: ' . $e->getMessage(); }
    }

    // Remove cover/logo if checkbox ticked
    if (isset($_POST['remove_cover']) && $club['cover_photo']) {
        clubs_delete_image(CLUB_UPLOAD_COVERS, $club['cover_photo']);
        $cover_photo = null;
    }
    if (isset($_POST['remove_logo']) && $club['logo']) {
        clubs_delete_image(CLUB_UPLOAD_LOGOS, $club['logo']);
        $logo = null;
    }

    if (empty($errors)) {
        $slug = unique_club_slug(clubs_slug($name), $id);

        $db->prepare(
            'UPDATE clubs SET dept_id=?, program_id=?, name=?, slug=?, goal=?, facilities=?, notice=?, cover_photo=?, logo=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$dept_id, $program_id, $name, $slug, $goal ?: null, $facilities ?: null, $notice ?: null, $cover_photo, $logo, $is_active, $id]);

        log_change('clubs', 'UPDATE', $id, $name, null, null, null, "Club '$name' updated.");

        flash_set('success', 'Club updated successfully.');
        redirect(APP_URL . '/clubs/view.php?id=' . $id);
    }

    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-edit me-2 text-primary"></i>Edit Club</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/index.php">Clubs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/view.php?id=<?= $id ?>"><?= h($club['name']) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/clubs/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?= flash_show() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3"><i class="fas fa-info-circle me-2"></i>Club Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= h(old('name', $club['name'])) ?>" required maxlength="200">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department</label>
                            <select name="dept_id" id="dept_id" class="form-select">
                                <option value="">— Select Department —</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (old('dept_id', $club['dept_id'])) == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Academic Program</label>
                            <select name="program_id" id="program_id" class="form-select">
                                <option value="">— Select Program —</option>
                                <?php foreach ($progs as $p): ?>
                                <option value="<?= $p['id'] ?>" data-dept="<?= $p['dept_id'] ?>" <?= (old('program_id', $club['program_id'])) == $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Goal</label>
                        <textarea name="goal" class="form-control" rows="4"><?= h(old('goal', $club['goal'])) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Facilities</label>
                        <textarea name="facilities" class="form-control" rows="3"><?= h(old('facilities', $club['facilities'])) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Club Notice</label>
                        <textarea name="notice" class="form-control" rows="3"><?= h(old('notice', $club['notice'])) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-semibold py-3">Settings</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" <?= old('is_active', $club['is_active']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_active">Active Club</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Logo</label>
                        <?php if ($club['logo']): ?>
                        <div class="mb-2"><img src="<?= CLUB_URL_LOGOS ?>/<?= h($club['logo']) ?>" alt="Logo" class="rounded-circle" style="width:60px;height:60px;object-fit:cover;"></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_logo" id="remove_logo"><label class="form-check-label small text-danger" for="remove_logo">Remove logo</label></div>
                        <?php endif; ?>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">JPG/PNG/WebP ≤ 5MB</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cover Photo</label>
                        <?php if ($club['cover_photo']): ?>
                        <div class="mb-2"><img src="<?= CLUB_URL_COVERS ?>/<?= h($club['cover_photo']) ?>" alt="Cover" class="img-fluid rounded" style="max-height:100px;"></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_cover" id="remove_cover"><label class="form-check-label small text-danger" for="remove_cover">Remove cover photo</label></div>
                        <?php endif; ?>
                        <input type="file" name="cover_photo" class="form-control" accept="image/*">
                        <div class="form-text">JPG/PNG/WebP ≤ 5MB</div>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i>Save Changes</button>
                <a href="<?= APP_URL ?>/clubs/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<script>
const deptSel    = document.getElementById('dept_id');
const progSel    = document.getElementById('program_id');
const allOptions = Array.from(progSel.options).map(o => ({val:o.value, text:o.text, dept:o.dataset.dept, sel:o.selected}));
function filterPrograms() {
    const deptVal = deptSel.value;
    const cur = progSel.value;
    progSel.innerHTML = '<option value="">— Select Program —</option>';
    allOptions.filter(o => o.val === '' || !deptVal || o.dept === deptVal).forEach(o => {
        if (!o.val) return;
        const opt = document.createElement('option');
        opt.value = o.val; opt.text = o.text; opt.dataset.dept = o.dept;
        if (o.val === cur || o.sel) opt.selected = true;
        progSel.appendChild(opt);
    });
}
deptSel.addEventListener('change', filterPrograms);
filterPrograms();
</script>

<?php clear_old(); require_once __DIR__ . '/../includes/footer.php'; ?>
