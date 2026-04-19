<?php
/**
 * Student Photos – Bulk ZIP Upload
 *
 * Accepts a ZIP file of student photos.
 * Each image file name (without extension) must be the student ID,
 * e.g. "25010101.jpg".  Sub-folder names are ignored.
 *
 * Supported image formats: JPG, JPEG, PNG, GIF, WEBP
 * Maximum ZIP size: 200 GB (configured in admin/.htaccess)
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if (!sm_can_create()) {
    flash_set('error', 'You do not have permission to upload photos.');
    redirect(APP_URL . '/students/index.php');
}

if (!class_exists('ZipArchive')) {
    flash_set('error', 'PHP ZipArchive extension is not available on this server.');
    redirect(APP_URL . '/students/index.php');
}

$page_title = 'Bulk Student Photo Upload';
$user       = auth_user();

// ── Results ────────────────────────────────────────────────────────────────────
$results       = null;   // null = not yet run
$assigned      = [];     // photos newly assigned (student had no photo before)
$overwritten   = [];     // photos replaced (student had an existing photo)
$skipped_dup   = [];     // student already has a photo and overwrite=false
$skipped_nostu = [];     // no matching student found for this filename
$errors        = [];

// ── Processing ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

    if (empty($_FILES['zip_file']['name']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose a ZIP file to upload.');
    } else {
        $tmp_zip  = $_FILES['zip_file']['tmp_name'];
        $zip_mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_zip);
        $zip_ext  = strtolower(pathinfo($_FILES['zip_file']['name'], PATHINFO_EXTENSION));

        $valid_zip_mimes = [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
            'multipart/x-zip',
        ];

        if ($zip_ext !== 'zip' || !in_array($zip_mime, $valid_zip_mimes, true)) {
            flash_set('error', 'Only ZIP files are accepted.');
        } else {
            $zip = new ZipArchive();
            if ($zip->open($tmp_zip) !== true) {
                flash_set('error', 'Could not open the ZIP file. It may be corrupt.');
            } else {
                // ── Pre-load all students to avoid N+1 queries ─────────────────
                $all_students_stmt = db()->query(
                    "SELECT id, student_id, full_name, photo FROM students"
                );
                $student_map = [];  // student_id (lowercase) => row
                foreach ($all_students_stmt->fetchAll() as $row) {
                    $student_map[strtolower(trim($row['student_id']))] = $row;
                }

                $dest_dir = UPLOAD_DIR . '/students/photos';
                if (!is_dir($dest_dir)) {
                    mkdir($dest_dir, 0755, true);
                }

                $finfo       = new finfo(FILEINFO_MIME_TYPE);
                $update_stmt = db()->prepare(
                    "UPDATE students SET photo = ? WHERE id = ?"
                );

                // ── Walk every entry in the ZIP ────────────────────────────────
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);

                    // Skip directory entries
                    if (substr($entry, -1) === '/') continue;

                    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                    if (!in_array($ext, SM_PHOTO_EXTS, true)) continue;

                    $original_name  = basename($entry);
                    $student_id_raw = pathinfo($original_name, PATHINFO_FILENAME);
                    $sid_key        = strtolower(trim($student_id_raw));

                    if ($sid_key === '') {
                        $errors[] = ['path' => $entry, 'reason' => 'Could not derive a student ID from the filename.'];
                        continue;
                    }

                    // ── Match student ──────────────────────────────────────────
                    if (!isset($student_map[$sid_key])) {
                        $skipped_nostu[] = [
                            'path'       => $entry,
                            'student_id' => $student_id_raw,
                        ];
                        continue;
                    }

                    $student = $student_map[$sid_key];
                    $stu_pk  = (int)$student['id'];

                    // ── Duplicate / overwrite logic ────────────────────────────
                    if ($student['photo'] && !$overwrite) {
                        $skipped_dup[] = [
                            'path'       => $entry,
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                        continue;
                    }

                    // ── Read image content from ZIP ────────────────────────────
                    $raw_content = $zip->getFromIndex($i);
                    if ($raw_content === false) {
                        $errors[] = ['path' => $entry, 'reason' => 'Could not read file from ZIP.'];
                        continue;
                    }

                    // ── Validate MIME type via magic bytes ─────────────────────
                    $mime = $finfo->buffer($raw_content);
                    if (!in_array($mime, SM_PHOTO_MIMES, true)) {
                        $errors[] = ['path' => $entry, 'reason' => 'File does not appear to be a valid image (detected MIME: ' . $mime . ').'];
                        continue;
                    }

                    // ── Save new photo to disk ─────────────────────────────────
                    $stored_name = bin2hex(random_bytes(12)) . '.' . $ext;
                    $dest_path   = $dest_dir . '/' . $stored_name;

                    if (file_put_contents($dest_path, $raw_content) === false) {
                        $errors[] = ['path' => $entry, 'reason' => 'Failed to write file to disk.'];
                        continue;
                    }

                    // ── Remove old photo file from disk (if replacing) ─────────
                    $old_photo = $student['photo'];
                    if ($old_photo) {
                        $old_path = $dest_dir . '/' . $old_photo;
                        if (is_file($old_path)) {
                            @unlink($old_path);
                        }
                    }

                    // ── Update database ────────────────────────────────────────
                    $update_stmt->execute([$stored_name, $stu_pk]);

                    // Update local map so the same student_id isn't double-processed
                    $student_map[$sid_key]['photo'] = $stored_name;

                    if ($old_photo) {
                        $overwritten[] = [
                            'path'       => $entry,
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                    } else {
                        $assigned[] = [
                            'path'       => $entry,
                            'student_id' => $student['student_id'],
                            'name'       => $student['full_name'],
                        ];
                    }
                }

                $zip->close();
                $results = true;
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Bulk Photo Upload</li>
        </ol>
    </nav>
</div>

<?php if ($results === true): ?>
<!-- ── Results ─────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#28a745,#1e7e34);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($assigned) ?></div>
                    <div class="stat-lbl">Assigned</div>
                </div>
                <i class="fas fa-check-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#fd7e14,#c96a0d);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($overwritten) ?></div>
                    <div class="stat-lbl">Replaced</div>
                </div>
                <i class="fas fa-sync-alt" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#17a2b8,#117a8b);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($skipped_dup) + count($skipped_nostu) ?></div>
                    <div class="stat-lbl">Skipped</div>
                </div>
                <i class="fas fa-forward" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#b02a37);">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-val"><?= count($errors) ?></div>
                    <div class="stat-lbl">Errors</div>
                </div>
                <i class="fas fa-times-circle" style="font-size:2rem;opacity:.4"></i>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($assigned)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white py-2">
        <i class="fas fa-check-circle me-1"></i> Photos Assigned (<?= count($assigned) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID</th><th>Student Name</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($assigned as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($overwritten)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header text-white py-2" style="background:#fd7e14;">
        <i class="fas fa-sync-alt me-1"></i> Photos Replaced (<?= count($overwritten) ?>)
        <small class="d-block mt-1" style="font-weight:400">These students had an existing photo which was overwritten with the new file.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID</th><th>Student Name</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($overwritten as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($skipped_dup)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white py-2">
        <i class="fas fa-forward me-1"></i> Already Has Photo – Skipped (<?= count($skipped_dup) ?>)
        <small class="d-block mt-1" style="font-weight:400">These students already have a photo on file. Enable "Replace existing photos" and re-upload to overwrite them.</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Student ID</th><th>Student Name</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($skipped_dup as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td><?= h($r['name']) ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($skipped_nostu)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-secondary text-white py-2">
        <i class="fas fa-user-times me-1"></i> Student Not Found – Skipped (<?= count($skipped_nostu) ?>)
        <small class="d-block mt-1" style="font-weight:400">No student record matched the filename. Ensure the file is named exactly after the student ID (e.g. <code>25010101.jpg</code>).</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>Filename (Student ID)</th><th>File in ZIP</th></tr></thead>
                <tbody>
                <?php foreach ($skipped_nostu as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td><code><?= h($r['student_id']) ?></code></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-danger text-white py-2">
        <i class="fas fa-times-circle me-1"></i> Errors (<?= count($errors) ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light"><tr><th>#</th><th>File in ZIP</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($errors as $k => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $k + 1 ?></td>
                        <td class="text-muted small"><?= h($r['path']) ?></td>
                        <td class="text-danger"><?= h($r['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
    <a href="<?= APP_URL ?>/students/bulk-photo-upload.php" class="btn btn-outline-primary">
        <i class="fas fa-upload me-1"></i> Upload Another ZIP
    </a>
    <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Students
    </a>
</div>

<?php else: ?>
<!-- ── Upload form ─────────────────────────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-12 col-lg-8">

        <div class="card shadow-sm mb-4">
            <div class="card-header py-3">
                <h5 class="mb-0"><i class="fas fa-images me-2 text-primary"></i>Bulk Upload Student Photos via ZIP</h5>
            </div>
            <div class="card-body">

                <div class="alert alert-info" role="alert">
                    <strong><i class="fas fa-info-circle me-1"></i> How it works:</strong>
                    <ul class="mb-0 mt-2 small">
                        <li>Create a ZIP file containing student photos.</li>
                        <li>Name each photo after the student's ID (without any other text), e.g. <code>25010101.jpg</code> or <code>25010101.png</code>.</li>
                        <li>Sub-folder names inside the ZIP are ignored — only the filename matters.</li>
                        <li>Supported formats: <strong>JPG, JPEG, PNG, GIF, WEBP</strong>.</li>
                        <li>If a student already has a photo, it will be <strong>skipped</strong> unless you enable "Replace existing photos" below.</li>
                        <li>Files whose name does not match any student ID are skipped (no auto-creation).</li>
                    </ul>
                </div>

                <form method="post" enctype="multipart/form-data" id="bulk-photo-form">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="overwrite" value="1" id="overwrite">
                            <label class="form-check-label fw-semibold" for="overwrite">
                                Replace existing photos
                            </label>
                        </div>
                        <div class="form-text ms-4">When checked, students who already have a photo will have it replaced by the new one. When unchecked, those students are skipped.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="zip_file">
                            ZIP File <span class="text-danger">*</span>
                        </label>
                        <input type="file" class="form-control" id="zip_file" name="zip_file"
                               accept=".zip,application/zip" required>
                        <div class="form-text">Maximum upload size: 200 GB. Each image inside the ZIP must be named after the student ID, e.g. <code>25010101.jpg</code>.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submit-btn">
                            <i class="fas fa-upload me-1"></i> Start Import
                        </button>
                        <a href="<?= APP_URL ?>/students/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                    </div>
                </form>

            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('bulk-photo-form')?.addEventListener('submit', function () {
    var btn = document.getElementById('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Importing…';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
