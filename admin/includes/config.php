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
define('DB_NAME',     'admin_primepnew2026');
define('DB_USER',     'primefaruk');          // change to your DB user
define('DB_PASS',     '48l0cE5x?');              // change to your DB password
define('DB_CHARSET',  'utf8mb4');

// ── Application ──────────────────────────────────────────────────────────────
define('APP_NAME',    'Prime University Admin');
define('APP_URL',     'https://prototype.primeuniversity.ac.bd/admin'); // no trailing slash
define('APP_VERSION', '1.0.0');

// ── Session ──────────────────────────────────────────────────────────────────
define('SESSION_NAME',     'pu_admin_sess');
define('SESSION_LIFETIME', 3600);   // seconds (1 hour)

// ── Security ─────────────────────────────────────────────────────────────────
define('BCRYPT_COST', 12);
define('CSRF_TOKEN_NAME', '_csrf_token');

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Dhaka');

// ── Uploads ──────────────────────────────────────────────────────────────────
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');   // admin/uploads (absolute path)
define('UPLOAD_URL', APP_URL . '/uploads');            // https://…/admin/uploads

// ── Error reporting (set to 0 in production) ─────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 1);
