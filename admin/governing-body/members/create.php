<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('governing-body', 'can_create');

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];
$page_type   = $_GET['page_type'] ?? $_POST['page_type'] ?? '';
if (!in_array($page_type, $valid_types, true)) {
    flash_set('error', 'Invalid page type.');
    redirect(APP_URL . '/governing-body/index.php');
}

// Load section title
$st = db()->prepare('SELECT title FROM governing_body_pages WHERE page_type = ? LIMIT 1');
$st->execute([$page_type]);
$pg_row        = $st->fetch();
$section_title = $pg_row ? $pg_row['title'] : ucwords(str_replace('-', ' ', $page_type));

$page_title = 'Add Member – ' . $section_title;
$errors     = [];

function gb_upload_photo(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed_mimes, true)) return false;
    $dir = UPLOAD_DIR . '/governing-body';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return false;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $full_name   = trim($_POST['full_name']   ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $department  = trim($_POST['department']  ?? '');
    $section     = trim($_POST['section']     ?? 'member');
    $bio         = trim($_POST['bio']         ?? '');
    $email       = trim($_POST['email']       ?? '');
    $phone       = trim($_POST['phone']       ?? '');
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $sort_order  = (int)($_POST['sort_order'] ?? 0);

    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($section   === '') $section  = 'member';

    $photo = null;
    if (!empty($_FILES['photo']['name'])) {
        $result = gb_upload_photo($_FILES['photo']);
        if ($result === false) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            $photo = $result;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO governing_body_members
             (page_type, section, full_name, designation, department, bio, photo, email, phone, is_featured, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $page_type, $section, $full_name,
            $designation ?: null, $department ?: null, $bio ?: null, $photo,
            $email ?: null, $phone ?: null, $is_featured, $sort_order,
        ]);

        flash_set('success', 'Member <strong>' . h($full_name) . '</strong> added.');
        redirect(APP_URL . '/governing-body/members/index.php?page_type=' . urlencode($page_type));
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/governing-body/index.php">Governing Body</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($page_type) ?>"><?= h($section_title) ?> – Members</a>
            </li>
            <li class="breadcrumb-item active">Add Member</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="page_type" value="<?= h($page_type) ?>">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Member Details</h6>
        </div>
        <div class="card-body p-4">

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= h($_POST['full_name'] ?? '') ?>" required maxlength="150"
                           placeholder="e.g. Prof. Dr. John Smith">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Designation / Role</label>
                    <input type="text" name="designation" class="form-control"
                           value="<?= h($_POST['designation'] ?? '') ?>" maxlength="150"
                           placeholder="e.g. Chairman">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="department" class="form-control"
                           value="<?= h($_POST['department'] ?? '') ?>" maxlength="150"
                           placeholder="e.g. Computer Science & Engineering">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Section</label>
                    <input type="text" name="section" class="form-control" list="section-suggestions"
                           value="<?= h($_POST['section'] ?? 'member') ?>" maxlength="100"
                           placeholder="e.g. member, chairman…">
                    <datalist id="section-suggestions">
                        <option value="Chairman">
                        <option value="member">
                        <option value="Ex-Officio Member">
                        <option value="Dean">
                        <option value="Head of Department">
                    </datalist>
                    <div class="form-text">Groups members under a section heading on the frontend.</div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Bio / Description</label>
                <textarea name="bio" class="form-control" rows="4"
                          placeholder="Short biography or role description."><?= h($_POST['bio'] ?? '') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($_POST['email'] ?? '') ?>" maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($_POST['phone'] ?? '') ?>" maxlength="30">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Recommended: square, min 300×300 px. JPG, PNG, GIF or WebP.</div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= h($_POST['sort_order'] ?? '0') ?>" min="0" style="width:120px;">
                    <div class="form-text">Lower numbers appear first.</div>
                </div>
                <div class="col-md-6 d-flex align-items-center" style="padding-top:28px;">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured"
                               value="1" <?= !empty($_POST['is_featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_featured">
                            Featured <span style="font-size:.8rem;color:#64748b;">(highlighted card, e.g. Chairman)</span>
                        </label>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Save Member
        </button>
        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($page_type) ?>"
           class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
