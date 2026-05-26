<?php
/**
 * Firebase Cloud Messaging (FCM) Helper
 * ========================================
 * Provides send_push_notification() for broadcasting push notifications
 * to registered Android devices via Firebase Cloud Messaging HTTP v1 API.
 *
 * Pre-requisites:
 *  - FCM server key stored in settings table with key 'fcm_server_key'
 *  - api_push_tokens table populated by admin/api/push/register.php
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';

/**
 * Send a push notification to one or more admin user IDs.
 *
 * @param int[]       $user_ids  Target user IDs (empty = broadcast to ALL users with tokens)
 * @param string      $title     Notification title
 * @param string      $body      Notification body text
 * @param array       $data      Optional key/value data payload for the app
 * @return array{sent:int, failed:int}
 */
function send_push_notification(array $user_ids, string $title, string $body, array $data = []): array
{
    $server_key = fcm_get_server_key();
    if ($server_key === '') {
        error_log('PUMIS FCM: server key is not configured (settings.fcm_server_key).');
        return ['sent' => 0, 'failed' => 0];
    }

    $tokens = fcm_get_tokens($user_ids);
    if (empty($tokens)) {
        return ['sent' => 0, 'failed' => 0];
    }

    $sent   = 0;
    $failed = 0;

    foreach ($tokens as $fcm_token) {
        $result = fcm_send_single($server_key, $fcm_token, $title, $body, $data);
        if ($result) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Retrieve the FCM server key from the settings table.
 */
function fcm_get_server_key(): string
{
    static $key = null;
    if ($key === null) {
        $stmt = db()->prepare("SELECT `value` FROM settings WHERE `key` = 'fcm_server_key' LIMIT 1");
        $stmt->execute();
        $key = (string)($stmt->fetchColumn() ?? '');
    }
    return $key;
}

/**
 * Fetch FCM device tokens for the given user IDs.
 * If $user_ids is empty, all tokens are returned.
 */
function fcm_get_tokens(array $user_ids): array
{
    if (empty($user_ids)) {
        $rows = db()->query(
            "SELECT DISTINCT fcm_token FROM api_push_tokens
             WHERE fcm_token IS NOT NULL AND fcm_token != ''"
        )->fetchAll(\PDO::FETCH_COLUMN);
    } else {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt = db()->prepare(
            "SELECT DISTINCT fcm_token FROM api_push_tokens
             WHERE user_id IN ($placeholders) AND fcm_token IS NOT NULL AND fcm_token != ''"
        );
        $stmt->execute(array_values($user_ids));
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
    return $rows;
}

/**
 * Send a single FCM notification using the legacy HTTP API.
 * Returns true on success, false on failure.
 */
function fcm_send_single(string $server_key, string $fcm_token, string $title, string $body, array $data): bool
{
    $payload = json_encode([
        'to'           => $fcm_token,
        'notification' => [
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
            'badge' => '1',
        ],
        'data'         => array_merge($data, ['title' => $title, 'body' => $body]),
        'priority'     => 'high',
        'content_available' => true,
    ]);

    $ch = curl_init('https://fcm.googleapis.com/fcm/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: key=' . $server_key,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || $response === false) {
        error_log("PUMIS FCM: HTTP $http – " . ($response ?: 'no response'));
        return false;
    }

    $json = json_decode($response, true);
    if (isset($json['failure']) && (int)$json['failure'] > 0) {
        error_log('PUMIS FCM: send failed – ' . $response);
        return false;
    }

    return true;
}

/**
 * Delete a stale/invalid FCM token from the database.
 */
function fcm_remove_token(string $fcm_token): void
{
    db()->prepare("DELETE FROM api_push_tokens WHERE fcm_token = ?")
       ->execute([$fcm_token]);
}
