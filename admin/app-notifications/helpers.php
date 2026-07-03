<?php
/**
 * App Notification – Helpers
 * ==========================
 * Database helpers plus a Firebase Cloud Messaging (FCM) HTTP v1 sender used by
 * the "App Notification" admin module. Publishing a notification here delivers a
 * push notification to every student who has installed the Android app and
 * registered an FCM device token (see admin/api/student/push/register.php →
 * `student_push_tokens`).
 *
 * FCM HTTP v1 requires a Google service-account credential (JSON). Paste the
 * service-account JSON on the module's Settings page; it is stored in the
 * `settings` table under the key `fcm_service_account`. The legacy FCM server
 * key API (admin/api/includes/fcm.php) was shut down by Google in 2024, so this
 * module intentionally uses the current OAuth2 / HTTP v1 flow.
 */

require_once __DIR__ . '/../includes/db.php';

const APN_FCM_SCOPE   = 'https://www.googleapis.com/auth/firebase.messaging';
const APN_SETTING_KEY = 'fcm_service_account';

// ── Settings (settings table) ───────────────────────────────────────────────

/** Read a value from the `settings` table. */
function apn_setting_get(string $key, string $default = ''): string
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

/** Insert/update a value in the `settings` table. */
function apn_setting_set(string $key, string $value, string $label = '', string $group = 'push'): void
{
    db()->prepare(
        "INSERT INTO settings (`key`, `value`, `label`, `group`)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    )->execute([$key, $value, $label, $group]);
}

/**
 * Parse and validate the stored service-account JSON.
 * Returns the decoded array or null when not configured / invalid.
 */
function apn_fcm_service_account(): ?array
{
    $raw = apn_setting_get(APN_SETTING_KEY, '');
    if ($raw === '') {
        return null;
    }
    $sa = json_decode($raw, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
        return null;
    }
    return $sa;
}

/** Whether the module has a usable FCM credential configured. */
function apn_fcm_is_configured(): bool
{
    return apn_fcm_service_account() !== null;
}

// ── OAuth2 access token (JWT bearer, RS256) ─────────────────────────────────

function apn_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Obtain a short-lived OAuth2 access token for the FCM HTTP v1 API.
 * Cached per-request. Returns null on failure.
 */
function apn_fcm_access_token(array $sa): ?string
{
    static $cache = null;
    if ($cache !== null && $cache['exp'] > time() + 30) {
        return $cache['token'];
    }

    $token_uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $now       = time();

    $header = apn_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = apn_base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => APN_FCM_SCOPE,
        'aud'   => $token_uri,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $signature    = '';
    $signingInput = $header . '.' . $claim;
    if (!openssl_sign($signingInput, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
        error_log('APN FCM: failed to sign JWT with service-account private key.');
        return null;
    }
    $jwt = $signingInput . '.' . apn_base64url($signature);

    $ch = curl_init($token_uri);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || $response === false) {
        error_log("APN FCM: token endpoint HTTP $http – " . ($response ?: 'no response'));
        return null;
    }
    $json = json_decode($response, true);
    if (empty($json['access_token'])) {
        error_log('APN FCM: token response missing access_token – ' . $response);
        return null;
    }

    $cache = [
        'token' => (string)$json['access_token'],
        'exp'   => $now + (int)($json['expires_in'] ?? 3600),
    ];
    return $cache['token'];
}

// ── Sending ─────────────────────────────────────────────────────────────────

/**
 * Send one message via FCM HTTP v1.
 * @return array{ok:bool, unregister:bool, error:string}
 */
function apn_fcm_send_single(string $accessToken, string $projectId, string $token, string $title, string $body, array $data = []): array
{
    // FCM data payload values must be strings.
    $stringData = [];
    foreach ($data as $k => $v) {
        if ($v === null || $v === '') continue;
        $stringData[(string)$k] = (string)$v;
    }

    $message = [
        'message' => [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => $stringData,
            'android'      => [
                'priority'     => 'high',
                'notification' => ['sound' => 'default'],
            ],
        ],
    ];

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POSTFIELDS     => json_encode($message),
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        return ['ok' => true, 'unregister' => false, 'error' => ''];
    }

    // Decode the FCM error to decide whether the token is dead.
    $json    = json_decode((string)$response, true);
    $status  = $json['error']['status'] ?? '';
    $errCode = '';
    foreach ($json['error']['details'] ?? [] as $d) {
        if (($d['@type'] ?? '') === 'type.googleapis.com/google.firebase.fcm.v1.FcmError') {
            $errCode = $d['errorCode'] ?? '';
        }
    }
    $unregister = in_array($errCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)
        || $status === 'NOT_FOUND';

    error_log("APN FCM: send failed HTTP $http status=$status errorCode=$errCode");
    return ['ok' => false, 'unregister' => $unregister, 'error' => trim($status . ' ' . $errCode)];
}

/**
 * Deliver a notification to every registered student device token.
 *
 * @return array{total:int, sent:int, failed:int, status:string, error:?string}
 */
function apn_send_to_all_students(string $title, string $body, ?string $url = null): array
{
    $result = ['total' => 0, 'sent' => 0, 'failed' => 0, 'status' => 'failed', 'error' => null];

    $sa = apn_fcm_service_account();
    if ($sa === null) {
        $result['error'] = 'FCM is not configured. Add the service-account JSON on the Settings page.';
        return $result;
    }

    $accessToken = apn_fcm_access_token($sa);
    if ($accessToken === null) {
        $result['error'] = 'Could not obtain an FCM access token. Check the service-account credential.';
        return $result;
    }

    $tokens = db()->query(
        "SELECT id, fcm_token FROM student_push_tokens
         WHERE fcm_token IS NOT NULL AND fcm_token != ''"
    )->fetchAll(PDO::FETCH_ASSOC);

    $result['total'] = count($tokens);
    if ($result['total'] === 0) {
        $result['status'] = 'sent';
        return $result;
    }

    $data = [
        'type'  => 'app_notification',
        'title' => $title,
        'body'  => $body,
    ];
    if (!empty($url)) {
        $data['url'] = $url;
    }

    $staleIds = [];
    foreach ($tokens as $row) {
        $r = apn_fcm_send_single($accessToken, $sa['project_id'], $row['fcm_token'], $title, $body, $data);
        if ($r['ok']) {
            $result['sent']++;
        } else {
            $result['failed']++;
            if ($r['unregister']) {
                $staleIds[] = (int)$row['id'];
            }
        }
    }

    // Prune tokens that FCM reported as permanently invalid.
    if ($staleIds) {
        $ph = implode(',', array_fill(0, count($staleIds), '?'));
        db()->prepare("DELETE FROM student_push_tokens WHERE id IN ($ph)")->execute($staleIds);
    }

    if ($result['sent'] > 0 && $result['failed'] === 0) {
        $result['status'] = 'sent';
    } elseif ($result['sent'] > 0) {
        $result['status'] = 'partial';
    } else {
        $result['status'] = 'failed';
        $result['error']  = 'All deliveries failed. See the server error log for details.';
    }

    return $result;
}

// ── History (app_notifications table) ───────────────────────────────────────

function apn_record(string $title, string $body, ?string $url, ?int $sentBy, array $result): int
{
    $stmt = db()->prepare(
        "INSERT INTO app_notifications
            (title, body, url, sent_by, status, total_tokens, sent_count, failed_count)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $title, $body, ($url ?: null), $sentBy,
        $result['status'], $result['total'], $result['sent'], $result['failed'],
    ]);
    return (int)db()->lastInsertId();
}

function apn_list(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = db()->prepare(
        "SELECT n.*, u.full_name AS sender_name
         FROM app_notifications n
         LEFT JOIN users u ON u.id = n.sent_by
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function apn_find(int $id): ?array
{
    $stmt = db()->prepare(
        "SELECT n.*, u.full_name AS sender_name
         FROM app_notifications n
         LEFT JOIN users u ON u.id = n.sent_by
         WHERE n.id = ? LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Count of currently registered student devices. */
function apn_device_count(): int
{
    try {
        return (int)db()->query(
            "SELECT COUNT(*) FROM student_push_tokens WHERE fcm_token IS NOT NULL AND fcm_token != ''"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Bootstrap-friendly status badge for a notification row. */
function apn_status_badge(string $status): string
{
    return match ($status) {
        'sent'    => '<span class="badge bg-success">Sent</span>',
        'partial' => '<span class="badge bg-warning text-dark">Partial</span>',
        default   => '<span class="badge bg-danger">Failed</span>',
    };
}
