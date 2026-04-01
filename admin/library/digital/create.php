<?php
require_once __DIR__ . '/../../../includes/auth.php';
auth_check();
require_access('library-digital');
require_once __DIR__ . '/../helpers.php';

if (!lib_can_create()) {
    flash_set('error', 'You do not have permission to upload resources.');
    redirect(APP_URL . '/library/digital/index.php');
}

$db     = db();
$errors = [];
clear_old();

// ── POST processing ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']        ?? '');
    $author       = trim($_POST['author']       ?? '');
    $publisher    = trim($_POST['publisher']    ?? '');
    $pub_year     = (int)($_POST['pub_year']    ?? 0);
    $resource_type = trim($_POST['resource_type'] ?? '');
    $category_id  = (int)($_POST['category_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $access_level = trim($_POST['access_level'] ?? 'Public');
    $description  = trim($_POST['description']  ?? '');
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    $valid_types = ['E-Book','Journal','Research Paper','Thesis','Dissertation','Other'];
    $valid_access = ['Public','Students','Faculty','Staff','Admin'];

    if ($title === '') $errors[] = 'Title is required.';
    if (!in_array($resource_type, $valid_types, true)) $errors[] = 'Invalid resource type.';
    if (!in_array($access_level, $valid_access, true)) $errors[] = 'Invalid access level.';
    if (empty($_FILES['resource_file']['name'])) $errors[] = 'Resource file is required.';

    // Upload digital file
    $file_info = null;
    if (empty($errors) && !empty($_FILES['resource_file']['name'])) {
        try {
            $file_info = lib_upload_digital($_FILES['resource_file']);
        } catch (RuntimeException $e) {
            $errors[] = 'File upload: ' . $e->getMessage();
        }
    }

    // Upload cover image
    $cover_image = null;
    if (!empty($_FILES['cover_image']['name'])) {
        try {
            $cover_image = lib_upload_cover($_FILES['cover_image']);
        } catch (RuntimeException $e) {
            $errors[] = 'Cover image: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $file_info) {
        $user = auth_user();
        $db->prepare(
            'INSERT INTO library_digital_resources
             (title, resource_type, author, publisher, pub_year, category_id, department_id,
              description, file_name, original_name, mime_type, file_size, access_level,
              cover_image, download_count, is_active, uploaded_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,NOW(),NOW())'
        )->execute([
            $title,
            $resource_type,
            $author       ?: null,
            $publisher    ?: null,
            $pub_year     ?: null,
            $category_id  ?: null,
            $department_id ?: null,
            $description  ?: null,
            $file_info['stored'],
            $file_info['original'],
            $file_info['mime'],
            $file_info['size'],
            $access_level,
            $cover_image,
            $is_active,
            $user['id'] ?? 0,
        ]);
        $new_id = (int)$db->lastInsertId();
        lib_audit('DIGITAL_CREATED', 'digital', $new_id, $title, "Uploaded {$resource_type}: {$title}.");
        log_change('library-digital', 'CREATE', $new_id, $title, null, null, null,
            "Uploaded digital resource \"{$title}\" ({$resource_type}).");
        flash_set('success', "Resource <strong>" . h($title) . "</strong> uploaded successfully.");
        redirect(APP_URL . '/library/digital/index.php');
    }

    save_old(compact('title','author','publisher','pub_year','resource_type','category_id',
                     'department_id','access_level','description','is_active'));
}

$categories  = lib_all_categories();
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$cur_year    = (int)date('Y');

$page_title  = 'Upload Digital Resource';
$breadcrumbs = [
    ['label' => 'Library', 'url' => APP_URL . '/library/index.php'],
    ['label' => 'Digital Resources', 'url' => APP_URL . '/library/digital/index.php'],
    ['label' => 'Upload'],
];
require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/digital/index.php">Digital Resources</a></li>
            <li class="breadcrumb-item active">Upload</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/library/digital/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-circle-exclamation me-2"></i><strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-1 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <!-- Main Details -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-circle-info me-2 text-muted"></i>Resource Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= h(old('title')) ?>" placeholder="Full title of the resource" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Author</label>
                            <input type="text" name="author" class="form-control" value="<?= h(old('author')) ?>" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Publisher</label>
                            <input type="text" name="publisher" class="form-control" value="<?= h(old('publisher')) ?>" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Publication Year</label>
                            <input type="number" name="pub_year" class="form-control" min="1900" max="<?= $cur_year ?>"
                                   value="<?= h(old('pub_year')) ?>" placeholder="e.g. 2023" style="border-radius:10px;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Resource Type <span class="text-danger">*</span></label>
                            <select name="resource_type" class="form-select" required style="border-radius:10px;">
                                <option value="">— Select Type —</option>
                                <?php foreach (['E-Book','Journal','Research Paper','Thesis','Dissertation','Other'] as $t): ?>
                                <option value="<?= $t ?>" <?= old('resource_type')===$t?'selected':'' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Category</label>
                            <select name="category_id" class="form-select" style="border-radius:10px;">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= old('category_id')==$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="department_id" class="form-select" style="border-radius:10px;">
                                <option value="">— All Departments —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= old('department_id')==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Access Level <span class="text-danger">*</span></label>
                            <select name="access_level" class="form-select" required style="border-radius:10px;">
                                <?php foreach (['Public','Students','Faculty','Staff','Admin'] as $al): ?>
                                <option value="<?= $al ?>" <?= old('access_level','Public')===$al?'selected':'' ?>><?= $al ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Controls who can download this resource.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Description</label>
                            <textarea name="description" class="form-control" rows="4" style="border-radius:10px;"
                                      placeholder="Brief description of the resource"><?= h(old('description')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- File Upload -->
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-upload me-2 text-muted"></i>Files</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Resource File <span class="text-danger">*</span></label>
                            <input type="file" name="resource_file" class="form-control" required
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.epub,.mobi,.txt" style="border-radius:10px;">
                            <div class="form-text">Accepted: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ZIP, EPUB, MOBI, TXT. Max 100 MB.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= old('is_active','1')?'checked':'' ?>>
                                <label class="form-check-label fw-medium" for="is_active">Active (visible to users)</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar: Cover Image -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Cover Image</h6>
                </div>
                <div class="card-body p-4">
                    <div id="cover_preview" class="mb-3 text-center" style="display:none;">
                        <img id="cover_img" src="" alt="Cover Preview"
                             style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #dee2e6;">
                    </div>
                    <input type="file" name="cover_image" id="cover_input" class="form-control"
                           accept=".jpg,.jpeg,.png,.gif,.webp" style="border-radius:10px;">
                    <div class="form-text">Optional. JPG/PNG/GIF/WEBP, max 5 MB.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-3">
        <button type="submit" class="btn btn-primary px-4" style="border-radius:10px;">
            <i class="fas fa-upload me-2"></i> Upload Resource
        </button>
        <a href="<?= APP_URL ?>/library/digital/index.php" class="btn btn-outline-secondary px-4" style="border-radius:10px;">Cancel</a>
    </div>
</form>

<script>
document.getElementById('cover_input').addEventListener('change', function() {
    const file = this.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('cover_img').src = e.target.result;
            document.getElementById('cover_preview').style.display = '';
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php
clear_old();
require_once __DIR__ . '/../../../includes/footer.php';
?>
