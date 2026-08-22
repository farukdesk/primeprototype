<?php
/**
 * Semester Drop – Bulk CSV Upload
 * ================================================================
 * Bulk-create semester drops from a CSV. Each row carries:
 *
 *     Student ID, Semester Type, Drop Start, Reason (optional)
 *
 * Semester Type is Bi (blocks 6 months) or Tri (blocks 4 months); dates are
 * accepted in DD/MM/YYYY and several other common formats. The workflow is
 * two steps:
 *
 *   1. Upload → every row is validated and shown in a colour-coded preview.
 *   2. Confirm → only the rows that passed validation are created.
 *
 * A row is SKIPPED (never duplicated) when:
 *   • the student already has an ACTIVE semester drop overlapping the new
 *     window (the "already created" case),
 *   • the student's account is frozen by an active dropout, or
 *   • an earlier row in the same file already covers the window.
 * Re-uploading the same file is therefore safe: everything already created
 * is skipped automatically.
 *
 * Evidence cannot be attached in bulk, so — matching the single-record policy
 * (evidence is mandatory unless recorded by a Super Administrator) — only a
 * Super Administrator can CONFIRM a bulk upload. Any user with create access
 * can still upload and preview.
 */

require_once __DIR__ . '/../includes/auth.php';
require_access('semester-drop', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk Semester Drop Upload';
$db       = db();
$is_super = is_super_admin();
$me       = auth_user();

// ── Sample CSV template download ────────────────────────────────────────
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="semester-drop-bulk-sample.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Semester Type', 'Drop Start', 'Reason'], ',', '"', '\\');
    fputcsv($out, ['02826105101071', 'Bi', '01/07/2026', 'Medical leave'], ',', '"', '\\');
    fputcsv($out, ['02826105101072', 'Tri', '15/08/2026', ''], ',', '"', '\\');
    fclose($out);
    exit;
}

/**
 * Normalise a semester-type cell to 'bi' | 'tri'.
 *
 * Accepts "Bi", "Bi-semester", "6", "Tri", "Tri semester", "4", etc.
 */
function sdb_parse_type(string $raw): ?string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[\s_\-]+/', ' ', $s);
    if ($s === '') {
        return null;
    }
    if ($s === '4' || $s === '4 months' || str_starts_with($s, 'tri')) {
        return 'tri';
    }
    if ($s === '6' || $s === '6 months' || str_starts_with($s, 'bi')) {
        return 'bi';
    }
    return null;
}

/**
 * Parse a date cell in DD/MM/YYYY (preferred) or other common formats.
 */
function sdb_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'j-M-Y', 'd-M-Y', 'd M Y', 'd/m/y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat('!' . $fmt, $raw);
        if ($dt instanceof DateTime) {
            $errs = DateTime::getLastErrors();
            if (!$errs || ($errs['warning_count'] === 0 && $errs['error_count'] === 0)) {
                return $dt->format('Y-m-d');
            }
        }
    }
    return null;
}

/**
 * Look up a student by ID, tolerant of leading zeros (the old ERP and the
 * current system sometimes store the same ID with or without them).
 */
function sdb_lookup_student(string $sid): ?array
{
    $sid = trim($sid);
    if ($sid === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT id, student_id, full_name, status FROM students WHERE student_id = ? LIMIT 1');
    $stmt->execute([$sid]);
    $stu = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($stu) {
        return $stu;
    }
    $stmt = db()->prepare(
        "SELECT id, student_id, full_name, status
           FROM students
          WHERE TRIM(LEADING '0' FROM student_id) = TRIM(LEADING '0' FROM ?)
          LIMIT 1"
    );
    $stmt->execute([$sid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Read CSV text into logical rows keyed by our fields.
 *
 * @return array{rows:array<int,array<string,mixed>>, error:?string}
 */
function sdb_read_csv(string $csv_text): array
{
    $csv_text = preg_replace("/^\xEF\xBB\xBF/", '', $csv_text);
    $csv_text = str_replace(["\r\n", "\r"], "\n", $csv_text);
    $lines = array_values(array_filter(explode("\n", $csv_text), static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return ['rows' => [], 'error' => 'The CSV file is empty.'];
    }
    $parsed = array_map(static fn($l) => str_getcsv($l, ',', '"', ''), $lines);
    $header = array_map(static fn($c) => strtolower(trim((string)$c)), $parsed[0]);

    $find = static function (array $needles) use ($header): ?int {
        foreach ($header as $i => $name) {
            foreach ($needles as $needle) {
                if ($name === $needle || str_contains($name, $needle)) {
                    return $i;
                }
            }
        }
        return null;
    };
    $col_student = $find(['student id', 'studentid', 'student']);
    $col_type    = $find(['semester type', 'type', 'semester']);
    $col_start   = $find(['drop start', 'start', 'date']);
    $col_reason  = $find(['reason', 'note', 'remark']);

    $missing = [];
    if ($col_student === null) { $missing[] = 'Student ID'; }
    if ($col_type === null)    { $missing[] = 'Semester Type'; }
    if ($col_start === null)   { $missing[] = 'Drop Start'; }
    if ($missing) {
        return ['rows' => [], 'error' => 'The CSV header is missing required column(s): ' . implode(', ', $missing)
            . '. Expected columns: Student ID, Semester Type, Drop Start, Reason (optional).'];
    }

    $rows  = [];
    $count = count($parsed);
    for ($i = 1; $i < $count; $i++) {
        $r = $parsed[$i];
        $rows[] = [
            'row_no'     => $i + 1,
            'student_id' => trim((string)($r[$col_student] ?? '')),
            'type'       => trim((string)($r[$col_type] ?? '')),
            'start'      => trim((string)($r[$col_start] ?? '')),
            'reason'     => $col_reason !== null ? trim((string)($r[$col_reason] ?? '')) : '',
        ];
    }
    return ['rows' => $rows, 'error' => null];
}

/**
 * Validate every CSV row against the database and the rows before it.
 *
 * Statuses: 'create' (will be created), 'skip' (already covered — never
 * duplicated), 'invalid' (bad data).
 *
 * @return array{results:array<int,array<string,mixed>>, counts:array<string,int>}
 */
function sdb_validate_rows(array $rows): array
{
    $results = [];
    $counts  = ['create' => 0, 'skip' => 0, 'invalid' => 0];
    $lookup  = [];        // sid string  => student row | null
    $file_windows = [];   // student_pk  => [[start, end, row_no], ...]

    $ov_stmt = db()->prepare(
        "SELECT drop_start, drop_end
           FROM semester_drops
          WHERE student_id = ? AND status = 'active' AND kind = 'drop'
            AND drop_start <= ? AND drop_end >= ?
          LIMIT 1"
    );

    foreach ($rows as $row) {
        // Fully blank lines are silently ignored.
        if ($row['student_id'] === '' && $row['type'] === '' && $row['start'] === '') {
            continue;
        }

        $notes  = [];
        $status = 'create';
        $res    = [
            'student_pk'   => null,
            'student_name' => '',
            'sid'          => $row['student_id'],
            'type'         => null,
            'start'        => null,
            'end'          => null,
            'reason'       => (string)$row['reason'],
        ];

        if (!array_key_exists($row['student_id'], $lookup)) {
            $lookup[$row['student_id']] = sdb_lookup_student($row['student_id']);
        }
        $stu = $lookup[$row['student_id']];
        if (!$stu) {
            $status  = 'invalid';
            $notes[] = 'Student ID not found.';
        } else {
            $res['student_pk']   = (int)$stu['id'];
            $res['student_name'] = (string)$stu['full_name'];
            $res['sid']          = (string)$stu['student_id'];
        }

        $type = sdb_parse_type($row['type']);
        if ($type === null) {
            $status  = 'invalid';
            $notes[] = 'Semester Type must be Bi (6 months) or Tri (4 months).';
        } else {
            $res['type'] = $type;
        }

        $start = sdb_parse_date($row['start']);
        if ($start === null) {
            $status  = 'invalid';
            $notes[] = 'Drop Start is not a valid date (use DD/MM/YYYY).';
        } else {
            $res['start'] = $start;
        }

        if ($status === 'create') {
            $res['end'] = sd_compute_end($start, $type);
            $pk = (int)$res['student_pk'];

            // Frozen accounts never receive a drop.
            if (sd_student_dropped_out($pk)) {
                $status  = 'skip';
                $notes[] = 'Account is frozen by an active dropout — skipped.';
            }

            // Already created: an active drop overlapping this window exists.
            if ($status === 'create') {
                $ov_stmt->execute([$pk, $res['end'], $start]);
                $ov = $ov_stmt->fetch(PDO::FETCH_ASSOC);
                if ($ov) {
                    $status  = 'skip';
                    $notes[] = 'Already has an active semester drop overlapping this window ('
                        . date('d M Y', strtotime((string)$ov['drop_start'])) . ' → '
                        . date('d M Y', strtotime((string)$ov['drop_end'])) . ') — skipped.';
                }
            }

            // Duplicate within the same file: first row wins.
            if ($status === 'create') {
                foreach ($file_windows[$pk] ?? [] as $w) {
                    if ($w[0] <= $res['end'] && $w[1] >= $start) {
                        $status  = 'skip';
                        $notes[] = 'Overlaps an earlier row in this file (row ' . $w[2] . ') — skipped.';
                        break;
                    }
                }
            }

            if ($status === 'create') {
                $file_windows[$pk][] = [$start, $res['end'], $row['row_no']];
                $notes[] = sd_type_label($type) . ' — blocks ' . sd_block_months($type) . ' months ('
                    . date('d M Y', strtotime($start)) . ' → ' . date('d M Y', strtotime($res['end'])) . ').';
            }
        }

        $counts[$status]++;
        $results[] = [
            'row_no'   => (int)$row['row_no'],
            'input'    => $row,
            'status'   => $status,
            'notes'    => $notes,
            'resolved' => $res,
        ];
    }
    return ['results' => $results, 'counts' => $counts];
}

// ── Handle POST (preview / confirm) ─────────────────────────────────────
$errors         = [];
$results        = null;
$counts         = ['create' => 0, 'skip' => 0, 'invalid' => 0];
$csv_b64        = '';
$did_commit     = false;
$commit_summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action   = (string)($_POST['action'] ?? '');
    $csv_text = '';

    if ($action === 'preview') {
        if (!isset($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a CSV file to upload.';
        } elseif ((int)$_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The CSV file is larger than 5 MB.';
        } else {
            $csv_text = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
        }
    } elseif ($action === 'confirm') {
        $csv_text = (string)base64_decode((string)($_POST['csv_data'] ?? ''), true);
        if ($csv_text === '') {
            $errors[] = 'The uploaded CSV could not be recovered. Please upload it again.';
        }
        if (!$is_super) {
            $errors[] = 'Only a Super Administrator can confirm a bulk upload — evidence cannot be attached in bulk, and evidence is mandatory for other users.';
        }
    }

    if (!$errors && $csv_text !== '') {
        $parsed = sdb_read_csv($csv_text);
        if ($parsed['error'] !== null) {
            $errors[] = $parsed['error'];
        } else {
            // Re-validation on confirm guarantees rows created in the meantime
            // (or by a double submit) are skipped, never duplicated.
            $validated = sdb_validate_rows($parsed['rows']);
            $results   = $validated['results'];
            $counts    = $validated['counts'];
            $csv_b64   = base64_encode($csv_text);

            if ($action === 'confirm') {
                $created = 0;
                $failed  = [];
                foreach ($results as $i => $r) {
                    if ($r['status'] !== 'create') {
                        continue;
                    }
                    $res = $r['resolved'];
                    try {
                        sd_create_drop(
                            (int)$res['student_pk'],
                            (string)$res['type'],
                            (string)$res['start'],
                            $res['reason'] !== '' ? (string)$res['reason'] : null,
                            null,           // no evidence in bulk (Super Admin only)
                            (int)$me['id']
                        );
                        $created++;
                        $results[$i]['notes'][] = 'Created.';
                    } catch (Throwable $e) {
                        $failed[] = 'Row ' . $r['row_no'] . ' (' . $res['sid'] . '): ' . $e->getMessage();
                        $results[$i]['status']  = 'invalid';
                        $results[$i]['notes'][] = 'Failed: ' . $e->getMessage();
                        $counts['create']--;
                        $counts['invalid']++;
                    }
                }
                $did_commit     = true;
                $commit_summary = ['created' => $created, 'failed' => $failed];
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size:.83rem;">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/semester-drop/index.php">Semester Drop</a></li>
        <li class="breadcrumb-item active">Bulk CSV Upload</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="fas fa-file-csv me-2 text-warning"></i>Bulk Semester Drop Upload</h1>
    <a href="<?= APP_URL ?>/semester-drop/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i> Back to Semester Drop
    </a>
</div>

<?= flash_show() ?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info">
    <div class="d-flex gap-3">
        <div class="fs-4 text-info"><i class="fas fa-info-circle"></i></div>
        <div class="small flex-grow-1">
            <strong>Create semester drops in bulk from a CSV.</strong>
            <ul class="mb-2 mt-1 ps-3">
                <li>Columns: <code>Student ID</code>, <code>Semester Type</code> (<code>Bi</code> = 6 months, <code>Tri</code> = 4 months), <code>Drop Start</code> (<strong>DD/MM/YYYY</strong>), <code>Reason</code> (optional).</li>
                <li><strong>Safe to re-run:</strong> a row is skipped when the student already has an active semester drop overlapping the window, so nothing is ever duplicated.</li>
                <li>Students frozen by an active <strong>dropout</strong> are skipped; duplicate rows within the file are skipped (first row wins).</li>
                <li>Student IDs are matched with or without leading zeros.</li>
                <li>Dropped months are <strong>deferred, not waived</strong> — exactly like a drop recorded one-by-one.</li>
                <li>Evidence cannot be attached in bulk, so only a <strong>Super Administrator</strong> can confirm the upload.</li>
            </ul>
            <a href="<?= APP_URL ?>/semester-drop/bulk-upload.php?sample=1" class="alert-link">
                <i class="fas fa-download me-1"></i>Download a sample CSV template
            </a>
        </div>
    </div>
</div>

<!-- ── Upload form ── -->
<div class="card mb-4">
    <div class="card-header py-3 fw-semibold">
        <i class="fas fa-upload me-2 text-primary"></i>Upload CSV
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="preview">
            <div class="col-md-8">
                <label class="form-label fw-semibold">CSV File <span class="text-danger">*</span></label>
                <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                <div class="form-text">Columns: Student ID, Semester Type, Drop Start, Reason (optional). Max 5 MB.</div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-warning w-100">
                    <i class="fas fa-search me-1"></i> Preview &amp; Validate
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($results !== null): ?>
<!-- ── Preview / result ── -->
<div class="card mb-4">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fas fa-table me-2 text-primary"></i><?= $did_commit ? 'Upload Result' : 'Preview' ?></span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-success">Will create: <?= (int)$counts['create'] ?></span>
            <span class="badge bg-warning text-dark">Skipped (already exists): <?= (int)$counts['skip'] ?></span>
            <span class="badge bg-danger">Invalid: <?= (int)$counts['invalid'] ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($did_commit && $commit_summary): ?>
        <div class="alert alert-<?= $commit_summary['failed'] ? 'warning' : 'success' ?> m-3">
            <strong><?= (int)$commit_summary['created'] ?></strong> semester drop(s) created.
            <?= (int)$counts['skip'] ?> row(s) skipped (already covered), <?= (int)$counts['invalid'] ?> invalid.
            <?php if (!empty($commit_summary['failed'])): ?>
            <div class="mt-2"><strong>Failures:</strong><ul class="mb-0"><?php foreach ($commit_summary['failed'] as $f): ?><li><?= h($f) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 align-items-center px-3 py-3 border-bottom">
            <div class="btn-group btn-group-sm" role="group" aria-label="Filter preview rows">
                <button type="button" class="btn btn-outline-secondary active" data-sdb-filter="all">All</button>
                <button type="button" class="btn btn-outline-success" data-sdb-filter="create">Create (<?= (int)$counts['create'] ?>)</button>
                <button type="button" class="btn btn-outline-warning" data-sdb-filter="skip">Skipped (<?= (int)$counts['skip'] ?>)</button>
                <button type="button" class="btn btn-outline-danger" data-sdb-filter="invalid">Invalid (<?= (int)$counts['invalid'] ?>)</button>
            </div>
            <input type="search" id="sdb-search" class="form-control form-control-sm" style="max-width: 260px;" placeholder="Search Student ID or name…" aria-label="Search preview rows">
            <span class="small text-muted ms-auto" id="sdb-filter-count"></span>
        </div>
        <div class="table-responsive" style="max-height: 65vh;">
            <table class="table table-sm table-hover mb-0 align-middle" id="sdb-preview-table">
                <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th>#</th>
                        <th>Status</th>
                        <th>Student ID</th>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Drop Start</th>
                        <th>Drop End</th>
                        <th>Reason</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $res = $r['resolved'];
                        $row_class = match ($r['status']) {
                            'create' => 'table-success',
                            'skip'   => 'table-warning',
                            default  => 'table-danger',
                        };
                        $badge = match ($r['status']) {
                            'create' => '<span class="badge bg-success">' . ($did_commit ? 'Created' : 'Create') . '</span>',
                            'skip'   => '<span class="badge bg-warning text-dark">Skipped</span>',
                            default  => '<span class="badge bg-danger">Invalid</span>',
                        };
                    ?>
                    <tr class="<?= $row_class ?>" data-status="<?= h($r['status']) ?>" data-search="<?= h(strtolower((string)$res['sid'] . ' ' . (string)$res['student_name'])) ?>">
                        <td><?= (int)$r['row_no'] ?></td>
                        <td><?= $badge ?></td>
                        <td class="fw-semibold"><?= h((string)$res['sid']) ?></td>
                        <td><?= h($res['student_name'] !== '' ? $res['student_name'] : '—') ?></td>
                        <td><?= $res['type'] !== null ? h(sd_type_label((string)$res['type'])) : h((string)$r['input']['type']) ?></td>
                        <td><?= $res['start'] !== null ? h(date('d M Y', strtotime((string)$res['start']))) : h((string)$r['input']['start']) ?></td>
                        <td><?= $res['end'] !== null ? h(date('d M Y', strtotime((string)$res['end']))) : '—' ?></td>
                        <td class="small"><?= h((string)$res['reason']) ?></td>
                        <td class="small"><?= h(implode(' ', $r['notes'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-center py-2 border-top d-none" id="sdb-more-wrap">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="sdb-show-more"></button>
        </div>
    </div>
    <?php if (!$did_commit): ?>
    <div class="card-footer py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Only the <strong><?= (int)$counts['create'] ?></strong> green row(s) will be created. Skipped and invalid rows are left untouched.
            <?php if (!$is_super): ?>
            <span class="text-danger d-block"><i class="fas fa-lock me-1"></i>Only a Super Administrator can confirm a bulk upload.</span>
            <?php endif; ?>
        </span>
        <form method="post" onsubmit="return confirm('Create <?= (int)$counts['create'] ?> semester drop(s)? Skipped and invalid rows will be left untouched.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="csv_data" value="<?= h($csv_b64) ?>">
            <button type="submit" class="btn btn-warning" <?= ($counts['create'] > 0 && $is_super) ? '' : 'disabled' ?>>
                <i class="fas fa-check me-1"></i> Confirm &amp; Create <?= (int)$counts['create'] ?> Drop(s)
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    var table = document.getElementById('sdb-preview-table');
    if (!table) { return; }
    var rows     = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var buttons  = Array.prototype.slice.call(document.querySelectorAll('[data-sdb-filter]'));
    var search   = document.getElementById('sdb-search');
    var countEl  = document.getElementById('sdb-filter-count');
    var moreWrap = document.getElementById('sdb-more-wrap');
    var moreBtn  = document.getElementById('sdb-show-more');
    var PAGE     = 300;
    var limit    = PAGE;
    var active   = 'all';
    var timer    = null;

    // Only PAGE matching rows are laid out at a time; "Show more" reveals the
    // next chunk, so even very large CSVs never freeze the page.
    function apply() {
        var q = (search && search.value ? search.value : '').trim().toLowerCase();
        var matched = 0, shown = 0;
        rows.forEach(function (tr) {
            var ok = (active === 'all' || tr.getAttribute('data-status') === active)
                && (q === '' || (tr.getAttribute('data-search') || '').indexOf(q) !== -1);
            var show = false;
            if (ok) {
                matched++;
                show = matched <= limit;
            }
            tr.style.display = show ? '' : 'none';
            if (show) { shown++; }
        });
        if (countEl) {
            countEl.textContent = 'Showing ' + shown + ' of ' + matched + ' matching row(s) — ' + rows.length + ' total';
        }
        if (moreWrap && moreBtn) {
            var left = matched - shown;
            moreWrap.classList.toggle('d-none', left <= 0);
            if (left > 0) {
                moreBtn.textContent = 'Show ' + Math.min(PAGE, left) + ' more row(s) (' + left + ' hidden)';
            }
        }
    }

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            active = btn.getAttribute('data-sdb-filter') || 'all';
            limit = PAGE;
            buttons.forEach(function (b) { b.classList.toggle('active', b === btn); });
            apply();
        });
    });
    if (search) {
        search.addEventListener('input', function () {
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(function () { limit = PAGE; apply(); }, 200);
        });
    }
    if (moreBtn) {
        moreBtn.addEventListener('click', function () { limit += PAGE; apply(); });
    }
    apply();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
