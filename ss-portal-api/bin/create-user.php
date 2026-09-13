#!/usr/bin/env php
<?php
/**
 * SS Portal – manage portal users (CLI only).
 *
 *   php bin/create-user.php --username=admin --name="Site Admin" --role=admin [--email=..] [--password=..]
 *   php bin/create-user.php --list
 *   php bin/create-user.php --reset-password=<username> [--password=..]
 *   php bin/create-user.php --disable=<username> | --enable=<username>
 *
 * When --password is omitted a random one is generated and printed once.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$opts = getopt('', ['username:', 'name:', 'email:', 'password:', 'role:', 'list', 'reset-password:', 'disable:', 'enable:', 'help']);
$db   = ssp_db();

$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
$fail = static function (string $line): void {
    fwrite(STDERR, 'Error: ' . $line . PHP_EOL);
    exit(1);
};
$randomPassword = static function (): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#%^*';
    $pw = '';
    for ($i = 0; $i < 14; $i++) {
        $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pw;
};

if (isset($opts['help']) || !$opts) {
    $out("Usage:");
    $out("  --username=<u> --name=<full name> [--role=admin|operator] [--email=<e>] [--password=<p>]   create a user");
    $out("  --list                                                                                 list users");
    $out("  --reset-password=<username> [--password=<p>]                                           set a new password");
    $out("  --disable=<username> | --enable=<username>                                             toggle access");
    exit(0);
}

if (isset($opts['list'])) {
    $rows = $db->query('SELECT id, username, full_name, role, is_active, last_login_at, created_at FROM ssp_users ORDER BY id')->fetchAll();
    $out(sprintf('%-4s %-20s %-28s %-9s %-8s %-19s %s', 'id', 'username', 'name', 'role', 'active', 'last login', 'created'));
    foreach ($rows as $r) {
        $out(sprintf('%-4d %-20s %-28s %-9s %-8s %-19s %s', $r['id'], $r['username'], mb_substr($r['full_name'], 0, 28), $r['role'], $r['is_active'] ? 'yes' : 'no', $r['last_login_at'] ?? '-', $r['created_at']));
    }
    exit(0);
}

if (isset($opts['disable']) || isset($opts['enable'])) {
    $username = (string)($opts['disable'] ?? $opts['enable']);
    $active   = isset($opts['enable']) ? 1 : 0;
    $st = $db->prepare('UPDATE ssp_users SET is_active = ? WHERE username = ?');
    $st->execute([$active, $username]);
    $st->rowCount() ? $out(($active ? 'Enabled ' : 'Disabled ') . $username . '.') : $fail('No user "' . $username . '".');
    exit(0);
}

if (isset($opts['reset-password'])) {
    $username = (string)$opts['reset-password'];
    $password = (string)($opts['password'] ?? '');
    $generated = $password === '';
    if ($generated) {
        $password = $randomPassword();
    } elseif (strlen($password) < 10) {
        $fail('Password must be at least 10 characters.');
    }
    $st = $db->prepare('UPDATE ssp_users SET password_hash = ? WHERE username = ?');
    $st->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
    if (!$st->rowCount()) {
        $fail('No user "' . $username . '".');
    }
    $out('Password updated for ' . $username . '.');
    if ($generated) {
        $out('New password (shown once): ' . $password);
    }
    exit(0);
}

// ── Create ──────────────────────────────────────────────────────────────────────────────────────
$username = trim((string)($opts['username'] ?? ''));
$name     = trim((string)($opts['name'] ?? ''));
$email    = trim((string)($opts['email'] ?? ''));
$role     = (string)($opts['role'] ?? 'operator');
$password = (string)($opts['password'] ?? '');

if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
    $fail('--username must be 3-60 characters: letters, digits, dot, underscore, hyphen.');
}
if ($name === '') {
    $fail('--name is required.');
}
if (!in_array($role, ['admin', 'operator'], true)) {
    $fail('--role must be admin or operator.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('--email is not a valid address.');
}
$generated = $password === '';
if ($generated) {
    $password = $randomPassword();
} elseif (strlen($password) < 10) {
    $fail('Password must be at least 10 characters.');
}

try {
    $db->prepare('INSERT INTO ssp_users (username, full_name, email, password_hash, role) VALUES (?,?,?,?,?)')
       ->execute([$username, $name, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $role]);
} catch (PDOException $e) {
    $fail(strpos($e->getMessage(), '1062') !== false ? 'Username "' . $username . '" already exists.' : $e->getMessage());
}

$out('Created ' . $role . ' user "' . $username . '" (id ' . $db->lastInsertId() . ').');
if ($generated) {
    $out('Password (shown once): ' . $password);
}
