<?php
/**
 * Staff Attendance → Devices → Bulk Map (CSV).
 *
 * Upload a CSV with two columns — the device enrollment id ("Device User ID")
 * and the staff member's full name ("Staff Member") — and map each id to the
 * matching ERP user. This mirrors the single-row mapper on devices.php but
 * processes many rows at once.
 *
 * Name matching is forgiving of small differences (the CSV is often exported
 * from a different system):
 *   • Exact            — case/whitespace-insensitive (e.g. "Ms. N Islam").
 *   • Punctuation/case — dots and separators ignored, so "Md. Jowel Rana" and
 *     "Md Jowel Rana" (or "Dr.khurshida Pervin" vs "Dr Khurshida Pervin") match.
 *   • Title/prefix     — leading honorifics (Prof./Dr./Md./Mr. …) are ignored,
 *     so "Prof. Dr. Momtaz Begum Momo" matches "Dr. Momtaz Begum Momo".
 * A name that resolves to more than one account is treated as ambiguous rather
 * than guessed, and names that match nothing are reported as missing.
 *
 * Two-step flow (per request — show a preview before importing):
 *   1. Upload the CSV → a PREVIEW is shown listing exactly what would change,
 *      including the matched staff member and how each row was matched. Nothing
 *      is written to the database at this stage.
 *   2. Confirm → the previewed plan is applied and an import report is shown.
 *
 * Rules implemented (per request):
 *   • Duplicate ids and duplicate names within the CSV are ignored (the first
 *     occurrence of a given id wins; identical repeats are dropped silently).
 *   • Rows whose id is already mapped to the same staff member are left as-is
 *     (reported as "already mapped"); a changed target updates the mapping.
 *   • An id repeated with a different name is flagged as a conflict (not guessed).
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

// ---------------------------------------------------------------------------
// Name normalisation + fuzzy matching helpers.
// ---------------------------------------------------------------------------

// Exact key: lower-cased, trimmed, whitespace collapsed (keeps punctuation).
$key_exact = static function (string $s): string {
    return preg_replace('/\s+/', ' ', strtolower(trim($s)));
};
// Normalised key: punctuation/separators become spaces, so "Md." == "Md" and
// "Dr.khurshida" == "Dr khurshida".
$key_norm = static function (string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
};
// Leading honorifics/name-prefixes to ignore when matching. Only stripped from
// the FRONT of the name (never from the middle, so "Begum" in a real name stays).
$title_words = ['prof', 'professor', 'dr', 'doctor', 'mr', 'mister', 'mrs', 'ms', 'miss',
    'mst', 'most', 'engr', 'eng', 'md', 'mohammad', 'mohammed', 'muhammad', 'muhammed'];
$key_core = static function (string $norm) use ($title_words): string {
    $parts = $norm === '' ? [] : explode(' ', $norm);
    while (count($parts) > 1 && in_array($parts[0], $title_words, true)) {
        array_shift($parts);
    }
    return implode(' ', $parts);
};

// Human-friendly label for each match tier.
$match_label = [
    'exact'      => 'Exact',
    'normalized' => 'Punctuation/case',
    'fuzzy'      => 'Title/prefix',
];

// Build a fresh set of name indexes from the same pool devices.php offers.
$build_indexes = static function () use ($key_exact, $key_norm, $key_core): array {
    $exact = $norm = $core = [];
    $names = []; // id => full_name
    foreach (att_mappable_users() as $u) {
        $id   = (int)$u['id'];
        $full = (string)$u['full_name'];
        $names[$id] = $full;

        $ek = $key_exact($full);
        if ($ek !== '') $exact[$ek][$id] = true;
        $nk = $key_norm($full);
        if ($nk !== '') $norm[$nk][$id] = true;
        $ck = $key_core($nk);
        if ($ck !== '') $core[$ck][$id] = true;
    }
    return [$exact, $norm, $core, $names];
};

// Header aliases we accept for each logical column (normalised: lower, spaces
// and separators collapsed to single spaces). Keeps the CSV forgiving.
$normalize_header = static function (string $header): string {
    $h = strtolower(trim($header));
    $h = preg_replace('/[_\-]+/', ' ', $h);
    return preg_replace('/\s+/', ' ', (string)$h);
};
$pin_aliases = ['device user id', 'device userid', 'user id', 'userid', 'pin', 'enroll id', 'enrollment id', 'device id'];
$name_aliases = ['staff member', 'staff name', 'staff', 'name', 'full name', 'employee name'];

$preview = null; // populated after a successful CSV parse (step 1)
$report  = null; // populated after applying a confirmed plan (step 2)

// ---------------------------------------------------------------------------
// STEP 2 — apply a previously previewed plan.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrf_check();

    $target_device = (int)($_POST['map_device_id'] ?? 0); // 0 = all devices
    $plan = json_decode((string)($_POST['plan'] ?? '[]'), true);
    if (!is_array($plan)) $plan = [];

    // Re-validate every target id against the live staff pool so a tampered or
    // stale hidden plan can never map an id to an arbitrary user.
    $valid_names = [];
    foreach (att_mappable_users() as $u) $valid_names[(int)$u['id']] = (string)$u['full_name'];

    // Existing mappings for the target device so we can tell "already mapped"
    // apart from "updated". device_id = 0 rows apply to all devices.
    $existing = [];
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

    $mapped = $updated = $already = $skipped = [];
    $seen_pins = [];
    foreach ($plan as $p) {
        $pin  = mb_substr(trim((string)($p['pin'] ?? '')), 0, 32);
        $uid  = (int)($p['uid'] ?? 0);
        $name = (string)($valid_names[$uid] ?? ($p['name'] ?? ''));
        if ($pin === '' || !isset($valid_names[$uid])) {
            $skipped[] = ['pin' => $pin, 'name' => (string)($p['name'] ?? '')];
            continue;
        }
        if (isset($seen_pins[$pin])) continue; // first occurrence wins
        $seen_pins[$pin] = true;

        $prev = $existing[$pin] ?? null;
        if ($prev === $uid) {
            $already[] = ['pin' => $pin, 'name' => $name];
            continue;
        }
        try {
            $upsert->execute([$target_device, $pin, $uid]);
        } catch (Throwable $e) {
            $skipped[] = ['pin' => $pin, 'name' => $name];
            continue;
        }
        if ($prev === null) {
            $mapped[] = ['pin' => $pin, 'name' => $name];
        } else {
            $updated[] = ['pin' => $pin, 'name' => $name];
        }
        $existing[$pin] = $uid;
    }

    $applied = count($mapped) + count($updated);
    if ($applied > 0) {
        log_change('staff-attendance', 'IMPORT', $target_device, 'Bulk device-user map (' . $applied . ' rows)');
    }

    $report = [
        'device_id' => $target_device,
        'mapped'    => $mapped,
        'updated'   => $updated,
        'already'   => $already,
        'skipped'   => $skipped,
    ];

    $summary = 'Bulk map done: ' . count($mapped) . ' added, ' . count($updated) . ' updated, '
             . count($already) . ' unchanged';
    if (!empty($skipped)) {
        flash_set('warning', $summary . '. ' . count($skipped) . ' row(s) were skipped (see report below).');
    } else {
        flash_set('success', $summary . '.');
    }
}

// ---------------------------------------------------------------------------
// STEP 1 — parse the uploaded CSV and build a preview (no DB writes).
// ---------------------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        [$idx_exact, $idx_norm, $idx_core, $user_names] = $build_indexes();

        // Resolve a single CSV name to a user id across the match tiers.
        $resolve = static function (string $name) use ($key_exact, $key_norm, $key_core, $idx_exact, $idx_norm, $idx_core): array {
            $ek = $key_exact($name);
            if ($ek !== '' && isset($idx_exact[$ek])) {
                $ids = array_keys($idx_exact[$ek]);
                return count($ids) === 1 ? ['status' => 'ok', 'id' => $ids[0], 'match' => 'exact']
                                         : ['status' => 'ambiguous', 'count' => count($ids)];
            }
            $nk = $key_norm($name);
            if ($nk !== '' && isset($idx_norm[$nk])) {
                $ids = array_keys($idx_norm[$nk]);
                return count($ids) === 1 ? ['status' => 'ok', 'id' => $ids[0], 'match' => 'normalized']
                                         : ['status' => 'ambiguous', 'count' => count($ids)];
            }
            $ck = $key_core($nk);
            if ($ck !== '' && isset($idx_core[$ck])) {
                $ids = array_keys($idx_core[$ck]);
                return count($ids) === 1 ? ['status' => 'ok', 'id' => $ids[0], 'match' => 'fuzzy']
                                         : ['status' => 'ambiguous', 'count' => count($ids)];
            }
            return ['status' => 'missing'];
        };

        // Existing mappings for the target device so the preview can tell
        // "already mapped" apart from "would update".
        $existing = [];
        try {
            $st = $db->prepare('SELECT pin, user_id FROM att_device_users WHERE device_id = ?');
            $st->execute([$target_device]);
            foreach ($st->fetchAll() as $r) $existing[(string)$r['pin']] = (int)$r['user_id'];
        } catch (Throwable $e) {
            $existing = [];
        }

        $seen_pins   = [];   // pin => name (first occurrence, for duplicate detection)
        $to_map      = [];   // rows that would be newly inserted
        $to_update   = [];   // rows whose target would change
        $already     = [];   // rows already mapped to the same user
        $missing     = [];   // names not found in the staff pool
        $ambiguous   = [];   // names matching more than one account
        $conflicts   = [];   // same id repeated with a different name
        $invalid     = [];   // rows missing an id or a name
        $dup_skipped = 0;    // identical id repeats dropped silently
        $row_no      = 1;

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

            $res = $resolve($name);
            if ($res['status'] === 'missing') {
                $missing[] = ['pin' => $pin, 'name' => $name];
                continue;
            }
            if ($res['status'] === 'ambiguous') {
                $ambiguous[] = ['pin' => $pin, 'name' => $name, 'count' => (int)$res['count']];
                continue;
            }

            $user_id = (int)$res['id'];
            $matched = (string)($user_names[$user_id] ?? $name);
            $mtype   = (string)$res['match'];
            $prev    = $existing[$pin] ?? null;

            $entry = ['pin' => $pin, 'name' => $name, 'matched' => $matched,
                      'match' => $match_label[$mtype] ?? $mtype, 'uid' => $user_id];

            if ($prev === $user_id) {
                $already[] = $entry;
            } elseif ($prev === null) {
                $to_map[] = $entry;
            } else {
                $to_update[] = $entry;
            }
            $existing[$pin] = $user_id; // keep in sync for later duplicates
        }

        // The plan carried to the confirm step: only rows that would change.
        $plan = [];
        foreach (array_merge($to_map, $to_update) as $e) {
            $plan[] = ['pin' => $e['pin'], 'uid' => $e['uid'], 'name' => $e['matched']];
        }

        $preview = [
            'device_id'   => $target_device,
            'to_map'      => $to_map,
            'to_update'   => $to_update,
            'already'     => $already,
            'missing'     => $missing,
            'ambiguous'   => $ambiguous,
            'conflicts'   => $conflicts,
            'invalid'     => $invalid,
            'dup_skipped' => $dup_skipped,
            'plan'        => $plan,
        ];

        $changes    = count($to_map) + count($to_update);
        $unresolved = count($missing) + count($ambiguous) + count($conflicts) + count($invalid);
        $msg = 'Preview ready: ' . count($to_map) . ' to add, ' . count($to_update) . ' to update, '
             . count($already) . ' unchanged.';
        if ($unresolved > 0) $msg .= ' ' . $unresolved . ' row(s) need attention.';
        if ($changes > 0) {
            $msg .= ' Review below, then confirm to apply.';
        } else {
            $msg .= ' Nothing to apply.';
        }
        flash_set('info', $msg);
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

// Shared table renderer for report/preview sections.
$labels = [
    'row' => 'Row', 'pin' => 'Device User ID', 'name' => 'Name in CSV',
    'matched' => 'Matched Staff Member', 'match' => 'Matched By',
    'count' => 'Matches', 'first' => 'First name for this ID',
];
$render_sections = static function (array $data, array $sections) use ($labels): void {
    foreach ($sections as $sec) {
        $rows = $data[$sec['key']] ?? [];
        if (empty($rows)) continue; ?>
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
    <?php }
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
                    <input type="hidden" name="action" value="preview">
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
                            <i class="fas fa-eye me-1"></i> Preview
                        </button>
                        <span class="small text-muted ms-2">You'll see exactly what changes before anything is saved.</span>
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
                    <li><code>Staff Member</code> — the staff member's full name.</li>
                </ul>
                <p class="mb-2 small text-muted">Names are matched to active staff accounts allowing for small differences — case, punctuation (<code>Md.</code> vs <code>Md</code>), and leading titles (<code>Prof. Dr.</code> vs <code>Dr.</code>). A name matching more than one account is flagged as ambiguous rather than guessed. Duplicate ids are ignored, and already-mapped ids are left unchanged.</p>
                <hr>
                <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">Device User ID,Staff Member
101,Dr. A Rahman
102,Ms. N Islam</pre>
            </div>
        </div>
    </div>
</div>

<?php if ($preview !== null): ?>
    <?php
        $preview_sections = [
            ['key' => 'to_map',    'title' => 'Will Be Mapped',      'class' => 'success', 'cols' => ['pin', 'name', 'matched', 'match']],
            ['key' => 'to_update', 'title' => 'Will Be Updated',     'class' => 'info',    'cols' => ['pin', 'name', 'matched', 'match']],
            ['key' => 'already',   'title' => 'Already Mapped (no change)', 'class' => 'secondary', 'cols' => ['pin', 'name', 'matched']],
            ['key' => 'missing',   'title' => 'Missing Staff (not in user list)', 'class' => 'danger', 'cols' => ['pin', 'name']],
            ['key' => 'ambiguous', 'title' => 'Ambiguous Names (matched more than one account)', 'class' => 'warning', 'cols' => ['pin', 'name', 'count']],
            ['key' => 'conflicts', 'title' => 'Conflicting Duplicate IDs', 'class' => 'warning', 'cols' => ['row', 'pin', 'name', 'first']],
            ['key' => 'invalid',   'title' => 'Skipped Rows (missing id or name)', 'class' => 'danger', 'cols' => ['row', 'pin', 'name']],
        ];
        $changes = count($preview['to_map']) + count($preview['to_update']);
    ?>
    <div class="card mt-3" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-eye me-2 text-primary"></i>Preview
                <span class="text-muted small fw-normal">— target: <?= h($device_label((int)$preview['device_id'])) ?></span>
            </h6>
            <?php if ($changes > 0): ?>
                <form method="POST" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="map_device_id" value="<?= (int)$preview['device_id'] ?>">
                    <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                        <i class="fas fa-check me-1"></i> Confirm &amp; Apply (<?= $changes ?>)
                    </button>
                </form>
            <?php else: ?>
                <span class="badge bg-secondary">Nothing to apply</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-success">To add <?= count($preview['to_map']) ?></span>
                <span class="badge bg-info">To update <?= count($preview['to_update']) ?></span>
                <span class="badge bg-secondary">Unchanged <?= count($preview['already']) ?></span>
                <span class="badge bg-danger">Missing <?= count($preview['missing']) ?></span>
                <span class="badge bg-warning text-dark">Ambiguous <?= count($preview['ambiguous']) ?></span>
                <span class="badge bg-warning text-dark">ID conflicts <?= count($preview['conflicts']) ?></span>
                <span class="badge bg-danger">Invalid rows <?= count($preview['invalid']) ?></span>
                <span class="badge bg-light text-dark border">Duplicate ids ignored <?= (int)$preview['dup_skipped'] ?></span>
            </div>
            <?php $render_sections($preview, $preview_sections); ?>
            <?php if ($changes > 0): ?>
                <div class="d-flex justify-content-end pt-2">
                    <form method="POST" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="apply">
                        <input type="hidden" name="map_device_id" value="<?= (int)$preview['device_id'] ?>">
                        <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                        <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                            <i class="fas fa-check me-1"></i> Confirm &amp; Apply (<?= $changes ?>)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($report !== null): ?>
    <?php
        $report_sections = [
            ['key' => 'mapped',  'title' => 'Newly Mapped',     'class' => 'success',   'cols' => ['pin', 'name']],
            ['key' => 'updated', 'title' => 'Updated Mappings', 'class' => 'info',      'cols' => ['pin', 'name']],
            ['key' => 'already', 'title' => 'Already Mapped',   'class' => 'secondary', 'cols' => ['pin', 'name']],
            ['key' => 'skipped', 'title' => 'Skipped',          'class' => 'danger',    'cols' => ['pin', 'name']],
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
                <span class="badge bg-danger">Skipped <?= count($report['skipped']) ?></span>
            </div>
            <?php $render_sections($report, $report_sections); ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
