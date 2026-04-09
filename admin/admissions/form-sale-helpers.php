<?php
/**
 * Form Sale Sub-Module – Shared Helpers
 */

require_once __DIR__ . '/helpers.php';

// ── Upload sub-directory ──────────────────────────────────────────────────────
define('ADM_FS_TPL_SUBDIR', 'admissions/fs-templates');

// ── Invoice field definitions (key => label) ──────────────────────────────────
function adm_fs_invoice_fields(): array
{
    return [
        'form_number'  => 'Form Number',
        'buyer_name'   => 'Buyer Full Name',
        'buyer_email'  => 'Buyer Email',
        'buyer_mobile' => 'Buyer Mobile',
        'form_price'   => 'Form Price (Taka)',
        'sold_date'    => 'Date of Sale',
        'sold_by_name' => 'Sold By',
    ];
}

// ── Settings ──────────────────────────────────────────────────────────────────

function adm_fs_get_setting(string $key, string $default = ''): string
{
    return adm_get_setting($key, $default);
}

function adm_fs_save_setting(string $key, string $value): void
{
    adm_save_setting($key, $value);
}

// ── Form-sale number generator ────────────────────────────────────────────────

function adm_fs_generate_number(): string
{
    $db = db();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT setting_value FROM admissions_settings WHERE setting_key = ? FOR UPDATE'
        );
        $stmt->execute(['next_fs_number']);
        $current = (int)($stmt->fetchColumn() ?: 1);

        $db->prepare(
            'INSERT INTO admissions_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['next_fs_number', (string)($current + 1)]);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return (string)$current;
}

// ── Template helpers ──────────────────────────────────────────────────────────

function adm_fs_get_template(): array|false
{
    return db()->query('SELECT * FROM adm_fs_templates ORDER BY id DESC LIMIT 1')->fetch() ?: false;
}

function adm_fs_upload_template(array $file): string|false
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($file['size'] > ADM_MAX_TPL) {
        flash_set('error', 'Template file exceeds 20 MB limit.');
        return false;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ADM_TPL_EXTS, true)) {
        flash_set('error', 'Invalid template file type.');
        return false;
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ADM_TPL_MIMES, true)) {
        flash_set('error', 'Invalid template MIME type.');
        return false;
    }
    $dir = UPLOAD_DIR . '/' . ADM_FS_TPL_SUBDIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $stored = uniqid('fs_tpl_', true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        flash_set('error', 'Failed to save template file.');
        return false;
    }
    return $stored;
}

// ── Mapping helpers ───────────────────────────────────────────────────────────

function adm_fs_get_mappings(): array
{
    $rows = db()->query('SELECT * FROM adm_fs_field_mappings')->fetchAll();
    $indexed = [];
    foreach ($rows as $row) {
        $indexed[$row['field_key']] = $row;
    }
    return $indexed;
}

// ── Form-sale CRUD helpers ────────────────────────────────────────────────────

function adm_fs_get(int $id): array
{
    $stmt = db()->prepare(
        'SELECT fs.*, u.full_name AS sold_by_name
         FROM adm_form_sales fs
         LEFT JOIN users u ON u.id = fs.sold_by
         WHERE fs.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Form sale record not found.');
        redirect(APP_URL . '/admissions/form-sale-index.php');
    }
    return $row;
}

function adm_fs_status_badge(string $status): string
{
    $map = [
        'pending'   => ['bg-warning text-dark', 'Waiting for Admission'],
        'used'      => ['bg-success',            'Used'],
        'cancelled' => ['bg-secondary',          'Cancelled'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

// ── Invoice field value resolver ──────────────────────────────────────────────

function adm_fs_field_value(array $sale, string $field_key): string
{
    switch ($field_key) {
        case 'form_number':
            return (string)($sale['form_number'] ?? '');
        case 'buyer_name':
            return (string)($sale['buyer_name'] ?? '');
        case 'buyer_email':
            return (string)($sale['buyer_email'] ?? '');
        case 'buyer_mobile':
            return (string)($sale['buyer_mobile'] ?? '');
        case 'form_price':
            return number_format((float)($sale['form_price'] ?? 0), 2);
        case 'sold_date':
            if (!empty($sale['sold_at'])) {
                return date('d/m/Y', strtotime($sale['sold_at']));
            }
            return date('d/m/Y');
        case 'sold_by_name':
            return (string)($sale['sold_by_name'] ?? '');
        default:
            return '';
    }
}
