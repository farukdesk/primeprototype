<?php
/**
 * System Backup Manager – shared helpers. SUPER ADMIN ONLY module.
 *
 * Full DB + files backups stored in GOOGLE DRIVE via a service account
 * (no external libraries – raw JWT + REST). Local disk is used only
 * transiently: dump/zip is created in the system temp dir, streamed to
 * Drive in 8 MB chunks, then deleted immediately.
 *
 * Retention: auto backups are 'daily' (kept 7 days) except the Sunday
 * run which is 'weekly' (kept 30 days). Manual backups are kept until
 * deleted by a super admin. Both windows are configurable in settings.
 */

require_once __DIR__ . '/../includes/auth.php';

const BK_CHUNK      = 8388608; // 8 MB Drive upload chunks
const BK_TMP_SUBDIR = 'pumis-backups';

// ── Settings (key/value) ──────────────────────────────────────────────────

function bk_setting_get(string $key, ?string $default = null): ?string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM sys_backup_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (string)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function bk_setting_set(string $key, ?string $value): void
{
    db()->prepare(
        'INSERT INTO sys_backup_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

function bk_tmp_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/\\') . '/' . BK_TMP_SUBDIR;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function bk_fmt_bytes($b): string
{
    $b = (float)$b;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($b < 1024) return round($b, 1) . ' ' . $u;
        $b /= 1024;
    }
    return round($b, 1) . ' PB';
}

// ── Google Drive client (service account, raw REST) ────────────────────────

/** Get (and cache) an OAuth2 access token for the configured service account. */
function bk_drive_token(): string
{
    $sa = json_decode((string)bk_setting_get('drive_service_account_json', ''), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('Google Drive is not configured – paste your service account JSON in Backup Settings.');
    }
    $cache = json_decode((string)bk_setting_get('drive_token_cache', ''), true);
    if (is_array($cache) && ($cache['expires'] ?? 0) > time() + 60 && !empty($cache['token'])) {
        return (string)$cache['token'];
    }

    $b64 = fn(string $d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $now = time();
    $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    if (!openssl_sign($jwt, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
        throw new RuntimeException('Could not sign JWT – invalid service account private key.');
    }
    $jwt .= '.' . $b64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    if ($code !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Google auth failed (HTTP ' . $code . '): ' . substr((string)$resp, 0, 300));
    }
    bk_setting_set('drive_token_cache', json_encode(['token' => $json['access_token'], 'expires' => $now + 3500]));
    return (string)$json['access_token'];
}

/** Verify credentials/folder. Returns a human-readable success message. */
function bk_drive_test(): string
{
    $token = bk_drive_token();
    $msg = 'Google Drive authentication OK.';
    $folder = trim((string)bk_setting_get('drive_folder_id', ''));
    if ($folder !== '') {
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($folder) . '?fields=id,name&supportsAllDrives=true');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        if ($code !== 200) {
            throw new RuntimeException('Folder not accessible (HTTP ' . $code . ') – share the Drive folder with the service account email.');
        }
        $msg .= ' Folder “' . ($json['name'] ?? $folder) . '” is accessible.';
    }
    return $msg;
}

/** Chunked resumable upload. Returns the Drive file ID. */
function bk_drive_upload(string $path, string $name): string
{
    $token = bk_drive_token();
    $size  = (int)filesize($path);
    $meta  = ['name' => $name];
    $folder = trim((string)bk_setting_get('drive_folder_id', ''));
    if ($folder !== '') $meta['parents'] = [$folder];

    // 1) Start a resumable session.
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Length: ' . $size,
        ],
        CURLOPT_POSTFIELDS     => json_encode($meta),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200 || !preg_match('/^location:\s*(.+)$/mi', (string)$resp, $m)) {
        throw new RuntimeException('Drive: could not start upload session (HTTP ' . $code . ').');
    }
    $session = trim($m[1]);

    // 2) Stream the file in 8 MB chunks.
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Cannot read temp file for upload.');
    $sent = 0;
    $file_id = null;
    while ($sent < $size) {
        $chunk = fread($fh, BK_CHUNK);
        $len   = strlen((string)$chunk);
        if ($len === 0) break;
        $ch = curl_init($session);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => [
                'Content-Length: ' . $len,
                'Content-Range: bytes ' . $sent . '-' . ($sent + $len - 1) . '/' . $size,
            ],
            CURLOPT_POSTFIELDS     => $chunk,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 308) { $sent += $len; continue; }        // chunk stored, Drive expects more
        if ($code === 200 || $code === 201) {                  // upload complete
            $sent += $len;
            $json = json_decode((string)$resp, true);
            $file_id = $json['id'] ?? null;
            break;
        }
        fclose($fh);
        throw new RuntimeException('Drive: chunk upload failed (HTTP ' . $code . '): ' . substr((string)$resp, 0, 200));
    }
    fclose($fh);
    if (!$file_id) throw new RuntimeException('Drive: upload finished but no file ID was returned.');
    return (string)$file_id;
}

/** Stream-download a Drive file to a local path. */
function bk_drive_download(string $file_id, string $dest): void
{
    $token = bk_drive_token();
    $fh = fopen($dest, 'wb');
    if (!$fh) throw new RuntimeException('Cannot create temp file: ' . $dest);
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 3600,
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $code !== 200) {
        @unlink($dest);
        throw new RuntimeException('Drive: download failed (HTTP ' . $code . ').');
    }
}

/** Delete a Drive file (ignores missing files). */
function bk_drive_delete(?string $file_id): void
{
    if (!$file_id) return;
    try {
        $token = bk_drive_token();
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?supportsAllDrives=true');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('bk_drive_delete: ' . $e->getMessage());
    }
}

// ── Backup creation ──────────────────────────────────────────────────────────

/** SQL-quote a value; newlines are escaped so every statement stays on ONE line. */
function bk_sql_value(PDO $pdo, mixed $v): string
{
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return str_replace(["\r", "\n"], ['\\r', '\\n'], $pdo->quote((string)$v));
}

/** Pure-PHP full database dump → gzip file (one statement per line). */
function bk_dump_database(string $gz_path, array &$log): void
{
    $pdo = db();
    $gz  = gzopen($gz_path, 'wb6');
    if (!$gz) throw new RuntimeException('Cannot create dump file in temp dir.');
    gzwrite($gz, "-- PUMIS database backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as [$table, $ttype]) {
        if (strcasecmp((string)$ttype, 'VIEW') === 0) {
            $row = $pdo->query('SHOW CREATE VIEW `' . $table . '`')->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "DROP VIEW IF EXISTS `$table`;\n" . preg_replace('/\s+/', ' ', (string)$row[1]) . ";\n");
            continue;
        }
        $row = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n" . str_replace("\n", ' ', (string)$row[1]) . ";\n");

        $offset = 0;
        $count  = 0;
        while (true) {
            $rows = $pdo->query("SELECT * FROM `$table` LIMIT 200 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(',', array_map(fn($v) => bk_sql_value($pdo, $v), array_values($r))) . ')';
            }
            gzwrite($gz, "INSERT INTO `$table` ($cols) VALUES " . implode(',', $vals) . ";\n");
            $count  += count($rows);
            $offset += 200;
        }
        $log[] = "db: $table ($count rows)";
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
}

/** Zip the whole site (admin's parent dir), excluding noise + configured paths. */
function bk_archive_files(string $zip_path, array &$log): void
{
    $root = realpath(__DIR__ . '/../../');
    if (!$root) throw new RuntimeException('Cannot resolve site root.');
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create zip archive in temp dir.');
    }
    $skip_names = ['.git', 'node_modules'];
    $extra = array_values(array_filter(array_map('trim', explode("\n", (string)bk_setting_get('exclude_paths', '')))));

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($f) use ($root, $skip_names, $extra) {
                if ($f->isDir() && in_array($f->getFilename(), $skip_names, true)) return false;
                $rel = str_replace('\\', '/', ltrim(substr($f->getPathname(), strlen($root)), '/\\'));
                foreach ($extra as $e) {
                    if ($e !== '' && str_starts_with($rel, trim($e, '/'))) return false;
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $n = 0;
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($root)), '/\\'));
        if ($zip->addFile($file->getPathname(), $rel)) $n++;
    }
    $log[] = "files: $n file(s) archived";
    if (!$zip->close()) throw new RuntimeException('Zip finalisation failed.');
}

/**
 * Run a backup: dump/zip to temp → upload to Google Drive → delete temp.
 * @return array{0: bool, 1: string} [ok, message]
 */
function bk_run_backup(string $scope, string $type, string $retention, ?int $user_id): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    $scope = in_array($scope, ['db', 'files', 'full'], true) ? $scope : 'full';
    $log   = [];

    db()->prepare('INSERT INTO sys_backups (backup_type, scope, retention_class, status, created_by) VALUES (?,?,?,?,?)')
        ->execute([$type, $scope, $retention, 'running', $user_id]);
    $id    = (int)db()->lastInsertId();
    $stamp = date('Y-m-d_His');
    $tmp   = bk_tmp_dir();

    try {
        if ($scope === 'db' || $scope === 'full') {
            $name = 'db-' . $stamp . '.sql.gz';
            $path = $tmp . '/' . $name;
            bk_dump_database($path, $log);
            $size = (int)filesize($path);
            $drive_id = bk_drive_upload($path, $name);
            @unlink($path); // local copy removed – no server space used
            db()->prepare('UPDATE sys_backups SET db_filename=?, db_drive_id=?, db_size_bytes=? WHERE id=?')
                ->execute([$name, $drive_id, $size, $id]);
            $log[] = 'database uploaded to Drive (' . bk_fmt_bytes($size) . ')';
        }
        if ($scope === 'files' || $scope === 'full') {
            $name = 'files-' . $stamp . '.zip';
            $path = $tmp . '/' . $name;
            bk_archive_files($path, $log);
            $size = (int)filesize($path);
            $drive_id = bk_drive_upload($path, $name);
            @unlink($path);
            db()->prepare('UPDATE sys_backups SET files_filename=?, files_drive_id=?, files_size_bytes=? WHERE id=?')
                ->execute([$name, $drive_id, $size, $id]);
            $log[] = 'files archive uploaded to Drive (' . bk_fmt_bytes($size) . ')';
        }
        db()->prepare('UPDATE sys_backups SET status=?, log=?, completed_at=NOW() WHERE id=?')
            ->execute(['completed', implode("\n", $log), $id]);
        return [true, 'Backup #' . $id . ' completed and stored in Google Drive.'];
    } catch (Throwable $e) {
        $log[] = 'ERROR: ' . $e->getMessage();
        try {
            db()->prepare('UPDATE sys_backups SET status=?, log=? WHERE id=?')
                ->execute(['failed', implode("\n", $log), $id]);
        } catch (Throwable $e2) {
        }
        return [false, 'Backup failed: ' . $e->getMessage()];
    }
}

/** Delete expired auto backups (daily > 7 days, weekly > 30 days) from Drive + DB. */
function bk_prune(array &$log = []): int
{
    $daily  = max(1, (int)bk_setting_get('keep_daily_days', '7'));
    $weekly = max($daily, (int)bk_setting_get('keep_weekly_days', '30'));
    $stmt = db()->prepare(
        "SELECT * FROM sys_backups
         WHERE backup_type = 'auto' AND (
               (retention_class = 'daily'  AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY))
            OR (retention_class = 'weekly' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY))
         )"
    );
    $stmt->execute([$daily, $weekly]);
    $n = 0;
    foreach ($stmt->fetchAll() as $b) {
        bk_drive_delete($b['db_drive_id'] ?? null);
        bk_drive_delete($b['files_drive_id'] ?? null);
        db()->prepare('DELETE FROM sys_backups WHERE id = ?')->execute([(int)$b['id']]);
        $log[] = 'pruned backup #' . $b['id'] . ' (' . $b['retention_class'] . ', ' . $b['created_at'] . ')';
        $n++;
    }
    return $n;
}

// ── Restore ────────────────────────────────────────────────────────────────────

/** Plain PDO (bypasses the row-snapshot wrapper – restores would be far too slow otherwise). */
function bk_raw_pdo(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/** Restore the database from a backup's Drive dump. */
function bk_restore_db(array $b, ?int $user_id): array
{
    if (empty($b['db_drive_id'])) return [false, 'This backup has no database file.'];
    @set_time_limit(0);
    @ignore_user_abort(true);
    $tmp = bk_tmp_dir() . '/restore-db-' . (int)$b['id'] . '.sql.gz';
    try {
        bk_drive_download((string)$b['db_drive_id'], $tmp);
        $pdo = bk_raw_pdo();
        $gz  = gzopen($tmp, 'rb');
        if (!$gz) throw new RuntimeException('Cannot open downloaded dump.');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $executed = 0;
        $buf = '';
        while (!gzeof($gz)) {
            $buf .= gzread($gz, 1048576);
            while (($p = strpos($buf, "\n")) !== false) {
                $line = trim(substr($buf, 0, $p));
                $buf  = substr($buf, $p + 1);
                if ($line === '' || str_starts_with($line, '--')) continue;
                $pdo->exec(rtrim($line, ';'));
                $executed++;
            }
        }
        if (trim($buf) !== '' && !str_starts_with(trim($buf), '--')) {
            $pdo->exec(rtrim(trim($buf), ';'));
            $executed++;
        }
        gzclose($gz);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        @unlink($tmp);
        db()->prepare('UPDATE sys_backups SET restored_at=NOW(), restored_by=? WHERE id=?')
            ->execute([$user_id, (int)$b['id']]);
        return [true, 'Database restored from backup #' . $b['id'] . ' (' . $executed . ' statements executed).'];
    } catch (Throwable $e) {
        @unlink($tmp);
        return [false, 'Database restore failed: ' . $e->getMessage()];
    }
}

/** Restore the site files from a backup's Drive archive (extracts over the site root). */
function bk_restore_files(array $b, ?int $user_id): array
{
    if (empty($b['files_drive_id'])) return [false, 'This backup has no files archive.'];
    @set_time_limit(0);
    @ignore_user_abort(true);
    $tmp = bk_tmp_dir() . '/restore-files-' . (int)$b['id'] . '.zip';
    try {
        bk_drive_download((string)$b['files_drive_id'], $tmp);
        $root = realpath(__DIR__ . '/../../');
        $zip  = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('Cannot open downloaded archive.');
        if (!$zip->extractTo($root)) throw new RuntimeException('Extraction failed – check file permissions.');
        $n = $zip->numFiles;
        $zip->close();
        @unlink($tmp);
        db()->prepare('UPDATE sys_backups SET restored_at=NOW(), restored_by=? WHERE id=?')
            ->execute([$user_id, (int)$b['id']]);
        return [true, 'Files restored from backup #' . $b['id'] . ' (' . $n . ' entries extracted).'];
    } catch (Throwable $e) {
        @unlink($tmp);
        return [false, 'Files restore failed: ' . $e->getMessage()];
    }
}

/** Status badge. */
function bk_status_badge(string $s): string
{
    return match ($s) {
        'completed' => '<span class="badge bg-success">Completed</span>',
        'failed'    => '<span class="badge bg-danger">Failed</span>',
        default     => '<span class="badge bg-primary">Running</span>',
    };
}
