<?php
/**
 * Third-party API – Client Key Authentication Middleware
 * ======================================================
 * Include at the top of every /admin/api/v1 endpoint that is meant for
 * external (partner / third-party) systems.  Unlike auth_api.php, which
 * authenticates a *person* holding a short-lived bearer token, this middleware
 * authenticates an *application* via a long-lived API key issued from the
 * api_clients table (see admin/student-api-clients-v1.sql).
 *
 *   X-API-Key: pu_xxxxxxxx…              (preferred)
 *   Authorization: Bearer pu_xxxxxxxx…   (also accepted)
 *
 * Provides scopes, an IP allow-list, per-minute rate limiting, idempotent
 * replay (X-Idempotency-Key) and a request audit log (api_client_requests).
 *
 * Usage:
 *   require_once __DIR__ . '/../../includes/auth_client_api.php';
 *   $client = capi_auth('students:create');
 *   capi_begin_request($client, 'v1/students/create');
 *   …
 *   capi_ok(['data' => $result], 201);
 */

// Keep the API audit table out of the automatic row snapshots (pure noise).
if (!defined('SNAP_EXCLUDED_TABLES')) {
    define('SNAP_EXCLUDED_TABLES', ['db_snapshots', 'change_log', 'auth_remember_tokens', 'api_client_requests']);
}

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once __DIR__ . '/authorization_header.php';

const CAPI_VERSION      = '1.0';
const CAPI_KEY_PATTERN  = '/^pu_[a-f0-9]{48}$/i';
const CAPI_IDEM_MAX_LEN = 100;
const CAPI_RATE_WINDOW  = 60;   // seconds
const CAPI_STALE_SECS   = 120;  // a "running" request older than this is considered dead

// JSON API: never leak PHP notices / HTML into the response body.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-API-Version: ' . CAPI_VERSION);
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key, X-Idempotency-Key');
header('Access-Control-Expose-Headers: X-API-Version, X-RateLimit-Limit, X-RateLimit-Remaining, X-Idempotent-Replayed, Retry-After');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Per-request context shared by the helpers below. */
$GLOBALS['CAPI'] = [
    'client'          => null,
    'request_id'      => null,
    'idempotency_key' => null,
    'student_db_id'   => null,
];

// If PHP dies with a fatal error mid-request, still close the audit row so the
// idempotency key is not left "in progress" and the client gets JSON back.
register_shutdown_function(static function (): void {
    if (empty($GLOBALS['CAPI']['request_id'])) {
        return;
    }
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $body = json_encode(['ok' => false, 'code' => 'server_error', 'message' => 'Unexpected server error.']);
        capi_finish_request(500, $body);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo $body;
    }
});

// ── Response helpers ─────────────────────────────────────────────────────────

/** Encode, log and send a JSON response, then terminate. */
function capi_respond(int $status, array $payload): never
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $status = 500;
        $body   = '{"ok":false,"code":"encoding_error","message":"Response could not be encoded."}';
    }
    capi_finish_request($status, $body);
    http_response_code($status);
    echo $body;
    exit;
}

/**
 * Send a JSON error and terminate.
 *
 * @param string $code    Stable machine-readable error code (see API-GUIDE.md).
 * @param array  $errors  Optional field => message map for validation failures.
 */
function capi_error(int $status, string $code, string $message, array $errors = []): never
{
    $payload = ['ok' => false, 'code' => $code, 'message' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    capi_respond($status, $payload);
}

/** Send a JSON success response and terminate. */
function capi_ok(array $data = [], int $status = 200): never
{
    capi_respond($status, ['ok' => true] + $data);
}

// ── Request helpers ──────────────────────────────────────────────────────────

/** Read an arbitrary request header regardless of SAPI / casing. */
function capi_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$key])) {
        return trim((string)$_SERVER[$key]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0 && $v !== '') {
                return trim((string)$v);
            }
        }
    }
    return '';
}

/**
 * The connecting IP.  Deliberately ignores X-Forwarded-For (trivially spoofed)
 * because this value is compared against the client's IP allow-list.
 */
function capi_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** Extract the API key from X-API-Key or Authorization: Bearer. */
function capi_extract_key(): string
{
    $key = capi_header('X-API-Key');
    if ($key !== '') {
        return $key;
    }
    if (preg_match('/^Bearer\s+(.+)$/i', api_authorization_header(), $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Parse the request body.  Accepts application/json, or multipart/form-data
 * and x-www-form-urlencoded (needed for photo uploads).  In multipart mode a
 * `payload` field may carry the whole JSON document; any plain form fields
 * sent alongside override keys of the same name.
 */
function capi_json_input(): array
{
    $ctype = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ctype, 'application/json')) {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            capi_error(400, 'invalid_json', 'Request body is not valid JSON: ' . json_last_error_msg());
        }
        return $data;
    }

    $data = $_POST;
    if (isset($data['payload']) && is_string($data['payload'])) {
        $decoded = json_decode($data['payload'], true);
        if (!is_array($decoded)) {
            capi_error(400, 'invalid_json', 'The "payload" form field is not valid JSON: ' . json_last_error_msg());
        }
        unset($data['payload']);
        $data = array_replace($decoded, $data);
    }
    return $data;
}

// ── IP allow-list ────────────────────────────────────────────────────────────

function capi_ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || !is_numeric($parts[1])) {
        return false;
    }
    $ip_bin  = inet_pton($ip);
    $sub_bin = inet_pton($parts[0]);
    if ($ip_bin === false || $sub_bin === false || strlen($ip_bin) !== strlen($sub_bin)) {
        return false;
    }
    $bits = (int)$parts[1];
    $max  = strlen($ip_bin) * 8;
    if ($bits < 0 || $bits > $max) {
        return false;
    }
    $full = intdiv($bits, 8);
    $rem  = $bits % 8;
    if ($full > 0 && substr($ip_bin, 0, $full) !== substr($sub_bin, 0, $full)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return ((ord($ip_bin[$full]) ^ ord($sub_bin[$full])) & $mask) === 0;
}

/** Empty / NULL allow-list means any IP is accepted. */
function capi_ip_allowed(string $ip, ?string $allowlist): bool
{
    $allowlist = trim((string)$allowlist);
    if ($allowlist === '') {
        return true;
    }
    if ($ip === '') {
        return false;
    }
    foreach (preg_split('/[\s,]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY) as $rule) {
        if (strcasecmp($rule, $ip) === 0) {
            return true;
        }
        if (str_contains($rule, '/') && capi_ip_in_cidr($ip, $rule)) {
            return true;
        }
    }
    return false;
}

// ── Authentication ───────────────────────────────────────────────────────────

/** True when the client's scope list contains $scope (or the wildcard "*"). */
function capi_has_scope(array $client, string $scope): bool
{
    $scopes = array_filter(array_map('trim', explode(',', (string)($client['scopes'] ?? ''))));
    return in_array($scope, $scopes, true) || in_array('*', $scopes, true);
}

/**
 * Authenticate the calling application and enforce scope, IP allow-list and
 * rate limit.  Returns the api_clients row or terminates with a JSON error.
 */
function capi_auth(string $required_scope): array
{
    $raw = capi_extract_key();
    if ($raw === '') {
        capi_error(401, 'missing_api_key', 'Provide your API key in the X-API-Key header.');
    }
    if (!preg_match(CAPI_KEY_PATTERN, $raw)) {
        capi_error(401, 'invalid_api_key', 'The API key format is not recognised.');
    }

    try {
        $stmt = db()->prepare('SELECT * FROM api_clients WHERE key_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $raw)]);
        $client = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('auth_client_api: ' . $e->getMessage() . ' (is admin/student-api-clients-v1.sql applied?)');
        capi_error(500, 'server_error', 'API client store is unavailable.');
    }

    if (!$client) {
        capi_error(401, 'invalid_api_key', 'The API key is not valid.');
    }
    if (!(int)$client['is_active']) {
        capi_error(403, 'client_disabled', 'This API client has been disabled. Contact the university IT office.');
    }
    if ($client['expires_at'] !== null && strtotime((string)$client['expires_at']) < time()) {
        capi_error(401, 'api_key_expired', 'This API key has expired. Request a new key from the university IT office.');
    }

    $ip = capi_client_ip();
    if (!capi_ip_allowed($ip, $client['ip_allowlist'])) {
        capi_error(403, 'ip_not_allowed', 'Requests from ' . ($ip ?: 'this address') . ' are not permitted for this API client.');
    }

    if (!capi_has_scope($client, $required_scope)) {
        capi_error(403, 'insufficient_scope', 'This API key does not have the "' . $required_scope . '" scope.');
    }

    // ── Sliding-window rate limit (requests logged in the last 60 s) ─────────
    $limit = max(1, (int)$client['rate_limit_per_min']);
    try {
        $cnt = db()->prepare(
            'SELECT COUNT(*) FROM api_client_requests
              WHERE client_id = ? AND created_at >= (NOW() - INTERVAL ? SECOND)'
        );
        $cnt->execute([(int)$client['id'], CAPI_RATE_WINDOW]);
        $used = (int)$cnt->fetchColumn();
    } catch (Throwable $e) {
        error_log('auth_client_api rate-limit: ' . $e->getMessage());
        $used = 0;
    }
    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - $used - 1));
    if ($used >= $limit) {
        header('Retry-After: ' . CAPI_RATE_WINDOW);
        capi_error(429, 'rate_limited', 'Rate limit of ' . $limit . ' requests per minute exceeded. Retry after ' . CAPI_RATE_WINDOW . ' seconds.');
    }

    try {
        db()->prepare('UPDATE api_clients SET last_used_at = NOW() WHERE id = ?')->execute([(int)$client['id']]);
    } catch (Throwable $e) {
        // non-fatal
    }

    $GLOBALS['CAPI']['client'] = $client;
    return $client;
}

// ── Audit log + idempotency ──────────────────────────────────────────────────

/**
 * Register the request in api_client_requests.  When the caller sends an
 * X-Idempotency-Key that was already completed successfully, the stored
 * response is replayed verbatim and the script terminates.
 */
function capi_begin_request(array $client, string $endpoint): void
{
    $idem = capi_header('X-Idempotency-Key');
    if ($idem !== '' && (strlen($idem) > CAPI_IDEM_MAX_LEN || !preg_match('/^[A-Za-z0-9._:\-]+$/', $idem))) {
        capi_error(400, 'invalid_idempotency_key', 'X-Idempotency-Key must be 1-' . CAPI_IDEM_MAX_LEN . ' characters using letters, digits, ".", "_", ":" or "-".');
    }

    $db     = db();
    $cid    = (int)$client['id'];
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $ip     = capi_client_ip();

    try {
        if ($idem !== '') {
            $prev = $db->prepare(
                'SELECT id, status_code, response_body, created_at
                   FROM api_client_requests
                  WHERE client_id = ? AND idempotency_key = ? LIMIT 1'
            );
            $prev->execute([$cid, $idem]);
            $row = $prev->fetch();

            if ($row) {
                $status = $row['status_code'] === null ? null : (int)$row['status_code'];
                $age    = time() - (int)strtotime((string)$row['created_at']);

                if ($status === null && $age < CAPI_STALE_SECS) {
                    header('Retry-After: 5');
                    capi_error(409, 'request_in_progress', 'A request with this X-Idempotency-Key is still being processed. Retry in a few seconds.');
                }
                if ($status !== null && $status >= 200 && $status < 300 && $row['response_body'] !== null) {
                    header('X-Idempotent-Replayed: true');
                    http_response_code($status);
                    echo $row['response_body'];
                    exit;
                }
                // Previous attempt failed (or died) → reuse the row for this retry.
                $db->prepare(
                    'UPDATE api_client_requests
                        SET status_code = NULL, response_body = NULL, student_db_id = NULL,
                            endpoint = ?, method = ?, ip_address = ?, created_at = NOW()
                      WHERE id = ?'
                )->execute([$endpoint, $method, $ip ?: null, (int)$row['id']]);
                $GLOBALS['CAPI']['request_id']      = (int)$row['id'];
                $GLOBALS['CAPI']['idempotency_key'] = $idem;
                return;
            }
        }

        $db->prepare(
            'INSERT INTO api_client_requests (client_id, endpoint, method, idempotency_key, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$cid, $endpoint, $method, $idem !== '' ? $idem : null, $ip ?: null]);

        $GLOBALS['CAPI']['request_id']      = (int)$db->lastInsertId();
        $GLOBALS['CAPI']['idempotency_key'] = $idem !== '' ? $idem : null;
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Two identical requests raced on the unique (client_id, idempotency_key).
            header('Retry-After: 5');
            capi_error(409, 'request_in_progress', 'A request with this X-Idempotency-Key is still being processed. Retry in a few seconds.');
        }
        error_log('auth_client_api begin_request: ' . $e->getMessage());
        capi_error(500, 'server_error', 'Could not register the request.');
    }
}

/** Close the audit row.  Called automatically by capi_respond(). */
function capi_finish_request(int $status, string $body): void
{
    $rid = $GLOBALS['CAPI']['request_id'] ?? null;
    if (!$rid) {
        return;
    }
    $GLOBALS['CAPI']['request_id'] = null; // guard against double-finish

    $store = ($GLOBALS['CAPI']['idempotency_key'] !== null && $status >= 200 && $status < 300) ? $body : null;
    try {
        db()->prepare(
            'UPDATE api_client_requests SET status_code = ?, response_body = ?, student_db_id = ? WHERE id = ?'
        )->execute([$status, $store, $GLOBALS['CAPI']['student_db_id'], $rid]);
    } catch (Throwable $e) {
        error_log('auth_client_api finish_request: ' . $e->getMessage());
    }
}
