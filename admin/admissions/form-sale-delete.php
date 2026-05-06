<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('admissions');
require_once __DIR__ . '/form-sale-helpers.php';

if (!adm_can_delete()) {
    flash_set('error', 'You do not have permission to cancel form sales.');
    redirect(APP_URL . '/admissions/form-sale-index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/admissions/form-sale-index.php');
}

csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$sale = adm_fs_get($id);

if ($sale['status'] !== 'pending') {
    flash_set('error', 'Only pending form sales can be cancelled.');
    redirect(APP_URL . '/admissions/form-sale-index.php');
}

db()->prepare(
    'UPDATE adm_form_sales SET status = ? WHERE id = ?'
)->execute(['cancelled', $id]);

log_change(
    'admissions', 'DELETE', $id,
    'Form Sale ' . $sale['form_number'],
    'status',
    'pending',
    'cancelled',
    'Form sale cancelled – buyer: ' . $sale['buyer_name']
    . ' | mobile: ' . $sale['buyer_mobile']
    . ' | price: ' . number_format((float)$sale['form_price'], 2) . ' BDT'
);
flash_set('success', 'Form sale ' . $sale['form_number'] . ' has been cancelled.');
redirect(APP_URL . '/admissions/form-sale-index.php');
