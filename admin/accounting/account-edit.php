<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting-coa', 'can_edit');
require_once __DIR__ . '/helpers.php';

$id      = (int)($_GET['id'] ?? 0);
$account = acc_get_account($id);
if (!$account) {
    flash_set('error', 'Account not found.');
    redirect(APP_URL . '/accounting/chart-of-accounts.php');
}

$page_title = 'Edit Account: ' . $account['code'];
$types = ['asset','liability','equity','income','expense'];
$sub_types = [
    'asset'     => ['current_asset','fixed_asset','other_asset'],
    'liability' => ['current_liability','long_term_liability'],
    'equity'    => ['equity'],
    'income'    => ['revenue','other_income'],
    'expense'   => ['operating_expense','other_expense'],
];
$all_accounts = db()->query(
    "SELECT id, code, name FROM acc_accounts WHERE is_active = 1 AND id != $id ORDER BY code ASC"
)->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    save_old($_POST);

    $name        = trim($_POST['name']        ?? '');
    $type        = trim($_POST['type']        ?? '');
    $sub_type    = trim($_POST['sub_type']    ?? '');
    $parent_id   = (int)($_POST['parent_id'] ?? 0) ?: null;
    $opening     = (float)($_POST['opening_balance'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if (!$name) $errors[] = 'Account name is required.';
    if (!in_array($type, $types, true)) $errors[] = 'Invalid account type.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE acc_accounts SET name=?, type=?, sub_type=?, parent_id=?, opening_balance=?, description=?, is_active=?, updated_at=NOW()
             WHERE id=?'
        )->execute([$name, $type, $sub_type ?: null, $parent_id, $opening, $description ?: null, $is_active, $id]);

        log_change('accounting-coa', 'UPDATE', $id, $account['code'] . ' ' . $name, null, null, null, 'Account updated');
        clear_old();
        flash_set('success', 'Account updated successfully.');
        redirect(APP_URL . '/accounting/chart-of-accounts.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-primary"></i>Edit Account: <?= h($account['code']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/chart-of-accounts.php">Chart of Accounts</a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/accounting/chart-of-accounts.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Account Code</label>
                            <input type="text" class="form-control" value="<?= h($account['code']) ?>" disabled>
                            <div class="form-text">Account code cannot be changed after creation.</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= old('name', $account['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Account Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" id="acc_type" <?= $account['is_system'] ? 'disabled' : '' ?> required>
                                <?php foreach ($types as $t): ?>
                                <option value="<?= $t ?>" <?= old('type', $account['type']) === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($account['is_system']): ?>
                            <input type="hidden" name="type" value="<?= h($account['type']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sub Type</label>
                            <select name="sub_type" class="form-select" id="acc_sub_type">
                                <option value="">— None —</option>
                                <?php foreach ($sub_types as $t => $subs): foreach ($subs as $s): ?>
                                <option value="<?= $s ?>" data-type="<?= $t ?>"
                                    <?= old('sub_type', $account['sub_type']) === $s ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', $s)) ?>
                                </option>
                                <?php endforeach; endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Parent Account</label>
                            <select name="parent_id" class="form-select">
                                <option value="">— None (Top Level) —</option>
                                <?php foreach ($all_accounts as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= old('parent_id', $account['parent_id']) == $a['id'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Opening Balance (<?= acc_currency() ?>)</label>
                            <input type="number" name="opening_balance" class="form-control" step="0.01"
                                   value="<?= old('opening_balance', $account['opening_balance']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?= old('description', $account['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="is_active"
                                       <?= old('is_active', $account['is_active']) ? 'checked' : '' ?>
                                       <?= $account['is_system'] ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                                <?php if ($account['is_system']): ?>
                                <input type="hidden" name="is_active" value="1">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Update Account</button>
                        <a href="<?= APP_URL ?>/accounting/chart-of-accounts.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('acc_type')?.addEventListener('change', function () {
    const selected = this.value;
    const sub = document.getElementById('acc_sub_type');
    Array.from(sub.options).forEach(o => {
        o.style.display = (!o.dataset.type || o.dataset.type === selected) ? '' : 'none';
    });
});
document.getElementById('acc_type')?.dispatchEvent(new Event('change'));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
