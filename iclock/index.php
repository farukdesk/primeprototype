<?php
/**
 * ADMS / iclock receiver for ZKTeco "Cloud Server (ADMS)" devices.
 * =============================================================================
 * The device (e.g. ZKTeco ECO) dials OUT to this server using its hard-coded
 * PUSH protocol paths and uploads attendance logs. We translate those punches
 * into the Staff Attendance module (att_records) via the shared ADMS helpers.
 *
 * Point the device's Cloud Server / ADMS settings at this server:
 *   • Server Address : this server's public IP or domain
 *   • Server Port    : the port this app listens on (usually 80, or 443 behind
 *                      a TLS-terminating proxy)
 *   • Enable Domain  : ON if you entered a domain, OFF for a raw IP
 * The device then talks to  http(s)://<address>:<port>/iclock/…  automatically;
 * the "/iclock/" path is fixed by the device firmware and cannot be changed.
 *
 * This endpoint is intentionally OUTSIDE the admin auth wall (the device cannot
 * log in). It authenticates each request by the device serial number, plus the
 * optional per-device IP allow-list and shared secret configured in the admin
 * "Devices" page. Everything is audited in att_device_log.
 *
 * Endpoints handled (device-driven):
 *   GET  /iclock/cdata?SN=…&options=all   → handshake (returns option block)
 *   POST /iclock/cdata?SN=…&table=ATTLOG  → attendance upload (returns "OK: N")
 *   GET  /iclock/getrequest?SN=…          → command poll (returns "OK")
 * Other tables/paths are acknowledged with "OK" so the device clears its buffer.
 */

declare(strict_types=1);

// Never render HTML errors into the protocol stream.
ini_set('display_errors', '0');

require_once __DIR__ . '/../admin/staff-attendance/adms-helpers.php';

// ── Plain-text protocol responses ────────────────────────────────────────────
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Emit a response, close the connection and stop. */
function adms_respond(string $body, int $status = 200): never
{
    http_response_code($status);
    echo $body;
    exit;
}

// ── Request basics ───────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ip     = adms_client_ip();

$path     = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$endpoint = strtolower(basename($path));                 // e.g. "cdata"
$endpoint = preg_replace('/\.php$/', '', $endpoint) ?? $endpoint;
if ($endpoint === '' || $endpoint === 'iclock' || $endpoint === 'index') {
    // Fall back to an explicit override or treat as a poll.
    $endpoint = strtolower((string)($_GET['endpoint'] ?? 'getrequest'));
}

$serial = trim((string)($_GET['SN'] ?? $_GET['sn'] ?? ''));
$table  = strtoupper(trim((string)($_GET['table'] ?? '')));

// ── Authenticate the device by serial number ─────────────────────────────────
$device = $serial !== '' ? adms_find_device($serial) : null;

if ($device === null) {
    adms_log_request([
        'serial_no' => $serial, 'ip' => $ip, 'endpoint' => $endpoint,
        'method' => $method, 'table_name' => $table,
        'http_status' => 401, 'result' => 'unknown_sn',
    ]);
    adms_respond("Unauthorized\n", 401);
}

if ((int)$device['is_active'] !== 1) {
    adms_log_request([
        'device_id' => (int)$device['id'], 'serial_no' => $serial, 'ip' => $ip,
        'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
        'http_status' => 401, 'result' => 'inactive',
    ]);
    adms_respond("Unauthorized\n", 401);
}

if (!adms_ip_allowed($device, $ip)) {
    adms_log_request([
        'device_id' => (int)$device['id'], 'serial_no' => $serial, 'ip' => $ip,
        'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
        'http_status' => 401, 'result' => 'ip_blocked',
    ]);
    adms_respond("Unauthorized\n", 401);
}

$provided_secret = (string)($_GET['key']
    ?? $_POST['key']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['HTTP_X_ADMS_KEY']
    ?? '');
if (!adms_secret_ok($device, $provided_secret)) {
    adms_log_request([
        'device_id' => (int)$device['id'], 'serial_no' => $serial, 'ip' => $ip,
        'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
        'http_status' => 401, 'result' => 'bad_secret',
    ]);
    adms_respond("Unauthorized\n", 401);
}

$device_id = (int)$device['id'];

// ── Dispatch ─────────────────────────────────────────────────────────────────
if ($endpoint === 'getrequest') {
    // Command poll. We have no queued commands, so acknowledge to keep the
    // device happy (otherwise it flags the server as down).
    adms_touch_device($device_id, $ip);
    adms_log_request([
        'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
        'endpoint' => $endpoint, 'method' => $method,
        'http_status' => 200, 'result' => 'ok',
    ]);
    adms_respond("OK\n");
}

if ($endpoint === 'cdata') {
    if ($method === 'GET') {
        // Handshake: return the option block for this SN.
        adms_touch_device($device_id, $ip);
        adms_log_request([
            'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
            'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
            'http_status' => 200, 'result' => 'handshake',
        ]);
        adms_respond(adms_options_block($device));
    }

    // POST: a data upload. We only fold attendance logs; other tables (OPERLOG,
    // OPTIONS, …) are acknowledged so the device advances its cursor.
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > ADMS_MAX_BODY_BYTES) {
        adms_log_request([
            'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
            'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
            'http_status' => 413, 'result' => 'too_large',
        ]);
        adms_respond("OK\n"); // still ack so the device does not loop forever
    }

    $body  = (string)file_get_contents('php://input', false, null, 0, ADMS_MAX_BODY_BYTES + 1);
    $stamp = trim((string)($_GET['Stamp'] ?? $_GET['stamp'] ?? ''));

    if ($table !== 'ATTLOG') {
        // Acknowledge non-attendance uploads without storing them.
        adms_touch_device($device_id, $ip, $stamp !== '' ? $stamp : null);
        adms_log_request([
            'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
            'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
            'http_status' => 200, 'result' => 'ack_other',
        ]);
        adms_respond("OK\n");
    }

    // Parse + store attendance punches.
    $records  = adms_parse_attlog($body);
    $received = count($records);
    $stored   = 0;
    $fold     = []; // "user_id|date" => [user_id, date] to recompute once each

    foreach ($records as $rec) {
        $uid = adms_pin_to_user($device_id, $rec['pin']);
        if (adms_store_punch($device, $rec, $uid)) $stored++;
        if ($uid !== null) {
            $day = substr($rec['punch_time'], 0, 10);
            $fold[$uid . '|' . $day] = [$uid, $day];
        }
    }
    foreach ($fold as [$uid, $day]) {
        adms_fold_day((int)$uid, $day);
    }

    adms_touch_device($device_id, $ip, $stamp !== '' ? $stamp : null, true);
    adms_log_request([
        'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
        'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
        'received' => $received, 'stored' => $stored,
        'http_status' => 200, 'result' => 'ok',
    ]);

    // ZK devices clear their buffer only on the exact "OK: <count>" reply.
    adms_respond('OK: ' . $received . "\n");
}

// ── Any other device path (fdata, devicecmd, …): acknowledge ─────────────────
adms_touch_device($device_id, $ip);
adms_log_request([
    'device_id' => $device_id, 'serial_no' => $serial, 'ip' => $ip,
    'endpoint' => $endpoint, 'method' => $method, 'table_name' => $table,
    'http_status' => 200, 'result' => 'ack_other',
]);
adms_respond("OK\n");
