<?php
/**
 * Staff API – shared authentication
 * =================================
 * Wraps the admin API token middleware (api_auth) and additionally loads the
 * caller's employee profile. Only accounts whose Employee Type
 * (staff_profiles.department_type) is Administrative ('administrative') or
 * Faculty ('educational') may call the staff endpoints — mirroring the
 * self-service rule used by the Staff Attendance and Leave Management modules.
 */

require_once dirname(__DIR__, 2) . '/includes/auth_api.php';

/**
 * Authenticate the bearer token and return the user + employee profile.
 * Sends a 403 when the account is not an Administrative/Faculty employee.
 *
 * @return array{user: array, profile: array, employee_type: string}
 */
function staff_api_auth(): array
{
    $user = api_auth();

    $profile = [];
    try {
        $stmt = db()->prepare(
            'SELECT sp.*, sd.name AS staff_dept_name
               FROM staff_profiles sp
          LEFT JOIN staff_departments sd ON sd.id = sp.staff_dept_id
              WHERE sp.user_id = ? LIMIT 1'
        );
        $stmt->execute([(int)$user['user_id']]);
        $profile = $stmt->fetch() ?: [];
    } catch (Throwable $e) {
        // staff_departments may not exist on older deployments.
        try {
            $stmt = db()->prepare('SELECT * FROM staff_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute([(int)$user['user_id']]);
            $profile = $stmt->fetch() ?: [];
        } catch (Throwable $e2) {
            $profile = [];
        }
    }

    $type = (string)($profile['department_type'] ?? '');
    if (!in_array($type, ['administrative', 'educational'], true)) {
        api_error(403, 'This account is not registered as an employee. Please contact HR/IT.', ['not_employee' => true]);
    }

    return ['user' => $user, 'profile' => $profile, 'employee_type' => $type];
}

/** Human label for an employee type. */
function staff_employee_type_label(string $type): string
{
    return $type === 'educational' ? 'Faculty' : 'Administrative';
}

/** The user's primary group id (api_auth does not include it). */
function staff_user_group_id(int $user_id): int
{
    try {
        $stmt = db()->prepare('SELECT group_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** The faculty member's academic department id, or 0 (used for dept notices). */
function staff_academic_dept_id(int $user_id): int
{
    foreach ([
        'SELECT dept_id FROM faculty_profiles WHERE user_id = ? LIMIT 1',
        'SELECT dept_id FROM dept_faculty WHERE user_id = ? AND is_active = 1 ORDER BY is_head DESC, id ASC LIMIT 1',
    ] as $sql) {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute([$user_id]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        } catch (Throwable $e) {
            // table missing – try the next source
        }
    }
    return 0;
}
