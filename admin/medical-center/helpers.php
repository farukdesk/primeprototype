<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

function mc_can_edit(): bool   { return is_super_admin() || can_access('medical-center', 'can_edit'); }
function mc_can_create(): bool { return is_super_admin() || can_access('medical-center', 'can_create'); }
function mc_can_delete(): bool { return is_super_admin() || can_access('medical-center', 'can_delete'); }

function mc_setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $rows  = db()->query('SELECT `key`, `value` FROM mc_settings')->fetchAll();
        $cache = array_column($rows, 'value', 'key');
    }
    return $cache[$key] ?? $default;
}

function mc_status_badge(string $status): string {
    $map = [
        'pending'   => 'warning',
        'confirmed' => 'info',
        'completed' => 'success',
        'cancelled' => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst(h($status)) . '</span>';
}

function mc_patient_badge(string $type): string {
    $map = ['student' => 'primary', 'faculty' => 'info', 'staff' => 'success', 'officer' => 'secondary'];
    $color = $map[$type] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst(h($type)) . '</span>';
}

function mc_day_name(int $dow): string {
    return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$dow] ?? 'Unknown';
}
