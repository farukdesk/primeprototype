<?php
/**
 * Automatic row-level database snapshots.
 *
 * Wraps PDO so that EVERY data change executed through db() —
 * INSERT, UPDATE, DELETE, REPLACE, even a tiny single-field edit —
 * stores a copy of the affected rows (before + after images) in the
 * db_snapshots table. Super admins can browse and restore snapshots
 * from the Database Snapshots module (admin/db-snapshots/).
 *
 * Design notes:
 * - Fails safe: snapshot errors are logged and NEVER break the write.
 * - If the db_snapshots table is missing (migration not applied yet),
 *   the feature disables itself for the request — the app keeps working.
 * - The snapshot table itself and other audit/noise tables are excluded
 *   (see SNAP_EXCLUDED_TABLES; override any constant in config.php).
 */

if (!defined('SNAP_ENABLED'))         define('SNAP_ENABLED', true);
if (!defined('SNAP_MAX_ROWS'))        define('SNAP_MAX_ROWS', 200);     // max rows captured per statement
if (!defined('SNAP_RETENTION_DAYS'))  define('SNAP_RETENTION_DAYS', 180); // 0 = keep forever
if (!defined('SNAP_EXCLUDED_TABLES')) define('SNAP_EXCLUDED_TABLES', [
    'db_snapshots',         // never snapshot ourselves
    'change_log',           // already an audit table
    'auth_remember_tokens', // login tokens – pure noise, no restore value
]);

class SnapshotStatement extends PDOStatement
{
    protected function __construct(private SnapshotPDO $snap)
    {
    }

    public function execute(?array $params = null): bool
    {
        $ctx = $this->snap->snapshotBefore($this->queryString, $params);
        $ok  = parent::execute($params);
        if ($ok && $ctx !== null) {
            $this->snap->snapshotAfter($ctx);
        }
        return $ok;
    }
}

class SnapshotPDO extends PDO
{
    private static bool $writing  = false; // recursion guard for internal reads/writes
    private static bool $disabled = false; // set when db_snapshots table is missing
    private array $pk_cache = [];

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null, ?array $options = null)
    {
        parent::__construct($dsn, $user, $pass, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SnapshotStatement::class, [$this]]);
    }

    public function exec(string $statement): int|false
    {
        $ctx = $this->snapshotBefore($statement, null);
        $res = parent::exec($statement);
        if ($res !== false && $ctx !== null) $this->snapshotAfter($ctx);
        return $res;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $ctx  = $this->snapshotBefore($query, null);
        $stmt = $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
        if ($stmt !== false && $ctx !== null) $this->snapshotAfter($ctx);
        return $stmt;
    }

    // ── Capture ────────────────────────────────────────────────────────────────────

    /**
     * Called before a write statement executes. Captures the BEFORE image
     * of the rows an UPDATE/DELETE is about to touch. Returns a context
     * array (or null when the statement is not snapshot-worthy).
     */
    public function snapshotBefore(string $sql, ?array $params): ?array
    {
        if (!SNAP_ENABLED || self::$disabled || self::$writing) return null;

        $parsed = $this->parseWrite($sql);
        if ($parsed === null) return null;
        [$action, $table] = $parsed;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;
        if (in_array(strtolower($table), array_map('strtolower', SNAP_EXCLUDED_TABLES), true)) return null;

        $ctx = [
            'action' => $action, 'table' => $table, 'sql' => $sql,
            'rows_before' => null, 'where' => null, 'where_params' => null,
        ];

        if ($action === 'UPDATE' || $action === 'DELETE') {
            [$where, $where_params] = $this->extractWhere($sql, $params);
            if ($where !== null) {
                $ctx['where']        = $where;
                $ctx['where_params'] = $where_params;
                self::$writing = true;
                try {
                    $sel = parent::prepare("SELECT * FROM `$table` WHERE $where LIMIT " . (int)SNAP_MAX_ROWS);
                    $sel->execute($where_params);
                    $ctx['rows_before'] = $sel->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $ctx['rows_before'] = null; // complex statement – never block the write
                } finally {
                    self::$writing = false;
                }
            }
        }
        return $ctx;
    }

    /**
     * Called after a successful write. Captures the AFTER image and
     * persists the snapshot row. All failures are swallowed + logged.
     */
    public function snapshotAfter(array $ctx): void
    {
        if (!SNAP_ENABLED || self::$disabled || self::$writing) return;
        self::$writing = true;
        try {
            $action = $ctx['action'];
            $table  = $ctx['table'];
            $pk     = $this->primaryKey($table);
            $rows_after = null;

            if ($action === 'UPDATE' && $ctx['where'] !== null) {
                try {
                    $sel = parent::prepare("SELECT * FROM `$table` WHERE {$ctx['where']} LIMIT " . (int)SNAP_MAX_ROWS);
                    $sel->execute($ctx['where_params']);
                    $rows_after = $sel->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $rows_after = null;
                }
            } elseif ($action === 'INSERT' || $action === 'REPLACE') {
                $last_id = (int)parent::lastInsertId();
                if ($pk !== null && $last_id > 0) {
                    try {
                        $sel = parent::prepare("SELECT * FROM `$table` WHERE `$pk` = ?");
                        $sel->execute([$last_id]);
                        $rows_after = $sel->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        $rows_after = null;
                    }
                }
            }

            $before = $ctx['rows_before'];
            // 0-row UPDATE/DELETE → nothing actually changed, skip the snapshot.
            if ($action !== 'INSERT' && $action !== 'REPLACE' && empty($before) && empty($rows_after)) {
                return;
            }

            $stmt = parent::prepare(
                'INSERT INTO db_snapshots
                    (table_name, action, pk_column, row_count, rows_before, rows_after,
                     query_snippet, user_id, ip_address, request_uri)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $table,
                $action,
                $pk,
                is_array($before) ? count($before) : (is_array($rows_after) ? count($rows_after) : 0),
                is_array($before)     ? json_encode($before,     JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                is_array($rows_after) ? json_encode($rows_after, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                mb_substr(preg_replace('/\s+/', ' ', trim($ctx['sql'])), 0, 500),
                isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                $this->clientIp(),
                isset($_SERVER['REQUEST_URI']) ? mb_substr((string)$_SERVER['REQUEST_URI'], 0, 500) : null,
            ]);

            // Occasional retention prune (cheap, probabilistic – ~1 in 500 writes).
            if (SNAP_RETENTION_DAYS > 0 && random_int(1, 500) === 1) {
                parent::exec(
                    'DELETE FROM db_snapshots
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int)SNAP_RETENTION_DAYS . ' DAY)'
                );
            }
        } catch (Throwable $e) {
            // Missing snapshot table → disable quietly for this request.
            if (stripos($e->getMessage(), 'db_snapshots') !== false) {
                self::$disabled = true;
            }
            error_log('db-snapshot: ' . $e->getMessage());
        } finally {
            self::$writing = false;
        }
    }

    // ── Parsing helpers ────────────────────────────────────────────────────────

    /** Detect write statements. Returns [ACTION, table] or null. */
    private function parseWrite(string $sql): ?array
    {
        $s = ltrim($sql);
        if (preg_match('/^insert\s+(?:ignore\s+)?into\s+`?([A-Za-z0-9_]+)`?/i', $s, $m)) return ['INSERT', $m[1]];
        if (preg_match('/^replace\s+into\s+`?([A-Za-z0-9_]+)`?/i', $s, $m))              return ['REPLACE', $m[1]];
        if (preg_match('/^update\s+(?:ignore\s+)?`?([A-Za-z0-9_]+)`?/i', $s, $m))        return ['UPDATE', $m[1]];
        if (preg_match('/^delete\s+from\s+`?([A-Za-z0-9_]+)`?/i', $s, $m))               return ['DELETE', $m[1]];
        return null;
    }

    /**
     * Extract the outer WHERE clause and the bound params that belong to it.
     * For positional placeholders the WHERE params are always the LAST n
     * params of the statement (SET params come first in UPDATEs).
     */
    private function extractWhere(string $sql, ?array $params): array
    {
        $pos = stripos($sql, ' where ');
        if ($pos === false) return [null, null];

        $where = substr($sql, $pos + 7);
        $where = preg_replace('/\s+(order\s+by|limit)\s[\s\S]*$/i', '', $where);
        $where = trim((string)$where);
        if ($where === '') return [null, null];

        $n = substr_count($where, '?');
        if ($n === 0) return [$where, []];
        if ($params === null) return [null, null]; // bindValue()-style – cannot rebuild safely
        return [$where, array_slice(array_values($params), -$n)];
    }

    /** Primary key column of a table (cached per request). */
    private function primaryKey(string $table): ?string
    {
        if (!array_key_exists($table, $this->pk_cache)) {
            $this->pk_cache[$table] = null;
            try {
                $stmt = parent::prepare(
                    "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                       AND CONSTRAINT_NAME = 'PRIMARY'
                     ORDER BY ORDINAL_POSITION LIMIT 1"
                );
                $stmt->execute([$table]);
                $col = $stmt->fetchColumn();
                $this->pk_cache[$table] = ($col !== false && $col !== null) ? (string)$col : null;
            } catch (Throwable $e) {
                // leave null
            }
        }
        return $this->pk_cache[$table];
    }

    private function clientIp(): ?string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return null;
    }
}
