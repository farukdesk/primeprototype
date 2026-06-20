<?php
/**
 * Student Portal API – Auth Middleware
 * =====================================
 * Include at the top of every student API endpoint.
 * Sets $STUDENT_API_USER (student row) when token is valid.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/auth_student_api.php';
 *   // $STUDENT_API_USER and $STUDENT_RECORD are now available
 */

require_once dirname(__DIR__, 3) . '/includes/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';

// ── CORS headers ──────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Device-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send a JSON error response and terminate.
 */
function sp_api_error(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

/**
 * Send a JSON success response.
 */
function sp_api_ok(array $data = []): void
{
    echo json_encode(array_merge(['ok' => true], $data));
}

/**
 * Validate the bearer token and confirm user is a student portal account.
 * Returns ['user' => ..., 'student' => ...] on success.
 */
function sp_api_auth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        sp_api_error(401, 'Missing or malformed Authorization header.');
    }

    $raw  = trim($m[1]);
    if (strlen($raw) < 32) {
        sp_api_error(401, 'Invalid token format.');
    }

    $hash = hash('sha256', $raw);

    // Validate token from shared api_tokens table (students use the same users table)
    $stmt = db()->prepare(
        'SELECT t.id AS token_id, t.user_id, t.device_id,
                u.full_name, u.username, u.email, u.is_active
         FROM api_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW() AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $user_row = $stmt->fetch();

    if (!$user_row) {
        sp_api_error(401, 'Token is invalid or has expired. Please log in again.');
    }

    // Confirm user has a linked student record
    $stmt2 = db()->prepare(
        'SELECT s.id AS student_db_id,
                s.student_id, s.full_name AS student_name,
                s.phone, s.email AS student_email,
                s.photo, s.status, s.dept_id, s.program_id, s.batch_id,
                d.name AS dept_name, d.code AS dept_code,
                p.program_name, p.program_type,
                b.name AS batch_name
         FROM students s
         JOIN dept_departments d ON d.id = s.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = s.program_id
         LEFT JOIN student_batches b ON b.id = s.batch_id
         WHERE s.portal_user_id = ?
         LIMIT 1'
    );
    $stmt2->execute([(int)$user_row['user_id']]);
    $student = $stmt2->fetch();

    if (!$student) {
        sp_api_error(403, 'This account is not linked to a student profile. Please contact the administrator.');
    }

    // Refresh last_used
    db()->prepare('UPDATE api_tokens SET last_used = NOW() WHERE id = ?')
       ->execute([(int)$user_row['token_id']]);

    return ['user' => $user_row, 'student' => $student];
}
