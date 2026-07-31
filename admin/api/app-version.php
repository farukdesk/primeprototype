<?php
/**
 * Public API – GET /api/app-version.php
 * =====================================
 * Returns the latest Android app version so installed apps can prompt the
 * user to update (self-hosted APK distribution, outside Google Play).
 *
 * Configure via the `settings` table (group "app"):
 *   app_latest_version_code  – integer versionCode of the newest APK
 *   app_latest_version_name  – e.g. "1.1.0"
 *   app_apk_url              – absolute URL of the APK download
 *   app_update_notes         – optional "what's new" text
 *   app_force_update         – "1" to require the update before continuing
 *
 * Example (run once, then update values per release):
 *   INSERT INTO settings (`key`,`value`,`label`,`group`) VALUES
 *     ('app_latest_version_code','1','Latest app versionCode','app'),
 *     ('app_latest_version_name','1.0.0','Latest app version','app'),
 *     ('app_apk_url','','APK download URL','app'),
 *     ('app_update_notes','','App update notes','app'),
 *     ('app_force_update','0','Force app update','app')
 *   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function app_version_setting(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare("SELECT `value` FROM settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    } catch (Throwable $e) {
        return $default;
    }
}

echo json_encode([
    'ok'           => true,
    'version_code' => (int)app_version_setting('app_latest_version_code', '0'),
    'version_name' => app_version_setting('app_latest_version_name', ''),
    'apk_url'      => app_version_setting('app_apk_url', ''),
    'notes'        => app_version_setting('app_update_notes', ''),
    'force'        => app_version_setting('app_force_update', '0') === '1',
]);
