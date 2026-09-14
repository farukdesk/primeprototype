<?php
/**
 * SS Portal – page chrome and form-field helpers shared by all pages.
 */

require_once __DIR__ . '/auth.php';

function ssp_header(string $title, ?array $user = null): void
{
    $app     = (string)ssp_config('app_name', 'SS Student Portal');
    $flashes = ssp_flash_pull();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="<?= e(ssp_url('assets/portal.css')) ?>?v=<?= e(SSP_VERSION) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(ssp_url($user ? 'dashboard.php' : 'login.php')) ?>"><?= e($app) ?></a>
  <?php if ($user): ?>
  <nav class="mainnav">
    <a href="<?= e(ssp_url('dashboard.php')) ?>">Dashboard</a>
    <a href="<?= e(ssp_url('students/create.php')) ?>">New student</a>
    <a href="<?= e(ssp_url('status.php')) ?>">Connection</a>
  </nav>
  <form class="userbox" method="post" action="<?= e(ssp_url('logout.php')) ?>">
    <?= ssp_csrf_field() ?>
    <span class="username" title="<?= e($user['username']) ?>"><?= e($user['full_name']) ?></span>
    <button type="submit" class="btn btn-ghost btn-sm">Logout</button>
  </form>
  <?php endif; ?>
</header>
<main class="container">
<?php foreach ($flashes as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach;
}

function ssp_footer(): void
{
    ?></main>
<footer class="footer">SS Portal v<?= e(SSP_VERSION) ?> · Students are registered at Prime University through the Student API v1.</footer>
<script src="<?= e(ssp_url('assets/portal.js')) ?>?v=<?= e(SSP_VERSION) ?>"></script>
</body>
</html><?php
}

function ssp_badge(string $status): string
{
    $labels = ['draft' => 'Draft', 'pending' => 'Sending…', 'synced' => 'Registered at PU', 'failed' => 'Failed', 'deleted' => 'Deleted'];
    return '<span class="badge badge-' . e($status) . '">' . e($labels[$status] ?? ucfirst($status)) . '</span>';
}

/** 'guardian.email' → 'guardian[email]', 'academic_qualifications.1.session' → 'academic_qualifications[1][session]' */
function ssp_field_name(string $dot): string
{
    $parts = explode('.', $dot);
    $name  = array_shift($parts);
    foreach ($parts as $p) {
        $name .= '[' . $p . ']';
    }
    return $name;
}

function ssp_field_id(string $dot): string
{
    return 'f_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $dot);
}

function ssp_field_error(array $errors, string $dot): string
{
    return (string)($errors[$dot] ?? '');
}

/**
 * Text-like input.  $o: type, placeholder, maxlength, min, max, step, list,
 * autocomplete, pattern, rows, hint, required (asterisk only), wide.
 */
function ssp_input(string $dot, string $label, array $form, array $errors, array $o = []): void
{
    $type = (string)($o['type'] ?? 'text');
    $val  = ssp_array_get($form, $dot, '');
    if (is_bool($val)) {
        $val = $val ? '1' : '';
    } elseif (!is_scalar($val)) {
        $val = '';
    }
    $err = ssp_field_error($errors, $dot);
    $id  = ssp_field_id($dot);

    $attrs = ' id="' . e($id) . '" name="' . e(ssp_field_name($dot)) . '"';
    foreach (['placeholder', 'maxlength', 'min', 'max', 'step', 'list', 'autocomplete', 'pattern', 'accept'] as $a) {
        if (isset($o[$a]) && $o[$a] !== '') {
            $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }

    echo '<div class="field', $err !== '' ? ' has-error' : '', !empty($o['wide']) ? ' field-wide' : '', '">';
    echo '<label for="', e($id), '">', e($label), !empty($o['required']) ? ' <span class="req">*</span>' : '', '</label>';
    if ($type === 'textarea') {
        echo '<textarea', $attrs, ' rows="', (int)($o['rows'] ?? 2), '">', e($val), '</textarea>';
    } elseif ($type === 'file') {
        echo '<input type="file"', $attrs, '>';
    } else {
        echo '<input type="', e($type), '"', $attrs, ' value="', e($val), '">';
    }
    if (!empty($o['hint'])) {
        echo '<small class="hint">', e($o['hint']), '</small>';
    }
    if ($err !== '') {
        echo '<small class="error">', e($err), '</small>';
    }
    echo '</div>';
}

/**
 * Select box. $options: list of ['value' => ..., 'label' => ..., 'attrs' => [...]].
 * A current value that is not among the options is kept as an extra option.
 */
function ssp_select(string $dot, string $label, array $form, array $errors, array $options, array $o = []): void
{
    $val = ssp_array_get($form, $dot, '');
    $val = is_scalar($val) ? (string)$val : '';
    $err = ssp_field_error($errors, $dot);
    $id  = ssp_field_id($dot);

    echo '<div class="field', $err !== '' ? ' has-error' : '', '">';
    echo '<label for="', e($id), '">', e($label), !empty($o['required']) ? ' <span class="req">*</span>' : '', '</label>';
    echo '<select id="', e($id), '" name="', e(ssp_field_name($dot)), '"';
    foreach ((array)($o['attrs'] ?? []) as $k => $v) {
        echo ' ', e($k), '="', e($v), '"';
    }
    echo '>';
    echo '<option value="">', e($o['placeholder'] ?? '— select —'), '</option>';
    $found = false;
    foreach ($options as $opt) {
        $ov  = (string)$opt['value'];
        $sel = $val !== '' && $ov === $val;
        $found = $found || $sel;
        echo '<option value="', e($ov), '"', $sel ? ' selected' : '';
        foreach ((array)($opt['attrs'] ?? []) as $k => $v) {
            echo ' ', e($k), '="', e($v), '"';
        }
        echo '>', e($opt['label']), '</option>';
    }
    if (!$found && $val !== '') {
        echo '<option value="', e($val), '" selected>', e($val), ' (current value)</option>';
    }
    echo '</select>';
    if (!empty($o['hint'])) {
        echo '<small class="hint">', e($o['hint']), '</small>';
    }
    if ($err !== '') {
        echo '<small class="error">', e($err), '</small>';
    }
    echo '</div>';
}

function ssp_datalist(string $id, array $values): void
{
    echo '<datalist id="', e($id), '">';
    foreach (array_unique(array_filter(array_map('strval', $values), 'strlen')) as $v) {
        echo '<option value="', e($v), '">';
    }
    echo '</datalist>';
}

/** Top-of-form summary of validation problems. */
function ssp_form_errors_summary(array $errors): void
{
    if (!$errors) {
        return;
    }
    $fieldErrors = array_diff_key($errors, ['_form' => 1]);
    echo '<div class="flash flash-error">';
    if (!empty($errors['_form'])) {
        echo '<strong>', e($errors['_form']), '</strong>';
    } else {
        echo '<strong>Please correct the highlighted fields.</strong>';
    }
    if ($fieldErrors) {
        echo '<ul class="error-list">';
        foreach ($fieldErrors as $key => $msg) {
            echo '<li><code>', e($key), '</code> – ', e(is_scalar($msg) ? $msg : json_encode($msg)), '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

function ssp_date_human(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d M Y, H:i', $ts) : $dt;
}
