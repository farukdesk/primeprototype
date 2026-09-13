<?php
/**
 * SS Portal – sign in with a portal account (ssp_users).
 */
require_once __DIR__ . '/includes/layout.php';

if (ssp_current_user() !== null) {
    ssp_redirect('dashboard.php');
}

$error    = '';
$username = '';

if (ssp_is_post()) {
    ssp_csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (($lock = ssp_login_locked()) > 0) {
        $error = 'Too many failed attempts. Try again in ' . (int)ceil($lock / 60) . ' minute(s).';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (ssp_login_attempt($username, $password)) {
        $next = (string)($_SESSION['ssp_after_login'] ?? '');
        unset($_SESSION['ssp_after_login']);
        if ($next !== '' && $next[0] === '/' && strpos($next, '//') !== 0 && strpos($next, 'login.php') === false) {
            header('Location: ' . $next, true, 303);
            exit;
        }
        ssp_redirect('dashboard.php');
    } else {
        $error = ssp_login_locked() > 0
            ? 'Too many failed attempts. Try again in ' . (int)ceil(ssp_login_locked() / 60) . ' minute(s).'
            : 'Invalid username or password.';
    }
}

ssp_header('Sign in');
?>
<div class="card card-narrow">
  <h1>Sign in</h1>
  <p class="muted">Use your portal account. Accounts are created by an administrator with <code>bin/create-user.php</code>.</p>
  <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e(ssp_url('login.php')) ?>" autocomplete="on">
    <?= ssp_csrf_field() ?>
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign in</button>
  </form>
</div>
<?php ssp_footer();
