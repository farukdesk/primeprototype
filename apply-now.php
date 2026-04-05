<?php
/**
 * Public Lead Application Form – Apply Now
 * Collects prospective student information and stores it as a lead.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';

$page_title = 'Apply Now – Prime University';

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['apply_csrf'])) {
    $_SESSION['apply_csrf'] = bin2hex(random_bytes(32));
}
$pub_csrf = $_SESSION['apply_csrf'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function an_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function an_old(string $key, array $old, string $default = ''): string
{
    return an_h($old[$key] ?? $default);
}

function an_generate_lead_number(): string
{
    $db   = front_db();
    if (!$db) return 'LD-' . date('Y') . '-0001';
    $year = date('Y');
    $pfx  = 'LD-' . $year . '-';
    $stmt = $db->prepare("SELECT lead_number FROM leads WHERE lead_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pfx . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $pfx . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

function an_send_mail(string $to, string $subject, string $body): void
{
    // Validate and sanitise recipient to prevent header injection
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $to = filter_var($to, FILTER_SANITIZE_EMAIL);
    if (strpbrk($to, "\r\n\t") !== false) return;

    $from  = 'noreply@primeuniversity.ac.bd';
    $fname = '=?UTF-8?B?' . base64_encode('Prime University Admissions') . '?=';
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $fname . ' <' . $from . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $from . "\r\n";
    @mail($to, $subject, $body, $headers, '-f' . $from);
}

// ── Semester list (next 3 years) ──────────────────────────────────────────────
function an_semester_list(): array
{
    $list    = [];
    $curYear = (int)date('Y');
    for ($y = $curYear; $y <= $curYear + 3; $y++) {
        $list[] = 'Summer ' . $y;
        $list[] = 'Fall '   . $y;
        $list[] = 'Spring ' . $y;
    }
    return $list;
}

// ── Load data from DB ─────────────────────────────────────────────────────────
$departments      = [];
$programs_by_dept = [];
try {
    $db = front_db();
    if ($db) {
        $departments = $db->query(
            'SELECT id, name FROM dept_departments WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $prog_rows = $db->query(
            'SELECT id, dept_id, program_name, degree_level
             FROM dept_academic_programs
             WHERE is_active = 1 ORDER BY program_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prog_rows as $p) {
            $programs_by_dept[(int)$p['dept_id']][] = $p;
        }
    }
} catch (Throwable $e) { /* silent */ }

$semesters = an_semester_list();

// ── Handle form submission ────────────────────────────────────────────────────
$form_errors  = [];
$form_success = false;
$submitted_number = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    $token = $_POST['_apply_csrf'] ?? '';
    if (!hash_equals($pub_csrf, $token)) {
        $form_errors[] = 'Security token mismatch. Please refresh the page and try again.';
    }

    if (empty($form_errors)) {
        $old = $_POST;

        $first_name   = trim($_POST['first_name']   ?? '');
        $last_name    = trim($_POST['last_name']    ?? '');
        $email        = trim($_POST['email']        ?? '');
        $phone        = trim($_POST['phone']        ?? '');
        $address      = trim($_POST['address']      ?? '');
        $current_city = trim($_POST['current_city'] ?? '');
        $degree_type  = in_array($_POST['degree_type'] ?? '', ['bachelor', 'master'], true)
                        ? $_POST['degree_type'] : 'bachelor';
        $dept_id      = (int)($_POST['dept_id']     ?? 0) ?: null;
        $program_id   = (int)($_POST['program_id']  ?? 0) ?: null;
        $preferred_semester = trim($_POST['preferred_semester'] ?? '');

        if ($first_name === '') $form_errors[] = 'First name is required.';
        if ($last_name  === '') $form_errors[] = 'Last name is required.';
        if ($phone      === '') $form_errors[] = 'Phone number is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $form_errors[] = 'Please enter a valid email address.';
        }
    }

    if (empty($form_errors)) {
        try {
            $db = front_db();
            if (!$db) throw new RuntimeException('Database unavailable.');

            $lead_number = an_generate_lead_number();
            $db->prepare(
                'INSERT INTO leads
                   (lead_number, first_name, last_name, email, phone, address, current_city,
                    degree_type, dept_id, program_id, preferred_semester,
                    status, source)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $lead_number,
                $first_name, $last_name,
                $email     ?: null,
                $phone,
                $address   ?: null,
                $current_city ?: null,
                $degree_type, $dept_id, $program_id,
                $preferred_semester ?: null,
                'fresh',
                'online',
            ]);
            $lead_id = (int)$db->lastInsertId();

            // Log creation in lead_history
            $db->prepare(
                'INSERT INTO lead_history (lead_id, user_id, action, description)
                 VALUES (?, NULL, ?, ?)'
            )->execute([
                $lead_id,
                'created',
                'Application submitted via online form',
            ]);

            // Send confirmation email
            if ($email !== '') {
                $subject = 'Your Application Has Been Received – ' . $lead_number;
                $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#f9fafb;border-radius:10px;">
  <div style="background:#1a1f36;color:#fff;padding:20px 28px;border-radius:8px 8px 0 0;text-align:center;">
    <h2 style="margin:0;font-size:1.3rem;">Application Received</h2>
    <p style="margin:4px 0 0;font-size:.85rem;opacity:.8;">Prime University Admissions</p>
  </div>
  <div style="background:#fff;padding:28px;border-radius:0 0 8px 8px;">
    <p>Dear ' . an_h($first_name) . ',</p>
    <p>Thank you for your interest in Prime University. Your application has been received and our admissions team will contact you shortly.</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600;width:40%">Application Number</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h($lead_number) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Name</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h($first_name . ' ' . $last_name) . '</td></tr>
      <tr><td style="padding:8px;border:1px solid #e5e7eb;background:#f9fafb;font-weight:600">Degree</td><td style="padding:8px;border:1px solid #e5e7eb;">' . an_h(ucfirst($degree_type)) . '</td></tr>
    </table>
    <p style="color:#6b7280;font-size:.85rem;">If you have any questions, feel free to contact us at <a href="mailto:admissions@primeuniversity.ac.bd">admissions@primeuniversity.ac.bd</a></p>
    <p>Warm regards,<br><strong>Prime University Admissions Team</strong></p>
  </div>
</div>';
                an_send_mail($email, $subject, $body);
            }

            $form_success     = true;
            $submitted_number = $lead_number;
            $old = [];
            // Regenerate CSRF after success
            $_SESSION['apply_csrf'] = bin2hex(random_bytes(32));
            $pub_csrf = $_SESSION['apply_csrf'];
        } catch (Throwable $e) {
            $form_errors[] = 'Something went wrong. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= an_h($page_title) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">

    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6fb; }
        .apply-header {
            background: linear-gradient(135deg, #1a1f36 0%, #2e3776 100%);
            color: #fff;
            padding: 60px 0 40px;
            text-align: center;
        }
        .apply-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 40px;
            margin-bottom: 40px;
        }
        .section-title {
            font-size: .8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #4f8ef7;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        .form-label { font-weight: 500; font-size: .9rem; }
        .required-star { color: #e53935; }
        @media (max-width: 576px) { .apply-card { padding: 20px; } }
    </style>
</head>
<body>

<!-- Site header / nav -->
<?php
// Try to include the site header if it exists
$site_header = __DIR__ . '/includes/header.php';
if (file_exists($site_header)) { require_once $site_header; }
?>

<!-- Hero -->
<div class="apply-header">
    <div class="container">
        <h1 class="fw-bold mb-2">Apply Now</h1>
        <p class="mb-0 opacity-75">Start your journey at Prime University – fill in the form below and our admissions team will reach out to you.</p>
    </div>
</div>

<div class="container py-5" style="max-width:860px">

    <?php if ($form_success): ?>
    <div class="apply-card text-center">
        <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
        <h4 class="fw-bold mb-2">Application Submitted!</h4>
        <p class="text-muted mb-2">Thank you for your interest in Prime University.</p>
        <p>Your application number is: <strong class="text-primary fs-5"><?= an_h($submitted_number) ?></strong></p>
        <p class="text-muted small">Our admissions team will contact you within 24–48 hours. Please keep your phone number accessible.</p>
        <a href="/" class="btn btn-primary mt-2"><i class="fas fa-home me-1"></i> Back to Home</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary mt-2 ms-2"><i class="fas fa-plus me-1"></i> Submit Another</button>
    </div>
    <?php else: ?>

    <?php if ($form_errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($form_errors as $err): ?>
            <li><?= an_h($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="apply-card">
        <form method="post" novalidate id="apply-form">
            <input type="hidden" name="_apply_csrf" value="<?= an_h($pub_csrf) ?>">

            <!-- ── Personal Information ── -->
            <div class="section-title"><i class="fas fa-user me-1"></i> Personal Information</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <label class="form-label">First Name <span class="required-star">*</span></label>
                    <input type="text" name="first_name" class="form-control" maxlength="100"
                           value="<?= an_old('first_name', $old) ?>" required placeholder="e.g. Sakib">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Last Name <span class="required-star">*</span></label>
                    <input type="text" name="last_name" class="form-control" maxlength="100"
                           value="<?= an_old('last_name', $old) ?>" required placeholder="e.g. Rahman">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" maxlength="200"
                           value="<?= an_old('email', $old) ?>" placeholder="you@example.com">
                    <div class="form-text">A confirmation will be sent to this email.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Phone Number <span class="required-star">*</span></label>
                    <input type="text" name="phone" class="form-control" maxlength="30"
                           value="<?= an_old('phone', $old) ?>" required placeholder="+880 1XXX-XXXXXX">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Current City</label>
                    <input type="text" name="current_city" class="form-control" maxlength="200"
                           value="<?= an_old('current_city', $old) ?>" placeholder="e.g. Dhaka">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2" maxlength="1000"
                              placeholder="Street, Area, District"><?= an_old('address', $old) ?></textarea>
                </div>
            </div>

            <!-- ── Education Information ── -->
            <div class="section-title"><i class="fas fa-graduation-cap me-1"></i> Education Information</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <label class="form-label">Applying For</label>
                    <div class="d-flex gap-3 mt-1">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="degree_type" id="deg_bachelor" value="bachelor"
                                <?= (an_old('degree_type', $old, 'bachelor') !== 'master') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="deg_bachelor">Bachelor Degree</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="degree_type" id="deg_master" value="master"
                                <?= an_old('degree_type', $old) === 'master' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="deg_master">Master Degree</label>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Preferred Intake Semester</label>
                    <select name="preferred_semester" class="form-select">
                        <option value="">— Select Semester —</option>
                        <?php foreach ($semesters as $sem): ?>
                        <option value="<?= an_h($sem) ?>" <?= an_old('preferred_semester', $old) === $sem ? 'selected' : '' ?>><?= an_h($sem) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Department</label>
                    <select name="dept_id" class="form-select" id="an_dept_select">
                        <option value="">— Select Department —</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= (int)$dept['id'] ?>"
                            <?= an_old('dept_id', $old) === (string)$dept['id'] ? 'selected' : '' ?>>
                            <?= an_h($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Interested Program</label>
                    <select name="program_id" class="form-select" id="an_program_select">
                        <option value="">— Select Department First —</option>
                        <?php
                        $presel_dept = (int)an_old('dept_id', $old);
                        $presel_prog = (int)an_old('program_id', $old);
                        if ($presel_dept && isset($programs_by_dept[$presel_dept])) {
                            foreach ($programs_by_dept[$presel_dept] as $p) {
                                $sel = $presel_prog === (int)$p['id'] ? 'selected' : '';
                                echo '<option value="' . $p['id'] . '" ' . $sel . '>' . an_h($p['program_name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>

            <hr>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                <a href="/" class="text-muted text-decoration-none"><i class="fas fa-arrow-left me-1"></i> Back to Home</a>
                <button type="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-paper-plane me-2"></i> Submit Application
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const programsByDept = <?= json_encode($programs_by_dept) ?>;
const deptSelect    = document.getElementById('an_dept_select');
const progSelect    = document.getElementById('an_program_select');

function updatePrograms() {
    const deptId   = parseInt(deptSelect.value) || 0;
    const programs = programsByDept[deptId] || [];
    progSelect.innerHTML = '<option value="">— Select Program —</option>';
    programs.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.program_name;
        progSelect.appendChild(opt);
    });
}

if (deptSelect) deptSelect.addEventListener('change', updatePrograms);
</script>
</body>
</html>
