<?php
/**
 * Staff Attendance → Devices → Auto-Map Unmapped Punches (1-click).
 *
 * One-click helper for the common "I just updated/re-imported staff and now a
 * batch of device punches show as Unmapped" situation. Unlike
 * devices-bulk-map.php this needs no CSV: it scans att_punch_log for punches
 * whose PIN has no resolved user (user_id IS NULL) and tries to match each PIN
 * directly against the active staff Employee ID (staff_profiles.employee_id),
 * which is the enrollment id most devices are configured with.
 *
 * Matching tiers (a name is never guessed — only Employee ID is used):
 *   • Exact    — PIN equals the Employee ID (case/whitespace-insensitive).
 *   • Numeric  — both are purely numeric and equal after stripping leading
 *     zeros, so PIN "0101" matches Employee ID "101".
 * A PIN whose Employee ID matches more than one account is reported as
 * ambiguous rather than guessed; a PIN matching no Employee ID is reported as
 * unmatched (use the single mapper or CSV Bulk Map on devices.php for those).
 *
 * Two-step flow (per request — show a preview before mapping anything):
 *   1. Scan    → lists exactly which PIN would map to which staff member, how
 *      many punches, and which dates are affected. Nothing is written yet.
 *   2. Confirm → applies the plan: saves the Device User ID → Staff mapping,
 *      backfills user_id on the previously unmapped att_punch_log rows, and
 *      re-folds every affected user/day into att_records so the attendance
 *      report reflects the punches immediately.
 *
 * Requires can_edit (module admin), same as devices.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adms-helpers.php';

$page_title = 'Auto-Map Unmapped Punches';
$db         = db();

$devices = [];
try {
    $devices = $db->query('SELECT id, name, serial_no FROM att_devices ORDER BY name ASC, serial_no ASC')->fetchAll();
} catch (Throwable $e) {
    $devices = [];
}

$device_label = static function (int $id) use ($devices): string {
    if ($id === 0) return 'All devices';
    foreach ($devices as $d) {
        if ((int)$d['id'] === $id) return $d['name'] !== '' ? $d['name'] : $d['serial_no'];
    }
    return 'Device #' . $id;
};

// ---------------------------------------------------------------------------
// Matching helpers: PIN ↔ Employee ID (exact, then leading-zero-tolerant).
// ---------------------------------------------------------------------------
$norm_exact = static function (string $s): string {
    return strtolower(trim($s));
};
// "0101" and "101" should match when both are purely numeric.
$norm_numeric = static function (string $s): ?string {
    $s = trim($s);
    if ($s === '' || !ctype_digit($s)) return null;
    $s = ltrim($s, '0');
    return $s === '' ? '0' : $s;
};

$build_employee_index = static function () use ($norm_exact, $norm_numeric): array {
    $exact = $numeric = [];
    $staff = []; // id => ['full_name'=>, 'employee_id'=>]
    foreach (att_mappable_users() as $u) {
        $eid = trim((string)($u['employee_id'] ?? ''));
        if ($eid === '') continue;
        $id = (int)$u['id'];
        $staff[$id] = ['full_name' => (string)$u['full_name'], 'employee_id' => $eid];

        $ek = $norm_exact($eid);
        if ($ek !== '') $exact[$ek][$id] = true;
        $nk = $norm_numeric($eid);
        if ($nk !== null) $numeric[$nk][$id] = true;
    }
    return [$exact, $numeric, $staff];
};

$preview = null; // populated after a scan (step 1)
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
    // stale hidden plan can never map a PIN to an arbitrary user.
    $valid_names = [];
    foreach (att_mappable_users() as $u) $valid_names[(int)$u['id']] = (string)$u['full_name'];

    $upsert_map = $db->prepare(
        'INSERT INTO att_device_users (device_id, pin, user_id, is_active)
         VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_active = 1'
    );
    $update_punches = $db->prepare(
        'UPDATE att_punch_log SET user_id = ? WHERE pin = ? AND user_id IS NULL'
    );
    $affected_days = $db->prepare(
        'SELECT DISTINCT work_date FROM att_punch_log WHERE pin = ? AND user_id = ?'
    );

    $mapped = $skipped = [];
    $folded = []; // "uid|date" => true, avoid re-folding the same day twice

    foreach ($plan as $p) {
        $pin  = mb_substr(trim((string)($p['pin'] ?? '')), 0, 32);
        $uid  = (int)($p['uid'] ?? 0);
        $name = (string)($valid_names[$uid] ?? ($p['name'] ?? ''));
        if ($pin === '' || !isset($valid_names[$uid])) {
            $skipped[] = ['pin' => $pin, 'name' => (string)($p['name'] ?? '')];
            continue;
        }

        $punches_updated = 0;
        try {
            $upsert_map->execute([$target_device, $pin, $uid]);
            $update_punches->execute([$uid, $pin]);
            $punches_updated = $update_punches->rowCount();
        } catch (Throwable $e) {
            $skipped[] = ['pin' => $pin, 'name' => $name];
            continue;
        }

        // Re-fold every affected day so att_records / the attendance report
        // picks the newly-resolved punches up right away.
        try {
            $affected_days->execute([$pin, $uid]);
            foreach ($affected_days->fetchAll() as $r) {
                $wd  = (string)$r['work_date'];
                $key = $uid . '|' . $wd;
                if (isset($folded[$key])) continue;
                $folded[$key] = true;
                adms_fold_day($uid, $wd);
            }
        } catch (Throwable $e) {
            // Folding is best-effort; punches remain stored/mapped either way.
        }

        $mapped[] = ['pin' => $pin, 'name' => $name, 'punches' => $punches_updated];
    }

    $applied = count($mapped);
    if ($applied > 0) {
        log_change('staff-attendance', 'IMPORT', $target_device, 'Auto-map unmapped punches (' . $applied . ' PIN(s))');
    }

    $report = [
        'device_id' => $target_device,
        'mapped'    => $mapped,
        'skipped'   => $skipped,
    ];

    $total_punches = array_sum(array_column($mapped, 'punches'));
    $summary = 'Auto-map done: ' . $applied . ' Device User ID(s) mapped, ' . $total_punches
             . ' punch(es) resolved and re-synced to attendance records.';
    if (!empty($skipped)) {
        flash_set('warning', $summary . ' ' . count($skipped) . ' row(s) were skipped (see report below).');
    } else {
        flash_set('success', $summary);
    }
}

// ---------------------------------------------------------------------------
// STEP 1 — scan unmapped punches and build a preview (no DB writes).
// ---------------------------------------------------------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'scan') {
    csrf_check();
    $target_device = (int)($_POST['map_device_id'] ?? 0); // 0 = all devices

    [$idx_exact, $idx_numeric, $staff_by_id] = $build_employee_index();

    $rows = [];
    try {
        $rows = $db->query(
            "SELECT pin, COUNT(*) AS cnt,
                    MIN(punch_time) AS first_time, MAX(punch_time) AS last_time,
                    COUNT(DISTINCT work_date) AS day_count,
                    GROUP_CONCAT(DISTINCT work_date ORDER BY work_date SEPARATOR ', ') AS work_dates
               FROM att_punch_log
              WHERE user_id IS NULL
           GROUP BY pin
           ORDER BY cnt DESC, pin ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
        flash_set('warning', 'Could not read the punch log – the ADMS tables may be missing.');
    }

    $matched = $ambiguous = $unmatched = [];
    foreach ($rows as $r) {
        $pin   = (string)$r['pin'];
        $dates = (string)$r['work_dates'] !== '' ? explode(', ', (string)$r['work_dates']) : [];
        $more  = 0;
        if (count($dates) > 8) {
            $more  = count($dates) - 8;
            $dates = array_slice($dates, 0, 8);
        }
        $base = [
            'pin' => $pin, 'count' => (int)$r['cnt'], 'day_count' => (int)$r['day_count'],
            'first' => (string)$r['first_time'], 'last' => (string)$r['last_time'],
            'dates' => $dates, 'more' => $more,
        ];

        $ids = null; $mtype = null;
        $ek = $norm_exact($pin);
        if ($ek !== '' && isset($idx_exact[$ek])) {
            $ids   = array_keys($idx_exact[$ek]);
            $mtype = 'Employee ID (exact)';
        } else {
            $nk = $norm_numeric($pin);
            if ($nk !== null && isset($idx_numeric[$nk])) {
                $ids   = array_keys($idx_numeric[$nk]);
                $mtype = 'Employee ID (numeric)';
            }
        }

        if ($ids === null) {
            $unmatched[] = $base;
            continue;
        }
        if (count($ids) > 1) {
            $ambiguous[] = $base + ['count_matches' => count($ids)];
            continue;
        }

        $uid = (int)$ids[0];
        $matched[] = $base + [
            'uid'         => $uid,
            'name'        => $staff_by_id[$uid]['full_name'] ?? ('User #' . $uid),
            'employee_id' => $staff_by_id[$uid]['employee_id'] ?? '',
            'match'       => $mtype,
        ];
    }

    $plan = [];
    foreach ($matched as $m) {
        $plan[] = ['pin' => $m['pin'], 'uid' => $m['uid'], 'name' => $m['name']];
    }

    $preview = [
        'device_id' => $target_device,
        'matched'   => $matched,
        'ambiguous' => $ambiguous,
        'unmatched' => $unmatched,
        'plan'      => $plan,
    ];

    if (empty($rows)) {
        flash_set('success', 'No unmapped punches found — everything is already mapped.');
    } else {
        $matched_punches = array_sum(array_column($matched, 'count'));
        $msg = 'Scan complete: ' . count($matched) . ' Device User ID(s) matched by Employee ID ('
             . $matched_punches . ' punch(es)).';
        if (!empty($ambiguous)) $msg .= ' ' . count($ambiguous) . ' ambiguous.';
        if (!empty($unmatched)) {
            $msg .= ' ' . count($unmatched) . ' unmatched (no Employee ID found) — use the single mapper or CSV Bulk Map for those.';
        }
        flash_set(count($matched) > 0 ? 'info' : 'warning', $msg);
    }
}

require_once __DIR__ . '/../includes/header.php';

// Shared table renderer for the scan / report sections.
$labels = [
    'pin' => 'Device User ID', 'name' => 'Matched Staff Member', 'employee_id' => 'Employee ID',
    'match' => 'Matched By', 'count' => 'Punches', 'count_matches' => 'Matches',
    'day_count' => 'Days', 'range' => 'Punch Dates', 'punches' => 'Punches Resolved',
];
$render_dates = static function (array $row): string {
    $out = h(implode(', ', $row['dates']));
    if (($row['more'] ?? 0) > 0) $out .= ' <span class="text-muted">+' . (int)$row['more'] . ' more</span>';
    return $out;
};
$render_sections = static function (array $data, array $sections) use ($labels, $render_dates): void {
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
                                <td class="small">
                                    <?php if ($c === 'pin'): ?>
                                        <code><?= h((string)($r[$c] ?? '')) ?></code>
                                    <?php elseif ($c === 'range'): ?>
                                        <?= $render_dates($r) ?>
                                    <?php else: ?>
                                        <?= h((string)($r[$c] ?? '')) ?>
                                    <?php endif; ?>
                                </td>
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
            <li class="breadcrumb-item active">Auto-Map</li>
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
                <h6 class="mb-0 fw-semibold"><i class="fas fa-bolt me-2 text-primary"></i>1-Click Scan</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="scan">
                    <div class="col-sm-8">
                        <label class="form-label small fw-semibold mb-1">Save mapping for</label>
                        <select name="map_device_id" class="form-select form-select-sm">
                            <option value="0">All devices</option>
                            <?php foreach ($devices as $d): ?>
                                <option value="<?= (int)$d['id'] ?>"><?= h($d['name'] !== '' ? $d['name'] : $d['serial_no']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Where the new Device User ID → Staff mapping is stored. "All devices" matches the existing mapper's default.</div>
                    </div>
                    <div class="col-sm-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100" style="border-radius:10px;">
                            <i class="fas fa-magnifying-glass me-1"></i> Scan Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">How this works</h6>
            </div>
            <div class="card-body p-4">
                <p class="mb-2 small">No file upload needed. This scans <strong>Recent Punches</strong> marked
                    <span class="badge bg-warning text-dark">Unmapped</span> and matches each Device User ID
                    straight to a staff member's <strong>Employee ID</strong> — exact match, or numeric match
                    ignoring leading zeros (e.g. <code>0101</code> ≈ <code>101</code>).</p>
                <p class="mb-0 small text-muted">Nothing is saved until you review the preview and click
                    Confirm. IDs that don't match any Employee ID are listed separately — map those with the
                    single mapper or <a href="<?= APP_URL ?>/staff-attendance/devices-bulk-map.php">CSV Bulk Map</a>.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($preview !== null): ?>
    <?php
        $preview_sections = [
            ['key' => 'matched',   'title' => 'Will Be Mapped',        'class' => 'success', 'cols' => ['pin', 'name', 'employee_id', 'match', 'count', 'day_count', 'range']],
            ['key' => 'ambiguous', 'title' => 'Ambiguous (matches more than one Employee ID)', 'class' => 'warning', 'cols' => ['pin', 'count_matches', 'count', 'range']],
            ['key' => 'unmatched', 'title' => 'Unmatched (no Employee ID found)', 'class' => 'danger', 'cols' => ['pin', 'count', 'day_count', 'range']],
        ];
        $changes = count($preview['matched']);
    ?>
    <div class="card mt-3" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-eye me-2 text-primary"></i>Preview
                <span class="text-muted small fw-normal">— target: <?= h($device_label((int)$preview['device_id'])) ?></span>
            </h6>
            <?php if ($changes > 0): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('Map ' + <?= $changes ?> + ' Device User ID(s) and resolve their punches now?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="map_device_id" value="<?= (int)$preview['device_id'] ?>">
                    <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                        <i class="fas fa-check me-1"></i> Confirm &amp; Map (<?= $changes ?>)
                    </button>
                </form>
            <?php else: ?>
                <span class="badge bg-secondary">Nothing to map</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-success">Will map <?= count($preview['matched']) ?></span>
                <span class="badge bg-warning text-dark">Ambiguous <?= count($preview['ambiguous']) ?></span>
                <span class="badge bg-danger">Unmatched <?= count($preview['unmatched']) ?></span>
            </div>
            <?php $render_sections($preview, $preview_sections); ?>
            <?php if ($changes > 0): ?>
                <div class="d-flex justify-content-end pt-2">
                    <form method="POST" class="m-0" onsubmit="return confirm('Map ' + <?= $changes ?> + ' Device User ID(s) and resolve their punches now?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="apply">
                        <input type="hidden" name="map_device_id" value="<?= (int)$preview['device_id'] ?>">
                        <input type="hidden" name="plan" value="<?= h(json_encode($preview['plan'])) ?>">
                        <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;">
                            <i class="fas fa-check me-1"></i> Confirm &amp; Map (<?= $changes ?>)
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
            ['key' => 'mapped',  'title' => 'Mapped &amp; Resolved', 'class' => 'success', 'cols' => ['pin', 'name', 'punches']],
            ['key' => 'skipped', 'title' => 'Skipped',               'class' => 'danger',  'cols' => ['pin', 'name']],
        ];
    ?>
    <div class="card mt-3" style="border-radius:12px;">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-clipboard-list me-2 text-primary"></i>Auto-Map Report
                <span class="text-muted small fw-normal">— target: <?= h($device_label((int)$report['device_id'])) ?></span>
            </h6>
        </div>
        <div class="card-body p-4">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="badge bg-success">Mapped <?= count($report['mapped']) ?></span>
                <span class="badge bg-danger">Skipped <?= count($report['skipped']) ?></span>
            </div>
            <?php $render_sections($report, $report_sections); ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
