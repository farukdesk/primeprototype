<?php
/**
 * Database Snapshots module – shared helpers.
 * Super-admin-only browsing + restore of automatic row-level snapshots
 * captured by includes/db-snapshot.php.
 */

require_once __DIR__ . '/../includes/auth.php';

/** Fetch one snapshot with user names. */
function snap_get(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, u.full_name AS user_name, r.full_name AS restorer_name
         FROM db_snapshots s
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN users r ON r.id = s.restored_by
         WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Current columns of a table (schema may have drifted since the snapshot). */
function snap_table_columns(string $table): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    try {
        $stmt = db()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Restore a snapshot.
 *  - UPDATE / DELETE / REPLACE → write the before-images back
 *    (upsert by primary/unique key, restricted to columns that still exist).
 *  - INSERT → undo: delete the inserted rows by primary key.
 * The restore itself runs through db() so it is snapshotted too (full audit).
 *
 * @return array{0: bool, 1: string} [ok, message]
 */
function snap_restore(int $snapshot_id): array
{
    $snap = snap_get($snapshot_id);
    if (!$snap) return [false, 'Snapshot not found.'];

    $table = (string)$snap['table_name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [false, 'Invalid table name in snapshot.'];

    $cols_now = snap_table_columns($table);
    if (empty($cols_now)) return [false, "Table `$table` no longer exists — cannot restore."];

    $before = $snap['rows_before'] ? json_decode((string)$snap['rows_before'], true) : null;
    $after  = $snap['rows_after']  ? json_decode((string)$snap['rows_after'],  true) : null;
    $pdo    = db();
    $user   = auth_user();

    try {
        if (in_array($snap['action'], ['UPDATE', 'DELETE', 'REPLACE'], true)) {
            if (empty($before)) {
                return [false, 'No before-image stored for this snapshot — nothing to restore.'];
            }
            $pdo->beginTransaction();
            $n = 0;
            foreach ($before as $row) {
                if (!is_array($row)) continue;
                $cols = array_values(array_intersect(array_keys($row), $cols_now));
                if (empty($cols)) continue;
                $col_sql = implode(', ', array_map(fn($c) => "`$c`", $cols));
                $ph      = implode(', ', array_fill(0, count($cols), '?'));
                $updates = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
                $stmt = $pdo->prepare(
                    "INSERT INTO `$table` ($col_sql) VALUES ($ph)
                     ON DUPLICATE KEY UPDATE $updates"
                );
                $stmt->execute(array_map(fn($c) => $row[$c], $cols));
                $n++;
            }
            $pdo->commit();
            $msg = "Restored $n row(s) in `$table` to the state before this {$snap['action']}.";
        } elseif ($snap['action'] === 'INSERT') {
            $pk = (string)($snap['pk_column'] ?? '');
            if ($pk === '' || !preg_match('/^[A-Za-z0-9_]+$/', $pk) || empty($after)) {
                return [false, 'This INSERT snapshot has no primary-key data — it cannot be undone automatically.'];
            }
            $ids = array_values(array_filter(
                array_map(fn($r) => is_array($r) ? ($r[$pk] ?? null) : null, $after),
                fn($v) => $v !== null && $v !== ''
            ));
            if (empty($ids)) return [false, 'No primary-key values stored — cannot undo.'];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` IN ($ph)");
            $stmt->execute($ids);
            $n = $stmt->rowCount();
            $pdo->commit();
            $msg = "Insert undone — removed $n row(s) from `$table`.";
        } else {
            return [false, 'Unsupported snapshot action: ' . $snap['action']];
        }

        db()->prepare('UPDATE db_snapshots SET restored_at = NOW(), restored_by = ? WHERE id = ?')
            ->execute([$user['id'] ?? null, $snapshot_id]);

        return [true, $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, 'Restore failed: ' . $e->getMessage()];
    }
}

/** Can this snapshot be restored? */
function snap_restorable(array $snap): bool
{
    if (in_array($snap['action'], ['UPDATE', 'DELETE', 'REPLACE'], true)) {
        return !empty($snap['rows_before']);
    }
    if ($snap['action'] === 'INSERT') {
        return !empty($snap['pk_column']) && !empty($snap['rows_after']);
    }
    return false;
}

/** Bootstrap badge for a snapshot action. */
function snap_action_badge(string $action): string
{
    return match ($action) {
        'INSERT'  => '<span class="badge bg-success">INSERT</span>',
        'DELETE'  => '<span class="badge bg-danger">DELETE</span>',
        'REPLACE' => '<span class="badge bg-warning text-dark">REPLACE</span>',
        default   => '<span class="badge bg-primary">UPDATE</span>',
    };
}
