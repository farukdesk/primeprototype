<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

// Already logged in?
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

define('RESET_EXPIRE_MINUTES', 30);

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Look up the user (always show generic success to prevent enumeration)
        $stmt = db()->prepare(
            'SELECT id, full_name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Delete any existing token for this email
            db()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

            // Generate a secure token
            $token = bin2hex(random_bytes(32));

            db()->prepare(
                'INSERT INTO password_resets (email, token) VALUES (?,?)'
            )->execute([$email, $token]);

            $reset_link = APP_URL . '/reset-password.php?token=' . urlencode($token);

            send_template_email('forgot_password', $user['email'], $user['full_name'], [
                'full_name'      => $user['full_name'],
                'reset_link'     => $reset_link,
                'expire_minutes' => RESET_EXPIRE_MINUTES,
            ]);
        }

        // Always show success to prevent user enumeration
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= h(APP_NAME) ?></title>
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
        <div>
            If that email address is registered, a password reset link has been sent.
            Please check your inbox (and spam folder).
        </div>
    </div>
    <div class="back-link">
        <a href="<?= APP_URL ?>/login.php"><i class="fas fa-arrow-left me-1"></i>Back to Login</a>
    </div>
    <?php else: ?>

    <h5 class="fw-semibold mb-1" style="color:#1a1f36;">Forgot Password?</h5>
    <p class="text-muted mb-4" style="font-size:.85rem;">
        Enter your registered email address and we'll send you a link to reset your password.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <i class="fas fa-exclamation-circle"></i> <?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <?= csrf_field() ?>

        <div class="mb-4">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input type="email" id="email" name="email" class="form-control"
                       placeholder="Enter your email address"
                       value="<?= h($_POST['email'] ?? '') ?>" required autocomplete="email">
            </div>
        </div>

        <button type="submit" class="btn btn-submit">
            <i class="fas fa-paper-plane me-2"></i>Send Reset Link
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
</body>
</html>
