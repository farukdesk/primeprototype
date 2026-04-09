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

if ($account['is_system']) {
    flash_set('error', 'System accounts cannot be deleted.');
    redirect(APP_URL . '/accounting/chart-of-accounts.php');
}

// Check if account has voucher items
$used = (int)db()->prepare(
    'SELECT COUNT(*) FROM acc_voucher_items WHERE account_id = ?'
)->execute([$id]) ? db()->query("SELECT COUNT(*) FROM acc_voucher_items WHERE account_id = $id")->fetchColumn() : 0;

if ($used > 0) {
    flash_set('error', 'Cannot delete account "' . $account['name'] . '" — it has ' . $used . ' transaction(s). Deactivate it instead.');
    redirect(APP_URL . '/accounting/chart-of-accounts.php');
}

// Soft-delete: just deactivate
db()->prepare('UPDATE acc_accounts SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
log_change('accounting-coa', 'DELETE', $id, $account['code'] . ' ' . $account['name'], null, null, null, 'Account deactivated');
flash_set('success', 'Account "' . $account['name'] . '" has been deactivated.');
redirect(APP_URL . '/accounting/chart-of-accounts.php');
