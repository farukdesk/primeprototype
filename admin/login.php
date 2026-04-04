<?php
require_once __DIR__ . '/includes/auth.php';

// Already logged in?
if (!empty($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $login    = trim($_POST['login']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $error = 'Please enter your username/email and password.';
    } else {
        $stmt = db()->prepare(
            'SELECT u.*, g.name AS group_name, g.is_super
             FROM users u
             JOIN user_groups g ON g.id = u.group_id
             WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['group_id']  = $user['group_id'];
            $_SESSION['is_super']  = $user['is_super'];

            // Update last login timestamp
            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
               ->execute([$user['id']]);

            // Redirect non-dashboard users to their primary area
            if (!$user['is_super'] && !can_access('dashboard')) {
                if (can_access('faculty-profile')) {
                    redirect(APP_URL . '/faculty-profiles/my-profile.php');
                }
            }

            redirect(APP_URL . '/index.php');
        } else {
            // Prevent username enumeration by using a generic message
            $error = 'Invalid credentials. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?= h(APP_NAME) ?></title>
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
        .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-logo img {
            max-height: 72px;
            max-width: 200px;
            object-fit: contain;
            margin-bottom: 12px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
        .login-logo h1 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a1f36;
            margin: 0;
        }
        .login-logo p {
            font-size: .8rem;
            color: #888;
            margin: 4px 0 0;
        }
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
        .btn-login {
            background: linear-gradient(135deg,#4f8ef7,#2d63e8);
            border: none;
            border-radius: 10px;
            padding: .7rem;
            font-size: .9rem;
            font-weight: 600;
            letter-spacing: .02em;
            color: #fff;
            width: 100%;
            transition: opacity .2s, transform .15s;
        }
        .btn-login:hover { opacity: .9; transform: translateY(-1px); color: #fff; }
        .alert { border: none; border-radius: 10px; font-size: .85rem; }
        .toggle-pw { cursor: pointer; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <img src="<?= LOGO_URL ?>" alt="Prime University">
        <p>Prime University Management System (PUMIS)</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <i class="fas fa-exclamation-circle"></i> <?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <?= csrf_field() ?>

        <div class="mb-3">
            <label for="login" class="form-label">Username or Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input type="text" id="login" name="login" class="form-control"
                       placeholder="Enter username or email"
                       value="<?= old('login') ?>" required autocomplete="username">
            </div>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label for="password" class="form-label mb-0">Password</label>
                <a href="<?= APP_URL ?>/forgot-password.php" style="font-size:.8rem;color:#4f8ef7;text-decoration:none;">
                    Forgot password?
                </a>
            </div>
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input type="password" id="password" name="password" class="form-control"
                       placeholder="Enter password" required autocomplete="current-password">
                <span class="input-group-text toggle-pw" onclick="togglePw()">
                    <i class="fas fa-eye" id="pw-eye"></i>
                </span>
            </div>
        </div>

        <button type="submit" class="btn btn-login">
            <i class="fas fa-sign-in-alt me-2"></i>Sign In
        </button>
    </form>

    <p class="text-center mt-4 mb-0" style="font-size:.75rem;color:#bbb;">
        &copy; <?= date('Y') ?> Prime University &mdash; Secure Admin Area
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePw() {
    var f = document.getElementById('password');
    var e = document.getElementById('pw-eye');
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
