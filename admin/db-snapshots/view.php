<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$snap = snap_get($id);
if (!$snap) {
    flash_set('error', 'Snapshot not found.');
    redirect(APP_URL . '/db-snapshots/index.php');
}

$page_title = 'Snapshot #' . $id;

$before = $snap['rows_before'] ? (json_decode((string)$snap['rows_before'], true) ?: []) : [];
$after  = $snap['rows_after']  ? (json_decode((string)$snap['rows_after'],  true) ?: []) : [];
$pk     = (string)($snap['pk_column'] ?? '');

// Pair before/after rows by primary key when possible, else by position.
$pairs = [];
if ($pk !== '' && ($before || $after)) {
    $map = [];
    foreach ($before as $i => $r) { $map[(string)($r[$pk] ?? ('b' . $i))]['before'] = $r; }
    foreach ($after  as $i => $r) { $map[(string)($r[$pk] ?? ('a' . $i))]['after']  = $r; }
    $pairs = array_values($map);
} else {
    $n = max(count($before), count($after));
    for ($i = 0; $i < $n; $i++) {
        $pairs[] = ['before' => $before[$i] ?? null, 'after' => $after[$i] ?? null];
    }
}

$restorable = snap_restorable($snap);
$restore_label = $snap['action'] === 'INSERT'
    ? 'Undo Insert (delete the added row)'
    : 'Restore Previous Data';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/db-snapshots/index.php">Database Snapshots</a></li>
            <li class="breadcrumb-item active">Snapshot #<?= $id ?></li>
        </ol>
    </nav>
</div>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body">
        <div class="row g-3" style="font-size:.88rem;">
            <div class="col-md-3"><strong>Table:</strong> <code><?= h($snap['table_name']) ?></code></div>
            <div class="col-md-2"><strong>Action:</strong> <?= snap_action_badge($snap['action']) ?></div>
            <div class="col-md-3"><strong>By:</strong> <?= h($snap['user_name'] ?? 'System') ?></div>
            <div class="col-md-4"><strong>When:</strong> <?= h(date('d M Y H:i:s', strtotime($snap['created_at']))) ?></div>
            <div class="col-md-3"><strong>Rows:</strong> <?= (int)$snap['row_count'] ?></div>
            <div class="col-md-3"><strong>IP:</strong> <?= h($snap['ip_address'] ?? '—') ?></div>
            <div class="col-md-6"><strong>Page:</strong> <span class="text-muted"><?= h($snap['request_uri'] ?? '—') ?></span></div>
            <div class="col-12"><strong>Query:</strong> <code style="font-size:.8rem;word-break:break-all;"><?= h($snap['query_snippet'] ?? '—') ?></code></div>
            <?php if ($snap['restored_at']): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0 py-2">
                    <i class="fas fa-history me-1"></i>
                    Restored on <strong><?= h(date('d M Y H:i:s', strtotime($snap['restored_at']))) ?></strong>
                    by <strong><?= h($snap['restorer_name'] ?? 'Unknown') ?></strong>.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($restorable): ?>
<div class="card mb-3 border-warning" style="border-radius:12px;">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div style="font-size:.88rem;">
            <i class="fas fa-undo me-1 text-warning"></i>
            <?php if ($snap['action'] === 'INSERT'): ?>
                Restoring will <strong>delete the row(s) added</strong> by this insert.
            <?php else: ?>
                Restoring will write the <strong>previous data (before-image)</strong> back into
                <code><?= h($snap['table_name']) ?></code>. Deleted rows are re-created; edited rows revert.
            <?php endif; ?>
            The restore itself is also recorded as a snapshot, so it can be reverted again.
        </div>
        <form method="POST" action="restore.php"
              onsubmit="return confirm('Restore snapshot #<?= $id ?> on table <?= h($snap['table_name']) ?>? This changes live data.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-undo me-1"></i> <?= h($restore_label) ?>
            </button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-secondary py-2" style="font-size:.85rem;">
    This snapshot cannot be restored automatically (no usable before-image / primary-key data). It remains available for audit.
</div>
<?php endif; ?>

<?php foreach ($pairs as $i => $pair):
    $b = $pair['before'] ?? null;
    $a = $pair['after']  ?? null;
    $fields = array_unique(array_merge(is_array($b) ? array_keys($b) : [], is_array($a) ? array_keys($a) : []));
?>
<div class="card mb-3" style="border-radius:12px;">
    <div class="card-header py-2 px-3" style="font-size:.85rem;">
        <strong>Row <?= $i + 1 ?></strong>
        <?php if ($pk !== '' && is_array($b ?? $a) && isset(($b ?? $a)[$pk])): ?>
            &nbsp;·&nbsp; <code><?= h($pk) ?> = <?= h(($b ?? $a)[$pk]) ?></code>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.83rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:20%;">Field</th>
                    <th style="width:40%;">Before</th>
                    <th style="width:40%;">After</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fields as $f):
                $vb = is_array($b) ? ($b[$f] ?? null) : null;
                $va = is_array($a) ? ($a[$f] ?? null) : null;
                $changed = is_array($b) && is_array($a) && (string)$vb !== (string)$va;
            ?>
                <tr <?= $changed ? 'class="table-warning"' : '' ?>>
                    <td><code><?= h($f) ?></code></td>
                    <td style="word-break:break-all;"><?= $b === null ? '<em class="text-muted">— (row did not exist)</em>' : ($vb === null ? '<em class="text-muted">NULL</em>' : h(mb_strimwidth((string)$vb, 0, 300, '…'))) ?></td>
                    <td style="word-break:break-all;"><?= $a === null ? '<em class="text-muted">— (row deleted / not captured)</em>' : ($va === null ? '<em class="text-muted">NULL</em>' : h(mb_strimwidth((string)$va, 0, 300, '…'))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($pairs)): ?>
<div class="alert alert-secondary">No row images stored for this snapshot.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
