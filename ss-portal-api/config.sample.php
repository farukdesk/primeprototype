<?php
/**
 * SS Portal – configuration
 * =========================
 * Copy this file to config.php (same folder) and edit the values.
 * config.php is git-ignored and blocked by .htaccess; it holds the database
 * password and the Prime University API key, so keep it out of the web root
 * backups you share.
 */

return [
    'app_name'     => 'SS Student Portal',
    'timezone'     => 'Asia/Dhaka',
    'session_name' => 'ssp_session',

    // Public URL path of this folder, without trailing slash (e.g. '/ss-portal-api'
    // or 'https://portal.example.com'). Leave '' to auto-detect from DOCUMENT_ROOT.
    'base_url'     => '',

    // The portal's OWN database (users, local student records, API audit log).
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'ss_portal',
        'user'    => 'ss_portal',
        'pass'    => 'change-me',
        'charset' => 'utf8mb4',
    ],

    // Prime University third-party Student API v1 (see admin/api/v1/API-GUIDE.md).
    'pu_api' => [
        'base_url'   => 'https://primeuniversity.ac.bd/admin/api/v1',
        // Partner key issued by Prime University IT (starts with "pu_").
        // Prefer the PU_API_KEY environment variable over hard-coding it here.
        'api_key'    => getenv('PU_API_KEY') ?: '',
        'timeout'    => 30,      // seconds per request
        'verify_ssl' => true,
        // How long GET /reference-data.php is cached locally (seconds).
        'reference_cache_ttl' => 6 * 3600,
    ],

    // Prefix for the X-Idempotency-Key sent with every student create request.
    'idempotency_prefix' => 'ssp',

    // Max photo upload size accepted by the portal (the university limit is 5 MB).
    'photo_max_bytes' => 5 * 1024 * 1024,
];
