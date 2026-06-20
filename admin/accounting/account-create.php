<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('accounting-coa', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Add Account';
$types = ['asset','liability','equity','income','expense'];
$sub_types = [
    'asset'     => ['current_asset','fixed_asset','other_asset'],
    'liability' => ['current_liability','long_term_liability'],
    'equity'    => ['equity'],
    'income'    => ['revenue','other_income'],
    'expense'   => ['operating_expense','other_expense'],
];
$errors = [];

// All accounts for parent dropdown
$all_accounts = acc_all_active_accounts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    save_old($_POST);

    $code       = trim($_POST['code']       ?? '');
    $name       = trim($_POST['name']       ?? '');
    $type       = trim($_POST['type']       ?? '');
    $sub_type   = trim($_POST['sub_type']   ?? '');
    $parent_id  = (int)($_POST['parent_id'] ?? 0) ?: null;
    $opening    = (float)($_POST['opening_balance'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;

    if (!$code)  $errors[] = 'Account code is required.';
    if (!$name)  $errors[] = 'Account name is required.';
    if (!in_array($type, $types, true)) $errors[] = 'Invalid account type.';

    // Check code uniqueness
    if ($code) {
        $exists = db()->prepare('SELECT id FROM acc_accounts WHERE code = ?');
        $exists->execute([$code]);
        if ($exists->fetchColumn()) $errors[] = 'Account code already exists.';
    }

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO acc_accounts (code, name, type, sub_type, parent_id, opening_balance, description, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$code, $name, $type, $sub_type ?: null, $parent_id, $opening, $description ?: null, $is_active]);

        $new_id = (int)db()->lastInsertId();
        log_change('accounting-coa', 'CREATE', $new_id, $code . ' ' . $name, null, null, null, 'Account created');
        clear_old();
        flash_set('success', 'Account "' . $name . '" created successfully.');
        redirect(APP_URL . '/accounting/chart-of-accounts.php');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold"><i class="fas fa-plus me-2 text-primary"></i>Add Account</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/index.php">Accounting</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/accounting/chart-of-accounts.php">Chart of Accounts</a></li>
            <li class="breadcrumb-item active">Add Account</li>
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
                            <label class="form-label fw-semibold">Account Code <span class="text-danger">*</span></label>
                            <input type="text" name="code" class="form-control" value="<?= old('code') ?>" placeholder="e.g. 1100" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= old('name') ?>" placeholder="e.g. Petty Cash" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Account Type <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required id="acc_type">
                                <option value="">— Select Type —</option>
                                <?php foreach ($types as $t): ?>
                                <option value="<?= $t ?>" <?= old('type') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sub Type</label>
                            <select name="sub_type" class="form-select" id="acc_sub_type">
                                <option value="">— Select Sub Type —</option>
                                <?php foreach ($sub_types as $t => $subs): foreach ($subs as $s): ?>
                                <option value="<?= $s ?>" data-type="<?= $t ?>" <?= old('sub_type') === $s ? 'selected' : '' ?> style="display:none">
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
                                <option value="<?= $a['id'] ?>" <?= old('parent_id') == $a['id'] ? 'selected' : '' ?>>
                                    <?= h($a['code'] . ' – ' . $a['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Opening Balance (<?= acc_currency() ?>)</label>
                            <input type="number" name="opening_balance" class="form-control" step="0.01"
                                   value="<?= old('opening_balance', '0.00') ?>" placeholder="0.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional description…"><?= old('description') ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="is_active"
                                       <?= old('is_active', '1') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Account</button>
                        <a href="<?= APP_URL ?>/accounting/chart-of-accounts.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('acc_type').addEventListener('change', function () {
    const selected = this.value;
    const sub = document.getElementById('acc_sub_type');
    sub.value = '';
    Array.from(sub.options).forEach(o => {
        o.style.display = (!o.dataset.type || o.dataset.type === selected) ? '' : 'none';
    });
});
// Trigger on load if old value exists
document.getElementById('acc_type').dispatchEvent(new Event('change'));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
