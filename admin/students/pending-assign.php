<?php
/**
 * Student Files – Pending PDF Assignment
 *
 * Lists all PDFs in student_pdf_pending with status='pending' and lets the
 * admin assign each one to a student (by searching name / student ID) or
 * dismiss it.
 *
 * Actions (POST):
 *   assign  – links a pending PDF to a student, moves the file to
 *              uploads/students/files/, inserts a student_files row,
 *              and marks the pending record as 'assigned'.
 *   dismiss – deletes the pending file from disk and marks the record
 *              as 'dismissed'.
 *
 * Action (GET):
 *   search_students – returns JSON array of up to 10 students matching
 *                     the "q" query parameter (name or student_id).
 *                     Used by the inline AJAX typeahead.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students');
require_once __DIR__ . '/helpers.php';

if (!sm_can_create()) {
    flash_set('error', 'You do not have permission to manage pending files.');
    redirect(APP_URL . '/students/index.php');
}

$page_title = 'Pending PDF Assignments';
$user       = auth_user();

$pending_dir = UPLOAD_DIR . '/students/pending';
$files_dir   = UPLOAD_DIR . '/students/files';

// ── AJAX student search ───────────────────────────────────────────────────────

if (isset($_GET['action']) && $_GET['action'] === 'search_students') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        echo '[]';
        exit;
    }
    $like = '%' . $q . '%';
    $stmt = db()->prepare(
        "SELECT id, student_id, full_name
         FROM students
         WHERE student_id LIKE ? OR full_name LIKE ?
         ORDER BY student_id ASC
         LIMIT 10"
    );
    $stmt->execute([$like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array_map(fn($r) => [
        'id'   => (int)$r['id'],
        'sid'  => $r['student_id'],
        'name' => $r['full_name'],
    ], $rows), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Handle POST actions ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action     = $_POST['action']     ?? '';
    $pending_id = (int)($_POST['pending_id'] ?? 0);

    if ($pending_id <= 0) {
        flash_set('error', 'Invalid pending record.');
        redirect(APP_URL . '/students/pending-assign.php');
    }

    $pending = db()->prepare(
        "SELECT * FROM student_pdf_pending WHERE id = ? AND status = 'pending'"
    );
    $pending->execute([$pending_id]);
    $record = $pending->fetch();

    if (!$record) {
        flash_set('error', 'Pending record not found or already processed.');
        redirect(APP_URL . '/students/pending-assign.php');
    }

    if ($action === 'assign') {
        // ── Assign to student ─────────────────────────────────────────────
        $student_pk = (int)($_POST['student_pk'] ?? 0);
        if ($student_pk <= 0) {
            flash_set('error', 'Please select a student.');
            redirect(APP_URL . '/students/pending-assign.php');
        }

        $stu_stmt = db()->prepare('SELECT id, student_id, full_name FROM students WHERE id = ?');
        $stu_stmt->execute([$student_pk]);
        $stu = $stu_stmt->fetch();
        if (!$stu) {
            flash_set('error', 'Student not found.');
            redirect(APP_URL . '/students/pending-assign.php');
        }

        $src_path = $pending_dir . '/' . $record['stored_name'];
        if (!is_file($src_path)) {
            flash_set('error', 'Pending file missing from disk.');
            redirect(APP_URL . '/students/pending-assign.php');
        }

        if (!is_dir($files_dir)) {
            mkdir($files_dir, 0755, true);
        }

        // Canonical original name: <student_id>.pdf
        $canonical_orig = $stu['student_id'] . '.pdf';

        // Duplicate check
        $dup = db()->prepare(
            "SELECT id FROM student_files WHERE student_id = ? AND original_name = ? LIMIT 1"
        );
        $dup->execute([$stu['id'], $canonical_orig]);
        if ($dup->fetch()) {
            flash_set('error', 'A file named "' . $canonical_orig . '" is already attached to this student.');
            redirect(APP_URL . '/students/pending-assign.php');
        }

        $dest_stored = bin2hex(random_bytes(12)) . '.pdf';
        $dest_path   = $files_dir . '/' . $dest_stored;

        if (!rename($src_path, $dest_path)) {
            flash_set('error', 'Could not move file to final storage.');
            redirect(APP_URL . '/students/pending-assign.php');
        }

        db()->prepare(
            'INSERT INTO student_files
               (student_id, file_name, description, stored_name,
                original_name, mime_type, file_size, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $stu['id'],
            $record['file_label'],
            $record['description'] ?: null,
            $dest_stored,
            $canonical_orig,
            'application/pdf',
            $record['file_size'],
            $user['id'],
        ]);

        db()->prepare(
            "UPDATE student_pdf_pending
             SET status = 'assigned', assigned_student_pk = ?
             WHERE id = ?"
        )->execute([$stu['id'], $pending_id]);

        log_change('students', 'UPDATE', $stu['id'],
            $stu['full_name'] . ' (' . $stu['student_id'] . ')',
            'file_upload', null, $record['file_label'],
            'File uploaded via Smart PDF: ' . $record['original_name']);

        flash_set('success',
            '"' . $record['original_name'] . '" assigned to ' . $stu['full_name'] .
            ' (' . $stu['student_id'] . ') successfully.');
        redirect(APP_URL . '/students/pending-assign.php');
    }

    if ($action === 'dismiss') {
        // ── Dismiss (delete temp file) ────────────────────────────────────
        $src_path = $pending_dir . '/' . $record['stored_name'];
        if (is_file($src_path)) {
            @unlink($src_path);
        }
        db()->prepare(
            "UPDATE student_pdf_pending SET status = 'dismissed' WHERE id = ?"
        )->execute([$pending_id]);

        flash_set('success', 'Pending PDF "' . $record['original_name'] . '" dismissed.');
        redirect(APP_URL . '/students/pending-assign.php');
    }

    flash_set('error', 'Unknown action.');
    redirect(APP_URL . '/students/pending-assign.php');
}

// ── Load pending records ──────────────────────────────────────────────────────

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$total_rows  = (int)db()->query("SELECT COUNT(*) FROM student_pdf_pending WHERE status = 'pending'")->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$pending_rows = db()->query(
    "SELECT * FROM student_pdf_pending
     WHERE status = 'pending'
     ORDER BY created_at ASC
     LIMIT $per_page OFFSET $offset"
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/smart-upload.php">Smart Upload</a></li>
            <li class="breadcrumb-item active">Pending Assignments</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/students/smart-upload.php" class="btn btn-primary btn-sm" style="border-radius:9px;">
        <i class="fas fa-upload me-1"></i> Upload More PDFs
    </a>
</div>

<?php if ($total_rows === 0): ?>
<div class="card shadow-sm">
    <div class="card-body text-center py-5">
        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
        <h5>All caught up!</h5>
        <p class="text-muted">There are no PDFs waiting for assignment.</p>
        <a href="<?= APP_URL ?>/students/smart-upload.php" class="btn btn-primary">
            <i class="fas fa-upload me-1"></i> Upload PDFs
        </a>
    </div>
</div>
<?php else: ?>

<div class="alert alert-warning d-flex align-items-center gap-2 small mb-4" role="alert">
    <i class="fas fa-info-circle fa-lg flex-shrink-0"></i>
    <div>
        <strong><?= $total_rows ?> PDF<?= $total_rows !== 1 ? 's' : '' ?></strong> could not be automatically matched to a student.
        For each file below, search for the correct student and click <strong>Assign</strong>,
        or <strong>Dismiss</strong> to discard the file.
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-clock me-2 text-warning"></i>Pending PDFs
        </h6>
        <span class="badge bg-warning text-dark"><?= $total_rows ?> pending</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-4" style="width:40px;">#</th>
                        <th>Original Filename</th>
                        <th>IDs Found in Text</th>
                        <th>Uploaded</th>
                        <th style="min-width:300px;">Assign to Student</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending_rows as $i => $row): ?>
                <?php
                    $candidates = $row['candidate_ids'] ? json_decode($row['candidate_ids'], true) : [];
                    $candidates = is_array($candidates) ? array_slice($candidates, 0, 8) : [];
                ?>
                <tr>
                    <td class="px-4 text-muted"><?= $offset + $i + 1 ?></td>
                    <td>
                        <div class="fw-medium small"><?= h($row['original_name']) ?></div>
                        <div class="text-muted" style="font-size:.75rem;">
                            <?= sm_format_size((int)$row['file_size']) ?>
                            &bull; Uploaded <?= date('d M Y, H:i', strtotime($row['created_at'])) ?>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($candidates)): ?>
                            <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($candidates as $c): ?>
                                <code class="text-secondary small"><?= h($c) ?></code>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                    <td>
                        <form method="POST" action="" id="assign-form-<?= $row['id'] ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="assign">
                            <input type="hidden" name="pending_id" value="<?= $row['id'] ?>">

                            <div class="d-flex gap-1">
                                <input type="text"
                                       id="stu-search-<?= $row['id'] ?>"
                                       class="form-control form-control-sm stu-search"
                                       data-pending="<?= $row['id'] ?>"
                                       placeholder="Type student name or ID…"
                                       autocomplete="off"
                                       style="min-width:0;">
                                <input type="hidden"
                                       name="student_pk"
                                       id="stu-pk-<?= $row['id'] ?>">
                            </div>
                            <ul class="list-group shadow-sm stu-dropdown mt-1"
                                id="stu-drop-<?= $row['id'] ?>"
                                style="display:none;position:absolute;z-index:1000;max-height:200px;overflow-y:auto;min-width:280px;">
                            </ul>
                        </form>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button"
                                    class="btn btn-sm btn-success assign-btn"
                                    data-pending="<?= $row['id'] ?>"
                                    title="Assign to selected student"
                                    style="border-radius:7px;">
                                <i class="fas fa-check"></i> Assign
                            </button>
                            <form method="POST" action=""
                                  onsubmit="return confirm('Dismiss and delete this PDF?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="dismiss">
                                <input type="hidden" name="pending_id" value="<?= $row['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Dismiss" style="border-radius:7px;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer py-3 px-4 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Student AJAX search (server-side, no full preload) -->
<script>
(function () {
    const SEARCH_URL = '<?= APP_URL ?>/students/pending-assign.php?action=search_students&q=';

    let debounceTimers = {};

    function fetchStudents(q, callback) {
        if (q.length < 2) { callback([]); return; }
        fetch(SEARCH_URL + encodeURIComponent(q))
            .then(r => r.json())
            .then(callback)
            .catch(() => callback([]));
    }

    function attachSearch(inputEl, dropEl, pkEl) {
        inputEl.addEventListener('input', function () {
            const pid = inputEl.dataset.pending;
            clearTimeout(debounceTimers[pid]);
            const q = this.value.trim();
            if (q.length < 2) { dropEl.style.display = 'none'; dropEl.innerHTML = ''; return; }
            debounceTimers[pid] = setTimeout(function () {
                fetchStudents(q, function (results) {
                    dropEl.innerHTML = '';
                    if (results.length === 0) { dropEl.style.display = 'none'; return; }
                    results.forEach(function (s) {
                        const li = document.createElement('li');
                        li.className = 'list-group-item list-group-item-action py-1 px-2 small';
                        li.style.cursor = 'pointer';
                        li.innerHTML = '<code class="me-1">' + s.sid + '</code> ' + s.name;
                        li.addEventListener('click', function () {
                            inputEl.value        = s.sid + ' \u2013 ' + s.name;
                            pkEl.value           = s.id;
                            dropEl.style.display = 'none';
                        });
                        dropEl.appendChild(li);
                    });
                    dropEl.style.display = 'block';
                });
            }, 250);
        });

        document.addEventListener('click', function (e) {
            if (!dropEl.contains(e.target) && e.target !== inputEl) {
                dropEl.style.display = 'none';
            }
        });
    }

    document.querySelectorAll('.stu-search').forEach(function (inp) {
        const pid  = inp.dataset.pending;
        const drop = document.getElementById('stu-drop-' + pid);
        const pk   = document.getElementById('stu-pk-'   + pid);
        attachSearch(inp, drop, pk);
    });

    document.querySelectorAll('.assign-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const pid = this.dataset.pending;
            const pk  = document.getElementById('stu-pk-' + pid);
            if (!pk.value) {
                alert('Please search for and select a student first.');
                return;
            }
            document.getElementById('assign-form-' + pid).submit();
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
