<?php
/**
 * Staff Attendance → Devices → Bulk Map (CSV).
 *
 * Upload a CSV with two columns — the device enrollment id ("Device User ID")
 * and the staff member's full name ("Staff Member") — and map each id to the
 * matching ERP user by EXACT name. This mirrors the single-row mapper on
 * devices.php but processes many rows at once.
 *
 * Rules implemented (per request):
 *   • Duplicate ids and duplicate names within the CSV are ignored (the first
 *     occurrence of a given id wins; identical repeats are dropped silently).
 *   • Rows whose id is already mapped to the same staff member are left as-is
 *     (reported as "already mapped"); a changed target updates the mapping.
 *   • Names that don't resolve to exactly one active staff account are reported
 *     back so the operator knows which users are missing / ambiguous.
 *
 * Requires can_edit (module admin), same as devices.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Bulk Map Device Users';
$db         = db();

// Devices for the target dropdown (0 = all devices), same source as devices.php.
$devices = [];
try {
    $devices = $db->query('SELECT id, name, serial_no FROM att_devices ORDER BY name ASC, serial_no ASC')->fetchAll();
} catch (Throwable $e) {
    $devices = [];
}

// Header aliases we accept for each logical column (normalised: lower, spaces
// and separators collapsed to single spaces). Keeps the CSV forgiving.
$normalize_header = static function (string $header): string {
    $h = strtolower(trim($header));
    $h = preg_replace('/[_\-]+/', ' ', $h);
    return preg_replace('/\s+/', ' ', (string)$h);
};
$pin_aliases = ['device user id', 'device userid', 'user id', 'userid', 'pin', 'enroll id', 'enrollment id', 'device id'];
$name_aliases = ['staff member', 'staff name', 'staff', 'name', 'full name', 'employee name'];

$report = null; // populated after a successful parse

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $target_device = (int)($_POST['map_device_id'] ?? 0); // 0 = all devices
    $errors = [];

    if (!isset($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a CSV file to upload.';
    }

    $fh = null;
    if (empty($errors)) {
        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) $errors[] = 'Unable to read the uploaded CSV file.';
    }

    if (empty($errors) && $fh) {
        $headers = fgetcsv($fh, 0, ',', '"', '');
        if ($headers === false || empty($headers)) {
            $errors[] = 'The CSV appears to be empty (no header row).';
        } else {
            // Strip a UTF-8 BOM from the very first header cell if present.
            if (isset($headers[0])) $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);

            $pin_idx = $name_idx = null;
            foreach ($headers as $idx => $header) {
                $key = $normalize_header((string)$header);
                if ($pin_idx === null && in_array($key, $pin_aliases, true))  $pin_idx  = $idx;
                if ($name_idx === null && in_array($key, $name_aliases, true)) $name_idx = $idx;
            }
            if ($pin_idx === null)  $errors[] = 'Could not find a "Device User ID" column in the CSV.';
            if ($name_idx === null) $errors[] = 'Could not find a "Staff Member" column in the CSV.';
        }
    }

    if (empty($errors) && $fh) {
        // Build a name → user index from the same pool devices.php offers, so the
        // matching rules stay consistent. Names are matched case-insensitively on
        // trimmed, whitespace-collapsed values. A name shared by >1 account is
        // treated as ambiguous rather than guessed.
        $name_index = []; // lc name => [user_id, ...]
        foreach (att_mappable_users() as $u) {
            $nk = preg_replace('/\s+/', ' ', strtolower(trim((string)$u['full_name'])));
            if ($nk === '') continue;
            $name_index[$nk][] = (int)$u['id'];
        }

        // Existing mappings for the target device so we can tell "already mapped"
        // apart from "updated". device_id = 0 rows apply to all devices.
        $existing = []; // pin => user_id
        try {
            $st = $db->prepare('SELECT pin, user_id FROM att_device_users WHERE device_id = ?');
            $st->execute([$target_device]);
            foreach ($st->fetchAll() as $r) $existing[(string)$r['pin']] = (int)$r['user_id'];
        } catch (Throwable $e) {
            $existing = [];
        }

        $upsert = $db->prepare(
            'INSERT INTO att_device_users (device_id, pin, user_id, is_active)
             VALUES (?,?,?,1)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_active = 1'
        );

        $seen_pins = [];   // pin => name (first occurrence, for duplicate detection)
        $mapped = [];      // rows newly inserted
        $updated = [];     // rows whose target changed
        $already = [];     // rows already mapped to the same user
        $missing = [];     // names not found in the staff pool
        $ambiguous = [];   // names matching more than one account
        $conflicts = [];   // same id repeated with a different name
        $invalid = [];     // rows missing an id or a name
        $dup_skipped = 0;  // identical id repeats dropped silently
        $row_no = 1;

        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $row_no++;
            $pin  = trim((string)($row[$pin_idx] ?? ''));
            $name = trim((string)($row[$name_idx] ?? ''));
            $name = preg_replace('/\s+/', ' ', $name);

            if ($pin === '' && $name === '') continue; // blank line
            if ($pin === '' || $name === '') {
                $invalid[] = ['row' => $row_no, 'pin' => $pin, 'name' => $name];
                continue;
            }
            $pin = mb_substr($pin, 0, 32);

            // Duplicate id handling: first id wins; identical repeats are ignored,
            // a repeat pointing at a different name is flagged as a conflict.
            if (isset($seen_pins[$pin])) {
                if (strcasecmp($seen_pins[$pin], $name) !== 0) {
                    $conflicts[] = ['row' => $row_no, 'pin' => $pin, 'name' => $name, 'first' => $seen_pins[$pin]];
                } else {
                    $dup_skipped++;
                }
                continue;
            }
            $seen_pins[$pin] = $name;

            $nk = strtolower($name);
            if (!isset($name_index[$nk])) {
                $missing[] = ['pin' => $pin, 'name' => $name];
                continue;
            }
            if (count($name_index[$nk]) > 1) {
                $ambiguous[] = ['pin' => $pin, 'name' => $name, 'count' => count($name_index[$nk])];
                continue;
            }

            $user_id = (int)$name_index[$nk][0];
            $prev    = $existing[$pin] ?? null;

            if ($prev === $user_id) {
                $already[] = ['pin' => $pin, 'name' => $name];
                continue;
            }

            try {
                $upsert->execute([$target_device, $pin, $user_id]);
            } catch (Throwable $e) {
                $invalid[] = ['row' => $row_no, 'pin' => $pin, 'name' => $name];
                continue;
            }

            if ($prev === null) {
                $mapped[] = ['pin' => $pin, 'name' => $name];
            } else {
                $updated[] = ['pin' => $pin, 'name' => $name];
            }
            $existing[$pin] = $user_id; // keep in sync for later duplicates
        }

        $applied = count($mapped) + count($updated);
        if ($applied > 0) {
            log_change('staff-attendance', 'IMPORT', $target_device, 'Bulk device-user map (' . $applied . ' rows)');
        }

        $report = [
            'device_id'   => $target_device,
            'mapped'      => $mapped,
            'updated'     => $updated,
            'already'     => $already,
            'missing'     => $missing,
            'ambiguous'   => $ambiguous,
            'conflicts'   => $conflicts,
            'invalid'     => $invalid,
            'dup_skipped' => $dup_skipped,
        ];

        $summary = 'Bulk map done: ' . count($mapped) . ' added, ' . count($updated) . ' updated, '
                 . count($already) . ' unchanged';
        $unresolved = count($missing) + count($ambiguous) + count($conflicts) + count($invalid);
        if ($unresolved > 0) {
            flash_set('warning', $summary . '. ' . $unresolved . ' row(s) need attention (see report below).');
        } else {
            flash_set('success', $summary . '.');
        }
    }

    if ($fh) fclose($fh);
    foreach ($errors as $e) flash_set('error', $e);
}

require_once __DIR__ . '/../includes/header.php';

$device_label = static function (int $id) use ($devices): string {
    if ($id === 0) return 'All devices';
    foreach ($devices as $d) {
        if ((int)$d['id'] === $id) return $d['name'] !== '' ? $d['name'] : $d['serial_no'];
    }
    return 'Device #' . $id;
};
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/devices.php">Devices</a></li>
            <li class="breadcrumb-item active">Bulk Map</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/staff-attendance/devices.php" class="btn btn-light btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Devices
    </a>
</div>

<?php flash_show(); ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-file-csv me-2 text-primary"></i>Upload Mapping CSV</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <?= csrf_field() ?>
                    <div class="col-sm-5">
                        <label class="form-label small fw-semibold mb-1">Target Device</label>
                        <select name="map_device_id" class="form-select form-select-sm">
                            <option value="0">All devices</option>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= h($d['name'] !== '' ? $d['name'] : $d['serial_no']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-7">
                        <label class="form-label small fw-semibold mb-1">CSV File</label>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm" style="border-radius:10px;">
                            <i class="fas fa-upload me-1"></i> Import &amp; Map
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">CSV Format</h6>
            </div>
            <div class="card-body p-4">
                <p class="mb-2">Two columns are required:</p>
                <ul class="mb-2 ps-3">
                    <li><code>Device User ID</code> — the enrollment id/PIN on the device.</li>
                    <li><code>Staff Member</code> — the staff member's exact full name.</li>
                </ul>
                <p class="mb-2 small text-muted">Names are matched to active staff accounts case-insensitively. Duplicate ids/names are ignored, and already-mapped ids are left unchanged.</p>
                <hr>
                <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">Device User ID,Staff Member
101,Dr. A Rahman
102,Ms. N Islam</pre>
            </div>
        </div>
    </div>
</div>

<?php if ($report !== null): ?>
    <?php
        $sections = [
            ['key' => 'mapped',    'title' => 'Newly Mapped',        'class' => 'success', 'cols' => ['pin', 'name']],
            ['key' => 'updated',   'title' => 'Updated Mappings',    'class' => 'info',    'cols' => ['pin', 'name']],
            ['key' => 'already',   'title' => 'Already Mapped',      'class' => 'secondary','cols' => ['pin', 'name']],
            ['key' => 'missing',   'title' => 'Missing Staff (not in user list)', 'class' => 'danger', 'cols' => ['pin', 'name']],
            ['key' => 'ambiguous', 'title' => 'Ambiguous Names (matched more than one account)', 'class' => 'warning', 'cols' => ['pin', 'name', 'count']],
            ['key' => 'conflicts', 'title' => 'Conflicting Duplicate IDs', 'class' => 'warning', 'cols' => ['row', 'pin', 'name', 'first']],
            ['key' => 'invalid',   'title' => 'Skipped Rows (missing id or name)', 'class' => 'danger', 'cols' => ['row', 'pin', 'name']],
        ];
        $labels = [
            'row' => 'Row', 'pin' => 'Device User ID', 'name' => 'Staff Member',
            'count' => 'Matches', 'first' => 'First name for this ID',
        ];
    ?>
    <div class="card mt-3" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-clipboard-list me-2 text-primary"></i>Import Report
                <span class="text-muted small fw-normal">— target: <?= h($device_label((int)$report['device_id'])) ?></span>
            </h6>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-success">Added <?= count($report['mapped']) ?></span>
                <span class="badge bg-info">Updated <?= count($report['updated']) ?></span>
                <span class="badge bg-secondary">Unchanged <?= count($report['already']) ?></span>
                <span class="badge bg-danger">Missing <?= count($report['missing']) ?></span>
                <span class="badge bg-warning text-dark">Ambiguous <?= count($report['ambiguous']) ?></span>
                <span class="badge bg-warning text-dark">ID conflicts <?= count($report['conflicts']) ?></span>
                <span class="badge bg-danger">Invalid rows <?= count($report['invalid']) ?></span>
                <span class="badge bg-light text-dark border">Duplicate ids ignored <?= (int)$report['dup_skipped'] ?></span>
            </div>

            <?php foreach ($sections as $sec): $rows = $report[$sec['key']]; if (empty($rows)) continue; ?>
                <div class="mb-3">
                    <div class="fw-semibold mb-1 text-<?= $sec['class'] ?>"><?= h($sec['title']) ?> (<?= count($rows) ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light"><tr>
                                <?php foreach ($sec['cols'] as $c): ?><th><?= h($labels[$c] ?? $c) ?></th><?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <?php foreach ($sec['cols'] as $c): ?>
                                        <td class="small"><?= $c === 'pin' ? '<code>' . h((string)($r[$c] ?? '')) . '</code>' : h((string)($r[$c] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
