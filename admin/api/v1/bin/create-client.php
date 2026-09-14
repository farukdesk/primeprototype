#!/usr/bin/env php
<?php
/**
 * CLI – issue or revoke a third-party API client key
 * ==================================================
 * Run from the server shell (never exposed over HTTP – see bin/.htaccess).
 *
 *   Issue a key:
 *     php admin/api/v1/bin/create-client.php \
 *         --name="Partner Admission CRM" \
 *         [--scopes=students:create,students:update,students:delete,results:create,reference:read] \
 *         [--ips=203.0.113.10,198.51.100.0/24] \
 *         [--rate=60] \
 *         [--expires=2027-12-31] \
 *         [--status="Not Admitted Yet"] \
 *         [--created-by=1] \
 *         [--email=ops@partner.example]
 *
 *   List clients:     php admin/api/v1/bin/create-client.php --list
 *   Revoke a client:  php admin/api/v1/bin/create-client.php --revoke=<id>
 *   Re-enable:        php admin/api/v1/bin/create-client.php --enable=<id>
 *
 * The plain key is printed ONCE.  Only its SHA-256 hash is stored.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once dirname(__DIR__, 3) . '/includes/db.php';

$opts = getopt('', [
    'name:', 'scopes::', 'ips::', 'rate::', 'expires::', 'status::',
    'created-by::', 'email::', 'list', 'revoke::', 'enable::', 'help',
]);

$out = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};
$fail = static function (string $msg): never {
    fwrite(STDERR, 'Error: ' . $msg . PHP_EOL);
    exit(1);
};

if (isset($opts['help']) || $opts === []) {
    $out('Usage:');
    $out('  --name="Partner Name" [--scopes=a,b] [--ips=ip,cidr] [--rate=60] [--expires=YYYY-MM-DD]');
    $out('        [--status="Not Admitted Yet"] [--created-by=<users.id>] [--email=contact@partner]');
    $out('  --list');
    $out('  --revoke=<id> | --enable=<id>');
    exit(0);
}

try {
    $db = db();

    // ── List ────────────────────────────────────────────────────────────────
    if (isset($opts['list'])) {
        $rows = $db->query(
            'SELECT id, name, key_prefix, scopes, rate_limit_per_min, is_active, expires_at, last_used_at
               FROM api_clients ORDER BY id'
        )->fetchAll();
        if (!$rows) {
            $out('No API clients.');
            exit(0);
        }
        $out(sprintf('%-4s %-30s %-14s %-8s %-6s %-19s %-19s %s', 'ID', 'Name', 'Key prefix', 'Active', 'Rate', 'Expires', 'Last used', 'Scopes'));
        foreach ($rows as $r) {
            $out(sprintf('%-4d %-30s %-14s %-8s %-6d %-19s %-19s %s',
                $r['id'], mb_strimwidth($r['name'], 0, 30, '…'), $r['key_prefix'] . '…',
                $r['is_active'] ? 'yes' : 'no', $r['rate_limit_per_min'],
                $r['expires_at'] ?? 'never', $r['last_used_at'] ?? '-', $r['scopes']));
        }
        exit(0);
    }

    // ── Revoke / enable ─────────────────────────────────────────────────────
    foreach (['revoke' => 0, 'enable' => 1] as $flag => $active) {
        if (isset($opts[$flag])) {
            $id = (int)$opts[$flag];
            if ($id <= 0) {
                $fail('--' . $flag . ' requires a numeric client id.');
            }
            $stmt = $db->prepare('UPDATE api_clients SET is_active = ? WHERE id = ?');
            $stmt->execute([$active, $id]);
            $out($stmt->rowCount() ? 'Client #' . $id . ($active ? ' enabled.' : ' revoked.') : 'Client #' . $id . ' not found (or unchanged).');
            exit(0);
        }
    }

    // ── Issue ───────────────────────────────────────────────────────────────
    $name = trim((string)($opts['name'] ?? ''));
    if ($name === '') {
        $fail('--name is required.');
    }

    $scopes = trim((string)($opts['scopes'] ?? 'students:create,students:update,students:delete,results:create,reference:read'));
    $scopes = implode(',', array_filter(array_map('trim', explode(',', $scopes))));
    if ($scopes === '') {
        $fail('--scopes cannot be empty.');
    }

    $ips = trim((string)($opts['ips'] ?? ''));
    foreach (preg_split('/[\s,]+/', $ips, -1, PREG_SPLIT_NO_EMPTY) as $rule) {
        $host = explode('/', $rule, 2)[0];
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $fail('Invalid IP / CIDR in --ips: ' . $rule);
        }
    }

    $rate = (int)($opts['rate'] ?? 60);
    if ($rate < 1 || $rate > 10000) {
        $fail('--rate must be between 1 and 10000.');
    }

    $expires = trim((string)($opts['expires'] ?? ''));
    if ($expires !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $expires);
        if (!$d || $d->format('Y-m-d') !== $expires) {
            $fail('--expires must be YYYY-MM-DD.');
        }
        $expires = $d->format('Y-m-d') . ' 23:59:59';
    }

    $status   = trim((string)($opts['status'] ?? 'Not Admitted Yet'));
    $statuses = ['Active', 'Inactive', 'Graduated', 'Dropped', 'Not Admitted Yet'];
    $matched  = null;
    foreach ($statuses as $s) {
        if (strcasecmp($s, $status) === 0) {
            $matched = $s;
        }
    }
    if ($matched === null) {
        $fail('--status must be one of: ' . implode(', ', $statuses));
    }

    $created_by = isset($opts['created-by']) && $opts['created-by'] !== false ? (int)$opts['created-by'] : null;
    if ($created_by !== null) {
        $chk = $db->prepare('SELECT id FROM users WHERE id = ?');
        $chk->execute([$created_by]);
        if (!$chk->fetchColumn()) {
            $fail('--created-by user #' . $created_by . ' does not exist.');
        }
    }

    $email = trim((string)($opts['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fail('--email is not a valid address.');
    }

    $plain  = 'pu_' . bin2hex(random_bytes(24));   // 51 chars, matches CAPI_KEY_PATTERN
    $prefix = substr($plain, 0, 12);
    $hash   = hash('sha256', $plain);

    $db->prepare(
        'INSERT INTO api_clients
            (name, key_prefix, key_hash, scopes, ip_allowlist, rate_limit_per_min,
             default_status, contact_email, is_active, expires_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
    )->execute([
        $name, $prefix, $hash, $scopes, $ips !== '' ? $ips : null, $rate,
        $matched, $email !== '' ? $email : null, $expires !== '' ? $expires : null, $created_by,
    ]);
    $id = (int)$db->lastInsertId();

    $out();
    $out('API client created');
    $out('  ID           : ' . $id);
    $out('  Name         : ' . $name);
    $out('  Scopes       : ' . $scopes);
    $out('  IP allowlist : ' . ($ips !== '' ? $ips : '(any)'));
    $out('  Rate limit   : ' . $rate . ' req/min');
    $out('  Expires      : ' . ($expires !== '' ? $expires : 'never'));
    $out('  Default stat.: ' . $matched);
    $out();
    $out('  API KEY (shown once, store it securely):');
    $out('  ' . $plain);
    $out();
    $out('Share the key with the partner over a secure channel together with admin/api/v1/API-GUIDE.md.');
} catch (Throwable $e) {
    $fail($e->getMessage() . ' (is admin/student-api-clients-v1.sql applied?)');
}
