<?php
/**
 * Student Portal API – POST /api/student/support-ticket-create.php
 * ==================================================================
 * Creates a new IT support ticket for the signed-in student.
 *
 * POST fields:
 *   title       (required, max 500 chars)
 *   description (required, plain text)
 *   category    (optional – Hardware | Software | Network | Email |
 *                Student Finances | Other Student Issues | Other)
 *
 * Matches the web portal rules for student-created tickets: priority is
 * fixed to Medium, the deadline follows the SLA, and the student's
 * identity (ID, department, program, batch + section) is taken from the
 * linked students profile.
 */

require_once __DIR__ . '/includes/auth_student_api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sp_api_error(405, 'Method Not Allowed. Use POST.');
}

$ctx     = sp_api_auth();
$user    = $ctx['user'];
$student = $ctx['student'];
$user_id = (int)$user['user_id'];

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$category    = (string)($_POST['category'] ?? 'Other');

$valid_cats = ['Hardware','Software','Network','Email','Student Finances','Other Student Issues','Other'];
if (!in_array($category, $valid_cats, true)) $category = 'Other';

if ($title === '')            sp_api_error(422, 'Title is required.');
if (mb_strlen($title) > 500)  sp_api_error(422, 'Title must be 500 characters or less.');
if ($description === '')      sp_api_error(422, 'Description is required.');

$priority = 'Medium'; // students cannot choose a priority

try {
    $db = db();

    // ── SLA-based deadline ────────────────────────────────────────────────
    $sla = $db->prepare('SELECT hours FROM support_sla_rules WHERE priority = ?');
    $sla->execute([$priority]);
    $sla_row  = $sla->fetch();
    $hours    = $sla_row ? (int)$sla_row['hours'] : 72;
    $deadline_dt = date('Y-m-d H:i:s', strtotime('+' . $hours . ' hours'));

    // ── Ticket number (TKT-YYYY-0001, same pattern as the web portal) ─────
    $prefix    = 'TKT-' . date('Y') . '-';
    $last_stmt = $db->prepare(
        'SELECT ticket_number FROM support_tickets WHERE ticket_number LIKE ? ORDER BY id DESC LIMIT 1'
    );
    $last_stmt->execute([$prefix . '%']);
    $last = $last_stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    $ticket_number = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    // ── Student identity from the linked profile (Section appended to Batch) ─
    $sec_stmt = $db->prepare('SELECT section FROM students WHERE id = ? LIMIT 1');
    $sec_stmt->execute([(int)$student['student_db_id']]);
    $section     = trim((string)($sec_stmt->fetchColumn() ?: ''));
    $batch_label = (string)($student['batch_name'] ?? '');
    if ($section !== '') {
        $batch_label .= ($batch_label !== '' ? ' – ' : '') . 'Section ' . $section;
    }

    // Store the plain-text description safely as HTML (support ticket
    // descriptions are rendered as HTML in the admin panel).
    $description_html = nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    $db->prepare(
        'INSERT INTO support_tickets
           (ticket_number, title, description, category, priority, status, department, deadline, created_by,
            user_type, student_id, student_department, student_program, student_batch)
         VALUES (?,?,?,?,?,\'Open\',?,?,?,?,?,?,?,?)'
    )->execute([
        $ticket_number, $title, $description_html, $category,
        $priority,
        ($student['dept_name'] ?? null) ?: null,
        $deadline_dt, $user_id,
        'Student',
        ($student['student_id'] ?? null) ?: null,
        ($student['dept_name'] ?? null) ?: null,
        ($student['program_name'] ?? null) ?: null,
        $batch_label !== '' ? $batch_label : null,
    ]);
    $ticket_id = (int)$db->lastInsertId();

    // ── Attachments (optional multipart field "attachments[]") ────────────
    // Same rules as the web portal: 10 MB max per file, safe-listed types.
    $allowed_exts  = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','zip','txt'];
    $allowed_mimes = [
        'image/jpeg','image/png','image/gif','image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip','application/x-zip-compressed',
        'text/plain',
    ];
    if (!empty($_FILES['attachments']['name'][0])) {
        $dir = UPLOAD_DIR . '/support-tickets';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ((int)$_FILES['attachments']['size'][$i] > 10 * 1024 * 1024) continue;
            $orig = (string)$_FILES['attachments']['name'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) continue;
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = (string)$finfo->file($tmp);
            if (!in_array($mime, $allowed_mimes, true)) continue;
            $stored = bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dir . '/' . $stored)) continue;
            $db->prepare(
                'INSERT INTO support_ticket_attachments
                   (ticket_id, original_name, stored_name, mime_type, file_size, uploaded_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$ticket_id, $orig, $stored, $mime, (int)$_FILES['attachments']['size'][$i], $user_id]);
        }
    }

    // ── Best-effort notifications (never block ticket creation) ───────────

    // Push notification to IT staff
    try {
        require_once dirname(__DIR__) . '/includes/fcm.php';
        $staff_ids = $db->query(
            "SELECT DISTINCT u.id FROM users u
             JOIN user_groups g ON g.id = u.group_id
             WHERE u.is_active = 1 AND (g.is_super = 1
                   OR u.id IN (
                       SELECT uma.user_id FROM user_module_access uma
                       JOIN modules m ON m.id = uma.module_id
                       WHERE m.slug = 'support-tickets' AND uma.can_view = 1
                   )
                   OR g.id IN (
                       SELECT gma.group_id FROM group_module_access gma
                       JOIN modules m ON m.id = gma.module_id
                       WHERE m.slug = 'support-tickets' AND gma.can_view = 1
                   )
             )"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($staff_ids) && function_exists('send_push_notification')) {
            send_push_notification(
                array_map('intval', $staff_ids),
                'New Support Ticket',
                '[' . $priority . '] ' . $title,
                ['type' => 'support_ticket', 'ticket_id' => (string)$ticket_id, 'ticket_number' => $ticket_number]
            );
        }
    } catch (Throwable $e) {
        error_log('support-ticket-create.php push failed: ' . $e->getMessage());
    }

    // Email confirmation to the student + configured IT admin addresses
    try {
        if (!function_exists('h')) {
            function h(mixed $val): string {
                return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        require_once dirname(__DIR__, 2) . '/includes/mailer.php';
        if (function_exists('send_template_email')) {
            $deadline_label = date('M d, Y H:i', strtotime($deadline_dt));
            $ticket_url     = (defined('APP_URL') ? APP_URL : '') . '/support-tickets/view.php?id=' . $ticket_id;
            $vars = [
                'full_name'       => $user['full_name'],
                'ticket_number'   => $ticket_number,
                'ticket_title'    => $title,
                'ticket_priority' => $priority,
                'ticket_category' => $category,
                'ticket_deadline' => $deadline_label,
                'ticket_url'      => $ticket_url,
            ];
            $creator_email = (string)($user['email'] ?? '');
            if ($creator_email !== '') {
                send_template_email('ticket_created', $creator_email, $user['full_name'], $vars);
            }
            $ns = $db->prepare('SELECT `value` FROM support_settings WHERE `key` = ? LIMIT 1');
            $ns->execute(['notify_emails']);
            $raw = (string)($ns->fetchColumn() ?: '');
            $notify_vars = array_merge($vars, [
                'submitter_name'  => $user['full_name'],
                'submitter_email' => $creator_email,
                'user_type'       => 'Student',
            ]);
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $admin_email) {
                if ($admin_email === $creator_email) continue;
                send_template_email('ticket_created_notify', $admin_email, 'IT Support Team', $notify_vars);
            }
        }
    } catch (Throwable $e) {
        error_log('support-ticket-create.php email failed: ' . $e->getMessage());
    }

    sp_api_ok([
        'message' => 'Ticket ' . $ticket_number . ' created successfully.',
        'ticket'  => [
            'id'            => $ticket_id,
            'ticket_number' => $ticket_number,
            'title'         => $title,
            'description'   => $description_html,
            'category'      => $category,
            'priority'      => $priority,
            'status'        => 'Open',
            'deadline'      => date('M d, Y H:i', strtotime($deadline_dt)),
            'date'          => date('M d, Y H:i'),
        ],
    ]);
} catch (Throwable $e) {
    error_log('support-ticket-create.php error: ' . $e->getMessage());
    sp_api_error(500, 'Failed to create the support ticket. Please try again.');
}
