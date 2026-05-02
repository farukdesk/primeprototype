<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('departments', 'can_delete');

$page_title = 'Merge Department';
$errors = [];
clear_old();

// Pre-select source from query-string (coming from index page)
$pre_source = (int)($_GET['id'] ?? 0);

// Load all departments for the dropdowns
$all_depts = db()->query('SELECT id, name, code FROM dept_departments ORDER BY name ASC')->fetchAll();

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $source_id = (int)($_POST['source_id'] ?? 0);
    $target_id = (int)($_POST['target_id'] ?? 0);

    if (!$source_id) $errors[] = 'Please select the department to merge (source).';
    if (!$target_id) $errors[] = 'Please select the destination department (target).';
    if ($source_id && $target_id && $source_id === $target_id) {
        $errors[] = 'Source and destination must be different departments.';
    }

    $source = null;
    $target = null;
    if ($source_id) {
        $s = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
        $s->execute([$source_id]);
        $source = $s->fetch();
        if (!$source) $errors[] = 'Source department not found.';
    }
    if ($target_id) {
        $t = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
        $t->execute([$target_id]);
        $target = $t->fetch();
        if (!$target) $errors[] = 'Destination department not found.';
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // ── 1. Core student records (the FK that blocked deletion) ────────
            // program_id is cleared because programs are department-specific;
            // the target dept's programs have different IDs and the old program_id
            // would point to the source dept's programs which are about to be deleted.
            $pdo->prepare(
                'UPDATE students SET dept_id = ?, program_id = NULL WHERE dept_id = ?'
            )->execute([$target_id, $source_id]);

            // ── 2. Result exams ───────────────────────────────────────────────
            // program_id cleared for the same reason as students above.
            if (table_exists('result_exams')) {
                $pdo->prepare(
                    'UPDATE result_exams SET dept_id = ?, program_id = NULL WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 3. Result mark sheets ─────────────────────────────────────────
            // program_id cleared for the same reason as students above.
            if (table_exists('result_mark_sheets')) {
                $pdo->prepare(
                    'UPDATE result_mark_sheets SET dept_id = ?, program_id = NULL WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 4. Workflow chains ────────────────────────────────────────────
            if (table_exists('wf_chains')) {
                $pdo->prepare(
                    'UPDATE wf_chains SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 5. Faculty profiles ───────────────────────────────────────────
            if (table_exists('faculty_profiles')) {
                $pdo->prepare(
                    'UPDATE faculty_profiles SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 6. Faculty registration ───────────────────────────────────────
            if (table_exists('faculty_registration')) {
                $pdo->prepare(
                    'UPDATE faculty_registration SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 7. Leads ──────────────────────────────────────────────────────
            if (table_exists('leads')) {
                $pdo->prepare(
                    'UPDATE leads SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 8. Broadcasts ─────────────────────────────────────────────────
            if (table_exists('broadcasts')) {
                $pdo->prepare(
                    'UPDATE broadcasts SET student_dept_id = ? WHERE student_dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 9. Clubs (main clubs module) ──────────────────────────────────
            if (table_exists('clubs')) {
                $pdo->prepare(
                    'UPDATE clubs SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 10. Gallery albums ────────────────────────────────────────────
            if (table_exists('gallery_albums')) {
                $pdo->prepare(
                    'UPDATE gallery_albums SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 11. Library members ───────────────────────────────────────────
            if (table_exists('library_members')) {
                $pdo->prepare(
                    'UPDATE library_members SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 12. Admissions applications ───────────────────────────────────
            if (table_exists('admissions_applications')) {
                $pdo->prepare(
                    'UPDATE admissions_applications SET dept_id = ? WHERE dept_id = ?'
                )->execute([$target_id, $source_id]);
            }

            // ── 13. Department sub-content: move to target ────────────────────
            // dept_overview is UNIQUE per dept_id, so it is intentionally excluded
            // here and will be removed by the CASCADE delete of the source dept.
            // $sub_tables is a fixed whitelist – never built from user input.
            $sub_tables = [
                'dept_faculty', 'dept_events', 'dept_alumni', 'dept_notices',
                'dept_routines', 'dept_facilities', 'dept_prime_pride',
                'dept_hero_slides', 'dept_clubs', 'dept_academic_programs',
            ];
            foreach ($sub_tables as $tbl) {
                if (table_exists($tbl)) {
                    // Safe: $tbl is validated against the whitelist above.
                    $pdo->prepare("UPDATE $tbl SET dept_id = ? WHERE dept_id = ?")
                        ->execute([$target_id, $source_id]);
                }
            }

            // ── 14. Delete source department (remaining CASCADE/SET-NULL FKs
            //        handle whatever is left) ───────────────────────────────────
            $pdo->prepare('DELETE FROM dept_departments WHERE id = ?')->execute([$source_id]);

            $pdo->commit();

            flash_set('success',
                "Department <strong>" . h($source['name']) . "</strong> has been merged into " .
                "<strong>" . h($target['name']) . "</strong> and deleted."
            );
            redirect(APP_URL . '/departments/index.php');

        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Database error: ' . h($e->getMessage());
        }
    }
}

// ── Helper: check if a table exists in the current database ──────────────────
// Results are cached in a static variable for the duration of the request.
function table_exists(string $table): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    }
    return $cache[$table];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item active">Merge Department</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-code-merge me-2 text-muted"></i>Merge Department</h6>
    </div>
    <div class="card-body p-4">
        <div class="alert alert-warning mb-4">
            <i class="fas fa-triangle-exclamation me-2"></i>
            <strong>What this does:</strong> All students, results, faculty profiles, leads, and other linked records
            belonging to the <em>source</em> department will be reassigned to the <em>destination</em> department.
            The source department will then be permanently deleted.
            <br><small class="text-muted mt-1 d-block">Note: Student program assignments are cleared during the merge because programs are department-specific. You can reassign programs to students individually afterwards if needed.</small>
        </div>

        <form method="POST" id="mergeFrm" novalidate>
            <?= csrf_field() ?>

            <div class="row g-4">
                <div class="col-md-5">
                    <label class="form-label fw-medium">
                        <span class="badge bg-danger me-1">Source</span>
                        Department to merge &amp; delete
                    </label>
                    <select name="source_id" id="sourceSelect" class="form-select" style="border-radius:10px;" required>
                        <option value="">— select department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= ((int)($_POST['source_id'] ?? $pre_source) === (int)$d['id']) ? 'selected' : '' ?>>
                            <?= h($d['name']) ?><?= $d['code'] ? ' (' . h($d['code']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">This department will be deleted after the merge.</small>
                </div>

                <div class="col-md-2 d-flex align-items-center justify-content-center pt-3">
                    <i class="fas fa-arrow-right fa-2x text-muted"></i>
                </div>

                <div class="col-md-5">
                    <label class="form-label fw-medium">
                        <span class="badge bg-success me-1">Destination</span>
                        Department to merge into
                    </label>
                    <select name="target_id" id="targetSelect" class="form-select" style="border-radius:10px;" required>
                        <option value="">— select department —</option>
                        <?php foreach ($all_depts as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= ((int)($_POST['target_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                            <?= h($d['name']) ?><?= $d['code'] ? ' (' . h($d['code']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">All records will be moved into this department.</small>
                </div>
            </div>

            <div id="confirmBox" class="alert alert-danger mt-4 d-none">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmChk">
                    <label class="form-check-label fw-medium" for="confirmChk">
                        I understand that <span id="confirmSourceName">the source department</span> will be
                        <strong>permanently deleted</strong> after merging all its data into
                        <span id="confirmTargetName">the destination department</span>.
                    </label>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" id="mergeBtn" class="btn btn-danger" style="border-radius:10px;" disabled>
                    <i class="fas fa-code-merge me-1"></i> Merge &amp; Delete Source
                </button>
                <a href="<?= APP_URL ?>/departments/index.php" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const src  = document.getElementById('sourceSelect');
    const tgt  = document.getElementById('targetSelect');
    const box  = document.getElementById('confirmBox');
    const chk  = document.getElementById('confirmChk');
    const btn  = document.getElementById('mergeBtn');
    const srcN = document.getElementById('confirmSourceName');
    const tgtN = document.getElementById('confirmTargetName');

    function update() {
        const sv = src.value, tv = tgt.value;
        const sText = src.options[src.selectedIndex]?.text ?? '';
        const tText = tgt.options[tgt.selectedIndex]?.text ?? '';
        if (sv && tv && sv !== tv) {
            srcN.textContent = '"' + sText + '"';
            tgtN.textContent = '"' + tText + '"';
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
            chk.checked = false;
        }
        btn.disabled = !(sv && tv && sv !== tv && chk.checked);
    }

    src.addEventListener('change', update);
    tgt.addEventListener('change', update);
    chk.addEventListener('change', update);

    document.getElementById('mergeFrm').addEventListener('submit', function (e) {
        if (src.value === tgt.value) {
            e.preventDefault();
            alert('Source and destination must be different departments.');
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
