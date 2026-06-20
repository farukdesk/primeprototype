<?php
/**
 * Front-end Database & Site Configuration
 * Mirrors admin/includes/config.php – keep in sync.
 */

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'admin_primepnew2026');
define('DB_USER',    'primefaruk');
define('DB_PASS',    '48l0cE5x?');
define('DB_CHARSET', 'utf8mb4');

// ── Site ─────────────────────────────────────────────────────────────────────
define('SITE_URL',         'https://primeuniversity.ac.bd');   // no trailing slash
define('ADMIN_UPLOAD_URL', SITE_URL . '/admin/uploads');                  // for slider images etc.

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/db.php';
