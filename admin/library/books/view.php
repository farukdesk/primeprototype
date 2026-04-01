<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_access('library');
require_once __DIR__ . '/../helpers.php';

$id   = (int)($_GET['id'] ?? 0);
$book = lib_get_book($id);
$db   = db();

// ── Handle add-copy (inline form, POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_copy') {
    if (!lib_is_staff()) {
        flash_set('error', 'Permission denied.');
        redirect(APP_URL . '/library/books/view.php?id=' . $id);
    }
    csrf_check();

    $condition = trim($_POST['condition_status'] ?? 'Good');
    $notes     = trim($_POST['notes'] ?? '');
    $allowed   = ['Good', 'Fair', 'Poor'];
    if (!in_array($condition, $allowed, true)) $condition = 'Good';

    // Determine next copy number
    $max_num = $db->prepare('SELECT COALESCE(MAX(copy_number), 0) FROM library_book_copies WHERE book_id = ?');
    $max_num->execute([$id]);
    $next_num = (int)$max_num->fetchColumn() + 1;

    $barcode = lib_generate_barcode($id, $next_num);

    $db->prepare(
        'INSERT INTO library_book_copies
         (book_id, barcode, copy_number, condition_status, notes, is_available, created_at)
         VALUES (?,?,?,?,?,1,NOW())'
    )->execute([$id, $barcode, $next_num, $condition, $notes ?: null]);

    // Update total_copies and available_copies counts
    $db->prepare(
        'UPDATE library_books
         SET total_copies     = (SELECT COUNT(*) FROM library_book_copies WHERE book_id = ?),
             available_copies = (SELECT COUNT(*) FROM library_book_copies WHERE book_id = ? AND is_available = 1),
             updated_at       = NOW()
         WHERE id = ?'
    )->execute([$id, $id, $id]);

    log_change('library', 'UPDATE', $id, $book['title'], 'copies', null, null,
        "Added copy #{$next_num} (barcode {$barcode}).");
    lib_audit('COPY_ADDED', 'books', $id, $book['title'], "Added copy #{$next_num}, barcode: {$barcode}.");

    flash_set('success', "Copy <strong>#{$next_num}</strong> added (barcode: <code>{$barcode}</code>).");
    redirect(APP_URL . '/library/books/view.php?id=' . $id);
}

// ── Handle edit-copy condition (modal form) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_copy') {
    if (!lib_is_staff()) {
        flash_set('error', 'Permission denied.');
        redirect(APP_URL . '/library/books/view.php?id=' . $id);
    }
    csrf_check();

    $copy_id   = (int)($_POST['copy_id']        ?? 0);
    $condition = trim($_POST['condition_status'] ?? 'Good');
    $notes     = trim($_POST['notes']            ?? '');
    $allowed   = ['Good', 'Fair', 'Poor', 'Lost', 'Damaged'];
    if (!in_array($condition, $allowed, true)) $condition = 'Good';

    $old_copy = $db->prepare('SELECT * FROM library_book_copies WHERE id = ? AND book_id = ?');
    $old_copy->execute([$copy_id, $id]);
    $copy_row = $old_copy->fetch();

    if ($copy_row) {
        // Mark Lost/Damaged copies as unavailable
        $is_available = in_array($condition, ['Lost','Damaged'], true) ? 0 : $copy_row['is_available'];

        $db->prepare(
            'UPDATE library_book_copies
             SET condition_status=?, notes=?, is_available=?
             WHERE id=?'
        )->execute([$condition, $notes ?: null, $is_available, $copy_id]);

        // Recalculate available_copies
        $db->prepare(
            'UPDATE library_books
             SET available_copies = (SELECT COUNT(*) FROM library_book_copies WHERE book_id = ? AND is_available = 1),
                 updated_at = NOW()
             WHERE id = ?'
        )->execute([$id, $id]);

        if ($copy_row['condition_status'] !== $condition) {
            log_change('library', 'UPDATE', $id, $book['title'], 'copy_condition',
                $copy_row['condition_status'], $condition,
                "Copy #{$copy_row['copy_number']} condition changed.");
        }
        lib_audit('COPY_UPDATED', 'books', $id, $book['title'],
            "Copy #{$copy_row['copy_number']} updated: condition={$condition}.");

        flash_set('success', "Copy #{$copy_row['copy_number']} updated.");
    }
    redirect(APP_URL . '/library/books/view.php?id=' . $id);
}

// ── Reload fresh book data ────────────────────────────────────────────────────
$book = lib_get_book($id);

// ── Book copies ───────────────────────────────────────────────────────────────
// Get latest circulation per copy using MAX(id) GROUP BY for efficiency
$copies = $db->prepare(
    'SELECT cp.*,
            COALESCE(s.full_name, u.full_name) AS last_borrower
     FROM library_book_copies cp
     LEFT JOIN library_circulation  ci  ON ci.copy_id = cp.id
                                       AND ci.id = (
                                           SELECT MAX(ci2.id)
                                           FROM library_circulation ci2
                                           WHERE ci2.copy_id = cp.id
                                       )
     LEFT JOIN library_members      m   ON m.id  = ci.member_id
     LEFT JOIN students             s   ON s.id  = m.student_id
     LEFT JOIN users                u   ON u.id  = m.user_id
     WHERE cp.book_id = ?
     ORDER BY cp.copy_number ASC'
);
$copies->execute([$id]);
$copies = $copies->fetchAll();

// ── Circulation history (last 20) ─────────────────────────────────────────────
$circulation = $db->prepare(
    'SELECT ci.id, ci.issue_date, ci.due_date, ci.return_date, ci.status,
            cp.copy_number, cp.barcode,
            m.member_code,
            COALESCE(s.full_name, u.full_name) AS member_name
     FROM library_circulation ci
     JOIN library_book_copies cp ON cp.id = ci.copy_id
     JOIN library_members     m  ON m.id  = ci.member_id
     LEFT JOIN students       s  ON s.id  = m.student_id
     LEFT JOIN users          u  ON u.id  = m.user_id
     WHERE cp.book_id = ?
     ORDER BY ci.id DESC
     LIMIT 20'
);
$circulation->execute([$id]);
$circulation = $circulation->fetchAll();

$page_title = $book['title'];
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Breadcrumb & Actions -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/index.php">Library</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/library/books/index.php">Books</a></li>
            <li class="breadcrumb-item active"><?= h($book['title']) ?></li>
        </ol>
    </nav>
    <div class="d-flex gap-2">
        <?php if (lib_is_staff()): ?>
        <a href="<?= APP_URL ?>/library/books/edit.php?id=<?= $id ?>"
           class="btn btn-outline-primary btn-sm" style="border-radius:10px;">
            <i class="fas fa-edit me-1"></i> Edit
        </a>
        <?php endif; ?>
        <?php if (lib_can_delete()): ?>
        <form method="POST" action="<?= APP_URL ?>/library/books/delete.php"
              onsubmit="return confirm('Delete this book? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-danger btn-sm" style="border-radius:10px;">
                <i class="fas fa-trash me-1"></i> Delete
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ── Book Header Card ── -->
<div class="card mb-4">
    <div class="card-body p-4">
        <div class="row g-4 align-items-start">
            <!-- Cover -->
            <div class="col-auto">
                <?php if ($book['cover_image']): ?>
                    <img src="<?= UPLOAD_URL ?>/library/covers/<?= h($book['cover_image']) ?>"
                         alt="Cover" style="width:120px;height:160px;object-fit:cover;
                                            border-radius:8px;border:1px solid #e0e0e0;box-shadow:0 2px 8px rgba(0,0,0,.1);">
                <?php else: ?>
                    <div style="width:120px;height:160px;background:linear-gradient(135deg,#e8ecf7,#c9d3ef);
                                border-radius:8px;display:flex;align-items:center;justify-content:center;
                                border:1px solid #d0d8f0;">
                        <i class="fas fa-book" style="font-size:2.5rem;color:#7b93d1;opacity:.7;"></i>
                    </div>
                <?php endif; ?>
            </div>
            <!-- Info -->
            <div class="col">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <h4 class="mb-0 fw-bold"><?= h($book['title']) ?></h4>
                    <?php if ($book['is_digital']): ?>
                        <span class="badge bg-info text-dark">Digital</span>
                    <?php endif; ?>
                    <?php
                    $avail_c = lib_book_available_copies($id);
                    $total_c = (int)$book['total_copies'];
                    ?>
                    <span class="badge <?= $avail_c > 0 ? 'bg-success' : 'bg-danger' ?>">
                        <?= $avail_c ?>/<?= $total_c ?> Available
                    </span>
                </div>
                <?php if ($book['subtitle']): ?>
                    <div class="text-muted mb-1" style="font-size:.95rem;"><?= h($book['subtitle']) ?></div>
                <?php endif; ?>
                <div class="mb-2">
                    <i class="fas fa-user-pen text-muted me-1" style="font-size:.85rem;"></i>
                    <span class="fw-medium"><?= h($book['author']) ?></span>
                </div>
                <div class="row g-2 mt-1">
                    <?php if ($book['isbn']): ?>
                    <div class="col-auto">
                        <span class="badge bg-light text-dark border" style="font-size:.78rem;">
                            <i class="fas fa-barcode me-1"></i><?= h($book['isbn']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($book['category_name']): ?>
                    <div class="col-auto">
                        <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:.78rem;">
                            <i class="fas fa-tag me-1"></i><?= h($book['category_name']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($book['publisher'] || $book['pub_year']): ?>
                    <div class="col-auto">
                        <span class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:.78rem;">
                            <i class="fas fa-building me-1"></i>
                            <?= h($book['publisher'] ?? '') ?>
                            <?= $book['pub_year'] ? ' (' . h($book['pub_year']) . ')' : '' ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Details Grid ── -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-list me-2 text-muted"></i>Book Details</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <?php
                    $details = [
                        ['Language',    $book['language'],    'fas fa-language'],
                        ['Edition',     $book['edition'],     'fas fa-bookmark'],
                        ['Year',        $book['pub_year'],    'fas fa-calendar'],
                        ['Shelf Rack',  $book['shelf_rack'],  'fas fa-warehouse'],
                        ['Shelf Row',   $book['shelf_row'],   'fas fa-layer-group'],
                        ['Department',  $book['dept_name'],   'fas fa-graduation-cap'],
                    ];
                    foreach ($details as [$label, $value, $icon]):
                    ?>
                    <div class="col-sm-6 col-md-4">
                        <div class="p-3 bg-light rounded" style="border-radius:10px!important;">
                            <div class="text-muted mb-1" style="font-size:.75rem;">
                                <i class="<?= $icon ?> me-1"></i><?= $label ?>
                            </div>
                            <div class="fw-medium" style="font-size:.9rem;">
                                <?= $value ? h($value) : '<span class="text-muted">—</span>' ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($book['description']): ?>
                <div class="mt-3 pt-3 border-top">
                    <div class="text-muted mb-1" style="font-size:.8rem;"><i class="fas fa-align-left me-1"></i>Description</div>
                    <p class="mb-0" style="font-size:.9rem;line-height:1.7;"><?= nl2br(h($book['description'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-3 px-4">
                <h6 class="mb-0 fw-semibold"><i class="fas fa-chart-bar me-2 text-muted"></i>Quick Stats</h6>
            </div>
            <div class="card-body p-4">
                <?php
                $active_circ = $db->prepare(
                    "SELECT COUNT(*) FROM library_circulation ci
                     JOIN library_book_copies cp ON cp.id = ci.copy_id
                     WHERE cp.book_id = ? AND ci.status IN ('Issued','Overdue')"
                );
                $active_circ->execute([$id]);
                $active_count = (int)$active_circ->fetchColumn();

                $total_circ = $db->prepare(
                    'SELECT COUNT(*) FROM library_circulation ci
                     JOIN library_book_copies cp ON cp.id = ci.copy_id
                     WHERE cp.book_id = ?'
                );
                $total_circ->execute([$id]);
                $total_circ_count = (int)$total_circ->fetchColumn();
                ?>
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted" style="font-size:.85rem;">Total Copies</span>
                        <span class="fw-semibold"><?= $total_c ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted" style="font-size:.85rem;">Available</span>
                        <span class="fw-semibold text-success"><?= $avail_c ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted" style="font-size:.85rem;">Currently Issued</span>
                        <span class="fw-semibold text-warning"><?= $active_count ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted" style="font-size:.85rem;">Total Circulations</span>
                        <span class="fw-semibold"><?= $total_circ_count ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted" style="font-size:.85rem;">Digital</span>
                        <span><?= $book['is_digital']
                            ? '<span class="badge bg-info text-dark">Yes</span>'
                            : '<span class="badge bg-secondary">No</span>' ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Copies Table ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-copy me-2 text-muted"></i>Copies</h6>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= count($copies) ?> cop<?= count($copies) !== 1 ? 'ies' : 'y' ?></span>
            <?php if (lib_is_staff()): ?>
            <button class="btn btn-success btn-sm" style="border-radius:10px;"
                    data-bs-toggle="modal" data-bs-target="#addCopyModal">
                <i class="fas fa-plus me-1"></i> Add Copy
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Copy #</th>
                        <th>Barcode</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th>Last Borrower</th>
                        <th>Notes</th>
                        <?php if (lib_is_staff()): ?>
                        <th class="text-end pe-4">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($copies)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No copies found.</td></tr>
                <?php else: ?>
                    <?php foreach ($copies as $copy): ?>
                    <tr>
                        <td class="px-4 fw-medium">#<?= (int)$copy['copy_number'] ?></td>
                        <td><code style="font-size:.82rem;"><?= h($copy['barcode']) ?></code></td>
                        <td><?= lib_copy_condition_badge($copy['condition_status']) ?></td>
                        <td>
                            <?php
                            if (in_array($copy['condition_status'], ['Lost','Damaged'], true)):
                                echo '<span class="badge bg-dark">' . h($copy['condition_status']) . '</span>';
                            elseif ($copy['is_available']):
                                echo '<span class="badge bg-success">Available</span>';
                            else:
                                echo '<span class="badge bg-warning text-dark">Issued</span>';
                            endif;
                            ?>
                        </td>
                        <td style="font-size:.85rem;">
                            <?= $copy['last_borrower'] ? h($copy['last_borrower']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td style="font-size:.82rem;max-width:150px;">
                            <?= $copy['notes'] ? h($copy['notes']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <?php if (lib_is_staff()): ?>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-primary" style="border-radius:7px;"
                                    title="Edit Condition"
                                    data-bs-toggle="modal" data-bs-target="#editCopyModal"
                                    data-copy-id="<?= $copy['id'] ?>"
                                    data-copy-num="<?= (int)$copy['copy_number'] ?>"
                                    data-condition="<?= h($copy['condition_status']) ?>"
                                    data-notes="<?= h($copy['notes'] ?? '') ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Circulation History ── -->
<div class="card mb-4">
    <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-rotate me-2 text-muted"></i>Circulation History</h6>
        <span class="badge bg-secondary bg-opacity-15 text-secondary">Last 20 records</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Member</th>
                        <th>Copy</th>
                        <th>Issue Date</th>
                        <th>Due Date</th>
                        <th>Return Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($circulation)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No circulation history yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($circulation as $circ): ?>
                    <tr>
                        <td class="px-4">
                            <div class="fw-medium" style="font-size:.88rem;"><?= h($circ['member_name']) ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= h($circ['member_code']) ?></div>
                        </td>
                        <td style="font-size:.82rem;">
                            <code>#<?= (int)$circ['copy_number'] ?></code>
                        </td>
                        <td style="font-size:.82rem;"><?= h(date('d M Y', strtotime($circ['issue_date']))) ?></td>
                        <td style="font-size:.82rem;"><?= h(date('d M Y', strtotime($circ['due_date']))) ?></td>
                        <td style="font-size:.82rem;">
                            <?= $circ['return_date']
                                ? h(date('d M Y', strtotime($circ['return_date'])))
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td><?= lib_circulation_status_badge($circ['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (lib_is_staff()): ?>
<!-- ── Add Copy Modal ── -->
<div class="modal fade" id="addCopyModal" tabindex="-1" aria-labelledby="addCopyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:0;">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id"     value="<?= $id ?>">
                <input type="hidden" name="action" value="add_copy">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold" id="addCopyModalLabel">
                        <i class="fas fa-plus me-2 text-success"></i>Add Copy
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Initial Condition</label>
                        <select name="condition_status" class="form-select" style="border-radius:10px;">
                            <option value="Good">Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-medium">Notes <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="notes" class="form-control" style="border-radius:10px;" rows="2"
                                  placeholder="Any notes about this copy…"></textarea>
                    </div>
                    <div class="form-text">
                        <i class="fas fa-info-circle me-1"></i>
                        A barcode will be auto-generated for this copy.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" style="border-radius:10px;"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" style="border-radius:10px;">
                        <i class="fas fa-plus me-1"></i> Add Copy
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Edit Copy Condition Modal ── -->
<div class="modal fade" id="editCopyModal" tabindex="-1" aria-labelledby="editCopyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;border:0;">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id"      value="<?= $id ?>">
                <input type="hidden" name="action"  value="edit_copy">
                <input type="hidden" name="copy_id" id="edit_copy_id" value="">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title fw-semibold" id="editCopyModalLabel">
                        <i class="fas fa-edit me-2 text-primary"></i>Edit Copy <span id="edit_copy_num_label"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4 pb-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Condition</label>
                        <select name="condition_status" id="edit_condition" class="form-select" style="border-radius:10px;">
                            <option value="Good">Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                            <option value="Damaged">Damaged</option>
                            <option value="Lost">Lost</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-medium">Notes</label>
                        <textarea name="notes" id="edit_notes" class="form-control"
                                  style="border-radius:10px;" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" style="border-radius:10px;"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                        <i class="fas fa-save me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('editCopyModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('edit_copy_id').value        = btn.dataset.copyId;
    document.getElementById('edit_copy_num_label').textContent = '#' + btn.dataset.copyNum;
    document.getElementById('edit_condition').value      = btn.dataset.condition;
    document.getElementById('edit_notes').value          = btn.dataset.notes;
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
