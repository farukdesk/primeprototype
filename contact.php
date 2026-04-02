<?php
require_once __DIR__ . '/includes/config.php';

$page_title = 'Contact Us – Prime University';

// ── CSRF token (used for the contact form) ────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pub_csrf'] ?? ($_SESSION['pub_csrf'] = bin2hex(random_bytes(16)));

// ── Handle form submission ─────────────────────────────────────────────────────
$form_success = false;
$form_errors  = [];
$form_data    = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['pub_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        $form_errors[] = 'Invalid security token. Please try again.';
    } else {
        $form_data['name']    = trim($_POST['name']    ?? '');
        $form_data['email']   = trim($_POST['email']   ?? '');
        $form_data['phone']   = trim($_POST['phone']   ?? '');
        $form_data['subject'] = trim($_POST['subject'] ?? '');
        $form_data['message'] = trim($_POST['message'] ?? '');

        if ($form_data['name'] === '')                              $form_errors[] = 'Your name is required.';
        if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) $form_errors[] = 'A valid email address is required.';
        if ($form_data['subject'] === '')                           $form_errors[] = 'Subject is required.';
        if (strlen($form_data['message']) < 10)                    $form_errors[] = 'Message must be at least 10 characters.';

        if (empty($form_errors)) {
            try {
                $db = front_db();
                if ($db) {
                    $db->prepare(
                        'INSERT INTO contact_messages (name, email, phone, subject, message) VALUES (?,?,?,?,?)'
                    )->execute([
                        $form_data['name'],
                        $form_data['email'],
                        $form_data['phone'],
                        $form_data['subject'],
                        $form_data['message'],
                    ]);
                    $form_success = true;
                    $_SESSION['pub_csrf'] = bin2hex(random_bytes(16));
                    $csrf_token           = $_SESSION['pub_csrf'];
                    $form_data            = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];
                }
            } catch (Throwable $e) {
                $form_errors[] = 'Something went wrong. Please try again later.';
            }
        }
    }
}
?>
<!doctype html>
<html class="no-js" lang="en">

<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <title><?= fh($page_title) ?></title>
   <meta name="description" content="Get in touch with Prime University. We&rsquo;re here to answer your questions about admissions, programs, and campus life.">
   <meta name="viewport" content="width=device-width, initial-scale=1">

   <link rel="shortcut icon" type="image/x-icon" href="assets/img/logo/favicon.png">

   <!-- CSS Here -->
   <link rel="stylesheet" href="assets/css/bootstrap.min.css">
   <link rel="stylesheet" href="assets/css/font-awesome-pro.css">
   <link rel="stylesheet" href="assets/css/swiper-bundle.min.css">
   <link rel="stylesheet" href="assets/css/slick.css">
   <link rel="stylesheet" href="assets/css/magnific-popup.css">
   <link rel="stylesheet" href="assets/css/nice-select.css">
   <link rel="stylesheet" href="assets/css/custom-animation.css">
   <link rel="stylesheet" href="assets/css/spacing.css">
   <link rel="stylesheet" href="assets/css/main.css">

   <style>
   /* ── Contact Page Custom Styles ──────────────────────────────────────────── */

   /* Hero */
   .pu-contact-hero {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      padding: 90px 0 80px;
      position: relative;
      overflow: hidden;
   }
   .pu-contact-hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: url('assets/img/shape/breadcrumb-1-bg.png') center/cover no-repeat;
      opacity: .07;
   }
   .pu-contact-hero::after {
      content: '';
      position: absolute;
      right: -80px;
      top: -80px;
      width: 360px;
      height: 360px;
      background: rgba(255,255,255,.05);
      border-radius: 50%;
   }
   .pu-contact-hero .breadcrumb-nav a,
   .pu-contact-hero .breadcrumb-nav span {
      color: rgba(255,255,255,.7);
      font-size: .85rem;
   }
   .pu-contact-hero .breadcrumb-nav a:hover { color: #fff; }
   .pu-contact-hero .breadcrumb-nav .sep { margin: 0 8px; color: rgba(255,255,255,.4); }
   .pu-contact-hero h1 {
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 800;
      color: #fff;
      line-height: 1.2;
      margin-bottom: 14px;
   }
   .pu-contact-hero .tagline {
      font-size: 1.05rem;
      color: rgba(255,255,255,.82);
      max-width: 540px;
   }

   /* Info cards strip */
   .pu-info-strip {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 40px rgba(0,0,0,.10);
      margin-top: -50px;
      position: relative;
      z-index: 10;
      overflow: hidden;
   }
   .pu-info-card {
      padding: 36px 28px;
      text-align: center;
      position: relative;
      transition: background .3s, transform .3s;
      cursor: default;
   }
   .pu-info-card + .pu-info-card::before {
      content: '';
      position: absolute;
      left: 0; top: 20%; bottom: 20%;
      width: 1px;
      background: #e8edf5;
   }
   .pu-info-card:hover {
      background: #f5f8ff;
      transform: translateY(-4px);
   }
   .pu-info-icon {
      width: 64px; height: 64px;
      border-radius: 18px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 18px;
      font-size: 1.4rem;
   }
   .pu-info-icon.blue   { background: #eef2ff; color: #2563eb; }
   .pu-info-icon.green  { background: #ecfdf5; color: #059669; }
   .pu-info-icon.amber  { background: #fffbeb; color: #d97706; }
   .pu-info-icon.red    { background: #fff1f2; color: #e11d48; }
   .pu-info-card h5 {
      font-size: 1rem;
      font-weight: 700;
      color: #1a2e5a;
      margin-bottom: 10px;
   }
   .pu-info-card p,
   .pu-info-card a {
      font-size: .88rem;
      color: #6b7280;
      line-height: 1.7;
      text-decoration: none;
      display: block;
   }
   .pu-info-card a:hover { color: #2563eb; }

   /* Section labels */
   .pu-section-tag {
      display: inline-block;
      background: #eef2ff;
      color: #2563eb;
      font-size: .78rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      padding: 5px 14px;
      border-radius: 50px;
      margin-bottom: 10px;
   }
   .pu-section-title {
      font-size: clamp(1.6rem, 3vw, 2.2rem);
      font-weight: 800;
      color: #1a2e5a;
      line-height: 1.25;
   }

   /* Map container */
   .pu-map-wrap {
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 8px 40px rgba(0,0,0,.10);
      height: 100%;
      min-height: 400px;
   }
   .pu-map-wrap iframe {
      width: 100%; height: 100%; min-height: 400px;
      border: 0; display: block;
   }

   /* Contact form card */
   .pu-form-card {
      background: #fff;
      border-radius: 20px;
      box-shadow: 0 8px 40px rgba(0,0,0,.08);
      padding: 40px 36px;
   }
   @media (max-width: 575px) {
      .pu-form-card { padding: 28px 20px; }
   }
   .pu-form-card .form-label {
      font-size: .82rem;
      font-weight: 600;
      color: #374151;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: 7px;
   }
   .pu-form-card .form-control,
   .pu-form-card .form-select {
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 16px;
      font-size: .92rem;
      color: #374151;
      background: #f8fafc;
      transition: border-color .2s, box-shadow .2s;
   }
   .pu-form-card .form-control:focus,
   .pu-form-card .form-select:focus {
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37,99,235,.12);
      background: #fff;
      outline: none;
   }
   .pu-form-card textarea.form-control { resize: vertical; min-height: 140px; }
   .pu-submit-btn {
      background: linear-gradient(135deg, #1a2e5a 0%, #2563eb 100%);
      color: #fff;
      font-weight: 700;
      font-size: .95rem;
      padding: 14px 36px;
      border-radius: 50px;
      border: none;
      cursor: pointer;
      transition: opacity .25s, transform .2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
   }
   .pu-submit-btn:hover { opacity: .88; transform: translateY(-2px); }

   /* Social links */
   .pu-social-row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: center;
   }
   .pu-social-link {
      width: 46px; height: 46px;
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem;
      text-decoration: none;
      transition: transform .25s, opacity .25s;
   }
   .pu-social-link:hover { transform: translateY(-3px); opacity: .85; }
   .pu-social-link.fb   { background: #1877f2; color: #fff; }
   .pu-social-link.ig   { background: linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color: #fff; }
   .pu-social-link.li   { background: #0077b5; color: #fff; }
   .pu-social-link.yt   { background: #ff0000; color: #fff; }

   /* Quick details sidebar */
   .pu-detail-box {
      background: #f8fafc;
      border-radius: 16px;
      padding: 28px 24px;
      margin-bottom: 24px;
   }
   .pu-detail-box h6 {
      font-size: .78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #9ca3af;
      margin-bottom: 14px;
   }
   .pu-detail-row {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      margin-bottom: 12px;
   }
   .pu-detail-row:last-child { margin-bottom: 0; }
   .pu-detail-row .icon {
      width: 36px; height: 36px; flex-shrink: 0;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: .9rem;
   }
   .pu-detail-row .icon.blue  { background: #eef2ff; color: #2563eb; }
   .pu-detail-row .icon.green { background: #ecfdf5; color: #059669; }
   .pu-detail-row .icon.amber { background: #fffbeb; color: #d97706; }
   .pu-detail-row .text { font-size: .88rem; color: #374151; line-height: 1.6; }
   .pu-detail-row .text a { color: #2563eb; text-decoration: none; }
   .pu-detail-row .text a:hover { text-decoration: underline; }

   /* Alert overrides */
   .pu-alert-success {
      background: #ecfdf5; border: 1.5px solid #6ee7b7;
      border-radius: 12px; color: #065f46;
      padding: 16px 20px; font-size: .92rem;
   }
   .pu-alert-danger {
      background: #fff1f2; border: 1.5px solid #fda4af;
      border-radius: 12px; color: #be123c;
      padding: 16px 20px; font-size: .92rem;
   }

   /* Office hours */
   .pu-hours-table { width: 100%; font-size: .88rem; color: #374151; }
   .pu-hours-table td { padding: 6px 0; }
   .pu-hours-table td:last-child { text-align: right; color: #6b7280; }
   .pu-hours-table tr.today td { font-weight: 700; color: #2563eb; }

   /* WOW stagger helpers */
   .wow-stagger > * { opacity: 0; }
   </style>
</head>

<body id="body" class="it-magic-cursor">

   <!-- preloader -->
   <div id="preloader">
      <div class="preloader">
         <span></span>
         <span></span>
      </div>
   </div>
   <!-- preloader end -->

   <div id="magic-cursor">
      <div id="ball"></div>
   </div>

   <!-- back-to-top -->
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <!-- search popup -->
   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <!-- offcanvas -->
   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn">
            <button class="close-btn"><i class="fal fa-times"></i></button>
         </div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="assets/img/logo/logo-black.png" alt="Prime University">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801710996196">+880-1710996196</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Location</span>
                  <a href="https://maps.google.com/?q=Prime+University+Dhaka" target="_blank">114/116 Mazar Road, Mirpur-1, Dhaka 1216</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>
   <!-- offcanvas end -->

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <!-- ── HERO ────────────────────────────────────────────────────────────── -->
   <section class="pu-contact-hero">
      <div class="container position-relative" style="z-index:2;">
         <nav class="breadcrumb-nav mb-20">
            <a href="<?= fh(SITE_URL) ?>/index.php">Home</a>
            <span class="sep">/</span>
            <span>Contact Us</span>
         </nav>
         <h1 class="wow fadeInUp" data-wow-delay=".1s">We&rsquo;d Love to Hear<br>From You</h1>
         <p class="tagline wow fadeInUp" data-wow-delay=".2s">
            Reach out to our team for admissions enquiries, academic information,<br class="d-none d-lg-block">
            or any other questions about Prime University.
         </p>
      </div>
   </section>
   <!-- ── HERO END ─────────────────────────────────────────────────────────── -->

   <!-- ── INFO STRIP ───────────────────────────────────────────────────────── -->
   <section style="background:#f5f7fb; padding-bottom:80px;">
      <div class="container">
         <div class="pu-info-strip wow fadeInUp" data-wow-delay=".15s">
            <div class="row g-0">

               <!-- Address -->
               <div class="col-lg-3 col-sm-6">
                  <div class="pu-info-card">
                     <div class="pu-info-icon blue"><i class="fas fa-map-marker-alt"></i></div>
                     <h5>Our Address</h5>
                     <p>114/116 Mazar Road, Mirpur-1<br>Dhaka 1216, Bangladesh</p>
                     <a href="https://www.google.com/maps/place/Prime+University/@23.790664,90.34818,14z" target="_blank" rel="noopener noreferrer" style="color:#2563eb;font-size:.82rem;font-weight:600;margin-top:8px;">
                        <i class="fas fa-directions me-1"></i> Get Directions
                     </a>
                  </div>
               </div>

               <!-- Phone -->
               <div class="col-lg-3 col-sm-6">
                  <div class="pu-info-card">
                     <div class="pu-info-icon green"><i class="fas fa-phone-alt"></i></div>
                     <h5>Phone Numbers</h5>
                     <a href="tel:+88-02-41002432">PABX: +88-02-41002432</a>
                     <a href="tel:+88-02-41002435">+88-02-41002435</a>
                     <a href="tel:+8801710996196">01710996196</a>
                     <a href="tel:+8801939425030">01939425030</a>
                  </div>
               </div>

               <!-- Email -->
               <div class="col-lg-3 col-sm-6">
                  <div class="pu-info-card">
                     <div class="pu-info-icon amber"><i class="fas fa-envelope"></i></div>
                     <h5>Email Address</h5>
                     <a href="mailto:info@primeuniversity.edu.bd">info@primeuniversity.edu.bd</a>
                     <p style="margin-top:10px;font-size:.82rem;">We typically respond within<br>1–2 business days.</p>
                  </div>
               </div>

               <!-- Follow Us -->
               <div class="col-lg-3 col-sm-6">
                  <div class="pu-info-card">
                     <div class="pu-info-icon red"><i class="fas fa-share-alt"></i></div>
                     <h5>Follow Us</h5>
                     <div class="pu-social-row">
                        <a class="pu-social-link fb" href="https://www.facebook.com/Primevarsity" target="_blank" rel="noopener" title="Facebook">
                           <i class="fab fa-facebook-f"></i>
                        </a>
                        <a class="pu-social-link ig" href="https://www.instagram.com/primeuniversityedu/" target="_blank" rel="noopener" title="Instagram">
                           <i class="fab fa-instagram"></i>
                        </a>
                        <a class="pu-social-link li" href="https://www.linkedin.com/company/primeuniversity/" target="_blank" rel="noopener" title="LinkedIn">
                           <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a class="pu-social-link yt" href="https://www.youtube.com/@primeuniversity2002" target="_blank" rel="noopener" title="YouTube">
                           <i class="fab fa-youtube"></i>
                        </a>
                     </div>
                  </div>
               </div>

            </div>
         </div>
      </div>
   </section>
   <!-- ── INFO STRIP END ───────────────────────────────────────────────────── -->

   <!-- ── MAP + FORM ───────────────────────────────────────────────────────── -->
   <section style="background:#f5f7fb; padding-top:0; padding-bottom:100px;">
      <div class="container">
         <div class="row g-4 align-items-stretch">

            <!-- Left: Map + Quick Details -->
            <div class="col-lg-5 wow fadeInLeft" data-wow-delay=".1s">

               <!-- Google Map -->
               <div class="pu-map-wrap mb-4">
                  <iframe
                     src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3650.2195706014956!2d90.34560931498184!3d23.790663984524957!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3755b9cb8a002d35%3A0xf36071651f4f6585!2sPrime%20University!5e0!3m2!1sen!2sbd!4v1680000000000!5m2!1sen!2sbd"
                     allowfullscreen=""
                     loading="lazy"
                     referrerpolicy="no-referrer-when-downgrade"
                     title="Prime University Location on Google Maps">
                  </iframe>
               </div>

               <!-- Quick Details -->
               <div class="pu-detail-box">
                  <h6>Additional Contacts</h6>
                  <div class="pu-detail-row">
                     <div class="icon blue"><i class="fas fa-phone-alt"></i></div>
                     <div class="text">
                        <a href="tel:+8801687191986">01687191986</a><br>
                        <a href="tel:+8801866207160">01866207160</a>
                     </div>
                  </div>
                  <div class="pu-detail-row">
                     <div class="icon green"><i class="fas fa-clock"></i></div>
                     <div class="text">
                        <strong>Office Hours</strong><br>
                        Sun – Thu: 9:00 AM – 5:00 PM<br>
                        Friday &amp; Saturday: Closed
                     </div>
                  </div>
                  <div class="pu-detail-row">
                     <div class="icon amber"><i class="fas fa-map-pin"></i></div>
                     <div class="text">
                        <strong>Nearest Landmark</strong><br>
                        Mirpur-1 Bus Stand,&nbsp;Dhaka
                     </div>
                  </div>
               </div>

            </div>

            <!-- Right: Contact Form -->
            <div class="col-lg-7 wow fadeInRight" data-wow-delay=".15s">
               <div class="pu-form-card">
                  <div class="mb-4">
                     <span class="pu-section-tag">Send a Message</span>
                     <h2 class="pu-section-title">Let&rsquo;s Talk</h2>
                     <p style="color:#6b7280;font-size:.92rem;margin-top:8px;">
                        Fill out the form below and our team will get back to you as soon as possible.
                     </p>
                  </div>

                  <?php if ($form_success): ?>
                  <div class="pu-alert-success mb-4" role="alert">
                     <i class="fas fa-check-circle me-2"></i>
                     <strong>Thank you!</strong> Your message has been sent. We&rsquo;ll be in touch soon.
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($form_errors)): ?>
                  <div class="pu-alert-danger mb-4" role="alert">
                     <i class="fas fa-exclamation-circle me-2"></i>
                     <?php foreach ($form_errors as $err): ?>
                        <div><?= fh($err) ?></div>
                     <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <form method="POST" action="<?= fh(SITE_URL) ?>/contact.php#contact-form" id="contact-form" novalidate>
                     <input type="hidden" name="_csrf" value="<?= fh($csrf_token) ?>">

                     <div class="row g-3">
                        <div class="col-sm-6">
                           <label class="form-label" for="cf-name">Full Name <span style="color:#e11d48;">*</span></label>
                           <input type="text" id="cf-name" name="name" class="form-control"
                                  placeholder="e.g. John Doe"
                                  value="<?= fh($form_data['name']) ?>" required>
                        </div>
                        <div class="col-sm-6">
                           <label class="form-label" for="cf-email">Email Address <span style="color:#e11d48;">*</span></label>
                           <input type="email" id="cf-email" name="email" class="form-control"
                                  placeholder="e.g. john@example.com"
                                  value="<?= fh($form_data['email']) ?>" required>
                        </div>
                        <div class="col-sm-6">
                           <label class="form-label" for="cf-phone">Phone Number</label>
                           <input type="tel" id="cf-phone" name="phone" class="form-control"
                                  placeholder="e.g. 01XXXXXXXXX"
                                  value="<?= fh($form_data['phone']) ?>">
                        </div>
                        <div class="col-sm-6">
                           <label class="form-label" for="cf-subject">Subject <span style="color:#e11d48;">*</span></label>
                           <input type="text" id="cf-subject" name="subject" class="form-control"
                                  placeholder="e.g. Admission Enquiry"
                                  value="<?= fh($form_data['subject']) ?>" required>
                        </div>
                        <div class="col-12">
                           <label class="form-label" for="cf-message">Your Message <span style="color:#e11d48;">*</span></label>
                           <textarea id="cf-message" name="message" class="form-control"
                                     rows="5" placeholder="Write your message here…" required><?= fh($form_data['message']) ?></textarea>
                        </div>
                        <div class="col-12 mt-2">
                           <button type="submit" class="pu-submit-btn">
                              <i class="fas fa-paper-plane"></i> Send Message
                           </button>
                        </div>
                     </div>
                  </form>

               </div>
            </div>

         </div>
      </div>
   </section>
   <!-- ── MAP + FORM END ───────────────────────────────────────────────────── -->

<?php include __DIR__ . '/includes/footer.php'; ?>

   <!-- JS Libraries -->
   <script src="assets/js/jquery.js"></script>
   <script src="assets/js/bootstrap.bundle.min.js"></script>
   <script src="assets/js/purecounter.js"></script>
   <script src="assets/js/nice-select.js"></script>
   <script src="assets/js/swiper-bundle.min.js"></script>
   <script src="assets/js/slick.min.js"></script>
   <script src="assets/js/wow.js"></script>
   <script src="assets/js/magnific-popup.js"></script>
   <script src="assets/js/parallax.js"></script>
   <script src="assets/js/main.js"></script>

   <script>
   (function () {
      'use strict';

      /* ── WOW.js initialisation ─────────────────────────────────────── */
      if (typeof WOW !== 'undefined') {
         new WOW({ mobile: false, offset: 60 }).init();
      }

      /* ── Info-card hover highlight ──────────────────────────────────── */
      document.querySelectorAll('.pu-info-card').forEach(function (card) {
         card.addEventListener('mouseenter', function () {
            this.style.boxShadow = '0 8px 32px rgba(37,99,235,.12)';
         });
         card.addEventListener('mouseleave', function () {
            this.style.boxShadow = '';
         });
      });

      /* ── Form field validation feedback ────────────────────────────── */
      var form = document.getElementById('contact-form');
      if (form) {
         form.addEventListener('submit', function (e) {
            var valid = true;
            form.querySelectorAll('[required]').forEach(function (el) {
               if (!el.value.trim()) {
                  el.style.borderColor = '#e11d48';
                  valid = false;
               } else {
                  el.style.borderColor = '';
               }
            });
            if (!valid) {
               e.preventDefault();
               var firstErr = form.querySelector('[required]:placeholder-shown, [required][value=""]');
               if (firstErr) firstErr.focus();
            }
         });

         /* Live clear error highlight on input */
         form.querySelectorAll('[required]').forEach(function (el) {
            el.addEventListener('input', function () {
               if (this.value.trim()) this.style.borderColor = '';
            });
         });
      }

   }());
   </script>

</body>
</html>
