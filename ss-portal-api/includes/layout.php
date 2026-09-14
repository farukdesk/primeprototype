<?php
/**
 * SS Portal – page chrome (app shell, alerts, icons) and the form-field
 * helpers shared by all pages.
 */

require_once __DIR__ . '/auth.php';

/** Inline SVG icon (outline style). Unknown names render nothing. */
function ssp_icon(string $name, string $class = ''): string
{
    static $icons = [
        'dashboard'      => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'user-plus'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'plug'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'log-out'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'sun'            => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon'           => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'check'          => '<path d="M20 6 9 17l-5-5"/>',
        'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info'           => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'x-circle'       => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'upload'         => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'file'           => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'arrow-left'     => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'external'       => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'send'           => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'pencil'         => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'trash'          => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'award'          => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
        'eye'            => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'refresh'        => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'plus'           => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'graduation-cap' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'chevron-left'   => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
        'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'clock'          => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'shield'         => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    ];
    if (!isset($icons[$name])) {
        return '';
    }
    return '<svg class="icon' . ($class !== '' ? ' ' . e($class) : '') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
}

/** Up to two initials for avatar placeholders. */
function ssp_initials(string $name): string
{
    $ini = '';
    foreach (preg_split('/\s+/u', trim($name)) ?: [] as $part) {
        if ($part !== '') {
            $ini .= mb_substr($part, 0, 1);
        }
        if (mb_strlen($ini) >= 2) {
            break;
        }
    }
    return mb_strtoupper($ini !== '' ? $ini : '?');
}

/** Main-nav entry to highlight for the current script. */
function ssp_active_nav(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (substr($script, -11) === '/status.php') {
        return 'connection';
    }
    if (substr($script, -20) === '/students/create.php' && empty($_GET['id'])) {
        return 'new';
    }
    return 'students';
}

/** Remembers whether the current page uses the signed-in app shell (for ssp_footer). */
function ssp_shell(?bool $set = null): bool
{
    static $shell = false;
    if ($set !== null) {
        $shell = $set;
    }
    return $shell;
}

/** One alert box. $html is escaped unless $escape is false (then pass pre-escaped HTML). */
function ssp_alert(string $type, string $html, bool $escape = true): string
{
    $icons = ['success' => 'check-circle', 'warning' => 'alert-triangle', 'error' => 'x-circle', 'info' => 'info'];
    $role  = ($type === 'error' || $type === 'warning') ? 'alert' : 'status';
    return '<div class="alert alert-' . e($type) . '" role="' . $role . '">' . ssp_icon($icons[$type] ?? 'info', 'alert-icon')
        . '<div class="alert-body">' . ($escape ? e($html) : $html) . '</div>'
        . '<button type="button" class="alert-close" aria-label="Dismiss">' . ssp_icon('x') . '</button></div>';
}

function ssp_header(string $title, ?array $user = null, array $opts = []): void
{
    $app     = (string)ssp_config('app_name', 'SS Student Portal');
    $flashes = ssp_flash_pull();
    $active  = (string)($opts['nav'] ?? ssp_active_nav());
    ssp_shell($user !== null);
    $navItem = static function (string $key, string $href, string $icon, string $label) use ($active): void {
        $on = $active === $key;
        echo '<a href="', e(ssp_url($href)), '"', $on ? ' class="active" aria-current="page"' : '', '>', ssp_icon($icon), '<span>', e($label), '</span></a>';
    };
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="stylesheet" href="<?= e(ssp_url('assets/portal.css')) ?>?v=<?= e(SSP_VERSION) ?>">
<script>try{var t=localStorage.getItem('ssp_theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
</head>
<body>
<a class="skip-link" href="#content">Skip to content</a>
<?php if ($user !== null): ?>
<div class="app">
  <aside class="sidebar">
    <a class="brand" href="<?= e(ssp_url('dashboard.php')) ?>"><span class="brand-mark"><?= ssp_icon('graduation-cap') ?></span><span class="brand-name"><?= e($app) ?></span></a>
    <nav class="sidenav" aria-label="Main navigation">
      <?php $navItem('students', 'dashboard.php', 'dashboard', 'Students'); ?>
      <?php $navItem('new', 'students/create.php', 'user-plus', 'New student'); ?>
      <?php $navItem('connection', 'status.php', 'plug', 'Connection'); ?>
    </nav>
    <div class="sidebar-foot">
      <div class="user-chip" title="<?= e($user['username']) ?>">
        <span class="avatar avatar-sm"><?= e(ssp_initials((string)$user['full_name'])) ?></span>
        <span class="user-meta"><strong><?= e($user['full_name']) ?></strong><small><?= e(ucfirst((string)$user['role'])) ?></small></span>
      </div>
      <div class="sidebar-actions">
        <button type="button" class="btn btn-ghost btn-icon" id="theme-toggle" title="Switch light / dark theme" aria-label="Switch light / dark theme"><?= ssp_icon('sun', 'icon-sun') ?><?= ssp_icon('moon', 'icon-moon') ?></button>
        <form method="post" action="<?= e(ssp_url('logout.php')) ?>"><?= ssp_csrf_field() ?><button type="submit" class="btn btn-ghost btn-icon" title="Sign out" aria-label="Sign out"><?= ssp_icon('log-out') ?></button></form>
      </div>
    </div>
  </aside>
  <div class="main">
  <main class="content" id="content">
<?php else: ?>
<main class="auth" id="content">
<?php endif; ?>
<?php if ($flashes): ?>
  <div class="alerts">
  <?php foreach ($flashes as $f) { echo ssp_alert((string)$f['type'], (string)$f['message']); } ?>
  </div>
<?php endif;
}

function ssp_footer(): void
{
    if (ssp_shell()) {
        ?>  </main>
  <footer class="footer">SS Portal v<?= e(SSP_VERSION) ?> · Students are registered at Prime University through the Student API v1.</footer>
  </div>
</div>
<?php
    } else {
        ?></main>
<?php
    }
    ?><script src="<?= e(ssp_url('assets/portal.js')) ?>?v=<?= e(SSP_VERSION) ?>"></script>
</body>
</html><?php
}

function ssp_badge(string $status): string
{
    $labels = ['draft' => 'Draft', 'pending' => 'Sending…', 'synced' => 'Registered at PU', 'failed' => 'Failed', 'deleted' => 'Deleted', 'update_pending' => 'Update pending'];
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
 * autocomplete, pattern, accept, rows, hint, required (asterisk only), wide,
 * multiple, attrs (extra attribute => value).
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

    $attrs = ' id="' . e($id) . '" name="' . e(ssp_field_name($dot)) . (!empty($o['multiple']) ? '[]' : '') . '"';
    foreach (['placeholder', 'maxlength', 'min', 'max', 'step', 'list', 'autocomplete', 'pattern', 'accept'] as $a) {
        if (isset($o[$a]) && $o[$a] !== '') {
            $attrs .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }
    foreach ((array)($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . e($k) . '="' . e($v) . '"';
    }
    if (!empty($o['multiple'])) {
        $attrs .= ' multiple';
    }
    if ($err !== '') {
        $attrs .= ' aria-invalid="true" aria-describedby="' . e($id) . '_err"';
    }

    echo '<div class="field', $err !== '' ? ' has-error' : '', !empty($o['wide']) ? ' field-wide' : '', '">';
    echo '<label for="', e($id), '">', e($label), !empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '', '</label>';
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
        echo '<small class="error" id="', e($id), '_err">', e($err), '</small>';
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

    echo '<div class="field', $err !== '' ? ' has-error' : '', !empty($o['wide']) ? ' field-wide' : '', '">';
    echo '<label for="', e($id), '">', e($label), !empty($o['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '', '</label>';
    echo '<select id="', e($id), '" name="', e(ssp_field_name($dot)), '"', $err !== '' ? ' aria-invalid="true"' : '';
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

/** Top-of-form summary of validation problems; each entry links to its field. */
function ssp_form_errors_summary(array $errors): void
{
    if (!$errors) {
        return;
    }
    $fieldErrors = array_diff_key($errors, ['_form' => 1]);
    echo '<div class="alert alert-error" role="alert">', ssp_icon('x-circle', 'alert-icon'), '<div class="alert-body">';
    echo '<strong>', e(!empty($errors['_form']) ? $errors['_form'] : 'Please correct the highlighted fields.'), '</strong>';
    if ($fieldErrors) {
        echo '<ul class="error-list">';
        foreach ($fieldErrors as $key => $msg) {
            echo '<li><a href="#', e(ssp_field_id((string)$key)), '">', e($key), '</a> – ', e(is_scalar($msg) ? $msg : json_encode($msg)), '</li>';
        }
        echo '</ul>';
    }
    echo '</div></div>';
}

function ssp_date_human(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('d M Y, H:i', $ts) : $dt;
}
