<?php
/**
 * Public Student Details Form
 * Accessible via a 24-hour secure token sent after a form sale.
 * No login required.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';

$page_title = 'Admission Form – Student Details | Prime University';

// ── Helper: HTML-escape ───────────────────────────────────────────────────────
function aff_h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Token validation ──────────────────────────────────────────────────────────
$raw_token = trim($_GET['token'] ?? '');
$token_row = null;
$sale_row  = null;
$token_error = '';

if ($raw_token === '') {
    $token_error = 'No token provided. Please use the link sent to your mobile or email.';
} else {
    $db = front_db();
    if (!$db) {
        $token_error = 'Service temporarily unavailable. Please try again later.';
    } else {
        $stmt = $db->prepare(
            'SELECT t.id, t.form_sale_id, t.token, t.expires_at, t.used_at,
                    fs.form_number, fs.buyer_name, fs.buyer_mobile, fs.buyer_email, fs.status AS sale_status
             FROM adm_form_sale_tokens t
             JOIN adm_form_sales fs ON fs.id = t.form_sale_id
             WHERE t.token = ?
             LIMIT 1'
        );
        $stmt->execute([$raw_token]);
        $token_row = $stmt->fetch();

        if (!$token_row) {
            $token_error = 'Invalid link. Please use the exact link sent to you.';
        } elseif ($token_row['used_at'] !== null) {
            $token_error = 'This link has already been used. Your details have been submitted.';
        } elseif (strtotime($token_row['expires_at']) < time()) {
            $token_error = 'This link has expired (valid for 24 hours). Please contact the admissions office.';
        } elseif ($token_row['sale_status'] !== 'pending') {
            $token_error = 'The form associated with this link is no longer available.';
        } else {
            $sale_row = $token_row; // has all needed fields
        }
    }
}

// ── Districts & Thanas ────────────────────────────────────────────────────────
$bd_districts  = [];
$bd_thanas     = [];
$bd_thana_map  = [];

if ($sale_row) {
    try {
        $bd_districts = front_db()->query(
            'SELECT id, name, division FROM bd_districts ORDER BY division, name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $bd_thanas = front_db()->query(
            'SELECT id, district_id, name FROM bd_thanas ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bd_thanas as $t) {
            $bd_thana_map[$t['district_id']][] = ['id' => $t['id'], 'name' => $t['name']];
        }
    } catch (Throwable $e) {}
}

// ── Already submitted? ────────────────────────────────────────────────────────
$already_submitted = false;
$submitted_details = null;
if ($sale_row) {
    $chk = front_db()->prepare(
        'SELECT id FROM adm_form_sale_student_details WHERE form_sale_id = ? LIMIT 1'
    );
    $chk->execute([$sale_row['form_sale_id']]);
    if ($chk->fetchColumn()) {
        // Token used_at might not be set if student submitted via another path; treat as submitted
        $already_submitted = true;
    }
}

// ── POST handler ──────────────────────────────────────────────────────────────
$form_errors  = [];
$form_success = false;
$old          = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sale_row && !$already_submitted) {
    $csrf_post = $_POST['_aff_csrf'] ?? '';
    $csrf_sess = $_SESSION['aff_csrf_' . $sale_row['id']] ?? '';
    if ($csrf_post === '' || !hash_equals($csrf_sess, $csrf_post)) {
        $form_errors[] = 'Security token mismatch. Please refresh the page and try again.';
    }

    if (empty($form_errors)) {
        $old = $_POST;

        $student_name          = trim($_POST['student_name']          ?? '');
        $father_name           = trim($_POST['father_name']           ?? '');
        $mother_name           = trim($_POST['mother_name']           ?? '');
        $gender                = in_array($_POST['gender'] ?? '', ['Male','Female','Other'], true)
                                  ? $_POST['gender'] : null;
        $date_of_birth         = trim($_POST['date_of_birth']         ?? '') ?: null;
        $blood_group           = trim($_POST['blood_group']           ?? '') ?: null;
        $nationality           = trim($_POST['nationality']           ?? '') ?: null;
        $place_of_birth        = trim($_POST['place_of_birth']        ?? '') ?: null;
        $nid_birth_cert        = trim($_POST['nid_birth_cert']        ?? '') ?: null;
        $religion              = trim($_POST['religion']              ?? '') ?: null;
        $permanent_address_1   = trim($_POST['permanent_address_1']   ?? '') ?: null;
        $permanent_address_2   = trim($_POST['permanent_address_2']   ?? '') ?: null;
        $permanent_area        = trim($_POST['permanent_area']        ?? '') ?: null;
        $permanent_district_id = (int)($_POST['permanent_district_id'] ?? 0) ?: null;
        $permanent_thana_id    = (int)($_POST['permanent_thana_id']   ?? 0) ?: null;
        $permanent_post_code   = trim($_POST['permanent_post_code']   ?? '') ?: null;

        $same_as_permanent     = isset($_POST['same_as_permanent']) ? 1 : 0;
        if ($same_as_permanent) {
            $present_address_1   = $permanent_address_1;
            $present_address_2   = $permanent_address_2;
            $present_area        = $permanent_area;
            $present_district_id = $permanent_district_id;
            $present_thana_id    = $permanent_thana_id;
            $present_post_code   = $permanent_post_code;
        } else {
            $present_address_1   = trim($_POST['present_address_1']   ?? '') ?: null;
            $present_address_2   = trim($_POST['present_address_2']   ?? '') ?: null;
            $present_area        = trim($_POST['present_area']        ?? '') ?: null;
            $present_district_id = (int)($_POST['present_district_id'] ?? 0) ?: null;
            $present_thana_id    = (int)($_POST['present_thana_id']   ?? 0) ?: null;
            $present_post_code   = trim($_POST['present_post_code']   ?? '') ?: null;
        }

        if ($student_name === '') $form_errors[] = 'Student Full Name is required.';
    }

    if (empty($form_errors)) {
        try {
            $db = front_db();
            $db->beginTransaction();

            // Insert student details
            $db->prepare(
                'INSERT INTO adm_form_sale_student_details
                   (form_sale_id, token_id,
                    student_name, father_name, mother_name, gender, date_of_birth,
                    blood_group, nationality, place_of_birth, nid_birth_cert, religion,
                    permanent_address_1, permanent_address_2, permanent_area,
                    permanent_district_id, permanent_thana_id, permanent_post_code,
                    present_same_as_permanent,
                    present_address_1, present_address_2, present_area,
                    present_district_id, present_thana_id, present_post_code)
                 VALUES (?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?, ?, ?,?,?, ?,?,?)'
            )->execute([
                $sale_row['form_sale_id'], $sale_row['id'],
                $student_name, $father_name ?: null, $mother_name ?: null,
                $gender, $date_of_birth,
                $blood_group, $nationality, $place_of_birth, $nid_birth_cert, $religion,
                $permanent_address_1, $permanent_address_2, $permanent_area,
                $permanent_district_id, $permanent_thana_id, $permanent_post_code,
                $same_as_permanent,
                $present_address_1, $present_address_2, $present_area,
                $present_district_id, $present_thana_id, $present_post_code,
            ]);

            // Mark token as used
            $db->prepare(
                'UPDATE adm_form_sale_tokens SET used_at = NOW() WHERE id = ?'
            )->execute([$sale_row['id']]);

            $db->commit();
            $form_success    = true;
            $already_submitted = true;

            // Regenerate CSRF
            unset($_SESSION['aff_csrf_' . $sale_row['id']]);
        } catch (Throwable $e) {
            if (front_db() && front_db()->inTransaction()) front_db()->rollBack();
            $form_errors[] = 'Submission failed. Please try again or contact the admissions office.';
        }
    }
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if ($sale_row && !$form_success && !$already_submitted) {
    if (empty($_SESSION['aff_csrf_' . $sale_row['id']])) {
        $_SESSION['aff_csrf_' . $sale_row['id']] = bin2hex(random_bytes(32));
    }
    $aff_csrf = $_SESSION['aff_csrf_' . $sale_row['id']];
}
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= aff_h($page_title) ?></title>
    <meta name="description" content="Fill in your personal details for your Prime University admission application.">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
    <link rel="stylesheet" href="/assets/css/main.css">
    <style>
        body { background: #f5f7fb; }
        .aff-hero {
            position: relative; overflow: hidden;
            padding: 60px 0 80px;
            background: linear-gradient(135deg, #0f1f4a 0%, #163d88 55%, #2563eb 100%);
        }
        .aff-hero h1 { color: #fff; font-size: clamp(1.6rem, 4vw, 2.6rem); font-weight: 800; margin-bottom: 10px; }
        .aff-hero p { color: rgba(255,255,255,.82); }
        .aff-hero .breadcrumb-nav a,
        .aff-hero .breadcrumb-nav span { color: rgba(255,255,255,.75); font-size: .88rem; }
        .aff-hero .breadcrumb-nav a:hover { color: #fff; }
        .aff-hero .sep { margin: 0 7px; color: rgba(255,255,255,.4); }
        .aff-form-card { border: none; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .aff-section-label {
            display: block; font-size: .68rem; font-weight: 700; letter-spacing: .09em;
            text-transform: uppercase; color: #adb5bd; margin-bottom: .6rem;
        }
        .aff-address-sep { border: 0; border-top: 2px dashed #dee2e6; margin: 1.25rem 0; }
        .form-number-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,198,0,.18); color: #fff; border-radius: 999px;
            padding: 6px 16px; font-size: .85rem; font-weight: 600;
            border: 1px solid rgba(255,198,0,.35);
        }
        .form-number-badge span { color: #ffc600; font-size: 1rem; }
        /* Searchable select */
        .aff-ss-list { display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 200px; overflow-y: auto; background: #fff;
            border: 1px solid #dee2e6; border-top: 0; border-radius: 0 0 6px 6px; z-index: 1050; }
        .aff-ss-item { padding: 6px 12px; cursor: pointer; font-size: .85rem; }
        .aff-ss-item:hover { background: #f0f4ff; }
        .aff-ss-item.header-item { background: #f0f4ff; font-weight: 600; font-size: .75rem; color: #555; pointer-events: none; }
        .aff-ss-item.none-item { color: #999; }
    </style>
</head>
<body>
<!-- ── Header ────────────────────────────────────────────────────────────────── -->
<header style="background:#0f1f4a; padding: 12px 0;">
    <div class="container d-flex align-items-center justify-content-between">
        <a href="/" class="d-flex align-items-center gap-2 text-decoration-none">
            <img src="/assets/img/logo/logo-white.png" alt="Prime University" style="height:40px;" onerror="this.style.display='none'">
            <span style="color:#fff;font-weight:700;font-size:1.1rem;">Prime University</span>
        </a>
        <a href="/" class="btn btn-sm btn-outline-light">Home</a>
    </div>
</header>

<!-- ── Hero ──────────────────────────────────────────────────────────────────── -->
<section class="aff-hero">
    <div class="container">
        <nav class="breadcrumb-nav mb-3">
            <a href="/">Home</a>
            <span class="sep">/</span>
            <span>Admission Form Fill-up</span>
        </nav>
        <h1><i class="fas fa-edit me-2"></i>Admission Form Fill-up</h1>
        <p>Please provide your personal details to complete your admission application.</p>
        <?php if ($sale_row): ?>
        <div class="form-number-badge mt-3">
            <i class="fas fa-receipt"></i> Form Number: <span><?= aff_h($sale_row['form_number']) ?></span>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ── Main Content ───────────────────────────────────────────────────────────── -->
<section style="padding: 50px 0 80px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-9">

                <?php if ($token_error !== ''): ?>
                <!-- ── Token Error ─────────────────────────────────────────────── -->
                <div class="aff-form-card card p-5 text-center">
                    <div class="mb-4"><i class="fas fa-exclamation-triangle fa-4x text-danger opacity-75"></i></div>
                    <h4 class="fw-bold text-danger mb-2">Link Not Valid</h4>
                    <p class="text-muted mb-4"><?= aff_h($token_error) ?></p>
                    <a href="/" class="btn btn-primary">Return to Homepage</a>
                </div>

                <?php elseif ($form_success || $already_submitted): ?>
                <!-- ── Success ─────────────────────────────────────────────────── -->
                <div class="aff-form-card card p-5 text-center">
                    <div class="mb-4"><i class="fas fa-check-circle fa-4x text-success opacity-90"></i></div>
                    <h4 class="fw-bold text-success mb-2">Details Submitted Successfully!</h4>
                    <p class="text-muted mb-2">
                        Your personal details for Form No. <strong class="text-dark"><?= aff_h($sale_row['form_number']) ?></strong> have been received.
                    </p>
                    <p class="text-muted mb-4">
                        Our admissions team will review your details and get back to you. Please bring your original documents on the day of admission.
                    </p>
                    <a href="/" class="btn btn-primary"><i class="fas fa-home me-1"></i> Return to Homepage</a>
                </div>

                <?php else: ?>
                <!-- ── Form ───────────────────────────────────────────────────── -->

                <?php if ($form_errors): ?>
                <div class="alert alert-danger mb-4">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($form_errors as $fe): ?><li><?= aff_h($fe) ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Expiry notice -->
                <div class="alert alert-warning mb-4 small">
                    <i class="fas fa-clock me-1"></i>
                    This link expires on <strong><?= aff_h(date('d M Y \a\t h:i A', strtotime($sale_row['expires_at']))) ?></strong>. Please complete the form before then.
                </div>

                <form method="post" novalidate>
                    <input type="hidden" name="_aff_csrf" value="<?= aff_h($aff_csrf) ?>">
                    <input type="hidden" name="token" value="<?= aff_h($raw_token) ?>">

                    <!-- ── Personal Information ─────────────────────────────── -->
                    <div class="aff-form-card card mb-4">
                        <div class="card-header bg-white fw-semibold py-3">
                            <i class="fas fa-user me-2 text-primary"></i>Personal Information
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Student Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="student_name" class="form-control form-control-lg"
                                           value="<?= aff_h($old['student_name'] ?? $sale_row['buyer_name']) ?>"
                                           placeholder="Enter your full name as in NID / Birth Certificate" required>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Father's Name</label>
                                    <input type="text" name="father_name" class="form-control"
                                           value="<?= aff_h($old['father_name'] ?? '') ?>" placeholder="Father's full name">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Mother's Name</label>
                                    <input type="text" name="mother_name" class="form-control"
                                           value="<?= aff_h($old['mother_name'] ?? '') ?>" placeholder="Mother's full name">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Gender</label>
                                    <div class="d-flex gap-4 flex-wrap mt-1">
                                        <?php foreach (['Male','Female','Other'] as $g): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="gender"
                                                   id="gender_<?= $g ?>" value="<?= $g ?>"
                                                   <?= (($old['gender'] ?? '') === $g) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="gender_<?= $g ?>"><?= $g ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6 col-md-4">
                                    <label class="form-label fw-semibold">Date of Birth</label>
                                    <input type="date" name="date_of_birth" class="form-control"
                                           value="<?= aff_h($old['date_of_birth'] ?? '') ?>">
                                </div>
                                <div class="col-12 col-sm-6 col-md-4">
                                    <label class="form-label fw-semibold">Blood Group</label>
                                    <select name="blood_group" class="form-select">
                                        <option value="">— Select —</option>
                                        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
                                        <option value="<?= $bg ?>" <?= (($old['blood_group'] ?? '') === $bg) ? 'selected' : '' ?>><?= $bg ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6 col-md-4">
                                    <label class="form-label fw-semibold">Nationality</label>
                                    <input type="text" name="nationality" class="form-control"
                                           value="<?= aff_h($old['nationality'] ?? 'Bangladeshi') ?>">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Place of Birth</label>
                                    <input type="text" name="place_of_birth" class="form-control"
                                           value="<?= aff_h($old['place_of_birth'] ?? '') ?>" placeholder="District / City">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">NID / Birth Certificate No</label>
                                    <input type="text" name="nid_birth_cert" class="form-control"
                                           value="<?= aff_h($old['nid_birth_cert'] ?? '') ?>" placeholder="National ID or Birth Certificate number">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Religion</label>
                                    <select name="religion" class="form-select">
                                        <option value="">— Select —</option>
                                        <?php foreach (['Islam','Hinduism','Christianity','Buddhism','Other'] as $rel): ?>
                                        <option value="<?= $rel ?>" <?= (($old['religion'] ?? '') === $rel) ? 'selected' : '' ?>><?= $rel ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Mobile</label>
                                    <input type="text" class="form-control bg-light" value="<?= aff_h($sale_row['buyer_mobile']) ?>" readonly>
                                    <div class="form-text">Taken from your form purchase record.</div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Email</label>
                                    <input type="text" class="form-control bg-light" value="<?= aff_h($sale_row['buyer_email'] ?? '') ?>" readonly>
                                    <div class="form-text">Taken from your form purchase record.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Permanent Address ──────────────────────────────────── -->
                    <div class="aff-form-card card mb-4">
                        <div class="card-header bg-white fw-semibold py-3">
                            <i class="fas fa-home me-2 text-warning"></i>Permanent Address
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">House / Building</label>
                                    <input type="text" name="permanent_address_1" class="form-control"
                                           value="<?= aff_h($old['permanent_address_1'] ?? '') ?>" placeholder="e.g. House 12, ABC Tower">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Road / Street</label>
                                    <input type="text" name="permanent_address_2" class="form-control"
                                           value="<?= aff_h($old['permanent_address_2'] ?? '') ?>" placeholder="e.g. Road 5, Mirpur Ave">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Area / Locality</label>
                                    <input type="text" name="permanent_area" class="form-control"
                                           value="<?= aff_h($old['permanent_area'] ?? '') ?>" placeholder="e.g. Dhanmondi, Gulshan">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">District</label>
                                    <div style="position:relative">
                                        <input type="text" class="form-control aff-ss-trigger" id="perm_district_search"
                                               placeholder="Search district…" autocomplete="off" data-target="permanent_district_id">
                                        <input type="hidden" name="permanent_district_id" id="permanent_district_id"
                                               value="<?= aff_h($old['permanent_district_id'] ?? '') ?>">
                                        <div class="aff-ss-list" id="perm_district_list">
                                            <div class="aff-ss-item none-item" data-value="" data-label="">— None —</div>
                                            <?php
                                            $cur_div = '';
                                            foreach ($bd_districts as $dist):
                                                if ($dist['division'] !== $cur_div) {
                                                    $cur_div = $dist['division'];
                                            ?>
                                            <div class="aff-ss-item header-item" data-value="" data-label="">— <?= aff_h($cur_div) ?> Division —</div>
                                            <?php } ?>
                                            <div class="aff-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= aff_h($dist['name']) ?>"><?= aff_h($dist['name']) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Thana / Upazila</label>
                                    <div style="position:relative">
                                        <input type="text" class="form-control aff-ss-trigger" id="perm_thana_search"
                                               placeholder="Select district first…" autocomplete="off" data-target="permanent_thana_id">
                                        <input type="hidden" name="permanent_thana_id" id="permanent_thana_id"
                                               value="<?= aff_h($old['permanent_thana_id'] ?? '') ?>">
                                        <div class="aff-ss-list" id="perm_thana_list" data-current-district="<?= aff_h($old['permanent_district_id'] ?? '') ?>">
                                            <div class="aff-ss-item none-item" data-value="" data-label="" data-district="">— None —</div>
                                            <?php foreach ($bd_thanas as $th): ?>
                                            <div class="aff-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= aff_h($th['name']) ?>" data-district="<?= $th['district_id'] ?>"><?= aff_h($th['name']) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label fw-semibold">Post Code</label>
                                    <input type="text" name="permanent_post_code" class="form-control"
                                           value="<?= aff_h($old['permanent_post_code'] ?? '') ?>" placeholder="e.g. 1207">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Present Address ────────────────────────────────────── -->
                    <div class="aff-form-card card mb-4">
                        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <span class="fw-semibold"><i class="fas fa-map-pin me-2 text-danger"></i>Present Address</span>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="same_as_permanent" id="same_as_permanent" value="1"
                                    <?= isset($old['same_as_permanent']) ? 'checked' : '' ?>>
                                <label class="form-check-label small text-muted" for="same_as_permanent">
                                    <i class="fas fa-copy me-1"></i>Same as Permanent Address
                                </label>
                            </div>
                        </div>
                        <div class="card-body" id="present_address_fields">
                            <div class="row g-3">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">House / Building</label>
                                    <input type="text" name="present_address_1" id="present_address_1" class="form-control"
                                           value="<?= aff_h($old['present_address_1'] ?? '') ?>" placeholder="e.g. House 12, ABC Tower">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Road / Street</label>
                                    <input type="text" name="present_address_2" id="present_address_2" class="form-control"
                                           value="<?= aff_h($old['present_address_2'] ?? '') ?>" placeholder="e.g. Road 5, Mirpur Ave">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Area / Locality</label>
                                    <input type="text" name="present_area" id="present_area" class="form-control"
                                           value="<?= aff_h($old['present_area'] ?? '') ?>" placeholder="e.g. Dhanmondi, Gulshan">
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">District</label>
                                    <div style="position:relative">
                                        <input type="text" class="form-control aff-ss-trigger" id="pres_district_search"
                                               placeholder="Search district…" autocomplete="off" data-target="present_district_id">
                                        <input type="hidden" name="present_district_id" id="present_district_id"
                                               value="<?= aff_h($old['present_district_id'] ?? '') ?>">
                                        <div class="aff-ss-list" id="pres_district_list">
                                            <div class="aff-ss-item none-item" data-value="" data-label="">— None —</div>
                                            <?php
                                            $cur_div = '';
                                            foreach ($bd_districts as $dist):
                                                if ($dist['division'] !== $cur_div) {
                                                    $cur_div = $dist['division'];
                                            ?>
                                            <div class="aff-ss-item header-item" data-value="" data-label="">— <?= aff_h($cur_div) ?> Division —</div>
                                            <?php } ?>
                                            <div class="aff-ss-item" data-value="<?= $dist['id'] ?>" data-label="<?= aff_h($dist['name']) ?>"><?= aff_h($dist['name']) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label class="form-label fw-semibold">Thana / Upazila</label>
                                    <div style="position:relative">
                                        <input type="text" class="form-control aff-ss-trigger" id="pres_thana_search"
                                               placeholder="Select district first…" autocomplete="off" data-target="present_thana_id">
                                        <input type="hidden" name="present_thana_id" id="present_thana_id"
                                               value="<?= aff_h($old['present_thana_id'] ?? '') ?>">
                                        <div class="aff-ss-list" id="pres_thana_list" data-current-district="<?= aff_h($old['present_district_id'] ?? '') ?>">
                                            <div class="aff-ss-item none-item" data-value="" data-label="" data-district="">— None —</div>
                                            <?php foreach ($bd_thanas as $th): ?>
                                            <div class="aff-ss-item" data-value="<?= $th['id'] ?>" data-label="<?= aff_h($th['name']) ?>" data-district="<?= $th['district_id'] ?>"><?= aff_h($th['name']) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label fw-semibold">Post Code</label>
                                    <input type="text" name="present_post_code" id="present_post_code" class="form-control"
                                           value="<?= aff_h($old['present_post_code'] ?? '') ?>" placeholder="e.g. 1207">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 d-sm-flex justify-content-sm-end">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-paper-plane me-2"></i>Submit Details
                        </button>
                    </div>
                </form>

                <?php endif; ?>

            </div>
        </div>
    </div>
</section>

<!-- ── Footer ─────────────────────────────────────────────────────────────────── -->
<footer style="background:#0f1f4a; color: rgba(255,255,255,.6); text-align:center; padding: 20px 0; font-size:.85rem;">
    &copy; <?= date('Y') ?> Prime University. All rights reserved.
</footer>

<?php if ($sale_row && !$form_success && !$already_submitted): ?>
<script>
// ── Searchable select widget ──────────────────────────────────────────────────
(function() {
    // Thana data not needed directly; thanas are already rendered in DOM per district

    function initSearchableSelect(searchEl, hiddenEl, listEl) {
        if (!searchEl || !hiddenEl || !listEl) return;
        var items = listEl.querySelectorAll('.aff-ss-item:not(.header-item)');

        // Set initial display value
        var initVal = hiddenEl.value;
        if (initVal) {
            items.forEach(function(it) {
                if (String(it.dataset.value) === String(initVal)) {
                    searchEl.value = it.dataset.label;
                }
            });
        }

        searchEl.addEventListener('focus', function() { listEl.style.display = 'block'; filterItems(); });
        searchEl.addEventListener('input', filterItems);

        document.addEventListener('click', function(e) {
            if (!searchEl.contains(e.target) && !listEl.contains(e.target)) {
                listEl.style.display = 'none';
            }
        });

        listEl.querySelectorAll('.aff-ss-item').forEach(function(it) {
            if (it.classList.contains('header-item')) return;
            it.addEventListener('click', function() {
                hiddenEl.value  = it.dataset.value;
                searchEl.value  = it.dataset.label;
                listEl.style.display = 'none';
                hiddenEl.dispatchEvent(new Event('change'));
            });
        });

        function filterItems() {
            var q = searchEl.value.trim().toLowerCase();
            var districtId = listEl.dataset.currentDistrict || '';
            items.forEach(function(it) {
                var matchQ  = !q || it.dataset.label.toLowerCase().indexOf(q) >= 0;
                var matchDist = !districtId || String(it.dataset.district) === String(districtId) || it.classList.contains('none-item');
                it.style.display = (matchQ && matchDist) ? '' : 'none';
            });
            // Hide division headers if no siblings visible
            listEl.querySelectorAll('.header-item').forEach(function(h) {
                var next = h.nextElementSibling;
                var visible = false;
                while (next && !next.classList.contains('header-item')) {
                    if (next.style.display !== 'none') { visible = true; break; }
                    next = next.nextElementSibling;
                }
                h.style.display = visible ? '' : 'none';
            });
        }
    }

    // Permanent address
    var permDistSearch = document.getElementById('perm_district_search');
    var permDistHidden = document.getElementById('permanent_district_id');
    var permDistList   = document.getElementById('perm_district_list');
    var permThanaSearch = document.getElementById('perm_thana_search');
    var permThanaHidden = document.getElementById('permanent_thana_id');
    var permThanaList   = document.getElementById('perm_thana_list');

    initSearchableSelect(permDistSearch, permDistHidden, permDistList);
    initSearchableSelect(permThanaSearch, permThanaHidden, permThanaList);

    // When permanent district changes, rebuild thana list
    if (permDistHidden) {
        permDistHidden.addEventListener('change', function() {
            var did = permDistHidden.value;
            permThanaList.dataset.currentDistrict = did;
            permThanaSearch.value = '';
            permThanaHidden.value = '';
            permThanaSearch.placeholder = did ? 'Search thana…' : 'Select district first…';
            permThanaList.querySelectorAll('.aff-ss-item:not(.header-item)').forEach(function(it) {
                it.style.display = (!did || String(it.dataset.district) === String(did) || it.classList.contains('none-item')) ? '' : 'none';
            });
        });
    }

    // Present address
    var presDistSearch = document.getElementById('pres_district_search');
    var presDistHidden = document.getElementById('present_district_id');
    var presDistList   = document.getElementById('pres_district_list');
    var presThanaSearch = document.getElementById('pres_thana_search');
    var presThanaHidden = document.getElementById('present_thana_id');
    var presThanaList   = document.getElementById('pres_thana_list');

    initSearchableSelect(presDistSearch, presDistHidden, presDistList);
    initSearchableSelect(presThanaSearch, presThanaHidden, presThanaList);

    if (presDistHidden) {
        presDistHidden.addEventListener('change', function() {
            var did = presDistHidden.value;
            presThanaList.dataset.currentDistrict = did;
            presThanaSearch.value = '';
            presThanaHidden.value = '';
            presThanaSearch.placeholder = did ? 'Search thana…' : 'Select district first…';
            presThanaList.querySelectorAll('.aff-ss-item:not(.header-item)').forEach(function(it) {
                it.style.display = (!did || String(it.dataset.district) === String(did) || it.classList.contains('none-item')) ? '' : 'none';
            });
        });
    }

    // ── Same as Permanent checkbox ────────────────────────────────────────────
    var sameChk   = document.getElementById('same_as_permanent');
    var presFields = document.getElementById('present_address_fields');

    function togglePresent() {
        if (!presFields) return;
        var locked = sameChk && sameChk.checked;
        presFields.querySelectorAll('input').forEach(function(el) {
            el.readOnly = locked;
            el.style.opacity = locked ? '0.55' : '';
        });
        presFields.querySelectorAll('select').forEach(function(el) {
            el.disabled = locked;
            el.style.opacity = locked ? '0.55' : '';
        });
        presFields.querySelectorAll('.aff-ss-trigger').forEach(function(el) {
            el.readOnly = locked;
            el.style.opacity = locked ? '0.55' : '';
        });
    }

    if (sameChk) {
        sameChk.addEventListener('change', function() {
            if (sameChk.checked) {
                // Copy permanent → present
                var copyFields = [
                    ['permanent_address_1', 'present_address_1'],
                    ['permanent_address_2', 'present_address_2'],
                    ['permanent_area',      'present_area'],
                    ['permanent_post_code', 'present_post_code'],
                ];
                copyFields.forEach(function(pair) {
                    var src = document.querySelector('[name="' + pair[0] + '"]');
                    var dst = document.querySelector('[name="' + pair[1] + '"]') || document.getElementById(pair[1]);
                    if (src && dst) dst.value = src.value;
                });
                // Copy district
                if (permDistHidden && presDistHidden) {
                    presDistHidden.value = permDistHidden.value;
                    presDistSearch.value = permDistSearch.value;
                }
                // Copy thana
                if (permThanaHidden && presThanaHidden) {
                    presThanaHidden.value = permThanaHidden.value;
                    presThanaSearch.value = permThanaSearch.value;
                }
            }
            togglePresent();
        });
        togglePresent();
    }
})();
</script>
<?php endif; ?>

</body>
</html>
