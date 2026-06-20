<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

require_access('clubs', 'can_edit');

$db   = db();
$id   = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT e.*, c.name AS club_name FROM club_events e JOIN clubs c ON c.id = e.club_id WHERE e.id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();
if (!$event) { flash_set('error', 'Event not found.'); redirect(APP_URL . '/clubs/index.php'); }

$page_title = 'Edit Event – ' . $event['title'];
$errors     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title        = trim($_POST['title']         ?? '');
    $description  = trim($_POST['description']   ?? '');
    $event_date   = $_POST['event_date']          ?? null;
    $event_time   = $_POST['event_time']          ?? null;
    $venue        = trim($_POST['venue']          ?? '');
    $capacity     = (int)($_POST['capacity']      ?? 0) ?: null;
    $reg_deadline = $_POST['reg_deadline']        ?? null;
    $is_published = isset($_POST['is_published']) ? 1 : 0;

    if ($title === '') $errors[] = 'Event title is required.';

    $cover_photo = $event['cover_photo'];
    if (empty($errors) && !empty($_FILES['cover_photo']['name'])) {
        try {
            clubs_delete_image(CLUB_UPLOAD_EVENTS, $event['cover_photo']);
            $cover_photo = clubs_upload_image($_FILES['cover_photo'], CLUB_UPLOAD_EVENTS);
        } catch (RuntimeException $e) { $errors[] = 'Cover: ' . $e->getMessage(); }
    }
    if (isset($_POST['remove_cover']) && $event['cover_photo']) {
        clubs_delete_image(CLUB_UPLOAD_EVENTS, $event['cover_photo']);
        $cover_photo = null;
    }

    if (empty($errors)) {
        $slug = unique_event_slug(clubs_slug($title), $id);

        $db->prepare(
            'UPDATE club_events SET title=?, slug=?, description=?, event_date=?, event_time=?, venue=?, capacity=?, registration_deadline=?, cover_photo=?, is_published=?, updated_at=NOW()
             WHERE id=?'
        )->execute([
            $title, $slug,
            $description ?: null,
            $event_date   ?: null,
            $event_time   ?: null,
            $venue        ?: null,
            $capacity,
            $reg_deadline ?: null,
            $cover_photo,
            $is_published,
            $id,
        ]);

        log_change('clubs', 'UPDATE', $id, $title, 'event', null, null, "Event '$title' updated for club '{$event['club_name']}'.");

        flash_set('success', 'Event updated.');
        redirect(APP_URL . '/clubs/event-view.php?id=' . $id);
    }
    save_old($_POST);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="fas fa-edit me-2 text-primary"></i>Edit Event</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/index.php">Clubs</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/clubs/view.php?id=<?= $event['club_id'] ?>"><?= h($event['club_name']) ?></a></li>
            <li class="breadcrumb-item active">Edit Event</li>
        </ol></nav>
    </div>
    <a href="<?= APP_URL ?>/clubs/event-view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
</div>

<?= flash_show() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white fw-semibold py-3"><i class="fas fa-calendar-day me-2"></i>Event Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Event Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= h(old('title', $event['title'])) ?>" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="5"><?= h(old('description', $event['description'])) ?></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Event Date</label>
                            <input type="date" name="event_date" class="form-control" value="<?= h(old('event_date', $event['event_date'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Event Time</label>
                            <input type="time" name="event_time" class="form-control" value="<?= h(old('event_time', $event['event_time'])) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Venue</label>
                            <input type="text" name="venue" class="form-control" value="<?= h(old('venue', $event['venue'])) ?>" maxlength="255">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Capacity <small class="text-muted">(blank = unlimited)</small></label>
                            <input type="number" name="capacity" class="form-control" value="<?= h(old('capacity', $event['capacity'])) ?>" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Registration Deadline</label>
                            <input type="date" name="reg_deadline" class="form-control" value="<?= h(old('reg_deadline', $event['registration_deadline'])) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-semibold py-3">Publish Settings</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_published" id="is_published" value="1" <?= old('is_published', $event['is_published']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_published">Publish Event</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cover Photo</label>
                        <?php if ($event['cover_photo']): ?>
                        <div class="mb-2"><img src="<?= CLUB_URL_EVENTS ?>/<?= h($event['cover_photo']) ?>" class="img-fluid rounded" style="max-height:100px;" alt=""></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="remove_cover" id="remove_cover"><label class="form-check-label small text-danger" for="remove_cover">Remove cover photo</label></div>
                        <?php endif; ?>
                        <input type="file" name="cover_photo" class="form-control" accept="image/*">
                        <div class="form-text">JPG/PNG/WebP ≤ 5MB</div>
                    </div>
                </div>
            </div>
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save me-2"></i>Save Changes</button>
                <a href="<?= APP_URL ?>/clubs/event-view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php clear_old(); require_once __DIR__ . '/../includes/footer.php'; ?>
