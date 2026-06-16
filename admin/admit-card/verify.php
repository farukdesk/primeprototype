<?php
/**
 * Admit Card – Public QR Verification Page
 * Accessed when scanning the QR code on an admit card.
 * No authentication required – this is publicly accessible.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$token = trim($_GET['t'] ?? '');

// ── Lookup token ──────────────────────────────────────────────────────────────
$data = null;
if ($token !== '' && ctype_xdigit($token) && strlen($token) === 64) {
    try {
        $stmt = db()->prepare(
            'SELECT tok.admit_card_id, tok.student_id, tok.created_at AS token_created,
                    ac.exam_name, ac.semester, ac.is_active,
                    d.name AS dept_name,
                    p.program_name,
                    s.full_name AS student_name,
                    s.student_id AS student_sid,
                    pkg.id AS pkg_id
             FROM ac_student_tokens tok
             JOIN ac_admit_cards ac ON ac.id = tok.admit_card_id
             JOIN dept_departments d ON d.id = ac.dept_id
             JOIN dept_academic_programs p ON p.id = ac.program_id
             JOIN students s ON s.id = tok.student_id
             LEFT JOIN sfp_packages pkg ON pkg.student_id = tok.student_id
             WHERE tok.token = ?
             ORDER BY pkg.id DESC
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $data = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        // DB not available – show generic error
    }
}

// Compute total dues if we have a package
$total_due = null;
if ($data && $data['pkg_id']) {
    try {
        require_once __DIR__ . '/../accounting/helpers.php';
        $total_due = acc_total_outstanding((int)$data['pkg_id']);
    } catch (Throwable $e) {
        // Silently ignore
    }
}

$valid = $data && (int)$data['is_active'] === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admit Card Verification – Prime University</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body { background: #f0f4f8; font-family: 'Segoe UI', Arial, sans-serif; }
  .verify-card { max-width: 520px; margin: 40px auto; }
  .status-badge { font-size: 1.4rem; font-weight: 700; }
</style>
</head>
<body>

<div class="verify-card">
    <div class="text-center mb-4">
        <img src="<?= rtrim(SITE_URL, '/') ?>/assets/img/logo/logo-black.png"
             alt="Prime University" style="height:60px;" onerror="this.style.display='none'">
        <h5 class="mt-2 fw-bold">Prime University</h5>
        <div class="text-muted small">Admit Card Authentication</div>
    </div>

    <?php if ($token === '' || (!ctype_xdigit($token) || strlen($token) !== 64)): ?>
    <!-- Invalid token format -->
    <div class="card border-danger shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-times-circle fa-3x text-danger mb-3"></i>
            <div class="status-badge text-danger mb-2">Invalid QR Code</div>
            <p class="text-muted">This QR code is not valid. Please ensure you are scanning a genuine Prime University admit card.</p>
        </div>
    </div>

    <?php elseif (!$data): ?>
    <!-- Token not found in DB -->
    <div class="card border-danger shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
            <div class="status-badge text-danger mb-2">Not Recognised</div>
            <p class="text-muted">This admit card token was not found in our system. It may be counterfeit or have been revoked.</p>
        </div>
    </div>

    <?php elseif (!$valid): ?>
    <!-- Token found but card is inactive -->
    <div class="card border-warning shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-ban fa-3x text-warning mb-3"></i>
            <div class="status-badge text-warning mb-2">Admit Card Inactive</div>
            <p class="text-muted">This admit card has been deactivated by the university. Please contact the Controller of Examinations.</p>
            <hr>
            <p class="mb-1"><strong><?= htmlspecialchars($data['student_name']) ?></strong></p>
            <p class="text-muted small mb-0"><?= htmlspecialchars($data['student_sid']) ?> &mdash; <?= htmlspecialchars($data['exam_name']) ?></p>
        </div>
    </div>

    <?php else: ?>
    <!-- Valid and active -->
    <div class="card border-success shadow-sm">
        <div class="card-body py-4 px-4">
            <div class="text-center mb-4">
                <i class="fas fa-check-circle fa-3x text-success mb-2"></i>
                <div class="status-badge text-success">Genuine Admit Card</div>
                <div class="text-muted small mt-1">This admit card is authentic and was issued by Prime University.</div>
            </div>

            <hr>

            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <th class="text-muted fw-normal" style="width:40%">Student Name</th>
                        <td class="fw-bold"><?= htmlspecialchars($data['student_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Student ID</th>
                        <td class="fw-bold"><?= htmlspecialchars($data['student_sid']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Department</th>
                        <td><?= htmlspecialchars($data['dept_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Program</th>
                        <td><?= htmlspecialchars($data['program_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Exam</th>
                        <td><?= htmlspecialchars($data['exam_name']) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Semester</th>
                        <td><?= htmlspecialchars($data['semester']) ?></td>
                    </tr>
                    <?php if ($total_due !== null): ?>
                    <tr>
                        <th class="text-muted fw-normal">Total Dues</th>
                        <td>
                            <?php if ($total_due > 0): ?>
                                <span class="text-danger fw-semibold">৳<?= number_format($total_due, 2) ?></span>
                            <?php else: ?>
                                <span class="text-success fw-semibold">No outstanding dues</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th class="text-muted fw-normal">Verified at</th>
                        <td><?= date('d M Y, H:i') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-success-subtle text-success-emphasis text-center small py-2">
            <i class="fas fa-shield-alt me-1"></i>
            Verified by Prime University Digital Admission System
        </div>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted small mt-4">
        &copy; <?= date('Y') ?> Prime University &mdash; primeuniversity.ac.bd
    </div>
</div>

</body>
</html>
