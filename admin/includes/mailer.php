<?php
/**
 * Mailer Helper
 * Sends an email using a stored email_template identified by its action slug.
 * Variables in the template (e.g. {{full_name}}) are replaced by the $vars array.
 */

/**
 * Send an email using a named template.
 *
 * @param  string $action   Template action slug (e.g. 'forgot_password')
 * @param  string $to_email Recipient email address
 * @param  string $to_name  Recipient display name
 * @param  array  $vars     Associative array: ['full_name' => '...', 'reset_link' => '...']
 * @return bool             True on success, false on failure or if template not found/inactive
 */
function send_template_email(string $action, string $to_email, string $to_name, array $vars = []): bool
{
    $stmt = db()->prepare(
        'SELECT subject, body_html FROM email_templates WHERE action = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$action]);
    $tpl = $stmt->fetch();

    if (!$tpl) {
        return false;
    }

    // Add built-in variables
    $vars['app_name'] = APP_NAME;

    // Replace {{variable}} placeholders in subject and body
    $search  = [];
    $replace = [];
    foreach ($vars as $key => $val) {
        $search[]  = '{{' . $key . '}}';
        $replace[] = $val;
    }

    $subject  = str_replace($search, $replace, $tpl['subject']);
    $body_html = str_replace($search, $replace, $tpl['body_html']);

    // Build RFC 2822 headers
    $from_name  = APP_NAME;
    $from_email = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // Encode display names as RFC 2047 Base64 UTF-8
    $encoded_from = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $encoded_to   = '=?UTF-8?B?' . base64_encode($to_name)   . '?=';

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $encoded_from . ' <' . $from_email . '>' . "\r\n";
    $headers .= 'To: '   . $encoded_to   . ' <' . $to_email   . '>' . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION;

    return mail($to_email, $subject, $body_html, $headers);
}
