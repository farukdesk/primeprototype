<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting');
require_once __DIR__ . '/helpers.php';

$page_title  = 'Chart of Accounts';
$can_manage  = acc_can_manage_coa();

// Fetch all accounts
$accounts = db()->query(
    "SELECT a.*, p.name AS parent_name
     FROM acc_accounts a
     LEFT JOIN acc_accounts p ON p.id = a.parent_id
     ORDER BY a.code ASC"
)->fetchAll();

// Group by type for display
$grouped = [];
foreach ($accounts as $acc) {
    $grouped[$acc['type']][] = $acc;
}
$type_order = ['asset','liability','equity','income','expense'];
$type_labels = [
    'asset'     => ['Assets',      'primary',   'building'],
    'liability' => ['Liabilities', 'danger',    'hand-holding'],
    'equity'    => ['Equity',      'success',   'university'],
    'income'    => ['Income',      'info',      'arrow-down'],
    'expense'   => ['Expenses',    'warning',   'arrow-up'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-sitemap me-2 text-primary"></i>Chart of Accounts</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item active">Chart of Accounts</li>
        </ol></nav>
    </div>
    <?php if ($can_manage): ?>
    <a href="<?= APP_URL ?>/accounting/account-create.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> Add Account</a>
    <?php endif; ?>
</div>

<?= flash_show() ?>

<?php foreach ($type_order as $type):
    if (empty($grouped[$type])) continue;
    [$label, $color, $icon] = $type_labels[$type];
    $type_accounts = $grouped[$type];
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 px-3 d-flex align-items-center gap-2">
        <span class="badge bg-<?= $color ?> p-2"><i class="fas fa-<?= $icon ?>"></i></span>
        <strong><?= $label ?></strong>
        <span class="badge bg-secondary ms-1"><?= count($type_accounts) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:100px">Code</th>
                        <th>Account Name</th>
                        <th>Sub Type</th>
                        <th>Parent</th>
                        <th class="text-end">Opening Balance</th>
                        <th>Status</th>
                        <?php if ($can_manage): ?><th class="text-end">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($type_accounts as $acc): ?>
                    <tr>
                        <td><span class="badge bg-light text-dark border fw-bold"><?= h($acc['code']) ?></span></td>
                        <td>
                            <span class="fw-semibold"><?= h($acc['name']) ?></span>
                            <?php if ($acc['is_system']): ?><span class="badge bg-secondary ms-1" style="font-size:.6rem">System</span><?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= h(str_replace('_', ' ', ucwords($acc['sub_type'] ?? '–', '_'))) ?></td>
                        <td class="text-muted small"><?= h($acc['parent_name'] ?? '–') ?></td>
                        <td class="text-end small"><?= acc_currency() ?> <?= number_format($acc['opening_balance'], 2) ?></td>
                        <td>
                            <?php if ($acc['is_active']): ?>
                            <span class="badge bg-success">Active</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_manage): ?>
                        <td class="text-end">
                            <a href="<?= APP_URL ?>/accounting/account-edit.php?id=<?= $acc['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php if (!$acc['is_system']): ?>
                            <a href="<?= APP_URL ?>/accounting/account-delete.php?id=<?= $acc['id'] ?>"
                               class="btn btn-sm btn-outline-danger" title="Delete"
                               onclick="return confirm('Delete account <?= h($acc['name']) ?>?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
