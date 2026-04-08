<?php
/**
 * Broadcast Acknowledgment Page
 * Recipients click the link in their email to confirm they have received
 * and understood a broadcast notice.
 */
require_once __DIR__ . '/includes/config.php';

$token  = trim($_GET['token'] ?? '');
$status = 'invalid'; // invalid | already | success
$row    = null;

if ($token !== '' && preg_match('/^[0-9a-f]{64}$/', $token)) {
    $db = front_db();
    if ($db) {
        $stmt = $db->prepare(
            'SELECT br.*, b.subject, b.ack_required
             FROM broadcast_recipients br
             JOIN broadcasts b ON b.id = br.broadcast_id
             WHERE br.ack_token = ?
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['acked_at'] !== null) {
                $status = 'already';
            } else {
                // Resolve department for this recipient
                $department = null;
                if (!empty($row['user_id'])) {
                    // Staff/admin user – use their group name
                    $dept_stmt = $db->prepare(
                        'SELECT g.name FROM users u JOIN user_groups g ON g.id = u.group_id WHERE u.id = ? LIMIT 1'
                    );
                    $dept_stmt->execute([$row['user_id']]);
                    $dept_row = $dept_stmt->fetch();
                    if ($dept_row) {
                        $department = $dept_row['name'];
                    }
                } else {
                    // Student – look up by email in students table
                    $dept_stmt = $db->prepare(
                        'SELECT d.name FROM students s JOIN dept_departments d ON d.id = s.dept_id WHERE s.email = ? LIMIT 1'
                    );
                    $dept_stmt->execute([$row['email']]);
                    $dept_row = $dept_stmt->fetch();
                    if ($dept_row) {
                        $department = $dept_row['name'];
                    }
                }

                // Record acknowledgment
                $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: null;

                $upd = $db->prepare(
                    'UPDATE broadcast_recipients
                     SET acked_at = NOW(), ack_ip = ?, ack_department = ?
                     WHERE ack_token = ? AND acked_at IS NULL'
                );
                $upd->execute([$ip, $department, $token]);

                if ($upd->rowCount() > 0) {
                    $status = 'success';
                } else {
                    // Race condition: already acknowledged by another request
                    $status = 'already';
                }
            }
        }
    }
}

$page_title = 'Email Acknowledgment – Prime University';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= fh($page_title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { background: #f4f6fb; font-family: 'Segoe UI', Arial, sans-serif; }
        .ack-card { max-width: 520px; margin: 80px auto; border-radius: 14px; box-shadow: 0 4px 32px rgba(0,0,0,.10); }
        .ack-icon { font-size: 3.5rem; }
    </style>
</head>
<body>
<div class="container">
    <div class="card ack-card p-4 p-md-5">
        <div class="text-center mb-4">
            <img src="<?= fh(defined('SITE_URL') ? SITE_URL : '') ?>/assets/img/logo/logo-black.png"
                 alt="Prime University" style="max-height:54px;" onerror="this.style.display='none'">
        </div>

        <?php if ($status === 'success'): ?>
        <div class="text-center">
            <div class="ack-icon text-success mb-3"><i class="fas fa-check-circle"></i></div>
            <h2 class="h4 fw-bold mb-2">Acknowledgment Recorded</h2>
            <p class="text-muted mb-3">
                Thank you, <strong><?= fh($row['full_name']) ?></strong>.<br>
                Your acknowledgment for the notice
                <strong>"<?= fh($row['subject']) ?>"</strong>
                has been successfully recorded.
            </p>
            <?php if (!empty($department)): ?>
            <p class="text-muted small">Department / Group: <strong><?= fh($department) ?></strong></p>
            <?php endif; ?>
            <div class="alert alert-success mt-3">
                <i class="fas fa-calendar-check me-1"></i>
                Acknowledged on <strong><?= date('d F Y \a\t h:i A') ?></strong>
            </div>
        </div>

        <?php elseif ($status === 'already'): ?>
        <div class="text-center">
            <div class="ack-icon text-info mb-3"><i class="fas fa-info-circle"></i></div>
            <h2 class="h4 fw-bold mb-2">Already Acknowledged</h2>
            <p class="text-muted mb-3">
                You have already acknowledged the notice
                <strong>"<?= fh($row['subject']) ?>"</strong>.
                No action is needed.
            </p>
            <div class="alert alert-info">
                <i class="fas fa-calendar-check me-1"></i>
                Previously acknowledged on
                <strong><?= fh(date('d F Y \a\t h:i A', strtotime($row['acked_at']))) ?></strong>
            </div>
        </div>

        <?php else: ?>
        <div class="text-center">
            <div class="ack-icon text-danger mb-3"><i class="fas fa-times-circle"></i></div>
            <h2 class="h4 fw-bold mb-2">Invalid or Expired Link</h2>
            <p class="text-muted">
                This acknowledgment link is invalid or has expired.<br>
                Please contact the administration if you believe this is an error.
            </p>
        </div>
        <?php endif; ?>

        <hr class="my-4">
        <p class="text-center text-muted small mb-0">
            &copy; <?= date('Y') ?> Prime University &middot;
            <a href="<?= fh(defined('SITE_URL') ? SITE_URL : '/') ?>" class="text-muted">Return to Website</a>
        </p>
    </div>
</div>
</body>
</html>
