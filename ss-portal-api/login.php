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

$app = (string)ssp_config('app_name', 'SS Student Portal');
ssp_header('Sign in');
?>
<div class="auth-card">
  <div class="auth-brand">
    <span class="brand-mark"><?= ssp_icon('graduation-cap') ?></span>
    <div>
      <h1><?= e($app) ?></h1>
      <p>Register students at Prime University and keep your own records in one place.</p>
    </div>
    <ul class="auth-points">
      <li><?= ssp_icon('check-circle') ?><span>Save drafts, send students and publish final results</span></li>
      <li><?= ssp_icon('clock') ?><span>Track status, retries and every API call</span></li>
      <li><?= ssp_icon('shield') ?><span>Your records and documents stay on your own server</span></li>
    </ul>
  </div>
  <div class="auth-form">
    <h2>Sign in</h2>
    <p class="muted">Use your portal account.</p>
    <?php if ($error !== ''): ?><?= ssp_alert('error', $error) ?><?php endif; ?>
    <form method="post" action="<?= e(ssp_url('login.php')) ?>" autocomplete="on">
      <?= ssp_csrf_field() ?>
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="input-group">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="btn btn-icon" data-toggle-password="password" title="Show password" aria-label="Show password" aria-pressed="false"><?= ssp_icon('eye') ?></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="muted small" style="margin-top:16px">Accounts are created by an administrator with <code>bin/create-user.php</code>.</p>
  </div>
</div>
<?php ssp_footer();
