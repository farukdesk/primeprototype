<?php
require_once __DIR__ . '/../includes/auth.php';
require_access('leads');
require_once __DIR__ . '/helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$lead = leads_get($id);

$page_title = 'Lead – ' . $lead['first_name'] . ' ' . $lead['last_name'];
$user       = auth_user();
$is_staff   = leads_is_staff();

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Quick status change ───────────────────────────────────────────────
    if ($action === 'change_status' && $is_staff) {
        $new_status = $_POST['new_status'] ?? '';
        if (in_array($new_status, ['fresh', 'unable_to_reach', 'converted'], true) && $new_status !== $lead['status']) {
            db()->prepare('UPDATE leads SET status=?, updated_by=? WHERE id=?')
                ->execute([$new_status, $user['id'], $id]);
            leads_log($id, 'status_changed', 'status', $lead['status'], $new_status,
                'Status changed by ' . $user['full_name']);
            flash_set('success', 'Status updated to ' . leads_status_label($new_status) . '.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id);
    }

    // ── Add note ──────────────────────────────────────────────────────────
    if ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            flash_set('error', 'Note cannot be empty.');
        } else {
            db()->prepare('INSERT INTO lead_notes (lead_id, user_id, note) VALUES (?,?,?)')
                ->execute([$id, $user['id'], $note]);
            leads_log($id, 'note_added', null, null, null,
                'Note added by ' . $user['full_name']);
            flash_set('success', 'Note added.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#notes');
    }

    // ── Assign user ───────────────────────────────────────────────────────
    if ($action === 'assign_user' && $is_staff) {
        $assign_id = (int)($_POST['assign_user_id'] ?? 0);
        if ($assign_id > 0) {
            $check = db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');
            $check->execute([$assign_id]);
            if ($check->fetch()) {
                db()->prepare('INSERT IGNORE INTO lead_assignments (lead_id, user_id, assigned_by) VALUES (?,?,?)')
                    ->execute([$id, $assign_id, $user['id']]);
                $aname = db()->prepare('SELECT full_name FROM users WHERE id=?');
                $aname->execute([$assign_id]);
                $aname_val = $aname->fetchColumn();
                leads_log($id, 'assigned', 'assignment', null, $aname_val,
                    'Assigned to ' . $aname_val . ' by ' . $user['full_name']);
                flash_set('success', 'User assigned to lead.');
            }
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#assignments');
    }

    // ── Unassign user ─────────────────────────────────────────────────────
    if ($action === 'unassign_user' && $is_staff) {
        $unassign_id = (int)($_POST['unassign_user_id'] ?? 0);
        if ($unassign_id > 0) {
            $aname = db()->prepare('SELECT full_name FROM users WHERE id=?');
            $aname->execute([$unassign_id]);
            $aname_val = $aname->fetchColumn();
            db()->prepare('DELETE FROM lead_assignments WHERE lead_id=? AND user_id=?')
                ->execute([$id, $unassign_id]);
            leads_log($id, 'unassigned', 'assignment', $aname_val, null,
                'Removed assignment of ' . $aname_val . ' by ' . $user['full_name']);
            flash_set('success', 'Assignment removed.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#assignments');
    }

    // ── Add appointment ───────────────────────────────────────────────────
    if ($action === 'add_appointment' && $is_staff) {
        $appt_date   = trim($_POST['appointment_date'] ?? '');
        $appt_time   = trim($_POST['appointment_time'] ?? '') ?: null;
        $appt_purpose= trim($_POST['purpose'] ?? '');
        $appt_notes  = trim($_POST['appt_notes'] ?? '');

        if ($appt_date === '') {
            flash_set('error', 'Appointment date is required.');
        } else {
            db()->prepare(
                'INSERT INTO lead_appointments (lead_id, appointment_date, appointment_time, purpose, notes, created_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$id, $appt_date, $appt_time, $appt_purpose ?: null, $appt_notes ?: null, $user['id']]);
            leads_log($id, 'appointment_set', null, null, $appt_date,
                'Campus visit appointment set for ' . $appt_date . ' by ' . $user['full_name']);
            flash_set('success', 'Appointment scheduled.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#appointments');
    }

    // ── Update appointment status ─────────────────────────────────────────
    if ($action === 'update_appointment' && $is_staff) {
        $appt_id    = (int)($_POST['appt_id'] ?? 0);
        $appt_status= in_array($_POST['appt_status'] ?? '', ['scheduled','completed','cancelled','no_show'], true)
                      ? $_POST['appt_status'] : 'scheduled';
        if ($appt_id > 0) {
            $old_status_q = db()->prepare('SELECT status FROM lead_appointments WHERE id=? AND lead_id=?');
            $old_status_q->execute([$appt_id, $id]);
            $old_appt_status = $old_status_q->fetchColumn();
            db()->prepare('UPDATE lead_appointments SET status=? WHERE id=? AND lead_id=?')
                ->execute([$appt_status, $appt_id, $id]);
            leads_log($id, 'appointment_updated', 'appointment_status', $old_appt_status, $appt_status,
                'Appointment status changed by ' . $user['full_name']);
            flash_set('success', 'Appointment updated.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#appointments');
    }

    // ── Send Facebook reply ───────────────────────────────────────────────
    if ($action === 'send_fb_reply' && $is_staff) {
        $reply_text  = trim($_POST['fb_reply'] ?? '');
        $contact_id  = (int)($_POST['fb_contact_id'] ?? 0);
        if ($reply_text === '' || $contact_id <= 0) {
            flash_set('error', 'Message cannot be empty.');
            redirect(APP_URL . '/leads/view.php?id=' . $id . '#facebook');
        }
        $cstmt = db()->prepare('SELECT * FROM lead_fb_contacts WHERE id = ? AND lead_id = ?');
        $cstmt->execute([$contact_id, $id]);
        $fc = $cstmt->fetch();
        if (!$fc) {
            flash_set('error', 'Facebook contact not found for this lead.');
            redirect(APP_URL . '/leads/view.php?id=' . $id . '#facebook');
        }
        $sent = leads_fb_send($fc['psid'], $reply_text);
        if ($sent) {
            db()->prepare(
                'INSERT INTO lead_fb_messages (contact_id, direction, message_text, sent_by)
                 VALUES (?,?,?,?)'
            )->execute([$contact_id, 'out', $reply_text, $user['id']]);
            db()->prepare('UPDATE lead_fb_contacts SET last_message_at=NOW() WHERE id=?')
                ->execute([$contact_id]);
            leads_log($id, 'fb_message_sent', null, null, null,
                'Facebook reply sent by ' . $user['full_name'] . ': ' . mb_substr($reply_text, 0, 100));
            flash_set('success', 'Message sent via Facebook Messenger.');
        } else {
            flash_set('error', 'Failed to send message. Check Facebook credentials in FB Settings.');
        }
        redirect(APP_URL . '/leads/view.php?id=' . $id . '#facebook');
    }
}

// ── Fetch related data ────────────────────────────────────────────────────────
$notes = db()->prepare(
    'SELECT n.*, u.full_name AS author_name
     FROM lead_notes n
     LEFT JOIN users u ON u.id = n.user_id
     WHERE n.lead_id = ? ORDER BY n.created_at DESC'
);
$notes->execute([$id]);
$notes = $notes->fetchAll();

$history = db()->prepare(
    'SELECT h.*, u.full_name AS actor_name
     FROM lead_history h
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.lead_id = ? ORDER BY h.created_at DESC'
);
$history->execute([$id]);
$history = $history->fetchAll();

$assignments = db()->prepare(
    'SELECT la.*, u.full_name, u.username, ab.full_name AS assigned_by_name
     FROM lead_assignments la
     JOIN users u ON u.id = la.user_id
     LEFT JOIN users ab ON ab.id = la.assigned_by
     WHERE la.lead_id = ? ORDER BY la.assigned_at ASC'
);
$assignments->execute([$id]);
$assignments = $assignments->fetchAll();
$assigned_ids = array_column($assignments, 'user_id');

$appointments = db()->prepare(
    'SELECT a.*, u.full_name AS created_by_name
     FROM lead_appointments a
     LEFT JOIN users u ON u.id = a.created_by
     WHERE a.lead_id = ? ORDER BY a.appointment_date ASC, a.appointment_time ASC'
);
$appointments->execute([$id]);
$appointments = $appointments->fetchAll();

$staff_users = db()->query(
    "SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name"
)->fetchAll();

$available_users = array_filter($staff_users, fn($u) => !in_array($u['id'], $assigned_ids));

// ── Facebook contact & messages ───────────────────────────────────────────────
$fb_contact = leads_fb_get_contact_by_lead($id);
$fb_messages = [];
if ($fb_contact) {
    $fm = db()->prepare(
        'SELECT m.*, u.full_name AS sender_name
         FROM lead_fb_messages m
         LEFT JOIN users u ON u.id = m.sent_by
         WHERE m.contact_id = ? ORDER BY m.created_at ASC'
    );
    $fm->execute([$fb_contact['id']]);
    $fb_messages = $fm->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-semibold">
            <i class="fas fa-user-circle me-2 text-primary"></i>
            <?= h($lead['first_name'] . ' ' . $lead['last_name']) ?>
            <span class="ms-2"><?= leads_status_badge($lead['status']) ?></span>
            <span class="ms-1"><?= leads_source_badge($lead['source']) ?></span>
        </h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small"><li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li><li class="breadcrumb-item"><a href="<?= APP_URL ?>/leads/index.php">Leads</a></li><li class="breadcrumb-item active"><?= h($lead['lead_number']) ?></li></ol></nav>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($is_staff): ?>
        <a href="<?= APP_URL ?>/leads/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a>
        <?php endif; ?>
        <?php if (leads_can_delete()): ?>
        <a href="<?= APP_URL ?>/leads/delete.php?id=<?= $id ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this lead permanently?')"><i class="fas fa-trash me-1"></i> Delete</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/leads/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<?= flash_show() ?>

<!-- ── Quick status change ── -->
<?php if ($is_staff): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 px-3 d-flex align-items-center flex-wrap gap-2">
        <span class="text-muted small fw-semibold me-1">Quick Status:</span>
        <?php foreach (['fresh' => ['Fresh','success'], 'unable_to_reach' => ['Unable to Reach','warning'], 'converted' => ['Converted','primary']] as $sv => [$sl, $color]): ?>
        <?php if ($lead['status'] !== $sv): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="new_status" value="<?= $sv ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $color ?>">Mark as <?= $sl ?></button>
        </form>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left: Details -->
    <div class="col-12 col-lg-8">
        <!-- Personal Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-user me-2 text-primary"></i>Personal Information</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6 col-md-3 text-muted small">First Name</div><div class="col-6 col-md-3"><?= h($lead['first_name']) ?></div>
                    <div class="col-6 col-md-3 text-muted small">Last Name</div><div class="col-6 col-md-3"><?= h($lead['last_name']) ?></div>
                    <div class="col-6 col-md-3 text-muted small">Email</div><div class="col-6 col-md-3"><?= $lead['email'] ? '<a href="mailto:' . h($lead['email']) . '">' . h($lead['email']) . '</a>' : '–' ?></div>
                    <div class="col-6 col-md-3 text-muted small">Phone</div><div class="col-6 col-md-3"><?= h($lead['phone']) ?></div>
                    <div class="col-6 col-md-3 text-muted small">Current City</div><div class="col-6 col-md-3"><?= h($lead['current_city'] ?? '–') ?></div>
                    <div class="col-6 col-md-3 text-muted small">Address</div><div class="col-6 col-md-3"><?= h($lead['address'] ?? '–') ?></div>
                </div>
            </div>
        </div>

        <!-- Education Info -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-graduation-cap me-2 text-success"></i>Education Information</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6 col-md-3 text-muted small">Applying For</div><div class="col-6 col-md-3"><?= leads_degree_badge($lead['degree_type']) ?></div>
                    <div class="col-6 col-md-3 text-muted small">Department</div><div class="col-6 col-md-3"><?= h($lead['dept_name'] ?? '–') ?></div>
                    <div class="col-6 col-md-3 text-muted small">Program</div><div class="col-6 col-md-3"><?= h($lead['program_name'] ?? '–') ?></div>
                    <div class="col-6 col-md-3 text-muted small">Preferred Semester</div><div class="col-6 col-md-3"><?= h($lead['preferred_semester'] ?? '–') ?></div>
                    <div class="col-6 col-md-3 text-muted small">Preferred Call Time</div><div class="col-6 col-md-3"><?= $lead['preferred_call_time'] ? '<span class="badge bg-info text-dark"><i class="fas fa-phone-alt me-1"></i>' . h($lead['preferred_call_time']) . '</span>' : '–' ?></div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card border-0 shadow-sm mb-4" id="notes">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="fas fa-sticky-note me-2 text-warning"></i>Staff Notes</span>
                <span class="badge bg-secondary"><?= count($notes) ?></span>
            </div>
            <div class="card-body">
                <?php if ($notes): ?>
                <?php foreach ($notes as $note): ?>
                <div class="border rounded p-3 mb-2 bg-light">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="fw-semibold small"><?= h($note['author_name'] ?? 'Unknown') ?></span>
                            <span class="text-muted small ms-2"><?= date('d M Y, h:i A', strtotime($note['created_at'])) ?></span>
                        </div>
                        <?php if (leads_can_delete() || (int)($note['user_id'] ?? 0) === $user['id']): ?>
                        <a href="<?= APP_URL ?>/leads/note-delete.php?id=<?= $note['id'] ?>&lead_id=<?= $id ?>" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="return confirm('Delete this note?')"><i class="fas fa-trash fa-xs"></i></a>
                        <?php endif; ?>
                    </div>
                    <p class="mb-0 mt-1"><?= nl2br(h($note['note'])) ?></p>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted small mb-2">No notes yet.</p>
                <?php endif; ?>

                <hr>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_note">
                    <label class="form-label fw-semibold small">Add Note</label>
                    <textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Write your note here…"></textarea>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="fas fa-plus me-1"></i> Add Note</button>
                </form>
            </div>
        </div>

        <!-- Appointments -->
        <div class="card border-0 shadow-sm mb-4" id="appointments">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="fas fa-calendar-check me-2 text-info"></i>Campus Visit Appointments</span>
                <span class="badge bg-secondary"><?= count($appointments) ?></span>
            </div>
            <div class="card-body">
                <?php if ($appointments): ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Date</th><th>Time</th><th>Purpose</th><th>Status</th><th>Notes</th><?php if ($is_staff): ?><th>Update</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appt): ?>
                            <tr>
                                <td><?= date('d M Y', strtotime($appt['appointment_date'])) ?></td>
                                <td><?= $appt['appointment_time'] ? date('h:i A', strtotime($appt['appointment_time'])) : '–' ?></td>
                                <td><?= h($appt['purpose'] ?? '–') ?></td>
                                <td><?= leads_appt_status_badge($appt['status']) ?></td>
                                <td class="small text-muted"><?= h($appt['notes'] ?? '') ?></td>
                                <?php if ($is_staff): ?>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <form method="post" class="d-flex gap-1">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_appointment">
                                            <input type="hidden" name="appt_id" value="<?= $appt['id'] ?>">
                                            <select name="appt_status" class="form-select form-select-sm" style="min-width:130px">
                                                <?php foreach (['scheduled','completed','cancelled','no_show'] as $as): ?>
                                                <option value="<?= $as ?>" <?= $appt['status'] === $as ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$as)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                        </form>
                                        <a href="<?= APP_URL ?>/leads/appointment-delete.php?id=<?= $appt['id'] ?>&lead_id=<?= $id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete appointment?')"><i class="fas fa-trash"></i></a>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-3">No appointments scheduled.</p>
                <?php endif; ?>

                <?php if ($is_staff): ?>
                <hr>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_appointment">
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="appointment_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-semibold">Time</label>
                            <input type="time" name="appointment_time" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold">Purpose</label>
                            <input type="text" name="purpose" class="form-control form-control-sm" placeholder="e.g. Campus tour, Counselling">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-semibold">Notes</label>
                            <input type="text" name="appt_notes" class="form-control form-control-sm">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-info btn-sm mt-2"><i class="fas fa-calendar-plus me-1"></i> Schedule Appointment</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Facebook Messages -->
        <div class="card border-0 shadow-sm mb-4" id="facebook">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fab fa-facebook-messenger me-2" style="color:#1877F2"></i>Facebook Messenger</span>
                <?php if ($fb_contact): ?>
                <span class="badge bg-secondary"><?= count($fb_messages) ?> messages</span>
                <?php else: ?>
                <a href="<?= APP_URL ?>/leads/fb-inbox.php" class="btn btn-sm btn-outline-primary"><i class="fab fa-facebook-messenger me-1"></i> FB Inbox</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$fb_contact): ?>
                <p class="text-muted small mb-2">No Facebook contact linked to this lead.</p>
                <p class="text-muted small mb-0">
                    When someone messages your Facebook page, they appear in the
                    <a href="<?= APP_URL ?>/leads/fb-inbox.php">FB Inbox</a>.
                    Open the conversation there and use <strong>Link to Lead</strong> to connect it here.
                </p>
                <?php else: ?>
                <!-- Contact info bar -->
                <div class="d-flex align-items-center gap-3 mb-3 p-2 rounded" style="background:#f0f2f5">
                    <?php if ($fb_contact['fb_picture']): ?>
                    <img src="<?= h($fb_contact['fb_picture']) ?>" class="rounded-circle" width="40" height="40" alt="FB Profile" style="object-fit:cover">
                    <?php else: ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:#1877F2">
                        <i class="fab fa-facebook-messenger text-white"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-semibold small"><?= h($fb_contact['fb_name'] ?? 'Facebook User') ?></div>
                        <div class="text-muted" style="font-size:.72rem">PSID: <?= h($fb_contact['psid']) ?></div>
                        <?php if ($fb_contact['last_message_at']): ?>
                        <div class="text-muted" style="font-size:.72rem">Last message: <?= date('d M Y, h:i A', strtotime($fb_contact['last_message_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="ms-auto">
                        <a href="<?= APP_URL ?>/leads/fb-conversation.php?contact_id=<?= $fb_contact['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-expand-alt me-1"></i> Full View
                        </a>
                    </div>
                </div>

                <!-- Message thread (last 20) -->
                <?php if ($fb_messages): ?>
                <div class="fb-thread mb-3" style="max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:8px">
                    <?php foreach (array_slice($fb_messages, -20) as $fbm): ?>
                    <?php $is_out = $fbm['direction'] === 'out'; ?>
                    <div class="d-flex <?= $is_out ? 'justify-content-end' : 'justify-content-start' ?>">
                        <div class="px-3 py-2 rounded-3 small" style="max-width:75%;<?= $is_out ? 'background:#1877F2;color:#fff' : 'background:#f0f2f5;color:#000' ?>">
                            <?php if ($fbm['message_text']): ?>
                            <?= nl2br(h($fbm['message_text'])) ?>
                            <?php endif; ?>
                            <?php if ($fbm['attachment_type']): ?>
                            <div class="mt-1">
                                <?php if ($fbm['attachment_type'] === 'image' && $fbm['attachment_url']): ?>
                                <a href="<?= h($fbm['attachment_url']) ?>" target="_blank"><img src="<?= h($fbm['attachment_url']) ?>" style="max-width:200px;border-radius:4px" alt="image"></a>
                                <?php else: ?>
                                <span class="badge bg-secondary">[<?= h($fbm['attachment_type']) ?> attachment]</span>
                                <?php if ($fbm['attachment_url']): ?><a href="<?= h($fbm['attachment_url']) ?>" target="_blank" class="ms-1 <?= $is_out ? 'text-white' : 'text-primary' ?>">View</a><?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div style="font-size:.68rem;opacity:.75;margin-top:4px;text-align:<?= $is_out ? 'right' : 'left' ?>">
                                <?= $is_out ? h($fbm['sender_name'] ?? 'Staff') . ' · ' : '' ?><?= date('d M, h:i A', strtotime($fbm['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small mb-3">No messages yet.</p>
                <?php endif; ?>

                <!-- Reply form -->
                <?php if ($is_staff): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_fb_reply">
                    <input type="hidden" name="fb_contact_id" value="<?= $fb_contact['id'] ?>">
                    <div class="input-group">
                        <textarea name="fb_reply" class="form-control form-control-sm" rows="2" placeholder="Type your reply…" required></textarea>
                        <button type="submit" class="btn btn-primary btn-sm" style="background:#1877F2;border-color:#1877F2">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- History -->
        <div class="card border-0 shadow-sm mb-4" id="history">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="fas fa-history me-2 text-secondary"></i>Lead History</span>
                <span class="badge bg-secondary"><?= count($history) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if ($history): ?>
                <div class="timeline p-3">
                    <?php foreach ($history as $h_item): ?>
                    <div class="d-flex gap-3 mb-3">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center" style="width:32px;height:32px">
                                <?php
                                $icons = [
                                    'created'              => 'fas fa-plus text-success',
                                    'updated'              => 'fas fa-edit text-primary',
                                    'status_changed'       => 'fas fa-exchange-alt text-warning',
                                    'assigned'             => 'fas fa-user-check text-info',
                                    'unassigned'           => 'fas fa-user-times text-danger',
                                    'note_added'           => 'fas fa-sticky-note text-warning',
                                    'appointment_set'      => 'fas fa-calendar-plus text-info',
                                    'appointment_updated'  => 'fas fa-calendar-check text-primary',
                                    'fb_message_received'  => 'fab fa-facebook-messenger text-primary',
                                    'fb_message_sent'      => 'fab fa-facebook-messenger text-success',
                                ];
                                $icon = $icons[$h_item['action']] ?? 'fas fa-circle text-muted';
                                ?>
                                <i class="<?= $icon ?> fa-xs"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1">
                            <div class="small fw-semibold"><?= h($h_item['description'] ?? ucfirst(str_replace('_', ' ', $h_item['action']))) ?></div>
                            <?php if ($h_item['field_name'] && ($h_item['old_value'] !== null || $h_item['new_value'] !== null)): ?>
                            <div class="small text-muted">
                                <span class="badge bg-light text-dark border"><?= h($h_item['field_name']) ?></span>
                                <?php if ($h_item['old_value'] !== null): ?>
                                <span class="text-danger ms-1"><del><?= h($h_item['old_value']) ?></del></span>
                                <?php endif; ?>
                                <?php if ($h_item['new_value'] !== null): ?>
                                <span class="text-success ms-1">→ <?= h($h_item['new_value']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size:.75rem"><?= h($h_item['actor_name'] ?? 'System') ?> · <?= date('d M Y, h:i A', strtotime($h_item['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted small p-3 mb-0">No history recorded.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right sidebar -->
    <div class="col-12 col-lg-4">
        <!-- Lead meta -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-info-circle me-2 text-primary"></i>Lead Details</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted small">Lead #</td><td class="fw-semibold"><?= h($lead['lead_number']) ?></td></tr>
                    <tr><td class="text-muted small">Status</td><td><?= leads_status_badge($lead['status']) ?></td></tr>
                    <tr><td class="text-muted small">Source</td><td><?= leads_source_badge($lead['source']) ?></td></tr>
                    <tr><td class="text-muted small">Created By</td><td><?= h($lead['created_by_name'] ?? '–') ?></td></tr>
                    <tr><td class="text-muted small">Created</td><td class="small"><?= date('d M Y, h:i A', strtotime($lead['created_at'])) ?></td></tr>
                    <tr><td class="text-muted small">Last Updated</td><td class="small"><?= date('d M Y, h:i A', strtotime($lead['updated_at'])) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Assignments -->
        <div class="card border-0 shadow-sm mb-4" id="assignments">
            <div class="card-header bg-white fw-semibold"><i class="fas fa-users me-2 text-info"></i>Assigned Staff</div>
            <div class="card-body">
                <?php if ($assignments): ?>
                <?php foreach ($assignments as $asgn): ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <i class="fas fa-user-circle text-muted me-1"></i>
                        <span class="small fw-semibold"><?= h($asgn['full_name']) ?></span>
                        <div class="text-muted" style="font-size:.72rem">Assigned <?= date('d M Y', strtotime($asgn['assigned_at'])) ?></div>
                    </div>
                    <?php if ($is_staff): ?>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unassign_user">
                        <input type="hidden" name="unassign_user_id" value="<?= $asgn['user_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="return confirm('Remove assignment?')"><i class="fas fa-times fa-xs"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <hr>
                <?php else: ?>
                <p class="text-muted small mb-2">No staff assigned yet.</p>
                <?php endif; ?>

                <?php if ($is_staff && !empty($available_users)): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign_user">
                    <div class="input-group input-group-sm">
                        <select name="assign_user_id" class="form-select">
                            <option value="">— Add Staff —</option>
                            <?php foreach ($available_users as $su): ?>
                            <option value="<?= $su['id'] ?>"><?= h($su['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-info">Assign</button>
                    </div>
                </form>
                <?php elseif ($is_staff): ?>
                <p class="text-muted small mb-0">All staff are already assigned.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming appointments summary -->
        <?php
        $today    = date('Y-m-d');
        $upcoming = array_filter($appointments, fn($a) => $a['status'] === 'scheduled' && $a['appointment_date'] >= $today);
        if ($upcoming):
        ?>
        <div class="card border-0 shadow-sm border-start border-4 border-info mb-4">
            <div class="card-body">
                <h6 class="fw-semibold text-info"><i class="fas fa-calendar-alt me-1"></i> Upcoming Visits</h6>
                <?php foreach ($upcoming as $ua): ?>
                <div class="small mb-1">
                    <i class="fas fa-clock text-muted me-1"></i>
                    <strong><?= date('d M Y', strtotime($ua['appointment_date'])) ?></strong>
                    <?= $ua['appointment_time'] ? ' at ' . date('h:i A', strtotime($ua['appointment_time'])) : '' ?>
                    <?= $ua['purpose'] ? ' – ' . h($ua['purpose']) : '' ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
