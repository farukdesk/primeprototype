<?php
/**
 * ID Card – Create (dynamic + manual)
 *
 * Dynamic:  pass ?student_id=XXXX – the form is auto-filled from the
 *           students table (photo, name, program, blood group …).
 * Manual:   open without parameters and type everything by hand –
 *           also used for Faculty and Staff cards.
 */
require_once __DIR__ . '/../includes/auth.php';
require_access('id-card', 'can_create');
require_once __DIR__ . '/helpers.php';

$page_title = 'Generate ID Card';
$errors = [];
$db = db();

// ── Dynamic lookup ───────────────────────────────────────────────────────────
$lookup_id = trim($_GET['student_id'] ?? '');
$student   = null;
if ($lookup_id !== '') {
    $student = idc_find_student($lookup_id);
    if (!$student) {
        $errors[] = 'No student found with ID "' . h($lookup_id) . '". You can still create the card manually below.';
    }
}

// Prefill values (student data → fallback to POSTed/old values)
$pre = [
    'card_type'    => $student ? 'student' : 'student',
    'id_number'    => $student['student_id'] ?? '',
    'full_name'    => $student['full_name'] ?? '',
    'program_name' => $student['program_name'] ?? '',
    'dept_name'    => $student['dept_name'] ?? '',
    'designation'  => '',
    'batch_name'   => $student['batch_name'] ?? '',
    'blood_group'  => $student['blood_group'] ?? '',
    'phone'        => $student['phone'] ?? '',
    'address'      => $student['present_address'] ?? ($student['permanent_address'] ?? ''),
    'photo'        => $student['photo'] ?? '',
    'issue_date'   => date('Y-m-d'),
    // Program-wise validity from the creation date: 4 years for bachelor
    // programs, 1 / 1.5 / 2 years for masters programs (idc_program_validity_months).
    'expiry_date'  => idc_expiry_date_for_program((string)($student['program_name'] ?? '')),
];

// ── Save ─────────────────────────────────────────────────────────────────────
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
    $student_ref  = (int)($_POST['student_ref_id'] ?? 0) ?: null;

    if (!isset(IDC_TYPES[$card_type])) $errors[] = 'Invalid card type.';
    if ($id_number === '')             $errors[] = 'ID number is required.';
    if ($full_name === '')             $errors[] = 'Full name is required.';

    if (!$errors) {
        try {
            // signature_path snapshots the Registrar signature current at
            // CREATION time and is intentionally NOT refreshed on duplicate
            // (re-saving an existing card keeps its issued signature).
            $st = $db->prepare(
                'INSERT INTO idc_cards
                    (card_type, student_ref_id, id_number, full_name, program_name, dept_name,
                     designation, batch_name, blood_group, phone, address, photo, signature_path,
                     issue_date, expiry_date, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    full_name=VALUES(full_name), program_name=VALUES(program_name),
                    dept_name=VALUES(dept_name), designation=VALUES(designation),
                    batch_name=VALUES(batch_name), blood_group=VALUES(blood_group),
                    phone=VALUES(phone), address=VALUES(address), photo=VALUES(photo),
                    issue_date=VALUES(issue_date), expiry_date=VALUES(expiry_date)'
            );
            $st->execute([
                $card_type, $student_ref, $id_number, $full_name,
                $program_name ?: null, $dept_name ?: null, $designation ?: null,
                $batch_name ?: null, $blood_group ?: null, $phone ?: null,
                $address ?: null, $photo ?: null,
                idc_current_signature_path() ?: null,
                $issue_date ?: null, $expiry_date ?: null,
                auth_user()['id'],
            ]);
            $card_id = (int)$db->lastInsertId();
            if ($card_id === 0) { // updated an existing card via the unique key
                $q = $db->prepare('SELECT id FROM idc_cards WHERE card_type = ? AND id_number = ?');
                $q->execute([$card_type, $id_number]);
                $card_id = (int)$q->fetchColumn();
            }
            flash_set('success', 'ID card saved. Opening print preview…');
            redirect(APP_URL . '/id-card/print.php?id=' . $card_id);
        } catch (Throwable $e) {
            $errors[] = 'Could not save ID card: ' . $e->getMessage();
        }
    }

    // keep submitted values on error
    foreach ($pre as $k => $v) { $pre[$k] = trim($_POST[$k] ?? (string)$v); }
    $pre['card_type'] = $card_type;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/id-card/index.php">ID Cards</a></li>
            <li class="breadcrumb-item active">Generate</li>
        </ol>
    </nav>
</div>

<div class="row justify-content-center"><div class="col-lg-8">

    <!-- Dynamic search -->
    <div class="card mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-auto fw-semibold"><i class="fas fa-search me-1 text-muted"></i>Search Student:</div>
                <div class="col-md-5">
                    <input type="text" name="student_id" class="form-control form-control-sm"
                           placeholder="Student ID" value="<?= h($lookup_id) ?>">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary">Auto-fill from record</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0 ps-3">
        <?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>

    <?php if ($student): ?>
    <div class="alert alert-success py-2">
        <i class="fas fa-check-circle me-1"></i>
        Loaded <strong><?= h($student['full_name']) ?></strong> (<?= h($student['student_id']) ?>)
        – status: <?= h($student['status']) ?>. Review the fields and save.
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header py-3 px-4">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2 text-muted"></i>Card Details</h6>
        </div>
        <div class="card-body p-4">
            <form method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="student_ref_id" value="<?= (int)($student['id'] ?? 0) ?>">
                <input type="hidden" name="photo" value="<?= h($pre['photo']) ?>">

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

                    <div class="col-md-6">
                        <label class="form-label fw-medium">Issue Date</label>
                        <input type="date" name="issue_date" class="form-control" value="<?= h($pre['issue_date']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Valid Until</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= h($pre['expiry_date']) ?>">
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
