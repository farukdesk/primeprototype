<?php
/**
 * Hall Management – shared helpers.
 */
require_once __DIR__ . '/../includes/auth.php';

/**
 * Departments visible to the current user (dept scope aware).
 */
function hm_departments(): array
{
    $scope = get_dept_scope();
    if ($scope !== null && empty($scope)) return [];
    $sql    = 'SELECT id, name FROM dept_departments WHERE is_active = 1';
    $params = [];
    if ($scope !== null) {
        $ph  = implode(',', array_fill(0, count($scope), '?'));
        $sql .= " AND id IN ($ph)";
        $params = $scope;
    }
    $sql .= ' ORDER BY name ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Load one hall (with dept name) or null. Enforces dept scope.
 */
function hm_get_hall(int $id): ?array
{
    $st = db()->prepare(
        'SELECT h.*, d.name AS dept_name
           FROM hm_halls h
           JOIN dept_departments d ON d.id = h.dept_id
          WHERE h.id = ?'
    );
    $st->execute([$id]);
    $hall = $st->fetch();
    if (!$hall) return null;
    if (!can_access_dept((int)$hall['dept_id'])) return null;
    return $hall;
}

/**
 * Columns of a hall ordered by col_no.
 */
function hm_hall_columns(int $hall_id): array
{
    $st = db()->prepare(
        'SELECT col_no, seat_capacity FROM hm_hall_columns WHERE hall_id = ? ORDER BY col_no ASC'
    );
    $st->execute([$hall_id]);
    return $st->fetchAll();
}

/**
 * Validate + normalise the submitted column capacities.
 * Returns [list of int capacities (1-based order), error string or null].
 */
function hm_parse_columns(array $raw, int $expected): array
{
    $caps = [];
    for ($i = 0; $i < $expected; $i++) {
        $cap = (int)($raw[$i] ?? 0);
        if ($cap < 1 || $cap > 500) {
            return [[], 'Each column must have a seat capacity between 1 and 500.'];
        }
        $caps[] = $cap;
    }
    return [$caps, null];
}

/**
 * Replace the column rows of a hall.
 */
function hm_save_columns(int $hall_id, array $caps): void
{
    db()->prepare('DELETE FROM hm_hall_columns WHERE hall_id = ?')->execute([$hall_id]);
    $ins = db()->prepare('INSERT INTO hm_hall_columns (hall_id, col_no, seat_capacity) VALUES (?, ?, ?)');
    foreach ($caps as $i => $cap) {
        $ins->execute([$hall_id, $i + 1, $cap]);
    }
}
