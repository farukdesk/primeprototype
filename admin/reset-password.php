<?php
require_once __DIR__ . '/includes/auth.php';

// Already logged in?
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

define('RESET_EXPIRE_SECONDS', 30 * 60); // 30 minutes

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$success = false;

// Validate token early
if ($token === '') {
    $error = 'Invalid or missing reset token.';
    $token_valid = false;
} else {
    $stmt = db()->prepare(
        'SELECT pr.*, u.id AS user_id, u.full_name, u.email
         FROM password_resets pr
         JOIN users u ON u.email = pr.email AND u.is_active = 1
         WHERE pr.token = ?
           AND pr.created_at >= DATE_SUB(NOW(), INTERVAL ' . (int)(RESET_EXPIRE_SECONDS / 60) . ' MINUTE)
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if (!$reset) {
        // Clean up any expired token with this value
        db()->prepare('DELETE FROM password_resets WHERE token = ?')->execute([$token]);
        $error       = 'This password reset link is invalid, has already been used, or has expired. Please request a new one.';
        $token_valid = false;
    } else {
        $token_valid = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    csrf_check();

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($password === '') {
        $error = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
           ->execute([$hash, $reset['user_id']]);

        // Remove the used token
        db()->prepare('DELETE FROM password_resets WHERE token = ?')->execute([$token]);

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1a1f36 0%, #2d3561 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            width: 100%;
            max-width: 420px;
            padding: 44px 40px;
        }
        .login-logo { text-align: center; margin-bottom: 28px; }
        .login-logo .logo-circle {
            width: 66px; height: 66px;
            border-radius: 18px;
            background: linear-gradient(135deg,#4f8ef7,#2d63e8);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: #fff;
            margin-bottom: 12px;
            box-shadow: 0 8px 24px rgba(79,142,247,.4);
        }
        .login-logo h1 { font-size: 1.2rem; font-weight: 700; color: #1a1f36; margin: 0; }
        .login-logo p  { font-size: .8rem; color: #888; margin: 4px 0 0; }
        .form-label { font-size: .875rem; font-weight: 500; color: #374151; }
        .form-control {
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            padding: .6rem 1rem;
            font-size: .875rem;
            transition: border-color .2s;
        }
        .form-control:focus {
            border-color: #4f8ef7;
            box-shadow: 0 0 0 3px rgba(79,142,247,.15);
        }
        .input-group-text {
            background: #f9fafb;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px 0 0 10px;
            color: #9ca3af;
        }
        .input-group .form-control { border-radius: 0 10px 10px 0; }
        .btn-submit {
            background: linear-gradient(135deg,#4f8ef7,#2d63e8);
            border: none;
            border-radius: 10px;
            padding: .7rem;
            font-size: .9rem;
            font-weight: 600;
            color: #fff;
            width: 100%;
            transition: opacity .2s, transform .15s;
        }
        .btn-submit:hover { opacity: .9; transform: translateY(-1px); color: #fff; }
        .alert { border: none; border-radius: 10px; font-size: .85rem; }
        .toggle-pw { cursor: pointer; }
        .back-link { text-align: center; margin-top: 18px; font-size: .83rem; }
        .back-link a { color: #4f8ef7; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <div class="logo-circle"><i class="fas fa-graduation-cap"></i></div>
        <h1>Prime University</h1>
        <p>Admin Control Panel</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="fas fa-check-circle mt-1"></i>
        <div><strong>Password updated!</strong> You can now log in with your new password.</div>
    </div>
    <div class="back-link">
        <a href="<?= APP_URL ?>/login.php"><i class="fas fa-sign-in-alt me-1"></i>Go to Login</a>
    </div>

    <?php elseif (!$token_valid): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="fas fa-exclamation-circle mt-1"></i>
        <div><?= h($error) ?></div>
    </div>
    <div class="back-link">
        <a href="<?= APP_URL ?>/forgot-password.php"><i class="fas fa-redo me-1"></i>Request a new link</a>
    </div>

    <?php else: ?>

    <h5 class="fw-semibold mb-1" style="color:#1a1f36;">Set New Password</h5>
    <p class="text-muted mb-4" style="font-size:.85rem;">
        Hi <strong><?= h($reset['full_name']) ?></strong>, choose a strong new password for your account.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <i class="fas fa-exclamation-circle"></i> <?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="mb-3">
            <label for="password" class="form-label">New Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
                <span class="input-group-text toggle-pw" onclick="togglePw('password','eye1')">
                    <i class="fas fa-eye" id="eye1"></i>
                </span>
            </div>
        </div>

        <div class="mb-4">
            <label for="password2" class="form-label">Confirm New Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" id="password2" name="password2" class="form-control"
                       placeholder="Repeat password" required minlength="8" autocomplete="new-password">
                <span class="input-group-text toggle-pw" onclick="togglePw('password2','eye2')">
                    <i class="fas fa-eye" id="eye2"></i>
                </span>
            </div>
        </div>

        <button type="submit" class="btn btn-submit">
            <i class="fas fa-key me-2"></i>Reset Password
        </button>
    </form>

    <div class="back-link">
        <a href="<?= APP_URL ?>/login.php"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
    </div>

    <?php endif; ?>

    <p class="text-center mt-4 mb-0" style="font-size:.75rem;color:#bbb;">
        &copy; <?= date('Y') ?> Prime University &mdash; Secure Admin Area
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw(fieldId, eyeId) {
    var f = document.getElementById(fieldId);
    var e = document.getElementById(eyeId);
    if (f.type === 'password') {
        f.type = 'text';
        e.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        f.type = 'password';
        e.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>
