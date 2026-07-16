<?php
/**
 * Employee Profile – download the complete profile as a CV in PDF format.
 */
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/sp-helpers.php';
require_once dirname(__DIR__) . '/../vendor/autoload.php';

$id = (int)($_GET['id'] ?? 0);

// Admins may download any employee's CV; other staff may only download their own.
$is_admin = is_super_admin() || sp_is_admin();
if (!$is_admin) {
    if ($id !== (int)auth_user()['id'] || !can_access('staff-profile', 'can_view')) {
        require_access('staff-profile', 'can_view');
    }
}

$data = $id > 0 ? sp_load_full_profile($id) : null;
if (!$data || empty($data['staff']) || empty($data['staff']['department_type'])) {
    http_response_code(404);
    die('Employee profile not found.');
}

$body      = sp_render_cv_html($data, true);
$full_name = $data['user']['full_name'] ?? 'employee';

// University logo (embedded so it renders with remote loading disabled).
$logo_uri  = '';
$logo_file = dirname(dirname(__DIR__)) . '/assets/img/logo/logo-black.png';
if (is_file($logo_file) && is_readable($logo_file)) {
    $bytes = file_get_contents($logo_file);
    if ($bytes !== false) {
        $logo_uri = 'data:image/png;base64,' . base64_encode($bytes);
    }
}
$logo_html = $logo_uri !== ''
    ? '<img src="' . h($logo_uri) . '" alt="" style="height:44px;">'
    : '<div style="font-size:18px;font-weight:bold;color:#1a3e72;">Prime University</div>';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
    . '<style>'
    . '@page { margin: 26px 30px; }'
    . 'body { font-family: DejaVu Sans, sans-serif; color:#111; font-size:12px; }'
    . '</style></head><body>'
    . '<table style="width:100%;border-collapse:collapse;border-bottom:2px solid #1a3e72;padding-bottom:6px;margin-bottom:14px;">'
    . '<tr>'
    . '<td style="vertical-align:middle;">' . $logo_html . '</td>'
    . '<td style="vertical-align:middle;text-align:right;">'
    . '<div style="font-size:16px;font-weight:bold;color:#1a3e72;">Prime University</div>'
    . '<div style="font-size:11px;color:#666;">Employee Profile / Curriculum Vitae</div>'
    . '</td>'
    . '</tr></table>'
    . $body
    . '</body></html>';

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$slug     = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower(trim($full_name)));
$slug     = trim($slug, '-') ?: 'employee';
$filename = 'cv-' . $slug . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
