<?php
/**
 * Student/Admin API – Authorization header resolver
 * ==================================================
 * Returns the raw `Authorization` request header (e.g. "******").
 *
 * `$_SERVER['HTTP_AUTHORIZATION']` is unreliable: many Apache + PHP-FPM/CGI
 * setups strip the Authorization header before it reaches PHP, which makes
 * every authenticated request fail with 401 even when the client sent a valid
 * token. This helper checks every place the header can surface so the API keeps
 * working regardless of the server configuration.
 */

if (!function_exists('api_authorization_header')) {
    function api_authorization_header(): string
    {
        // 1. Direct server variables (mod_php, and the value forwarded by the
        //    api/.htaccess rewrite which lands in REDIRECT_HTTP_AUTHORIZATION).
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim($_SERVER[$key]);
            }
        }

        // 2. apache_request_headers()/getallheaders() – available under Apache
        //    and PHP-FPM; the header name casing can vary, so match loosely.
        $headers = null;
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
        }
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0 && $value !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }
}
