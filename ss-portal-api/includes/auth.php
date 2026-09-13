<?php
/**
 * SS Portal – portal users, login session and CSRF protection.
 * Users live in the portal's own ssp_users table; nothing here talks to
 * Prime University.
 */

require_once __DIR__ . '/bootstrap.php';

const SSP_LOGIN_MAX_FAILURES = 5;
const SSP_LOGIN_LOCK_SECONDS = 300;

function ssp_current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $uid = (int)($_SESSION['ssp_user_id'] ?? 0);
    if ($uid <= 0) {
        return $user = null;
    }
    $st = ssp_db()->prepare('SELECT id, username, full_name, email, role, is_active FROM ssp_users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row || !(int)$row['is_active']) {
        unset($_SESSION['ssp_user_id']);
        return $user = null;
    }
    return $user = $row;
}

/** Redirects to the login page when nobody is signed in. */
function ssp_require_login(): array
{
    $user = ssp_current_user();
    if ($user === null) {
        $_SESSION['ssp_after_login'] = (string)($_SERVER['REQUEST_URI'] ?? '');
        ssp_redirect('login.php');
    }
    return $user;
}

function ssp_require_admin(): array
{
    $user = ssp_require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
    return $user;
}

/** Seconds remaining on the failed-login lock for this session (0 = not locked). */
function ssp_login_locked(): int
{
    $until = (int)($_SESSION['ssp_login_lock_until'] ?? 0);
    return $until > time() ? $until - time() : 0;
}

function ssp_login_attempt(string $username, string $password): bool
{
    if (ssp_login_locked() > 0) {
        return false;
    }
    $db = ssp_db();
    $st = $db->prepare('SELECT id, password_hash, is_active FROM ssp_users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    $ok  = $row && (int)$row['is_active'] && password_verify($password, (string)$row['password_hash']);

    if (!$ok) {
        $fails = (int)($_SESSION['ssp_login_failures'] ?? 0) + 1;
        $_SESSION['ssp_login_failures'] = $fails;
        if ($fails >= SSP_LOGIN_MAX_FAILURES) {
            $_SESSION['ssp_login_lock_until'] = time() + SSP_LOGIN_LOCK_SECONDS;
            $_SESSION['ssp_login_failures']   = 0;
        }
        return false;
    }

    session_regenerate_id(true);
    unset($_SESSION['ssp_login_failures'], $_SESSION['ssp_login_lock_until']);
    $_SESSION['ssp_user_id'] = (int)$row['id'];

    if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
        $db->prepare('UPDATE ssp_users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]);
    }
    $db->prepare('UPDATE ssp_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    return true;
}

function ssp_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function ssp_csrf_token(): string
{
    if (empty($_SESSION['ssp_csrf'])) {
        $_SESSION['ssp_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['ssp_csrf'];
}

function ssp_csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(ssp_csrf_token()) . '">';
}

/** Aborts the request when the POSTed token does not match the session. */
function ssp_csrf_verify(): void
{
    $sent = (string)($_POST['_token'] ?? '');
    if ($sent === '' || !hash_equals(ssp_csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired or the form token is invalid. Go back, reload the page and try again.');
    }
}
