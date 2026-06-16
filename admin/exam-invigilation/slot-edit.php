<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation', 'can_edit');

$sid     = (int)($_GET['id'] ?? 0);
$exam_id = (int)($_GET['exam_id'] ?? 0);

$slot_st = db()->prepare('SELECT * FROM ei_slots WHERE id = ? AND exam_id = ?');
$slot_st->execute([$sid, $exam_id]);
$slot = $slot_st->fetch();
if (!$slot) {
    flash_set('error', 'Slot not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$exam_id]);
$exam = $exam_st->fetch();

$page_title = 'Edit Slot';
$errors     = [];
clear_old();

$faculty_list = ei_get_faculty_list();
[$slot_start_time, $slot_end_time] = ei_parse_time_slot_range((string)($slot['time_slot'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $slot_date   = trim($_POST['slot_date']   ?? '');
    $start_time  = trim($_POST['start_time']  ?? '');
    $end_time    = trim($_POST['end_time']    ?? '');
    $room_number = trim($_POST['room_number'] ?? '');
    $faculty1_id = (int)($_POST['faculty1_id'] ?? 0) ?: null;
    $faculty2_id = (int)($_POST['faculty2_id'] ?? 0) ?: null;
    $time_slot   = ei_normalize_time_slot_range($start_time, $end_time);

    if ($slot_date === '')   $errors[] = 'Date is required.';
    if ($start_time === '' || $end_time === '') $errors[] = 'Start time and end time are required.';
    elseif (!$time_slot)     $errors[] = 'Enter a valid time range with end time after start time.';
    if ($room_number === '') $errors[] = 'Room number is required.';
    if ($faculty1_id && $faculty2_id && $faculty1_id === $faculty2_id)
        $errors[] = 'Invigilator 1 and Invigilator 2 must be different people.';

    if (empty($errors)) {
        db()->prepare(
            'UPDATE ei_slots SET slot_date=?, time_slot=?, room_number=?, faculty1_id=?, faculty2_id=? WHERE id=?'
        )->execute([$slot_date, $time_slot, $room_number, $faculty1_id, $faculty2_id, $sid]);
        flash_set('success', 'Slot updated.');
        redirect(APP_URL . '/exam-invigilation/view.php?id=' . $exam_id);
    }
    save_old(compact('slot_date','start_time','end_time','room_number','faculty1_id','faculty2_id'));
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $exam_id ?>"><?= h($exam['exam_name'] ?? 'Exam') ?></a>
            </li>
            <li class="breadcrumb-item active">Edit Slot</li>
        </ol>
    </nav>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold"><i class="fas fa-edit me-2 text-muted"></i>Edit Slot</h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Date <span class="text-danger">*</span></label>
                    <input type="date" name="slot_date" class="form-control" style="border-radius:10px;"
                           value="<?= old('slot_date', $slot['slot_date']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Start Time <span class="text-danger">*</span></label>
                    <input type="time" name="start_time" class="form-control" style="border-radius:10px;"
                           value="<?= old('start_time', $slot_start_time) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">End Time <span class="text-danger">*</span></label>
                    <input type="time" name="end_time" class="form-control" style="border-radius:10px;"
                           value="<?= old('end_time', $slot_end_time) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Room Number <span class="text-danger">*</span></label>
                    <input type="text" name="room_number" class="form-control" style="border-radius:10px;"
                           value="<?= old('room_number', $slot['room_number']) ?>" required maxlength="50">
                </div>
                <div class="col-12">
                    <small class="text-muted">Saved format: <code>09:00 AM – 12:00 PM</code></small>
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-6">
                    <label class="form-label fw-medium">Invigilator 1</label>
                    <select name="faculty1_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Not assigned —</option>
                        <?php
                        $sel1 = (int)(old('faculty1_id') ?: ($slot['faculty1_id'] ?? 0));
                        $last_dept = '';
                        foreach ($faculty_list as $f):
                            if ($f['dept_name'] !== $last_dept):
                                if ($last_dept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . h($f['dept_name']) . '">';
                                $last_dept = $f['dept_name'];
                            endif;
                        ?>
                        <option value="<?= $f['id'] ?>" <?= $sel1 == $f['id'] ? 'selected' : '' ?>>
                            <?= h(ei_format_faculty_option_label($f)) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($last_dept !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Invigilator 2 <span class="text-muted">(different dept)</span></label>
                    <select name="faculty2_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Not assigned —</option>
                        <?php
                        $sel2 = (int)(old('faculty2_id') ?: ($slot['faculty2_id'] ?? 0));
                        $last_dept = '';
                        foreach ($faculty_list as $f):
                            if ($f['dept_name'] !== $last_dept):
                                if ($last_dept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . h($f['dept_name']) . '">';
                                $last_dept = $f['dept_name'];
                            endif;
                        ?>
                        <option value="<?= $f['id'] ?>" <?= $sel2 == $f['id'] ? 'selected' : '' ?>>
                            <?= h(ei_format_faculty_option_label($f)) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($last_dept !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Update Slot
                </button>
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $exam_id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
