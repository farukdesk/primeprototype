<?php
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$page_title = 'Database Snapshots';

// ── Filters ─────────────────────────────────────────────────────────────────────
$f_table  = trim((string)($_GET['table'] ?? ''));
$f_action = strtoupper(trim((string)($_GET['action'] ?? '')));
$f_user   = (int)($_GET['user_id'] ?? 0);
$f_from   = trim((string)($_GET['from'] ?? ''));
$f_to     = trim((string)($_GET['to'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

$where  = [];
$params = [];
if ($f_table !== '') { $where[] = 's.table_name = ?'; $params[] = $f_table; }
if (in_array($f_action, ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) { $where[] = 's.action = ?'; $params[] = $f_action; }
if ($f_user > 0)   { $where[] = 's.user_id = ?';      $params[] = $f_user; }
if ($f_from !== '') { $where[] = 's.created_at >= ?'; $params[] = $f_from . ' 00:00:00'; }
if ($f_to !== '')   { $where[] = 's.created_at <= ?'; $params[] = $f_to . ' 23:59:59'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$table_missing = false;
$total = 0; $rows = []; $tables = []; $users = [];
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM db_snapshots s $where_sql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT s.id, s.table_name, s.action, s.row_count, s.query_snippet,
                s.created_at, s.restored_at, u.full_name AS user_name
         FROM db_snapshots s
         LEFT JOIN users u ON u.id = s.user_id
         $where_sql
         ORDER BY s.id DESC
         LIMIT $per_page OFFSET " . (($page - 1) * $per_page)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $tables = db()->query('SELECT DISTINCT table_name FROM db_snapshots ORDER BY table_name ASC')
                  ->fetchAll(PDO::FETCH_COLUMN);
    $users  = db()->query(
        'SELECT DISTINCT s.user_id AS id, u.full_name
         FROM db_snapshots s JOIN users u ON u.id = s.user_id
         ORDER BY u.full_name ASC'
    )->fetchAll();
} catch (Throwable $e) {
    $table_missing = true;
}
$pages = max(1, (int)ceil($total / $per_page));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item active">Database Snapshots</li>
        </ol>
    </nav>
    <span class="badge bg-dark"><i class="fas fa-user-shield me-1"></i> Super Admin Only</span>
</div>

<?php if ($table_missing): ?>
<div class="alert alert-warning">
    <strong>Snapshot table not found.</strong>
    Run the migration <code>admin/db-snapshots.sql</code> once to create the
    <code>db_snapshots</code> table. Snapshots start recording automatically afterwards.
</div>
<?php else: ?>

<div class="card mb-3" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.8rem;">Table</label>
                <select name="table" class="form-select form-select-sm">
                    <option value="">All tables</option>
                    <?php foreach ($tables as $t): ?>
                    <option value="<?= h($t) ?>" <?= $f_table === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.8rem;">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['INSERT', 'UPDATE', 'DELETE', 'REPLACE'] as $a): ?>
                    <option value="<?= $a ?>" <?= $f_action === $a ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1" style="font-size:.8rem;">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All users</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $f_user === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.8rem;">From</label>
                <input type="date" name="from" value="<?= h($f_from) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1" style="font-size:.8rem;">To</label>
                <input type="date" name="to" value="<?= h($f_to) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="<?= APP_URL ?>/db-snapshots/index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                <span class="ms-auto text-muted align-self-center" style="font-size:.82rem;"><?= number_format($total) ?> snapshot(s)</span>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:.86rem;">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>When</th>
                    <th>User</th>
                    <th>Table</th>
                    <th>Action</th>
                    <th class="text-center">Rows</th>
                    <th>Query</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No snapshots recorded yet. Every data change is captured automatically.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-muted"><?= (int)$r['id'] ?></td>
                    <td style="white-space:nowrap;"><?= h(date('d M Y H:i:s', strtotime($r['created_at']))) ?></td>
                    <td><?= h($r['user_name'] ?? 'System') ?></td>
                    <td><code><?= h($r['table_name']) ?></code></td>
                    <td><?= snap_action_badge($r['action']) ?>
                        <?php if ($r['restored_at']): ?><span class="badge bg-info text-dark" title="Restored <?= h($r['restored_at']) ?>">Restored</span><?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int)$r['row_count'] ?></td>
                    <td class="text-muted" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($r['query_snippet']) ?>"><?= h($r['query_snippet']) ?></td>
                    <td class="text-end pe-3">
                        <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i> View / Restore</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qs = $_GET; 
        for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++):
            $qs['page'] = $p; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <a class="page-link" href="?<?= h(http_build_query($qs)) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
