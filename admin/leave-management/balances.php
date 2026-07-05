<?php
/**
 * Admin: set per-user yearly leave totals (Casual / Sick).
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('leave-management', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Leave Balances';
$db         = db();
$year       = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$search     = trim($_GET['q'] ?? '');
$fmt        = fn(float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');

// ── Save handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid    = (int)($_POST['user_id'] ?? 0);
    $y      = (int)($_POST['year'] ?? $year);
    $casual = (float)($_POST['casual_total'] ?? 0);
    $sick   = (float)($_POST['sick_total'] ?? 0);

    if ($uid < 1) {
        flash_set('error', 'Invalid user.');
    } elseif ($casual < 0 || $sick < 0 || $casual > 365 || $sick > 365) {
        flash_set('error', 'Totals must be between 0 and 365 days.');
    } else {
        lm_set_balance($uid, $y, $casual, $sick);
        log_change('leave-management', 'UPDATE', $uid, 'Balance ' . $y, 'balance', null, "Casual={$casual}, Sick={$sick}");
        flash_set('success', 'Leave balance updated.');
    }
    redirect(APP_URL . '/leave-management/balances.php?year=' . $y . ($search !== '' ? '&q=' . urlencode($search) : ''));
}

// ── Staff list (users who can access the module via their group) ───────────────
$where  = ["u.is_active = 1"];
$params = [];
if ($search !== '') {
    $where[]  = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$where_sql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT u.id, u.full_name, u.username, g.name AS group_name
       FROM users u
       JOIN user_groups g ON g.id = u.group_id
      WHERE $where_sql
      ORDER BY u.full_name ASC
      LIMIT 500"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leave-management/index.php">Leave Management</a></li>
            <li class="breadcrumb-item active">Balances</li>
        </ol>
    </nav>
</div>

<?= flash_show() ?>

<div class="card mb-4" style="border-radius:12px;">
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold small mb-1">Search staff</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>" placeholder="Name, username or email">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small mb-1">Year</label>
                <input type="number" name="year" class="form-control" value="<?= $year ?>" min="2000" max="2100">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="border-radius:12px;">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-wallet me-2 text-primary"></i>Yearly Leave Totals — <?= $year ?></h6>
    </div>
    <div class="card-body p-0">
        <?php // One form per user, referenced by the `form` attribute so the markup stays valid inside the table.
        foreach ($users as $u): ?>
            <form id="bf<?= (int)$u['id'] ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="year" value="<?= $year ?>">
            </form>
        <?php endforeach; ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">Staff</th>
                        <th>Group</th>
                        <th style="width:150px;">Casual Total</th>
                        <th style="width:150px;">Sick Total</th>
                        <th>Used (C / S)</th>
                        <th style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No staff found.</td></tr>
                <?php else: foreach ($users as $u):
                    $bal = lm_get_balance((int)$u['id'], $year);
                    $ff  = 'bf' . (int)$u['id'];
                ?>
                    <tr>
                        <td class="px-3">
                            <strong><?= h($u['full_name']) ?></strong>
                            <div class="text-muted small"><?= h($u['username']) ?></div>
                        </td>
                        <td class="small text-muted"><?= h($u['group_name']) ?></td>
                        <td><input type="number" form="<?= $ff ?>" step="0.5" min="0" max="365" name="casual_total" class="form-control form-control-sm" value="<?= $fmt($bal['casual_total']) ?>"></td>
                        <td><input type="number" form="<?= $ff ?>" step="0.5" min="0" max="365" name="sick_total" class="form-control form-control-sm" value="<?= $fmt($bal['sick_total']) ?>"></td>
                        <td class="small"><?= $fmt($bal['casual_used']) ?> / <?= $fmt($bal['sick_used']) ?></td>
                        <td><button type="submit" form="<?= $ff ?>" class="btn btn-sm btn-success" style="border-radius:8px;"><i class="fas fa-save"></i></button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="text-muted small mt-2">Users without an explicit row use the default of <?= $fmt(LM_DEFAULT_CASUAL) ?> casual and <?= $fmt(LM_DEFAULT_SICK) ?> sick days. Saving stores their totals for <?= $year ?>.</p>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
