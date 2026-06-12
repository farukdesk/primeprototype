<?php
/**
 * Merge Duplicate Students
 *
 * Finds students whose IDs are duplicates differing only by leading zeros
 * (e.g. 02826205101010 vs 2826205101010). The record that already has a fee
 * package assigned (normally the one starting with zero) is KEPT; missing
 * general information (photo, personal info, etc.) is copied over from the
 * other record, all related records are re-pointed to the kept record, and
 * the duplicate is then deleted.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('students', 'can_delete');
require_once __DIR__ . '/helpers.php';

$page_title = 'Merge Duplicate Students';

// Scalar columns whose values are copied from the duplicate when the kept
// record's value is empty. Identity / fee columns are intentionally excluded.
const SM_MERGE_COLUMNS = [
    'program_id', 'batch', 'batch_id', 'shift', 'full_name', 'year', 'semester_type',
    'father_name', 'father_phone', 'father_occupation', 'father_yearly_income',
    'mother_name', 'mother_phone', 'mother_occupation', 'mother_yearly_income',
    'present_address', 'permanent_address', 'nationality', 'country',
    'district_id', 'thana_id', 'faculty_label', 'email', 'phone',
    'dob', 'blood_group', 'nid', 'place_of_birth', 'sex', 'religion', 'photo',
    'marital_status', 'passport_no',
    'guardian_name', 'guardian_profession', 'guardian_address',
    'guardian_phone', 'guardian_relationship',
    'reference_name', 'reference_address', 'reference_contact', 'reference_email',
    'local_guardian_name', 'local_guardian_contact',
    'local_guardian_address', 'local_guardian_email',
    'exam_title_id', 'board_id', 'group_id', 'ref_number',
];

/**
 * Merge column list limited to columns actually present in the students table,
 * so the tool works regardless of which optional migrations were applied.
 */
function sm_merge_columns(): array
{
    static $cols = null;
    if ($cols === null) {
        $stmt = db()->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students'"
        );
        $stmt->execute();
        $existing = array_column($stmt->fetchAll(), 'COLUMN_NAME');
        $cols = array_values(array_intersect(SM_MERGE_COLUMNS, $existing));
    }
    return $cols;
}

/**
 * Returns list of [table, column] pairs that have a FK referencing students.id.
 */
function sm_student_child_tables(): array
{
    $stmt = db()->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = 'students'
            AND REFERENCED_COLUMN_NAME = 'id'"
    );
    $stmt->execute();
    $refs = [];
    foreach ($stmt->fetchAll() as $row) {
        $refs[] = [$row['TABLE_NAME'], $row['COLUMN_NAME']];
    }
    // Tables that reference students.id without a declared foreign key.
    foreach ([['student_portal_log', 'student_id']] as $extra) {
        $exists = db()->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $exists->execute($extra);
        if ($exists->fetchColumn() && !in_array($extra, $refs, true)) {
            $refs[] = $extra;
        }
    }
    return $refs;
}

/**
 * Detects duplicate pairs. Two students are duplicates when their IDs are
 * identical after stripping leading zeros but the raw IDs differ.
 * Returns rows: keep_* (record to keep) and dup_* (record to merge+delete).
 */
function sm_find_duplicate_pairs(): array
{
    $sql = "SELECT a.id AS a_id, b.id AS b_id
              FROM students a
              JOIN students b
                ON a.id < b.id
               AND a.student_id <> b.student_id
               AND TRIM(LEADING '0' FROM a.student_id) = TRIM(LEADING '0' FROM b.student_id)";
    $pairs = [];
    foreach (db()->query($sql)->fetchAll() as $row) {
        $a = sm_get_student((int)$row['a_id']);
        $b = sm_get_student((int)$row['b_id']);
        if (!$a || !$b) continue;

        $pkg = db()->prepare('SELECT COUNT(*) FROM sfp_packages WHERE student_id = ?');
        $pkg->execute([(int)$a['id']]);
        $a_pkg = (int)$pkg->fetchColumn() > 0;
        $pkg->execute([(int)$b['id']]);
        $b_pkg = (int)$pkg->fetchColumn() > 0;

        // Keep the record with a fee package; fall back to the one whose ID
        // starts with a leading zero (per business rule), then to the older row.
        if ($a_pkg !== $b_pkg) {
            [$keep, $dup] = $a_pkg ? [$a, $b] : [$b, $a];
        } elseif (str_starts_with($a['student_id'], '0') !== str_starts_with($b['student_id'], '0')) {
            [$keep, $dup] = str_starts_with($a['student_id'], '0') ? [$a, $b] : [$b, $a];
        } else {
            [$keep, $dup] = [$a, $b];
        }
        $pkg_by_id = [(int)$a['id'] => $a_pkg, (int)$b['id'] => $b_pkg];
        $keep['has_pkg'] = $pkg_by_id[(int)$keep['id']];
        $dup['has_pkg']  = $pkg_by_id[(int)$dup['id']];
        $pairs[] = ['keep' => $keep, 'dup' => $dup];
    }
    return $pairs;
}

/**
 * Merges $dup into $keep inside a transaction, then deletes $dup.
 */
function sm_merge_students(array $keep, array $dup): void
{
    $keep_id = (int)$keep['id'];
    $dup_id  = (int)$dup['id'];

    db()->beginTransaction();
    try {
        // 1. Copy missing general info onto the kept record.
        $sets   = [];
        $params = [];
        foreach (sm_merge_columns() as $col) {
            $keep_val = $keep[$col] ?? null;
            $dup_val  = $dup[$col]  ?? null;
            $keep_empty = $keep_val === null || trim((string)$keep_val) === '';
            $dup_filled = $dup_val !== null && trim((string)$dup_val) !== '';
            if ($keep_empty && $dup_filled) {
                $sets[]   = "`$col` = ?";
                $params[] = $dup_val;
            }
        }
        if ($sets) {
            $params[] = $keep_id;
            db()->prepare('UPDATE students SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($params);
        }

        // Photo file reference moves with the column copy above; clear it on
        // the duplicate so the file is not deleted with the duplicate record.
        $photo_transferred = ($keep['photo'] === null || trim((string)$keep['photo']) === '')
            && $dup['photo'] !== null && trim((string)$dup['photo']) !== '';
        if ($photo_transferred) {
            db()->prepare('UPDATE students SET photo = NULL WHERE id = ?')->execute([$dup_id]);
        }

        // Portal account: keep takes the duplicate's portal account if it has none.
        $cols = db()->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'portal_user_id'"
        );
        $cols->execute();
        if ($cols->fetchColumn()) {
            $row = db()->prepare('SELECT id, portal_user_id FROM students WHERE id IN (?, ?)');
            $row->execute([$keep_id, $dup_id]);
            $portal = array_column($row->fetchAll(), 'portal_user_id', 'id');
            if (empty($portal[$keep_id]) && !empty($portal[$dup_id])) {
                db()->prepare('UPDATE students SET portal_user_id = NULL WHERE id = ?')->execute([$dup_id]);
                db()->prepare('UPDATE students SET portal_user_id = ? WHERE id = ?')
                    ->execute([$portal[$dup_id], $keep_id]);
            }
        }

        // 2. Re-point all related records to the kept student. UPDATE IGNORE
        //    skips rows that would violate a unique key (e.g. the kept student
        //    already has a fee package); those leftovers are removed when the
        //    duplicate is deleted via ON DELETE CASCADE / SET NULL.
        foreach (sm_student_child_tables() as [$table, $column]) {
            db()->prepare("UPDATE IGNORE `$table` SET `$column` = ? WHERE `$column` = ?")
                ->execute([$keep_id, $dup_id]);
        }

        // 3. Delete the duplicate (cascades clean any remaining children).
        db()->prepare('DELETE FROM students WHERE id = ?')->execute([$dup_id]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    // Remove the duplicate's photo file when it was not transferred.
    if (!$photo_transferred && $dup['photo']) {
        $still_used = db()->prepare('SELECT COUNT(*) FROM students WHERE photo = ?');
        $still_used->execute([$dup['photo']]);
        if ((int)$still_used->fetchColumn() === 0) {
            $p = UPLOAD_DIR . '/students/photos/' . $dup['photo'];
            if (is_file($p)) @unlink($p);
        }
    }

    $label = $keep['full_name'] . ' (' . $keep['student_id'] . ')';
    log_change(
        'students', 'DELETE', $dup_id,
        $dup['full_name'] . ' (' . $dup['student_id'] . ')',
        null, null, null,
        'Duplicate merged into ' . $label . ' and deleted.'
    );
}

// ── Handle merge POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $pairs   = sm_find_duplicate_pairs();
    $by_dup  = [];
    foreach ($pairs as $p) {
        $by_dup[(int)$p['dup']['id']] = $p;
    }

    $targets = [];
    if (($_POST['action'] ?? '') === 'merge_all') {
        $targets = array_keys($by_dup);
    } else {
        $dup_id = (int)($_POST['dup_id'] ?? 0);
        if (isset($by_dup[$dup_id])) {
            $targets = [$dup_id];
        }
    }

    if (!$targets) {
        flash_set('error', 'No matching duplicate pair found. The list may have changed — please review again.');
        redirect(APP_URL . '/students/merge-duplicates.php');
    }

    $done = 0;
    $fail = 0;
    foreach ($targets as $dup_id) {
        $p = $by_dup[$dup_id];
        try {
            sm_merge_students($p['keep'], $p['dup']);
            $done++;
        } catch (Throwable $e) {
            $fail++;
        }
    }

    if ($done > 0) {
        flash_set('success', $done . ' duplicate' . ($done === 1 ? '' : 's') . ' merged successfully.'
            . ($fail ? ' ' . $fail . ' failed — please retry.' : ''));
    } else {
        flash_set('error', 'Merge failed. Please try again.');
    }
    redirect(APP_URL . '/students/merge-duplicates.php');
}

$pairs = sm_find_duplicate_pairs();

/** Counts how many merge-eligible columns are filled on a record. */
function sm_info_score(array $s): array
{
    $filled = 0;
    $cols   = sm_merge_columns();
    foreach ($cols as $col) {
        if (isset($s[$col]) && trim((string)$s[$col]) !== '') $filled++;
    }
    return [$filled, count($cols)];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/students/index.php">Students</a></li>
            <li class="breadcrumb-item active">Merge Duplicates</li>
        </ol>
    </nav>
    <?php if ($pairs): ?>
    <form method="post" onsubmit="return confirm('Merge ALL <?= count($pairs) ?> duplicate pairs? Missing info will be copied to the kept records and the duplicates will be permanently deleted. This cannot be undone.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="merge_all">
        <button type="submit" class="btn btn-danger" style="border-radius:10px;font-size:.875rem;">
            <i class="fas fa-compress-arrows-alt me-1"></i> Merge All (<?= count($pairs) ?>)
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($msg = flash_get('success')): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= $msg ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="alert alert-info" style="border-radius:10px;">
    <i class="fas fa-info-circle me-1"></i>
    This tool finds student IDs that are identical except for leading zeros
    (e.g. <code>02826205101010</code> and <code>2826205101010</code>).
    The record with the fee package (normally the ID starting with <code>0</code>) is <strong>kept</strong>;
    missing info such as photos and personal details is copied from the duplicate,
    all related records (files, comments, payments, results, etc.) are moved to the kept record,
    and the duplicate is then <strong>deleted</strong>.
</div>

<?php if (!$pairs): ?>
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
        <div class="card-body text-center py-5 text-muted">
            <i class="fas fa-check-circle fa-2x mb-3 text-success d-block"></i>
            No leading-zero duplicate student IDs were found.
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm" style="border-radius:12px;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Keep (target)</th>
                    <th>Duplicate (will be deleted)</th>
                    <th class="text-center">Info Filled</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pairs as $p):
                    $keep = $p['keep'];
                    $dup  = $p['dup'];
                    [$kf, $total] = sm_info_score($keep);
                    [$df]         = sm_info_score($dup);
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$keep['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= h($keep['full_name']) ?>
                        </a>
                        <div class="small text-muted font-monospace"><?= h($keep['student_id']) ?></div>
                        <?php if ($keep['has_pkg']): ?>
                            <span class="badge bg-success-subtle text-success border">Fee package</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$dup['id'] ?>" class="text-decoration-none">
                            <?= h($dup['full_name']) ?>
                        </a>
                        <div class="small text-muted font-monospace"><?= h($dup['student_id']) ?></div>
                        <?php if ($dup['has_pkg']): ?>
                            <span class="badge bg-warning-subtle text-warning border">Fee package</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center small">
                        Keep: <?= $kf ?>/<?= $total ?> &nbsp;·&nbsp; Dup: <?= $df ?>/<?= $total ?>
                    </td>
                    <td class="text-end">
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Merge <?= h($dup['student_id']) ?> into <?= h($keep['student_id']) ?>?\n\nMissing info will be copied to <?= h($keep['student_id']) ?> and <?= h($dup['student_id']) ?> will be permanently deleted. This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="dup_id" value="<?= (int)$dup['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:7px;">
                                <i class="fas fa-compress-arrows-alt me-1"></i> Merge &amp; Delete
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
