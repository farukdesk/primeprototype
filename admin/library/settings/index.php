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

    // ── Dept. Collections ──────────────────────────────────────────────────────

    if ($action === 'add_dept_collection') {
        csrf_check();
        $label      = trim($_POST['dc_label'] ?? '');
        $sub_label  = trim($_POST['dc_sub_label'] ?? '');
        $icon_class = trim($_POST['dc_icon_class'] ?? 'fas fa-book');
        $color_from = trim($_POST['dc_color_from'] ?? '#0f2a6b');
        $color_to   = trim($_POST['dc_color_to'] ?? '#1e4db7');
        $sort_order = (int)($_POST['dc_sort_order'] ?? 0);
        $is_active  = isset($_POST['dc_is_active']) ? 1 : 0;
        if (!$label) { flash_set('error','Label is required.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol'); }
        $image_file = '';
        if (!empty($_FILES['dc_image']['name'])) {
            try { $image_file = lib_upload_dept_image($_FILES['dc_image']); }
            catch (RuntimeException $e) { flash_set('error',$e->getMessage()); redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol'); }
        }
        $pdo->prepare('INSERT INTO library_dept_collections (label,sub_label,icon_class,color_from,color_to,image_file,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$label,$sub_label,$icon_class,$color_from,$color_to,$image_file,$sort_order,$is_active]);
        lib_audit('DEPT_COLLECTION_ADDED','library',(int)$pdo->lastInsertId(),$label,'Dept. collection added');
        flash_set('success','Department collection added.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol');
    }

    if ($action === 'edit_dept_collection') {
        csrf_check();
        $dcid = (int)($_POST['dc_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM library_dept_collections WHERE id=?'); $stmt->execute([$dcid]);
        $dc   = $stmt->fetch();
        if (!$dc) { flash_set('error','Not found.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol'); }
        $label      = trim($_POST['dc_label'] ?? '');
        $image_file = $dc['image_file'];
        if (!empty($_FILES['dc_image']['name'])) {
            if ($image_file) lib_delete_file('dept-collections', $image_file);
            try { $image_file = lib_upload_dept_image($_FILES['dc_image']); }
            catch (RuntimeException $e) { flash_set('error',$e->getMessage()); redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol'); }
        }
        if (isset($_POST['dc_remove_image']) && $dc['image_file']) {
            lib_delete_file('dept-collections', $dc['image_file']);
            $image_file = '';
        }
        $pdo->prepare('UPDATE library_dept_collections SET label=?,sub_label=?,icon_class=?,color_from=?,color_to=?,image_file=?,sort_order=?,is_active=? WHERE id=?')
            ->execute([$label,trim($_POST['dc_sub_label']??''),trim($_POST['dc_icon_class']??'fas fa-book'),trim($_POST['dc_color_from']??'#0f2a6b'),trim($_POST['dc_color_to']??'#1e4db7'),$image_file,(int)($_POST['dc_sort_order']??0),(int)(isset($_POST['dc_is_active'])),$dcid]);
        lib_audit('DEPT_COLLECTION_UPDATED','library',$dcid,$label,'Dept. collection updated');
        flash_set('success','Department collection updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol');
    }

    if ($action === 'delete_dept_collection') {
        csrf_check();
        $dcid = (int)($_POST['dc_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM library_dept_collections WHERE id=?'); $stmt->execute([$dcid]);
        $dc   = $stmt->fetch();
        if (!$dc) { flash_set('error','Not found.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol'); }
        if ($dc['image_file']) lib_delete_file('dept-collections', $dc['image_file']);
        $pdo->prepare('DELETE FROM library_dept_collections WHERE id=?')->execute([$dcid]);
        lib_audit('DEPT_COLLECTION_DELETED','library',$dcid,$dc['label'],'Dept. collection deleted');
        flash_set('success','Department collection deleted.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol');
    }

    if ($action === 'toggle_dept_collection') {
        csrf_check();
        $dcid = (int)($_POST['dc_id'] ?? 0);
        $pdo->prepare('UPDATE library_dept_collections SET is_active = NOT is_active WHERE id=?')->execute([$dcid]);
        flash_set('success','Status updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=deptcol');
    }

    // ── Library Facilities ─────────────────────────────────────────────────────

    if ($action === 'add_facility') {
        csrf_check();
        $name            = trim($_POST['fac_name'] ?? '');
        $icon_class      = trim($_POST['fac_icon_class'] ?? 'fas fa-star');
        $description     = trim($_POST['fac_description'] ?? '');
        $icon_bg_color   = trim($_POST['fac_icon_bg_color'] ?? '#f9e8eb');
        $icon_text_color = trim($_POST['fac_icon_text_color'] ?? '#b5182e');
        $sort_order      = (int)($_POST['fac_sort_order'] ?? 0);
        $is_active       = isset($_POST['fac_is_active']) ? 1 : 0;
        if (!$name) { flash_set('error','Facility name is required.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities'); }
        $pdo->prepare('INSERT INTO library_facilities (icon_class,name,description,icon_bg_color,icon_text_color,sort_order,is_active) VALUES (?,?,?,?,?,?,?)')
            ->execute([$icon_class,$name,$description,$icon_bg_color,$icon_text_color,$sort_order,$is_active]);
        lib_audit('FACILITY_ADDED','library',(int)$pdo->lastInsertId(),$name,'Facility added');
        flash_set('success','Facility added.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities');
    }

    if ($action === 'edit_facility') {
        csrf_check();
        $fid  = (int)($_POST['fac_id'] ?? 0);
        $name = trim($_POST['fac_name'] ?? '');
        if (!$fid || !$name) { flash_set('error','Invalid data.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities'); }
        $pdo->prepare('UPDATE library_facilities SET icon_class=?,name=?,description=?,icon_bg_color=?,icon_text_color=?,sort_order=?,is_active=? WHERE id=?')
            ->execute([trim($_POST['fac_icon_class']??'fas fa-star'),$name,trim($_POST['fac_description']??''),trim($_POST['fac_icon_bg_color']??'#f9e8eb'),trim($_POST['fac_icon_text_color']??'#b5182e'),(int)($_POST['fac_sort_order']??0),(int)(isset($_POST['fac_is_active'])),$fid]);
        lib_audit('FACILITY_UPDATED','library',$fid,$name,'Facility updated');
        flash_set('success','Facility updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities');
    }

    if ($action === 'delete_facility') {
        csrf_check();
        $fid  = (int)($_POST['fac_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM library_facilities WHERE id=?'); $stmt->execute([$fid]);
        $fac  = $stmt->fetch();
        if (!$fac) { flash_set('error','Not found.'); redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities'); }
        $pdo->prepare('DELETE FROM library_facilities WHERE id=?')->execute([$fid]);
        lib_audit('FACILITY_DELETED','library',$fid,$fac['name'],'Facility deleted');
        flash_set('success','Facility deleted.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities');
    }

    if ($action === 'toggle_facility') {
        csrf_check();
        $fid = (int)($_POST['fac_id'] ?? 0);
        $pdo->prepare('UPDATE library_facilities SET is_active = NOT is_active WHERE id=?')->execute([$fid]);
        flash_set('success','Status updated.');
        redirect(APP_URL.'/admin/library/settings/index.php?tab=facilities');
    }
}

// ── Data for display ──────────────────────────────────────────────────────────
$settings    = lib_settings_all();
$librarians  = $pdo->query('SELECT * FROM library_librarians ORDER BY sort_order ASC, name ASC')->fetchAll();
$categories  = $pdo->query('SELECT c.*, p.name as parent_name, (SELECT COUNT(*) FROM library_books b WHERE b.category_id=c.id) as book_count FROM library_categories c LEFT JOIN library_categories p ON p.id=c.parent_id ORDER BY c.sort_order ASC, c.name ASC')->fetchAll();
$root_cats   = array_filter($categories, fn($c) => !$c['parent_id']);
$dept_collections = [];
$facilities       = [];
try {
    $dept_collections = $pdo->query('SELECT * FROM library_dept_collections ORDER BY sort_order ASC, id ASC')->fetchAll();
    $facilities       = $pdo->query('SELECT * FROM library_facilities ORDER BY sort_order ASC, id ASC')->fetchAll();
} catch (Throwable $e) { /* tables may not exist yet */ }

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
    <ul class="nav nav-tabs mb-3 flex-wrap" id="settingsTabs">
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-info" type="button"><i class="fa fa-info-circle me-1"></i>Library Info</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rules" type="button"><i class="fa fa-gavel me-1"></i>Borrowing Rules</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-librarians" type="button"><i class="fa fa-id-badge me-1"></i>Librarians</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-categories" type="button"><i class="fa fa-tags me-1"></i>Categories</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-deptcol" type="button"><i class="fa fa-th-large me-1"></i>Dept. Collections</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-facilities" type="button"><i class="fa fa-building me-1"></i>Facilities</button></li>
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

        <!-- TAB: Dept. Collections -->
        <div class="tab-pane fade" id="tab-deptcol">
            <div class="card mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold"><i class="fa fa-th-large me-1 text-primary"></i>Department Collections</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDeptColModal">
                        <i class="fa fa-plus me-1"></i>Add Collection
                    </button>
                </div>
                <div class="card-body p-0">
                    <?php if (!$dept_collections): ?>
                        <div class="text-center text-muted py-4">No department collections yet. Run <code>library-v2.sql</code> and add collections above.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Preview</th>
                                    <th>Label</th>
                                    <th>Sub Label</th>
                                    <th>Icon</th>
                                    <th>Gradient</th>
                                    <th class="text-center">Sort</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dept_collections as $dc): ?>
                                <tr>
                                    <td>
                                        <?php if ($dc['image_file']): ?>
                                            <img src="<?= h(UPLOAD_URL) ?>/library/dept-collections/<?= h($dc['image_file']) ?>" alt="" style="width:60px;height:40px;object-fit:cover;border-radius:6px;">
                                        <?php else: ?>
                                            <div style="width:60px;height:40px;border-radius:6px;background:linear-gradient(150deg,<?= h($dc['color_from']) ?> 0%,<?= h($dc['color_to']) ?> 100%);display:flex;align-items:center;justify-content:center;">
                                                <i class="<?= h($dc['icon_class']) ?>" style="color:#fff;font-size:.85rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold"><?= h($dc['label']) ?></td>
                                    <td><?= h($dc['sub_label']) ?></td>
                                    <td><code><?= h($dc['icon_class']) ?></code></td>
                                    <td>
                                        <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= h($dc['color_from']) ?>;vertical-align:middle;"></span>
                                        → <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= h($dc['color_to']) ?>;vertical-align:middle;"></span>
                                    </td>
                                    <td class="text-center"><?= (int)$dc['sort_order'] ?></td>
                                    <td class="text-center">
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_dept_collection">
                                            <input type="hidden" name="dc_id" value="<?= $dc['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $dc['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                                <?= $dc['is_active'] ? 'Active' : 'Hidden' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editDeptColModal"
                                            data-id="<?= $dc['id'] ?>"
                                            data-label="<?= h($dc['label']) ?>"
                                            data-sublabel="<?= h($dc['sub_label']) ?>"
                                            data-icon="<?= h($dc['icon_class']) ?>"
                                            data-from="<?= h($dc['color_from']) ?>"
                                            data-to="<?= h($dc['color_to']) ?>"
                                            data-sort="<?= (int)$dc['sort_order'] ?>"
                                            data-active="<?= (int)$dc['is_active'] ?>"
                                            data-image="<?= $dc['image_file'] ? h(UPLOAD_URL).'/library/dept-collections/'.h($dc['image_file']) : '' ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this collection?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_dept_collection">
                                            <input type="hidden" name="dc_id" value="<?= $dc['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB: Library Facilities -->
        <div class="tab-pane fade" id="tab-facilities">
            <div class="card mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold"><i class="fa fa-building me-1 text-primary"></i>Library Facilities</span>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFacilityModal">
                        <i class="fa fa-plus me-1"></i>Add Facility
                    </button>
                </div>
                <div class="card-body p-0">
                    <?php if (!$facilities): ?>
                        <div class="text-center text-muted py-4">No facilities yet. Run <code>library-v2.sql</code> and add facilities above.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Icon Preview</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th class="text-center">Sort</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($facilities as $fac): ?>
                                <tr>
                                    <td>
                                        <div style="width:40px;height:40px;border-radius:8px;background:<?= h($fac['icon_bg_color']) ?>;display:flex;align-items:center;justify-content:center;">
                                            <i class="<?= h($fac['icon_class']) ?>" style="color:<?= h($fac['icon_text_color']) ?>;font-size:1rem;"></i>
                                        </div>
                                    </td>
                                    <td class="fw-semibold"><?= h($fac['name']) ?></td>
                                    <td style="max-width:260px;white-space:normal;font-size:.85rem;"><?= h($fac['description']) ?></td>
                                    <td class="text-center"><?= (int)$fac['sort_order'] ?></td>
                                    <td class="text-center">
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_facility">
                                            <input type="hidden" name="fac_id" value="<?= $fac['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $fac['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                                <?= $fac['is_active'] ? 'Active' : 'Hidden' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal" data-bs-target="#editFacilityModal"
                                            data-id="<?= $fac['id'] ?>"
                                            data-name="<?= h($fac['name']) ?>"
                                            data-desc="<?= h($fac['description']) ?>"
                                            data-icon="<?= h($fac['icon_class']) ?>"
                                            data-bg="<?= h($fac['icon_bg_color']) ?>"
                                            data-color="<?= h($fac['icon_text_color']) ?>"
                                            data-sort="<?= (int)$fac['sort_order'] ?>"
                                            data-active="<?= (int)$fac['is_active'] ?>">
                                            <i class="fa fa-edit"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this facility?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_facility">
                                            <input type="hidden" name="fac_id" value="<?= $fac['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /tab-content wrapper -->
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

// Edit Dept. Collection modal population
const editDeptColModal = document.getElementById('editDeptColModal');
if (editDeptColModal) {
    editDeptColModal.addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('editDcId').value       = btn.dataset.id;
        document.getElementById('editDcLabel').value    = btn.dataset.label;
        document.getElementById('editDcSubLabel').value = btn.dataset.sublabel;
        document.getElementById('editDcIcon').value     = btn.dataset.icon;
        document.getElementById('editDcFrom').value     = btn.dataset.from;
        document.getElementById('editDcTo').value       = btn.dataset.to;
        document.getElementById('editDcSort').value     = btn.dataset.sort;
        document.getElementById('editDcActive').checked = btn.dataset.active === '1';
        const wrap = document.getElementById('editDcImgWrap');
        const img  = document.getElementById('editDcImg');
        if (btn.dataset.image) { img.src = btn.dataset.image; wrap.classList.remove('d-none'); }
        else { wrap.classList.add('d-none'); }
    });
}

// Edit Facility modal population
const editFacilityModal = document.getElementById('editFacilityModal');
if (editFacilityModal) {
    editFacilityModal.addEventListener('show.bs.modal', function(e) {
        const btn = e.relatedTarget;
        document.getElementById('editFacId').value     = btn.dataset.id;
        document.getElementById('editFacName').value   = btn.dataset.name;
        document.getElementById('editFacDesc').value   = btn.dataset.desc;
        document.getElementById('editFacIcon').value   = btn.dataset.icon;
        document.getElementById('editFacBg').value     = btn.dataset.bg;
        document.getElementById('editFacColor').value  = btn.dataset.color;
        document.getElementById('editFacSort').value   = btn.dataset.sort;
        document.getElementById('editFacActive').checked = btn.dataset.active === '1';
    });
}
</script>

<!-- ── Add Dept. Collection Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="addDeptColModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_dept_collection">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-plus me-1"></i>Add Department Collection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Label <span class="text-danger">*</span></label>
                            <input type="text" name="dc_label" class="form-control" placeholder="e.g. CSE" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sub Label</label>
                            <input type="text" name="dc_sub_label" class="form-control" placeholder="e.g. Computer Science &amp; Eng.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Icon Class <small class="text-muted">(Font Awesome)</small></label>
                            <input type="text" name="dc_icon_class" class="form-control" value="fas fa-book" placeholder="fas fa-microchip">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gradient From</label>
                            <input type="color" name="dc_color_from" class="form-control form-control-color w-100" value="#0f2a6b">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gradient To</label>
                            <input type="color" name="dc_color_to" class="form-control form-control-color w-100" value="#1e4db7">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Background Image <small class="text-muted">(optional, max 5 MB)</small></label>
                            <input type="file" name="dc_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                            <div class="form-text">If set, the image overrides the gradient background.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="dc_sort_order" class="form-control" value="0">
                        </div>
                        <div class="col-md-3 d-flex align-items-end pb-1">
                            <div class="form-check">
                                <input type="checkbox" name="dc_is_active" class="form-check-input" id="addDcActive" checked>
                                <label class="form-check-label" for="addDcActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Add Collection</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Dept. Collection Modal ────────────────────────────────────────── -->
<div class="modal fade" id="editDeptColModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_dept_collection">
            <input type="hidden" name="dc_id" id="editDcId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-edit me-1"></i>Edit Department Collection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Label <span class="text-danger">*</span></label>
                            <input type="text" name="dc_label" id="editDcLabel" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sub Label</label>
                            <input type="text" name="dc_sub_label" id="editDcSubLabel" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Icon Class <small class="text-muted">(Font Awesome)</small></label>
                            <input type="text" name="dc_icon_class" id="editDcIcon" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gradient From</label>
                            <input type="color" name="dc_color_from" id="editDcFrom" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gradient To</label>
                            <input type="color" name="dc_color_to" id="editDcTo" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Replace Image <small class="text-muted">(leave blank to keep)</small></label>
                            <input type="file" name="dc_image" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                        </div>
                        <div class="col-md-6 d-none" id="editDcImgWrap">
                            <label class="form-label">Current Image</label>
                            <div class="d-flex align-items-center gap-2">
                                <img id="editDcImg" src="" alt="" style="height:50px;border-radius:6px;">
                                <div class="form-check">
                                    <input type="checkbox" name="dc_remove_image" class="form-check-input" id="editDcRemoveImg">
                                    <label class="form-check-label" for="editDcRemoveImg">Remove image</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="dc_sort_order" id="editDcSort" class="form-control">
                        </div>
                        <div class="col-md-3 d-flex align-items-end pb-1">
                            <div class="form-check">
                                <input type="checkbox" name="dc_is_active" class="form-check-input" id="editDcActive">
                                <label class="form-check-label" for="editDcActive">Active</label>
                            </div>
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

<!-- ── Add Facility Modal ─────────────────────────────────────────────────── -->
<div class="modal fade" id="addFacilityModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_facility">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-plus me-1"></i>Add Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="fac_name" class="form-control" placeholder="e.g. Reading Room" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Icon Class <small class="text-muted">(Font Awesome)</small></label>
                            <input type="text" name="fac_icon_class" class="form-control" value="fas fa-star" placeholder="fas fa-book-reader">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input type="text" name="fac_description" class="form-control" placeholder="Brief description of the facility">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Icon Background Color</label>
                            <input type="color" name="fac_icon_bg_color" class="form-control form-control-color w-100" value="#f9e8eb">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Icon Text Color</label>
                            <input type="color" name="fac_icon_text_color" class="form-control form-control-color w-100" value="#b5182e">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="fac_sort_order" class="form-control" value="0">
                        </div>
                        <div class="col-md-2 d-flex align-items-end pb-1">
                            <div class="form-check">
                                <input type="checkbox" name="fac_is_active" class="form-check-input" id="addFacActive" checked>
                                <label class="form-check-label" for="addFacActive">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Add Facility</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Facility Modal ────────────────────────────────────────────────── -->
<div class="modal fade" id="editFacilityModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_facility">
            <input type="hidden" name="fac_id" id="editFacId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-edit me-1"></i>Edit Facility</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="fac_name" id="editFacName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Icon Class <small class="text-muted">(Font Awesome)</small></label>
                            <input type="text" name="fac_icon_class" id="editFacIcon" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input type="text" name="fac_description" id="editFacDesc" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Icon Background Color</label>
                            <input type="color" name="fac_icon_bg_color" id="editFacBg" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Icon Text Color</label>
                            <input type="color" name="fac_icon_text_color" id="editFacColor" class="form-control form-control-color w-100">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="fac_sort_order" id="editFacSort" class="form-control">
                        </div>
                        <div class="col-md-2 d-flex align-items-end pb-1">
                            <div class="form-check">
                                <input type="checkbox" name="fac_is_active" class="form-check-input" id="editFacActive">
                                <label class="form-check-label" for="editFacActive">Active</label>
                            </div>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
