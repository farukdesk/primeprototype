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
        // Load all group IDs for multi-group support
        $gstmt = db()->prepare(
            'SELECT uga.group_id, g.is_super
             FROM user_group_assignments uga
             JOIN user_groups g ON g.id = uga.group_id
             WHERE uga.user_id = ? AND g.is_active = 1'
        );
        $gstmt->execute([$user['id']]);
        $user['group_ids']    = [];
        $user['is_super']     = 0;
        foreach ($gstmt->fetchAll() as $row) {
            $user['group_ids'][] = (int)$row['group_id'];
            if ((int)$row['is_super'] === 1) {
                $user['is_super'] = 1;
            }
        }
        // Fallback: if junction table is empty (legacy), use primary group
        if (empty($user['group_ids'])) {
            $user['group_ids'] = [(int)$user['group_id']];
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
 * User-level access (user_module_access) takes precedence over group-level access.
 * When a user belongs to multiple groups, permissions are the union of all groups.
 */
function can_access(string $slug, string $permission = 'can_view'): bool {
    if (is_super_admin()) return true;
    $user = auth_user();
    if (!$user) return false;

    static $user_access  = [];
    static $group_access = [];

    // Check user-level override first
    if (!isset($user_access[$slug])) {
        $stmt = db()->prepare(
            'SELECT uma.*
             FROM user_module_access uma
             JOIN modules m ON m.id = uma.module_id
             WHERE uma.user_id = ? AND m.slug = ? AND m.is_active = 1'
        );
        $stmt->execute([$user['id'], $slug]);
        $row = $stmt->fetch();
        $user_access[$slug] = $row !== false ? $row : null;
    }
    if ($user_access[$slug] !== null) {
        return !empty($user_access[$slug][$permission]);
    }

    // Fall back to group-level access (union of all groups the user belongs to)
    if (!isset($group_access[$slug])) {
        $group_ids = $user['group_ids'];
        if (empty($group_ids)) {
            $group_access[$slug] = [];
        } else {
            $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
            $stmt = db()->prepare(
                "SELECT gma.*
                 FROM group_module_access gma
                 JOIN modules m ON m.id = gma.module_id
                 WHERE gma.group_id IN ($placeholders) AND m.slug = ? AND m.is_active = 1"
            );
            $stmt->execute(array_merge($group_ids, [$slug]));
            $rows = $stmt->fetchAll();
            // Merge: any granted permission across groups is granted
            $merged = [];
            foreach ($rows as $r) {
                $merged['can_view']   = ($merged['can_view']   ?? 0) | (int)$r['can_view'];
                $merged['can_create'] = ($merged['can_create'] ?? 0) | (int)$r['can_create'];
                $merged['can_edit']   = ($merged['can_edit']   ?? 0) | (int)$r['can_edit'];
                $merged['can_delete'] = ($merged['can_delete'] ?? 0) | (int)$r['can_delete'];
            }
            $group_access[$slug] = $merged;
        }
    }
    return !empty($group_access[$slug][$permission]);
}

/**
 * Check whether the current user can access a specific department.
 * Super admins bypass all checks.
 * User-level dept scope overrides group-level scope.
 * No scope rows = unrestricted (access all departments).
 */
function can_access_dept(int $dept_id): bool {
    if (is_super_admin()) return true;
    $user = auth_user();
    if (!$user) return false;

    static $user_dept_cache  = null;
    static $group_dept_cache = null;

    // Load user-level dept scope (once)
    if ($user_dept_cache === null) {
        $stmt = db()->prepare(
            'SELECT dept_id FROM user_dept_scope WHERE user_id = ?'
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $user_dept_cache = $rows;  // array of dept_ids (NULL represented as null/empty string)
    }

    // If user has explicit scope rows, apply them
    if (!empty($user_dept_cache)) {
        foreach ($user_dept_cache as $d) {
            if ($d === null || $d === '') return true;  // NULL = all depts
            if ((int)$d === $dept_id) return true;
        }
        return false;  // user has scope rows but none match
    }

    // Check group-level scope for all of user's groups
    if ($group_dept_cache === null) {
        $group_ids = $user['group_ids'];
        if (empty($group_ids)) {
            $group_dept_cache = [];
        } else {
            $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
            $stmt = db()->prepare(
                "SELECT dept_id FROM group_dept_scope WHERE group_id IN ($placeholders)"
            );
            $stmt->execute($group_ids);
            $group_dept_cache = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    // No group scope rows = unrestricted (all departments)
    if (empty($group_dept_cache)) return true;

    foreach ($group_dept_cache as $d) {
        if ($d === null || $d === '') return true;  // NULL = all depts
        if ((int)$d === $dept_id) return true;
    }
    return false;
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
 * Return the department scope for the current user.
 * Returns null  → unrestricted (all departments allowed).
 * Returns int[] → only those specific dept_ids are allowed.
 */
function get_dept_scope(): ?array {
    if (is_super_admin()) return null;
    $user = auth_user();
    if (!$user) return [];

    static $cached = '__unset__';
    if ($cached !== '__unset__') return $cached;

    // User-level override takes priority
    $stmt = db()->prepare('SELECT dept_id FROM user_dept_scope WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($rows)) {
        if (in_array(null, $rows, true)) { $cached = null; return null; }
        $cached = array_map('intval', $rows);
        return $cached;
    }

    // Group-level scope
    $group_ids = $user['group_ids'];
    if (empty($group_ids)) { $cached = null; return null; }

    $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
    $stmt = db()->prepare("SELECT dept_id FROM group_dept_scope WHERE group_id IN ($placeholders)");
    $stmt->execute($group_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($rows)) { $cached = null; return null; }
    if (in_array(null, $rows, true)) { $cached = null; return null; }
    $cached = array_map('intval', array_unique($rows));
    return $cached;
}

/**
 * Gate – redirect with error if user cannot access the given department.
 */
function require_access_dept(int $dept_id): void {
    auth_check();
    if (!can_access_dept($dept_id)) {
        $_SESSION['flash_error'] = 'You do not have permission to access this department.';
        redirect(APP_URL . '/departments/index.php');
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
function flash(string $type, ?string $message = null): ?string {
    if ($message !== null) {
        flash_set($type, $message);
        return null;
    }
    return flash_get($type);
}

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
function redirect(string $url, int $code = 302): never {
    header('Location: ' . $url, true, $code);
    exit;
}

function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function old(string $key, string $default = ''): string {
    return h($_SESSION['old'][$key] ?? $default);
}

function old_array(string $key): array {
    $val = $_SESSION['old'][$key] ?? [];
    return is_array($val) ? $val : [];
}

function save_old(array $data): void {
    $_SESSION['old'] = $data;
}

function clear_old(): void {
    unset($_SESSION['old']);
}
