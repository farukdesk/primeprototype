<?php
/**
 * Database & Application Configuration
 * =====================================
 * Copy this file to config.php and adjust values for your environment.
 * Never commit real credentials to version control.
 */

// ── Database ────────────────────────────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_PORT',     '3306');
define('DB_NAME',     'prime_university');
define('DB_USER',     'root');          // change to your DB user
define('DB_PASS',     '');              // change to your DB password
define('DB_CHARSET',  'utf8mb4');

// ── Application ──────────────────────────────────────────────────────────────
define('APP_NAME',    'Prime University Admin');
define('APP_URL',     'http://localhost/primeprototype/admin'); // no trailing slash
define('APP_VERSION', '1.0.0');

// ── Session ──────────────────────────────────────────────────────────────────
define('SESSION_NAME',     'pu_admin_sess');
define('SESSION_LIFETIME', 3600);   // seconds (1 hour)

// ── Security ─────────────────────────────────────────────────────────────────
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', '_csrf_token');

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Dhaka');

// ── Error reporting (set to 0 in production) ─────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 1);
