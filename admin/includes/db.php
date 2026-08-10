<?php
/**
 * Database Connection (PDO)
 *
 * db() returns a SnapshotPDO: every INSERT/UPDATE/DELETE/REPLACE is
 * automatically snapshotted (before/after row images) into db_snapshots
 * so super admins can restore any change from admin/db-snapshots/.
 * See includes/db-snapshot.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-snapshot.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new SnapshotPDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production replace with a friendly error page
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
}
