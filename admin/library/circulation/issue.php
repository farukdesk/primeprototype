<?php
require_once __DIR__ . '/../../../includes/auth.php';
auth_check();
require_once __DIR__ . '/../helpers.php';

if (!lib_is_circulation_staff()) {
    flash_set('error', 'You do not have permission to issue books.');
    redirect(APP_URL . '/library/circulation/index.php');
}

$db   = db();
$user = auth_user();

// ── POST: Process issue ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    save_old($_POST);

    $member_id  = (int)($_POST['member_id']  ?? 0);
    $copy_id    = (int)($_POST['copy_id']    ?? 0);
    $due_date   = trim($_POST['due_date']    ?? '');
    $notes      = trim($_POST['notes']       ?? '');

    $errors = [];

    // Validate member
    if (!$member_id) {
        $errors[] = 'Please select a member.';
    } else {
        $mem_stmt = $db->prepare('SELECT * FROM library_members WHERE id = ? AND is_active = 1');
        $mem_stmt->execute([$member_id]);
        $member = $mem_stmt->fetch();
        if (!$member) $errors[] = 'Member not found or is inactive.';
    }

    // Validate copy
    if (!$copy_id) {
        $errors[] = 'Please select a book copy.';
    } else {
        $copy_stmt = $db->prepare(
            'SELECT cp.*, b.title AS book_title, b.id AS book_id
             FROM library_book_copies cp
             JOIN library_books b ON b.id = cp.book_id
             WHERE cp.id = ? AND cp.is_available = 1'
        );
        $copy_stmt->execute([$copy_id]);
        $copy = $copy_stmt->fetch();
        if (!$copy) $errors[] = 'Selected copy is not available.';
    }

    // Validate due date
    if ($due_date === '') {
        $errors[] = 'Due date is required.';
    } elseif (strtotime($due_date) <= time()) {
        $errors[] = 'Due date must be in the future.';
    }

    // Check borrow limit
    if (!$errors && isset($member)) {
        $borrow_count = lib_member_borrow_count($member_id);
        $limit_key    = $member['member_type'] === 'Faculty' ? 'borrow_limit_faculty' : 'borrow_limit_student';
        $limit        = (int)lib_setting($limit_key, 3);
        if ($borrow_count >= $limit) {
            $errors[] = "Member has reached the borrowing limit ($limit books). Please return an existing book first.";
        }
    }

    // Check duplicate issue for same copy
    if (!$errors && isset($copy)) {
        $dup = $db->prepare(
            "SELECT id FROM library_circulation
             WHERE copy_id = ? AND status IN ('Issued','Overdue') LIMIT 1"
        );
        $dup->execute([$copy_id]);
        if ($dup->fetch()) $errors[] = 'This copy is already issued and not yet returned.';
    }

    if ($errors) {
        $page_errors = $errors;
    } else {
        try {
            $db->beginTransaction();

            // Insert circulation record
            $ins = $db->prepare(
                'INSERT INTO library_circulation
                     (copy_id, book_id, member_id, issued_by, issue_date, due_date, status, notes, renewal_count)
                 VALUES (?, ?, ?, ?, NOW(), ?, "Issued", ?, 0)'
            );
            $ins->execute([$copy_id, $copy['book_id'], $member_id, $user['id'], $due_date, $notes]);
            $circ_id = (int)$db->lastInsertId();

            // Mark copy unavailable
            $db->prepare('UPDATE library_book_copies SET is_available=0 WHERE id=?')->execute([$copy_id]);

            // Decrease available_copies
            $db->prepare('UPDATE library_books SET available_copies = GREATEST(0, available_copies - 1) WHERE id=?')
               ->execute([$copy['book_id']]);

            // Fulfill pending reservation for this member + book if exists
            $res_stmt = $db->prepare(
                "UPDATE library_reservations SET status='Fulfilled'
                 WHERE book_id=? AND member_id=? AND status='Pending'
                 ORDER BY id ASC LIMIT 1"
            );
            $res_stmt->execute([$copy['book_id'], $member_id]);

            $db->commit();

            lib_audit('BOOK_ISSUED', 'circulation', $circ_id,
                $copy['book_title'] . ' → ' . ($member['name'] ?? ''),
                'Copy #' . $copy['copy_number'] . ' issued. Due: ' . $due_date
            );

            clear_old();
            flash_set('success', 'Book "' . $copy['book_title'] . '" issued successfully. Due: ' . date('d M Y', strtotime($due_date)));
            redirect(APP_URL . '/library/circulation/index.php');
        } catch (Exception $e) {
            $db->rollBack();
            $page_errors = ['Database error: ' . $e->getMessage()];
        }
    }
}

// ── GET: Load form data ───────────────────────────────────────────────────────
$active_members = $db->query(
    "SELECT m.id, m.member_code, m.member_type,
            COALESCE(s.full_name, u.full_name, m.name) AS display_name
     FROM library_members m
     LEFT JOIN students s ON s.id = m.student_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE m.is_active = 1
     ORDER BY display_name ASC"
)->fetchAll();

$available_books = $db->query(
    'SELECT id, title, isbn, available_copies
     FROM library_books WHERE available_copies > 0 ORDER BY title ASC'
)->fetchAll();

$page_errors = $page_errors ?? [];

$page_title = 'Issue Book';
require_once __DIR__ . '/../../../includes/header.php';
?>

<!-- Breadcrumb -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/circulation/index.php">Circulation</a></li>
            <li class="breadcrumb-item active">Issue Book</li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/library/circulation/index.php" class="btn btn-outline-secondary btn-sm" style="border-radius:10px;">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<?php if ($page_errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="fas fa-exclamation-circle me-1"></i>
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-1 ps-3">
        <?php foreach ($page_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card">
            <div class="card-header py-3 px-4">
                <h5 class="mb-0"><i class="fas fa-book me-2 text-primary"></i> Issue Book to Member</h5>
            </div>
            <div class="card-body px-4 py-4">
                <form method="POST" id="issueForm">
                    <?= csrf_field() ?>

                    <!-- Member Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="member_id">
                            <i class="fas fa-user me-1 text-muted"></i> Member <span class="text-danger">*</span>
                        </label>
                        <select name="member_id" id="member_id" class="form-select" required style="border-radius:10px;">
                            <option value="">— Select Member —</option>
                            <?php foreach ($active_members as $mem): ?>
                            <option value="<?= $mem['id'] ?>"
                                    data-type="<?= h($mem['member_type']) ?>"
                                    <?= old('member_id') == $mem['id'] ? 'selected' : '' ?>>
                                <?= h($mem['display_name']) ?>
                                (<?= h($mem['member_code']) ?> · <?= h($mem['member_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="memberInfo" class="mt-2" style="display:none;">
                            <div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem;" id="memberInfoText"></div>
                        </div>
                    </div>

                    <!-- Book Selection -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="book_id">
                            <i class="fas fa-book me-1 text-muted"></i> Book <span class="text-danger">*</span>
                        </label>
                        <select name="book_id" id="book_id" class="form-select" style="border-radius:10px;">
                            <option value="">— Select Book —</option>
                            <?php foreach ($available_books as $bk): ?>
                            <option value="<?= $bk['id'] ?>"
                                    <?= old('book_id') == $bk['id'] ? 'selected' : '' ?>>
                                <?= h($bk['title']) ?>
                                <?= $bk['isbn'] ? '(ISBN: ' . h($bk['isbn']) . ')' : '' ?>
                                — <?= $bk['available_copies'] ?> available
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only books with available copies are listed.</div>
                    </div>

                    <!-- Copy Selection -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="copy_id">
                            <i class="fas fa-copy me-1 text-muted"></i> Specific Copy <span class="text-danger">*</span>
                        </label>
                        <select name="copy_id" id="copy_id" class="form-select" required style="border-radius:10px;">
                            <option value="">— Select book first —</option>
                        </select>
                        <div class="form-text">
                            Or enter barcode directly:
                            <input type="text" id="barcodeInput" class="form-control form-control-sm d-inline-block ms-1"
                                   style="width:180px;border-radius:8px;" placeholder="Scan barcode…">
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-1"
                                    style="border-radius:8px;" onclick="lookupBarcode()">
                                <i class="fas fa-barcode me-1"></i> Find
                            </button>
                        </div>
                    </div>

                    <!-- Due Date -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="due_date">
                            <i class="fas fa-calendar me-1 text-muted"></i> Due Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="due_date" id="due_date" class="form-control" required
                               style="border-radius:10px;"
                               value="<?= h(old('due_date') ?: date('Y-m-d', strtotime('+14 days'))) ?>"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        <div class="form-text">Auto-calculated: Student = <?= lib_setting('borrow_days_student', 14) ?> days, Faculty = <?= lib_setting('borrow_days_faculty', 30) ?> days.</div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="notes">
                            <i class="fas fa-sticky-note me-1 text-muted"></i> Notes
                        </label>
                        <textarea name="notes" id="notes" class="form-control" rows="2"
                                  style="border-radius:10px;"
                                  placeholder="Optional notes…"><?= h(old('notes')) ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                            <i class="fas fa-check me-1"></i> Issue Book
                        </button>
                        <a href="<?= APP_URL ?>/library/circulation/index.php"
                           class="btn btn-outline-secondary" style="border-radius:10px;">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const memberData = <?= json_encode(array_map(fn($m) => [
    'id'    => $m['id'],
    'type'  => $m['member_type'],
    'code'  => $m['member_code'],
], $active_members)) ?>;

const borrowDaysStudent = <?= (int)lib_setting('borrow_days_student', 14) ?>;
const borrowDaysFaculty = <?= (int)lib_setting('borrow_days_faculty', 30) ?>;

document.getElementById('member_id').addEventListener('change', function () {
    const id   = parseInt(this.value);
    const info = memberData.find(m => m.id === id);
    const box  = document.getElementById('memberInfo');
    const txt  = document.getElementById('memberInfoText');
    const due  = document.getElementById('due_date');
    if (info) {
        const days = info.type === 'Faculty' ? borrowDaysFaculty : borrowDaysStudent;
        const d    = new Date();
        d.setDate(d.getDate() + days);
        due.value  = d.toISOString().substring(0, 10);
        txt.textContent = 'Member type: ' + info.type + ' · Default borrow period: ' + days + ' days';
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
});

document.getElementById('book_id').addEventListener('change', function () {
    const bookId = this.value;
    const copySelect = document.getElementById('copy_id');
    copySelect.innerHTML = '<option value="">Loading…</option>';
    if (!bookId) {
        copySelect.innerHTML = '<option value="">— Select book first —</option>';
        return;
    }
    fetch('<?= APP_URL ?>/library/circulation/ajax_copies.php?book_id=' + bookId)
        .then(r => r.json())
        .then(data => {
            copySelect.innerHTML = '<option value="">— Select Copy —</option>';
            data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = 'Copy #' + c.copy_number +
                    (c.barcode ? ' [' + c.barcode + ']' : '') +
                    ' · ' + c.condition_status;
                copySelect.appendChild(opt);
            });
            if (data.length === 0) {
                copySelect.innerHTML = '<option value="">No available copies</option>';
            }
        })
        .catch(() => {
            copySelect.innerHTML = '<option value="">Error loading copies</option>';
        });
});

function lookupBarcode() {
    const barcode = document.getElementById('barcodeInput').value.trim();
    if (!barcode) return;
    fetch('<?= APP_URL ?>/library/circulation/ajax_copies.php?barcode=' + encodeURIComponent(barcode))
        .then(r => r.json())
        .then(data => {
            if (data.length === 1) {
                const c = data[0];
                // Update book select
                const bookSel = document.getElementById('book_id');
                for (let opt of bookSel.options) {
                    if (parseInt(opt.value) === c.book_id) { opt.selected = true; break; }
                }
                // Trigger copy load then select
                document.getElementById('book_id').dispatchEvent(new Event('change'));
                setTimeout(() => {
                    const cs = document.getElementById('copy_id');
                    for (let opt of cs.options) {
                        if (parseInt(opt.value) === c.id) { opt.selected = true; break; }
                    }
                }, 600);
            } else {
                alert('Barcode not found or copy not available.');
            }
        });
}
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
