<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs');

$db   = db();
$id   = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare(
    'SELECT c.*, d.name AS dept_name, p.program_name AS program_name
     FROM clubs c
     LEFT JOIN dept_departments d ON d.id = c.dept_id
     LEFT JOIN dept_academic_programs p ON p.id = c.program_id
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$club = $stmt->fetch();
if (!$club) { flash_set('error', 'Club not found.'); redirect(APP_URL . '/clubs/index.php'); }

$page_title = $club['name'];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // Add member
    if ($action === 'add_member' && clubs_is_staff()) {
        $full_name     = trim($_POST['full_name']    ?? '');
        $student_id_no = trim($_POST['student_id_no'] ?? '');
        $role_position = trim($_POST['role_position'] ?? '');
        if ($full_name !== '') {
            $db->prepare('INSERT INTO club_members (club_id, full_name, student_id_no, role_position) VALUES (?,?,?,?)')
               ->execute([$id, $full_name, $student_id_no ?: null, $role_position ?: null]);
            $new_mid = (int)$db->lastInsertId();
            log_change('clubs', 'CREATE', $new_mid, $full_name, 'member', null, null, "Member '$full_name' added to club '{$club['name']}'.");
            flash_set('success', 'Member added.');
        }
        redirect(APP_URL . '/clubs/view.php?id=' . $id . '#members');
    }

    // Add gallery photo
    if ($action === 'add_photo' && clubs_is_staff()) {
        $caption = trim($_POST['caption'] ?? '');
        if (!empty($_FILES['photo']['name'])) {
            try {
                $stored = clubs_upload_image($_FILES['photo'], CLUB_UPLOAD_GALLERY);
                $db->prepare('INSERT INTO club_photos (club_id, caption, stored_name, original_name, uploaded_by) VALUES (?,?,?,?,?)')
                   ->execute([$id, $caption ?: null, $stored, $_FILES['photo']['name'], auth_user()['id']]);
                $new_pid = (int)$db->lastInsertId();
                log_change('clubs', 'CREATE', $new_pid, $caption ?: $stored, 'gallery_photo', null, null, "Gallery photo added to club '{$club['name']}'.");
                flash_set('success', 'Photo added to gallery.');
            } catch (RuntimeException $e) {
                flash_set('error', $e->getMessage());
            }
        }
        redirect(APP_URL . '/clubs/view.php?id=' . $id . '#gallery');
    }

    // Add activity
    if ($action === 'add_activity' && clubs_is_staff()) {
        $title         = trim($_POST['act_title']   ?? '');
        $description   = trim($_POST['act_desc']    ?? '');
        $activity_date = $_POST['act_date']         ?? null;
        $photo_stored  = null;
        if ($title !== '') {
            if (!empty($_FILES['act_photo']['name'])) {
                try {
                    $photo_stored = clubs_upload_image($_FILES['act_photo'], CLUB_UPLOAD_ACTIVITIES);
                } catch (RuntimeException $e) {
                    flash_set('error', $e->getMessage());
                    redirect(APP_URL . '/clubs/view.php?id=' . $id . '#activities');
                }
            }
            $db->prepare('INSERT INTO club_activities (club_id, title, description, activity_date, photo, created_by) VALUES (?,?,?,?,?,?)')
               ->execute([$id, $title, $description ?: null, $activity_date ?: null, $photo_stored, auth_user()['id']]);
            $new_aid = (int)$db->lastInsertId();
            log_change('clubs', 'CREATE', $new_aid, $title, 'activity', null, null, "Activity '$title' added to club '{$club['name']}'.");
            flash_set('success', 'Activity added.');
        }
        redirect(APP_URL . '/clubs/view.php?id=' . $id . '#activities');
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$st = $db->prepare('SELECT * FROM club_members WHERE club_id=? ORDER BY sort_order, id'); $st->execute([$id]); $members = $st->fetchAll();

$st = $db->prepare('SELECT * FROM club_photos WHERE club_id=? ORDER BY sort_order, id'); $st->execute([$id]); $photos = $st->fetchAll();

$st = $db->prepare('SELECT * FROM club_activities WHERE club_id=? ORDER BY activity_date DESC, id DESC'); $st->execute([$id]); $activities = $st->fetchAll();

$st = $db->prepare('SELECT e.*, (SELECT COUNT(*) FROM club_event_registrations r WHERE r.event_id=e.id) AS reg_count FROM club_events e WHERE e.club_id=? ORDER BY e.event_date DESC, e.id DESC'); $st->execute([$id]); $events = $st->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Hero Banner -->
<div class="position-relative rounded-3 overflow-hidden mb-4 shadow" style="min-height:200px;background:linear-gradient(135deg,#1abc9c,#16a085);">
    <?php if ($club['cover_photo']): ?>
    <img src="<?= CLUB_URL_COVERS ?>/<?= h($club['cover_photo']) ?>" alt="" class="position-absolute top-0 start-0 w-100 h-100" style="object-fit:cover;opacity:.35;">
    <?php endif; ?>
    <div class="position-relative p-4 d-flex align-items-end" style="min-height:200px;">
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <?php if ($club['logo']): ?>
            <img src="<?= CLUB_URL_LOGOS ?>/<?= h($club['logo']) ?>" alt="Logo" class="rounded-circle border border-3 border-white shadow" style="width:90px;height:90px;object-fit:cover;">
            <?php else: ?>
            <div class="rounded-circle border border-3 border-white shadow bg-white d-flex align-items-center justify-content-center" style="width:90px;height:90px;">
                <i class="fas fa-users fa-2x text-success"></i>
            </div>
            <?php endif; ?>
            <div>
                <h1 class="h2 text-white fw-bold mb-1"><?= h($club['name']) ?></h1>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?= clubs_status_badge($club['is_active']) ?>
                    <?php if ($club['dept_name']): ?><span class="badge bg-white text-dark"><i class="fas fa-building-columns me-1"></i><?= h($club['dept_name']) ?></span><?php endif; ?>
                    <?php if ($club['program_name']): ?><span class="badge bg-white bg-opacity-75 text-dark"><?= h($club['program_name']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Breadcrumb & Actions -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/index.php">Clubs</a></li>
        <li class="breadcrumb-item active"><?= h($club['name']) ?></li>
    </ol></nav>
    <div class="d-flex gap-2">
        <?php if (clubs_is_staff()): ?>
        <a href="<?= APP_URL ?>/clubs/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit</a>
        <a href="<?= APP_URL ?>/clubs/event-create.php?club_id=<?= $id ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-calendar-plus me-1"></i>New Event</a>
        <?php endif; ?>
        <?php if (clubs_can_delete()): ?>
        <a href="<?= APP_URL ?>/clubs/delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this club and all its data?')"><i class="fas fa-trash me-1"></i>Delete</a>
        <?php endif; ?>
    </div>
</div>

<?= flash_show() ?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-success"><?= count($members) ?></div>
            <div class="small text-muted">Members</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-primary"><?= count($events) ?></div>
            <div class="small text-muted">Events</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-warning"><?= count($activities) ?></div>
            <div class="small text-muted">Activities</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-3 fw-bold text-info"><?= count($photos) ?></div>
            <div class="small text-muted">Gallery Photos</div>
        </div>
    </div>
</div>

<!-- Info Cards Row -->
<div class="row g-4 mb-4">
    <?php if ($club['goal']): ?>
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-success fw-bold"><i class="fas fa-bullseye me-2"></i>Club Goal</h6>
                <p class="card-text text-muted"><?= nl2br(h($club['goal'])) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($club['facilities']): ?>
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-title text-primary fw-bold"><i class="fas fa-tools me-2"></i>Facilities</h6>
                <p class="card-text text-muted"><?= nl2br(h($club['facilities'])) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($club['notice']): ?>
    <div class="col-md-4">
        <div class="card h-100 border-0 shadow-sm border-start border-4 border-warning">
            <div class="card-body">
                <h6 class="card-title text-warning fw-bold"><i class="fas fa-bell me-2"></i>Club Notice</h6>
                <p class="card-text"><?= nl2br(h($club['notice'])) ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" id="clubTabs" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-members"  id="members"   ><i class="fas fa-users me-1"></i>Members <span class="badge bg-secondary ms-1"><?= count($members) ?></span></button></li>
    <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-events"   id="events"    ><i class="fas fa-calendar me-1"></i>Events <span class="badge bg-secondary ms-1"><?= count($events) ?></span></button></li>
    <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-gallery"  id="gallery"   ><i class="fas fa-images me-1"></i>Gallery <span class="badge bg-secondary ms-1"><?= count($photos) ?></span></button></li>
    <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#tab-activity" id="activities"><i class="fas fa-running me-1"></i>Activities <span class="badge bg-secondary ms-1"><?= count($activities) ?></span></button></li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom shadow-sm bg-white p-4">

    <!-- ── Members ── -->
    <div class="tab-pane fade show active" id="tab-members">
        <?php if (clubs_is_staff()): ?>
        <div class="mb-4">
            <button class="btn btn-sm btn-success mb-3" data-bs-toggle="collapse" data-bs-target="#add-member-form"><i class="fas fa-user-plus me-1"></i>Add Member</button>
            <div class="collapse" id="add-member-form">
                <div class="card border-success border-opacity-25 mb-3">
                    <div class="card-body">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_member">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Student ID</label>
                                    <input type="text" name="student_id_no" class="form-control form-control-sm" maxlength="30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Role / Position</label>
                                    <input type="text" name="role_position" class="form-control form-control-sm" maxlength="100">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button class="btn btn-success btn-sm w-100"><i class="fas fa-save me-1"></i>Add</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($members)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-user-slash fa-2x mb-2 d-block opacity-25"></i>No members yet.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr><th>#</th><th>Full Name</th><th>Student ID</th><th>Role / Position</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                <?php $n=1; foreach ($members as $m): ?>
                <tr>
                    <td class="text-muted small"><?= $n++ ?></td>
                    <td class="fw-semibold"><?= h($m['full_name']) ?></td>
                    <td><?= h($m['student_id_no'] ?? '—') ?></td>
                    <td><?php if ($m['role_position']): ?><span class="badge bg-light text-dark border"><?= h($m['role_position']) ?></span><?php else: ?>—<?php endif; ?></td>
                    <td class="text-end">
                        <?php if (clubs_can_delete()): ?>
                        <a href="<?= APP_URL ?>/clubs/member-delete.php?id=<?= $m['id'] ?>&club_id=<?= $id ?>"
                           class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this member?')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Events ── -->
    <div class="tab-pane fade" id="tab-events">
        <?php if (clubs_is_staff()): ?>
        <div class="mb-3">
            <a href="<?= APP_URL ?>/clubs/event-create.php?club_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="fas fa-calendar-plus me-1"></i>New Event</a>
        </div>
        <?php endif; ?>
        <?php if (empty($events)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-calendar-times fa-2x mb-2 d-block opacity-25"></i>No events yet.</div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($events as $ev): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <?php if ($ev['cover_photo']): ?>
                <img src="<?= CLUB_URL_EVENTS ?>/<?= h($ev['cover_photo']) ?>" class="card-img-top" style="height:140px;object-fit:cover;" alt="">
                <?php else: ?>
                <div class="bg-gradient d-flex align-items-center justify-content-center" style="height:100px;background:linear-gradient(135deg,#667eea,#764ba2);">
                    <i class="fas fa-calendar-day fa-2x text-white opacity-75"></i>
                </div>
                <?php endif; ?>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="card-title fw-bold mb-0"><?= h($ev['title']) ?></h6>
                        <?= $ev['is_published'] ? '<span class="badge bg-success">Published</span>' : '<span class="badge bg-warning text-dark">Draft</span>' ?>
                    </div>
                    <?php if ($ev['event_date']): ?><div class="small text-muted mb-1"><i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($ev['event_date'])) ?><?php if ($ev['event_time']): ?> at <?= date('h:i A', strtotime($ev['event_time'])) ?><?php endif; ?></div><?php endif; ?>
                    <?php if ($ev['venue']): ?><div class="small text-muted mb-2"><i class="fas fa-map-marker-alt me-1"></i><?= h($ev['venue']) ?></div><?php endif; ?>
                    <div class="small text-muted mb-3"><i class="fas fa-users me-1"></i><?= $ev['reg_count'] ?> registration<?= $ev['reg_count'] != 1 ? 's' : '' ?></div>
                    <div class="d-flex gap-1 flex-wrap">
                        <a href="<?= APP_URL ?>/clubs/event-view.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye me-1"></i>View</a>
                        <?php if (clubs_is_staff()): ?>
                        <a href="<?= APP_URL ?>/clubs/event-edit.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit me-1"></i>Edit</a>
                        <?php endif; ?>
                        <?php if (clubs_can_delete()): ?>
                        <a href="<?= APP_URL ?>/clubs/event-delete.php?id=<?= $ev['id'] ?>&club_id=<?= $id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this event?')"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Gallery ── -->
    <div class="tab-pane fade" id="tab-gallery">
        <?php if (clubs_is_staff()): ?>
        <div class="mb-4">
            <button class="btn btn-sm btn-success mb-3" data-bs-toggle="collapse" data-bs-target="#add-photo-form"><i class="fas fa-camera me-1"></i>Upload Photo</button>
            <div class="collapse" id="add-photo-form">
                <div class="card border-success border-opacity-25 mb-3">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_photo">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold">Photo <span class="text-danger">*</span></label>
                                    <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Caption</label>
                                    <input type="text" name="caption" class="form-control form-control-sm" maxlength="300">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button class="btn btn-success btn-sm w-100"><i class="fas fa-upload me-1"></i>Upload</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (empty($photos)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-images fa-2x mb-2 d-block opacity-25"></i>No photos in gallery.</div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($photos as $ph): ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card border-0 shadow-sm h-100 overflow-hidden">
                <a href="<?= CLUB_URL_GALLERY ?>/<?= h($ph['stored_name']) ?>" target="_blank">
                    <img src="<?= CLUB_URL_GALLERY ?>/<?= h($ph['stored_name']) ?>" class="card-img-top" style="height:160px;object-fit:cover;" alt="<?= h($ph['caption'] ?? '') ?>">
                </a>
                <?php if ($ph['caption'] || clubs_can_delete()): ?>
                <div class="card-body py-2 px-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted text-truncate"><?= h($ph['caption'] ?? '') ?></span>
                    <?php if (clubs_can_delete()): ?>
                    <a href="<?= APP_URL ?>/clubs/photo-delete.php?id=<?= $ph['id'] ?>&club_id=<?= $id ?>" class="btn btn-sm btn-link text-danger p-0 ms-1" onclick="return confirm('Delete photo?')"><i class="fas fa-trash-alt"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Activities ── -->
    <div class="tab-pane fade" id="tab-activity">
        <?php if (clubs_is_staff()): ?>
        <div class="mb-4">
            <button class="btn btn-sm btn-success mb-3" data-bs-toggle="collapse" data-bs-target="#add-activity-form"><i class="fas fa-plus me-1"></i>Add Activity</button>
            <div class="collapse" id="add-activity-form">
                <div class="card border-success border-opacity-25 mb-3">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_activity">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Title <span class="text-danger">*</span></label>
                                    <input type="text" name="act_title" class="form-control form-control-sm" required maxlength="255">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-semibold">Date</label>
                                    <input type="date" name="act_date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Photo</label>
                                    <input type="file" name="act_photo" class="form-control form-control-sm" accept="image/*">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small fw-semibold">Description</label>
                                    <textarea name="act_desc" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-success btn-sm w-100"><i class="fas fa-save me-1"></i>Save Activity</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (empty($activities)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-running fa-2x mb-2 d-block opacity-25"></i>No activities recorded.</div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($activities as $act): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <?php if ($act['photo']): ?>
                <img src="<?= CLUB_URL_ACTIVITIES ?>/<?= h($act['photo']) ?>" class="card-img-top" style="height:160px;object-fit:cover;" alt="">
                <?php endif; ?>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="card-title fw-bold mb-0"><?= h($act['title']) ?></h6>
                        <?php if (clubs_can_delete()): ?>
                        <a href="<?= APP_URL ?>/clubs/activity-delete.php?id=<?= $act['id'] ?>&club_id=<?= $id ?>" class="btn btn-sm btn-link text-danger p-0 ms-2" onclick="return confirm('Delete this activity?')"><i class="fas fa-trash-alt"></i></a>
                        <?php endif; ?>
                    </div>
                    <?php if ($act['activity_date']): ?><div class="small text-muted mb-2"><i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($act['activity_date'])) ?></div><?php endif; ?>
                    <?php if ($act['description']): ?><p class="card-text small text-muted"><?= nl2br(h($act['description'])) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Activate tab from URL hash
const hash = window.location.hash.replace('#','');
const map  = {members:'#tab-members', gallery:'#tab-gallery', activities:'#tab-activity', events:'#tab-events'};
if (map[hash]) {
    const trigger = document.querySelector('[data-bs-target="'+map[hash]+'"]');
    if (trigger) new bootstrap.Tab(trigger).show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
