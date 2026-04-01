<?php
require_once __DIR__ . '/../../includes/auth.php';
auth_check();
require_super_admin();
require_once __DIR__ . '/../helpers.php';

$pdo = db();

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save Library Info
    if ($action === 'save_info') {
        csrf_check();
        $fields = ['lib_name','lib_address','lib_room','lib_location','lib_phone','lib_email','lib_website','lib_hours','lib_description'];
        foreach ($fields as $key) {
            lib_save_setting($key, trim($_POST[$key] ?? ''));
        }
        lib_audit('SETTINGS_UPDATED', 'library', null, 'Library Information', 'Library info settings updated');
        flash_set('success', 'Library information saved.');
        redirect(APP_URL . '/admin/library/settings/index.php?tab=info');
    }

    // Save Borrowing Rules
    if ($action === 'save_rules') {
        csrf_check();
        $rules = ['borrow_limit_student','borrow_limit_faculty','borrow_days_student','borrow_days_faculty','fine_per_day','max_renewals','max_reservations','lost_book_fine','reservation_expiry_hours'];
        foreach ($rules as $key) {
            lib_save_setting($key, trim($_POST[$key] ?? ''));
        }
        lib_audit('SETTINGS_UPDATED', 'library', null, 'Borrowing Rules', 'Borrowing rule settings updated');
        flash_set('success', 'Borrowing rules saved.');
        redirect(APP_URL . '/admin/library/settings/index.php?tab=rules');
    }

    // Add Librarian
    if ($action === 'add_librarian') {
        csrf_check();
        $name        = trim($_POST['lib_name_val'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $room_number = trim($_POST['room_number'] ?? '');
        $bio         = trim($_POST['bio'] ?? '');
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        $photo       = null;
        if (!empty($_FILES['photo']['name'])) {
            try { $photo = lib_upload_librarian_photo($_FILES['photo']); }
            catch (RuntimeException $e) {
                flash_set('error', $e->getMessage());
                redirect(APP_URL . '/admin/library/settings/index.php?tab=librarians');
            }
        }
        if (!$name || !$designation) {
            flash_set('error', 'Name and designation are required.');
            redirect(APP_URL . '/admin/library/settings/index.php?tab=librarians');
        }
        $pdo->prepare('INSERT INTO library_librarians (name,designation,photo,email,phone,room_number,bio,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$name,$designation,$photo,$email,$phone,$room_number,$bio,$is_active,$sort_order]);
        lib_audit('LIBRARIAN_ADDED', 'library', (int)$pdo->lastInsertId(), $name, 'Librarian added: '.$name);
        flash_set('success', 'Librarian added.');
        redirect(APP_URL . '/admin/library/settings/index.php?tab=librarians');
    }

    // Edit Librarian
    if ($action === 'edit_librarian') {
        csrf_check();
        $lid  = (int)($_POST['librarian_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM library_librarians WHERE id = ?');
        $stmt->execute([$lid]);
        $librarian = $stmt->fetch();
        if (!$librarian) {
            flash_set('error','Librarian not found.');
            redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
        }
        $name        = trim($_POST['lib_name_val'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $photo       = $librarian['photo'];
        if (!empty($_FILES['photo']['name'])) {
            if ($photo) lib_delete_file('librarians', $photo);
            try { $photo = lib_upload_librarian_photo($_FILES['photo']); }
            catch (RuntimeException $e) {
                flash_set('error', $e->getMessage());
                redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
            }
        }
        if (isset($_POST['remove_photo']) && $librarian['photo']) {
            lib_delete_file('librarians', $librarian['photo']);
            $photo = null;
        }
        $pdo->prepare('UPDATE library_librarians SET name=?,designation=?,photo=?,email=?,phone=?,room_number=?,bio=?,is_active=?,sort_order=? WHERE id=?')
            ->execute([$name,$designation,$photo,trim($_POST['email']??''),trim($_POST['phone']??''),trim($_POST['room_number']??''),trim($_POST['bio']??''),(int)(isset($_POST['is_active'])),(int)($_POST['sort_order']??0),$lid]);
        lib_audit('LIBRARIAN_UPDATED','library',$lid,$name,'Librarian updated');
        flash_set('success','Librarian updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
    }

    // Delete Librarian
    if ($action === 'delete_librarian') {
        csrf_check();
        $lid  = (int)($_POST['librarian_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM library_librarians WHERE id = ?');
        $stmt->execute([$lid]);
        $librarian = $stmt->fetch();
        if (!$librarian) {
            flash_set('error','Not found.');
            redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
        }
        if ($librarian['photo']) lib_delete_file('librarians', $librarian['photo']);
        $pdo->prepare('DELETE FROM library_librarians WHERE id = ?')->execute([$lid]);
        lib_audit('LIBRARIAN_DELETED','library',$lid,$librarian['name'],'Librarian deleted');
        flash_set('success','Librarian deleted.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
    }

    // Toggle Librarian active
    if ($action === 'toggle_librarian') {
        csrf_check();
        $lid = (int)($_POST['librarian_id'] ?? 0);
        $pdo->prepare('UPDATE library_librarians SET is_active = NOT is_active WHERE id = ?')->execute([$lid]);
        flash_set('success','Librarian status updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=librarians');
    }

    // Add Category
    if ($action === 'add_category') {
        csrf_check();
        $cat_name   = trim($_POST['cat_name'] ?? '');
        $parent     = (int)($_POST['parent_id'] ?? 0) ?: null;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if (!$cat_name) { flash_set('error','Category name required.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=categories'); }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cat_name)), '-'));
        // Ensure slug uniqueness
        $base = $slug; $i = 2;
        while ($pdo->prepare('SELECT id FROM library_categories WHERE slug=?')->execute([$slug]) && $pdo->query("SELECT COUNT(*) FROM library_categories WHERE slug='$slug'")->fetchColumn() > 0) {
            $slug = $base . '-' . $i++;
        }
        $pdo->prepare('INSERT INTO library_categories (name,slug,parent_id,sort_order) VALUES (?,?,?,?)')->execute([$cat_name,$slug,$parent,$sort_order]);
        lib_audit('CATEGORY_ADDED','library',(int)$pdo->lastInsertId(),$cat_name,'Category added');
        flash_set('success','Category added.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=categories');
    }

    // Edit Category
    if ($action === 'edit_category') {
        csrf_check();
        $cid        = (int)($_POST['category_id'] ?? 0);
        $cat_name   = trim($_POST['cat_name'] ?? '');
        $parent     = (int)($_POST['parent_id'] ?? 0) ?: null;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        if (!$cat_name) { flash_set('error','Category name required.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=categories'); }
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cat_name)), '-'));
        $base = $slug; $i = 2;
        while ($pdo->query("SELECT COUNT(*) FROM library_categories WHERE slug='$slug' AND id <> $cid")->fetchColumn() > 0) {
            $slug = $base . '-' . $i++;
        }
        $pdo->prepare('UPDATE library_categories SET name=?,slug=?,parent_id=?,sort_order=? WHERE id=?')->execute([$cat_name,$slug,$parent,$sort_order,$cid]);
        lib_audit('CATEGORY_UPDATED','library',$cid,$cat_name,'Category updated');
        flash_set('success','Category updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=categories');
    }

    // Delete Category
    if ($action === 'delete_category') {
        csrf_check();
        $cid   = (int)($_POST['category_id'] ?? 0);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM library_books WHERE category_id=$cid")->fetchColumn();
        if ($count > 0) {
            flash_set('error','Cannot delete: '.$count.' book(s) assigned to this category.');
            redirect(APP_URL.'/admin/library/settings/index.php?tab=categories');
        }
        $pdo->prepare('DELETE FROM library_categories WHERE id=?')->execute([$cid]);
        lib_audit('CATEGORY_DELETED','library',$cid,'Category #'.$cid,'Category deleted');
        flash_set('success','Category deleted.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=categories');
    }
}

// ── Data for display ──────────────────────────────────────────────────────────
$settings    = lib_settings_all();
$librarians  = $pdo->query('SELECT * FROM library_librarians ORDER BY sort_order ASC, name ASC')->fetchAll();
$categories  = $pdo->query('SELECT c.*, p.name as parent_name, (SELECT COUNT(*) FROM library_books b WHERE b.category_id=c.id) as book_count FROM library_categories c LEFT JOIN library_categories p ON p.id=c.parent_id ORDER BY c.sort_order ASC, c.name ASC')->fetchAll();
$root_cats   = array_filter($categories, fn($c) => !$c['parent_id']);

$page_title  = 'Library Settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'url' => APP_URL . '/admin/'],
    ['label' => 'Library',   'url' => APP_URL . '/admin/library/'],
    ['label' => 'Settings'],
];
require_once __DIR__ . '/../../includes/header.php';

$s = fn(string $k, mixed $d='') => $settings[$k] ?? $d;
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fa fa-cog me-2 text-primary"></i>Library Settings</h4>
    </div>

    <?php if ($flash = flash_get('success')): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="fa fa-check-circle me-1"></i><?= h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($flash = flash_get('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="fa fa-times-circle me-1"></i><?= h($flash) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Tabs Nav -->
    <ul class="nav nav-tabs mb-3" id="settingsTabs">
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" type="button"><i class="fa fa-info-circle me-1"></i>Library Info</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rules" type="button"><i class="fa fa-gavel me-1"></i>Borrowing Rules</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-librarians" type="button"><i class="fa fa-id-badge me-1"></i>Librarians</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-categories" type="button"><i class="fa fa-tags me-1"></i>Categories</button></li>
    </ul>

    <div class="tab-content">

        <!-- TAB: Library Info -->
        <div class="tab-pane fade" id="tab-info">
            <div class="card">
                <div class="card-header fw-semibold bg-white">Library Information</div>
                <div class="card-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_info">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Library Name</label>
                                <input type="text" name="lib_name" class="form-control" value="<?= h($s('lib_name')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="lib_phone" class="form-control" value="<?= h($s('lib_phone')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="lib_email" class="form-control" value="<?= h($s('lib_email')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Website</label>
                                <input type="text" name="lib_website" class="form-control" value="<?= h($s('lib_website')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Room</label>
                                <input type="text" name="lib_room" class="form-control" value="<?= h($s('lib_room')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Location / Building</label>
                                <input type="text" name="lib_location" class="form-control" value="<?= h($s('lib_location')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Opening Hours</label>
                                <input type="text" name="lib_hours" class="form-control" placeholder="e.g. Sat–Thu 8am–8pm" value="<?= h($s('lib_hours')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" name="lib_address" class="form-control" value="<?= h($s('lib_address')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="lib_description" class="form-control" rows="3"><?= h($s('lib_description')) ?></textarea>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Information</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- TAB: Borrowing Rules -->
        <div class="tab-pane fade" id="tab-rules">
            <div class="card">
                <div class="card-header fw-semibold bg-white">Borrowing Rules</div>
                <div class="card-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_rules">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Borrow Limit – Student</label>
                                <div class="input-group">
                                    <input type="number" name="borrow_limit_student" class="form-control" min="1" value="<?= h($s('borrow_limit_student','3')) ?>">
                                    <span class="input-group-text">books</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Borrow Limit – Faculty</label>
                                <div class="input-group">
                                    <input type="number" name="borrow_limit_faculty" class="form-control" min="1" value="<?= h($s('borrow_limit_faculty','5')) ?>">
                                    <span class="input-group-text">books</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Borrow Days – Student</label>
                                <div class="input-group">
                                    <input type="number" name="borrow_days_student" class="form-control" min="1" value="<?= h($s('borrow_days_student','14')) ?>">
                                    <span class="input-group-text">days</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Borrow Days – Faculty</label>
                                <div class="input-group">
                                    <input type="number" name="borrow_days_faculty" class="form-control" min="1" value="<?= h($s('borrow_days_faculty','30')) ?>">
                                    <span class="input-group-text">days</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fine Per Day (৳)</label>
                                <input type="number" step="0.01" name="fine_per_day" class="form-control" min="0" value="<?= h($s('fine_per_day','5.00')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Max Renewals</label>
                                <input type="number" name="max_renewals" class="form-control" min="0" value="<?= h($s('max_renewals','2')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Max Reservations</label>
                                <input type="number" name="max_reservations" class="form-control" min="0" value="<?= h($s('max_reservations','3')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Lost Book Fine (৳)</label>
                                <input type="number" step="0.01" name="lost_book_fine" class="form-control" min="0" value="<?= h($s('lost_book_fine','500.00')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reservation Expiry (hours)</label>
                                <input type="number" name="reservation_expiry_hours" class="form-control" min="1" value="<?= h($s('reservation_expiry_hours','48')) ?>">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Rules</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- TAB: Librarians -->
        <div class="tab-pane fade" id="tab-librarians">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="fa fa-id-badge me-1 text-primary"></i>Librarians</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLibrarianModal">
                        <i class="fa fa-plus me-1"></i>Add Librarian
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:60px">Photo</th>
                                    <th>Name</th>
                                    <th>Designation</th>
                                    <th>Room</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th class="text-center">Sort</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($librarians): foreach ($librarians as $lib): ?>
                                <tr>
                                    <td>
                                        <?php if ($lib['photo']): ?>
                                            <img src="<?= h(UPLOAD_URL.'/librarians/'.rawurlencode($lib['photo'])) ?>" class="rounded-circle" style="width:40px;height:40px;object-fit:cover" alt="">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width:40px;height:40px;font-size:16px">
                                                <?= strtoupper(mb_substr($lib['name'],0,1)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold"><?= h($lib['name']) ?></td>
                                    <td><?= h($lib['designation']) ?></td>
                                    <td><?= h($lib['room_number']) ?></td>
                                    <td><?= h($lib['email']) ?></td>
                                    <td><?= h($lib['phone']) ?></td>
                                    <td class="text-center"><?= (int)$lib['sort_order'] ?></td>
                                    <td class="text-center">
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_librarian">
                                            <input type="hidden" name="librarian_id" value="<?= $lib['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $lib['is_active'] ? 'btn-success' : 'btn-outline-secondary' ?>" title="Toggle">
                                                <i class="fa fa-<?= $lib['is_active'] ? 'check' : 'times' ?>"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editLibrarianModal"
                                            data-id="<?= $lib['id'] ?>"
                                            data-name="<?= h($lib['name']) ?>"
                                            data-designation="<?= h($lib['designation']) ?>"
                                            data-email="<?= h($lib['email']) ?>"
                                            data-phone="<?= h($lib['phone']) ?>"
                                            data-room="<?= h($lib['room_number']) ?>"
                                            data-bio="<?= h($lib['bio']) ?>"
                                            data-sort="<?= (int)$lib['sort_order'] ?>"
                                            data-active="<?= (int)$lib['is_active'] ?>"
                                            data-photo="<?= h($lib['photo']) ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this librarian?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_librarian">
                                            <input type="hidden" name="librarian_id" value="<?= $lib['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No librarians yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: Categories -->
        <div class="tab-pane fade" id="tab-categories">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="fa fa-tags me-1 text-primary"></i>Book Categories</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fa fa-plus me-1"></i>Add Category
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Parent</th>
                                    <th class="text-center">Sort</th>
                                    <th class="text-center">Books</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($categories): foreach ($categories as $cat): ?>
                                <tr>
                                    <td class="fw-semibold"><?= h($cat['name']) ?></td>
                                    <td><code><?= h($cat['slug']) ?></code></td>
                                    <td><?= $cat['parent_name'] ? h($cat['parent_name']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-center"><?= (int)$cat['sort_order'] ?></td>
                                    <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$cat['book_count'] ?></span></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editCategoryModal"
                                            data-id="<?= $cat['id'] ?>"
                                            data-name="<?= h($cat['name']) ?>"
                                            data-parent="<?= (int)$cat['parent_id'] ?>"
                                            data-sort="<?= (int)$cat['sort_order'] ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" <?= $cat['book_count'] > 0 ? 'disabled title="Has books assigned"' : '' ?>><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No categories yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content -->
</div><!-- /container-fluid -->

<!-- ── Add Librarian Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="addLibrarianModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_librarian">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-plus me-1"></i>Add Librarian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="lib_name_val" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Designation <span class="text-danger">*</span></label>
                            <input type="text" name="designation" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="addActive" checked>
                                <label class="form-check-label" for="addActive">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Add Librarian</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Librarian Modal ────────────────────────────────────────────────── -->
<div class="modal fade" id="editLibrarianModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_librarian">
            <input type="hidden" name="librarian_id" id="editLibId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-edit me-1"></i>Edit Librarian</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="editCurrentPhoto" class="mb-3 d-none">
                        <label class="form-label">Current Photo</label><br>
                        <img id="editPhotoImg" src="" class="rounded" style="max-height:80px" alt="">
                        <div class="form-check mt-1">
                            <input type="checkbox" name="remove_photo" class="form-check-input" id="removePhoto">
                            <label class="form-check-label" for="removePhoto">Remove photo</label>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="lib_name_val" id="editLibName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Designation <span class="text-danger">*</span></label>
                            <input type="text" name="designation" id="editDesig" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="editEmail" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" id="editPhone" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" id="editRoom" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" id="editSort" class="form-control">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" id="editActive">
                                <label class="form-check-label" for="editActive">Active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" id="editBio" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Replace Photo</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Add Category Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_category">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-plus me-1"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="cat_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_id" class="form-select">
                            <option value="0">— None (Top-level) —</option>
                            <?php foreach ($root_cats as $rc): ?>
                                <option value="<?= $rc['id'] ?>"><?= h($rc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Add Category</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Category Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="editCatId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-edit me-1"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="cat_name" id="editCatName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_id" id="editCatParent" class="form-select">
                            <option value="0">— None (Top-level) —</option>
                            <?php foreach ($root_cats as $rc): ?>
                                <option value="<?= $rc['id'] ?>"><?= h($rc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="editCatSort" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Activate tab from URL param
const tab = new URLSearchParams(window.location.search).get('tab') || 'info';
document.querySelector(`[data-bs-target="#tab-${tab}"]`)?.click();

// Edit Librarian modal population
document.getElementById('editLibrarianModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('editLibId').value    = btn.dataset.id;
    document.getElementById('editLibName').value  = btn.dataset.name;
    document.getElementById('editDesig').value    = btn.dataset.designation;
    document.getElementById('editEmail').value    = btn.dataset.email;
    document.getElementById('editPhone').value    = btn.dataset.phone;
    document.getElementById('editRoom').value     = btn.dataset.room;
    document.getElementById('editBio').value      = btn.dataset.bio;
    document.getElementById('editSort').value     = btn.dataset.sort;
    document.getElementById('editActive').checked = btn.dataset.active === '1';
    const photoDiv = document.getElementById('editCurrentPhoto');
    const photoImg = document.getElementById('editPhotoImg');
    if (btn.dataset.photo) {
        photoImg.src = '<?= rtrim(UPLOAD_URL,'/') ?>/librarians/' + encodeURIComponent(btn.dataset.photo);
        photoDiv.classList.remove('d-none');
    } else {
        photoDiv.classList.add('d-none');
    }
});

// Edit Category modal population
document.getElementById('editCategoryModal').addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('editCatId').value     = btn.dataset.id;
    document.getElementById('editCatName').value   = btn.dataset.name;
    document.getElementById('editCatParent').value = btn.dataset.parent;
    document.getElementById('editCatSort').value   = btn.dataset.sort;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
