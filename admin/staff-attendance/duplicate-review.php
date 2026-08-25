<?php
/**
 * Staff Attendance – Duplicate ID Review & Auto-Fix.
 *
 * Investigates the "one person's clock in/out showing under two or three
 * accounts" problem. That happens when:
 *   • the same device user ID (PIN) — or a leading-zero variant of it — is
 *     actively mapped to MORE THAN ONE staff account (att_device_users), so
 *     punches credit different accounts depending on which mapping wins; or
 *   • punches were stored (att_punch_log.user_id) under an OLD mapping and
 *     the mapping was later corrected, leaving historical punches (and their
 *     folded att_records days) on the wrong account; or
 *   • the exact same physical punch (same device + same second) exists under
 *     multiple accounts through PIN variants.
 *
 * The page previews all three cases read-only, lets the admin pick the right
 * owner for each conflicting ID, and offers a one-click auto-fix that:
 *   1. re-points every punch to the CURRENT mapping's staff account,
 *   2. re-folds every affected user/day into att_records,
 *   3. deletes device-created att_records rows for days that no longer have
 *      any punches (manually entered rows are never deleted, only reported).
 *
 * Unmapped IDs are flagged but never guessed — map them on the Devices page.
 * Leading-zero ID variants can be merged with devices-merge-pins.php.
 *
 * Requires can_edit (module admin), same as devices.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adms-helpers.php';

$page_title = 'Duplicate ID Review';
$db         = db();

/** Normalise a numeric device ID (strip leading zeros) for variant matching. */
$norm_pin = static function (string $pin): string {
    $pin = trim($pin);
    if (!preg_match('/^\d+$/', $pin)) return $pin;
    $n = ltrim($pin, '0');
    return $n === '' ? '0' : $n;
};

/**
 * Auto-fix: re-point punches to the CURRENT mapping and rebuild attendance.
 * $only_pin limits the pass to one exact PIN (used after resolving a conflict).
 * Returns counters for the flash message.
 */
$resync = static function (?string $only_pin = null) use ($db): array {
    $stats = ['checked' => 0, 'repinned' => 0, 'refolded' => 0, 'orphans_removed' => 0, 'kept_manual' => 0];
    $sys   = adms_system_user_id();
    try {
        $sql    = 'SELECT DISTINCT device_id, pin FROM att_punch_log';
        $params = [];
        if ($only_pin !== null) { $sql .= ' WHERE pin = ?'; $params[] = $only_pin; }
        $st = $db->prepare($sql);
        $st->execute($params);
        $combos = $st->fetchAll();
    } catch (Throwable $e) {
        return $stats; // punch log table missing – nothing to fix
    }

    $refold = []; // "uid|date" => [uid, date] — every user/day touched by a move
    foreach ($combos as $c) {
        $stats['checked']++;
        $dev = (int)$c['device_id'];
        $pin = (string)$c['pin'];
        $expected = adms_pin_to_user($dev, $pin);
        if ($expected === null) continue; // unmapped – flagged below, never guessed

        $st = $db->prepare(
            'SELECT DISTINCT user_id, work_date FROM att_punch_log
              WHERE device_id = ? AND pin = ? AND (user_id IS NULL OR user_id <> ?)'
        );
        $st->execute([$dev, $pin, $expected]);
        $wrong = $st->fetchAll();
        if (!$wrong) continue;

        $upd = $db->prepare(
            'UPDATE att_punch_log SET user_id = ?
              WHERE device_id = ? AND pin = ? AND (user_id IS NULL OR user_id <> ?)'
        );
        $upd->execute([$expected, $dev, $pin, $expected]);
        $stats['repinned'] += $upd->rowCount();

        foreach ($wrong as $w) {
            $d = (string)$w['work_date'];
            if (!empty($w['user_id'])) $refold[(int)$w['user_id'] . '|' . $d] = [(int)$w['user_id'], $d];
            $refold[$expected . '|' . $d] = [$expected, $d];
        }
    }

    foreach ($refold as [$uid, $d]) {
        $st = $db->prepare('SELECT COUNT(*) FROM att_punch_log WHERE user_id = ? AND work_date = ?');
        $st->execute([$uid, $d]);
        if ((int)$st->fetchColumn() > 0) {
            adms_fold_day($uid, $d);
            $stats['refolded']++;
        } else {
            // The day was emptied for this user: remove the device-created
            // record; NEVER delete a manually entered one — report it instead.
            $st = $db->prepare('SELECT id, created_by FROM att_records WHERE user_id = ? AND work_date = ?');
            $st->execute([$uid, $d]);
            if ($r = $st->fetch()) {
                if ((int)$r['created_by'] === $sys) {
                    $db->prepare('DELETE FROM att_records WHERE id = ?')->execute([(int)$r['id']]);
                    $stats['orphans_removed']++;
                } else {
                    $stats['kept_manual']++;
                }
            }
        }
    }
    return $stats;
};

// ── Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'resync') {
        $s = $resync(null);
        log_change('staff-attendance', 'UPDATE', 0, 'Duplicate ID auto-fix', null, null,
            'repinned=' . $s['repinned'] . ', refolded=' . $s['refolded'] . ', orphans=' . $s['orphans_removed']);
        flash_set('success',
            'Auto-fix complete: ' . $s['repinned'] . ' punch(es) moved to the correct account, '
            . $s['refolded'] . ' day(s) recalculated, ' . $s['orphans_removed'] . ' orphan day record(s) removed'
            . ($s['kept_manual'] > 0 ? ', ' . $s['kept_manual'] . ' manually-entered day(s) kept for review' : '') . '.');
        redirect(APP_URL . '/staff-attendance/duplicate-review.php');
    }

    if ($act === 'resolve_pin') {
        $pin  = trim((string)($_POST['pin'] ?? ''));
        $keep = (int)($_POST['keep_user_id'] ?? 0);
        if ($pin === '' || $keep < 1) {
            flash_set('error', 'Choose which staff member this ID belongs to.');
        } else {
            $norm = $norm_pin($pin);
            // Deactivate every other active mapping of this ID (incl. zero-variants).
            $st = $db->prepare(
                "SELECT id, pin, user_id FROM att_device_users\n                  WHERE is_active = 1 AND TRIM(LEADING '0' FROM pin) = TRIM(LEADING '0' FROM ?)"
            );
            $st->execute([$pin]);
            $off = 0;
            foreach ($st->fetchAll() as $m) {
                if ((int)$m['user_id'] !== $keep) {
                    $db->prepare('UPDATE att_device_users SET is_active = 0 WHERE id = ?')->execute([(int)$m['id']]);
                    $off++;
                }
            }
            // Re-sync punches for every variant of this ID in the punch log.
            $tot = ['repinned' => 0, 'refolded' => 0, 'orphans_removed' => 0];
            try {
                $st = $db->prepare("SELECT DISTINCT pin FROM att_punch_log WHERE TRIM(LEADING '0' FROM pin) = TRIM(LEADING '0' FROM ?)");
                $st->execute([$pin]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vp) {
                    $s = $resync((string)$vp);
                    $tot['repinned']        += $s['repinned'];
                    $tot['refolded']        += $s['refolded'];
                    $tot['orphans_removed'] += $s['orphans_removed'];
                }
            } catch (Throwable $e) { /* punch log missing */ }
            log_change('staff-attendance', 'UPDATE', $keep, 'Duplicate ID resolved: ' . $pin, null, null,
                'deactivated=' . $off . ', repinned=' . $tot['repinned']);
            flash_set('success', 'ID ' . h($pin) . ' resolved: ' . $off . ' conflicting mapping(s) deactivated, '
                . $tot['repinned'] . ' punch(es) moved, ' . $tot['refolded'] . ' day(s) recalculated, '
                . $tot['orphans_removed'] . ' orphan record(s) removed.');
        }
        redirect(APP_URL . '/staff-attendance/duplicate-review.php');
    }
}

// ── Detection (read-only) ───────────────────────────────────────────────────
$names = [];
try {
    foreach ($db->query('SELECT id, full_name FROM users')->fetchAll() as $r) {
        $names[(int)$r['id']] = (string)$r['full_name'];
    }
} catch (Throwable $e) { /* ignore */ }
$uname = static fn(?int $uid): string => $uid ? ($names[$uid] ?? ('User #' . $uid)) : 'Unmapped';

// 1. Shared IDs: one device ID (after zero-normalisation) actively mapped to 2+ staff.
$shared = []; // norm => ['pins' => [...], 'rows' => mapping rows, 'users' => uid => name]
try {
    $rows = $db->query(
        'SELECT m.id, m.device_id, m.pin, m.user_id, u.full_name, d.name AS device_name\n           FROM att_device_users m\n      LEFT JOIN users u ON u.id = m.user_id\n      LEFT JOIN att_devices d ON d.id = m.device_id\n          WHERE m.is_active = 1'
    )->fetchAll();
    $by_norm = [];
    foreach ($rows as $r) $by_norm[$norm_pin((string)$r['pin'])][] = $r;
    foreach ($by_norm as $norm => $grp) {
        $uids = array_values(array_unique(array_map(static fn($g) => (int)$g['user_id'], $grp)));
        if (count($uids) > 1) {
            $shared[(string)$norm] = ['rows' => $grp, 'uids' => $uids];
        }
    }
} catch (Throwable $e) { /* device tables missing */ }

// 2. Wrong-owner punches: stored user differs from the current mapping.
$mismatches = [];
try {
    $rows = $db->query(
        'SELECT device_id, pin, user_id, COUNT(*) c, MIN(work_date) d1, MAX(work_date) d2\n           FROM att_punch_log GROUP BY device_id, pin, user_id'
    )->fetchAll();
    foreach ($rows as $r) {
        $expected = adms_pin_to_user((int)$r['device_id'], (string)$r['pin']);
        $stored   = $r['user_id'] !== null ? (int)$r['user_id'] : null;
        if ($expected === $stored) continue;
        $mismatches[] = [
            'device_id' => (int)$r['device_id'],
            'pin'       => (string)$r['pin'],
            'stored'    => $stored,
            'expected'  => $expected,
            'count'     => (int)$r['c'],
            'from'      => (string)$r['d1'],
            'to'        => (string)$r['d2'],
        ];
    }
} catch (Throwable $e) { /* punch log missing */ }

// 3. Same physical punch (device + exact second) credited to multiple accounts.
$twins = [];
try {
    $twins = $db->query(
        "SELECT device_id, punch_time,\n                GROUP_CONCAT(DISTINCT pin ORDER BY pin SEPARATOR ', ') AS pins,\n                GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS uids\n           FROM att_punch_log\n          WHERE user_id IS NOT NULL\n          GROUP BY device_id, punch_time\n         HAVING COUNT(DISTINCT user_id) > 1\n          ORDER BY punch_time DESC\n          LIMIT 100"
    )->fetchAll();
} catch (Throwable $e) { /* punch log missing */ }

$issues = count($shared) + count($mismatches) + count($twins);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item active">Duplicate ID Review</li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/staff-attendance/devices.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-fingerprint me-1"></i> Devices</a>
        <a href="<?= APP_URL ?>/staff-attendance/devices-merge-pins.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-object-group me-1"></i> Merge Duplicate IDs</a>
    </div>
</div>

<?= flash_show() ?>

<div class="card mb-3" style="border-radius:12px;border-left:4px solid <?= $issues > 0 ? '#dc3545' : '#198754' ?>;">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="h5 mb-1"><i class="fas fa-people-arrows me-2 text-primary"></i>Duplicate ID Review</h1>
            <div class="small text-muted">
                Finds device IDs shared by multiple staff accounts, punches credited to the wrong account,
                and identical punches collected into two or three accounts.
            </div>
            <div class="mt-2">
                <?php if ($issues > 0): ?>
                <span class="badge bg-danger"><?= $issues ?> issue group(s) flagged</span>
                <?php else: ?>
                <span class="badge bg-success">No duplicate-ID issues detected</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="POST" onsubmit="return confirm('Re-point every punch to its currently mapped staff account and rebuild the affected attendance days?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resync">
            <button class="btn btn-danger"><i class="fas fa-wand-magic-sparkles me-1"></i> Auto-Fix: Re-sync Punch Owners</button>
        </form>
    </div>
</div>

<!-- 1 · Shared device IDs -->
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-triangle-exclamation me-2 text-danger"></i>Device IDs mapped to more than one staff account (<?= count($shared) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($shared)): ?>
        <p class="text-muted small px-4 py-3 mb-0">None found — every active ID is mapped to exactly one staff account.</p>
        <?php else: ?>
        <div class="px-4 pt-3 small text-muted">
            Every punch with these IDs can land on a different account depending on which mapping wins.
            Pick the correct owner — the other mappings are deactivated and all punches for the ID (and its
            leading-zero variants) are moved to the chosen account, then the affected days are rebuilt.
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th class="px-3">Device ID</th><th>Active mappings</th><th style="width:340px;">Correct owner</th>
                </tr></thead>
                <tbody>
                <?php foreach ($shared as $norm => $grp): $first_pin = (string)$grp['rows'][0]['pin']; ?>
                <tr>
                    <td class="px-3 fw-semibold"><?= h($first_pin) ?><?php if ((string)$norm !== $first_pin): ?> <span class="text-muted small">(variants of <?= h((string)$norm) ?>)</span><?php endif; ?></td>
                    <td class="small">
                        <?php foreach ($grp['rows'] as $m): ?>
                        <div><span class="badge bg-light text-dark border"><?= h((string)$m['pin']) ?></span>
                            → <?= h($uname((int)$m['user_id'])) ?>
                            <span class="text-muted">(<?= $m['device_id'] ? h((string)($m['device_name'] ?? ('Device #' . (int)$m['device_id']))) : 'All devices' ?>)</span>
                        </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <form method="POST" class="d-flex gap-2" onsubmit="return confirm('Keep the selected staff member for this ID and deactivate the other mapping(s)?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resolve_pin">
                            <input type="hidden" name="pin" value="<?= h($first_pin) ?>">
                            <select name="keep_user_id" class="form-select form-select-sm" required>
                                <option value="">Choose staff…</option>
                                <?php foreach ($grp['uids'] as $uid): ?>
                                <option value="<?= (int)$uid ?>"><?= h($uname((int)$uid)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-check me-1"></i> Fix</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 2 · Punches on the wrong account -->
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-right-left me-2 text-warning"></i>Punches credited to a different account than the current mapping (<?= count($mismatches) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($mismatches)): ?>
        <p class="text-muted small px-4 py-3 mb-0">None found — every stored punch matches its current ID mapping.</p>
        <?php else: ?>
        <div class="px-4 pt-3 small text-muted">
            These punches were stored under an old or wrong mapping. “Auto-Fix: Re-sync Punch Owners” moves them
            to the mapped account and rebuilds the affected days. Rows showing <em>Unmapped</em> as the correct owner
            need the ID mapped on the <a href="<?= APP_URL ?>/staff-attendance/devices.php">Devices</a> page first — they are never guessed.
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th class="px-3">Device ID</th><th>Punches</th><th>Date range</th><th>Currently credited to</th><th>Correct owner (current mapping)</th>
                </tr></thead>
                <tbody>
                <?php foreach ($mismatches as $m): ?>
                <tr>
                    <td class="px-3 fw-semibold"><?= h($m['pin']) ?> <span class="text-muted small">(<?= $m['device_id'] ? 'Device #' . (int)$m['device_id'] : 'unknown device' ?>)</span></td>
                    <td><?= (int)$m['count'] ?></td>
                    <td class="small"><?= h(date('d M Y', strtotime($m['from']))) ?> – <?= h(date('d M Y', strtotime($m['to']))) ?></td>
                    <td><?= h($uname($m['stored'])) ?></td>
                    <td><?php if ($m['expected'] !== null): ?><span class="fw-semibold"><?= h($uname($m['expected'])) ?></span><?php else: ?><span class="badge bg-warning text-dark">Unmapped — map on Devices</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 3 · Identical punches under multiple accounts -->
<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-clone me-2 text-info"></i>Identical punches collected into multiple accounts (latest <?= count($twins) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <?php if (empty($twins)): ?>
        <p class="text-muted small px-4 py-3 mb-0">None found — no single device punch is credited to more than one account.</p>
        <?php else: ?>
        <div class="px-4 pt-3 small text-muted">
            The same device punch (same device, same second) exists under different accounts — usually caused by
            leading-zero ID variants each mapped to someone. Resolve the shared IDs above, or merge the variants with
            <a href="<?= APP_URL ?>/staff-attendance/devices-merge-pins.php">Merge Duplicate Device User IDs</a>, then run the auto-fix.
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th class="px-3">Punch time</th><th>Device</th><th>IDs</th><th>Credited accounts</th>
                </tr></thead>
                <tbody>
                <?php foreach ($twins as $t): ?>
                <tr>
                    <td class="px-3"><?= h(date('d M Y, g:i:s A', strtotime((string)$t['punch_time']))) ?></td>
                    <td>Device #<?= (int)$t['device_id'] ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h((string)$t['pins']) ?></span></td>
                    <td class="small"><?php
                        $parts = [];
                        foreach (explode(',', (string)$t['uids']) as $uid) $parts[] = h($uname((int)$uid));
                        echo implode(' · ', $parts);
                    ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
