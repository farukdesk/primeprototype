<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$contact_id = (int)($_GET['contact_id'] ?? 0);
if ($contact_id <= 0) {
    flash_set('error', 'Invalid contact.');
    redirect(APP_URL . '/leads/fb-inbox.php');
}

$cq = db()->prepare(
    'SELECT c.*, l.first_name, l.last_name, l.lead_number, l.id AS linked_lead_id
     FROM lead_fb_contacts c
     LEFT JOIN leads l ON l.id = c.lead_id
     WHERE c.id = ?'
);
$cq->execute([$contact_id]);
$contact = $cq->fetch();

if (!$contact) {
    flash_set('error', 'Facebook contact not found.');
    redirect(APP_URL . '/leads/fb-inbox.php');
}

$user     = auth_user();
$is_staff = leads_is_staff();

// ── Resolve missing profile name / picture on the fly ────────────────────────
if (empty($contact['fb_name'])) {
    [$fb_name, $fb_picture] = leads_fb_fetch_profile($contact['psid']);
    if ($fb_name !== null) {
        db()->prepare('UPDATE lead_fb_contacts SET fb_name=?, fb_picture=COALESCE(?, fb_picture) WHERE id=?')
            ->execute([$fb_name, $fb_picture, $contact_id]);
        $contact['fb_name'] = $fb_name;
        if ($fb_picture) $contact['fb_picture'] = $fb_picture;
    }
}

$display_name = $contact['fb_name'] ?: 'Facebook User #' . substr((string)$contact['psid'], -6);
$page_title   = 'Messenger – ' . $display_name;

// ── JSON shape for a message row ──────────────────────────────────────────
function fbconv_msg_json(array $m): array
{
    return [
        'id'              => (int)$m['id'],
        'direction'       => $m['direction'],
        'text'            => $m['message_text'],
        'attachment_type' => $m['attachment_type'] ?? null,
        'attachment_url'  => $m['attachment_url'] ?? null,
        'status'          => $m['status'] ?? 'sent',
        'seen'            => !empty($m['seen_at']),
        'sender'          => $m['sender_name'] ?? null,
        'time'            => leads_time_ago($m['created_at']),
        'time_full'       => date('d M Y, h:i A', strtotime($m['created_at'])),
    ];
}

// ── AJAX: poll for new messages (also returns seen receipts) ────────────────
if (($_GET['ajax'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    leads_fb_run_followups_throttled();
    $after = (int)($_GET['after_id'] ?? 0);
    $q = db()->prepare(
        'SELECT m.*, u.full_name AS sender_name
         FROM lead_fb_messages m LEFT JOIN users u ON u.id = m.sent_by
         WHERE m.contact_id = ? AND m.id > ?
         ORDER BY m.id ASC'
    );
    $q->execute([$contact_id, $after]);
    $rows = $q->fetchAll();
    try {
        db()->prepare('UPDATE lead_fb_contacts SET last_read_at = NOW() WHERE id = ?')->execute([$contact_id]);
    } catch (Exception $e) { /* run fb-inbox-upgrade.sql */ }
    $seen_ids = [];
    try {
        $sq = db()->prepare(
            "SELECT id FROM lead_fb_messages
             WHERE contact_id = ? AND direction = 'out' AND seen_at IS NOT NULL
             ORDER BY id DESC LIMIT 30"
        );
        $sq->execute([$contact_id]);
        $seen_ids = array_map('intval', $sq->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { /* run fb-inbox-upgrade-2.sql */ }
    echo json_encode(['messages' => array_map('fbconv_msg_json', $rows), 'seen_ids' => $seen_ids]);
    exit;
}

// ── AJAX: send reply ──────────────────────────────────────────────────────
if (($_GET['ajax'] ?? '') === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$is_staff) { echo json_encode(['ok' => false, 'error' => 'Permission denied.']); exit; }
    csrf_check();
    $text = trim($_POST['fb_reply'] ?? '');
    if ($text === '') { echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']); exit; }

    $sent   = leads_fb_send($contact['psid'], $text);
    $status = $sent ? 'sent' : 'failed';
    try {
        db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by, status) VALUES (?,?,?,?,?)')
            ->execute([$contact_id, 'out', $text, $user['id'], $status]);
    } catch (Exception $e) {
        db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by) VALUES (?,?,?,?)')
            ->execute([$contact_id, 'out', $text, $user['id']]);
    }
    $mid = (int)db()->lastInsertId();
    if ($sent) {
        db()->prepare('UPDATE lead_fb_contacts SET last_message_at = NOW() WHERE id = ?')->execute([$contact_id]);
        $qa_id = (int)($_POST['qa_id'] ?? 0);
        if ($qa_id > 0) {
            try {
                db()->prepare('UPDATE lead_fb_qa SET use_count = use_count + 1 WHERE id = ?')->execute([$qa_id]);
            } catch (Exception $e) { /* run fb-inbox-upgrade-3.sql */ }
        }
        if ($contact['lead_id']) {
            leads_log((int)$contact['lead_id'], 'fb_message_sent', null, null, null,
                'Facebook reply sent by ' . $user['full_name'] . ': ' . mb_substr($text, 0, 100));
        }
    }
    echo json_encode([
        'ok'     => $sent,
        'id'     => $mid,
        'status' => $status,
        'error'  => $sent ? null : 'Facebook rejected the message. Check FB Settings and the 24-hour messaging window.',
    ]);
    exit;
}

// ── AJAX: send attachment directly from the ERP ─────────────────────────────
if (($_GET['ajax'] ?? '') === 'send_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$is_staff) { echo json_encode(['ok' => false, 'error' => 'Permission denied.']); exit; }
    csrf_check();

    if (empty($_FILES['fb_file']) || ($_FILES['fb_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload failed.']); exit;
    }
    $f = $_FILES['fb_file'];
    if ((int)$f['size'] > 25 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Maximum file size is 25 MB.']); exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($f['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',  'image/png' => 'png',  'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3',
        'text/plain' => 'txt', 'application/zip' => 'zip',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['ok' => false, 'error' => 'File type not allowed. Allowed: images, PDF, Word, Excel, MP4, MP3, TXT, ZIP.']); exit;
    }
    $fb_type = 'file';
    if (str_starts_with($mime, 'image/')) $fb_type = 'image';
    elseif (str_starts_with($mime, 'video/')) $fb_type = 'video';
    elseif (str_starts_with($mime, 'audio/')) $fb_type = 'audio';

    $dir = UPLOAD_DIR . '/fb-attachments';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        echo json_encode(['ok' => false, 'error' => 'Could not create the upload directory.']); exit;
    }
    $name = 'fb_' . $contact_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $path)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save the uploaded file.']); exit;
    }
    $url = UPLOAD_URL . '/fb-attachments/' . $name;

    $sent   = leads_fb_send_attachment($contact['psid'], $path, $mime, $fb_type);
    $status = $sent ? 'sent' : 'failed';
    try {
        db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, attachment_type, attachment_url, sent_by, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$contact_id, 'out', null, $fb_type, $url, $user['id'], $status]);
    } catch (Exception $e) {
        db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, attachment_type, attachment_url, sent_by) VALUES (?,?,?,?,?,?)')
            ->execute([$contact_id, 'out', null, $fb_type, $url, $user['id']]);
    }
    $mid = (int)db()->lastInsertId();
    if ($sent) {
        db()->prepare('UPDATE lead_fb_contacts SET last_message_at = NOW() WHERE id = ?')->execute([$contact_id]);
        if ($contact['lead_id']) {
            leads_log((int)$contact['lead_id'], 'fb_message_sent', null, null, null,
                'Facebook attachment sent by ' . $user['full_name'] . ': ' . $f['name']);
        }
    }
    echo json_encode([
        'ok'      => $sent,
        'message' => fbconv_msg_json([
            'id' => $mid, 'direction' => 'out', 'message_text' => null,
            'attachment_type' => $fb_type, 'attachment_url' => $url,
            'status' => $status, 'sender_name' => $user['full_name'] ?? 'Staff',
            'created_at' => date('Y-m-d H:i:s'),
        ]),
        'error'   => $sent ? null : 'Facebook rejected the attachment. Check FB Settings and the 24-hour messaging window.',
    ]);
    exit;
}

// ── Export transcript as .txt ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'txt') {
    $eq = db()->prepare(
        'SELECT m.*, u.full_name AS sender_name
         FROM lead_fb_messages m LEFT JOIN users u ON u.id = m.sent_by
         WHERE m.contact_id = ? ORDER BY m.id ASC'
    );
    $eq->execute([$contact_id]);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="fb-chat-' . $contact_id . '-' . date('Ymd-His') . '.txt"');
    echo "FACEBOOK MESSENGER TRANSCRIPT\n";
    echo 'Contact : ' . $display_name . ' (PSID: ' . $contact['psid'] . ")\n";
    if ($contact['linked_lead_id']) {
        echo 'Lead    : ' . $contact['first_name'] . ' ' . $contact['last_name'] . ' (' . $contact['lead_number'] . ")\n";
    }
    echo 'Exported: ' . date('d M Y, h:i A') . ' by ' . ($user['full_name'] ?? 'Staff') . "\n";
    echo str_repeat('=', 72) . "\n\n";
    foreach ($eq->fetchAll() as $m) {
        $who = $m['direction'] === 'out'
            ? 'STAFF (' . ($m['sender_name'] ?? 'Auto-reply') . ')'
            : 'CUSTOMER';
        echo '[' . date('d M Y, h:i A', strtotime($m['created_at'])) . '] ' . $who . ":\n";
        if ($m['message_text'] !== null && $m['message_text'] !== '') echo $m['message_text'] . "\n";
        if (!empty($m['attachment_url'])) echo '[Attachment: ' . ($m['attachment_type'] ?? 'file') . '] ' . $m['attachment_url'] . "\n";
        echo "\n";
    }
    exit;
}

// ── POST handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Send reply (non-JS fallback) ─────────────────────────────────
    if ($action === 'send_reply' && $is_staff) {
        $text = trim($_POST['fb_reply'] ?? '');
        if ($text === '') {
            flash_set('error', 'Message cannot be empty.');
        } else {
            $sent   = leads_fb_send($contact['psid'], $text);
            $status = $sent ? 'sent' : 'failed';
            try {
                db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by, status) VALUES (?,?,?,?,?)')
                    ->execute([$contact_id, 'out', $text, $user['id'], $status]);
            } catch (Exception $e) {
                db()->prepare('INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by) VALUES (?,?,?,?)')
                    ->execute([$contact_id, 'out', $text, $user['id']]);
            }
            if ($sent) {
                db()->prepare('UPDATE lead_fb_contacts SET last_message_at=NOW() WHERE id=?')->execute([$contact_id]);
                if ($contact['lead_id']) {
                    leads_log((int)$contact['lead_id'], 'fb_message_sent', null, null, null,
                        'Facebook reply sent by ' . $user['full_name'] . ': ' . mb_substr($text, 0, 100));
                }
                flash_set('success', 'Message sent.');
            } else {
                flash_set('error', 'Failed to send. Check Facebook credentials in FB Settings.');
            }
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Link to lead ──────────────────────────────────────────────────
    if ($action === 'link_lead' && $is_staff) {
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        if ($lead_id > 0) {
            $lchk = db()->prepare('SELECT id, first_name, last_name FROM leads WHERE id = ?');
            $lchk->execute([$lead_id]);
            $ldata = $lchk->fetch();
            if ($ldata) {
                db()->prepare('UPDATE lead_fb_contacts SET lead_id = ? WHERE id = ?')
                    ->execute([$lead_id, $contact_id]);
                leads_log($lead_id, 'fb_linked', 'facebook_contact', null, $contact['psid'],
                    'Facebook contact ' . ($contact['fb_name'] ?? $contact['psid']) . ' linked by ' . $user['full_name']);
                flash_set('success', 'Facebook contact linked to lead ' . $ldata['first_name'] . ' ' . $ldata['last_name'] . '.');
            } else {
                flash_set('error', 'Lead not found.');
            }
        } else {
            flash_set('error', 'Please select a lead.');
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Unlink from lead ──────────────────────────────────────────────
    if ($action === 'unlink_lead' && $is_staff) {
        db()->prepare('UPDATE lead_fb_contacts SET lead_id = NULL WHERE id = ?')
            ->execute([$contact_id]);
        flash_set('success', 'Facebook contact unlinked from lead.');
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Mark conversation as unread / read ───────────────────────────────
    if ($action === 'mark_unread' && $is_staff) {
        try {
            db()->prepare('UPDATE lead_fb_contacts SET marked_unread = 1, last_read_at = NULL WHERE id = ?')
                ->execute([$contact_id]);
            flash_set('success', 'Conversation marked as unread.');
        } catch (Exception $e) {
            flash_set('error', 'Run admin/leads/fb-inbox-upgrade-2.sql first to enable read/unread.');
        }
        redirect(APP_URL . '/leads/fb-inbox.php');
    }
    if ($action === 'mark_read' && $is_staff) {
        try {
            db()->prepare('UPDATE lead_fb_contacts SET marked_unread = 0, last_read_at = NOW() WHERE id = ?')
                ->execute([$contact_id]);
            flash_set('success', 'Conversation marked as read.');
        } catch (Exception $e) {
            flash_set('error', 'Run admin/leads/fb-inbox-upgrade-2.sql first to enable read/unread.');
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── 1-click convert conversation to a lead ────────────────────────────────
    if ($action === 'convert_lead' && leads_can_create() && empty($contact['lead_id'])) {
        $name_parts = preg_split('/\s+/', trim((string)($contact['fb_name'] ?? '')), 2);
        $first = ($name_parts[0] ?? '') !== '' ? $name_parts[0] : 'Facebook';
        $last  = $name_parts[1] ?? 'User';
        $lead_number = leads_generate_number();
        db()->prepare(
            'INSERT INTO leads (lead_number, first_name, last_name, phone, degree_type, status, source, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$lead_number, $first, $last, '', 'bachelor', 'fresh', 'facebook', $user['id']]);
        $lead_id = (int)db()->lastInsertId();
        db()->prepare('UPDATE lead_fb_contacts SET lead_id = ? WHERE id = ?')->execute([$lead_id, $contact_id]);
        leads_log($lead_id, 'created', null, null, null, 'Lead created from Facebook conversation by ' . $user['full_name']);
        leads_log($lead_id, 'fb_linked', 'facebook_contact', null, $contact['psid'], 'Facebook contact auto-linked during conversion');
        if (function_exists('log_change')) {
            log_change('leads', 'CREATE', $lead_id, $first . ' ' . $last, null, null, $lead_number, 'Lead created from FB conversation');
        }
        flash_set('success', 'Lead ' . $lead_number . ' created from this conversation. Please add the phone number and remaining details.');
        redirect(APP_URL . '/leads/edit.php?id=' . $lead_id);
    }

    // ── Internal staff note ────────────────────────────────────────────
    if ($action === 'add_contact_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            flash_set('error', 'Note cannot be empty.');
        } else {
            try {
                db()->prepare('INSERT INTO lead_fb_contact_notes (contact_id, user_id, note) VALUES (?,?,?)')
                    ->execute([$contact_id, $user['id'], $note]);
                flash_set('success', 'Internal note added.');
            } catch (Exception $e) {
                flash_set('error', 'Notes table missing – run admin/leads/fb-inbox-upgrade.sql first.');
            }
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }
    if ($action === 'delete_contact_note' && $is_staff) {
        try {
            db()->prepare('DELETE FROM lead_fb_contact_notes WHERE id = ? AND contact_id = ?')
                ->execute([(int)($_POST['note_id'] ?? 0), $contact_id]);
            flash_set('success', 'Note deleted.');
        } catch (Exception $e) { /* ignore */ }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Toggle conversation tag ──────────────────────────────────────────
    if ($action === 'toggle_tag' && $is_staff) {
        $tag_id = (int)($_POST['tag_id'] ?? 0);
        try {
            $chk = db()->prepare('SELECT 1 FROM lead_fb_contact_tags WHERE contact_id = ? AND tag_id = ?');
            $chk->execute([$contact_id, $tag_id]);
            if ($chk->fetchColumn()) {
                db()->prepare('DELETE FROM lead_fb_contact_tags WHERE contact_id = ? AND tag_id = ?')
                    ->execute([$contact_id, $tag_id]);
            } else {
                db()->prepare('INSERT IGNORE INTO lead_fb_contact_tags (contact_id, tag_id) VALUES (?,?)')
                    ->execute([$contact_id, $tag_id]);
            }
        } catch (Exception $e) {
            flash_set('error', 'Tags tables missing – run admin/leads/fb-inbox-upgrade.sql first.');
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Canned responses management (unlimited) ────────────────────────────────
    if ($action === 'add_canned' && $is_staff) {
        $shortcut = strtolower(trim($_POST['shortcut'] ?? ''));
        $title    = trim($_POST['title'] ?? '');
        $body     = trim($_POST['body'] ?? '');
        if (!preg_match('/^\/[a-z0-9_-]{1,25}$/', $shortcut)) {
            flash_set('error', 'Shortcut must look like /fees (letters, numbers, - or _).');
        } elseif ($title === '' || $body === '') {
            flash_set('error', 'Title and reply text are required.');
        } else {
            try {
                db()->prepare('INSERT INTO lead_fb_canned_responses (shortcut, title, body, created_by) VALUES (?,?,?,?)')
                    ->execute([$shortcut, $title, $body, $user['id']]);
                flash_set('success', 'Canned reply ' . $shortcut . ' added.');
            } catch (Exception $e) {
                flash_set('error', 'Could not save (duplicate shortcut, or run admin/leads/fb-inbox-upgrade.sql first).');
            }
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }
    if ($action === 'delete_canned' && $is_staff) {
        try {
            db()->prepare('DELETE FROM lead_fb_canned_responses WHERE id = ?')
                ->execute([(int)($_POST['canned_id'] ?? 0)]);
            flash_set('success', 'Canned reply deleted.');
        } catch (Exception $e) { /* ignore */ }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }

    // ── Saved Q&A (smart answer suggestions) ──────────────────────────────────
    if ($action === 'add_qa' && $is_staff) {
        $qa_question = trim($_POST['qa_question'] ?? '');
        $qa_keywords = trim($_POST['qa_keywords'] ?? '');
        $qa_answer   = trim($_POST['qa_answer'] ?? '');
        if ($qa_question === '' || $qa_answer === '') {
            flash_set('error', 'Question and answer are both required.');
        } else {
            try {
                db()->prepare('INSERT INTO lead_fb_qa (question, keywords, answer, created_by) VALUES (?,?,?,?)')
                    ->execute([$qa_question, $qa_keywords !== '' ? $qa_keywords : null, $qa_answer, $user['id']]);
                flash_set('success', 'Q&A saved. Matching customer questions will now show this answer as a suggestion.');
            } catch (Exception $e) {
                flash_set('error', 'Q&A table missing – run admin/leads/fb-inbox-upgrade-3.sql first.');
            }
        }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }
    if ($action === 'delete_qa' && $is_staff) {
        try {
            db()->prepare('DELETE FROM lead_fb_qa WHERE id = ?')->execute([(int)($_POST['qa_id'] ?? 0)]);
            flash_set('success', 'Q&A deleted.');
        } catch (Exception $e) { /* ignore */ }
        redirect(APP_URL . '/leads/fb-conversation.php?contact_id=' . $contact_id);
    }
}

// ── Fetch messages ───────────────────────────────────────────────────────
$msgs_q = db()->prepare(
    'SELECT m.*, u.full_name AS sender_name
     FROM lead_fb_messages m
     LEFT JOIN users u ON u.id = m.sent_by
     WHERE m.contact_id = ?
     ORDER BY m.id ASC'
);
$msgs_q->execute([$contact_id]);
$messages = $msgs_q->fetchAll();
$last_row = $messages ? $messages[count($messages) - 1] : null;
$last_msg_id = $last_row ? (int)$last_row['id'] : 0;

$upgrade_needed = false;

// Mark conversation as read (and clear any manual "unread" flag)
try {
    db()->prepare('UPDATE lead_fb_contacts SET last_read_at = NOW(), marked_unread = 0 WHERE id = ?')->execute([$contact_id]);
} catch (Exception $e) {
    try {
        db()->prepare('UPDATE lead_fb_contacts SET last_read_at = NOW() WHERE id = ?')->execute([$contact_id]);
    } catch (Exception $e2) { $upgrade_needed = true; }
}

// Canned responses (unlimited – searchable in the UI)
$canned = [];
try {
    $canned = db()->query('SELECT * FROM lead_fb_canned_responses WHERE is_active = 1 ORDER BY shortcut ASC')->fetchAll();
} catch (Exception $e) { $upgrade_needed = true; }
$canned_map = [];
foreach ($canned as $cr) { $canned_map[$cr['shortcut']] = $cr['body']; }

// Saved Q&A for smart answer suggestions
$qa_list = [];
try {
    $qa_list = db()->query('SELECT id, question, keywords, answer, use_count FROM lead_fb_qa WHERE is_active = 1 ORDER BY use_count DESC, id DESC')->fetchAll();
} catch (Exception $e) { /* run fb-inbox-upgrade-3.sql */ }

// Last incoming customer message (used to suggest answers on page load)
$last_incoming_text = '';
foreach (array_reverse($messages) as $m_row) {
    if ($m_row['direction'] === 'in' && (string)($m_row['message_text'] ?? '') !== '') {
        $last_incoming_text = (string)$m_row['message_text'];
        break;
    }
}

// Internal notes
$contact_notes = [];
try {
    $nq = db()->prepare(
        'SELECT n.*, u.full_name FROM lead_fb_contact_notes n LEFT JOIN users u ON u.id = n.user_id
         WHERE n.contact_id = ? ORDER BY n.created_at DESC'
    );
    $nq->execute([$contact_id]);
    $contact_notes = $nq->fetchAll();
} catch (Exception $e) { $upgrade_needed = true; }

// Tags
$all_tags = [];
$contact_tag_ids = [];
try {
    $all_tags = db()->query('SELECT * FROM lead_fb_tags ORDER BY name ASC')->fetchAll();
    $tq = db()->prepare('SELECT tag_id FROM lead_fb_contact_tags WHERE contact_id = ?');
    $tq->execute([$contact_id]);
    $contact_tag_ids = array_map('intval', $tq->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) { $upgrade_needed = true; }
$active_tags = array_values(array_filter($all_tags, fn($t) => in_array((int)$t['id'], $contact_tag_ids, true)));

// Phone number detection (customer provided a phone number at any point)
$phone_found = null;
try {
    $pq = db()->prepare(
        "SELECT message_text FROM lead_fb_messages
         WHERE contact_id = ? AND direction = 'in' AND message_text REGEXP '01[3-9][0-9]{8}'
         ORDER BY id DESC LIMIT 1"
    );
    $pq->execute([$contact_id]);
    $ptext = $pq->fetchColumn();
    if ($ptext && preg_match('/(?:\+?88)?01[3-9]\d{8}/', $ptext, $pm)) {
        $phone_found = $pm[0];
    }
} catch (Exception $e) { /* ignore */ }

// Contact sidebar (50 most recent, with unread counts when available)
try {
    $side_contacts = db()->query(
        "SELECT c.id, c.psid, c.fb_name, c.fb_picture, c.lead_id, c.last_message_at, c.first_seen,
                (SELECT COUNT(*) FROM lead_fb_messages m WHERE m.contact_id = c.id AND m.direction = 'in'
                   AND (c.last_read_at IS NULL OR m.created_at > c.last_read_at)) AS unread,
                (SELECT m2.message_text FROM lead_fb_messages m2 WHERE m2.contact_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_text
         FROM lead_fb_contacts c
         ORDER BY c.last_message_at DESC, c.first_seen DESC
         LIMIT 50"
    )->fetchAll();
} catch (Exception $e) {
    $side_contacts = db()->query(
        "SELECT c.id, c.psid, c.fb_name, c.fb_picture, c.lead_id, c.last_message_at, c.first_seen,
                0 AS unread,
                (SELECT m2.message_text FROM lead_fb_messages m2 WHERE m2.contact_id = c.id ORDER BY m2.id DESC LIMIT 1) AS last_text
         FROM lead_fb_contacts c
         ORDER BY c.last_message_at DESC, c.first_seen DESC
         LIMIT 50"
    )->fetchAll();
}

// Leads for the link dropdown
$all_leads = db()->query(
    'SELECT id, lead_number, first_name, last_name FROM leads ORDER BY created_at DESC LIMIT 200'
)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.msg-bubble{border-radius:18px}
.msg-out{background:#1877F2;color:#fff;border-bottom-right-radius:6px}
.msg-in{background:#fff;color:#111;border:1px solid #e4e6eb;border-bottom-left-radius:6px}
.contact-item.active{background:#1877F2;border-color:#1877F2}
.tag-toggle{cursor:pointer}
.fb-phone-hit{background:#ffe58a;color:#7a5b00;font-weight:600;padding:0 3px;border-radius:4px}
#qa-suggest{animation:qaFade .25s ease}
.qa-chip{background:linear-gradient(90deg,#f8f5ff,#f0f7ff);border-color:#e3d9f7!important;transition:box-shadow .15s}
.qa-chip:hover{box-shadow:0 2px 8px rgba(111,66,193,.18)}
@keyframes qaFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i><?= h($display_name) ?>
            <?php foreach ($active_tags as $tg): ?>
            <span class="badge ms-1" style="background:<?= h($tg['color']) ?>;font-size:.6rem;vertical-align:middle"><?= h($tg['name']) ?></span>
            <?php endforeach; ?>
            <?php if ($phone_found): ?>
            <span class="badge bg-success ms-1" style="font-size:.65rem;vertical-align:middle" title="The customer shared a phone number in this chat"><i class="fas fa-phone me-1"></i>Has phone: <?= h($phone_found) ?></span>
            <?php endif; ?>
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a></li>
            <li class="breadcrumb-item active"><?= h($display_name) ?></li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($is_staff): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_unread">
            <button type="submit" class="btn btn-outline-warning btn-sm" title="Mark as unread and return to inbox"><i class="fas fa-envelope me-1"></i> Mark Unread</button>
        </form>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_read">
            <button type="submit" class="btn btn-outline-success btn-sm" title="Mark this conversation as read"><i class="fas fa-envelope-open me-1"></i> Mark Read</button>
        </form>
        <?php endif; ?>
        <a href="?contact_id=<?= $contact_id ?>&export=txt" class="btn btn-outline-dark btn-sm"><i class="fas fa-file-download me-1"></i> Export Chat</a>
        <a href="<?= APP_URL ?>/leads/fb-analytics.php" class="btn btn-outline-info btn-sm"><i class="fas fa-chart-line me-1"></i> Analytics</a>
        <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?= flash_show() ?>

<?php if ($upgrade_needed && is_super_admin()): ?>
<div class="alert alert-warning py-2 small">
    <i class="fas fa-database me-1"></i>
    Some inbox features (unread tracking, canned replies, notes, tags, seen receipts, auto follow-up) are disabled.
    Run <code>admin/leads/fb-inbox-upgrade.sql</code> and <code>admin/leads/fb-inbox-upgrade-2.sql</code> once to enable them.
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── Contacts sidebar ── -->
    <div class="col-12 col-lg-3">
        <div class="card border-0 shadow-sm" style="height:660px;display:flex;flex-direction:column">
            <div class="card-header bg-white p-2">
                <input type="text" id="contact-search" class="form-control form-control-sm mb-2" placeholder="Search name or PSID…" autocomplete="off">
                <div class="btn-group btn-group-sm w-100" role="group">
                    <button type="button" class="btn btn-outline-secondary active" data-tab="all">All</button>
                    <button type="button" class="btn btn-outline-secondary" data-tab="unread">Unread</button>
                    <button type="button" class="btn btn-outline-secondary" data-tab="unlinked">Unlinked</button>
                </div>
            </div>
            <div id="contact-list" class="list-group list-group-flush" style="overflow-y:auto;flex:1">
                <?php foreach ($side_contacts as $sc):
                    $sc_name   = $sc['fb_name'] ?: 'FB User #' . substr((string)$sc['psid'], -6);
                    $is_active = (int)$sc['id'] === $contact_id;
                ?>
                <a href="?contact_id=<?= $sc['id'] ?>"
                   class="list-group-item list-group-item-action d-flex gap-2 align-items-center py-2 contact-item <?= $is_active ? 'active' : '' ?>"
                   data-name="<?= h(mb_strtolower($sc_name . ' ' . $sc['psid'])) ?>"
                   data-unread="<?= (int)$sc['unread'] ?>"
                   data-linked="<?= $sc['lead_id'] ? 1 : 0 ?>">
                    <?php if ($sc['fb_picture']): ?>
                    <img src="<?= h($sc['fb_picture']) ?>" class="rounded-circle flex-shrink-0" width="34" height="34" alt="" style="object-fit:cover">
                    <?php else: ?>
                    <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width:34px;height:34px;background:#1877F2">
                        <i class="fab fa-facebook-messenger text-white" style="font-size:.7rem"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold small text-truncate"><?= h($sc_name) ?></span>
                            <small class="flex-shrink-0 ms-1 <?= $is_active ? '' : 'text-muted' ?>" style="font-size:.62rem"><?= h(leads_time_ago($sc['last_message_at'] ?: $sc['first_seen'])) ?></small>
                        </div>
                        <div class="text-truncate <?= $is_active ? '' : 'text-muted' ?>" style="font-size:.72rem"><?= h(mb_substr((string)($sc['last_text'] ?? ''), 0, 44)) ?></div>
                    </div>
                    <?php if ((int)$sc['unread'] > 0 && !$is_active): ?>
                    <span class="badge bg-danger rounded-pill flex-shrink-0"><?= (int)$sc['unread'] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Chat column ── -->
    <div class="col-12 col-lg-6">
        <div class="card border-0 shadow-sm" style="height:660px;display:flex;flex-direction:column">
            <div class="card-header bg-white d-flex align-items-center gap-3 py-2">
                <?php if ($contact['fb_picture']): ?>
                <img src="<?= h($contact['fb_picture']) ?>" class="rounded-circle" width="42" height="42" alt="" style="object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:42px;height:42px;background:#1877F2">
                    <i class="fab fa-facebook-messenger text-white"></i>
                </div>
                <?php endif; ?>
                <div class="overflow-hidden">
                    <div class="fw-semibold text-truncate"><?= h($display_name) ?></div>
                    <div class="text-muted small">PSID: <?= h($contact['psid']) ?> · First seen <?= h(leads_time_ago($contact['first_seen'])) ?></div>
                </div>
                <?php if ($contact['linked_lead_id']): ?>
                <div class="ms-auto flex-shrink-0">
                    <a href="<?= APP_URL ?>/leads/view.php?id=<?= $contact['linked_lead_id'] ?>#facebook" class="btn btn-sm btn-success">
                        <i class="fas fa-user me-1"></i> <?= h($contact['lead_number']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <div id="msg-thread" style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:10px;background:#f0f2f5">
                <?php if ($messages): foreach ($messages as $msg): $is_out = $msg['direction'] === 'out'; $m_status = $msg['status'] ?? 'sent'; ?>
                <div class="d-flex <?= $is_out ? 'justify-content-end' : 'justify-content-start' ?> align-items-end gap-2" data-id="<?= (int)$msg['id'] ?>">
                    <?php if (!$is_out && $contact['fb_picture']): ?>
                    <img src="<?= h($contact['fb_picture']) ?>" class="rounded-circle flex-shrink-0 mb-1" width="28" height="28" alt="" style="object-fit:cover">
                    <?php elseif (!$is_out): ?>
                    <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center mb-1" style="width:28px;height:28px;background:#1877F2">
                        <i class="fab fa-facebook-messenger text-white" style="font-size:.6rem"></i>
                    </div>
                    <?php endif; ?>
                    <div style="max-width:75%">
                        <div class="px-3 py-2 msg-bubble <?= $is_out ? 'msg-out' : 'msg-in' ?>">
                            <?php if ($msg['message_text'] !== null && $msg['message_text'] !== ''): ?>
                            <div style="font-size:.9rem;line-height:1.45;white-space:pre-wrap;word-break:break-word"><?= $is_out ? h($msg['message_text']) : leads_fb_highlight_phones($msg['message_text']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($msg['attachment_url'])): ?>
                            <div class="mt-1">
                                <?php if (($msg['attachment_type'] ?? '') === 'image'): ?>
                                <a href="<?= h($msg['attachment_url']) ?>" target="_blank" rel="noopener">
                                    <img src="<?= h($msg['attachment_url']) ?>" style="max-width:220px;border-radius:8px" alt="image">
                                </a>
                                <?php else: ?>
                                <a href="<?= h($msg['attachment_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm <?= $is_out ? 'btn-light' : 'btn-outline-primary' ?>">
                                    <i class="fas fa-download me-1"></i><?= h($msg['attachment_type'] ?: 'file') ?>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-1 px-1 text-muted d-flex gap-1 align-items-center <?= $is_out ? 'justify-content-end' : '' ?>" style="font-size:.68rem">
                            <span title="<?= h(date('d M Y, h:i A', strtotime($msg['created_at']))) ?>"><?= $is_out ? h(($msg['sender_name'] ?? 'Auto-reply')) . ' · ' : '' ?><?= h(leads_time_ago($msg['created_at'])) ?></span>
                            <?php if ($is_out): ?>
                            <span class="msg-status" data-msg-id="<?= (int)$msg['id'] ?>"><?php
                                if ($m_status === 'failed') {
                                    echo '<i class="fas fa-exclamation-circle text-danger" title="Failed to send"></i>';
                                } elseif (!empty($msg['seen_at'])) {
                                    echo '<i class="fas fa-check-double" style="color:#1877F2" title="Seen by customer"></i>';
                                } else {
                                    echo '<i class="fas fa-check" title="Sent"></i>';
                                }
                            ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?>
                <div class="text-center text-muted py-5" id="empty-thread">
                    <i class="fab fa-facebook-messenger fa-2x mb-2 d-block" style="color:#1877F2"></i>
                    No messages yet.
                </div>
                <?php endif; ?>
            </div>

            <?php if ($is_staff): ?>
            <div class="card-footer bg-white border-top-0 pt-2">
                <div id="qa-suggest" class="d-none mb-2">
                    <div class="d-flex align-items-center gap-1 mb-1">
                        <span class="small fw-semibold" style="color:#6f42c1"><i class="fas fa-lightbulb me-1 text-warning"></i>Suggested answers</span>
                        <span class="text-muted" style="font-size:.65rem">based on the customer's question</span>
                        <button type="button" id="qa-suggest-close" class="btn btn-link btn-sm text-muted p-0 ms-auto" style="font-size:.65rem">hide</button>
                    </div>
                    <div id="qa-suggest-list" class="d-flex flex-column gap-1"></div>
                </div>
                <div class="position-relative">
                    <div id="canned-panel" class="card shadow border position-absolute d-none" style="bottom:100%;left:0;width:330px;max-height:320px;overflow-y:auto;z-index:50">
                        <div class="p-2 border-bottom bg-white position-sticky top-0">
                            <input type="text" id="canned-search" class="form-control form-control-sm" placeholder="Search canned replies…" autocomplete="off">
                        </div>
                        <div class="list-group list-group-flush">
                            <?php foreach ($canned as $cr): ?>
                            <button type="button" class="list-group-item list-group-item-action py-2 canned-item" data-body="<?= h($cr['body']) ?>" data-search="<?= h(mb_strtolower($cr['shortcut'] . ' ' . $cr['title'] . ' ' . $cr['body'])) ?>">
                                <code class="small"><?= h($cr['shortcut']) ?></code>
                                <span class="small fw-semibold ms-1"><?= h($cr['title']) ?></span>
                                <div class="text-muted text-truncate" style="font-size:.72rem"><?= h(mb_substr($cr['body'], 0, 64)) ?></div>
                            </button>
                            <?php endforeach; ?>
                            <?php if (!$canned): ?>
                            <div class="list-group-item text-muted small">No canned replies yet – add them in the sidebar.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="post" id="reply-form" class="d-flex gap-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send_reply">
                        <button type="button" id="canned-toggle" class="btn btn-light border flex-shrink-0" title="Canned replies" style="height:56px"><i class="fas fa-bolt text-warning"></i></button>
                        <button type="button" id="attach-btn" class="btn btn-light border flex-shrink-0" title="Send attachment (max 25 MB)" style="height:56px"><i class="fas fa-paperclip text-primary"></i></button>
                        <input type="file" id="fb_file" class="d-none" accept="image/*,video/mp4,audio/mpeg,application/pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
                        <textarea name="fb_reply" id="fb_reply" class="form-control" rows="2" placeholder="Type a message… (/shortcut + Tab for canned reply, Ctrl+Enter to send)" required style="resize:none"></textarea>
                        <button type="submit" class="btn text-white flex-shrink-0" style="background:#1877F2;height:56px"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Details sidebar ── -->
    <div class="col-12 col-lg-3">

        <!-- Lead link / conversion -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="fas fa-link me-2 text-info"></i>Lead</div>
            <div class="card-body p-3">
                <?php if ($contact['linked_lead_id']): ?>
                <div class="mb-2">
                    <div class="fw-semibold"><?= h($contact['first_name'] . ' ' . $contact['last_name']) ?></div>
                    <div class="text-muted small"><?= h($contact['lead_number']) ?></div>
                </div>
                <a href="<?= APP_URL ?>/leads/view.php?id=<?= $contact['linked_lead_id'] ?>#facebook" class="btn btn-sm btn-outline-primary w-100 mb-2"><i class="fas fa-eye me-1"></i> View Lead</a>
                <?php if ($is_staff): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlink_lead">
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('Unlink this contact from the lead?')"><i class="fas fa-unlink me-1"></i> Unlink</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <?php if (leads_can_create()): ?>
                <form method="post" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="convert_lead">
                    <button type="submit" class="btn btn-sm btn-primary w-100" onclick="return confirm('Create a new lead from this conversation? Name and source are filled automatically.')">
                        <i class="fas fa-user-plus me-1"></i> Convert to Lead
                    </button>
                </form>
                <div class="text-center text-muted small mb-2">— or link an existing lead —</div>
                <?php endif; ?>
                <?php if ($is_staff): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="link_lead">
                    <select name="lead_id" class="form-select form-select-sm mb-2" required>
                        <option value="">— Select Lead —</option>
                        <?php foreach ($all_leads as $ld): ?>
                        <option value="<?= $ld['id'] ?>"><?= h($ld['lead_number'] . ' – ' . $ld['first_name'] . ' ' . $ld['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-success w-100"><i class="fas fa-link me-1"></i> Link Lead</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tags -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2"><i class="fas fa-tags me-2 text-warning"></i>Tags</div>
            <div class="card-body p-3">
                <?php if ($all_tags): ?>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($all_tags as $tg): $on = in_array((int)$tg['id'], $contact_tag_ids, true); ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_tag">
                        <input type="hidden" name="tag_id" value="<?= (int)$tg['id'] ?>">
                        <button type="submit" class="badge tag-toggle mb-1" <?= !$is_staff ? 'disabled' : '' ?>
                                style="<?= $on ? 'background:' . h($tg['color']) . ';color:#fff;border:1px solid ' . h($tg['color']) : 'background:#fff;color:' . h($tg['color']) . ';border:1px solid ' . h($tg['color']) ?>">
                            <?= $on ? '<i class="fas fa-check me-1"></i>' : '' ?><?= h($tg['name']) ?>
                        </button>
                    </form>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-0">Run <code>fb-inbox-upgrade.sql</code> to enable tags.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Internal notes -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold py-2">
                <i class="fas fa-lock me-2 text-secondary"></i>Internal Notes
                <span class="badge bg-light text-muted border ms-1" style="font-size:.6rem">staff only</span>
            </div>
            <div class="card-body p-3">
                <form method="post" class="mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_contact_note">
                    <textarea name="note" class="form-control form-control-sm mb-2" rows="2" placeholder="Private note for the team… (never sent to the customer)" required></textarea>
                    <button type="submit" class="btn btn-sm btn-secondary w-100"><i class="fas fa-plus me-1"></i> Add Note</button>
                </form>
                <?php if ($contact_notes): ?>
                <div style="max-height:220px;overflow-y:auto">
                    <?php foreach ($contact_notes as $cn): ?>
                    <div class="border rounded p-2 mb-2 bg-light">
                        <div class="small" style="white-space:pre-wrap"><?= h($cn['note']) ?></div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <small class="text-muted" style="font-size:.65rem"><?= h($cn['full_name'] ?? 'Staff') ?> · <?= h(leads_time_ago($cn['created_at'])) ?></small>
                            <?php if ($is_staff): ?>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_contact_note">
                                <input type="hidden" name="note_id" value="<?= (int)$cn['id'] ?>">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size:.65rem" onclick="return confirm('Delete this note?')">delete</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-0">No internal notes yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Smart answer suggestions (saved Q&A) -->
        <?php if ($is_staff): ?>
        <details class="card border-0 shadow-sm mb-3">
            <summary class="card-header bg-white fw-semibold py-2" style="cursor:pointer;list-style:none">
                <i class="fas fa-lightbulb me-2 text-warning"></i>Smart Answer Suggestions
                <span class="badge bg-light text-muted border ms-1" style="font-size:.6rem"><?= count($qa_list) ?> saved</span>
            </summary>
            <div class="card-body p-3">
                <p class="text-muted mb-2" style="font-size:.72rem">Save common questions with their answers. When a customer asks a similar question, the matching answer appears above the reply box – one click to use or send it.</p>
                <?php if ($qa_list): ?>
                <div style="max-height:220px;overflow-y:auto" class="mb-2">
                    <?php foreach ($qa_list as $qa): ?>
                    <div class="border rounded p-2 mb-1 bg-light">
                        <div class="small fw-semibold" style="font-size:.75rem"><i class="fas fa-question-circle text-primary me-1"></i><?= h($qa['question']) ?></div>
                        <div class="text-muted text-truncate" style="font-size:.7rem"><?= h(mb_substr($qa['answer'], 0, 70)) ?></div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <small class="text-muted" style="font-size:.62rem"><i class="fas fa-paper-plane me-1"></i>used <?= (int)$qa['use_count'] ?>×</small>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_qa">
                                <input type="hidden" name="qa_id" value="<?= (int)$qa['id'] ?>">
                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size:.65rem" onclick="return confirm('Delete this Q&A?')">delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small">No saved Q&A yet – add your first one below.</p>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_qa">
                    <input type="text" name="qa_question" class="form-control form-control-sm mb-2" placeholder="Customer question (e.g. What is the tuition fee?)" required>
                    <input type="text" name="qa_keywords" class="form-control form-control-sm mb-2" placeholder="Extra keywords, comma separated (optional)">
                    <textarea name="qa_answer" class="form-control form-control-sm mb-2" rows="3" placeholder="Answer to send…" required></textarea>
                    <button type="submit" class="btn btn-sm btn-warning w-100"><i class="fas fa-plus me-1"></i> Save Q&A</button>
                </form>
            </div>
        </details>
        <?php endif; ?>

        <!-- Canned replies management (unlimited) -->
        <?php if ($is_staff): ?>
        <details class="card border-0 shadow-sm mb-3">
            <summary class="card-header bg-white fw-semibold py-2" style="cursor:pointer;list-style:none">
                <i class="fas fa-bolt me-2 text-warning"></i>Manage Canned Replies
                <span class="badge bg-light text-muted border ms-1" style="font-size:.6rem"><?= count($canned) ?> saved · unlimited</span>
            </summary>
            <div class="card-body p-3">
                <input type="text" id="canned-manage-search" class="form-control form-control-sm mb-2" placeholder="Search saved replies…" autocomplete="off">
                <div id="canned-manage-list" style="max-height:240px;overflow-y:auto">
                <?php foreach ($canned as $cr): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-1 canned-manage-item" data-search="<?= h(mb_strtolower($cr['shortcut'] . ' ' . $cr['title'] . ' ' . $cr['body'])) ?>">
                    <div class="overflow-hidden">
                        <code class="small"><?= h($cr['shortcut']) ?></code>
                        <span class="small ms-1"><?= h($cr['title']) ?></span>
                    </div>
                    <form method="post" class="d-inline flex-shrink-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_canned">
                        <input type="hidden" name="canned_id" value="<?= (int)$cr['id'] ?>">
                        <button type="submit" class="btn btn-link btn-sm text-danger p-0" style="font-size:.7rem" onclick="return confirm('Delete this canned reply?')">delete</button>
                    </form>
                </div>
                <?php endforeach; ?>
                </div>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_canned">
                    <input type="text" name="shortcut" class="form-control form-control-sm mb-2" placeholder="/shortcut (e.g. /fees)" required>
                    <input type="text" name="title" class="form-control form-control-sm mb-2" placeholder="Title" required>
                    <textarea name="body" class="form-control form-control-sm mb-2" rows="3" placeholder="Reply text…" required></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-plus me-1"></i> Add Canned Reply</button>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const thread      = document.getElementById('msg-thread');
    const CONTACT_PIC = <?= json_encode((string)($contact['fb_picture'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const STAFF_NAME  = <?= json_encode((string)($user['full_name'] ?? 'Staff'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const CANNED      = <?= json_encode($canned_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const QA_LIST     = <?= json_encode(array_map(static fn($r) => [
        'id' => (int)$r['id'],
        'q'  => (string)$r['question'],
        'k'  => (string)($r['keywords'] ?? ''),
        'a'  => (string)$r['answer'],
    ], $qa_list), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const LAST_IN_TEXT = <?= json_encode($last_incoming_text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    let   pendingQaId  = 0;
    let   lastId      = <?= $last_msg_id ?>;

    function scrollBottom() { if (thread) thread.scrollTop = thread.scrollHeight; }
    scrollBottom();

    // ── Audio chime for new incoming messages ──
    let audioCtx;
    function chime() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.5);
            o.connect(g); g.connect(audioCtx.destination);
            o.start(); o.stop(audioCtx.currentTime + 0.55);
        } catch (e) { /* audio blocked */ }
    }

    // ── Highlight phone numbers in customer messages ──
    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function phoneHighlight(s) {
        return escHtml(s).replace(/((?:\+?88)?01[3-9]\d{8})/g, '<span class="fb-phone-hit" title="Phone number detected">$1</span>');
    }

    // ── Build a message bubble ──
    function renderMsg(m) {
        const wrap = document.createElement('div');
        wrap.className = 'd-flex align-items-end gap-2 ' + (m.direction === 'out' ? 'justify-content-end' : 'justify-content-start');
        if (m.id) wrap.dataset.id = m.id;

        if (m.direction !== 'out') {
            if (CONTACT_PIC) {
                const img = document.createElement('img');
                img.src = CONTACT_PIC; img.width = 28; img.height = 28;
                img.className = 'rounded-circle flex-shrink-0 mb-1';
                img.style.objectFit = 'cover';
                wrap.appendChild(img);
            } else {
                const av = document.createElement('div');
                av.className = 'rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center mb-1';
                av.style.cssText = 'width:28px;height:28px;background:#1877F2';
                av.innerHTML = '<i class="fab fa-facebook-messenger text-white" style="font-size:.6rem"></i>';
                wrap.appendChild(av);
            }
        }

        const box = document.createElement('div');
        box.style.maxWidth = '75%';

        const bubble = document.createElement('div');
        bubble.className = 'px-3 py-2 msg-bubble ' + (m.direction === 'out' ? 'msg-out' : 'msg-in');
        if (m.text) {
            const t = document.createElement('div');
            t.style.cssText = 'font-size:.9rem;line-height:1.45;white-space:pre-wrap;word-break:break-word';
            if (m.direction === 'out') {
                t.textContent = m.text;
            } else {
                t.innerHTML = phoneHighlight(m.text);
            }
            bubble.appendChild(t);
        }
        if (m.attachment_url) {
            const att = document.createElement('div');
            att.className = 'mt-1';
            const a = document.createElement('a');
            a.href = m.attachment_url; a.target = '_blank'; a.rel = 'noopener';
            if (m.attachment_type === 'image') {
                const im = document.createElement('img');
                im.src = m.attachment_url;
                im.style.cssText = 'max-width:220px;border-radius:8px';
                a.appendChild(im);
            } else {
                a.className = 'btn btn-sm ' + (m.direction === 'out' ? 'btn-light' : 'btn-outline-primary');
                a.innerHTML = '<i class="fas fa-download me-1"></i>' + (m.attachment_type || 'file');
            }
            att.appendChild(a);
            bubble.appendChild(att);
        }
        box.appendChild(bubble);

        const meta = document.createElement('div');
        meta.className = 'mt-1 px-1 text-muted d-flex gap-1 align-items-center' + (m.direction === 'out' ? ' justify-content-end' : '');
        meta.style.fontSize = '.68rem';
        const span = document.createElement('span');
        span.textContent = (m.direction === 'out' ? (m.sender || STAFF_NAME) + ' · ' : '') + (m.time || 'Just now');
        if (m.time_full) span.title = m.time_full;
        meta.appendChild(span);
        if (m.direction === 'out') {
            const st = document.createElement('span');
            st.className = 'msg-status';
            if (m.id) st.dataset.msgId = m.id;
            st.innerHTML = m.status === 'sending'
                ? '<i class="far fa-clock" title="Sending…"></i>'
                : (m.status === 'failed'
                    ? '<i class="fas fa-exclamation-circle text-danger" title="Failed to send"></i>'
                    : (m.seen
                        ? '<i class="fas fa-check-double" style="color:#1877F2" title="Seen by customer"></i>'
                        : '<i class="fas fa-check" title="Sent"></i>'));
            meta.appendChild(st);
        }
        box.appendChild(meta);
        wrap.appendChild(box);
        return wrap;
    }

    function removeEmptyPlaceholder() {
        const ph = document.getElementById('empty-thread');
        if (ph) ph.remove();
    }

    // ── Smart answer suggestions (saved Q&A) ──
    const qaBox    = document.getElementById('qa-suggest');
    const qaListEl = document.getElementById('qa-suggest-list');
    const qaClose  = document.getElementById('qa-suggest-close');
    const QA_STOP  = new Set(['the','and','for','are','you','your','what','how','can','will','with','this','that','about','have','please','from','when','where','which','there','would','could','should','is','to','of','in','my','me','do','it','on','at','be','or','if','we','us','our','know','tell','want','need','like','ki','kina','ache','ase','koto','kemon','apni','ami','amar','apnar','vai','bhai','কি','কত','আমি','আমার','আপনি','আপনার','ভাই']);
    function qaTokens(s) {
        return String(s || '').toLowerCase()
            .replace(/[^\p{L}\p{N}\s]/gu, ' ')
            .split(/\s+/)
            .filter(function (w) { return w.length > 1 && !QA_STOP.has(w); });
    }
    function qaScore(msgTokens, msgLower, qa) {
        const base = qaTokens(qa.q).concat(qaTokens(qa.k));
        if (!base.length) return 0;
        let hits = 0;
        const seen = new Set();
        base.forEach(function (t) {
            if (seen.has(t)) return;
            seen.add(t);
            if (msgTokens.indexOf(t) !== -1 || msgLower.indexOf(t) !== -1) hits++;
        });
        let score = hits / seen.size;
        // Strong boost when the whole saved question appears in the message
        if (msgLower.indexOf(String(qa.q).toLowerCase()) !== -1) score += 1;
        return score;
    }
    function qaSuggestFor(text) {
        if (!qaBox || !qaListEl || !QA_LIST.length || !text) return;
        const msgLower  = String(text).toLowerCase();
        const msgTokens = qaTokens(text);
        const ranked = QA_LIST
            .map(function (qa) { return { qa: qa, s: qaScore(msgTokens, msgLower, qa) }; })
            .filter(function (r) { return r.s >= 0.34; })
            .sort(function (a, b) { return b.s - a.s; })
            .slice(0, 3);
        qaListEl.innerHTML = '';
        if (!ranked.length) { qaBox.classList.add('d-none'); return; }
        ranked.forEach(function (r) {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 border rounded-3 px-2 py-1 qa-chip';
            row.innerHTML =
                '<div class="flex-grow-1 overflow-hidden" style="cursor:pointer" title="Click to insert into the reply box">' +
                '<div class="fw-semibold text-truncate" style="font-size:.72rem;color:#6f42c1">' + escHtml(r.qa.q) + '</div>' +
                '<div class="text-muted text-truncate" style="font-size:.72rem">' + escHtml(r.qa.a) + '</div>' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0 qa-insert" title="Insert into reply box"><i class="fas fa-pen"></i></button>' +
                '<button type="button" class="btn btn-sm text-white flex-shrink-0 qa-send" style="background:#1877F2" title="Send this answer now"><i class="fas fa-paper-plane"></i></button>';
            function qaInsert() {
                const taEl = document.getElementById('fb_reply');
                if (taEl) { taEl.value = r.qa.a; pendingQaId = r.qa.id; taEl.focus(); }
            }
            row.querySelector('.flex-grow-1').addEventListener('click', qaInsert);
            row.querySelector('.qa-insert').addEventListener('click', qaInsert);
            row.querySelector('.qa-send').addEventListener('click', function () {
                qaInsert();
                const f = document.getElementById('reply-form');
                if (f) { f.requestSubmit ? f.requestSubmit() : f.dispatchEvent(new Event('submit', { cancelable: true })); }
            });
            qaListEl.appendChild(row);
        });
        qaBox.classList.remove('d-none');
    }
    if (qaClose) qaClose.addEventListener('click', function () { qaBox.classList.add('d-none'); });

    // ── AJAX polling every 4 seconds (new messages + seen receipts) ──
    setInterval(function () {
        fetch('?contact_id=<?= $contact_id ?>&ajax=poll&after_id=' + lastId, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                let gotIncoming = false;
                (d.messages || []).forEach(function (m) {
                    if (m.id <= lastId) return;
                    if (document.querySelector('[data-id="' + m.id + '"]')) { lastId = Math.max(lastId, m.id); return; }
                    removeEmptyPlaceholder();
                    thread.appendChild(renderMsg(m));
                    lastId = m.id;
                    if (m.direction === 'in') gotIncoming = true;
                });
                if (gotIncoming) {
                    scrollBottom(); chime();
                    const lastIn = (d.messages || []).filter(function (m) { return m.direction === 'in' && m.text; }).pop();
                    if (lastIn) qaSuggestFor(lastIn.text);
                }
                (d.seen_ids || []).forEach(function (id) {
                    const st = document.querySelector('.msg-status[data-msg-id="' + id + '"]');
                    if (st && !st.querySelector('.fa-check-double') && !st.querySelector('.fa-exclamation-circle')) {
                        st.innerHTML = '<i class="fas fa-check-double" style="color:#1877F2" title="Seen by customer"></i>';
                    }
                });
            })
            .catch(function () { /* network hiccup – retry next tick */ });
    }, 4000);

    // ── AJAX send with optimistic status ──
    const form = document.getElementById('reply-form');
    const ta   = document.getElementById('fb_reply');
    if (form && ta) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const text = ta.value.trim();
            if (!text) return;
            removeEmptyPlaceholder();
            const bubble = renderMsg({ direction: 'out', text: text, status: 'sending', sender: STAFF_NAME, time: 'Sending…' });
            thread.appendChild(bubble);
            scrollBottom();
            const fd = new FormData(form);
            fd.set('fb_reply', text);
            if (pendingQaId) { fd.set('qa_id', String(pendingQaId)); pendingQaId = 0; }
            if (qaBox) qaBox.classList.add('d-none');
            ta.value = '';
            ta.focus();
            fetch('?contact_id=<?= $contact_id ?>&ajax=send', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    const icon = bubble.querySelector('.msg-status');
                    const span = bubble.querySelector('span[title], span');
                    if (res.ok) {
                        if (icon) icon.innerHTML = '<i class="fas fa-check" title="Sent"></i>';
                        if (span) span.textContent = STAFF_NAME + ' · Just now';
                        bubble.dataset.id = res.id;
                        if (icon) icon.dataset.msgId = res.id;
                        lastId = Math.max(lastId, res.id);
                    } else {
                        if (icon) icon.innerHTML = '<i class="fas fa-exclamation-circle text-danger" title="Failed"></i>';
                        if (span) span.textContent = STAFF_NAME + ' · Failed';
                        if (res.id) { bubble.dataset.id = res.id; lastId = Math.max(lastId, res.id); }
                        alert(res.error || 'Failed to send message.');
                    }
                })
                .catch(function () {
                    const icon = bubble.querySelector('.msg-status');
                    if (icon) icon.innerHTML = '<i class="fas fa-exclamation-circle text-danger" title="Failed"></i>';
                });
        });

        // Ctrl+Enter to send
        ta.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
            // /shortcut + Tab → expand canned reply
            if (e.key === 'Tab') {
                const v = ta.value.trim();
                if (CANNED[v]) {
                    e.preventDefault();
                    ta.value = CANNED[v];
                }
            }
        });
    }

    // ── Attachment upload (send directly from the ERP) ──
    const attachBtn = document.getElementById('attach-btn');
    const fileInput = document.getElementById('fb_file');
    if (attachBtn && fileInput && form) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) return;
            if (file.size > 25 * 1024 * 1024) { alert('Maximum file size is 25 MB.'); fileInput.value = ''; return; }
            removeEmptyPlaceholder();
            const bubble = renderMsg({ direction: 'out', text: '📎 Uploading ' + file.name + '…', status: 'sending', sender: STAFF_NAME, time: 'Uploading…' });
            thread.appendChild(bubble);
            scrollBottom();
            const fd = new FormData();
            form.querySelectorAll('input[type="hidden"]').forEach(function (inp) { fd.append(inp.name, inp.value); });
            fd.append('fb_file', file);
            fileInput.value = '';
            fetch('?contact_id=<?= $contact_id ?>&ajax=send_file', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.ok && res.message) {
                        bubble.replaceWith(renderMsg(res.message));
                        lastId = Math.max(lastId, res.message.id || 0);
                        scrollBottom();
                    } else {
                        const icon = bubble.querySelector('.msg-status');
                        if (icon) icon.innerHTML = '<i class="fas fa-exclamation-circle text-danger" title="Failed"></i>';
                        alert(res.error || 'Failed to send attachment.');
                    }
                })
                .catch(function () {
                    const icon = bubble.querySelector('.msg-status');
                    if (icon) icon.innerHTML = '<i class="fas fa-exclamation-circle text-danger" title="Failed"></i>';
                });
        });
    }

    // ── Canned reply search (quick panel + manage list) ──
    const cannedSearch = document.getElementById('canned-search');
    if (cannedSearch) {
        cannedSearch.addEventListener('input', function () {
            const q = cannedSearch.value.toLowerCase();
            document.querySelectorAll('.canned-item').forEach(function (it) {
                it.style.display = (it.dataset.search || '').indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }
    const cannedManageSearch = document.getElementById('canned-manage-search');
    if (cannedManageSearch) {
        cannedManageSearch.addEventListener('input', function () {
            const q = cannedManageSearch.value.toLowerCase();
            document.querySelectorAll('.canned-manage-item').forEach(function (it) {
                it.style.display = (it.dataset.search || '').indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    // ── Canned replies quick panel ──
    const cannedToggle = document.getElementById('canned-toggle');
    const cannedPanel  = document.getElementById('canned-panel');
    if (cannedToggle && cannedPanel) {
        cannedToggle.addEventListener('click', function () {
            cannedPanel.classList.toggle('d-none');
            if (!cannedPanel.classList.contains('d-none') && cannedSearch) cannedSearch.focus();
        });
        document.querySelectorAll('.canned-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (ta) {
                    ta.value = btn.dataset.body || '';
                    ta.focus();
                }
                cannedPanel.classList.add('d-none');
            });
        });
        document.addEventListener('click', function (e) {
            if (!cannedPanel.contains(e.target) && e.target !== cannedToggle && !cannedToggle.contains(e.target)) {
                cannedPanel.classList.add('d-none');
            }
        });
    }

    // ── Contact list: live search + tabs ──
    const searchEl = document.getElementById('contact-search');
    const items    = document.querySelectorAll('.contact-item');
    let activeTab  = 'all';
    function applyFilter() {
        const q = (searchEl && searchEl.value ? searchEl.value : '').toLowerCase();
        items.forEach(function (it) {
            let show = (it.dataset.name || '').indexOf(q) !== -1;
            if (activeTab === 'unread')   show = show && parseInt(it.dataset.unread || '0', 10) > 0;
            if (activeTab === 'unlinked') show = show && it.dataset.linked === '0';
            it.style.display = show ? '' : 'none';
        });
    }
    if (searchEl) searchEl.addEventListener('input', applyFilter);
    document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            activeTab = b.dataset.tab;
            document.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            applyFilter();
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
