<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();
require_once __DIR__ . '/helpers.php';

$id         = (int)($_GET['id'] ?? 0);
$ticket     = st_get_ticket($id);
$user       = auth_user();
$is_staff   = st_is_staff();
$is_creator = (int)$ticket['created_by'] === (int)$user['id'];
$can_manage = is_super_admin() || $is_creator;

$page_title = 'Ticket #' . $ticket['ticket_number'];

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ── Add comment ───────────────────────────────────────────────────────
    if ($action === 'add_comment') {
        $comment     = trim($_POST['comment'] ?? '');
        $is_internal = $is_staff && !empty($_POST['is_internal']);

        if ($comment === '') {
            flash_set('error', 'Comment cannot be empty.');
        } else {
            $pdo = db();
            $pdo->prepare(
                'INSERT INTO support_ticket_comments (ticket_id, comment, is_internal, created_by)
                 VALUES (?,?,?,?)'
            )->execute([$id, $comment, $is_internal ? 1 : 0, $user['id']]);
            $comment_id = (int)$pdo->lastInsertId();

            // Comment attachments
            if (!empty($_FILES['comment_files']['name'][0])) {
                foreach ($_FILES['comment_files']['tmp_name'] as $i => $tmp) {
                    if ($_FILES['comment_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $file = [
                        'name'     => $_FILES['comment_files']['name'][$i],
                        'tmp_name' => $tmp,
                        'error'    => $_FILES['comment_files']['error'][$i],
                        'size'     => $_FILES['comment_files']['size'][$i],
                        'type'     => $_FILES['comment_files']['type'][$i],
                    ];
                    $stored = st_upload_file($file);
                    if ($stored) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $pdo->prepare(
                            'INSERT INTO support_ticket_comment_attachments
                               (comment_id, original_name, stored_name, mime_type, file_size)
                             VALUES (?,?,?,?,?)'
                        )->execute([
                            $comment_id, $file['name'], $stored,
                            $finfo->file(UPLOAD_DIR . '/support-tickets/' . $stored),
                            $file['size'],
                        ]);
                    }
                }
            }

            $pdo->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$id]);

            $already_notified = [];

            // Notify ticket creator (only for public comments, not self)
            if (!$is_internal && (int)$ticket['created_by'] !== (int)$user['id']) {
                $cr = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $cr->execute([$ticket['created_by']]);
                $creator = $cr->fetch();
                if ($creator) {
                    st_notify_comment_added($ticket, $creator, $user, $comment);
                    $already_notified[] = $creator['email'];
                }
            }
            // Notify assigned staff (if not the commenter)
            if ($ticket['assigned_to'] && (int)$ticket['assigned_to'] !== (int)$user['id']) {
                $as = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $as->execute([$ticket['assigned_to']]);
                $assignee = $as->fetch();
                if ($assignee && !in_array($assignee['email'], $already_notified, true)) {
                    st_notify_comment_added($ticket, $assignee, $user, $comment);
                    $already_notified[] = $assignee['email'];
                }
            }

            // Notify IT admins + public submitter (non-internal only)
            if (!$is_internal) {
                st_notify_comment_to_admins($ticket, $user, $comment, $already_notified);
            }

            // Handle @mention notifications
            st_notify_mentions($ticket, $user, $comment);

            flash_set('success', 'Comment posted.');
        }
        redirect(APP_URL . '/support-tickets/view.php?id=' . $id . '#comments');
    }

    // ── Update status (staff only) ────────────────────────────────────────
    if ($action === 'update_status' && $is_staff) {
        $new_status     = $_POST['status'] ?? '';
        $valid_statuses = ['Open','In Progress','Pending','Resolved','Closed','Reopened'];
        if (in_array($new_status, $valid_statuses, true)) {
            $old_status = $ticket['status'];

            if ($new_status === 'Resolved' && $old_status !== 'Resolved') {
                db()->prepare(
                    "UPDATE support_tickets SET status = ?, resolved_at = NOW() WHERE id = ?"
                )->execute([$new_status, $id]);
            } elseif ($new_status === 'Closed' && $old_status !== 'Closed') {
                db()->prepare(
                    "UPDATE support_tickets SET status = ?, closed_at = NOW() WHERE id = ?"
                )->execute([$new_status, $id]);
            } elseif ($new_status === 'Reopened') {
                db()->prepare(
                    "UPDATE support_tickets SET status = ?, resolved_at = NULL, closed_at = NULL WHERE id = ?"
                )->execute([$new_status, $id]);
            } else {
                db()->prepare(
                    "UPDATE support_tickets SET status = ? WHERE id = ?"
                )->execute([$new_status, $id]);
            }

            // Notify creator of status change
            $cr = db()->prepare('SELECT * FROM users WHERE id = ?');
            $cr->execute([$ticket['created_by']]);
            $creator = $cr->fetch() ?: null;
            if ($old_status !== $new_status) {
                $updated = array_merge($ticket, ['status' => $new_status]);
                st_notify_status_changed($updated, $creator, $old_status, $new_status);
            }
            flash_set('success', 'Status updated to <strong>' . h($new_status) . '</strong>.');
        }
        redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
    }

    // ── Assign ticket (staff only) ────────────────────────────────────────
    if ($action === 'assign' && $is_staff) {
        $assign_to = (int)($_POST['assign_to'] ?? 0);
        if ($assign_to > 0) {
            $au = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
            $au->execute([$assign_to]);
            $assignee = $au->fetch();
            if ($assignee) {
                db()->prepare(
                    "UPDATE support_tickets
                     SET assigned_to = ?,
                         status = CASE WHEN status = 'Open' THEN 'In Progress' ELSE status END
                     WHERE id = ?"
                )->execute([$assign_to, $id]);

                $cr = db()->prepare('SELECT * FROM users WHERE id = ?');
                $cr->execute([$ticket['created_by']]);
                $creator = $cr->fetch();
                st_notify_assigned($ticket, $assignee, $creator ?: $user);
                flash_set('success', 'Ticket assigned to <strong>' . h($assignee['full_name']) . '</strong>.');
            }
        } else {
            db()->prepare('UPDATE support_tickets SET assigned_to = NULL WHERE id = ?')->execute([$id]);
            flash_set('success', 'Assignment cleared.');
        }
        redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
    }

    // ── Reopen (creator only, ticket must be Resolved/Closed) ─────────────
    if ($action === 'reopen' && $is_creator) {
        if (in_array($ticket['status'], ['Resolved','Closed'], true)) {
            db()->prepare(
                "UPDATE support_tickets SET status = 'Reopened', resolved_at = NULL, closed_at = NULL WHERE id = ?"
            )->execute([$id]);
            flash_set('success', 'Ticket reopened.');
        }
        redirect(APP_URL . '/support-tickets/view.php?id=' . $id);
    }
}

// ── Reload ticket after any updates ──────────────────────────────────────────
$ticket   = st_get_ticket($id);
$overdue  = st_is_overdue($ticket);

// ── Comments ──────────────────────────────────────────────────────────────────
$comments_sql =
    'SELECT c.*, u.full_name AS author_name
     FROM support_ticket_comments c
     JOIN users u ON u.id = c.created_by
     WHERE c.ticket_id = ?'
    . (!$is_staff ? ' AND c.is_internal = 0' : '')
    . ' ORDER BY c.created_at ASC';
$comments_stmt = db()->prepare($comments_sql);
$comments_stmt->execute([$id]);
$comments = $comments_stmt->fetchAll();

// Comment attachments (batch fetch)
$comment_attachments = [];
if ($comments) {
    $cids         = array_column($comments, 'id');
    $placeholders = implode(',', array_fill(0, count($cids), '?'));
    $ca_stmt      = db()->prepare(
        "SELECT * FROM support_ticket_comment_attachments WHERE comment_id IN ({$placeholders})"
    );
    $ca_stmt->execute($cids);
    foreach ($ca_stmt->fetchAll() as $ca) {
        $comment_attachments[$ca['comment_id']][] = $ca;
    }
}

// ── Ticket attachments ────────────────────────────────────────────────────────
$att_stmt = db()->prepare(
    'SELECT a.*, u.full_name AS uploader_name
     FROM support_ticket_attachments a
     JOIN users u ON u.id = a.uploaded_by
     WHERE a.ticket_id = ?
     ORDER BY a.uploaded_at DESC'
);
$att_stmt->execute([$id]);
$attachments = $att_stmt->fetchAll();

// ── Tagged users ──────────────────────────────────────────────────────────────
$tags_stmt = db()->prepare(
    'SELECT ut.*, u.full_name
     FROM support_ticket_user_tags ut
     JOIN users u ON u.id = ut.user_id
     WHERE ut.ticket_id = ?'
);
$tags_stmt->execute([$id]);
$tagged_users = $tags_stmt->fetchAll();

// ── All users for assign dropdown ─────────────────────────────────────────────
$all_users_for_assign = [];
if ($is_staff) {
    $all_users_for_assign = db()->query(
        'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name'
    )->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php $flash_s = flash_get('success'); $flash_e = flash_get('error'); ?>
<?php if ($flash_s): ?>
<div class="alert alert-success alert-dismissible fade show mb-3"><?= $flash_s ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($flash_e): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3"><?= h($flash_e) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Breadcrumb + action buttons -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/support-tickets/index.php">IT Support</a></li>
            <li class="breadcrumb-item active"><?= h($ticket['ticket_number']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($can_manage): ?>
        <a href="<?= APP_URL ?>/support-tickets/edit.php?id=<?= $id ?>"
           class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>

        <?php if (in_array($ticket['status'], ['Resolved','Closed'], true) && $is_creator): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reopen">
            <button class="btn btn-sm btn-outline-warning" style="border-radius:8px;">
                <i class="fas fa-redo me-1"></i> Reopen
            </button>
        </form>
        <?php endif; ?>

        <?php if ($can_manage): ?>
        <form method="POST" action="<?= APP_URL ?>/support-tickets/delete.php"
              onsubmit="return confirm('Permanently delete this ticket and all its data?');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

    <!-- ── Left column: ticket body + comments ──────────────────────────── -->
    <div class="col-lg-8">

        <!-- Ticket header / description -->
        <div class="card mb-4 <?= $overdue ? 'border-danger' : '' ?>" style="border-radius:12px;">
            <div class="card-body p-4">
                <div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
                    <span class="text-muted fw-semibold" style="font-size:.8rem;"><?= h($ticket['ticket_number']) ?></span>
                    <?= st_status_badge($ticket['status']) ?>
                    <?= st_priority_badge($ticket['priority']) ?>
                    <span class="badge bg-light text-dark border" style="font-size:.75rem;"><?= h($ticket['category']) ?></span>
                    <?php if ($overdue): ?>
                    <span class="badge bg-danger">OVERDUE</span>
                    <?php endif; ?>
                </div>
                <h4 class="fw-semibold mb-3"><?= h($ticket['title']) ?></h4>
                <div style="line-height:1.75;"><?= $ticket['description'] ?></div>
            </div>
        </div>

        <!-- Attachments -->
        <?php if ($attachments): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-paperclip me-2 text-muted"></i>Attachments (<?= count($attachments) ?>)
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <div class="d-flex flex-wrap gap-2">
                <?php foreach ($attachments as $att): ?>
                    <?php $ext = strtolower(pathinfo($att['stored_name'], PATHINFO_EXTENSION)); ?>
                    <a href="<?= h(UPLOAD_URL . '/support-tickets/' . $att['stored_name']) ?>"
                       download="<?= h($att['original_name']) ?>" target="_blank"
                       class="d-flex align-items-center gap-2 text-decoration-none text-dark border rounded p-2"
                       style="border-radius:8px !important;font-size:.8rem;max-width:220px;background:#f8f9fa;">
                        <i class="<?= st_file_icon($ext) ?> flex-shrink-0"></i>
                        <span class="text-truncate"><?= h($att['original_name']) ?></span>
                        <span class="text-muted ms-auto flex-shrink-0" style="font-size:.72rem;"><?= st_format_size($att['file_size']) ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Comments -->
        <div id="comments" class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-comments me-2 text-muted"></i>Comments (<?= count($comments) ?>)
                </h6>
            </div>
            <div class="card-body p-0">

                <?php if (empty($comments)): ?>
                <div class="px-4 py-4 text-center text-muted" style="font-size:.875rem;">
                    No comments yet. Be the first to add one.
                </div>
                <?php endif; ?>

                <?php foreach ($comments as $comment): ?>
                <?php $is_own_comment = (int)$comment['created_by'] === (int)$user['id']; ?>
                <div class="px-4 py-3 border-bottom <?= $comment['is_internal'] ? 'bg-warning bg-opacity-10' : '' ?>">
                    <div class="d-flex align-items-start gap-3">
                        <!-- Avatar -->
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:36px;height:36px;font-size:.8rem;font-weight:700;">
                            <?= strtoupper(substr($comment['author_name'], 0, 1)) ?>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <strong style="font-size:.875rem;"><?= h($comment['author_name']) ?></strong>
                                <?php if ($comment['is_internal']): ?>
                                <span class="badge bg-warning text-dark" style="font-size:.65rem;">
                                    <i class="fas fa-lock me-1"></i>Internal Note
                                </span>
                                <?php endif; ?>
                                <span class="text-muted" style="font-size:.75rem;">
                                    <?= date('M d, Y \a\t H:i', strtotime($comment['created_at'])) ?>
                                </span>
                                <?php if ($is_own_comment || is_super_admin()): ?>
                                <form method="POST" action="<?= APP_URL ?>/support-tickets/comment-delete.php"
                                      class="ms-auto" onsubmit="return confirm('Delete this comment?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                    <input type="hidden" name="ticket_id"  value="<?= $id ?>">
                                    <button class="btn btn-sm p-0 text-danger border-0" style="font-size:.75rem;" title="Delete comment">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <div style="line-height:1.65;font-size:.875rem;white-space:pre-wrap;"><?= st_render_mentions($comment['comment']) ?></div>
                            <!-- Comment attachments -->
                            <?php if (!empty($comment_attachments[$comment['id']])): ?>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php foreach ($comment_attachments[$comment['id']] as $ca): ?>
                                <?php $cext = strtolower(pathinfo($ca['stored_name'], PATHINFO_EXTENSION)); ?>
                                <a href="<?= h(UPLOAD_URL . '/support-tickets/' . $ca['stored_name']) ?>"
                                   download="<?= h($ca['original_name']) ?>" target="_blank"
                                   class="d-flex align-items-center gap-1 text-decoration-none text-dark border rounded px-2 py-1"
                                   style="border-radius:6px !important;font-size:.75rem;background:#f8f9fa;">
                                    <i class="<?= st_file_icon($cext) ?>"></i>
                                    <span class="text-truncate" style="max-width:130px;"><?= h($ca['original_name']) ?></span>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Add comment form -->
                <div class="px-4 py-4 border-top">
                    <h6 class="fw-semibold mb-3" style="font-size:.875rem;">
                        <i class="fas fa-reply me-1 text-muted"></i> Add Comment
                    </h6>
                    <form method="POST" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_comment">
                        <div class="mb-2">
                             <textarea name="comment" class="form-control" rows="4"
                                       placeholder="Write your comment… Use @username to mention someone" style="border-radius:10px;"></textarea>
                             <div class="form-text">Use @username to mention and notify a specific user.</div>
                        </div>
                        <div class="mb-2">
                            <input type="file" name="comment_files[]" class="form-control form-control-sm" multiple
                                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt"
                                   style="border-radius:8px;">
                        </div>
                        <?php if ($is_staff): ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_internal" name="is_internal" value="1">
                            <label class="form-check-label" for="is_internal" style="font-size:.875rem;">
                                <i class="fas fa-lock me-1 text-warning"></i>
                                Internal note <span class="text-muted">(only visible to IT staff)</span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-sm" style="border-radius:8px;">
                            <i class="fas fa-paper-plane me-1"></i> Post Comment
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Right column: meta / actions ─────────────────────────────────── -->
    <div class="col-lg-4">

        <!-- Ticket meta -->
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-info-circle me-2 text-muted"></i>Ticket Info
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:.875rem;">
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium" style="width:110px;">Status</td>
                        <td class="py-2"><?= st_status_badge($ticket['status']) ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Priority</td>
                        <td class="py-2"><?= st_priority_badge($ticket['priority']) ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Category</td>
                        <td class="py-2"><?= h($ticket['category']) ?></td>
                    </tr>
                    <?php if ($ticket['department']): ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Department</td>
                        <td class="py-2"><?= h($ticket['department']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($ticket['user_type'])): ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">User Type</td>
                        <td class="py-2"><span class="badge bg-secondary"><?= h($ticket['user_type']) ?></span></td>
                    </tr>
                    <?php if ($ticket['user_type'] === 'Student'): ?>
                        <?php if (!empty($ticket['student_id'])): ?>
                        <tr>
                            <td class="px-4 py-2 text-muted fw-medium">Student ID</td>
                            <td class="py-2"><?= h($ticket['student_id']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($ticket['student_department'])): ?>
                        <tr>
                            <td class="px-4 py-2 text-muted fw-medium">S. Department</td>
                            <td class="py-2"><?= h($ticket['student_department']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($ticket['student_program'])): ?>
                        <tr>
                            <td class="px-4 py-2 text-muted fw-medium">Program</td>
                            <td class="py-2"><?= h($ticket['student_program']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($ticket['student_batch'])): ?>
                        <tr>
                            <td class="px-4 py-2 text-muted fw-medium">Batch</td>
                            <td class="py-2"><?= h($ticket['student_batch']) ?></td>
                        </tr>
                        <?php endif; ?>
                    <?php elseif ($ticket['user_type'] === 'Faculty' || $ticket['user_type'] === 'Administrative Employee'): ?>
                        <?php if (!empty($ticket['student_department'])): ?>
                        <tr>
                            <td class="px-4 py-2 text-muted fw-medium">Department</td>
                            <td class="py-2"><?= h($ticket['student_department']) ?></td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($ticket['submitter_phone'])): ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Phone</td>
                        <td class="py-2"><?= h($ticket['submitter_phone']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Submitted</td>
                        <td class="py-2" style="font-size:.82rem;"><?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">By</td>
                        <td class="py-2"><?= h($ticket['creator_name']) ?></td>
                    </tr>
                    <?php if ($ticket['assignee_name']): ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Assigned to</td>
                        <td class="py-2"><?= h($ticket['assignee_name']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Deadline</td>
                        <td class="py-2 <?= $overdue ? 'text-danger fw-semibold' : '' ?>" style="font-size:.82rem;">
                            <?php if ($ticket['deadline']): ?>
                                <?= date('M d, Y H:i', strtotime($ticket['deadline'])) ?>
                                <?php if ($overdue): ?>
                                <span class="badge bg-danger ms-1" style="font-size:.6rem;">OVERDUE</span>
                                <?php else: ?>
                                <?php
                                    $diff_h = (int)((strtotime($ticket['deadline']) - time()) / 3600);
                                    $diff_d = floor($diff_h / 24);
                                    if ($diff_d > 0) echo '<br><small class="text-muted">' . $diff_d . 'd remaining</small>';
                                    elseif ($diff_h > 0) echo '<br><small class="text-warning fw-semibold">' . $diff_h . 'h remaining</small>';
                                ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($ticket['resolved_at']): ?>
                    <tr>
                        <td class="px-4 py-2 text-muted fw-medium">Resolved</td>
                        <td class="py-2" style="font-size:.82rem;"><?= date('M d, Y H:i', strtotime($ticket['resolved_at'])) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Update status (staff only) -->
        <?php if ($is_staff): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-exchange-alt me-2 text-muted"></i>Update Status
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="form-select mb-2" style="border-radius:8px;">
                        <?php foreach (['Open','In Progress','Pending','Resolved','Closed','Reopened'] as $s): ?>
                        <option value="<?= $s ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-check me-1"></i> Update Status
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assign ticket (staff only) -->
        <?php if ($is_staff): ?>
        <div class="card mb-4" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-user-tag me-2 text-muted"></i>Assign Ticket
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="assign">
                    <select name="assign_to" class="form-select mb-2" style="border-radius:8px;">
                        <option value="0">— Unassigned —</option>
                        <?php foreach ($all_users_for_assign as $au): ?>
                        <option value="<?= $au['id'] ?>"
                            <?= (int)$ticket['assigned_to'] === (int)$au['id'] ? 'selected' : '' ?>>
                            <?= h($au['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary w-100" style="border-radius:8px;">
                        <i class="fas fa-user-check me-1"></i> Assign
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tagged users -->
        <?php if ($tagged_users): ?>
        <div class="card" style="border-radius:12px;">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold">
                    <i class="fas fa-tags me-2 text-muted"></i>Tagged Users
                </h6>
            </div>
            <div class="card-body px-4 py-3">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($tagged_users as $tu): ?>
                    <span class="badge bg-light text-dark border" style="font-size:.8rem;padding:5px 10px;border-radius:20px;">
                        <i class="fas fa-user me-1 text-muted"></i><?= h($tu['full_name']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
