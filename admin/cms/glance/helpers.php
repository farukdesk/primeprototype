<?php
/**
 * PU At a Glance – shared helpers
 */

define('GLANCE_IMG_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('GLANCE_IMG_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('GLANCE_UPLOAD_SUBDIR', 'glance');

function glance_upload_image(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, GLANCE_IMG_EXTS, true)) return false;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, GLANCE_IMG_MIMES, true)) return false;
    $dir = UPLOAD_DIR . '/' . GLANCE_UPLOAD_SUBDIR;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = time() . '_' . bin2hex(random_bytes(10)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . '/' . $name) ? $name : false;
}

function glance_img_url(string $name): string
{
    return UPLOAD_URL . '/' . GLANCE_UPLOAD_SUBDIR . '/' . $name;
}

function glance_get_settings(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $rows = db()->query('SELECT setting_key, setting_val FROM glance_settings')->fetchAll();
    $s = [];
    foreach ($rows as $r) {
        $s[$r['setting_key']] = $r['setting_val'];
    }
    return $s;
}

function glance_save_setting(string $key, ?string $val): void
{
    db()->prepare(
        'INSERT INTO glance_settings (setting_key, setting_val) AS new_row
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = new_row.setting_val'
    )->execute([$key, $val]);
}
