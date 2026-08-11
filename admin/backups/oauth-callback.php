<?php
/**
 * Google OAuth callback – stores the refresh token so backups can upload
 * using a real Google account's Drive storage. SUPER ADMIN ONLY.
 *
 * Configure in Google Cloud Console: create an OAuth Client ID of type
 * "Web application" and add this file's URL as an authorised redirect URI.
 */
require_once __DIR__ . '/../includes/auth.php';
require_super_admin();
require_once __DIR__ . '/helpers.php';

$stored_state = (string)bk_setting_get('oauth_state', '');
$state        = (string)($_GET['state'] ?? '');
if ($stored_state === '' || $state === '' || !hash_equals($stored_state, $state)) {
    flash_set('error', 'OAuth state mismatch – please click “Connect Google Account” again.');
    redirect(APP_URL . '/backups/index.php');
}
bk_setting_set('oauth_state', null);

if (!empty($_GET['error'])) {
    flash_set('error', 'Google authorisation was cancelled: ' . h((string)$_GET['error']));
    redirect(APP_URL . '/backups/index.php');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    flash_set('error', 'No authorisation code received from Google.');
    redirect(APP_URL . '/backups/index.php');
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
        flash_set('error', 'Google did not return a refresh token. Remove this app\'s access at myaccount.google.com/permissions, then click “Connect Google Account” again.');
    } else {
        flash_set('error', 'Could not connect Google account (HTTP ' . $http . '): ' . h(substr((string)$resp, 0, 300)));
    }
    redirect(APP_URL . '/backups/index.php');
}

bk_setting_set('oauth_refresh_token', (string)$json['refresh_token']);
bk_setting_set('drive_token_cache', null);
flash_set('success', 'Google account connected – backups will now upload using this account\'s own Drive storage (no service account quota problem).');
redirect(APP_URL . '/backups/index.php');
