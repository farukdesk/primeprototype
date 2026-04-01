<?php
/**
 * Shared helpers for the Change Log module.
 */

require_once __DIR__ . '/../includes/auth.php';

/**
 * Record a change in the change_log table.
 *
 * @param string      $module       Module slug / section name (e.g. 'users', 'knowledge-base')
 * @param string      $action       'CREATE', 'UPDATE', or 'DELETE'
 * @param int|null    $record_id    PK of the affected record (nullable)
 * @param string|null $record_label Human-readable label for the record (e.g. username, title)
 * @param string|null $field_name   Specific field that changed; null for whole-record actions
 * @param mixed       $old_value    Previous value
 * @param mixed       $new_value    New value
 * @param string|null $description  Optional free-text summary
 */
function log_change(
    string  $module,
    string  $action,
    ?int    $record_id    = null,
    ?string $record_label = null,
    ?string $field_name   = null,
    mixed   $old_value    = null,
    mixed   $new_value    = null,
    ?string $description  = null
): void {
    $user = auth_user();
    if (!$user) return;

    $valid_actions = ['CREATE', 'UPDATE', 'DELETE'];
    $action = strtoupper($action);
    if (!in_array($action, $valid_actions, true)) $action = 'UPDATE';

    $ip = cl_get_ip();

    $stmt = db()->prepare(
        'INSERT INTO change_log
            (user_id, module, record_id, record_label, action, field_name, old_value, new_value, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $user['id'],
        $module,
        $record_id,
        $record_label,
        $action,
        $field_name,
        $old_value !== null ? (string)$old_value : null,
        $new_value !== null ? (string)$new_value : null,
        $description,
        $ip,
    ]);
}

/**
 * Resolve the visitor's real IP address.
 */
function cl_get_ip(): string
{
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

/**
 * Return a Bootstrap badge for an action type.
 */
function cl_action_badge(string $action): string
{
    return match ($action) {
        'CREATE' => '<span class="badge bg-success">CREATE</span>',
        'DELETE' => '<span class="badge bg-danger">DELETE</span>',
        default  => '<span class="badge bg-primary">UPDATE</span>',
    };
}

/**
 * Return a list of distinct module names present in the log (for filter dropdown).
 */
function cl_distinct_modules(): array
{
    $stmt = db()->query('SELECT DISTINCT module FROM change_log ORDER BY module ASC');
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
