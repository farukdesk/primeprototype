<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_super_admin();

$dept_id = (int)($_GET['dept_id'] ?? $_POST['dept_id'] ?? 0);
if (!$dept_id) { flash_set('error', 'Invalid department.'); redirect(APP_URL . '/departments/index.php'); }

$dept = db()->prepare('SELECT * FROM dept_departments WHERE id = ?');
$dept->execute([$dept_id]);
$dept = $dept->fetch();
if (!$dept) { flash_set('error', 'Department not found.'); redirect(APP_URL . '/departments/index.php'); }

$page_title = 'Add Event – ' . $dept['name'];
$errors = [];
clear_old();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $title       = trim($_POST['title']       ?? '');
    $event_date  = trim($_POST['event_date']  ?? '');
    $location    = trim($_POST['location']    ?? '');
    $description = trim($_POST['description'] ?? '');
    $link_url    = trim($_POST['link_url']    ?? '');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') $errors[] = 'Title is required.';

    if (empty($errors)) {
        db()->prepare(
            'INSERT INTO dept_events (dept_id, title, event_date, location, description, link_url, is_active)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$dept_id, $title, $event_date ?: null, $location ?: null,
                    $description ?: null, $link_url ?: null, $is_active]);

        flash_set('success', "Event <strong>" . h($title) . "</strong> added.");
        redirect(APP_URL . '/departments/events/index.php?dept_id=' . $dept_id);
    }

    save_old(compact('title','event_date','location','description','link_url'));
}

require_once __DIR__ . '/../../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/index.php">Departments</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/view.php?id=<?= $dept_id ?>"><?= h($dept['name']) ?></a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/departments/events/index.php?dept_id=<?= $dept_id ?>">Events</a></li>
            <li class="breadcrumb-item active">Add</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-calendar-plus me-2 text-muted"></i>Add Event</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="dept_id" value="<?= $dept_id ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-medium">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" style="border-radius:10px;"
                           value="<?= old('title') ?>" required maxlength="300">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Event Date</label>
                    <input type="date" name="event_date" class="form-control" style="border-radius:10px;"
                           value="<?= old('event_date') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Location</label>
                    <input type="text" name="location" class="form-control" style="border-radius:10px;"
                           value="<?= old('location') ?>" maxlength="300">
                </div>
                <div class="col-12">
                    <label class="form-label fw-medium">Description</label>
                    <textarea name="description" class="form-control" style="border-radius:10px;" rows="4"><?= old('description') ?></textarea>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Link URL</label>
                    <input type="url" name="link_url" class="form-control" style="border-radius:10px;"
                           value="<?= old('link_url') ?>" maxlength="500">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Event
                </button>
                <a href="<?= APP_URL ?>/departments/events/index.php?dept_id=<?= $dept_id ?>"
                   class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
