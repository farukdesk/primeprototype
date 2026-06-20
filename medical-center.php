<?php
/**
 * Medical Center – Public Page
 * Prime University
 */
require_once __DIR__ . '/includes/config.php';

$page_title = 'Medical Center – Prime University';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pub_csrf'] ?? ($_SESSION['pub_csrf'] = bin2hex(random_bytes(16)));

$form_success = false;
$form_errors  = [];
$form_data    = [
    'patient_name'   => '',
    'patient_type'   => 'student',
    'patient_id_no'  => '',
    'department'     => '',
    'contact_number' => '',
    'email'          => '',
    'appointment_date' => '',
    'appointment_time' => '',
    'chief_complaint'  => '',
];

// Load settings & health tips
$settings = [];
$tips     = [];
$schedules = [];
$appointment_enabled = true;

try {
    $db = front_db();
    if ($db) {
        $rows     = $db->query('SELECT `key`, `value` FROM mc_settings')->fetchAll(PDO::FETCH_ASSOC);
        $settings = array_column($rows, 'value', 'key');
        $tips     = $db->query("SELECT * FROM mc_health_tips WHERE is_published = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $schedules = $db->query("SELECT * FROM mc_schedules ORDER BY day_of_week")->fetchAll(PDO::FETCH_ASSOC);
        $appointment_enabled = ($settings['appointment_enabled'] ?? '1') === '1';
    }
} catch (Throwable $e) {
    // graceful degradation
}

$clinic_name     = $settings['clinic_name']          ?? 'Prime University Medical Center';
$doctor_name     = $settings['doctor_name']          ?? 'Dr. Saida Ahmed';
$doctor_qual     = $settings['doctor_qualification'] ?? 'MBBS, MPH (NIPSOM), CCD, CCVD, FCGP';
$doctor_desg     = $settings['doctor_designation']   ?? 'Medical Officer';
$clinic_location = $settings['clinic_location']      ?? 'Ground Floor, Administrative Building';
$hours_weekday   = $settings['clinic_hours_weekday'] ?? '9:00 AM – 5:00 PM';
$hours_weekend   = $settings['clinic_hours_weekend'] ?? 'Closed';
$contact_phone   = $settings['contact_phone']        ?? '01969-955566';
$contact_email   = $settings['contact_email']        ?? 'medical@primeuniversity.ac.bd';
$emergency_note  = $settings['emergency_note']       ?? 'For emergency, call 999 or proceed to nearest hospital immediately.';

// Day names
$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $appointment_enabled) {
    if (!hash_equals($_SESSION['pub_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $form_data['patient_name']    = trim($_POST['patient_name']    ?? '');
        $form_data['patient_type']    = $_POST['patient_type']         ?? 'student';
        $form_data['patient_id_no']   = trim($_POST['patient_id_no']   ?? '');
        $form_data['department']      = trim($_POST['department']      ?? '');
        $form_data['contact_number']  = trim($_POST['contact_number']  ?? '');
        $form_data['email']           = trim($_POST['email']           ?? '');
        $form_data['appointment_date']= trim($_POST['appointment_date'] ?? '');
        $form_data['appointment_time']= trim($_POST['appointment_time'] ?? '');
        $form_data['chief_complaint'] = trim($_POST['chief_complaint'] ?? '');

        if ($form_data['patient_name'] === '')     $form_errors[] = 'Full name is required.';
        if ($form_data['contact_number'] === '')   $form_errors[] = 'Contact number is required.';
        if ($form_data['appointment_date'] === '') $form_errors[] = 'Appointment date is required.';
        if ($form_data['appointment_time'] === '') $form_errors[] = 'Appointment time is required.';
        if (!in_array($form_data['patient_type'], ['student','faculty','staff','officer'], true)) {
            $form_data['patient_type'] = 'student';
        }
        if ($form_data['email'] !== '' && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            $form_errors[] = 'Please enter a valid email address.';
        }
        if ($form_data['appointment_date'] !== '' && strtotime($form_data['appointment_date']) < strtotime('today')) {
            $form_errors[] = 'Appointment date cannot be in the past.';
        }

        if (empty($form_errors)) {
            try {
                $db = front_db();
                if ($db) {
                    $db->prepare(
                        'INSERT INTO mc_appointments
                         (patient_name, patient_type, patient_id_no, department, contact_number, email,
                          appointment_date, appointment_time, chief_complaint, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $form_data['patient_name'],
                        $form_data['patient_type'],
                        $form_data['patient_id_no'],
                        $form_data['department'],
                        $form_data['contact_number'],
                        $form_data['email'],
                        $form_data['appointment_date'],
                        $form_data['appointment_time'],
                        $form_data['chief_complaint'],
                        'pending',
                    ]);
                    $new_id = (int)$db->lastInsertId();

                    // Generate token
                    $token = 'APT-' . date('Ymd') . '-' . str_pad($new_id, 4, '0', STR_PAD_LEFT);
                    $db->prepare('UPDATE mc_appointments SET token_number = ? WHERE id = ?')->execute([$token, $new_id]);

                    $form_success = true;
                    $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
                    $csrf_token           = $_SESSION['pub_csrf'];
                    $form_data = array_fill_keys(array_keys($form_data), '');
                    $form_data['patient_type'] = 'student';
                    $_SESSION['apt_token'] = $token;
                }
            } catch (Throwable $e) {
                $form_errors[] = 'Something went wrong. Please try again later.';
            }
        }
    }
}

$apt_token = $_SESSION['apt_token'] ?? '';
if ($form_success) unset($_SESSION['apt_token']);
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title><?= fh($page_title) ?></title>
    <meta name="description" content="Prime University Medical Center provides free healthcare services to students, faculty, and staff. Book an appointment online.">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/font-awesome-pro.css">
    <link rel="stylesheet" href="/assets/css/swiper-bundle.min.css">
    <link rel="stylesheet" href="/assets/css/slick.css">
    <link rel="stylesheet" href="/assets/css/magnific-popup.css">
    <link rel="stylesheet" href="/assets/css/nice-select.css">
    <link rel="stylesheet" href="/assets/css/custom-animation.css">
    <link rel="stylesheet" href="/assets/css/spacing.css">
    <link rel="stylesheet" href="/assets/css/main.css">
    <style>
    /* ── Medical Center Hero ────────────────────────────────────── */
    .mc-hero {
        background: linear-gradient(135deg, #0a2342 0%, #1a6b5a 60%, #20b2aa 100%);
        padding: 90px 0 80px;
        position: relative;
        overflow: hidden;
    }
    .mc-hero::before {
        content: '';
        position: absolute; inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .mc-hero .hero-circle {
        position: absolute;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
        animation: floatBubble 8s ease-in-out infinite;
    }
    .mc-hero .hero-circle.c1 { width:300px;height:300px;top:-80px;right:-60px;animation-delay:0s; }
    .mc-hero .hero-circle.c2 { width:200px;height:200px;bottom:-50px;left:10%;animation-delay:3s; }
    .mc-hero .hero-circle.c3 { width:150px;height:150px;top:30%;left:5%;animation-delay:5s; }
    @keyframes floatBubble {
        0%,100% { transform:translateY(0) scale(1); }
        50% { transform:translateY(-20px) scale(1.05); }
    }
    .mc-hero-badge {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255,255,255,0.25);
        color: #fff; padding: 6px 18px; border-radius: 50px;
        font-size: 0.85rem; margin-bottom: 20px; font-weight: 500;
    }
    .mc-hero h1 { color: #fff; font-size: clamp(2rem, 5vw, 3rem); font-weight: 700; line-height: 1.2; }
    .mc-hero p  { color: rgba(255,255,255,0.85); font-size: 1.05rem; }

    /* ── Services ────────────────────────────────────────────────── */
    .mc-service-card {
        border: none; border-radius: 12px; padding: 28px 24px;
        transition: transform .3s ease, box-shadow .3s ease;
        background: #fff; height: 100%;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    }
    .mc-service-card:hover { transform: translateY(-6px); box-shadow: 0 12px 36px rgba(0,0,0,0.12); }
    .mc-service-icon {
        width: 60px; height: 60px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem; margin-bottom: 16px;
    }

    /* ── Doctor Card ─────────────────────────────────────────────── */
    .mc-doctor-card {
        background: linear-gradient(135deg, #0a2342 0%, #1a5276 100%);
        color: #fff; border-radius: 16px; overflow: hidden; position: relative;
    }
    .mc-doctor-card::after {
        content: '';
        position: absolute; top: -60px; right: -60px;
        width: 200px; height: 200px; border-radius: 50%;
        background: rgba(255,255,255,0.06);
    }
    .mc-doctor-avatar {
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(255,255,255,0.15);
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem; color: #fff; border: 3px solid rgba(255,255,255,0.3);
    }

    /* ── Hours Table ─────────────────────────────────────────────── */
    .mc-hours-table tr td:first-child { font-weight: 600; color: #333; }
    .mc-hours-table tr.today td { background: #e8f5e9; color: #1a6b3a; }
    .mc-hours-table tr.closed td:last-child { color: #e74c3c; font-weight: 600; }

    /* ── Health Tips ─────────────────────────────────────────────── */
    .mc-tip-card {
        border-left: 4px solid #20b2aa;
        background: #f8fffe; border-radius: 0 8px 8px 0;
        padding: 16px 20px; height: 100%;
        transition: border-color .25s, box-shadow .25s;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    }
    .mc-tip-card:hover { border-color: #0a2342; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
    .mc-tip-category {
        font-size: 0.7rem; font-weight: 700; letter-spacing: 1px;
        text-transform: uppercase; color: #20b2aa; margin-bottom: 6px;
    }

    /* ── Appointment Form ────────────────────────────────────────── */
    .mc-form-section { background: linear-gradient(135deg, #f0fdfa 0%, #e8f4fd 100%); }
    .mc-form-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,0.08); }

    /* ── Emergency Banner ────────────────────────────────────────── */
    .mc-emergency { background: linear-gradient(90deg, #c0392b 0%, #e74c3c 100%); color: #fff; }

    /* ── Section headings ────────────────────────────────────────── */
    .mc-section-tag {
        display: inline-block; background: rgba(32,178,170,0.12);
        color: #1a6b5a; font-size: 0.8rem; font-weight: 700;
        letter-spacing: 1.5px; text-transform: uppercase;
        padding: 5px 16px; border-radius: 50px; margin-bottom: 12px;
    }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/header-top.php'; ?>
<?php include __DIR__ . '/includes/nav-menu.php'; ?>

<!-- ── HERO ────────────────────────────────────────────────────────── -->
<section class="mc-hero">
    <div class="hero-circle c1"></div>
    <div class="hero-circle c2"></div>
    <div class="hero-circle c3"></div>
    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="mc-hero-badge">
                    <i class="fas fa-heartbeat"></i>
                    Your Health Is Our Priority
                </div>
                <h1 class="mb-3"><?= fh($clinic_name) ?></h1>
                <p class="mb-4">
                    Free, confidential healthcare for Prime University students, faculty, and staff.
                    Qualified doctors, essential medicines, and a caring environment — all on campus.
                </p>
                <div class="d-flex gap-3 flex-wrap">
                    <?php if ($appointment_enabled): ?>
                    <a href="#book-appointment" class="btn btn-light text-dark fw-semibold px-4 py-2">
                        <i class="fas fa-calendar-plus me-2"></i> Book Appointment
                    </a>
                    <?php endif; ?>
                    <a href="tel:<?= fh(preg_replace('/[^0-9+]/', '', $contact_phone)) ?>" class="btn btn-outline-light px-4 py-2">
                        <i class="fas fa-phone me-2"></i> <?= fh($contact_phone) ?>
                    </a>
                </div>
            </div>
            <div class="col-lg-5 d-none d-lg-flex justify-content-end">
                <div class="text-center">
                    <div style="font-size:8rem;opacity:.25;color:#fff;">
                        <i class="fas fa-hospital"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── EMERGENCY BANNER ─────────────────────────────────────────────── -->
<?php if ($emergency_note): ?>
<div class="mc-emergency py-2">
    <div class="container d-flex align-items-center gap-3">
        <i class="fas fa-exclamation-triangle fa-lg flex-shrink-0"></i>
        <span class="fw-semibold small"><?= fh($emergency_note) ?></span>
    </div>
</div>
<?php endif; ?>

<!-- ── SERVICES ─────────────────────────────────────────────────────── -->
<section class="py-5" style="background:#f8f9fa">
    <div class="container">
        <div class="text-center mb-5">
            <div class="mc-section-tag">Our Services</div>
            <h2 class="h3 fw-bold">Comprehensive Campus Healthcare</h2>
            <p class="text-muted">Everything you need to stay healthy during your academic journey.</p>
        </div>
        <div class="row g-4">
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#e8f5e9"><i class="fas fa-stethoscope" style="color:#2ecc71"></i></div>
                    <h5 class="fw-bold mb-2">General Consultation</h5>
                    <p class="text-muted small mb-0">Walk-in and appointment-based consultations for all common ailments, illness, and health concerns.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#e8f4fd"><i class="fas fa-pills" style="color:#3498db"></i></div>
                    <h5 class="fw-bold mb-2">Free Medicines</h5>
                    <p class="text-muted small mb-0">Essential medicines dispensed free of charge to all registered students and university staff.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#fff8e1"><i class="fas fa-heartbeat" style="color:#f39c12"></i></div>
                    <h5 class="fw-bold mb-2">Health Screening</h5>
                    <p class="text-muted small mb-0">Routine blood pressure, blood glucose, BMI, and other preventive health screenings available on-site.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#fce4ec"><i class="fas fa-brain" style="color:#e91e63"></i></div>
                    <h5 class="fw-bold mb-2">Mental Health Support</h5>
                    <p class="text-muted small mb-0">Confidential counseling and mental wellness support for students dealing with stress, anxiety, or personal challenges.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#ede7f6"><i class="fas fa-syringe" style="color:#9c27b0"></i></div>
                    <h5 class="fw-bold mb-2">First Aid &amp; Emergency</h5>
                    <p class="text-muted small mb-0">Immediate first aid care for injuries and emergencies occurring within the campus premises.</p>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="mc-service-card">
                    <div class="mc-service-icon" style="background:#e0f2f1"><i class="fas fa-file-medical" style="color:#00897b"></i></div>
                    <h5 class="fw-bold mb-2">Medical Certificates</h5>
                    <p class="text-muted small mb-0">Issuance of medical fitness certificates, sick notes, and referral letters for students and employees.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── DOCTOR & HOURS ────────────────────────────────────────────────── -->
<section class="py-5">
    <div class="container">
        <div class="row g-4 align-items-start">
            <!-- Doctor -->
            <div class="col-lg-5">
                <div class="mc-doctor-card p-4">
                    <div class="mc-section-tag" style="background:rgba(255,255,255,0.15);color:#a0e6df">Meet Our Doctor</div>
                    <div class="d-flex align-items-center gap-3 mt-3 mb-3">
                        <div class="mc-doctor-avatar flex-shrink-0">
                            <i class="fas fa-user-md"></i>
                        </div>
                        <div>
                            <div class="fw-bold fs-5"><?= fh($doctor_name) ?></div>
                            <div style="color:rgba(255,255,255,0.7);font-size:0.85rem"><?= fh($doctor_qual) ?></div>
                        </div>
                    </div>
                    <div class="badge mb-3" style="background:rgba(255,255,255,0.15);font-size:0.8rem;font-weight:500">
                        <?= fh($doctor_desg) ?>
                    </div>
                    <hr style="border-color:rgba(255,255,255,0.2)">
                    <div class="d-flex flex-column gap-2 small">
                        <div><i class="fas fa-map-marker-alt me-2" style="color:#a0e6df"></i><?= fh($clinic_location) ?></div>
                        <div><i class="fas fa-phone me-2" style="color:#a0e6df"></i><?= fh($contact_phone) ?></div>
                        <div><i class="fas fa-envelope me-2" style="color:#a0e6df"></i><?= fh($contact_email) ?></div>
                    </div>
                </div>
            </div>

            <!-- Hours -->
            <div class="col-lg-7">
                <div class="mc-section-tag">Clinic Hours</div>
                <h2 class="h3 fw-bold mb-4">When We Are Available</h2>
                <?php
                $today_dow = (int)date('w');
                ?>
                <div class="card border-0 shadow-sm">
                    <div class="table-responsive">
                        <table class="table mc-hours-table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Day</th>
                                    <th>Hours</th>
                                    <th>Slots</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($schedules): ?>
                                <?php foreach ($schedules as $sched): ?>
                                <?php
                                $dow       = (int)$sched['day_of_week'];
                                $is_today  = $dow === $today_dow;
                                $available = (bool)$sched['is_available'];
                                ?>
                                <tr class="<?= $is_today ? 'today' : '' ?> <?= !$available ? 'closed' : '' ?>">
                                    <td>
                                        <?= fh($day_names[$dow] ?? '') ?>
                                        <?php if ($is_today): ?><span class="badge bg-success ms-1" style="font-size:.65rem">Today</span><?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= $available
                                            ? date('h:i A', strtotime($sched['start_time'])) . ' – ' . date('h:i A', strtotime($sched['end_time']))
                                            : '—' ?>
                                    </td>
                                    <td class="small text-muted"><?= $available ? (int)$sched['max_slots'] : '—' ?></td>
                                    <td>
                                        <?php if ($available): ?>
                                        <span class="badge bg-success">Open</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Closed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td>Mon – Thu</td>
                                    <td><?= fh($hours_weekday) ?></td>
                                    <td>—</td>
                                    <td><span class="badge bg-success">Open</span></td>
                                </tr>
                                <tr>
                                    <td>Fri – Sat</td>
                                    <td><?= fh($hours_weekend) ?></td>
                                    <td>—</td>
                                    <td><span class="badge bg-secondary">Varies</span></td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="alert alert-info mt-3 small mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Location: <?= fh($clinic_location) ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── HEALTH TIPS ───────────────────────────────────────────────────── -->
<?php if ($tips): ?>
<section class="py-5" style="background:#f8f9fa">
    <div class="container">
        <div class="text-center mb-5">
            <div class="mc-section-tag">Health Tips</div>
            <h2 class="h3 fw-bold">Stay Healthy, Stay Active</h2>
            <p class="text-muted">Evidence-based health advice from our medical team.</p>
        </div>
        <div class="row g-3">
            <?php foreach ($tips as $tip): ?>
            <div class="col-sm-6 col-lg-3">
                <div class="mc-tip-card">
                    <div class="mc-tip-category"><?= fh($tip['category'] ?? 'General') ?></div>
                    <h6 class="fw-bold mb-2"><?= fh($tip['title']) ?></h6>
                    <p class="text-muted small mb-0"><?= fh($tip['content']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── APPOINTMENT FORM ──────────────────────────────────────────────── -->
<?php if ($appointment_enabled): ?>
<section class="mc-form-section py-5" id="book-appointment">
    <div class="container">
        <div class="text-center mb-5">
            <div class="mc-section-tag">Online Booking</div>
            <h2 class="h3 fw-bold">Book an Appointment</h2>
            <p class="text-muted">Fill in the form below and we will confirm your appointment shortly.</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="mc-form-card p-4 p-md-5">

                    <?php if ($form_success): ?>
                    <div class="text-center py-4">
                        <div style="font-size:4rem;color:#2ecc71"><i class="fas fa-check-circle"></i></div>
                        <h4 class="fw-bold mt-3 mb-2">Appointment Submitted!</h4>
                        <p class="text-muted">Your appointment request has been received. We will contact you to confirm.</p>
                        <?php if ($apt_token): ?>
                        <div class="alert alert-success d-inline-block mt-2">
                            Your Token: <strong class="fs-5"><?= fh($apt_token) ?></strong>
                        </div>
                        <?php endif; ?>
                        <div class="mt-3">
                            <a href="#book-appointment" class="btn btn-outline-success" onclick="location.reload()">
                                Book Another Appointment
                            </a>
                        </div>
                    </div>

                    <?php else: ?>

                    <?php if ($form_errors): ?>
                    <div class="alert alert-danger mb-4">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($form_errors as $err): ?>
                            <li><?= fh($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <form method="post" action="<?= fh(SITE_URL) ?>/medical-center.php#book-appointment">
                        <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="patient_name" class="form-control"
                                       placeholder="Your full name"
                                       value="<?= fh($form_data['patient_name']) ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">I am a</label>
                                <select name="patient_type" class="form-select">
                                    <option value="student"  <?= $form_data['patient_type'] === 'student'  ? 'selected' : '' ?>>Student</option>
                                    <option value="faculty"  <?= $form_data['patient_type'] === 'faculty'  ? 'selected' : '' ?>>Faculty</option>
                                    <option value="staff"    <?= $form_data['patient_type'] === 'staff'    ? 'selected' : '' ?>>Staff</option>
                                    <option value="officer"  <?= $form_data['patient_type'] === 'officer'  ? 'selected' : '' ?>>Officer</option>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Student / Employee ID</label>
                                <input type="text" name="patient_id_no" class="form-control"
                                       placeholder="Optional"
                                       value="<?= fh($form_data['patient_id_no']) ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Department</label>
                                <input type="text" name="department" class="form-control"
                                       placeholder="e.g. CSE, BBA"
                                       value="<?= fh($form_data['department']) ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                                <input type="tel" name="contact_number" class="form-control"
                                       placeholder="01XXXXXXXXX"
                                       value="<?= fh($form_data['contact_number']) ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Email Address</label>
                                <input type="email" name="email" class="form-control"
                                       placeholder="Optional"
                                       value="<?= fh($form_data['email']) ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Preferred Date <span class="text-danger">*</span></label>
                                <input type="date" name="appointment_date" class="form-control"
                                       min="<?= date('Y-m-d') ?>"
                                       value="<?= fh($form_data['appointment_date']) ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold">Preferred Time <span class="text-danger">*</span></label>
                                <input type="time" name="appointment_time" class="form-control"
                                       value="<?= fh($form_data['appointment_time']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Chief Complaint / Reason for Visit</label>
                                <textarea name="chief_complaint" rows="3" class="form-control"
                                          placeholder="Briefly describe your symptoms or reason for visiting…"><?= fh($form_data['chief_complaint']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100 py-3 fw-semibold">
                                    <i class="fas fa-calendar-check me-2"></i> Submit Appointment Request
                                </button>
                            </div>
                            <div class="col-12">
                                <p class="text-muted small text-center mb-0">
                                    <i class="fas fa-lock me-1"></i>
                                    Your information is confidential and used only for appointment scheduling.
                                </p>
                            </div>
                        </div>
                    </form>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ── CONTACT & LOCATION ─────────────────────────────────────────────── -->
<section class="py-5" style="background:#0a2342">
    <div class="container">
        <div class="row g-4 text-white">
            <div class="col-md-4 text-center">
                <div class="mb-3" style="font-size:2.5rem;opacity:.7"><i class="fas fa-map-marker-alt"></i></div>
                <h6 class="fw-bold">Location</h6>
                <p class="small" style="color:rgba(255,255,255,.7)"><?= fh($clinic_location) ?></p>
            </div>
            <div class="col-md-4 text-center">
                <div class="mb-3" style="font-size:2.5rem;opacity:.7"><i class="fas fa-phone"></i></div>
                <h6 class="fw-bold">Phone</h6>
                <p class="small" style="color:rgba(255,255,255,.7)">
                    <a href="tel:<?= fh(preg_replace('/[^0-9+]/', '', $contact_phone)) ?>" style="color:inherit;text-decoration:none">
                        <?= fh($contact_phone) ?>
                    </a>
                </p>
            </div>
            <div class="col-md-4 text-center">
                <div class="mb-3" style="font-size:2.5rem;opacity:.7"><i class="fas fa-envelope"></i></div>
                <h6 class="fw-bold">Email</h6>
                <p class="small" style="color:rgba(255,255,255,.7)">
                    <a href="mailto:<?= fh($contact_email) ?>" style="color:inherit;text-decoration:none">
                        <?= fh($contact_email) ?>
                    </a>
                </p>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
