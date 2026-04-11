<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('governing-body', 'can_edit');

$valid_types = ['board-of-trustees', 'pu-syndicates', 'deans', 'head-of-departments'];

$id        = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$page_type = $_GET['page_type'] ?? $_POST['page_type'] ?? '';

if (!$id || !in_array($page_type, $valid_types, true)) {
    flash_set('error', 'Invalid request.');
    redirect(APP_URL . '/governing-body/index.php');
}

$stmt = db()->prepare('SELECT * FROM governing_body_members WHERE id = ? AND page_type = ? LIMIT 1');
$stmt->execute([$id, $page_type]);
$member = $stmt->fetch();
if (!$member) {
    flash_set('error', 'Member not found.');
    redirect(APP_URL . '/governing-body/members/index.php?page_type=' . urlencode($page_type));
}

// Load section title
$st = db()->prepare('SELECT title FROM governing_body_pages WHERE page_type = ? LIMIT 1');
$st->execute([$page_type]);
$pg_row        = $st->fetch();
$section_title = $pg_row ? $pg_row['title'] : ucwords(str_replace('-', ' ', $page_type));

$page_title = 'Edit Member – ' . $member['full_name'];
$errors     = [];

function gb_edit_upload_photo(array $file): string|false
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

    $full_name      = trim($_POST['full_name']      ?? '');
    $designation    = trim($_POST['designation']    ?? '');
    $department     = trim($_POST['department']     ?? '');
    $section        = trim($_POST['section']        ?? 'member');
    $bio            = trim($_POST['bio']            ?? '');
    $email          = trim($_POST['email']          ?? '');
    $phone          = trim($_POST['phone']          ?? '');
    $is_featured    = isset($_POST['is_featured'])    ? 1 : 0;
    $sort_order     = (int)($_POST['sort_order']    ?? 0);
    $glance_officer = isset($_POST['glance_officer']) ? 1 : 0;
    $glance_msg_tab = trim($_POST['glance_msg_tab'] ?? '');
    $glance_message = trim($_POST['glance_message'] ?? '');

    if ($full_name === '') $errors[] = 'Full name is required.';
    if ($section   === '') $section  = 'member';

    $photo = $member['photo'];

    if (!empty($_FILES['photo']['name'])) {
        $result = gb_edit_upload_photo($_FILES['photo']);
        if ($result === false) {
            $errors[] = 'Photo: invalid file. Allowed: JPG, PNG, GIF, WebP.';
        } else {
            // Remove old photo
            if ($photo) {
                $old = UPLOAD_DIR . '/governing-body/' . $photo;
                if (is_file($old)) @unlink($old);
            }
            $photo = $result;
        }
    }

    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        if ($photo) {
            $old = UPLOAD_DIR . '/governing-body/' . $photo;
            if (is_file($old)) @unlink($old);
        }
        $photo = null;
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE governing_body_members
             SET section=?, full_name=?, designation=?, department=?, bio=?, photo=?,
                 email=?, phone=?, is_featured=?, sort_order=?,
                 glance_officer=?, glance_msg_tab=?, glance_message=?
             WHERE id=?'
        )->execute([
            $section, $full_name, $designation ?: null, $department ?: null,
            $bio ?: null, $photo, $email ?: null, $phone ?: null,
            $is_featured, $sort_order,
            $glance_officer, $glance_msg_tab ?: null, $glance_message ?: null,
            $id,
        ]);

        flash_set('success', 'Member <strong>' . h($full_name) . '</strong> updated.');
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
            <li class="breadcrumb-item active">Edit Member</li>
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
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="page_type" value="<?= h($page_type) ?>">
    <input type="hidden" name="remove_photo" id="remove_photo" value="0">

    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-user me-2 text-muted"></i>Member Details</h6>
        </div>
        <div class="card-body p-4">

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= h($_POST['full_name'] ?? $member['full_name']) ?>"
                           required maxlength="150">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-medium">Designation / Role</label>
                    <input type="text" name="designation" class="form-control"
                           value="<?= h($_POST['designation'] ?? $member['designation'] ?? '') ?>"
                           maxlength="150">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Department</label>
                    <input type="text" name="department" class="form-control"
                           value="<?= h($_POST['department'] ?? $member['department'] ?? '') ?>"
                           maxlength="150">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Section</label>
                    <input type="text" name="section" class="form-control" list="section-suggestions"
                           value="<?= h($_POST['section'] ?? $member['section']) ?>" maxlength="100">
                    <datalist id="section-suggestions">
                        <option value="Chairman">
                        <option value="member">
                        <option value="Ex-Officio Member">
                        <option value="Dean">
                        <option value="Head of Department">
                    </datalist>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Bio / Description</label>
                <textarea name="bio" class="form-control" rows="4"><?= h($_POST['bio'] ?? $member['bio'] ?? '') ?></textarea>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= h($_POST['email'] ?? $member['email'] ?? '') ?>" maxlength="191">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= h($_POST['phone'] ?? $member['phone'] ?? '') ?>" maxlength="30">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-medium">Photo</label>
                <?php if ($member['photo']): ?>
                <div class="mb-2 d-flex align-items-center gap-3" id="currentPhotoWrap">
                    <img src="<?= UPLOAD_URL ?>/governing-body/<?= h($member['photo']) ?>"
                         style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid #e8eaf0;" alt="">
                    <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:7px;"
                            onclick="document.getElementById('remove_photo').value='1';
                                     document.getElementById('currentPhotoWrap').style.display='none';">
                        <i class="fas fa-times me-1"></i>Remove photo
                    </button>
                </div>
                <?php endif; ?>
                <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <div class="form-text">Recommended: square, min 300×300 px. Leave blank to keep current.</div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="<?= h($_POST['sort_order'] ?? (string)$member['sort_order']) ?>"
                           min="0" style="width:120px;">
                    <div class="form-text">Lower numbers appear first.</div>
                </div>
                <div class="col-md-6 d-flex align-items-center" style="padding-top:28px;">
                    <?php $is_feat_checked = isset($_POST['is_featured']) ? (bool)$_POST['is_featured'] : (bool)$member['is_featured']; ?>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_featured" name="is_featured"
                               value="1" <?= $is_feat_checked ? 'checked' : '' ?>>
                        <label class="form-check-label fw-medium" for="is_featured">
                            Featured <span style="font-size:.8rem;color:#64748b;">(highlighted card, e.g. Chairman)</span>
                        </label>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php if ($page_type === 'board-of-trustees'): ?>
    <?php
    $glance_officer_val  = isset($_POST['glance_officer'])  ? (bool)$_POST['glance_officer']  : (bool)($member['glance_officer'] ?? 0);
    $glance_msg_tab_val  = $_POST['glance_msg_tab']  ?? ($member['glance_msg_tab']  ?? '');
    $glance_message_val  = $_POST['glance_message']  ?? ($member['glance_message']  ?? '');
    ?>
    <div class="card mb-4" style="border-radius:12px;border:2px solid #e0e7ef;">
        <div class="card-header py-3 px-4" style="background:rgba(0,33,71,.04);">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-eye me-2" style="color:#002147;"></i>PU At a Glance – Page Appearance</h6>
            <div class="form-text mt-1">Choose how this member appears on the <em>PU At a Glance</em> public page.</div>
        </div>
        <div class="card-body p-4">
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" id="glance_officer" name="glance_officer"
                       value="1" <?= $glance_officer_val ? 'checked' : '' ?>>
                <label class="form-check-label fw-medium" for="glance_officer">
                    Show as <strong>Key Administrative Officer</strong>
                    <span style="font-size:.8rem;color:#64748b;display:block;">Adds this person to the "Key Administrative Officers" section on PU At a Glance.</span>
                </label>
            </div>
            <hr>
            <div class="mb-3">
                <label class="form-label fw-medium">Words from Our Leadership – Tab Label</label>
                <input type="text" name="glance_msg_tab" class="form-control" maxlength="120"
                       value="<?= h($glance_msg_tab_val) ?>"
                       placeholder="e.g. Message from Chairman">
                <div class="form-text">If filled, this person will appear as a tab in the "Words from Our Leadership" section. Leave blank to exclude.</div>
            </div>
            <div class="mb-1">
                <label class="form-label fw-medium">Leadership Message Body</label>
                <textarea name="glance_message" class="form-control" rows="6"
                          placeholder="Type the message here. Separate paragraphs with a blank line."><?= h($glance_message_val) ?></textarea>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
            <i class="fas fa-save me-1"></i> Update Member
        </button>
        <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=<?= urlencode($page_type) ?>"
           class="btn btn-light" style="border-radius:10px;">Cancel</a>
    </div>
</form>
</div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
