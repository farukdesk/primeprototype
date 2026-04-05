<?php
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: /clubs.php'); exit; }

$event      = null;
$club       = null;
$reg_count  = 0;
$success    = false;
$errors     = [];

try {
    $db = front_db();
    if ($db) {
        $st = $db->prepare(
            "SELECT e.*, c.name AS club_name, c.slug AS club_slug, c.logo AS club_logo
             FROM club_events e
             JOIN clubs c ON c.id = e.club_id
             WHERE e.slug = ? AND e.is_published = 1"
        );
        $st->execute([$slug]);
        $event = $st->fetch();

        if ($event) {
            $st = $db->prepare("SELECT COUNT(*) FROM club_event_registrations WHERE event_id = ? AND status = 'approved'");
            $st->execute([$event['id']]);
            $reg_count = (int)$st->fetchColumn();
        }
    }
} catch (Throwable $e) { /* silently */ }

if (!$event) { header('Location: /clubs.php'); exit; }

// ── Computed flags ────────────────────────────────────────────────────────────
$deadline_passed  = $event['registration_deadline'] && $event['registration_deadline'] < date('Y-m-d');
$is_full          = $event['capacity'] && ($reg_count >= $event['capacity']);
$registration_open = !$deadline_passed && !$is_full;

// ── Handle Registration Form ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registration_open) {
    // Simple CSRF using session token
    session_start();
    $token     = $_POST['_pub_token'] ?? '';
    $sess_tok  = $_SESSION['_pub_csrf'] ?? '';

    if (!$sess_tok || !hash_equals($sess_tok, $token)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    } else {
        $full_name     = trim($_POST['full_name']    ?? '');
        $student_id_no = trim($_POST['student_id_no'] ?? '');
        $email         = trim($_POST['email']         ?? '');
        $phone         = trim($_POST['phone']         ?? '');
        $department    = trim($_POST['department']    ?? '');
        $program       = trim($_POST['program']       ?? '');
        $message       = trim($_POST['message']       ?? '');

        if ($full_name === '')   $errors[] = 'Full name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        // Prevent duplicate by email or student_id_no
        if (empty($errors) && $email !== '') {
            $st = $db->prepare('SELECT id FROM club_event_registrations WHERE event_id = ? AND email = ?');
            $st->execute([$event['id'], $email]);
            if ($st->fetch()) $errors[] = 'You have already registered with this email.';
        }
        if (empty($errors) && $student_id_no !== '') {
            $st = $db->prepare('SELECT id FROM club_event_registrations WHERE event_id = ? AND student_id_no = ?');
            $st->execute([$event['id'], $student_id_no]);
            if ($st->fetch()) $errors[] = 'You have already registered with this Student ID.';
        }

        if (empty($errors)) {
            $db->prepare(
                'INSERT INTO club_event_registrations
                    (event_id, full_name, student_id_no, email, phone, department, program, message)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $event['id'],
                $full_name,
                $student_id_no ?: null,
                $email         ?: null,
                $phone         ?: null,
                $department    ?: null,
                $program       ?: null,
                $message       ?: null,
            ]);
            unset($_SESSION['_pub_csrf']);
            $success = true;
        }
    }
}

// Generate CSRF token for form
if (!$success) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['_pub_csrf'])) {
        $_SESSION['_pub_csrf'] = bin2hex(random_bytes(16));
    }
    $pub_token = $_SESSION['_pub_csrf'];
}

$page_title = fh($event['title']) . ' – ' . fh($event['club_name']) . ' – Prime University';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= $page_title ?></title>
   <meta name="description" content="<?= fh(mb_substr(strip_tags($event['description'] ?? $event['title']), 0, 160)) ?>">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <link rel="shortcut icon" type="image/x-icon" href="/assets/img/logo/favicon.png">
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
   /* ── Event Detail Styles ─────────────────────────── */
   .pu-event-hero {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
      padding: 90px 0 70px;
      position: relative;
      overflow: hidden;
   }
   .pu-event-hero::before {
      content: '';
      position: absolute; inset: 0;
      background: url('/assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .06;
   }
   .pu-event-hero .hero-cover {
      position: absolute; inset: 0;
      background-size: cover; background-position: center;
      opacity: .2;
   }
   .pu-event-hero .hero-content { position: relative; z-index: 2; }
   .pu-event-hero h1 { font-size: clamp(1.8rem,4vw,2.6rem); font-weight: 800; color: #fff; margin-bottom: 14px; }
   .pu-event-hero .breadcrumb-nav a, .pu-event-hero .breadcrumb-nav span { color:rgba(255,255,255,.7); font-size:.84rem; }
   .pu-event-hero .breadcrumb-nav .sep { margin:0 8px; color:rgba(255,255,255,.35); }

   /* Meta badges */
   .pu-meta-badge { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:50px; padding:7px 16px; color:#fff; font-size:.875rem; backdrop-filter:blur(4px); }
   .pu-meta-badge i { color:#1abc9c; }

   /* Details card */
   .pu-details-card { border:none; border-radius:18px; box-shadow:0 6px 28px rgba(0,0,0,.09); }
   .pu-detail-item { display:flex; gap:16px; padding:16px 0; border-bottom:1px solid #f1f5f9; align-items:flex-start; }
   .pu-detail-item:last-child { border-bottom:none; }
   .pu-detail-icon { width:42px; height:42px; border-radius:10px; background:#f0faf8; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
   .pu-detail-icon i { color:#1abc9c; font-size:1rem; }
   .pu-detail-label { font-size:.78rem; color:#9ca3af; text-transform:uppercase; letter-spacing:.05em; font-weight:600; margin-bottom:3px; }
   .pu-detail-value { font-weight:600; color:#1a2e5a; }

   /* Capacity bar */
   .pu-capacity-bar { background:#e5e7eb; border-radius:50px; height:8px; overflow:hidden; }
   .pu-capacity-fill { height:8px; border-radius:50px; background:linear-gradient(90deg,#1abc9c,#16a085); transition:width .5s; }

   /* Registration form */
   .pu-reg-form { border:none; border-radius:18px; box-shadow:0 6px 28px rgba(0,0,0,.09); }
   .pu-reg-form .card-header { background:linear-gradient(135deg,#1abc9c,#16a085); border-radius:18px 18px 0 0 !important; padding:20px 28px; }
   .pu-reg-form .card-header h5 { color:#fff; font-weight:700; margin:0; }
   .pu-reg-form .card-body { padding:28px; }
   .pu-reg-form .form-control, .pu-reg-form .form-select { border-radius:10px; border-color:#e2e8f0; padding:12px 16px; }
   .pu-reg-form .form-control:focus { border-color:#1abc9c; box-shadow:0 0 0 3px rgba(26,188,156,.15); }
   .pu-btn-submit { background:linear-gradient(135deg,#1abc9c,#16a085); color:#fff; border:none; border-radius:12px; padding:15px 36px; font-weight:700; font-size:1rem; width:100%; transition:opacity .2s; cursor:pointer; }
   .pu-btn-submit:hover { opacity:.9; }

   /* Success message */
   .pu-success-card { border:none; border-radius:18px; background:linear-gradient(135deg,#f0faf8,#e8f5e9); box-shadow:0 4px 18px rgba(26,188,156,.15); text-align:center; padding:40px 32px; }
   .pu-success-icon { width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg,#1abc9c,#16a085); display:flex; align-items:center; justify-content:center; margin:0 auto 20px; }
   .pu-success-icon i { color:#fff; font-size:2rem; }

   /* Closed banner */
   .pu-closed-banner { background:linear-gradient(135deg,#f8fafc,#f1f5f9); border-radius:18px; border:2px dashed #e2e8f0; padding:40px 28px; text-align:center; }
   </style>
</head>
<body id="body" class="it-magic-cursor">
   <div id="preloader"><div class="preloader"><span></span><span></span></div></div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html"><i class="fa fa-angle-up"></i></button>
   <div class="offcanvas-overlay"></div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- Hero -->
   <section class="pu-event-hero">
      <?php if ($event['cover_photo']): ?>
      <div class="hero-cover" style="background-image:url('<?= ADMIN_UPLOAD_URL ?>/clubs/events/<?= fh($event['cover_photo']) ?>')"></div>
      <?php endif; ?>
      <div class="container hero-content">
         <nav class="breadcrumb-nav mb-3">
            <a href="/">Home</a><span class="sep">/</span>
            <a href="/clubs.php">Clubs</a><span class="sep">/</span>
            <a href="/club-detail.php?slug=<?= fh($event['club_slug']) ?>"><?= fh($event['club_name']) ?></a><span class="sep">/</span>
            <span><?= fh($event['title']) ?></span>
         </nav>
         <h1><?= fh($event['title']) ?></h1>
         <div class="d-flex flex-wrap gap-3 mt-3">
            <?php if ($event['event_date']): ?>
            <span class="pu-meta-badge"><i class="fas fa-calendar"></i><?= date('d M Y', strtotime($event['event_date'])) ?><?php if ($event['event_time']): ?> &nbsp;·&nbsp; <?= date('h:i A', strtotime($event['event_time'])) ?><?php endif; ?></span>
            <?php endif; ?>
            <?php if ($event['venue']): ?>
            <span class="pu-meta-badge"><i class="fas fa-map-marker-alt"></i><?= fh($event['venue']) ?></span>
            <?php endif; ?>
            <?php if ($event['capacity']): ?>
            <span class="pu-meta-badge"><i class="fas fa-users"></i><?= $reg_count ?> / <?= $event['capacity'] ?> registered</span>
            <?php endif; ?>
         </div>
      </div>
   </section>

   <div class="container" style="padding-top:56px;padding-bottom:80px;">
      <div class="row g-5">

         <!-- Left: Description + Details -->
         <div class="col-lg-7">
            <?php if ($event['description']): ?>
            <div class="mb-5">
               <h3 class="fw-bold mb-3" style="color:#1a2e5a;">About This Event</h3>
               <p class="text-muted lh-lg" style="font-size:1.02rem;"><?= nl2br(fh($event['description'])) ?></p>
            </div>
            <?php endif; ?>

            <div class="pu-details-card card">
               <div class="card-body p-0 px-4">

                  <?php if ($event['event_date']): ?>
                  <div class="pu-detail-item">
                     <div class="pu-detail-icon"><i class="fas fa-calendar-day"></i></div>
                     <div>
                        <div class="pu-detail-label">Date &amp; Time</div>
                        <div class="pu-detail-value"><?= date('l, d F Y', strtotime($event['event_date'])) ?><?php if ($event['event_time']): ?> &nbsp;at&nbsp; <?= date('h:i A', strtotime($event['event_time'])) ?><?php endif; ?></div>
                     </div>
                  </div>
                  <?php endif; ?>

                  <?php if ($event['venue']): ?>
                  <div class="pu-detail-item">
                     <div class="pu-detail-icon"><i class="fas fa-map-marker-alt"></i></div>
                     <div>
                        <div class="pu-detail-label">Venue</div>
                        <div class="pu-detail-value"><?= fh($event['venue']) ?></div>
                     </div>
                  </div>
                  <?php endif; ?>

                  <div class="pu-detail-item">
                     <div class="pu-detail-icon"><i class="fas fa-users"></i></div>
                     <div class="flex-grow-1">
                        <div class="pu-detail-label">Capacity &amp; Registrations</div>
                        <?php if ($event['capacity']): ?>
                        <div class="pu-detail-value mb-2"><?= $reg_count ?> registered <?php if ($event['capacity']): ?>/ <?= $event['capacity'] ?> total<?php endif; ?></div>
                        <div class="pu-capacity-bar">
                           <div class="pu-capacity-fill" style="width:<?= min(100, round($reg_count/$event['capacity']*100)) ?>%"></div>
                        </div>
                        <?php else: ?>
                        <div class="pu-detail-value"><?= $reg_count ?> registered &nbsp;·&nbsp; <span class="text-muted">Unlimited capacity</span></div>
                        <?php endif; ?>
                     </div>
                  </div>

                  <?php if ($event['registration_deadline']): ?>
                  <div class="pu-detail-item">
                     <div class="pu-detail-icon"><i class="fas fa-hourglass-end"></i></div>
                     <div>
                        <div class="pu-detail-label">Registration Deadline</div>
                        <div class="pu-detail-value"><?= date('d M Y', strtotime($event['registration_deadline'])) ?></div>
                     </div>
                  </div>
                  <?php endif; ?>

                  <div class="pu-detail-item">
                     <div class="pu-detail-icon"><i class="fas fa-users"></i></div>
                     <div>
                        <div class="pu-detail-label">Organised by</div>
                        <div class="pu-detail-value">
                           <?php if ($event['club_logo']): ?>
                           <img src="<?= ADMIN_UPLOAD_URL ?>/clubs/logos/<?= fh($event['club_logo']) ?>" alt="" class="rounded-circle me-2" style="width:24px;height:24px;object-fit:cover;">
                           <?php endif; ?>
                           <a href="/club-detail.php?slug=<?= fh($event['club_slug']) ?>"><?= fh($event['club_name']) ?></a>
                        </div>
                     </div>
                  </div>

               </div>
            </div>

         </div><!-- /.col-lg-7 -->

         <!-- Right: Registration Form -->
         <div class="col-lg-5">
            <?php if ($success): ?>
            <div class="pu-success-card">
               <div class="pu-success-icon"><i class="fas fa-check"></i></div>
               <h4 class="fw-bold text-success mb-2">Registration Successful!</h4>
               <p class="text-muted mb-4">Your registration is pending approval. You will be notified once it is reviewed.</p>
               <a href="/club-detail.php?slug=<?= fh($event['club_slug']) ?>" class="btn btn-outline-success rounded-pill px-4">Back to Club</a>
            </div>

            <?php elseif (!$registration_open): ?>
            <div class="pu-closed-banner">
               <i class="fas fa-lock fa-2x text-muted mb-3 d-block"></i>
               <h5 class="fw-bold text-muted mb-2"><?= $is_full ? 'Event is Full' : 'Registration Closed' ?></h5>
               <p class="text-muted small"><?= $is_full ? 'All seats have been taken for this event.' : 'The registration deadline has passed.' ?></p>
               <a href="/club-detail.php?slug=<?= fh($event['club_slug']) ?>" class="btn btn-outline-secondary rounded-pill mt-2">Back to Club</a>
            </div>

            <?php else: ?>
            <div class="pu-reg-form card sticky-top" style="top:100px;">
               <div class="card-header">
                  <h5><i class="fas fa-user-plus me-2"></i>Register for This Event</h5>
               </div>
               <div class="card-body">
                  <?php if (!empty($errors)): ?>
                  <div class="alert alert-danger mb-3">
                     <ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= fh($e) ?></li><?php endforeach; ?></ul>
                  </div>
                  <?php endif; ?>

                  <form method="post" novalidate>
                     <input type="hidden" name="_pub_token" value="<?= fh($pub_token ?? '') ?>">

                     <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" value="<?= fh($_POST['full_name'] ?? '') ?>" required placeholder="Your full name">
                     </div>
                     <div class="mb-3">
                        <label class="form-label fw-semibold">Student ID</label>
                        <input type="text" name="student_id_no" class="form-control" value="<?= fh($_POST['student_id_no'] ?? '') ?>" placeholder="e.g. 240101010001" maxlength="30">
                     </div>
                     <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= fh($_POST['email'] ?? '') ?>" placeholder="your@email.com">
                     </div>
                     <div class="mb-3">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="tel" name="phone" class="form-control" value="<?= fh($_POST['phone'] ?? '') ?>" placeholder="01XXXXXXXXX">
                     </div>
                     <div class="mb-3">
                        <label class="form-label fw-semibold">Department</label>
                        <input type="text" name="department" class="form-control" value="<?= fh($_POST['department'] ?? '') ?>" placeholder="e.g. CSE, BBA, EEE">
                     </div>
                     <div class="mb-3">
                        <label class="form-label fw-semibold">Program</label>
                        <input type="text" name="program" class="form-control" value="<?= fh($_POST['program'] ?? '') ?>" placeholder="e.g. B.Sc., MBA">
                     </div>
                     <div class="mb-4">
                        <label class="form-label fw-semibold">Message / Why do you want to join? <small class="text-muted">(optional)</small></label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Tell us a bit about yourself…"><?= fh($_POST['message'] ?? '') ?></textarea>
                     </div>

                     <button type="submit" class="pu-btn-submit">
                        <i class="fas fa-paper-plane me-2"></i>Submit Registration
                     </button>
                     <p class="text-muted small text-center mt-3 mb-0"><i class="fas fa-info-circle me-1"></i>Your registration will be reviewed and approved by the club.</p>
                  </form>
               </div>
            </div>
            <?php endif; ?>

         </div><!-- /.col-lg-5 -->

      </div><!-- /.row -->
   </div><!-- /.container -->

   <?php include __DIR__ . '/includes/footer.php'; ?>

   <script src="/assets/js/jquery.js"></script>
   <script src="/assets/js/bootstrap.bundle.min.js"></script>
   <script src="/assets/js/purecounter.js"></script>
   <script src="/assets/js/nice-select.js"></script>
   <script src="/assets/js/swiper-bundle.min.js"></script>
   <script src="/assets/js/slick.min.js"></script>
   <script src="/assets/js/wow.js"></script>
   <script src="/assets/js/magnific-popup.js"></script>
   <script src="/assets/js/parallax.js"></script>
   <script src="/assets/js/main.js"></script>
</body>
</html>
