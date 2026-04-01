<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

if (!lib_is_circulation_staff()) {
    flash_set('error', 'You do not have permission to process returns.');
    redirect(APP_URL . '/library/circulation/index.php');
}

$db   = db();
$user = auth_user();

// ── POST: Return or Renew ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = trim($_POST['action']   ?? '');
    $circ_id = (int)($_POST['circ_id'] ?? 0);

    if (!$circ_id) {
        flash_set('error', 'Invalid circulation ID.');
        redirect(APP_URL . '/library/circulation/return.php');
    }

    $circ_stmt = $db->prepare(
        'SELECT c.*,
                b.title AS book_title, b.id AS book_id,
                cp.barcode, cp.copy_number,
                m.member_code, m.member_type,
                COALESCE(s.full_name, u.full_name) AS member_name
         FROM library_circulation c
         JOIN library_book_copies cp ON cp.id = c.copy_id
         JOIN library_books b ON b.id = cp.book_id
         JOIN library_members m ON m.id = c.member_id
         LEFT JOIN students s ON s.id = m.student_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE c.id = ?'
    );
    $circ_stmt->execute([$circ_id]);
    $circ = $circ_stmt->fetch();

    if (!$circ) {
        flash_set('error', 'Circulation record not found.');
        redirect(APP_URL . '/library/circulation/return.php');
    }

    if ($action === 'return') {
        if (!in_array($circ['status'], ['Issued', 'Overdue'])) {
            flash_set('error', 'This book is not currently issued.');
            redirect(APP_URL . '/library/circulation/return.php');
        }

        try {
            $db->beginTransaction();

            $fine_amount = lib_calculate_fine($circ_id);

            // Update circulation
            $db->prepare(
                "UPDATE library_circulation
                 SET return_date=NOW(), status='Returned', returned_to=?
                 WHERE id=?"
            )->execute([$user['id'], $circ_id]);

            // Mark copy available
            $db->prepare('UPDATE library_book_copies SET is_available=1 WHERE id=?')
               ->execute([$circ['copy_id']]);

            // Increase available_copies
            $db->prepare('UPDATE library_books SET available_copies = available_copies + 1 WHERE id=?')
               ->execute([$circ['book_id']]);

            // Insert fine if overdue
            $fine_id = null;
            if ($fine_amount > 0) {
                $days = lib_overdue_days($circ['due_date']);
                $ins  = $db->prepare(
                    "INSERT INTO library_fines
                         (circulation_id, member_id, fine_type, amount, days_overdue, status, notes)
                     VALUES (?, ?, 'Late', ?, ?, 'Unpaid', ?)"
                );
                $ins->execute([
                    $circ_id, $circ['member_id'], $fine_amount, $days,
                    'Auto-generated on return. ' . $days . ' days overdue.',
                ]);
                $fine_id = (int)$db->lastInsertId();
            }

            // Notify first pending reservation for this book
            $db->prepare(
                "UPDATE library_reservations SET status='Available', notified_at=NOW()
                 WHERE book_id=? AND status='Pending'
                 ORDER BY id ASC LIMIT 1"
            )->execute([$circ['book_id']]);

            $db->commit();

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            flash_set('error', 'Database error: ' . $e->getMessage());
            redirect(APP_URL . '/library/circulation/return.php?id=' . $circ_id);
        }

        lib_audit('BOOK_RETURNED', 'circulation', $circ_id,
            $circ['book_title'] . ' ← ' . $circ['member_name'],
            'Copy #' . $circ['copy_number'] .
            ($fine_amount > 0 ? '. Fine: ৳' . number_format($fine_amount, 2) : '')
        );

        $msg = 'Book "' . $circ['book_title'] . '" returned successfully.';
        if ($fine_amount > 0) {
            $msg .= ' Fine of ৳' . number_format($fine_amount, 2) . ' has been recorded.';
        }
        flash_set('success', $msg);
        redirect(APP_URL . '/library/circulation/index.php');

    } elseif ($action === 'renew') {
        $max_renewals = (int)lib_setting('max_renewals', 2);

        if ((int)$circ['renewal_count'] >= $max_renewals) {
            flash_set('error', "Maximum renewals ($max_renewals) reached for this book.");
            redirect(APP_URL . '/library/circulation/return.php?id=' . $circ_id);
        }
        if ($circ['status'] === 'Overdue') {
            flash_set('error', 'Overdue books cannot be renewed. Please return the book and pay any fines.');
            redirect(APP_URL . '/library/circulation/return.php?id=' . $circ_id);
        }

        $borrow_days = $circ['member_type'] === 'Faculty'
            ? (int)lib_setting('borrow_days_faculty', 30)
            : (int)lib_setting('borrow_days_student', 14);

        $new_due = date('Y-m-d H:i:s', strtotime($circ['due_date'] . ' +' . $borrow_days . ' days'));

        try {
            $db->prepare(
                "UPDATE library_circulation SET due_date=?, renewal_count=renewal_count+1 WHERE id=?"
            )->execute([$new_due, $circ_id]);
        } catch (Exception $e) {
            flash_set('error', 'Database error: ' . $e->getMessage());
            redirect(APP_URL . '/library/circulation/return.php?id=' . $circ_id);
        }

        lib_audit('BOOK_RENEWED', 'circulation', $circ_id,
            $circ['book_title'] . ' — ' . $circ['member_name'],
            'New due date: ' . $new_due . '. Renewal #' . ($circ['renewal_count'] + 1)
        );

        flash_set('success', 'Book renewed. New due date: ' . date('d M Y', strtotime($new_due)));
        redirect(APP_URL . '/library/circulation/index.php');
    }
}

// ── GET: Search ───────────────────────────────────────────────────────────────
$search_q    = trim($_GET['q']  ?? '');
$direct_id   = (int)($_GET['id'] ?? 0);
$results     = [];
$direct_circ = null;

if ($direct_id) {
    $s = $db->prepare(
        'SELECT c.*,
                b.title AS book_title, b.isbn, b.id AS book_id,
                cp.barcode, cp.copy_number,
                m.member_code, m.member_type,
                COALESCE(s2.full_name, u.full_name) AS member_name
         FROM library_circulation c
         JOIN library_book_copies cp ON cp.id = c.copy_id
         JOIN library_books b ON b.id = cp.book_id
         JOIN library_members m ON m.id = c.member_id
         LEFT JOIN students s2 ON s2.id = m.student_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE c.id = ?'
    );
    $s->execute([$direct_id]);
    $direct_circ = $s->fetch();
    if ($direct_circ) $results = [$direct_circ];
} elseif ($search_q !== '') {
    $like = '%' . $search_q . '%';
    $s = $db->prepare(
        "SELECT c.*,
                b.title AS book_title, b.isbn, b.id AS book_id,
                cp.barcode, cp.copy_number,
                m.member_code, m.member_type,
                COALESCE(s2.full_name, u.full_name) AS member_name
         FROM library_circulation c
         JOIN library_book_copies cp ON cp.id = c.copy_id
         JOIN library_books b ON b.id = cp.book_id
         JOIN library_members m ON m.id = c.member_id
         LEFT JOIN students s2 ON s2.id = m.student_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE c.status IN ('Issued','Overdue')
           AND (COALESCE(s2.full_name, u.full_name) LIKE ?
                OR m.member_code LIKE ?
                OR cp.barcode LIKE ?
                OR b.title LIKE ?
                OR c.id = ?)
         ORDER BY c.id DESC LIMIT 50"
    );
    $s->execute([$like, $like, $like, $like, (int)$search_q ?: 0]);
    $results = $s->fetchAll();
}

$page_title = 'Return / Renew Book';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/circulation/index.php">Circulation</a></li>
            <li class="breadcrumb-item active">Return / Renew</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/library/circulation/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<!-- Search Form -->
<div class="card mb-4">
    <div class="card-header py-3 px-4">
        <h5 class="mb-0"><i class="fas fa-search me-2 text-primary"></i> Find Issued Book</h5>
    </div>
    <div class="card-body px-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-12 col-md-8">
                <label class="form-label fw-medium">Search by member name/code, barcode, book title, or circulation ID</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" name="q" class="form-control" style="border-radius:0 10px 10px 0;"
                           placeholder="e.g. STU-001, John Doe, 978000…, #45"
                           value="<?= h($search_q) ?>" autofocus>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-search me-1"></i> Search
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<?php if ($search_q !== '' || $direct_id): ?>
<div class="card">
    <div class="card-header py-3 px-4 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Search Results</h6>
        <span class="badge bg-secondary"><?= count($results) ?> found</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($results)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-search fa-2x mb-2 d-block opacity-25"></i>
            No active issues found for "<strong><?= h($search_q ?: $direct_id) ?></strong>".
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.875rem;">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">ID</th>
                        <th>Book</th>
                        <th>Copy</th>
                        <th>Member</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Fine Preview</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r):
                    $fine_preview = lib_calculate_fine($r['id']);
                    $max_r        = (int)lib_setting('max_renewals', 2);
                    $can_renew    = ($r['renewal_count'] < $max_r) && ($r['status'] !== 'Overdue');
                ?>
                <tr class="<?= $r['status'] === 'Overdue' ? 'table-danger' : '' ?>">
                    <td class="px-4 fw-medium">#<?= $r['id'] ?></td>
                    <td>
                        <div class="fw-semibold"><?= h($r['book_title']) ?></div>
                        <?php if ($r['isbn']): ?><div class="text-muted" style="font-size:.78rem;">ISBN: <?= h($r['isbn']) ?></div><?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;">
                        Copy #<?= h($r['copy_number']) ?>
                        <?php if ($r['barcode']): ?><br><span class="text-muted"><?= h($r['barcode']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-medium"><?= h($r['member_name']) ?></div>
                        <div class="text-muted" style="font-size:.78rem;"><?= h($r['member_code']) ?> · <?= h($r['member_type']) ?></div>
                    </td>
                    <td style="font-size:.82rem;"><?= date('d M Y', strtotime($r['issue_date'])) ?></td>
                    <td style="font-size:.82rem;">
                        <?= date('d M Y', strtotime($r['due_date'])) ?>
                        <?php if ($r['status'] === 'Overdue'): ?>
                        <div class="text-danger fw-semibold" style="font-size:.75rem;"><?= lib_overdue_days($r['due_date']) ?>d overdue</div>
                        <?php endif; ?>
                    </td>
                    <td><?= lib_circulation_status_badge($r['status']) ?></td>
                    <td style="font-size:.82rem;">
                        <?php if ($fine_preview > 0): ?>
                            <span class="text-danger fw-semibold">৳<?= number_format($fine_preview, 2) ?></span>
                        <?php else: ?>
                            <span class="text-success">৳0.00</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <!-- Return Button -->
                            <button type="button"
                                    class="btn btn-sm btn-success" style="border-radius:7px;"
                                    onclick="confirmReturn(<?= $r['id'] ?>, '<?= h(addslashes($r['book_title'])) ?>', '<?= h(addslashes($r['member_name'])) ?>', <?= $fine_preview ?>)">
                                <i class="fas fa-undo me-1"></i> Return
                            </button>
                            <!-- Renew Button -->
                            <?php if ($can_renew): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary" style="border-radius:7px;"
                                    onclick="confirmRenew(<?= $r['id'] ?>, '<?= h(addslashes($r['book_title'])) ?>', '<?= $r['renewal_count'] ?>', <?= $max_r ?>)">
                                <i class="fas fa-redo me-1"></i> Renew
                            </button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-secondary" style="border-radius:7px;" disabled
                                    title="<?= $r['status'] === 'Overdue' ? 'Cannot renew overdue book' : 'Max renewals reached' ?>">
                                <i class="fas fa-redo"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Hidden Forms -->
<form method="POST" id="returnForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="return">
    <input type="hidden" name="circ_id" id="returnCircId">
</form>
<form method="POST" id="renewForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="renew">
    <input type="hidden" name="circ_id" id="renewCircId">
</form>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingAction = null;

function confirmReturn(circId, title, member, fine) {
    document.getElementById('confirmModalTitle').textContent = 'Confirm Return';
    let body = `<p>Return <strong>${title}</strong> for <strong>${member}</strong>?</p>`;
    if (fine > 0) {
        body += `<div class="alert alert-warning py-2">
            <i class="fas fa-exclamation-triangle me-1"></i>
            A fine of <strong>৳${parseFloat(fine).toFixed(2)}</strong> will be recorded for this overdue return.
        </div>`;
    }
    document.getElementById('confirmModalBody').innerHTML = body;
    document.getElementById('confirmModalBtn').className = 'btn btn-success';
    document.getElementById('confirmModalBtn').textContent = 'Return Book';
    pendingAction = () => {
        document.getElementById('returnCircId').value = circId;
        document.getElementById('returnForm').submit();
    };
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).show();
}

function confirmRenew(circId, title, currentCount, maxCount) {
    document.getElementById('confirmModalTitle').textContent = 'Confirm Renewal';
    document.getElementById('confirmModalBody').innerHTML =
        `<p>Renew <strong>${title}</strong>?</p>
         <p class="text-muted mb-0">Renewal ${parseInt(currentCount)+1} of ${maxCount}. The due date will be extended.</p>`;
    document.getElementById('confirmModalBtn').className = 'btn btn-primary';
    document.getElementById('confirmModalBtn').textContent = 'Renew';
    pendingAction = () => {
        document.getElementById('renewCircId').value = circId;
        document.getElementById('renewForm').submit();
    };
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).show();
}

document.getElementById('confirmModalBtn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    if (pendingAction) pendingAction();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
