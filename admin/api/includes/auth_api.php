<?php
/**
 * API Token Authentication Middleware
 * =====================================
 * Include this file at the top of every API endpoint.
 * Sets $API_USER (array) if the token is valid, otherwise sends a 401 JSON error.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/auth_api.php';
 *   // $API_USER is now available
 */

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';

// ── CORS headers (adjust origins as needed) ──────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send a JSON error response and terminate.
 */
function api_error(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

/**
 * Send a JSON success response.
 */
function api_ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data));
}

/**
 * Validate the ****** from the Authorization header.
 * Returns the user row on success or calls api_error(401).
 */
function api_auth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        api_error(401, 'Missing or malformed Authorization header.');
    }
    $raw = trim($m[1]);
    if (strlen($raw) < 32) {
        api_error(401, 'Invalid token format.');
    }

    $token = hash('sha256', $raw); // tokens stored as sha256 hash
    $stmt  = db()->prepare(
        'SELECT t.*, u.id AS user_id, u.full_name, u.username, u.email,
                u.is_active, g.name AS group_name, g.is_super
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         JOIN user_groups g ON g.id = u.group_id
         WHERE t.token = ? AND t.expires_at > NOW() AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        api_error(401, 'Token is invalid or has expired. Please log in again.');
    }

    // Refresh last_used timestamp
    db()->prepare('UPDATE api_tokens SET last_used = NOW() WHERE id = ?')
       ->execute([$row['id']]);

    return $row;
}

/**
 * Return user permissions as an array of module slugs.
 */
function api_get_permissions(int $user_id, int $group_id, bool $is_super): array
{
    if ($is_super) {
        $rows = db()->query(
            "SELECT slug FROM modules WHERE is_active = 1 ORDER BY slug"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return $rows;
    }

    $stmt = db()->prepare(
        'SELECT DISTINCT m.slug
         FROM group_module_access gma
         JOIN modules m ON m.id = gma.module_id
         WHERE gma.group_id = ? AND gma.can_view = 1 AND m.is_active = 1
         UNION
         SELECT DISTINCT m.slug
         FROM user_module_access uma
         JOIN modules m ON m.id = uma.module_id
         WHERE uma.user_id = ? AND uma.can_view = 1 AND m.is_active = 1
         ORDER BY slug'
    );
    $stmt->execute([$group_id, $user_id]);
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}
