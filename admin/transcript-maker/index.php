<?php
/**
 * Transcript Maker
 *
 * Two-step workflow:
 *   1. Upload a tabulation file (CSV / XLS / XLSX / ODS) – the same file format
 *      accepted by the Tabulation Sheet Checker.
 *   2. Pick a student from the parsed list, review the generated transcript, and
 *      export it as an editable Microsoft Word (.doc) document.
 *
 * Parsed data is cached in the session between steps so the file only has to be
 * uploaded once.  The actual Word file is streamed by download.php.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_access('transcript-maker');
require_once __DIR__ . '/helpers.php';

csrf_check();

$page_title = 'Transcript Maker';
const TM_SESSION_KEY = 'transcript_maker_data';

$errors = [];

/* ── Step 1: handle upload ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tabulation_file'])) {
    $file = $_FILES['tabulation_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed (error code ' . (int)$file['error'] . ').';
    } else {
        $allowed_ext = ['csv', 'xls', 'xlsx', 'ods'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            $errors[] = 'Unsupported file type. Please upload CSV, XLS, XLSX, or ODS.';
        } else {
            try {
                $parsed = tm_parse_file($file['tmp_name'], $ext);
                if (empty($parsed['students'])) {
                    $errors[] = 'No student data could be detected. Please check the file matches the expected tabulation layout.';
                } else {
                    $_SESSION[TM_SESSION_KEY] = [
                        'file_name' => $file['name'],
                        'meta'      => $parsed['meta'],
                        'students'  => $parsed['students'],
                    ];
                    // Post/Redirect/Get so a refresh does not re-upload.
                    redirect(APP_URL . '/transcript-maker/index.php');
                }
            } catch (\Throwable $e) {
                $errors[] = 'Could not read the file: ' . h($e->getMessage());
            }
        }
    }
}

/* ── Clear loaded data ──────────────────────────────────────────────────────── */
if (isset($_GET['clear'])) {
    unset($_SESSION[TM_SESSION_KEY]);
    redirect(APP_URL . '/transcript-maker/index.php');
}

/* ── Current session data ───────────────────────────────────────────────────── */
$data     = $_SESSION[TM_SESSION_KEY] ?? null;
$meta     = $data['meta']     ?? [];
$students = $data['students'] ?? [];

$selected_id = isset($_GET['sid']) ? (string)$_GET['sid'] : '';
$student     = ($selected_id !== '' && isset($students[$selected_id])) ? $students[$selected_id] : null;

// Pre-filled transcript header values for the selected student.
$program            = $meta['program']            ?? '';
$admission_semester = $meta['admission_semester'] ?? '';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Transcript Maker</li>
        </ol>
    </nav>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Step 1: Upload ─────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-excel me-2 text-success"></i>Step 1 &middot; Upload Tabulation File</h6>
        <?php if ($data): ?>
        <span class="badge bg-success-subtle text-success ms-auto">
            <i class="fas fa-check me-1"></i><?= h($data['file_name']) ?> &middot; <?= count($students) ?> students
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="tabulation_file" class="form-label fw-medium">Select File
                        <span class="text-muted fw-normal">(CSV, XLS, XLSX, ODS)</span>
                    </label>
                    <input type="file" class="form-control" id="tabulation_file" name="tabulation_file"
                           accept=".csv,.xls,.xlsx,.ods" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                        <i class="fas fa-upload me-1"></i> Upload &amp; Parse
                    </button>
                </div>
            </div>
            <div class="mt-3 text-muted" style="font-size:.82rem;">
                <i class="fas fa-info-circle me-1"></i>
                Upload the same tabulation sheet used by the
                <a href="<?= APP_URL ?>/tabulation-checker/index.php">Tabulation Checker</a>.
                Each subject block should contain <strong>Grade | Grade Point | Cr. Hr. | Semester</strong>
                columns with the Course Code and Course Title in the header rows above.
            </div>
        </form>
    </div>
</div>

<?php if ($data): ?>
<!-- ── Step 2: Select student ─────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-user-graduate me-2 text-primary"></i>Step 2 &middot; Select Student</h6>
        <a href="<?= APP_URL ?>/transcript-maker/index.php?clear=1" class="btn btn-sm btn-outline-secondary ms-auto"
           style="border-radius:8px;font-size:.75rem;">
            <i class="fas fa-times me-1"></i> Clear &amp; upload another
        </a>
    </div>
    <div class="card-body p-4">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-9">
                <label for="sid" class="form-label fw-medium">Student</label>
                <select name="sid" id="sid" class="form-select" onchange="this.form.submit()">
                    <option value="">— choose a student —</option>
                    <?php foreach ($students as $id => $s): ?>
                    <option value="<?= h($id) ?>" <?= $id === $selected_id ? 'selected' : '' ?>>
                        <?= h($id) ?> — <?= h($s['name'] !== '' ? $s['name'] : '(no name)') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-eye me-1"></i> Preview
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($student): ?>
<!-- ── Step 3: Preview & export ───────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-file-word me-2 text-primary"></i>Step 3 &middot; Review &amp; Export</h6>
    </div>
    <div class="card-body p-4">
        <form method="get" action="<?= APP_URL ?>/transcript-maker/download.php" target="_blank">
            <input type="hidden" name="sid" value="<?= h($selected_id) ?>">

            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Program</label>
                    <input type="text" name="program" class="form-control" value="<?= h($program) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Area of Concentration</label>
                    <input type="text" name="area_of_concentration" class="form-control"
                           placeholder="e.g. Human Resource Management">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Admission Semester</label>
                    <input type="text" name="admission_semester" class="form-control" value="<?= h($admission_semester) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Completion Semester</label>
                    <input type="text" name="completion_semester" class="form-control"
                           placeholder="e.g. Spring-2026">
                </div>
            </div>

            <!-- Transcript header preview -->
            <div class="p-3 mb-3 rounded" style="background:#f7f9ff;font-size:.9rem;">
                <div class="fw-bold"><?= h($meta['university'] ?? 'Prime University') ?></div>
                <?php if (!empty($meta['department'])): ?><div><?= h($meta['department']) ?></div><?php endif; ?>
                <?php if (!empty($meta['faculty'])): ?><div class="text-muted"><?= h($meta['faculty']) ?></div><?php endif; ?>
                <hr class="my-2">
                <div class="row">
                    <div class="col-md-6"><strong>Name of Student:</strong> <?= h($student['name']) ?></div>
                    <div class="col-md-6"><strong>ID No.:</strong> <?= h($student['id']) ?></div>
                    <div class="col-md-6"><strong>Total Credit:</strong> <?= h(rtrim(rtrim(number_format($student['total_credit'], 2), '0'), '.')) ?></div>
                    <div class="col-md-6"><strong>CGPA:</strong> <?= $student['cgpa'] !== null ? h(number_format($student['cgpa'], 2)) : '—' ?></div>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:4rem;">Sl. No.</th>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th class="text-center" style="width:5rem;">Credit</th>
                            <th>Grade Obtained</th>
                            <th class="text-center" style="width:6rem;">Grade Point</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sl = 0; foreach ($student['subjects'] as $sub): ?>
                        <?php if ($sub['code'] === '' && $sub['grade'] === '') continue; $sl++; ?>
                        <tr>
                            <td class="text-center"><?= $sl ?></td>
                            <td><?= h($sub['code']) ?></td>
                            <td><?= h($sub['title']) ?></td>
                            <td class="text-center"><?= $sub['credit'] !== null ? h(rtrim(rtrim(number_format($sub['credit'], 2), '0'), '.')) : '' ?></td>
                            <td><?= h(tm_format_grade($sub['grade'])) ?></td>
                            <td class="text-center"><?= $sub['grade_point'] !== null ? h(number_format($sub['grade_point'], 2)) : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-success" style="border-radius:10px;">
                <i class="fas fa-file-word me-1"></i> Download Word (.doc)
            </button>
        </form>
    </div>
</div>
<?php endif; /* $student */ ?>
<?php endif; /* $data */ ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
