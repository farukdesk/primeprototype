<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation');

$id = (int)($_GET['id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = h($exam['exam_name']) . ' – Version History';

// ── Load all snapshots ────────────────────────────────────────────────────────
$snaps_st = db()->prepare(
    'SELECT * FROM ei_assignment_snapshots WHERE exam_id = ? ORDER BY version_number ASC'
);
$snaps_st->execute([$id]);
$snapshots = $snaps_st->fetchAll();

// ── Load all slot metadata (room_number, slot_date, time_slot) for display ───
$slot_meta_st = db()->prepare(
    'SELECT id, slot_date, time_slot, room_number FROM ei_slots WHERE exam_id = ? ORDER BY slot_date, time_slot, room_number'
);
$slot_meta_st->execute([$id]);
$slot_meta = [];
foreach ($slot_meta_st->fetchAll() as $row) {
    $slot_meta[(int)$row['id']] = $row;
}

// ── Load faculty names for display ───────────────────────────────────────────
$fac_st = db()->query('SELECT id, name, designation FROM ei_faculty ORDER BY name ASC');
$fac_map = [];
foreach ($fac_st->fetchAll() as $f) {
    $fac_map[(int)$f['id']] = $f['name'] . ($f['designation'] ? ' (' . $f['designation'] . ')' : '');
}

// ── Load all snapshot slot data in one query ──────────────────────────────────
$snap_slot_map = [];
if (!empty($snapshots)) {
    $snap_ids      = array_column($snapshots, 'id');
    $placeholders  = implode(',', array_fill(0, count($snap_ids), '?'));
    $ss_st = db()->prepare(
        "SELECT snapshot_id, slot_id, faculty1_id, faculty2_id
         FROM ei_assignment_snapshot_slots
         WHERE snapshot_id IN ({$placeholders})"
    );
    $ss_st->execute($snap_ids);
    foreach ($ss_st->fetchAll() as $row) {
        $snap_slot_map[(int)$row['snapshot_id']][(int)$row['slot_id']] = $row;
    }
}

/**
 * Compute the diff between two slot-maps (keyed by slot_id).
 * Returns array of changed slots with before/after faculty values.
 * $prev_map = [] means "compare against all-unassigned".
 */
function ei_snapshot_diff(array $cur_map, array $prev_map, array $slot_meta): array
{
    $changes = [];
    // All slot IDs present in either map
    $all_ids = array_unique(array_merge(array_keys($cur_map), array_keys($prev_map)));
    foreach ($all_ids as $slot_id) {
        $cur  = $cur_map[$slot_id]  ?? ['faculty1_id' => null, 'faculty2_id' => null];
        $prev = $prev_map[$slot_id] ?? ['faculty1_id' => null, 'faculty2_id' => null];
        $f1_changed = (int)($cur['faculty1_id'] ?? 0) !== (int)($prev['faculty1_id'] ?? 0);
        $f2_changed = (int)($cur['faculty2_id'] ?? 0) !== (int)($prev['faculty2_id'] ?? 0);
        if ($f1_changed || $f2_changed) {
            $changes[] = [
                'slot_id'         => $slot_id,
                'slot_date'       => $slot_meta[$slot_id]['slot_date']   ?? '',
                'time_slot'       => $slot_meta[$slot_id]['time_slot']   ?? '',
                'room_number'     => $slot_meta[$slot_id]['room_number'] ?? "(Room #{$slot_id})",
                'before_f1'       => $prev['faculty1_id'],
                'before_f2'       => $prev['faculty2_id'],
                'after_f1'        => $cur['faculty1_id'],
                'after_f2'        => $cur['faculty2_id'],
                'f1_changed'      => $f1_changed,
                'f2_changed'      => $f2_changed,
            ];
        }
    }
    // Sort by date → time → room
    usort($changes, static function ($a, $b) {
        return ($a['slot_date'] . $a['time_slot'] . $a['room_number'])
             <=> ($b['slot_date'] . $b['time_slot'] . $b['room_number']);
    });
    return $changes;
}

$type_labels = [
    'auto_assign'  => ['label' => 'Auto-Assign', 'class' => 'bg-primary'],
    'manual_edit'  => ['label' => 'Manual Edit',  'class' => 'bg-info text-dark'],
    'clear_slot'   => ['label' => 'Clear Slot',   'class' => 'bg-warning text-dark'],
    'revert'       => ['label' => 'Revert',        'class' => 'bg-secondary'],
];

// Display newest first
$snapshots_desc = array_reverse($snapshots);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>"><?= h($exam['exam_name']) ?></a>
            </li>
            <li class="breadcrumb-item active">Version History</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>"
       class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back to Invigilation View
    </a>
</div>

<?php flash_show(); ?>

<!-- Info banner -->
<div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-4" style="font-size:.875rem;">
    <i class="fas fa-info-circle mt-1"></i>
    <span>
        A new version is saved automatically after every assignment operation (auto-assign, manual edit, or clear).
        You can revert the entire exam to any previous state. Reverting also creates a new version so the action itself is logged and reversible.
    </span>
</div>

<?php if (empty($snapshots)): ?>
<!-- Empty state -->
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-history fa-3x mb-3 d-block" style="opacity:.15;"></i>
        <p class="mb-1">No versions saved yet.</p>
        <p style="font-size:.85rem;">Versions are created automatically the first time assignments are made or changed.</p>
        <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>" class="btn btn-sm btn-primary mt-2" style="border-radius:10px;">
            <i class="fas fa-arrow-left me-1"></i> Go to Invigilation View
        </a>
    </div>
</div>
<?php else: ?>

<!-- Version count summary -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h6 class="mb-0 fw-semibold text-muted">
        <i class="fas fa-history me-2"></i><?= count($snapshots) ?> version<?= count($snapshots) !== 1 ? 's' : '' ?> saved
    </h6>
    <small class="text-muted">Showing newest first</small>
</div>

<?php
$total_snaps = count($snapshots);
foreach ($snapshots_desc as $i => $snap):
    $snap_id      = (int)$snap['id'];
    $ver_num      = (int)$snap['version_number'];
    $is_latest    = ($i === 0); // first in desc = newest

    // Diff: compare this snapshot with the previous one (chronologically)
    $cur_slot_map  = $snap_slot_map[$snap_id] ?? [];
    // Previous snapshot in chronological order = the one with version_number = ver_num - 1
    $prev_snap_idx = $total_snaps - 1 - $i - 1; // index in original asc array
    $prev_slot_map = [];
    if ($prev_snap_idx >= 0 && isset($snapshots[$prev_snap_idx])) {
        $prev_snap_id  = (int)$snapshots[$prev_snap_idx]['id'];
        $prev_slot_map = $snap_slot_map[$prev_snap_id] ?? [];
    }
    $diff = ei_snapshot_diff($cur_slot_map, $prev_slot_map, $slot_meta);
    $diff_count = count($diff);

    $type_info  = $type_labels[$snap['change_type']] ?? ['label' => ucfirst($snap['change_type']), 'class' => 'bg-dark'];
    $collapse_id = 'diff-' . $snap_id;
    $revert_modal_id = 'modalRevert' . $snap_id;
?>
<div class="card mb-3<?= $is_latest ? ' border-success' : '' ?>">
    <div class="card-header py-2 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2"
         style="background:<?= $is_latest ? '#f0fff4' : '#f8f9ff' ?>;border-bottom:1px solid <?= $is_latest ? '#b7e4c7' : '#e8ecf5' ?>;">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge" style="background:#4f8ef7;font-size:.8rem;min-width:2.5rem;text-align:center;">V<?= $ver_num ?></span>
            <span class="badge <?= h($type_info['class']) ?>"><?= h($type_info['label']) ?></span>
            <?php if ($is_latest): ?>
            <span class="badge bg-success"><i class="fas fa-check me-1"></i>Current State</span>
            <?php endif; ?>
            <span class="fw-medium" style="font-size:.9rem;"><?= h($snap['change_summary'] ?? '') ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 text-muted flex-wrap" style="font-size:.8rem;">
            <span><i class="fas fa-user me-1"></i><?= h($snap['changed_by_name'] ?? 'System') ?></span>
            <span><i class="fas fa-clock me-1"></i><?= date('d M Y, h:i A', strtotime($snap['created_at'])) ?></span>
            <span><i class="fas fa-layer-group me-1"></i><?= (int)$snap['slots_count'] ?> slot<?= (int)$snap['slots_count'] !== 1 ? 's' : '' ?></span>
        </div>
    </div>
    <div class="card-body px-4 py-2">
        <!-- Changes summary row -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <?php if ($diff_count > 0): ?>
                <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:.8rem;"
                        type="button" data-bs-toggle="collapse" data-bs-target="#<?= $collapse_id ?>" aria-expanded="false">
                    <i class="fas fa-exchange-alt me-1"></i>
                    <?= $diff_count ?> change<?= $diff_count !== 1 ? 's' : '' ?> from previous version
                    <i class="fas fa-chevron-down ms-1" style="font-size:.7rem;"></i>
                </button>
                <?php elseif ($ver_num === 1): ?>
                <span class="text-muted" style="font-size:.85rem;"><i class="fas fa-info-circle me-1"></i>First version – captures initial state</span>
                <?php else: ?>
                <span class="text-muted" style="font-size:.85rem;"><i class="fas fa-equals me-1"></i>No assignment changes from previous version</span>
                <?php endif; ?>
            </div>
            <?php if (!$is_latest && (is_super_admin() || can_access('exam-invigilation', 'can_edit'))): ?>
            <button type="button" class="btn btn-sm btn-outline-warning" style="border-radius:8px;font-size:.8rem;"
                    data-bs-toggle="modal" data-bs-target="#<?= $revert_modal_id ?>">
                <i class="fas fa-undo me-1"></i> Revert to this Version
            </button>
            <?php endif; ?>
        </div>

        <!-- Diff collapse -->
        <?php if ($diff_count > 0): ?>
        <div class="collapse mt-3" id="<?= $collapse_id ?>">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:.82rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Room</th>
                            <th>Date</th>
                            <th>Time Slot</th>
                            <th>Invigilator 1 – Before</th>
                            <th>Invigilator 1 – After</th>
                            <th>Invigilator 2 – Before</th>
                            <th>Invigilator 2 – After</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diff as $chg): ?>
                    <tr>
                        <td class="fw-medium"><?= h($chg['room_number']) ?></td>
                        <td class="text-nowrap"><?= $chg['slot_date'] ? date('d M Y', strtotime($chg['slot_date'])) : '–' ?></td>
                        <td class="text-nowrap"><?= h($chg['time_slot']) ?></td>
                        <!-- F1 before -->
                        <td class="<?= $chg['f1_changed'] ? 'table-danger' : '' ?>">
                            <?php if ($chg['before_f1']): ?>
                                <?= h($fac_map[(int)$chg['before_f1']] ?? "ID #{$chg['before_f1']}") ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <!-- F1 after -->
                        <td class="<?= $chg['f1_changed'] ? 'table-success' : '' ?>">
                            <?php if ($chg['after_f1']): ?>
                                <?= h($fac_map[(int)$chg['after_f1']] ?? "ID #{$chg['after_f1']}") ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <!-- F2 before -->
                        <td class="<?= $chg['f2_changed'] ? 'table-danger' : '' ?>">
                            <?php if ($chg['before_f2']): ?>
                                <?= h($fac_map[(int)$chg['before_f2']] ?? "ID #{$chg['before_f2']}") ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <!-- F2 after -->
                        <td class="<?= $chg['f2_changed'] ? 'table-success' : '' ?>">
                            <?php if ($chg['after_f2']): ?>
                                <?= h($fac_map[(int)$chg['after_f2']] ?? "ID #{$chg['after_f2']}") ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Revert confirmation modal for this version -->
<?php if (!$is_latest && (is_super_admin() || can_access('exam-invigilation', 'can_edit'))): ?>
<div class="modal fade" id="<?= $revert_modal_id ?>" tabindex="-1" aria-labelledby="<?= $revert_modal_id ?>Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="<?= $revert_modal_id ?>Label">
                    <i class="fas fa-undo me-2"></i>Revert to Version <?= $ver_num ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>You are about to restore all slot assignments to the state saved in <strong>Version <?= $ver_num ?></strong>:</p>
                <ul style="font-size:.9rem;">
                    <li><strong>Saved by:</strong> <?= h($snap['changed_by_name'] ?? 'System') ?></li>
                    <li><strong>Date/Time:</strong> <?= date('d M Y, h:i A', strtotime($snap['created_at'])) ?></li>
                    <li><strong>Operation:</strong> <?= h($type_info['label']) ?> – <?= h($snap['change_summary'] ?? '') ?></li>
                    <li><strong>Slots in snapshot:</strong> <?= (int)$snap['slots_count'] ?></li>
                </ul>
                <div class="alert alert-info py-2 mb-0" style="font-size:.85rem;">
                    <i class="fas fa-shield-alt me-1"></i>
                    The current state will be saved as a new version before reverting, so you can undo this revert if needed.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $id ?>" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="revert_version">
                    <input type="hidden" name="snapshot_id" value="<?= $snap_id ?>">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-undo me-1"></i> Confirm Revert to V<?= $ver_num ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
