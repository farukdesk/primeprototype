<?php
/**
 * App Notification – Registered Devices
 * =====================================
 * Lists every student who has installed the Android app and registered an FCM
 * device token (student_push_tokens), including their name and department, plus
 * registered employee/user devices (api_push_tokens).
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_access('app-notifications');
require_once __DIR__ . '/helpers.php';

$page_title = 'Registered App Devices';
$q          = trim($_GET['q'] ?? '');

// Whether the app_version column exists (see admin/app-push-app-version.sql).
$has_app_version = static function (string $table): bool {
    try {
        db()->query("SELECT app_version FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
};
$spt_ver_col = $has_app_version('student_push_tokens') ? 't.app_version' : 'NULL AS app_version';
$apt_ver_col = $has_app_version('api_push_tokens')     ? 't.app_version' : 'NULL AS app_version';

// ── Student devices ─────────────────────────────────────────────────────────
$student_devices = [];
try {
    $sql = "SELECT t.id, t.platform, t.device_id, t.created_at, t.updated_at,
                   u.full_name AS account_name, u.username, u.email AS account_email,
                   s.id AS student_db_id,
                   s.student_id, s.full_name AS student_name,
                   s.email AS student_email, s.phone AS student_phone,
                   d.name AS dept_name, d.code AS dept_code,
                   p.program_name,
                   b.name AS batch_name
            FROM student_push_tokens t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN students s ON s.portal_user_id = t.user_id
            LEFT JOIN dept_departments d ON d.id = s.dept_id
            LEFT JOIN dept_academic_programs p ON p.id = s.program_id
            LEFT JOIN student_batches b ON b.id = s.batch_id
            WHERE t.fcm_token IS NOT NULL AND t.fcm_token != ''";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (s.full_name LIKE ? OR s.student_id LIKE ? OR d.name LIKE ?
                       OR s.email LIKE ? OR s.phone LIKE ?
                       OR u.full_name LIKE ? OR u.username LIKE ?)";
        $like   = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like, $like, $like];
    }
    $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $student_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('APN devices: student query failed – ' . $e->getMessage());
}

// ── Employee / user devices ─────────────────────────────────────────────────
$user_devices = [];
try {
    $sql = "SELECT t.id, t.platform, t.device_id, t.created_at, t.updated_at,
                   u.id AS user_db_id, u.full_name, u.username, u.email
            FROM api_push_tokens t
            JOIN users u ON u.id = t.user_id AND u.is_active = 1
            WHERE t.fcm_token IS NOT NULL AND t.fcm_token != ''";
    $params = [];
    if ($q !== '') {
        $sql   .= ' AND (u.full_name LIKE ? OR u.username LIKE ?)';
        $like   = '%' . $q . '%';
        $params = [$like, $like];
    }
    $sql .= ' ORDER BY t.updated_at DESC, t.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $user_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('APN devices: user query failed – ' . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-mobile-alt me-2 text-primary"></i>Registered App Devices</h1>
        <p class="text-muted small mb-0">Everyone who installed the app and registered a push device.</p>
    </div>
    <a href="<?= APP_URL ?>/app-notifications/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Notifications
    </a>
</div>

<?php flash_show(); ?>

<form method="get" class="mb-4">
    <div class="input-group" style="max-width:420px;">
        <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
               placeholder="Search by name, student ID or department…">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
        <?php if ($q !== ''): ?>
        <a href="<?= APP_URL ?>/app-notifications/devices.php" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h2 class="h6 mb-0"><i class="fas fa-user-graduate me-2 text-primary"></i>Student Devices</h2>
        <span class="badge bg-primary"><?= count($student_devices) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($student_devices)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-mobile-alt fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No student devices found<?= $q !== '' ? ' for this search' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Student ID</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Program</th>
                        <th>Batch</th>
                        <th>Platform</th>
                        <th>Registered</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 0; foreach ($student_devices as $r): $i++; ?>
                <tr>
                    <td class="text-muted"><?= $i ?></td>
                    <td class="fw-semibold">
                        <?php if (!empty($r['student_db_id'])): ?>
                        <a href="<?= APP_URL ?>/students/view.php?id=<?= (int)$r['student_db_id'] ?>"
                           class="text-decoration-none"><?= h($r['student_name'] ?? $r['account_name'] ?? '—') ?></a>
                        <?php else: ?>
                        <?= h($r['student_name'] ?? $r['account_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['student_id'] ?? '—') ?></td>
                    <td>
                        <?= h($r['dept_name'] ?? '—') ?>
                        <?php if (!empty($r['dept_code'])): ?>
                        <span class="text-muted small">(<?= h($r['dept_code']) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php $sd_email = $r['student_email'] ?? $r['account_email'] ?? ''; ?>
                        <?php if (!empty($sd_email)): ?>
                        <a href="mailto:<?= h($sd_email) ?>"><?= h($sd_email) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($r['student_phone'])): ?>
                        <a href="tel:<?= h($r['student_phone']) ?>"><?= h($r['student_phone']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="small"><?= h($r['program_name'] ?? '—') ?></td>
                    <td class="small"><?= h($r['batch_name'] ?? '—') ?></td>
                    <td><span class="badge bg-light text-dark border"><i class="fab fa-<?= ($r['platform'] ?? '') === 'ios' ? 'apple' : 'android' ?> me-1"></i><?= h(ucfirst($r['platform'] ?? 'android')) ?></span></td>
                    <td class="small text-muted"><?= !empty($r['created_at']) ? h(date('d M Y', strtotime($r['created_at']))) : '—' ?></td>
                    <td class="small text-muted"><?= !empty($r['updated_at']) ? h(date('d M Y H:i', strtotime($r['updated_at']))) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h2 class="h6 mb-0"><i class="fas fa-user-tie me-2 text-primary"></i>Employee / User Devices</h2>
        <span class="badge bg-primary"><?= count($user_devices) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($user_devices)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-mobile-alt fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">No employee/user devices found<?= $q !== '' ? ' for this search' : '' ?>.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Platform</th>
                        <th>Registered</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 0; foreach ($user_devices as $r): $i++; ?>
                <tr>
                    <td class="text-muted"><?= $i ?></td>
                    <td class="fw-semibold">
                        <?php if (!empty($r['user_db_id'])): ?>
                        <a href="<?= APP_URL ?>/users/edit.php?id=<?= (int)$r['user_db_id'] ?>"
                           class="text-decoration-none"><?= h($r['full_name'] ?? '—') ?></a>
                        <?php else: ?>
                        <?= h($r['full_name'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['username'] ?? '—') ?></td>
                    <td class="small">
                        <?php if (!empty($r['email'])): ?>
                        <a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="badge bg-light text-dark border"><i class="fab fa-<?= ($r['platform'] ?? '') === 'ios' ? 'apple' : 'android' ?> me-1"></i><?= h(ucfirst($r['platform'] ?? 'android')) ?></span></td>
                    <td class="small text-muted"><?= !empty($r['created_at']) ? h(date('d M Y', strtotime($r['created_at']))) : '—' ?></td>
                    <td class="small text-muted"><?= !empty($r['updated_at']) ? h(date('d M Y H:i', strtotime($r['updated_at']))) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
