<?php
/**
 * Google OAuth callback – stores the refresh token so backups can upload
 * using a real Google account's Drive storage.
 *
 * NOTE: This endpoint intentionally does NOT require a logged-in session.
 * The admin session cookie is SameSite=Strict, so browsers do not send it
 * on the cross-site redirect back from accounts.google.com - a logged-in
 * super admin would appear logged out here and the flow would fail.
 * Security is enforced with the one-time random `state` token instead:
 * it is generated only when a logged-in super admin clicks "Connect
 * Google Account", stored server-side, compared with hash_equals, and
 * cleared after a single use.
 *
 * Configure in Google Cloud Console: create an OAuth Client ID of type
 * "Web application" and add this file's URL as an authorised redirect URI.
 */
require_once __DIR__ . '/helpers.php';

/** Render a small result page. The "Continue" link is a fresh user-initiated
 *  navigation, so the SameSite=Strict session cookie is sent and the admin
 *  arrives back at Backup Settings still logged in. */
function bk_oauth_page(string $title, string $body_html, bool $ok): void
{
    http_response_code($ok ? 200 : 400);
    $color = $ok ? '#198754' : '#dc3545';
    $back  = APP_URL . '/backups/index.php';
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . h($title) . '</title>'
       . ($ok ? '<meta http-equiv="refresh" content="3;url=' . h($back) . '">' : '')
       . '</head><body style="font-family:system-ui,-apple-system,sans-serif;max-width:560px;margin:8vh auto;padding:0 16px;">'
       . '<h3 style="color:' . $color . ';margin-bottom:12px;">' . h($title) . '</h3>'
       . '<p style="color:#333;line-height:1.5;">' . $body_html . '</p>'
       . '<p><a href="' . h($back) . '">Continue to Backup Settings &rarr;</a></p>'
       . '</body></html>';
    exit;
}

$stored_state = (string)bk_setting_get('oauth_state', '');
$state        = (string)($_GET['state'] ?? '');
if ($stored_state === '' || $state === '' || !hash_equals($stored_state, $state)) {
    bk_oauth_page('Connection failed', 'OAuth state mismatch or expired link – go back to Backup Settings and click “Connect Google Account” again.', false);
}
bk_setting_set('oauth_state', null); // one-time use

if (!empty($_GET['error'])) {
    bk_oauth_page('Connection cancelled', 'Google authorisation was cancelled: ' . h((string)$_GET['error']), false);
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    bk_oauth_page('Connection failed', 'No authorisation code received from Google.', false);
}

$client_id     = (string)bk_setting_get('oauth_client_id', '');
$client_secret = (string)bk_setting_get('oauth_client_secret', '');

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => APP_URL . '/backups/oauth-callback.php',
    ]),
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$json = json_decode((string)$resp, true);

if ($http !== 200 || !is_array($json) || empty($json['refresh_token'])) {
    if (is_array($json) && !empty($json['access_token']) && empty($json['refresh_token'])) {
        // Google only returns a refresh token on the first consent.
        bk_oauth_page('Connection failed', 'Google did not return a refresh token. Remove this app\'s access at <strong>myaccount.google.com/permissions</strong>, then click “Connect Google Account” again.', false);
    }
    bk_oauth_page('Connection failed', 'Could not connect Google account (HTTP ' . (int)$http . '): ' . h(substr((string)$resp, 0, 300)), false);
}

bk_setting_set('oauth_refresh_token', (string)$json['refresh_token']);
bk_setting_set('drive_token_cache', null);

bk_oauth_page(
    'Google account connected',
    'Backups will now upload using this account\'s own Drive storage – the service-account quota problem is gone. Taking you back to Backup Settings…',
    true
);
