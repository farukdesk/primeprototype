<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('results', 'can_edit');
require_once __DIR__ . '/../helpers.php';

$id      = (int)($_GET['id']      ?? 0);
$exam_id = (int)($_GET['exam_id'] ?? 0);

$subj_stmt = db()->prepare('SELECT * FROM result_subjects WHERE id = ? AND exam_id = ?');
$subj_stmt->execute([$id, $exam_id]);
$subject = $subj_stmt->fetch();
if (!$subject) {
    flash_set('error', 'Subject not found.');
    redirect(APP_URL . '/results/index.php');
}
$exam = rm_get_exam($exam_id);
$existing_cats = rm_get_mark_categories($id);
$page_title = 'Edit Subject';
$errors     = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
    $course_code   = trim($_POST['course_code']   ?? '');
    $course_title  = trim($_POST['course_title']  ?? '');
    $credits       = trim($_POST['credits']        ?? '');
    $sort_order    = (int)($_POST['sort_order']    ?? 0);

    // Mark categories
    $cat_names  = (array)($_POST['cat_name']  ?? []);
    $cat_marks  = (array)($_POST['cat_marks'] ?? []);
    $cat_orders = (array)($_POST['cat_order'] ?? []);

    if ($course_title === '') $errors[] = 'Course title is required.';

    $valid_cats = [];
    $cat_total  = 0;
    foreach ($cat_names as $ci => $cname) {
        $cname  = trim($cname);
        $cmarks = isset($cat_marks[$ci]) ? (float)$cat_marks[$ci] : 0;
        if ($cname === '') continue;
        if ($cmarks <= 0) { $errors[] = 'Each category must have max marks greater than 0.'; break; }
        $cat_total += $cmarks;
        $valid_cats[] = ['name' => $cname, 'marks' => $cmarks, 'order' => (int)($cat_orders[$ci] ?? $ci)];
    }
    if (!empty($valid_cats) && abs($cat_total - 100) > 0.01) {
        $errors[] = 'Marking category totals must add up to 100 (currently ' . number_format($cat_total, 2) . ').';
    }

    $credits_val = null;
    if ($credits !== '') {
        if (!is_numeric($credits) || (float)$credits < 0) {
            $errors[] = 'Credits must be a valid non-negative number.';
        } else {
            $credits_val = (float)$credits;
        }
    }

    if (empty($errors)) {
        db()->prepare(
            'UPDATE result_subjects
             SET curriculum_id=?, course_code=?, course_title=?, credits=?, sort_order=?
             WHERE id=?'
        )->execute([
            $curriculum_id ?: null,
            $course_code   ?: null,
            $course_title,
            $credits_val,
            $sort_order,
            $id,
        ]);

        // Replace mark categories: delete old, insert new
        db()->prepare('DELETE FROM result_mark_categories WHERE subject_id = ?')->execute([$id]);
        if (!empty($valid_cats)) {
            $cat_stmt = db()->prepare(
                'INSERT INTO result_mark_categories (subject_id, category_name, max_marks, sort_order)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($valid_cats as $cat) {
                $cat_stmt->execute([$id, $cat['name'], $cat['marks'], $cat['order']]);
            }
        }

        flash_set('success', 'Subject updated.');
        redirect(APP_URL . '/results/view.php?id=' . $exam_id . '&tab=subjects');
    }

    save_old(compact('curriculum_id','course_code','course_title','credits','sort_order'));
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/results/index.php">Results</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects">
                    <?= h($exam['exam_title']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active">Edit Subject</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-book me-2 text-muted"></i>Edit Subject</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="curriculum_id" value="<?= (int)($subject['curriculum_id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Course Title <span class="text-danger">*</span></label>
                        <input type="text" name="course_title" class="form-control"
                               value="<?= old('course_title', $subject['course_title']) ?>"
                               maxlength="300" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium">Course Code</label>
                            <input type="text" name="course_code" class="form-control"
                                   value="<?= old('course_code', $subject['course_code'] ?? '') ?>"
                                   maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Credits</label>
                            <input type="number" name="credits" class="form-control"
                                   value="<?= old('credits', $subject['credits'] ?? '') ?>"
                                   step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-medium">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                   value="<?= old('sort_order', $subject['sort_order']) ?>" min="0">
                        </div>
                    </div>

                    <!-- ── Marking Categories ── -->
                    <hr>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <label class="form-label fw-medium mb-0">
                            Marking Categories
                            <small class="text-muted fw-normal">— breakdown of the 100 marks (optional)</small>
                        </label>
                        <button type="button" id="add_cat_btn" class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
                            <i class="fas fa-plus me-1"></i> Add Category
                        </button>
                    </div>
                    <div id="cat_rows">
                        <!-- rows injected by JS -->
                    </div>
                    <div id="cat_total_wrap" class="small mt-1" style="display:none;">
                        Total: <span id="cat_total" class="fw-bold">0</span> / 100
                        <span id="cat_total_warn" class="text-danger ms-1" style="display:none;">Must equal 100</span>
                    </div>
                    <div class="form-text mb-3">
                        e.g. Attendance 10, Class Test 10, Mid Term 30, Final 50 — must sum to 100 if provided.
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-save me-1"></i> Save
                        </button>
                        <a href="<?= APP_URL ?>/results/view.php?id=<?= $exam_id ?>&tab=subjects"
                           class="btn btn-light" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var rowIdx = 0;
    var catRows = document.getElementById('cat_rows');
    var totalSpan = document.getElementById('cat_total');
    var totalWrap = document.getElementById('cat_total_wrap');
    var totalWarn = document.getElementById('cat_total_warn');

    function updateTotal() {
        var total = 0;
        catRows.querySelectorAll('.cat-marks-inp').forEach(function (inp) {
            var v = parseFloat(inp.value);
            if (!isNaN(v)) total += v;
        });
        totalSpan.textContent = total.toFixed(2);
        var ok = Math.abs(total - 100) < 0.01 || catRows.children.length === 0;
        totalWarn.style.display = ok ? 'none' : '';
        totalWrap.style.display = catRows.children.length ? '' : 'none';
    }

    function addRow(name, marks, order) {
        var idx = rowIdx++;
        var div = document.createElement('div');
        div.className = 'row g-2 mb-2 cat-row align-items-center';
        div.innerHTML =
            '<div class="col-md-5">' +
            '<input type="text" name="cat_name[' + idx + ']" class="form-control form-control-sm" placeholder="Category name (e.g. Attendance)" maxlength="100" value="' + (name || '') + '" required>' +
            '</div>' +
            '<div class="col-md-3">' +
            '<div class="input-group input-group-sm">' +
            '<input type="number" name="cat_marks[' + idx + ']" class="form-control form-control-sm cat-marks-inp" placeholder="Max marks" min="0.01" max="100" step="0.01" value="' + (marks || '') + '" required>' +
            '<span class="input-group-text">pts</span>' +
            '</div>' +
            '</div>' +
            '<div class="col-md-2">' +
            '<input type="number" name="cat_order[' + idx + ']" class="form-control form-control-sm" placeholder="Order" min="0" value="' + (order !== undefined ? order : idx) + '">' +
            '</div>' +
            '<div class="col-md-2">' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-cat-btn" style="border-radius:7px;"><i class="fas fa-trash"></i></button>' +
            '</div>';
        div.querySelector('.cat-marks-inp').addEventListener('input', updateTotal);
        div.querySelector('.remove-cat-btn').addEventListener('click', function () {
            div.remove();
            updateTotal();
        });
        catRows.appendChild(div);
        updateTotal();
    }

    document.getElementById('add_cat_btn').addEventListener('click', function () {
        addRow('', '', rowIdx);
    });

    // Pre-fill existing categories (from DB or from POST on validation error)
    var existing = <?= json_encode(
        !empty($_POST['cat_name'])
            ? array_values(array_filter(array_map(function($i) {
                $n = trim($_POST['cat_name'][$i] ?? '');
                return $n !== '' ? ['category_name' => $n, 'max_marks' => $_POST['cat_marks'][$i] ?? 0, 'sort_order' => $_POST['cat_order'][$i] ?? $i] : null;
              }, array_keys($_POST['cat_name']))))
            : $existing_cats
    ) ?>;
    existing.forEach(function (c) {
        addRow(c.category_name, parseFloat(c.max_marks), c.sort_order);
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
