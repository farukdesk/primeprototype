<?php
/**
 * Authentication & Session Helpers
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Session bootstrap ────────────────────────────────────────────────────────
function session_bootstrap(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

session_bootstrap();

// ── CSRF helpers ─────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_field(): string {
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $token);
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
        http_response_code(403);
        die('CSRF token mismatch.');
    }
}

// ── Auth helpers ─────────────────────────────────────────────────────────────
function auth_check(): void {
    if (empty($_SESSION['user_id'])) {
        redirect(APP_URL . '/login.php');
    }
}

function auth_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare(
            'SELECT u.*, g.name AS group_name, g.is_super
             FROM users u
             JOIN user_groups g ON g.id = u.group_id
             WHERE u.id = ? AND u.is_active = 1'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            session_destroy();
            redirect(APP_URL . '/login.php');
        }
    }
    return $user;
}

function is_super_admin(): bool {
    $user = auth_user();
    return $user && (int)$user['is_super'] === 1;
}

/**
 * Check whether the current user can access a given module slug.
 * Super admins bypass all checks.
 */
function can_access(string $slug, string $permission = 'can_view'): bool {
    if (is_super_admin()) return true;
    $user = auth_user();
    if (!$user) return false;

    static $access = [];
    if (!isset($access[$slug])) {
        $stmt = db()->prepare(
            'SELECT gma.*
             FROM group_module_access gma
             JOIN modules m ON m.id = gma.module_id
             WHERE gma.group_id = ? AND m.slug = ? AND m.is_active = 1'
        );
        $stmt->execute([$user['group_id'], $slug]);
        $access[$slug] = $stmt->fetch() ?: [];
    }
    return !empty($access[$slug][$permission]);
}

/**
 * Gate – redirect with error if no access.
 */
function require_access(string $slug, string $permission = 'can_view'): void {
    auth_check();
    if (!can_access($slug, $permission)) {
        $_SESSION['flash_error'] = 'You do not have permission to access this section.';
        redirect(APP_URL . '/index.php');
    }
}

/**
 * Gate – restrict a page to super admins only.
 */
function require_super_admin(): void {
    auth_check();
    if (!is_super_admin()) {
        $_SESSION['flash_error'] = 'This section is restricted to Super Administrators.';
        redirect(APP_URL . '/index.php');
    }
}

// ── Flash messages ───────────────────────────────────────────────────────────
function flash_set(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function flash_get(string $type): ?string {
    $msg = $_SESSION['flash_' . $type] ?? null;
    unset($_SESSION['flash_' . $type]);
    return $msg;
}

function flash_show(): void {
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        $msg = flash_get($type);
        if ($msg) {
            $cls = $type === 'error' ? 'danger' : $type;
            echo '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
               . strip_tags($msg, '<strong><em><b><i>')
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

// ── Misc helpers ─────────────────────────────────────────────────────────────
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function old(string $key, string $default = ''): string {
    return h($_SESSION['old'][$key] ?? $default);
}

function save_old(array $data): void {
    $_SESSION['old'] = $data;
}

function clear_old(): void {
    unset($_SESSION['old']);
}
