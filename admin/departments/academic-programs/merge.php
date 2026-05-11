<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('dept-academic-programs', 'can_delete');

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }
require_access_dept($dept_id);

$page_title = 'Merge Academic Programs – ' . $dept['name'];
$errors = [];
clear_old();

function prog_merge_safe_ident(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function prog_merge_table_exists(string $table): bool {
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

function prog_merge_column_exists(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    }
    return $cache[$key];
}

function prog_merge_fk_relations(): array {
    static $rows = null;
    if ($rows !== null) return $rows;
    $stmt = db()->query(
        "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = 'dept_academic_programs'
           AND REFERENCED_COLUMN_NAME = 'id'
         ORDER BY TABLE_NAME, COLUMN_NAME"
    );
    $rows = $stmt->fetchAll() ?: [];
    return $rows;
}

function prog_merge_count_refs(string $table, string $column, int $source_id): int {
    $sql = 'SELECT COUNT(*) FROM ' . prog_merge_safe_ident($table) . ' WHERE ' . prog_merge_safe_ident($column) . ' = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute([$source_id]);
    return (int)$stmt->fetchColumn();
}

function prog_merge_update_refs(PDO $pdo, string $table, string $column, int $source_id, int $target_id): void {
    $sql = 'UPDATE ' . prog_merge_safe_ident($table) .
        ' SET ' . prog_merge_safe_ident($column) . ' = ?' .
        ' WHERE ' . prog_merge_safe_ident($column) . ' = ?';
    $pdo->prepare($sql)->execute([$target_id, $source_id]);
}

$all_programs = db()->prepare(
    'SELECT id, program_name, degree_type, is_active
     FROM dept_academic_programs
     WHERE dept_id = ?
     ORDER BY sort_order ASC, id ASC'
);
$all_programs->execute([$dept_id]);
$all_programs = $all_programs->fetchAll();

$pre_source = (int)($_GET['id'] ?? 0);
$summary_source_id = (int)($_POST['source_id'] ?? $pre_source);
$relations_summary = [];
$manual_relations = [];

$fk_relations = prog_merge_fk_relations();
$fk_keys = [];
foreach ($fk_relations as $fk) {
    $key = $fk['table_name'] . '.' . $fk['column_name'];
    $fk_keys[$key] = true;
}

if (!isset($fk_keys['admissions_applications.program_id'])
    && prog_merge_table_exists('admissions_applications')
    && prog_merge_column_exists('admissions_applications', 'program_id')
) {
    $manual_relations[] = ['table_name' => 'admissions_applications', 'column_name' => 'program_id'];
}

if ($summary_source_id > 0) {
    foreach ($fk_relations as $rel) {
        $count = prog_merge_count_refs($rel['table_name'], $rel['column_name'], $summary_source_id);
        if ($count > 0) {
            $relations_summary[] = ['table' => $rel['table_name'], 'column' => $rel['column_name'], 'count' => $count];
        }
    }
    foreach ($manual_relations as $rel) {
        $count = prog_merge_count_refs($rel['table_name'], $rel['column_name'], $summary_source_id);
        if ($count > 0) {
            $relations_summary[] = ['table' => $rel['table_name'], 'column' => $rel['column_name'], 'count' => $count];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $source_id = (int)($_POST['source_id'] ?? 0);
    $target_id = (int)($_POST['target_id'] ?? 0);

    if (!$source_id) $errors[] = 'Please select source program.';
    if (!$target_id) $errors[] = 'Please select destination program.';
    if ($source_id && $target_id && $source_id === $target_id) {
        $errors[] = 'Source and destination programs must be different.';
    }

    $source = null;
    $target = null;
    if ($source_id) {
        $s = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ? AND dept_id = ?');
        $s->execute([$source_id, $dept_id]);
        $source = $s->fetch();
        if (!$source) $errors[] = 'Source program not found in this department.';
    }
    if ($target_id) {
        $t = db()->prepare('SELECT * FROM dept_academic_programs WHERE id = ? AND dept_id = ?');
        $t->execute([$target_id, $dept_id]);
        $target = $t->fetch();
        if (!$target) $errors[] = 'Destination program not found in this department.';
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (prog_merge_table_exists('adm_student_id_settings')
                && prog_merge_column_exists('adm_student_id_settings', 'program_id')
            ) {
                $sidSrc = $pdo->prepare('SELECT id, next_serial FROM adm_student_id_settings WHERE program_id = ?');
                $sidSrc->execute([$source_id]);
                $srcRow = $sidSrc->fetch();

                if ($srcRow) {
                    $sidTgt = $pdo->prepare('SELECT id, next_serial FROM adm_student_id_settings WHERE program_id = ?');
                    $sidTgt->execute([$target_id]);
                    $tgtRow = $sidTgt->fetch();

                    if ($tgtRow) {
                        // Keep the larger serial to avoid issuing duplicate/lower IDs after merge.
                        $nextSerial = max((int)$srcRow['next_serial'], (int)$tgtRow['next_serial']);
                        $pdo->prepare('UPDATE adm_student_id_settings SET next_serial = ? WHERE id = ?')
                            ->execute([$nextSerial, (int)$tgtRow['id']]);
                        $pdo->prepare('DELETE FROM adm_student_id_settings WHERE id = ?')
                            ->execute([(int)$srcRow['id']]);
                    } else {
                        $pdo->prepare('UPDATE adm_student_id_settings SET program_id = ? WHERE id = ?')
                            ->execute([$target_id, (int)$srcRow['id']]);
                    }
                }
            }

            foreach ($fk_relations as $rel) {
                if ($rel['table_name'] === 'adm_student_id_settings' && $rel['column_name'] === 'program_id') {
                    continue;
                }
                prog_merge_update_refs($pdo, $rel['table_name'], $rel['column_name'], $source_id, $target_id);
            }
            foreach ($manual_relations as $rel) {
                prog_merge_update_refs($pdo, $rel['table_name'], $rel['column_name'], $source_id, $target_id);
            }

            $pdo->prepare('DELETE FROM dept_academic_programs WHERE id = ? AND dept_id = ?')
                ->execute([$source_id, $dept_id]);

            $pdo->commit();

            if (!empty($source['attachment'])) {
                $file = UPLOAD_DIR . '/departments/' . $source['attachment'];
                if (file_exists($file) && !unlink($file)) {
                    error_log('Program merge attachment delete failed: ' . $file);
                }
            }

            flash_set(
                'success',
                'Program <strong>' . h($source['program_name']) . '</strong> merged into <strong>' .
                h($target['program_name']) . '</strong>.'
            );
            redirect(APP_URL . '/departments/academic-programs/index.php?dept_id=' . $dept_id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Program merge failed: ' . $e->getMessage());
            $errors[] = 'Unable to merge programs due to a data conflict. Please contact support if this continues.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>">Academic Programs</a></li>
            <li class="breadcrumb-item active">Merge</li>
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
        <h6 class="mb-0 fw-semibold"><i class="fas fa-code-merge me-2 text-muted"></i>Merge Duplicate Program</h6>
    </div>
    <div class="card-body p-4">
        <div class="alert alert-warning mb-4">
            <i class="fas fa-triangle-exclamation me-2"></i>
            This will move all current <strong>records linked to the source program</strong> to the destination program,
            then delete the source program permanently.
        </div>

        <form method="POST" id="mergeFrm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-4">
                <div class="col-md-5">
                    <label class="form-label fw-medium"><span class="badge bg-danger me-1">Source</span> Program to merge &amp; delete</label>
                    <select name="source_id" id="sourceSelect" class="form-select" style="border-radius:10px;" required>
                        <option value="">— select program —</option>
                        <?php foreach ($all_programs as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ((int)($_POST['source_id'] ?? $pre_source) === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= h($p['program_name']) ?><?= !empty($p['degree_type']) ? ' (' . h($p['degree_type']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-center justify-content-center pt-3">
                    <i class="fas fa-arrow-right fa-2x text-muted"></i>
                </div>

                <div class="col-md-5">
                    <label class="form-label fw-medium"><span class="badge bg-success me-1">Destination</span> Program to keep</label>
                    <select name="target_id" id="targetSelect" class="form-select" style="border-radius:10px;" required>
                        <option value="">— select program —</option>
                        <?php foreach ($all_programs as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ((int)($_POST['target_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= h($p['program_name']) ?><?= !empty($p['degree_type']) ? ' (' . h($p['degree_type']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="confirmBox" class="alert alert-danger mt-4 d-none">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="confirmChk">
                    <label class="form-check-label fw-medium" for="confirmChk">
                        I confirm the source program will be permanently deleted after merge.
                    </label>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" id="mergeBtn" class="btn btn-danger" style="border-radius:10px;" disabled>
                    <i class="fas fa-code-merge me-1"></i> Merge &amp; Delete Source
                </button>
                <a href="<?= APP_URL ?>/departments/academic-programs/index.php?dept_id=<?= $dept_id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-link me-2 text-muted"></i>Current Program References</h6>
        <span class="badge bg-primary bg-opacity-10 text-primary"><?= count($relations_summary) ?> tables with rows</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Table</th>
                        <th>Column</th>
                        <th class="text-end pe-4">Rows referencing selected source</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($relations_summary)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No references found for the currently selected source program.</td></tr>
                <?php else: ?>
                    <?php foreach ($relations_summary as $r): ?>
                    <tr>
                        <td class="px-4"><code><?= h($r['table']) ?></code></td>
                        <td><code><?= h($r['column']) ?></code></td>
                        <td class="text-end pe-4 fw-semibold"><?= (int)$r['count'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const src = document.getElementById('sourceSelect');
    const tgt = document.getElementById('targetSelect');
    const chk = document.getElementById('confirmChk');
    const box = document.getElementById('confirmBox');
    const btn = document.getElementById('mergeBtn');

    function update() {
        const isValidSelection = src.value && tgt.value && src.value !== tgt.value;
        box.classList.toggle('d-none', !isValidSelection);
        if (!isValidSelection) chk.checked = false;
        btn.disabled = !(isValidSelection && chk.checked);
    }

    src.addEventListener('change', update);
    tgt.addEventListener('change', update);
    chk.addEventListener('change', update);

    document.getElementById('mergeFrm').addEventListener('submit', function (e) {
        if (src.value === tgt.value) {
            e.preventDefault();
            alert('Source and destination must be different programs.');
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
