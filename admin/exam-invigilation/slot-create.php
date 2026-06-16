<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/slot-helpers.php';
require_access('exam-invigilation', 'can_create');

$exam_id = (int)($_GET['exam_id'] ?? 0);
$exam_st = db()->prepare('SELECT * FROM ei_exams WHERE id = ?');
$exam_st->execute([$exam_id]);
$exam = $exam_st->fetch();
if (!$exam) {
    flash_set('error', 'Exam not found.');
    redirect(APP_URL . '/exam-invigilation/index.php');
}

$page_title = 'Add Slot – ' . $exam['exam_name'];
$errors     = [];
$import_summary = null;
clear_old();

$faculty_list  = ei_get_faculty_list();
$dept_list     = ei_get_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save_slot';

    if ($action === 'import_csv') {
        $normalize_header = static function (string $header): string {
            return strtolower(trim(str_replace(['-', ' '], '_', $header)));
        };
        $find_column = static function (array $header_map, array $aliases): ?int {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $header_map)) return (int)$header_map[$alias];
            }
            return null;
        };

        if (!isset($_FILES['csv_file']) || (int)($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a valid CSV file.';
        }

        if (empty($errors)) {
            $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
            if (!$fh) {
                $errors[] = 'Unable to read uploaded CSV file.';
            } else {
                $headers = fgetcsv($fh, 0, ',', '"', '');
                if ($headers === false || empty($headers)) {
                    $errors[] = 'CSV header row is missing.';
                } else {
                    $header_map = [];
                    foreach ($headers as $idx => $header) {
                        $header_map[$normalize_header((string)$header)] = $idx;
                    }

                    $date_idx      = $find_column($header_map, ['slot_date', 'date', 'exam_date']);
                    $room_idx      = $find_column($header_map, ['room_number', 'room', 'room_no']);
                    $time_slot_idx = $find_column($header_map, ['time_slot', 'time']);
                    $start_idx     = $find_column($header_map, ['start_time', 'start']);
                    $end_idx       = $find_column($header_map, ['end_time', 'end']);
                    $dept_idx      = $find_column($header_map, ['dept_id', 'department', 'dept', 'department_id']);

                    if ($date_idx === null) $errors[] = 'Missing required column: slot_date (or date).';
                    if ($room_idx === null) $errors[] = 'Missing required column: room_number (or room).';
                    if ($time_slot_idx === null && ($start_idx === null || $end_idx === null)) {
                        $errors[] = 'Provide either time_slot or both start_time and end_time columns.';
                    }

                    if (empty($errors)) {
                        // Build a name→id map for departments so CSV can supply a name string
                        $dept_name_map = [];
                        foreach ($dept_list as $d) {
                            $dept_name_map[strtolower(trim((string)$d['name']))] = (int)$d['id'];
                        }

                        $insert_st = db()->prepare(
                            'INSERT INTO ei_slots (exam_id, slot_date, time_slot, room_number, dept_id, faculty1_id, faculty2_id)
                             VALUES (?,?,?,?,?,NULL,NULL)'
                        );
                        $exists_st = db()->prepare(
                            'SELECT id FROM ei_slots WHERE exam_id = ? AND slot_date = ? AND time_slot = ? AND room_number = ? LIMIT 1'
                        );

                        $created = 0;
                        $failed = 0;
                        $row_no = 1;
                        $row_errors = [];

                        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                            $row_no++;

                            $date_raw = trim((string)($row[$date_idx] ?? ''));
                            $room_number = trim((string)($row[$room_idx] ?? ''));
                            $time_slot_raw = $time_slot_idx !== null ? trim((string)($row[$time_slot_idx] ?? '')) : '';
                            $start_time = $start_idx !== null ? trim((string)($row[$start_idx] ?? '')) : '';
                            $end_time = $end_idx !== null ? trim((string)($row[$end_idx] ?? '')) : '';
                            $dept_raw = $dept_idx !== null ? trim((string)($row[$dept_idx] ?? '')) : '';

                            if ($date_raw === '' && $room_number === '' && $time_slot_raw === '' && $start_time === '' && $end_time === '') {
                                continue;
                            }

                            $slot_date = ei_normalize_slot_date($date_raw);
                            if (!$slot_date) {
                                $failed++;
                                $row_errors[] = "Row {$row_no}: invalid slot_date '{$date_raw}'. Use YYYY-MM-DD.";
                                continue;
                            }

                            if ($room_number === '') {
                                $failed++;
                                $row_errors[] = "Row {$row_no}: room_number is required.";
                                continue;
                            }

                            if ($time_slot_raw !== '') {
                                [$parsed_start, $parsed_end] = ei_parse_time_slot_range($time_slot_raw);
                                $time_slot = ($parsed_start !== '' && $parsed_end !== '')
                                    ? ei_normalize_time_slot_range($parsed_start, $parsed_end)
                                    : null;
                            } else {
                                $time_slot = ei_normalize_time_slot_range($start_time, $end_time);
                            }

                            if (!$time_slot) {
                                $failed++;
                                $row_errors[] = "Row {$row_no}: invalid time format.";
                                continue;
                            }

                            $exists_st->execute([$exam_id, $slot_date, $time_slot, $room_number]);
                            if ($exists_st->fetchColumn()) {
                                $failed++;
                                $row_errors[] = "Row {$row_no}: duplicate slot already exists for {$slot_date}, {$time_slot}, room {$room_number}.";
                                continue;
                            }

                            // Resolve dept_id: accept numeric ID or department name
                            $csv_dept_id = null;
                            if ($dept_raw !== '') {
                                if (ctype_digit($dept_raw) && isset($dept_name_map[strtolower($dept_raw)]) === false) {
                                    $csv_dept_id = (int)$dept_raw ?: null;
                                } else {
                                    $csv_dept_id = $dept_name_map[strtolower($dept_raw)] ?? null;
                                }
                            }

                            $insert_st->execute([$exam_id, $slot_date, $time_slot, $room_number, $csv_dept_id]);
                            $created++;
                        }

                        $import_summary = [
                            'created' => $created,
                            'failed' => $failed,
                            'row_errors' => $row_errors,
                        ];

                        if ($created > 0) {
                            flash_set('success', "CSV import complete. Added {$created} slot(s)." . ($failed > 0 ? " {$failed} row(s) failed." : ''));
                        } elseif ($failed > 0) {
                            flash_set('warning', "No slots imported. {$failed} row(s) failed validation.");
                        } else {
                            flash_set('info', 'No data rows found in CSV.');
                        }
                    }
                }

                fclose($fh);
            }
        }
    } else {
        $slot_date   = trim($_POST['slot_date']   ?? '');
        $start_time  = trim($_POST['start_time']  ?? '');
        $end_time    = trim($_POST['end_time']    ?? '');
        $room_number = trim($_POST['room_number'] ?? '');
        $dept_id     = (int)($_POST['dept_id']    ?? 0) ?: null;
        $faculty1_id = (int)($_POST['faculty1_id'] ?? 0) ?: null;
        $faculty2_id = (int)($_POST['faculty2_id'] ?? 0) ?: null;
        $time_slot   = ei_normalize_time_slot_range($start_time, $end_time);

        if ($slot_date === '')   $errors[] = 'Date is required.';
        if ($start_time === '' || $end_time === '') $errors[] = 'Start time and end time are required.';
        elseif (!$time_slot)     $errors[] = 'Enter a valid time range with end time after start time.';
        if ($room_number === '') $errors[] = 'Room number is required.';
        if ($faculty1_id && $faculty2_id && $faculty1_id === $faculty2_id) {
            $errors[] = 'Invigilator 1 and Invigilator 2 must be different people.';
        }
        // Overlap check: ensure neither invigilator is already assigned at the same date+time
        if ($faculty1_id && $time_slot && ei_faculty_has_overlap($faculty1_id, $slot_date, $time_slot)) {
            $errors[] = 'Invigilator 1 is already assigned to another room at this date and time.';
        }
        if ($faculty2_id && $time_slot && ei_faculty_has_overlap($faculty2_id, $slot_date, $time_slot)) {
            $errors[] = 'Invigilator 2 is already assigned to another room at this date and time.';
        }

        if (empty($errors)) {
            db()->prepare(
                'INSERT INTO ei_slots (exam_id, slot_date, time_slot, room_number, dept_id, faculty1_id, faculty2_id)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$exam_id, $slot_date, $time_slot, $room_number, $dept_id, $faculty1_id, $faculty2_id]);
            flash_set('success', 'Slot added.');
            if (isset($_POST['add_another'])) {
                save_old(['slot_date' => $slot_date, 'start_time' => $start_time, 'end_time' => $end_time]);
                redirect(APP_URL . '/exam-invigilation/slot-create.php?exam_id=' . $exam_id);
            }
            redirect(APP_URL . '/exam-invigilation/view.php?id=' . $exam_id);
        }
        save_old(compact('slot_date','start_time','end_time','room_number','dept_id','faculty1_id','faculty2_id'));
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/exam-invigilation/index.php">Exam Invigilation</a></li>
            <li class="breadcrumb-item">
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $exam_id ?>"><?= h($exam['exam_name']) ?></a>
            </li>
            <li class="breadcrumb-item active">Add Slot</li>
        </ol>
    </nav>
</div>

<?php flash_show(); ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-7">
<div class="card h-100">
    <div class="card-header py-3 px-4">
        <h6 class="mb-0 fw-semibold">
            <i class="fas fa-plus me-2 text-muted"></i>
            Add Slot — <span class="text-primary"><?= h($exam['exam_name']) ?> (<?= h($exam['exam_year']) ?>)</span>
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_slot">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-medium">Date <span class="text-danger">*</span></label>
                    <input type="date" name="slot_date" class="form-control" style="border-radius:10px;"
                           value="<?= old('slot_date') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Start Time <span class="text-danger">*</span></label>
                    <input type="time" name="start_time" class="form-control" style="border-radius:10px;"
                           value="<?= old('start_time') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">End Time <span class="text-danger">*</span></label>
                    <input type="time" name="end_time" class="form-control" style="border-radius:10px;"
                           value="<?= old('end_time') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium">Room Number <span class="text-danger">*</span></label>
                    <input type="text" name="room_number" class="form-control" style="border-radius:10px;"
                           value="<?= old('room_number') ?>" required maxlength="50"
                           placeholder="e.g. Room 301">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-medium">Preferred Department <span class="text-muted">(for Invigilator 1 in auto-assign)</span></label>
                    <select name="dept_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Any department —</option>
                        <?php foreach ($dept_list as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= old('dept_id') == $dept['id'] ? 'selected' : '' ?>>
                            <?= h($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <p class="text-muted mb-2" style="font-size:.85rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Optionally assign invigilators now, or leave blank and use Auto-Assign later.
                    </p>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-medium">Invigilator 1</label>
                    <select name="faculty1_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Leave for Auto-Assign —</option>
                        <?php
                        $last_dept = '';
                        foreach ($faculty_list as $f):
                            if ($f['dept_name'] !== $last_dept):
                                if ($last_dept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . h($f['dept_name']) . '">';
                                $last_dept = $f['dept_name'];
                            endif;
                        ?>
                        <option value="<?= $f['id'] ?>"
                            <?= old('faculty1_id') == $f['id'] ? 'selected' : '' ?>>
                            <?= h(ei_format_faculty_option_label($f)) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($last_dept !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Invigilator 2 <span class="text-muted">(different dept)</span></label>
                    <select name="faculty2_id" class="form-select" style="border-radius:10px;">
                        <option value="0">— Leave for Auto-Assign —</option>
                        <?php
                        $last_dept = '';
                        foreach ($faculty_list as $f):
                            if ($f['dept_name'] !== $last_dept):
                                if ($last_dept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . h($f['dept_name']) . '">';
                                $last_dept = $f['dept_name'];
                            endif;
                        ?>
                        <option value="<?= $f['id'] ?>"
                            <?= old('faculty2_id') == $f['id'] ? 'selected' : '' ?>>
                            <?= h(ei_format_faculty_option_label($f)) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($last_dept !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4 flex-wrap">
                <button type="submit" class="btn btn-success" style="border-radius:10px;">
                    <i class="fas fa-save me-1"></i> Save Slot
                </button>
                <button type="submit" name="add_another" value="1" class="btn btn-outline-success" style="border-radius:10px;">
                    <i class="fas fa-plus me-1"></i> Save &amp; Add Another
                </button>
                <a href="<?= APP_URL ?>/exam-invigilation/view.php?id=<?= $exam_id ?>" class="btn btn-light" style="border-radius:10px;">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
    <div class="col-lg-5" id="csv-upload">
    <div class="card h-100">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-file-csv me-2 text-muted"></i>
                Bulk Upload Slots (CSV)
            </h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="import_csv">
                <div class="mb-3">
                    <label class="form-label fw-medium">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-outline-primary" style="border-radius:10px;">
                    <i class="fas fa-upload me-1"></i> Import CSV
                </button>
            </form>
            <hr>
            <p class="mb-2">Required columns: <code>slot_date</code>, <code>room_number</code>, and either <code>time_slot</code> or both <code>start_time</code> + <code>end_time</code>.</p>
            <p class="mb-2">Optional column: <code>dept_id</code> (or <code>department</code>) — preferred department for Invigilator 1 auto-assign; accepts a numeric ID or department name.</p>
            <p class="mb-2">Accepted date format: <code>YYYY-MM-DD</code>.</p>
            <p class="mb-0">Accepted time formats: <code>09:00</code>, <code>9:00 AM</code>, or full range like <code>09:00 AM – 12:00 PM</code>.</p>
            <hr>
            <pre class="mb-0" style="font-size:.78rem;white-space:pre-wrap;">slot_date,start_time,end_time,room_number,department
2026-06-20,09:00,12:00,Room 301,Computer Science
2026-06-20,13:00,16:00,Room 302,</pre>
        </div>
    </div>
    </div>
    </div>

    <?php if ($import_summary && !empty($import_summary['row_errors'])): ?>
    <div class="card mt-3">
        <div class="card-header py-2 px-4"><strong>CSV Row Errors</strong></div>
        <div class="card-body">
            <ul class="mb-0 ps-3">
                <?php foreach ($import_summary['row_errors'] as $row_error): ?>
                <li><?= h($row_error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
