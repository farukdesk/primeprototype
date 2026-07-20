<?php
/**
 * Staff Attendance → Devices → Merge Duplicate Device User IDs.
 *
 * Punch devices sometimes hold the same person under two IDs that differ only
 * by leading zeros (e.g. 028620 and 28620). This tool:
 *
 *   1. PREVIEW (read-only, shown on page load): detects numeric ID groups that
 *      are identical after stripping leading zeros – across both the
 *      ID→staff mappings and the raw punch log – and lists exactly what a
 *      merge would do (which ID is kept, which are removed, the mapped staff
 *      member, punch counts and overlapping duplicate punches).
 *   2. CONFIRM: merges the selected groups. The zero-prefixed ID is kept:
 *        • punches under the other variants are re-pinned to the kept ID
 *          (punches that already exist under the kept ID at the same
 *          device+time are removed as duplicates – the unique punch key
 *          keeps this idempotent);
 *        • mappings under the other variants are renamed to the kept ID, or
 *          removed when the kept ID is already mapped on the same device;
 *        • user_id is backfilled on all punches of the kept ID and every
 *          affected user/day is re-folded into att_records.
 *
 * Groups that cannot be merged safely are flagged for review, never guessed:
 *   • no variant starts with zero (nothing matches the "zero-padded is right"
 *     rule), • more than one variant starts with zero, • variants are mapped
 *     to different staff members.
 *
 * Requires can_edit (module admin), same as devices.php.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('staff-attendance', 'can_edit');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/adms-helpers.php';

$page_title = 'Merge Duplicate Device User IDs';
$db         = db();

// ---------------------------------------------------------------------------
// Detection – build duplicate-ID groups from live data (read-only).
// ---------------------------------------------------------------------------
$build_groups = static function () use ($db): array {
    $pins = [];
    try {
        $rows = $db->query(
            'SELECT DISTINCT pin FROM att_device_users
             UNION
             SELECT DISTINCT pin FROM att_punch_log'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $p) {
            $p = trim((string)$p);
            if ($p !== '') $pins[$p] = true;
        }
    } catch (Throwable $e) {
        return [];
    }

    // Only purely numeric IDs can differ by leading zeros.
    // Note: PHP converts numeric string array keys (e.g. "28620") to integers,
    // so cast every pin back to string before using it as a string.
    $by_norm = [];
    foreach (array_keys($pins) as $p) {
        $p = (string)$p;
        if (!preg_match('/^\d+$/', $p)) continue;
        $norm = ltrim($p, '0');
        if ($norm === '') $norm = '0';
        $by_norm[$norm][] = $p;
    }

    $groups = [];
    foreach ($by_norm as $norm => $variants) {
        if (count($variants) < 2) continue;
        sort($variants);
        $in = implode(',', array_fill(0, count($variants), '?'));

        // Punch counts per variant.
        $counts = [];
        try {
            $st = $db->prepare("SELECT pin, COUNT(*) c FROM att_punch_log WHERE pin IN ($in) GROUP BY pin");
            $st->execute($variants);
            foreach ($st->fetchAll() as $r) $counts[(string)$r['pin']] = (int)$r['c'];
        } catch (Throwable $e) { /* table missing */ }

        // Mapped staff per variant.
        $users = []; // uid => name
        try {
            $st = $db->prepare(
                "SELECT m.pin, m.user_id, u.full_name
                   FROM att_device_users m
              LEFT JOIN users u ON u.id = m.user_id
                  WHERE m.pin IN ($in)"
            );
            $st->execute($variants);
            foreach ($st->fetchAll() as $m) {
                if ($m['user_id']) $users[(int)$m['user_id']] = (string)($m['full_name'] ?? ('User #' . (int)$m['user_id']));
            }
        } catch (Throwable $e) { /* table missing */ }

        // The kept (canonical) ID is the one that starts with zero.
        $zero = array_values(array_filter($variants, static fn($v) => ((string)$v)[0] === '0'));
        if (count($zero) === 1) {
            $canonical = $zero[0];
            $status    = count($users) > 1 ? 'user_conflict' : 'ready';
        } elseif (count($zero) === 0) {
            $canonical = null;
            $status    = 'no_zero';
        } else {
            $canonical = null;
            $status    = 'multi_zero';
        }

        // Overlapping duplicates: variant punches that already exist under the
        // kept ID at the same device + timestamp (they would be removed).
        $overlap = 0;
        if ($canonical !== null) {
            foreach ($variants as $v) {
                if ($v === $canonical) continue;
                try {
                    $st = $db->prepare(
                        'SELECT COUNT(*)
                           FROM att_punch_log v
                           JOIN att_punch_log c
                             ON c.device_id = v.device_id AND c.punch_time = v.punch_time AND c.pin = ?
                          WHERE v.pin = ?'
                    );
                    $st->execute([$canonical, $v]);
                    $overlap += (int)$st->fetchColumn();
                } catch (Throwable $e) { /* ignore */ }
            }
        }

        $groups[] = [
            'key'       => (string)$norm,
            'variants'  => $variants,
            'canonical' => $canonical,
            'status'    => $status,
            'counts'    => $counts,
            'overlap'   => $overlap,
            'users'     => $users,
        ];
    }
    usort($groups, static fn($a, $b) => strnatcmp($a['key'], $b['key']));
    return $groups;
};

$report = null;

// ---------------------------------------------------------------------------
// CONFIRM – merge the selected groups (re-derived from live data, so a stale
// or tampered form can never merge anything the preview did not show).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    csrf_check();

    $selected = $_POST['groups'] ?? [];
    if (!is_array($selected)) $selected = [];
    $selected = array_map('strval', $selected);

    $groups    = $build_groups();
    $rows      = [];
    $tot_moved = $tot_dupes = $tot_map = 0;

    foreach ($groups as $g) {
        if ($g['status'] !== 'ready' || !in_array($g['key'], $selected, true)) continue;
        $C          = (string)$g['canonical'];
        $target_uid = !empty($g['users']) ? (int)array_key_first($g['users']) : 0;

        $moved = $dupes = $map_renamed = $map_removed = 0;
        $dates = [];

        try {
            foreach ($g['variants'] as $v) {
                if ($v === $C) continue;

                // Days whose attendance must be recalculated afterwards.
                $st = $db->prepare('SELECT DISTINCT work_date FROM att_punch_log WHERE pin = ?');
                $st->execute([$v]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $dates[(string)$d] = true;

                // 1. Drop variant punches that already exist under the kept ID
                //    (same device + timestamp) – they are duplicates.
                $st = $db->prepare(
                    'DELETE v FROM att_punch_log v
                       JOIN att_punch_log c
                         ON c.device_id = v.device_id AND c.punch_time = v.punch_time AND c.pin = ?
                      WHERE v.pin = ?'
                );
                $st->execute([$C, $v]);
                $dupes += $st->rowCount();

                // 2. Re-pin the remaining punches to the kept ID.
                if ($target_uid > 0) {
                    $st = $db->prepare('UPDATE att_punch_log SET pin = ?, user_id = ? WHERE pin = ?');
                    $st->execute([$C, $target_uid, $v]);
                } else {
                    $st = $db->prepare('UPDATE att_punch_log SET pin = ? WHERE pin = ?');
                    $st->execute([$C, $v]);
                }
                $moved += $st->rowCount();

                // 3. Fold the variant's mappings into the kept ID.
                $st = $db->prepare('SELECT id, device_id FROM att_device_users WHERE pin = ?');
                $st->execute([$v]);
                foreach ($st->fetchAll() as $m) {
                    $chk = $db->prepare('SELECT id FROM att_device_users WHERE device_id = ? AND pin = ?');
                    $chk->execute([(int)$m['device_id'], $C]);
                    if ($chk->fetchColumn()) {
                        $db->prepare('DELETE FROM att_device_users WHERE id = ?')->execute([(int)$m['id']]);
                        $map_removed++;
                    } else {
                        $db->prepare('UPDATE att_device_users SET pin = ? WHERE id = ?')->execute([$C, (int)$m['id']]);
                        $map_renamed++;
                    }
                }
            }

            // 4. Backfill user_id on every punch of the kept ID and re-fold
            //    all affected user/days so daily records converge.
            if ($target_uid > 0) {
                $db->prepare('UPDATE att_punch_log SET user_id = ? WHERE pin = ? AND (user_id IS NULL OR user_id <> ?)')
                   ->execute([$target_uid, $C, $target_uid]);
                foreach (array_keys($dates) as $d) {
                    adms_fold_day($target_uid, (string)$d);
                }
            }
        } catch (Throwable $e) {
            $rows[] = ['keep' => $C, 'removed' => implode(', ', array_diff($g['variants'], [$C])),
                       'staff' => implode(', ', $g['users']), 'moved' => $moved, 'dupes' => $dupes,
                       'maps' => $map_renamed + $map_removed, 'days' => count($dates), 'ok' => false];
            continue;
        }

        $tot_moved += $moved;
        $tot_dupes += $dupes;
        $tot_map   += $map_renamed + $map_removed;
        $rows[] = ['keep' => $C, 'removed' => implode(', ', array_diff($g['variants'], [$C])),
                   'staff' => implode(', ', $g['users']), 'moved' => $moved, 'dupes' => $dupes,
                   'maps' => $map_renamed + $map_removed, 'days' => count($dates), 'ok' => true];
    }

    if (!empty($rows)) {
        log_change('staff-attendance', 'UPDATE', 0, 'Merged duplicate device user IDs (' . count($rows) . ' groups, ' . $tot_moved . ' punches moved)');
        flash_set('success', 'Merge done: ' . count($rows) . ' group(s) processed – ' . $tot_moved
            . ' punch(es) moved, ' . $tot_dupes . ' duplicate punch(es) removed, ' . $tot_map . ' mapping(s) updated.');
    } else {
        flash_set('warning', 'No groups were selected (or none were safe to merge).');
    }
    $report = $rows;
}

// Fresh preview (after a merge this reflects the new, clean state).
$groups  = $build_groups();
$ready   = array_values(array_filter($groups, static fn($g) => $g['status'] === 'ready'));
$blocked = array_values(array_filter($groups, static fn($g) => $g['status'] !== 'ready'));

$status_labels = [
    'no_zero'       => 'No variant starts with 0 – nothing matches the “zero-padded ID is correct” rule',
    'multi_zero'    => 'More than one variant starts with 0 – unclear which to keep',
    'user_conflict' => 'Variants are mapped to different staff members – fix the mapping first',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/index.php">Staff Attendance</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/staff-attendance/devices.php">Devices</a></li>
            <li class="breadcrumb-item active">Merge Duplicate IDs</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/staff-attendance/devices.php#tab-mapping" class="btn btn-light btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Devices
    </a>
</div>

<?= flash_show() ?>

<div class="alert alert-info d-flex align-items-start gap-2">
    <i class="fas fa-circle-info mt-1"></i>
    <div class="small">
        This tool finds Device User IDs that are the same number written with and without leading
        zeros (e.g. <code>028620</code> and <code>28620</code>). The <strong>zero-prefixed ID is kept</strong>;
        punches and mappings from the other variant are merged into it, and daily attendance is recalculated.
        <strong>Nothing is changed until you confirm below.</strong>
    </div>
</div>

<?php if ($report !== null && !empty($report)): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4"><h6 class="mb-0 fw-semibold"><i class="fas fa-clipboard-list me-2 text-primary"></i>Merge Report</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th class="ps-3">Kept ID</th><th>Removed IDs</th><th>Staff</th>
                    <th>Punches Moved</th><th>Duplicates Removed</th><th>Mappings Updated</th><th>Days Recalculated</th><th>Result</th>
                </tr></thead>
                <tbody>
                <?php foreach ($report as $r): ?>
                    <tr>
                        <td class="ps-3"><code><?= h($r['keep']) ?></code></td>
                        <td><code><?= h($r['removed']) ?></code></td>
                        <td class="small"><?= h($r['staff']) ?></td>
                        <td><?= (int)$r['moved'] ?></td>
                        <td><?= (int)$r['dupes'] ?></td>
                        <td><?= (int)$r['maps'] ?></td>
                        <td><?= (int)$r['days'] ?></td>
                        <td><span class="badge <?= $r['ok'] ? 'bg-success' : 'bg-danger' ?>"><?= $r['ok'] ? 'Merged' : 'Failed' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Preview: ready to merge ──────────────────────────────────────────────── -->
<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="merge">
    <div class="card mb-4" style="border-radius:12px;">
        <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-code-merge me-2 text-primary"></i>Preview – Ready to Merge
                <span class="badge bg-light text-dark border ms-1"><?= count($ready) ?></span>
            </h6>
            <?php if (!empty($ready)): ?>
            <button type="submit" class="btn btn-success btn-sm" style="border-radius:10px;"
                    onclick="return confirm('Merge the selected ID groups? Punches will be moved to the zero-prefixed IDs and duplicates removed. This cannot be undone.');">
                <i class="fas fa-check me-1"></i> Confirm &amp; Merge Selected
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
        <?php if (empty($ready)): ?>
            <p class="text-muted p-4 mb-0"><i class="fas fa-circle-check me-1 text-success"></i>
                No mergeable duplicate IDs found – every numeric Device User ID is unique after ignoring leading zeros.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th class="ps-3" style="width:40px;">
                            <input type="checkbox" class="form-check-input" checked
                                   onclick="document.querySelectorAll('.grp-check').forEach(c => c.checked = this.checked);"
                                   title="Select / deselect all">
                        </th>
                        <th>Keep (zero-prefixed)</th>
                        <th>Merge &amp; Remove</th>
                        <th>Staff Member</th>
                        <th>Punches per ID</th>
                        <th>Will Move</th>
                        <th>Duplicates to Remove</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($ready as $g):
                        $others      = array_values(array_diff($g['variants'], [$g['canonical']]));
                        $other_count = 0;
                        foreach ($others as $o) $other_count += (int)($g['counts'][$o] ?? 0);
                        $will_move   = max(0, $other_count - (int)$g['overlap']);
                    ?>
                        <tr>
                            <td class="ps-3">
                                <input type="checkbox" class="form-check-input grp-check" name="groups[]"
                                       value="<?= h($g['key']) ?>" checked>
                            </td>
                            <td><code class="fw-bold"><?= h($g['canonical']) ?></code>
                                <span class="text-muted small">(<?= (int)($g['counts'][$g['canonical']] ?? 0) ?> punches)</span></td>
                            <td>
                                <?php foreach ($others as $o): ?>
                                    <code><?= h($o) ?></code> <span class="text-muted small">(<?= (int)($g['counts'][$o] ?? 0) ?> punches)</span><br>
                                <?php endforeach; ?>
                            </td>
                            <td class="small"><?= !empty($g['users']) ? h(implode(', ', $g['users'])) : '<span class="badge bg-warning text-dark">Unmapped</span>' ?></td>
                            <td class="small text-muted"><?= (int)array_sum($g['counts']) ?> total</td>
                            <td><span class="badge bg-success"><?= (int)$will_move ?></span></td>
                            <td><?= $g['overlap'] > 0 ? '<span class="badge bg-secondary">' . (int)$g['overlap'] . '</span>' : '<span class="text-muted small">0</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div>
    </div>
</form>

<?php if (!empty($blocked)): ?>
<div class="card mb-4" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-triangle-exclamation me-2 text-warning"></i>Needs Attention – not merged automatically
            <span class="badge bg-light text-dark border ms-1"><?= count($blocked) ?></span></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th class="ps-3">IDs</th><th>Staff</th><th>Punches per ID</th><th>Why it needs review</th></tr></thead>
                <tbody>
                <?php foreach ($blocked as $g): ?>
                    <tr>
                        <td class="ps-3">
                            <?php foreach ($g['variants'] as $v): ?><code><?= h($v) ?></code> <?php endforeach; ?>
                        </td>
                        <td class="small"><?= !empty($g['users']) ? h(implode(', ', $g['users'])) : '<span class="text-muted">Unmapped</span>' ?></td>
                        <td class="small text-muted">
                            <?php foreach ($g['variants'] as $v): ?>
                                <code><?= h($v) ?></code>: <?= (int)($g['counts'][$v] ?? 0) ?>&nbsp;
                            <?php endforeach; ?>
                        </td>
                        <td class="small text-warning"><?= h($status_labels[$g['status']] ?? $g['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<p class="text-muted small">
    Merging is idempotent and audited. Duplicate punches (same device and timestamp under both IDs)
    are removed rather than doubled, the staff mapping is preserved on the kept ID, and each affected
    day's in/out record is recalculated from the merged punch log.
</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
