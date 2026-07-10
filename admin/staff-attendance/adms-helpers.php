<?php
/**
 * ADMS / iclock (ZKTeco Push) helpers for the Staff Attendance module.
 *
 * These functions are shared by two very different callers, so this file must
 * stay self-contained and NEVER require the admin auth layer:
 *
 *   1. The public receiver  /iclock/index.php  – runs with no session; the
 *      device authenticates only by its serial number (+ optional IP allow-list
 *      / shared secret). It must not redirect to a login page.
 *   2. The admin page  admin/staff-attendance/devices.php  – already includes
 *      the auth layer before including this file.
 *
 * It therefore only pulls in config + the PDO helper (both guarded by
 * require_once) and talks to the att_* tables directly.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

// Hard caps to keep the endpoint cheap and abuse-resistant.
const ADMS_MAX_BODY_BYTES = 1048576; // 1 MB per upload
const ADMS_MAX_RECORDS    = 20000;   // records parsed per upload

// ── Client IP ───────────────────────────────────────────────────────────────

/**
 * Best-effort source IP. We deliberately trust ONLY REMOTE_ADDR here (not
 * X-Forwarded-For) so a spoofed header cannot bypass the per-device IP
 * allow-list. When the app sits behind a trusted reverse proxy, terminate the
 * proxy so REMOTE_ADDR is the device address, or add the proxy IP to the list.
 */
function adms_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) ? $ip : '';
}

// ── Device lookup & security checks ─────────────────────────────────────────

/** Look up a device row by serial number (any state). */
function adms_find_device(string $serial): ?array
{
    $serial = trim($serial);
    if ($serial === '') return null;
    try {
        $stmt = db()->prepare('SELECT * FROM att_devices WHERE serial_no = ? LIMIT 1');
        $stmt->execute([$serial]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Whether the request IP is permitted for this device (empty list = any). */
function adms_ip_allowed(array $device, string $ip): bool
{
    $raw = trim((string)($device['allowed_ips'] ?? ''));
    if ($raw === '') return true; // no restriction configured
    foreach (explode(',', $raw) as $allowed) {
        $allowed = trim($allowed);
        if ($allowed !== '' && hash_equals($allowed, $ip)) return true;
    }
    return false;
}

/**
 * Whether the optional per-device shared secret is satisfied. When no secret is
 * configured this always passes. When configured, the request must present the
 * exact value via ?key=, an Authorization header, or a `key` POST field. The
 * comparison is constant-time.
 */
function adms_secret_ok(array $device, string $provided): bool
{
    $secret = (string)($device['secret_key'] ?? '');
    if ($secret === '') return true; // no secret configured
    return $provided !== '' && hash_equals($secret, $provided);
}

/** Update the device heartbeat after a successful, authenticated request. */
function adms_touch_device(int $device_id, string $ip, ?string $stamp = null, bool $pushed = false): void
{
    try {
        $sql = 'UPDATE att_devices
                   SET last_seen_at = NOW(), last_ip = ?'
             . ($stamp !== null && $stamp !== '' ? ', last_stamp = ?' : '')
             . ($pushed ? ', last_push_at = NOW()' : '')
             . ' WHERE id = ?';
        $params = [$ip];
        if ($stamp !== null && $stamp !== '') $params[] = $stamp;
        $params[] = $device_id;
        db()->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // Non-fatal: heartbeat is best-effort.
    }
}

// ── Request audit log ───────────────────────────────────────────────────────

/** Record one device request for auditing / troubleshooting. */
function adms_log_request(array $info): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO att_device_log
                (device_id, serial_no, ip, endpoint, method, table_name,
                 `received`, `stored_count`, http_status, result, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $info['device_id'] ?? null,
            isset($info['serial_no']) ? mb_substr((string)$info['serial_no'], 0, 64) : null,
            $info['ip']         ?? null,
            $info['endpoint']   ?? null,
            $info['method']     ?? null,
            isset($info['table_name']) ? mb_substr((string)$info['table_name'], 0, 32) : null,
            (int)($info['received'] ?? 0),
            (int)($info['stored'] ?? 0),
            (int)($info['http_status'] ?? 200),
            isset($info['result']) ? mb_substr((string)$info['result'], 0, 24) : null,
            isset($info['note']) ? mb_substr((string)$info['note'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        // Auditing must never break the receiver.
    }
}

// ── Settings (self-contained reader for att_settings) ───────────────────────

/** Read a Staff Attendance setting directly (no dependency on the auth layer). */
function adms_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_val FROM att_settings')->fetchAll() as $r) {
                $cache[(string)$r['setting_key']] = $r['setting_val'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** The user id credited as "created_by" for device-generated att_records rows. */
function adms_system_user_id(): int
{
    return max(0, (int)adms_setting('adms_system_user_id', '0'));
}

// ── PIN → user resolution ───────────────────────────────────────────────────

/**
 * Resolve a device PIN to a users.id. A device-specific mapping takes priority
 * over a global (device_id = 0) one. Returns null when the PIN is unmapped.
 */
function adms_pin_to_user(int $device_id, string $pin): ?int
{
    $pin = trim($pin);
    if ($pin === '') return null;
    try {
        $stmt = db()->prepare(
            'SELECT user_id FROM att_device_users
              WHERE is_active = 1 AND pin = ? AND device_id IN (?, 0)
              ORDER BY (device_id = ?) DESC
              LIMIT 1'
        );
        $stmt->execute([$pin, $device_id, $device_id]);
        $uid = $stmt->fetchColumn();
        return $uid !== false ? (int)$uid : null;
    } catch (Throwable $e) {
        return null;
    }
}

// ── ATTLOG parsing ──────────────────────────────────────────────────────────

/**
 * Parse a ZK ATTLOG upload body into structured records.
 * Each non-empty line is tab-delimited: PIN \t YYYY-MM-DD HH:MM:SS \t status \t
 * verify \t workcode \t … . Malformed lines are skipped (tolerant parsing).
 *
 * @return array<int, array{pin:string,punch_time:string,status:?string,verify:?string,raw:string}>
 */
function adms_parse_attlog(string $body): array
{
    $records = [];
    // Devices may separate records with \n, \r\n or \r.
    $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
    foreach ($lines as $line) {
        if (count($records) >= ADMS_MAX_RECORDS) break;
        $line = rtrim($line);
        if ($line === '') continue;
        $cols = explode("\t", $line);
        if (count($cols) < 2) continue;

        $pin = trim($cols[0]);
        $ts  = adms_normalize_datetime($cols[1] ?? '');
        if ($pin === '' || $ts === null) continue;

        $records[] = [
            'pin'        => mb_substr($pin, 0, 32),
            'punch_time' => $ts,
            'status'     => isset($cols[2]) && trim($cols[2]) !== '' ? mb_substr(trim($cols[2]), 0, 8) : null,
            'verify'     => isset($cols[3]) && trim($cols[3]) !== '' ? mb_substr(trim($cols[3]), 0, 8) : null,
            'raw'        => mb_substr($line, 0, 255),
        ];
    }
    return $records;
}

/** Normalise a device timestamp to "Y-m-d H:i:s", or null when unparseable. */
function adms_normalize_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

// ── Punch storage & folding into att_records ────────────────────────────────

/**
 * Idempotently store a single punch. Returns true when a NEW row was inserted
 * (duplicates from device re-sends are ignored via the unique key).
 */
function adms_store_punch(array $device, array $rec, ?int $user_id): bool
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO att_punch_log
                (device_id, serial_no, pin, user_id, punch_time, work_date,
                 status_code, verify_mode, raw_line)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                user_id     = VALUES(user_id),
                status_code = VALUES(status_code),
                verify_mode = VALUES(verify_mode),
                raw_line    = VALUES(raw_line)'
        );
        $stmt->execute([
            (int)$device['id'],
            (string)$device['serial_no'],
            $rec['pin'],
            $user_id,
            $rec['punch_time'],
            substr($rec['punch_time'], 0, 10),
            $rec['status'],
            $rec['verify'],
            $rec['raw'],
        ]);
        return $stmt->rowCount() === 1; // 1 = insert, 2/0 = duplicate update
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Recompute att_records for a user/day from the raw punch log: earliest punch of
 * the day becomes in_time, latest becomes out_time. This is order-independent,
 * so out-of-order or re-sent punches always converge to the correct row.
 */
function adms_fold_day(int $user_id, string $work_date): void
{
    if ($user_id < 1) return;
    try {
        $stmt = db()->prepare(
            'SELECT TIME(MIN(punch_time)) AS in_t, TIME(MAX(punch_time)) AS out_t
               FROM att_punch_log
              WHERE user_id = ? AND work_date = ?'
        );
        $stmt->execute([$user_id, $work_date]);
        $row = $stmt->fetch();
        if (!$row || $row['in_t'] === null) return;

        $in_t  = substr((string)$row['in_t'], 0, 5);   // HH:MM
        $out_t = substr((string)$row['out_t'], 0, 5);
        // A single punch day has in == out: keep in_time, leave out_time empty
        // so the status shows "No Out Time" rather than a bogus 0-minute day.
        if ($in_t === $out_t) $out_t = null;

        $upd = db()->prepare(
            'INSERT INTO att_records (user_id, work_date, in_time, out_time, created_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE in_time = VALUES(in_time), out_time = VALUES(out_time)'
        );
        $upd->execute([$user_id, $work_date, $in_t, $out_t, adms_system_user_id()]);
    } catch (Throwable $e) {
        // Folding is best-effort; the raw punch log remains the source of truth.
    }
}

// ── ADMS handshake response builder ─────────────────────────────────────────

/**
 * Build the plain-text option block returned to the device on the initial
 * GET /iclock/cdata handshake. Tells the device this is a valid ADMS host and
 * that it should push attendance logs in real time.
 */
function adms_options_block(array $device): string
{
    $sn    = (string)$device['serial_no'];
    $stamp = trim((string)($device['last_stamp'] ?? ''));
    if ($stamp === '') $stamp = '0';
    // TimeZone is in minutes for many ZK firmwares; Asia/Dhaka = +06:00 = 360.
    $tz = (int)adms_setting('adms_timezone_minutes', '360');

    $lines = [
        'GET OPTION FROM: ' . $sn,
        'Stamp=' . $stamp,
        'OpStamp=' . $stamp,
        'ATTLOGStamp=' . $stamp,
        'OPERLOGStamp=9999',
        'ATTPHOTOStamp=None',
        'ErrorDelay=30',
        'Delay=10',
        'TransTimes=00:00;14:00',
        'TransInterval=1',
        'TransFlag=TransData AttLog',
        'TimeZone=' . $tz,
        'Realtime=1',
        'Encrypt=0',
    ];
    return implode("\n", $lines) . "\n";
}
