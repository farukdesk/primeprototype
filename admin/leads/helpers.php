<?php
/**
 * Lead Management – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── Permission helpers ────────────────────────────────────────────────────────

function leads_is_staff(): bool
{
    return is_super_admin() || can_access('leads', 'can_edit');
}

function leads_can_create(): bool
{
    return is_super_admin() || can_access('leads', 'can_create');
}

function leads_can_delete(): bool
{
    return is_super_admin() || can_access('leads', 'can_delete');
}

// ── Lead number generator ─────────────────────────────────────────────────────

function leads_generate_number(): string
{
    $year = date('Y');
    $pfx  = 'LD-' . $year . '-';
    $stmt = db()->prepare(
        "SELECT lead_number FROM leads WHERE lead_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── Semester list ─────────────────────────────────────────────────────────────

function leads_semester_list(): array
{
    $list    = [];
    $curYear = (int)date('Y');
    for ($y = $curYear; $y <= $curYear + 3; $y++) {
        $list[] = 'Summer ' . $y;
        $list[] = 'Fall '   . $y;
        $list[] = 'Spring ' . $y;
    }
    return $list;
}

// ── Call-time options ─────────────────────────────────────────────────────────

function leads_call_time_options(): array
{
    return [
        'Morning (9 AM – 12 PM)',
        'Afternoon (12 PM – 3 PM)',
        'Evening (3 PM – 6 PM)',
    ];
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

function leads_status_badge(string $status): string
{
    $map = [
        'fresh'            => ['bg-success',   'Fresh'],
        'unable_to_reach'  => ['bg-warning text-dark', 'Unable to Reach'],
        'converted'        => ['bg-primary',   'Converted'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

function leads_source_badge(string $source): string
{
    if ($source === 'facebook') {
        return '<span class="badge" style="background:#1877F2"><i class="fab fa-facebook-messenger me-1"></i>Facebook</span>';
    }
    $map = [
        'online'         => ['bg-info text-dark',  'Online'],
        'campus_visit'   => ['bg-secondary',        'Campus Visit'],
        'agent'          => ['bg-dark',             'Agent'],
        'f2f_marketing'  => ['bg-warning text-dark','F2F Marketing'],
    ];
    [$cls, $label] = $map[$source] ?? ['bg-secondary', ucfirst(str_replace('_', ' ', $source))];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

function leads_degree_badge(string $type): string
{
    return $type === 'master'
        ? '<span class="badge bg-purple" style="background:#6f42c1">Master</span>'
        : '<span class="badge bg-teal" style="background:#20c997">Bachelor</span>';
}

function leads_appt_status_badge(string $status): string
{
    $map = [
        'scheduled'  => ['bg-primary',   'Scheduled'],
        'completed'  => ['bg-success',   'Completed'],
        'cancelled'  => ['bg-danger',    'Cancelled'],
        'no_show'    => ['bg-warning text-dark', 'No Show'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
}

function leads_source_label(string $source): string
{
    $map = [
        'online'        => 'Online',
        'campus_visit'  => 'Campus Visit',
        'agent'         => 'Agent',
        'f2f_marketing' => 'F2F Marketing',
        'facebook'      => 'Facebook',
    ];
    return $map[$source] ?? ucfirst(str_replace('_', ' ', $source));
}

function leads_status_label(string $status): string
{
    $map = [
        'fresh'           => 'Fresh',
        'unable_to_reach' => 'Unable to Reach',
        'converted'       => 'Converted',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

// ── History logger ────────────────────────────────────────────────────────────

function leads_log(
    int     $lead_id,
    string  $action,
    ?string $field_name  = null,
    mixed   $old_value   = null,
    mixed   $new_value   = null,
    ?string $description = null
): void {
    $user = auth_user();
    db()->prepare(
        'INSERT INTO lead_history (lead_id, user_id, action, field_name, old_value, new_value, description)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $lead_id,
        $user ? $user['id'] : null,
        $action,
        $field_name,
        $old_value !== null ? (string)$old_value : null,
        $new_value !== null ? (string)$new_value : null,
        $description,
    ]);
}

// ── Facebook Messenger helpers ────────────────────────────────────────────────

function leads_fb_setting(string $key): string
{
    try {
        $stmt = db()->prepare('SELECT `value` FROM lead_fb_settings WHERE `key` = ?');
        $stmt->execute([$key]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}

function leads_fb_setting_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO lead_fb_settings (`key`, `value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=?, updated_at=NOW()'
    )->execute([$key, $value, $value]);
}

/**
 * Send a text message to a Facebook user via the Send API.
 * Returns true on success, false on failure.
 */
function leads_fb_send(string $psid, string $text): bool
{
    $token = leads_fb_setting('page_access_token');
    if ($token === '') return false;

    $payload = json_encode([
        'recipient'      => ['id' => $psid],
        'message'        => ['text' => $text],
        'messaging_type' => 'RESPONSE',
    ]);

    $ch = curl_init('https://graph.facebook.com/v19.0/me/messages?access_token=' . urlencode($token));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $body = json_decode($resp, true);
        return !empty($body['message_id']);
    }
    return false;
}

function leads_fb_get_contact_by_psid(string $psid): ?array
{
    $stmt = db()->prepare('SELECT * FROM lead_fb_contacts WHERE psid = ?');
    $stmt->execute([$psid]);
    return $stmt->fetch() ?: null;
}

function leads_fb_get_contact_by_lead(int $lead_id): ?array
{
    $stmt = db()->prepare('SELECT * FROM lead_fb_contacts WHERE lead_id = ?');
    $stmt->execute([$lead_id]);
    return $stmt->fetch() ?: null;
}

function leads_fb_source_badge(): string
{
    return '<span class="badge" style="background:#1877F2"><i class="fab fa-facebook-messenger me-1"></i>Facebook</span>';
}

// ── Fetch helpers ─────────────────────────────────────────────────────────────

function leads_get(int $id): array
{
    $stmt = db()->prepare(
        'SELECT l.*,
                d.name         AS dept_name,
                p.program_name,
                u.full_name    AS created_by_name,
                a.full_name    AS assigned_to_name
         FROM leads l
         LEFT JOIN dept_departments d     ON d.id = l.dept_id
         LEFT JOIN dept_academic_programs p ON p.id = l.program_id
         LEFT JOIN users u                ON u.id = l.created_by
         LEFT JOIN users a                ON a.id = l.assigned_to
         WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) {
        flash_set('error', 'Lead not found.');
        redirect(APP_URL . '/leads/index.php');
    }
    return $lead;
}
