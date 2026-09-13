<?php
/**
 * SS Portal – bootstrap
 * =====================
 * Shared start-up for every page and CLI script of the partner portal:
 * configuration, session, database connection and small view helpers.
 *
 * The portal is a stand-alone application (own database, own users) that
 * runs on the partner's server and talks to Prime University only through
 * the third-party Student API v1 (see ../../admin/api/v1/API-GUIDE.md).
 */

define('SSP_ROOT', dirname(__DIR__));
define('SSP_VERSION', '1.0.0');

if (!is_file(SSP_ROOT . '/config.php')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "ss-portal-api is not configured.\n"
       . "Copy config.sample.php to config.php and fill in the database and Prime University API settings.\n";
    exit(1);
}

/**
 * Read a configuration value with dot notation, e.g. ssp_config('db.host').
 */
function ssp_config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require SSP_ROOT . '/config.php';
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    if ($key === null) {
        return $cfg;
    }
    $node = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}

date_default_timezone_set((string)ssp_config('timezone', 'Asia/Dhaka'));
mb_internal_encoding('UTF-8');

// ── Session (web requests only) ───────────────────────────────────────────────
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $ssp_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name((string)ssp_config('session_name', 'ssp_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $ssp_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Shared PDO connection to the portal's own database. */
function ssp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db  = (array)ssp_config('db', []);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? '127.0.0.1',
        (int)($db['port'] ?? 3306),
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** HTML-escape for output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL inside the portal, e.g. ssp_url('students/view.php?id=3'). */
function ssp_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = rtrim((string)ssp_config('base_url', ''), '/');
        if ($base === '') {
            $docroot = rtrim(str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''))), '/');
            $root    = str_replace('\\', '/', (string)realpath(SSP_ROOT));
            if ($docroot !== '' && strpos($root, $docroot) === 0) {
                $base = rtrim(substr($root, strlen($docroot)), '/');
            }
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function ssp_redirect(string $path): void
{
    header('Location: ' . ssp_url($path), true, 303);
    exit;
}

function ssp_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Queue a one-time message shown on the next page (types: success, warning, error, info). */
function ssp_flash(string $type, string $message): void
{
    $_SESSION['ssp_flash'][] = ['type' => $type, 'message' => $message];
}

function ssp_flash_pull(): array
{
    $flashes = $_SESSION['ssp_flash'] ?? [];
    unset($_SESSION['ssp_flash']);
    return is_array($flashes) ? $flashes : [];
}

function ssp_now(): string
{
    return date('Y-m-d H:i:s');
}

function ssp_json_pretty($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** Read a dotted path from a nested array, e.g. ssp_array_get($form, 'guardian.email'). */
function ssp_array_get(array $data, string $dot, $default = '')
{
    $node = $data;
    foreach (explode('.', $dot) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }
    return $node;
}
