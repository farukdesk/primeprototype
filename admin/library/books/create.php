<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

if (!lib_can_create()) {
    flash_set('error', 'You do not have permission to add books.');
    redirect(APP_URL . '/library/books/index.php');
}

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

    // Copies count (1–20)
    $copies_count = max(1, min(20, (int)($_POST['copies_count'] ?? 1)));

    // ── Validation ────────────────────────────────────────────────────────────
    if ($title === '')  $errors[] = 'Title is required.';
    if ($author === '') $errors[] = 'Author is required.';

    if ($isbn !== '') {
        $dup = $db->prepare('SELECT id FROM library_books WHERE isbn = ? LIMIT 1');
        $dup->execute([$isbn]);
        if ($dup->fetch()) {
            $errors[] = 'A book with this ISBN already exists.';
        }
    }

    // ── Cover upload ──────────────────────────────────────────────────────────
    $cover_image = null;
    if (!empty($_FILES['cover_image']['name'])) {
        try {
            $cover_image = lib_upload_cover($_FILES['cover_image']);
        } catch (RuntimeException $e) {
            $errors[] = 'Cover image: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $user = auth_user();

        $db->prepare(
            'INSERT INTO library_books
             (isbn, title, subtitle, author, publisher, edition, pub_year,
              category_id, language, description, department_id, cover_image,
              shelf_rack, shelf_row, total_copies, available_copies,
              is_digital, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
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
            $copies_count,
            $copies_count,
            $is_digital,
            $user['id'] ?? 0,
        ]);

        $book_id = (int)$db->lastInsertId();

        // Insert copies
        $copy_stmt = $db->prepare(
            'INSERT INTO library_book_copies
             (book_id, barcode, copy_number, condition_status, notes, is_available, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        for ($n = 1; $n <= $copies_count; $n++) {
            $barcode = lib_generate_barcode($book_id, $n);
            $copy_stmt->execute([$book_id, $barcode, $n, 'Good', null, 1]);
        }

        log_change('library', 'CREATE', $book_id, $title, null, null, null,
            "Added book \"{$title}\" with {$copies_count} cop" . ($copies_count === 1 ? 'y' : 'ies') . ".");
        lib_audit('BOOK_CREATED', 'books', $book_id, $title,
            "Created with {$copies_count} cop" . ($copies_count === 1 ? 'y' : 'ies') . ".");

        flash_set('success', "Book <strong>" . h($title) . "</strong> added successfully.");
        redirect(APP_URL . '/library/books/view.php?id=' . $book_id);
    }

    save_old(compact('title','subtitle','isbn','author','language','publisher','edition',
                     'pub_year','category_id','dept_id','shelf_rack','shelf_row',
                     'description','is_digital','copies_count'));
}

// ── Filter data for dropdowns ─────────────────────────────────────────────────
$categories  = lib_all_categories();
$departments = $db->query('SELECT id, name FROM dept_departments ORDER BY name ASC')->fetchAll();
$cur_year    = (int)date('Y');

$page_title = 'Add Book';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/books/index.php">Books</a></li>
            <li class="breadcrumb-item active">Add Book</li>
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
                                   value="<?= h(old('title')) ?>" required maxlength="500"
                                   placeholder="Full book title">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Subtitle</label>
                            <input type="text" name="subtitle" class="form-control" style="border-radius:10px;"
                                   value="<?= h(old('subtitle')) ?>" maxlength="500"
                                   placeholder="Optional subtitle">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">ISBN</label>
                            <input type="text" name="isbn" class="form-control" style="border-radius:10px;"
                                   value="<?= h(old('isbn')) ?>" maxlength="30"
                                   placeholder="e.g. 978-3-16-148410-0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Language</label>
                            <select name="language" class="form-select" style="border-radius:10px;">
                                <option value="">— Select Language —</option>
                                <?php foreach (['English','Bengali','Arabic','Hindi','Other'] as $lng): ?>
                                <option value="<?= h($lng) ?>" <?= old('language') === $lng ? 'selected' : '' ?>>
                                    <?= h($lng) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Author(s) <span class="text-danger">*</span></label>
                            <textarea name="author" class="form-control" style="border-radius:10px;" rows="2"
                                      placeholder="Separate multiple authors with a comma"
                                      required><?= h(old('author')) ?></textarea>
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
                                   value="<?= h(old('publisher')) ?>" maxlength="300"
                                   placeholder="Publisher name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Edition</label>
                            <input type="text" name="edition" class="form-control" style="border-radius:10px;"
                                   value="<?= h(old('edition')) ?>" maxlength="50"
                                   placeholder="e.g. 3rd">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Publication Year</label>
                            <select name="pub_year" class="form-select" style="border-radius:10px;">
                                <option value="">— Year —</option>
                                <?php for ($yr = $cur_year; $yr >= 1950; $yr--): ?>
                                <option value="<?= $yr ?>" <?= (int)old('pub_year', 0) === $yr ? 'selected' : '' ?>>
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
                                        <?= (int)old('category_id', 0) === (int)$cat['id'] ? 'selected' : '' ?>>
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
                                        <?= (int)old('dept_id', 0) === (int)$dep['id'] ? 'selected' : '' ?>>
                                    <?= h($dep['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Shelf Rack</label>
                            <input type="text" name="shelf_rack" class="form-control" style="border-radius:10px;"
                                   value="<?= h(old('shelf_rack')) ?>" maxlength="50"
                                   placeholder="e.g. A, B1, East-3">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Shelf Row</label>
                            <input type="text" name="shelf_row" class="form-control" style="border-radius:10px;"
                                   value="<?= h(old('shelf_row')) ?>" maxlength="50"
                                   placeholder="e.g. Row 2, Top">
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
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="5"
                              placeholder="Book summary or description…"><?= h(old('description')) ?></textarea>
                </div>
            </div>

        </div>

        <!-- Right Column: Cover & Copies -->
        <div class="col-lg-4">

            <!-- Cover Image -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-image me-2 text-muted"></i>Cover Image</h6>
                </div>
                <div class="card-body p-4">
                    <div id="cover-preview" class="mb-3 text-center" style="display:none;">
                        <img id="cover-img" src="" alt="Cover preview"
                             style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #e0e0e0;">
                    </div>
                    <div id="cover-placeholder" class="mb-3 d-flex align-items-center justify-content-center"
                         style="height:140px;background:#f8f9fa;border-radius:8px;border:2px dashed #dee2e6;">
                        <div class="text-center text-muted">
                            <i class="fas fa-image fa-2x mb-2 d-block opacity-40"></i>
                            <small>No cover selected</small>
                        </div>
                    </div>
                    <input type="file" name="cover_image" id="cover_image" class="form-control"
                           style="border-radius:10px;" accept=".jpg,.jpeg,.png,.gif,.webp">
                    <div class="form-text">Max 5 MB. JPG, PNG, GIF or WebP.</div>
                </div>
            </div>

            <!-- Copies & Options -->
            <div class="card mb-4">
                <div class="card-header py-3 px-4">
                    <h6 class="mb-0 fw-semibold"><i class="fas fa-copy me-2 text-muted"></i>Copies &amp; Options</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Number of Copies to Add</label>
                        <input type="number" name="copies_count" class="form-control" style="border-radius:10px;"
                               value="<?= h(old('copies_count', '1')) ?>" min="1" max="20">
                        <div class="form-text">Each copy gets an auto-generated barcode (Good condition).</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_digital" name="is_digital"
                               value="1" <?= old('is_digital') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_digital">Digital / E-Book</label>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Book
                </button>
                <a href="<?= APP_URL ?>/library/books/index.php" class="btn btn-light" style="border-radius:10px;">
                    Cancel
                </a>
            </div>

        </div>
    </div>
</form>

<script>
document.getElementById('cover_image').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('cover-img').src = e.target.result;
        document.getElementById('cover-preview').style.display = 'block';
        document.getElementById('cover-placeholder').style.display = 'none';
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
