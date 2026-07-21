<?php
/**
 * Facebook Messenger Follow-up Cron
 * ---------------------------------
 * Sends the one-time auto follow-up "আপনি কি আছেন?" when a customer has not
 * replied to a staff response within 10 minutes.
 *
 * Recommended: run every minute via cron so it works even when nobody has the
 * inbox open in a browser (the admin pages also trigger it opportunistically):
 *   * * * * * php /path/to/htdocs/fb-followup-cron.php
 *
 * Or over HTTP (set a 'cron_token' row in lead_fb_settings first):
 *   https://primeuniversity.ac.bd/fb-followup-cron.php?token=YOUR_TOKEN
 *
 * Requires admin/leads/fb-inbox-upgrade-2.sql.
 */

define('FB_CRON_ENTRY', true);

require_once __DIR__ . '/admin/includes/config.php';
require_once __DIR__ . '/admin/includes/db.php';

const FB_FOLLOWUP_TEXT = 'আপনি কি আছেন?';

function fbcron_setting(string $key): string
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM lead_fb_settings WHERE `key` = ?');
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

function fbcron_send_text(string $psid, string $text): bool
{
    $token = fbcron_setting('page_access_token');
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
        $body = json_decode((string)$resp, true);
        return !empty($body['message_id']);
    }
    return false;
}

// ── Access control: CLI is always allowed, HTTP needs the cron_token ─────────
if (PHP_SAPI !== 'cli') {
    $stored = fbcron_setting('cron_token');
    $given  = (string)($_GET['token'] ?? '');
    if ($stored === '' || !hash_equals($stored, $given)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ── Find conversations where OUR staff reply is the last message, sent more
//    than 10 minutes ago, and no follow-up was sent for that reply yet ────────
try {
    $rows = db()->query(
        "SELECT c.id, c.psid, m.created_at AS staff_msg_at
         FROM lead_fb_contacts c
         JOIN lead_fb_messages m ON m.id = (
             SELECT m2.id FROM lead_fb_messages m2
             WHERE m2.contact_id = c.id ORDER BY m2.id DESC LIMIT 1
         )
         WHERE m.direction = 'out'
           AND m.is_auto = 0
           AND m.sent_by IS NOT NULL
           AND m.status = 'sent'
           AND m.created_at <= (NOW() - INTERVAL 10 MINUTE)
           AND (c.followup_sent_at IS NULL OR c.followup_sent_at < m.created_at)
         LIMIT 50"
    )->fetchAll();
} catch (Exception $e) {
    exit('Schema missing - run admin/leads/fb-inbox-upgrade-2.sql first.');
}

$sent = 0;
foreach ($rows as $r) {
    // Claim first so overlapping runs never double-send
    $claim = db()->prepare(
        'UPDATE lead_fb_contacts SET followup_sent_at = NOW()
         WHERE id = ? AND (followup_sent_at IS NULL OR followup_sent_at < ?)'
    );
    $claim->execute([(int)$r['id'], $r['staff_msg_at']]);
    if ($claim->rowCount() === 0) continue;

    if (fbcron_send_text($r['psid'], FB_FOLLOWUP_TEXT)) {
        db()->prepare(
            'INSERT INTO lead_fb_messages (contact_id, direction, message_text, status, is_auto)
             VALUES (?,?,?,?,1)'
        )->execute([(int)$r['id'], 'out', FB_FOLLOWUP_TEXT, 'sent']);
        db()->prepare('UPDATE lead_fb_contacts SET last_message_at = NOW() WHERE id = ?')
            ->execute([(int)$r['id']]);
        $sent++;
    }
}

echo 'Follow-ups sent: ' . $sent . "\n";
