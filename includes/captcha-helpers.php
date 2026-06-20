<?php
/**
 * Global reCAPTCHA Helper Functions
 * 
 * Provides reusable functions for Google reCAPTCHA v2 integration across
 * all public forms (apply-now, contact, certificate-verification, etc.)
 */

/**
 * Get a global setting value from the database
 * 
 * @param string $key The setting key
 * @param string $default Default value if not found
 * @return string The setting value or default
 */
function captcha_get_setting(string $key, string $default = ''): string
{
    // Use the appropriate database function based on context
    // Admin context uses db(), front-end context uses front_db()
    if (function_exists('db')) {
        $db = db();
    } elseif (function_exists('front_db')) {
        $db = front_db();
    } else {
        return $default;
    }
    
    if (!$db) return $default;
    
    try {
        $stmt = $db->prepare("SELECT `value` FROM global_settings WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        return $row !== false ? (string)$row : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Verify a reCAPTCHA response token
 * 
 * @param string $response The g-recaptcha-response token from the form
 * @param string $secret_key The reCAPTCHA secret key
 * @return bool True if verification succeeds, false otherwise
 */
function captcha_verify(string $response, string $secret_key): bool
{
    if (empty($response) || empty($secret_key)) {
        return false;
    }

    $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret'   => $secret_key,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    $options = [
        'http' => [
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5,
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($verify_url, false, $context);

    if ($result === false) {
        // Log the error for debugging
        $error = error_get_last();
        error_log('reCAPTCHA verification failed: ' . ($error['message'] ?? 'Unknown error'));
        return false;
    }

    $json = json_decode($result, true);
    
    if ($json === null) {
        // JSON decode failed - log the raw response for debugging
        error_log('reCAPTCHA JSON decode failed. Response: ' . substr($result, 0, 200));
        return false;
    }
    
    return isset($json['success']) && $json['success'] === true;
}

/**
 * Check if CAPTCHA is enabled globally
 * 
 * @return bool True if CAPTCHA is enabled
 */
function captcha_is_enabled(): bool
{
    return captcha_get_setting('captcha_enabled', '0') === '1';
}

/**
 * Get the CAPTCHA site key
 * 
 * @return string The site key or empty string
 */
function captcha_site_key(): string
{
    return captcha_get_setting('captcha_site_key', '');
}

/**
 * Verify CAPTCHA from POST request
 * 
 * Checks if CAPTCHA is enabled and verifies the response token if so.
 * Returns true if CAPTCHA is disabled or verification succeeds.
 * 
 * @return bool True if verification passes or CAPTCHA is disabled
 */
function captcha_verify_request(): bool
{
    if (!captcha_is_enabled()) {
        return true; // CAPTCHA is disabled, so we pass
    }
    
    $captcha_response = $_POST['g-recaptcha-response'] ?? '';
    $captcha_secret = captcha_get_setting('captcha_secret_key', '');
    
    return captcha_verify($captcha_response, $captcha_secret);
}

/**
 * Render the reCAPTCHA widget HTML
 * 
 * Only outputs the widget if CAPTCHA is enabled.
 * 
 * @return void Echoes HTML directly
 */
function captcha_render_widget(): void
{
    if (!captcha_is_enabled()) {
        return;
    }
    
    $site_key = captcha_site_key();
    if (empty($site_key)) {
        return;
    }
    
    echo '<div class="g-recaptcha" data-sitekey="' . htmlspecialchars($site_key, ENT_QUOTES, 'UTF-8') . '"></div>';
}

/**
 * Render the reCAPTCHA script tag
 * 
 * Only outputs the script tag if CAPTCHA is enabled.
 * Should be placed in the <head> section or before </body>.
 * 
 * @return void Echoes HTML directly
 */
function captcha_render_script(): void
{
    if (!captcha_is_enabled()) {
        return;
    }
    
    echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}
