<?php
/**
 * Facebook Messenger Webhook
 * --------------------------
 * Public endpoint – no admin authentication.
 * Configure this URL in your Facebook App dashboard:
 *   Callback URL : https://primeuniversity.ac.bd/fb-webhook.php
 *   Verify Token : (value stored in lead_fb_settings.verify_token)
 *   Subscriptions: messages, messaging_postbacks
 */

// Bootstrap DB and helpers without the admin session/auth stack
define('FB_WEBHOOK_ENTRY', true);

require_once __DIR__ . '/admin/includes/config.php';
require_once __DIR__ . '/admin/includes/db.php';

// ── Helper: write raw JSON log for debugging (disabled in production) ──────────
// file_put_contents('/tmp/fb_webhook.log', date('c') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);

// ── GET: Webhook verification (Facebook hub challenge) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? $_GET['hub.challenge']    ?? '';

    $stored_token = fb_setting('verify_token');

    if ($mode === 'subscribe' && $stored_token !== '' && hash_equals($stored_token, $token)) {
        http_response_code(200);
        echo (int)$challenge;
    } else {
        http_response_code(403);
        echo 'Verification failed.';
    }
    exit;
}

// ── POST: Receive webhook events ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    // Validate app secret proof if app_secret is configured
    $app_secret = fb_setting('app_secret');
    if ($app_secret !== '') {
        $sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $expected   = 'sha256=' . hash_hmac('sha256', $raw, $app_secret);
        if (!hash_equals($expected, $sig_header)) {
            http_response_code(403);
            exit('Invalid signature.');
        }
    }

    // Respond 200 immediately to Facebook (must be fast)
    http_response_code(200);
    echo 'EVENT_RECEIVED';
    // Flush response before heavier processing
    if (ob_get_level()) ob_end_flush();
    flush();

    if (!is_array($data) || ($data['object'] ?? '') !== 'page') {
        exit;
    }

    foreach ($data['entries'] ?? $data['entry'] ?? [] as $entry) {
        foreach ($entry['messaging'] ?? [] as $event) {
            $psid = $event['sender']['id'] ?? null;
            if (!$psid) continue;

            // Skip echoes (messages sent by the page itself)
            if (!empty($event['message']['is_echo'])) continue;

            // ── Text / attachment message ──────────────────────────────────
            if (isset($event['message'])) {
                $msg     = $event['message'];
                $fb_mid  = $msg['mid'] ?? null;
                $text    = $msg['text'] ?? null;

                // Deduplicate by fb_mid
                if ($fb_mid) {
                    $dup = db()->prepare('SELECT id FROM lead_fb_messages WHERE fb_mid = ?');
                    $dup->execute([$fb_mid]);
                    if ($dup->fetchColumn()) continue;
                }

                // Upsert contact (resolve profile if first time seeing this PSID)
                $contact_id = fb_upsert_contact($psid);

                $att_type = null;
                $att_url  = null;
                if (!empty($msg['attachments'])) {
                    $att       = $msg['attachments'][0];
                    $att_type  = $att['type'] ?? null;
                    $att_url   = $att['payload']['url'] ?? null;
                }

                db()->prepare(
                    'INSERT INTO lead_fb_messages
                       (contact_id, direction, message_text, attachment_type, attachment_url, fb_mid)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$contact_id, 'in', $text, $att_type, $att_url, $fb_mid]);

                // Update last_message_at on contact
                db()->prepare('UPDATE lead_fb_contacts SET last_message_at=NOW() WHERE id=?')
                    ->execute([$contact_id]);

                // If contact is already linked to a lead, log it
                $contact = db()->prepare('SELECT lead_id FROM lead_fb_contacts WHERE id=?');
                $contact->execute([$contact_id]);
                $lead_id = $contact->fetchColumn();
                if ($lead_id) {
                    db()->prepare(
                        'INSERT INTO lead_history (lead_id, user_id, action, description)
                         VALUES (?,NULL,?,?)'
                    )->execute([
                        $lead_id,
                        'fb_message_received',
                        'Facebook message received: ' . mb_substr($text ?? '[attachment]', 0, 200),
                    ]);
                }
            }

            // ── Postback (quick reply button pressed) ──────────────────────
            if (isset($event['postback'])) {
                $contact_id = fb_upsert_contact($psid);
                $payload    = $event['postback']['payload']  ?? '';
                $title      = $event['postback']['title']    ?? '';
                db()->prepare(
                    'INSERT INTO lead_fb_messages
                       (contact_id, direction, message_text, fb_mid)
                     VALUES (?,?,?,?)'
                )->execute([
                    $contact_id,
                    'in',
                    '[Postback] ' . $title . ($payload ? ' (' . $payload . ')' : ''),
                    null,
                ]);
                db()->prepare('UPDATE lead_fb_contacts SET last_message_at=NOW() WHERE id=?')
                    ->execute([$contact_id]);
            }
        }
    }
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
exit;

// ── Local helpers (no admin session needed) ───────────────────────────────────

function fb_setting(string $key): string
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM lead_fb_settings WHERE `key` = ?');
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

function fb_upsert_contact(string $psid): int
{
    $stmt = db()->prepare('SELECT id, fb_name FROM lead_fb_contacts WHERE psid = ?');
    $stmt->execute([$psid]);
    $existing = $stmt->fetch();

    if ($existing) {
        return (int)$existing['id'];
    }

    // New contact – try to fetch profile from Graph API
    $name    = null;
    $picture = null;
    $token   = fb_setting('page_access_token');
    if ($token !== '') {
        $url = 'https://graph.facebook.com/' . urlencode($psid)
             . '?fields=name,profile_pic&access_token=' . urlencode($token);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $profile = json_decode($resp, true);
            $name    = $profile['name']        ?? null;
            $picture = $profile['profile_pic'] ?? null;
        }
    }

    db()->prepare(
        'INSERT INTO lead_fb_contacts (psid, fb_name, fb_picture, last_message_at) VALUES (?,?,?,NOW())'
    )->execute([$psid, $name, $picture]);

    return (int)db()->lastInsertId();
}
