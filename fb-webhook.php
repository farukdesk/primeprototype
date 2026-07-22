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

            // ── Read receipt: customer has seen our messages up to watermark ──
            // (requires the 'message_reads' webhook subscription and
            //  admin/leads/fb-inbox-upgrade-2.sql)
            if (isset($event['read']['watermark'])) {
                $rc = db()->prepare('SELECT id FROM lead_fb_contacts WHERE psid = ?');
                $rc->execute([$psid]);
                $rcid = (int)$rc->fetchColumn();
                if ($rcid) {
                    try {
                        db()->prepare(
                            "UPDATE lead_fb_messages SET seen_at = NOW()
                             WHERE contact_id = ? AND direction = 'out' AND seen_at IS NULL
                               AND created_at <= FROM_UNIXTIME(?)"
                        )->execute([$rcid, (int)floor(((float)$event['read']['watermark']) / 1000)]);
                    } catch (Exception $e) { /* run admin/leads/fb-inbox-upgrade-2.sql */ }
                }
                continue;
            }

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

                // Contact shared a phone number → remove "Follow Up",
                // apply "Converted to Lead"
                if ($text !== null && preg_match('/01[3-9][0-9]{8}/', $text)) {
                    fb_remove_tag($contact_id, 'Follow Up');
                    fb_add_tag($contact_id, 'Converted to Lead', '#198754');
                }

                // Off-hours auto-responder
                fb_maybe_auto_reply($contact_id, $psid);

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

/**
 * Fetch a user's profile (name + picture) from Meta's User Profile API.
 * Returns [name|null, picture|null].
 */
function fb_fetch_profile(string $psid): array
{
    $token = fb_setting('page_access_token');
    if ($token === '') return [null, null];

    $url = 'https://graph.facebook.com/v19.0/' . urlencode($psid)
         . '?fields=first_name,last_name,name,profile_pic&access_token=' . urlencode($token);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code !== 200) return [null, null];

    $profile = json_decode($resp, true);
    if (!is_array($profile)) return [null, null];

    $name = trim((string)($profile['name'] ?? ''));
    if ($name === '') {
        $name = trim(trim((string)($profile['first_name'] ?? '')) . ' ' . trim((string)($profile['last_name'] ?? '')));
    }

    return [$name !== '' ? $name : null, $profile['profile_pic'] ?? null];
}

function fb_upsert_contact(string $psid): int
{
    $stmt = db()->prepare('SELECT id, fb_name FROM lead_fb_contacts WHERE psid = ?');
    $stmt->execute([$psid]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Name still missing (token was absent/invalid when first seen) – retry now
        if (empty($existing['fb_name'])) {
            [$name, $picture] = fb_fetch_profile($psid);
            if ($name !== null) {
                db()->prepare('UPDATE lead_fb_contacts SET fb_name=?, fb_picture=COALESCE(?, fb_picture) WHERE id=?')
                    ->execute([$name, $picture, (int)$existing['id']]);
            }
        }
        return (int)$existing['id'];
    }

    // New contact – fetch profile from the Graph API
    [$name, $picture] = fb_fetch_profile($psid);

    db()->prepare(
        'INSERT INTO lead_fb_contacts (psid, fb_name, fb_picture, last_message_at) VALUES (?,?,?,NOW())'
    )->execute([$psid, $name, $picture]);

    $new_id = (int)db()->lastInsertId();

    // Automatically apply the "Follow Up" tag to every new contact
    fb_add_tag($new_id, 'Follow Up', '#fd7e14');

    return $new_id;
}

/**
 * Send a plain-text message to a PSID via the Send API (webhook-local).
 */
function fb_send_text(string $psid, string $text): bool
{
    $token = fb_setting('page_access_token');
    if ($token === '') return false;

    $payload = json_encode([
        'recipient'      => ['id' => $psid],
        'message'        => ['text' => $text],
        'messaging_type' => 'RESPONSE',
    ]);

    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . urlencode($token));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $body = json_decode($resp, true);
        return !empty($body['message_id']);
    }
    return false;
}

/**
 * Working-hours auto-responder.
 * Sends a configurable automatic reply when a message arrives outside
 * office hours. Throttled to one auto-reply per contact per 6 hours.
 * Settings (lead_fb_settings): auto_reply_enabled ('1'/'0'),
 * auto_reply_message, office_days (CSV, 0=Sun), office_start, office_end (H:i).
 */
function fb_maybe_auto_reply(int $contact_id, string $psid): void
{
    if (fb_setting('auto_reply_enabled') !== '1') return;
    $message = trim(fb_setting('auto_reply_message'));
    if ($message === '') return;

    $days  = fb_setting('office_days')  !== '' ? fb_setting('office_days')  : '0,1,2,3,4';
    $start = fb_setting('office_start') !== '' ? fb_setting('office_start') : '09:00';
    $end   = fb_setting('office_end')   !== '' ? fb_setting('office_end')   : '17:00';

    $day_list = array_map('trim', explode(',', $days));
    $inside   = in_array(date('w'), $day_list, true)
             && date('H:i') >= $start
             && date('H:i') < $end;
    if ($inside) return; // office is open – humans will reply

    // Throttle: max one auto-reply per contact per 6 hours
    try {
        $stmt = db()->prepare('SELECT last_auto_reply_at FROM lead_fb_contacts WHERE id = ?');
        $stmt->execute([$contact_id]);
        $last = $stmt->fetchColumn();
        if ($last && strtotime($last) > time() - 6 * 3600) return;
    } catch (Exception $e) {
        return; // column missing – run admin/leads/fb-inbox-upgrade.sql
    }

    if (fb_send_text($psid, $message)) {
        try {
            db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, status) VALUES (?,?,?,?)')
                ->execute([$contact_id, 'out', $message, 'sent']);
        } catch (Exception $e) {
            db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text) VALUES (?,?,?)')
                ->execute([$contact_id, 'out', $message]);
        }
        db()->prepare('UPDATE lead_fb_contacts SET last_auto_reply_at = NOW(), last_message_at = NOW() WHERE id = ?')
            ->execute([$contact_id]);
    }
}

// ── Automatic tagging helpers ─────────────────────────────────────────────────

/**
 * Get (or create) a tag id by name. Returns 0 when the tags tables are missing.
 */
function fb_tag_id(string $name, string $color = '#6c757d'): int
{
    try {
        $stmt = db()->prepare('SELECT id FROM lead_fb_tags WHERE name = ?');
        $stmt->execute([$name]);
        $id = (int)$stmt->fetchColumn();
        if ($id) return $id;
        db()->prepare('INSERT INTO lead_fb_tags (name, color) VALUES (?,?)')->execute([$name, $color]);
        return (int)db()->lastInsertId();
    } catch (Exception $e) {
        return 0; // run admin/leads/fb-inbox-upgrade.sql first
    }
}

function fb_add_tag(int $contact_id, string $name, string $color = '#6c757d'): void
{
    $tag_id = fb_tag_id($name, $color);
    if ($tag_id <= 0) return;
    try {
        db()->prepare('INSERT IGNORE INTO lead_fb_contact_tags (contact_id, tag_id) VALUES (?,?)')
            ->execute([$contact_id, $tag_id]);
    } catch (Exception $e) { /* ignore */ }
}

function fb_remove_tag(int $contact_id, string $name): void
{
    try {
        db()->prepare(
            'DELETE ct FROM lead_fb_contact_tags ct
             JOIN lead_fb_tags t ON t.id = ct.tag_id
             WHERE ct.contact_id = ? AND t.name = ?'
        )->execute([$contact_id, $name]);
    } catch (Exception $e) { /* ignore */ }
}
