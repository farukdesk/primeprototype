<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

if (!lib_is_staff()) {
    flash_set('error', 'You do not have permission to edit books.');
    redirect(APP_URL . '/library/books/index.php');
}

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$book = lib_get_book($id);

$db     = db();
$errors = [];
clear_old();

// ── POST processing ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Basic Information
    $title    = trim($_POST['title']    ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $isbn     = trim($_POST['isbn']     ?? '');
    $author   = trim($_POST['author']   ?? '');
    $language = trim($_POST['language'] ?? '');

    // Publication Details
    $publisher = trim($_POST['publisher'] ?? '');
    $edition   = trim($_POST['edition']   ?? '');
    $pub_year  = (int)($_POST['pub_year'] ?? 0);

    // Classification
    $category_id = (int)($_POST['category_id'] ?? 0);
    $dept_id     = (int)($_POST['dept_id']      ?? 0);
    $shelf_rack  = trim($_POST['shelf_rack']    ?? '');
    $shelf_row   = trim($_POST['shelf_row']     ?? '');

    // Description & Digital
    $description = trim($_POST['description'] ?? '');
    $is_digital  = isset($_POST['is_digital']) ? 1 : 0;

    // ── Validation ────────────────────────────────────────────────────────────
    if ($title === '')  $errors[] = 'Title is required.';
    if ($author === '') $errors[] = 'Author is required.';

    if ($isbn !== '' && $isbn !== ($book['isbn'] ?? '')) {
        $dup = $db->prepare('SELECT id FROM library_books WHERE isbn = ? AND id != ? LIMIT 1');
        $dup->execute([$isbn, $id]);
        if ($dup->fetch()) {
            $errors[] = 'Another book with this ISBN already exists.';
        }
    }

    // ── Cover handling ────────────────────────────────────────────────────────
    $cover_image = $book['cover_image'];

    // Explicit remove cover checkbox
    if (isset($_POST['remove_cover'])) {
        if ($cover_image) {
            lib_delete_file('covers', $cover_image);
        }
        $cover_image = null;
    }

    // New cover upload
    if (!empty($_FILES['cover_image']['name'])) {
        try {
            $new_cover = lib_upload_cover($_FILES['cover_image']);
            // Delete old cover if present
            if ($cover_image) {
                lib_delete_file('covers', $cover_image);
            }
            $cover_image = $new_cover;
        } catch (RuntimeException $e) {
            $errors[] = 'Cover image: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // Build change log entries for modified fields
        $fields = [
            'title'       => [$book['title'],       $title],
            'subtitle'    => [$book['subtitle'],     $subtitle     ?: null],
            'isbn'        => [$book['isbn'],         $isbn         ?: null],
            'author'      => [$book['author'],       $author],
            'language'    => [$book['language'],     $language     ?: null],
            'publisher'   => [$book['publisher'],    $publisher    ?: null],
            'edition'     => [$book['edition'],      $edition      ?: null],
            'pub_year'    => [$book['pub_year'],     $pub_year     ?: null],
            'category_id' => [$book['category_id'], $category_id  ?: null],
            'department_id' => [$book['department_id'], $dept_id      ?: null],
            'shelf_rack'  => [$book['shelf_rack'],   $shelf_rack   ?: null],
            'shelf_row'   => [$book['shelf_row'],    $shelf_row    ?: null],
            'description' => [$book['description'], $description  ?: null],
            'is_digital'  => [$book['is_digital'],  $is_digital],
        ];

        $db->prepare(
            'UPDATE library_books SET
             isbn=?, title=?, subtitle=?, author=?, publisher=?, edition=?, pub_year=?,
             category_id=?, language=?, description=?, department_id=?, cover_image=?,
             shelf_rack=?, shelf_row=?, is_digital=?, updated_at=NOW()
             WHERE id=?'
        )->execute([
            $isbn        ?: null,
            $title,
            $subtitle    ?: null,
            $author,
            $publisher   ?: null,
            $edition     ?: null,
            $pub_year    ?: null,
            $category_id ?: null,
            $language    ?: null,
            $description ?: null,
            $dept_id     ?: null,
            $cover_image,
            $shelf_rack  ?: null,
            $shelf_row   ?: null,
            $is_digital,
            $id,
        ]);

        foreach ($fields as $field => [$old_val, $new_val]) {
            if ((string)$old_val !== (string)$new_val) {
                log_change('library', 'UPDATE', $id, $title, $field, $old_val, $new_val, null);
            }
        }

        lib_audit('BOOK_UPDATED', 'books', $id, $title, "Book details updated.");

        flash_set('success', "Book <strong>" . h($title) . "</strong> updated successfully.");
        redirect(APP_URL . '/library/books/view.php?id=' . $id);
    }

    // Re-populate $book with submitted values for form repopulation
    $book = array_merge($book, compact(
        'title','subtitle','isbn','author','language','publisher','edition',
        'pub_year','category_id','dept_id','shelf_rack','shelf_row',
        'description','is_digital'
    ));
}

// ── Filter data for dropdowns ─────────────────────────────────────────────────
$categories  = lib_all_categories();
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$cur_year    = (int)date('Y');

$page_title = 'Edit Book – ' . $book['title'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/books/index.php">Books</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/library/books/view.php?id=<?= $id ?>">
                    <?= h($book['title']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-circle-exclamation me-2"></i>
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-1 ps-3">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="row g-4">
        <!-- Left Column: Main Details -->
        <div class="col-lg-8">

            <!-- Basic Information -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-circle-info me-2 text-muted"></i>Basic Information</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['title']) ?>" required maxlength="500">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Subtitle</label>
                            <input type="text" name="subtitle" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['subtitle'] ?? '') ?>" maxlength="500">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">ISBN</label>
                            <input type="text" name="isbn" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['isbn'] ?? '') ?>" maxlength="30">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Language</label>
                            <select name="language" class="form-select" style="border-radius:10px;">
                                <option value="">— Select Language —</option>
                                <?php foreach (['English','Bengali','Arabic','Hindi','Other'] as $lng): ?>
                                <option value="<?= h($lng) ?>"
                                        <?= ($book['language'] ?? '') === $lng ? 'selected' : '' ?>>
                                    <?= h($lng) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Author(s) <span class="text-danger">*</span></label>
                            <textarea name="author" class="form-control" style="border-radius:10px;" rows="2"
                                      required><?= h($book['author']) ?></textarea>
                            <div class="form-text">For multiple authors, separate names with a comma.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Publication Details -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-building-columns me-2 text-muted"></i>Publication Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Publisher</label>
                            <input type="text" name="publisher" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['publisher'] ?? '') ?>" maxlength="300">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Edition</label>
                            <input type="text" name="edition" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['edition'] ?? '') ?>" maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Publication Year</label>
                            <select name="pub_year" class="form-select" style="border-radius:10px;">
                                <option value="">— Year —</option>
                                <?php for ($yr = $cur_year; $yr >= 1950; $yr--): ?>
                                <option value="<?= $yr ?>" <?= (int)($book['pub_year'] ?? 0) === $yr ? 'selected' : '' ?>>
                                    <?= $yr ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Classification -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-tags me-2 text-muted"></i>Classification &amp; Location</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Category</label>
                            <select name="category_id" class="form-select" style="border-radius:10px;">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"
                                        <?= (int)($book['category_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Department</label>
                            <select name="dept_id" class="form-select" style="border-radius:10px;">
                                <option value="">— None / General —</option>
                                <?php foreach ($departments as $dep): ?>
                                <option value="<?= $dep['id'] ?>"
                                        <?= (int)($book['dept_id'] ?? 0) === (int)$dep['id'] ? 'selected' : '' ?>>
                                    <?= h($dep['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Shelf Rack</label>
                            <input type="text" name="shelf_rack" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['shelf_rack'] ?? '') ?>" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Shelf Row</label>
                            <input type="text" name="shelf_row" class="form-control" style="border-radius:10px;"
                                   value="<?= h($book['shelf_row'] ?? '') ?>" maxlength="50">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-align-left me-2 text-muted"></i>Description</h6>
                </div>
                <div class="card-body p-4">
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="5"><?= h($book['description'] ?? '') ?></textarea>
                </div>
            </div>

        </div>

        <!-- Right Column: Cover & Options -->
        <div class="col-lg-4">

            <!-- Cover Image -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Cover Image</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($book['cover_image']): ?>
                    <div class="mb-3 text-center" id="current-cover">
                        <img src="<?= UPLOAD_URL ?>/library/covers/<?= h($book['cover_image']) ?>"
                             alt="Current cover" id="cover-img"
                             style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #e0e0e0;">
                        <div class="mt-2">
                            <div class="form-check d-inline-flex align-items-center gap-2">
                                <input class="form-check-input" type="checkbox" id="remove_cover"
                                       name="remove_cover" value="1">
                                <label class="form-check-label text-danger small" for="remove_cover">
                                    Remove current cover
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3 d-flex align-items-center justify-content-center" id="current-cover"
                         style="height:140px;background:#f8f9fa;border-radius:8px;border:2px dashed #dee2e6;">
                        <div class="text-center text-muted">
                            <i class="fas fa-image fa-2x mb-2 d-block opacity-40"></i>
                            <small>No cover image</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div id="new-cover-preview" class="mb-2 text-center" style="display:none;">
                        <img id="new-cover-img" src="" alt="New cover"
                             style="max-width:100%;max-height:160px;border-radius:8px;border:1px solid #e0e0e0;">
                        <div class="text-muted mt-1" style="font-size:.75rem;">New cover (not saved yet)</div>
                    </div>
                    <label class="form-label fw-medium mb-1">Upload New Cover</label>
                    <input type="file" name="cover_image" id="cover_image_input" class="form-control"
                           style="border-radius:10px;" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text">Max 5 MB. Replaces current cover on save.</div>
                </div>
            </div>

            <!-- Options -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-sliders me-2 text-muted"></i>Options</h6>
                </div>
                <div class="card-body p-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_digital" name="is_digital"
                               value="1" <?= $book['is_digital'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_digital">Digital / E-Book</label>
                    </div>
                    <div class="mt-3 p-3 bg-light rounded" style="font-size:.8rem;">
                        <div class="text-muted mb-1">
                            <i class="fas fa-copy me-1"></i>
                            <strong><?= (int)$book['total_copies'] ?></strong> total cop<?= $book['total_copies'] == 1 ? 'y' : 'ies' ?>,
                            <strong class="text-success"><?= (int)$book['available_copies'] ?></strong> available
                        </div>
                        <small class="text-muted">Manage copies on the book detail page.</small>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Book
                </button>
                <a href="<?= APP_URL ?>/library/books/view.php?id=<?= $id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>

        </div>
    </div>
</form>

<script>
document.getElementById('cover_image_input').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('new-cover-img').src = e.target.result;
        document.getElementById('new-cover-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
