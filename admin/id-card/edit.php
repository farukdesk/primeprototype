<?php
/**
 * ID Card – Edit
 * Every field of an existing card is editable, including the photo
 * (upload a new one, keep the current one, or remove it).
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card', 'can_edit');
require_once __DIR__ . '/helpers.php';

$page_title = 'Edit ID Card';
$errors = [];
$db = db();

$id   = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$card = $id > 0 ? idc_get_card($id) : false;
if (!$card) {
    flash_set('danger', 'ID card not found.');
    redirect(APP_URL . '/id-card/index.php');
}

$pre = [
    'card_type'    => $card['card_type'],
    'id_number'    => $card['id_number'],
    'full_name'    => $card['full_name'],
    'program_name' => (string)($card['program_name'] ?? ''),
    'dept_name'    => (string)($card['dept_name'] ?? ''),
    'designation'  => (string)($card['designation'] ?? ''),
    'batch_name'   => (string)($card['batch_name'] ?? ''),
    'blood_group'  => (string)($card['blood_group'] ?? ''),
    'phone'        => (string)($card['phone'] ?? ''),
    'address'      => (string)($card['address'] ?? ''),
    'photo'        => (string)($card['photo'] ?? ''),
    'issue_date'   => (string)($card['issue_date'] ?? ''),
    'expiry_date'  => (string)($card['expiry_date'] ?? ''),
    'print_status' => trim((string)($card['print_status'] ?? '')) ?: 'in_printing_queue',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $card_type    = $_POST['card_type'] ?? 'student';
    $id_number    = trim($_POST['id_number']    ?? '');
    $full_name    = trim($_POST['full_name']    ?? '');
    $program_name = trim($_POST['program_name'] ?? '');
    $dept_name    = trim($_POST['dept_name']    ?? '');
    $designation  = trim($_POST['designation']  ?? '');
    $batch_name   = trim($_POST['batch_name']   ?? '');
    $blood_group  = trim($_POST['blood_group']  ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $address      = trim($_POST['address']      ?? '');
    $photo        = trim($_POST['photo']        ?? '');
    $issue_date   = trim($_POST['issue_date']   ?? '');
    $expiry_date  = trim($_POST['expiry_date']  ?? '');
    $print_status = trim($_POST['print_status'] ?? 'in_printing_queue');
    $remove_photo = (int)($_POST['remove_photo'] ?? 0) === 1;

    if (!isset(IDC_PRINT_STATUSES[$print_status])) $errors[] = 'Invalid print status.';

    if (!isset(IDC_TYPES[$card_type])) $errors[] = 'Invalid card type.';
    if ($id_number === '')             $errors[] = 'ID number is required.';
    if ($full_name === '')             $errors[] = 'Full name is required.';

    // Photo: a newly uploaded file wins; "remove" clears; otherwise keep the path field.
    if (!$errors) {
        try {
            $uploaded = idc_store_photo($_FILES['photo_file'] ?? []);
            if ($uploaded !== null) {
                $photo = $uploaded;
            } elseif ($remove_photo) {
                $photo = '';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            $db->prepare(
                'UPDATE idc_cards SET
                    card_type = ?, id_number = ?, full_name = ?, program_name = ?, dept_name = ?,
                    designation = ?, batch_name = ?, blood_group = ?, phone = ?, address = ?,
                    photo = ?, issue_date = ?, expiry_date = ?
                 WHERE id = ?'
            )->execute([
                $card_type, $id_number, $full_name,
                $program_name ?: null, $dept_name ?: null, $designation ?: null,
                $batch_name ?: null, $blood_group ?: null, $phone ?: null,
                $address ?: null, $photo ?: null,
                $issue_date ?: null, $expiry_date ?: null,
                $id,
            ]);
            flash_set('success', 'ID card updated. Opening print preview…');
            redirect(APP_URL . '/id-card/print.php?id=' . $id);
        } catch (Throwable $e) {
            $errors[] = 'Could not update ID card: ' . $e->getMessage();
        }
    }

    // keep submitted values on error
    foreach ($pre as $k => $v) { $pre[$k] = trim($_POST[$k] ?? (string)$v); }
    $pre['card_type'] = $card_type;
    $pre['photo']     = $photo;
}

// Photo preview URL (module uploads live under admin/uploads/)
$photo_src = '';
$p = trim((string)$pre['photo']);
if ($p !== '') {
    if (preg_match('#^https?://#i', $p)) {
        $photo_src = $p;
    } elseif (strpos($p, 'uploads/') === 0) {
        $photo_src = APP_URL . '/' . $p;
    } else {
        $photo_src = idc_photo_url($p);
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/id-card/index.php">ID Cards</a></li>
            <li class="breadcrumb-item active">Edit <?= h($card['id_number']) ?></li>
        </ol>
    </nav>
    <a href="<?= APP_URL ?>/id-card/print.php?id=<?= (int)$card['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">
        <i class="fas fa-print me-1"></i> Preview &amp; Print
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0 ps-3">
    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="row justify-content-center"><div class="col-lg-8">
    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-pen me-2 text-muted"></i>Edit Card Details</h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$card['id'] ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Card Type <span class="text-danger">*</span></label>
                        <select name="card_type" id="cardType" class="form-select">
                            <?php foreach (IDC_TYPES as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $pre['card_type'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">ID Number <span class="text-danger">*</span></label>
                        <input type="text" name="id_number" class="form-control" required value="<?= h($pre['id_number']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Blood Group</label>
                        <select name="blood_group" class="form-select">
                            <option value="">—</option>
                            <?php foreach (IDC_BLOOD_GROUPS as $bg): ?>
                                <option value="<?= $bg ?>" <?= $pre['blood_group'] === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label fw-medium">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required value="<?= h($pre['full_name']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= h($pre['phone']) ?>">
                    </div>

                    <div class="col-md-6 idc-student-only">
                        <label class="form-label fw-medium">Program</label>
                        <input type="text" name="program_name" class="form-control" value="<?= h($pre['program_name']) ?>">
                        <div class="form-text">Shown on the card as: <strong><?= h(idc_short_program_name($pre['program_name'])) ?></strong></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Department</label>
                        <input type="text" name="dept_name" class="form-control" value="<?= h($pre['dept_name']) ?>">
                    </div>

                    <div class="col-md-6 idc-staff-only" style="display:none">
                        <label class="form-label fw-medium">Designation</label>
                        <input type="text" name="designation" class="form-control" value="<?= h($pre['designation']) ?>"
                               placeholder="e.g. Assistant Professor / Office Assistant">
                    </div>
                    <div class="col-md-6 idc-student-only">
                        <label class="form-label fw-medium">Batch</label>
                        <input type="text" name="batch_name" class="form-control" value="<?= h($pre['batch_name']) ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Address</label>
                        <input type="text" name="address" class="form-control" value="<?= h($pre['address']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-medium">Issue Date</label>
                        <input type="date" name="issue_date" class="form-control" value="<?= h($pre['issue_date']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Valid Until</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= h($pre['expiry_date']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Print Status</label>
                        <select name="print_status" class="form-select">
                            <?php foreach (IDC_PRINT_STATUSES as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($pre['print_status'] ?? 'in_printing_queue') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-3">
                        <label class="form-label fw-medium">Current Photo</label>
                        <div>
                            <?php if ($photo_src !== ''): ?>
                            <img src="<?= h($photo_src) ?>" alt="Photo"
                                 style="width:110px;height:132px;object-fit:cover;border:1px solid #dee2e6;border-radius:6px;background:#f8f9fa">
                            <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted"
                                 style="width:110px;height:132px;border:1px dashed #ccc;border-radius:6px;font-size:.75rem">No Photo</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="idcRemovePhoto">
                            <label class="form-check-label small" for="idcRemovePhoto">Remove photo</label>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label fw-medium">Upload New Photo</label>
                        <input type="file" name="photo_file" class="form-control" accept=".jpg,.jpeg,.png,.webp">
                        <div class="form-text">JPG, PNG or WEBP, max 3 MB. Uploading a new photo replaces the current one.</div>
                        <label class="form-label fw-medium mt-3">Photo Path <span class="text-muted small">(advanced)</span></label>
                        <input type="text" name="photo" class="form-control" value="<?= h($pre['photo']) ?>"
                               placeholder="e.g. uploads/students/photos/xyz.jpg">
                        <div class="form-text">Stored path of the photo. Ignored when a new file is uploaded or “Remove photo” is ticked.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="<?= APP_URL ?>/id-card/index.php" class="btn btn-outline-secondary">Cancel</a>
                    <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save &amp; Preview</button>
                </div>
            </form>
        </div>
    </div>
</div></div>

<script>
// Show/hide student- vs staff-specific fields based on card type
(function () {
    var sel = document.getElementById('cardType');
    function sync() {
        var isStudent = sel.value === 'student';
        document.querySelectorAll('.idc-student-only').forEach(function (el) { el.style.display = isStudent ? '' : 'none'; });
        document.querySelectorAll('.idc-staff-only').forEach(function (el) { el.style.display = isStudent ? 'none' : ''; });
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
