<?php
/**
 * Front-end PDO database connection.
 * Returns a shared PDO instance (singleton via static variable), or null on failure.
 */
function front_db(): ?PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Silently fail on front-end – sections will render empty
            return null;
        }
    }
    return $pdo;
}

/**
 * HTML-escape a value for safe output.
 */
function fh(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Strip all characters except digits and leading + from a phone number,
 * returning a tel: href-safe string.
 */
function sanitize_phone(string $phone): string {
    return preg_replace('/[^0-9+]/', '', $phone);
}

/**
 * Load all cms_header_settings into an associative array.
 * Returns empty array on DB failure.
 */
function get_header_settings(): array {
    try {
        $db = front_db();
        if (!$db) return [];
        $rows = $db->query('SELECT setting_key, setting_value FROM cms_header_settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Load all cms_footer_settings into an associative array.
 * Returns empty array on DB failure.
 */
function get_footer_settings(): array {
    try {
        $db = front_db();
        if (!$db) return [];
        $rows = $db->query('SELECT setting_key, setting_value FROM cms_footer_settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
