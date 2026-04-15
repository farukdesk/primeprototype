<?php
/**
 * Terms of Service – Prime University
 * URL: /terms-of-service.php
 */
require_once __DIR__ . '/includes/config.php';

$page_title     = 'Terms of Service';
$effective_date = 'January 1, 2025';
$last_updated   = 'April 15, 2025';
?>
<!doctype html>
<html class="no-js" lang="en">
<head>
   <meta charset="utf-8">
   <meta http-equiv="x-ua-compatible" content="ie=edge">
   <meta name="viewport" content="width=device-width, initial-scale=1">
   <title><?= fh($page_title) ?> – Prime University</title>
   <meta name="description" content="Read Prime University's Terms of Service governing use of our website, student portal, digital platforms, and other services.">
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
      /* ── Terms of Service Page ─────────────────────────────────── */
      .tos-hero {
         background: linear-gradient(135deg, #0c1a3a 0%, #1e3a5f 100%);
         padding: 80px 0 70px;
         position: relative;
         overflow: hidden;
      }
      .tos-hero::before {
         content: '';
         position: absolute; inset: 0;
         background: url('/assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .06;
      }
      .tos-badge {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         background: rgba(255,255,255,.12);
         border: 1px solid rgba(255,255,255,.25);
         color: #FFB81C;
         padding: 6px 18px;
         border-radius: 50px;
         font-size: .82rem;
         font-weight: 600;
         letter-spacing: .04em;
         margin-bottom: 18px;
      }
      .tos-meta-bar {
         background: #fff;
         border-bottom: 3px solid #D21034;
         padding: 13px 0;
         box-shadow: 0 2px 12px rgba(0,33,71,.06);
         position: sticky;
         top: 0;
         z-index: 50;
      }
      .tos-meta-item {
         display: flex;
         align-items: center;
         gap: 7px;
         font-size: .85rem;
         color: #475569;
      }
      .tos-meta-item i { color: #D21034; }
      /* Content */
      .tos-body {
         font-family: 'Inter', 'Segoe UI', sans-serif;
         font-size: 16px;
         line-height: 1.85;
         color: #1e293b;
      }
      .tos-body h2 {
         font-size: 1.3rem;
         font-weight: 700;
         color: #0c1a3a;
         border-left: 4px solid #D21034;
         padding-left: 14px;
         margin-top: 2.2rem;
         margin-bottom: .8rem;
      }
      .tos-body h3 {
         font-size: 1.1rem;
         font-weight: 600;
         color: #1e3a5f;
         margin-top: 1.5rem;
         margin-bottom: .5rem;
      }
      .tos-body p  { margin-bottom: 1rem; }
      .tos-body ul, .tos-body ol { padding-left: 1.6rem; margin-bottom: 1rem; }
      .tos-body li { margin-bottom: .35rem; }
      .tos-body a  { color: #2d63e8; text-decoration: underline; }
      .tos-body table {
         width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: .9rem;
      }
      .tos-body table th {
         background: #0c1a3a; color: #fff; padding: 10px 14px; text-align: left; font-size: .85rem;
      }
      .tos-body table td { padding: 9px 14px; border-bottom: 1px solid #e2e8f0; }
      .tos-body table tr:nth-child(even) td { background: #f8fafc; }
      .tos-highlight-box {
         background: #eff6ff;
         border-left: 4px solid #2563eb;
         border-radius: 0 8px 8px 0;
         padding: 14px 18px;
         margin-bottom: 1rem;
         font-size: .93rem;
      }
      .tos-warn-box {
         background: #fff7ed;
         border-left: 4px solid #f59e0b;
         border-radius: 0 8px 8px 0;
         padding: 14px 18px;
         margin-bottom: 1rem;
         font-size: .93rem;
      }
      /* Sidebar */
      .tos-sidebar-card {
         border: none;
         border-radius: 14px;
         box-shadow: 0 2px 16px rgba(0,33,71,.07);
         margin-bottom: 20px;
      }
      .tos-toc-list { list-style: none; padding: 0; margin: 0; }
      .tos-toc-list li { border-bottom: 1px dotted #e2e8f0; }
      .tos-toc-list li:last-child { border: none; }
      .tos-toc-list a {
         display: block;
         padding: 7px 0;
         color: #1e3a5f;
         font-size: .875rem;
         text-decoration: none;
      }
      .tos-toc-list a:hover { color: #D21034; }
   </style>
<?php include __DIR__ . '/includes/meta-pixel.php'; ?>
</head>
<body id="body" class="it-magic-cursor">

   <div id="preloader">
      <div class="preloader"><span></span><span></span></div>
   </div>
   <div id="magic-cursor"><div id="ball"></div></div>
   <button class="scroll-top scroll-to-target" data-target="html">
      <i class="far fa-angle-double-up"></i>
   </button>

   <div class="search-popup">
      <button class="close-search"><span class="flaticon-multiply"><i class="fal fa-times"></i></span></button>
      <form method="post" action="#">
         <div class="form-group">
            <input type="search" name="search-field" value="" placeholder="Search Here" required="">
            <button type="submit"><i class="fal fa-search"></i></button>
         </div>
      </form>
   </div>

   <div class="it-offcanvas-area">
      <div class="itoffcanvas">
         <div class="itoffcanvas__close-btn">
            <button class="close-btn"><i class="fal fa-times"></i></button>
         </div>
         <div class="itoffcanvas__logo">
            <a href="<?= fh(SITE_URL) ?>/index.php">
               <img src="/assets/img/logo/logo-black.png" alt="">
            </a>
         </div>
         <div class="it-menu-mobile d-xl-none"></div>
         <div class="itoffcanvas__info">
            <h3 class="offcanva-title">Get In Touch</h3>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-envelope"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Email</span>
                  <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a>
               </div>
            </div>
            <div class="it-info-wrapper mb-20 d-flex align-items-center">
               <div class="itoffcanvas__info-icon"><a href="#"><i class="fal fa-phone-alt"></i></a></div>
               <div class="itoffcanvas__info-address">
                  <span>Phone</span>
                  <a href="tel:+8801969955566">01969-955566</a>
               </div>
            </div>
         </div>
      </div>
   </div>
   <div class="body-overlay"></div>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <!-- Hero -->
   <section class="tos-hero">
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-8">
               <div class="tos-badge">
                  <i class="fas fa-file-contract"></i> Legal
               </div>
               <h1 style="color:#fff;font-size:clamp(26px,4vw,46px);font-weight:700;margin-bottom:14px;">
                  Terms of Service
               </h1>
               <p style="color:rgba(255,255,255,.8);font-size:17px;line-height:1.8;">
                  These terms govern your use of Prime University's website, student portal, digital platforms,
                  and related services. Please read them carefully before using our services.
               </p>
               <nav aria-label="breadcrumb" class="mt-3">
                  <ol class="breadcrumb justify-content-center mb-0" style="background:none;">
                     <li class="breadcrumb-item">
                        <a href="<?= fh(SITE_URL) ?>/index.php" style="color:rgba(255,255,255,.65);">Home</a>
                     </li>
                     <li class="breadcrumb-item active" style="color:#FFB81C;">Terms of Service</li>
                  </ol>
               </nav>
            </div>
         </div>
      </div>
   </section>

   <!-- Meta bar -->
   <div class="tos-meta-bar">
      <div class="container">
         <div class="d-flex flex-wrap gap-4 align-items-center">
            <div class="tos-meta-item">
               <i class="fas fa-calendar-check"></i>
               <strong>Effective:</strong>&nbsp;<?= fh($effective_date) ?>
            </div>
            <div class="tos-meta-item">
               <i class="fas fa-clock"></i>
               <strong>Last Updated:</strong>&nbsp;<?= fh($last_updated) ?>
            </div>
            <div class="tos-meta-item ms-auto">
               <i class="fas fa-globe"></i>
               <strong>Jurisdiction:</strong>&nbsp;Bangladesh
            </div>
         </div>
      </div>
   </div>

   <!-- Main Content -->
   <section class="pt-70 pb-100" style="background:#f8fafc;">
      <div class="container">
         <div class="row g-4">

            <!-- ToS Body -->
            <div class="col-lg-8">
               <div class="card" style="border:none;border-radius:14px;box-shadow:0 2px 16px rgba(0,33,71,.07);">
                  <div class="card-body p-4 p-lg-5">
                     <div class="tos-body">

                        <!-- 1. Acceptance -->
                        <h2 id="acceptance">1. Acceptance of Terms</h2>
                        <p>
                           By accessing or using any website, portal, application, or digital service operated
                           by <strong>Prime University</strong> ("we", "us", "our"), you agree to be bound by
                           these Terms of Service ("Terms") and our <a href="/privacy-policy.php">Privacy Policy</a>.
                           If you do not agree to these Terms, please discontinue use immediately.
                        </p>
                        <div class="tos-highlight-box">
                           <i class="fas fa-info-circle me-2 text-primary"></i>
                           These Terms apply to all visitors, students, staff, alumni, and any other users of
                           Prime University's digital platforms.
                        </div>

                        <!-- 2. Services -->
                        <h2 id="services">2. Description of Services</h2>
                        <p>Prime University provides the following digital services (collectively, the "Services"):</p>
                        <ul>
                           <li>Public website at <a href="https://primeuniversity.ac.bd">primeuniversity.ac.bd</a></li>
                           <li>Online admissions portal and application system</li>
                           <li>Student information and academic management system</li>
                           <li>Faculty and staff portal</li>
                           <li>Notice board, exam results, and digital library access</li>
                           <li>Online fee payment and financial services</li>
                           <li>Support ticket and helpdesk system</li>
                           <li>Any other platforms or applications we may offer from time to time</li>
                        </ul>
                        <p>
                           We reserve the right to modify, suspend, or discontinue any Service at any time
                           with or without notice.
                        </p>

                        <!-- 3. Eligibility -->
                        <h2 id="eligibility">3. Eligibility</h2>
                        <p>
                           To use our Services you must be at least 13 years of age. By using the Services you
                           represent that you meet this requirement. Users under 18 must have parental or guardian
                           consent where required by applicable law.
                        </p>
                        <p>
                           Student and staff portal accounts are issued exclusively to currently enrolled students
                           and active employees of Prime University. Account credentials are personal and
                           non-transferable.
                        </p>

                        <!-- 4. Accounts -->
                        <h2 id="accounts">4. User Accounts &amp; Security</h2>
                        <h3>4.1 Account Registration</h3>
                        <p>
                           Portal accounts are created by the university upon enrolment or employment.
                           You are responsible for keeping your credentials confidential and for all activities
                           that occur under your account.
                        </p>
                        <h3>4.2 Password Security</h3>
                        <p>
                           You must notify us immediately at
                           <a href="mailto:itsupport@primeuniversity.ac.bd">itsupport@primeuniversity.ac.bd</a>
                           if you suspect any unauthorised use of your account or security breach.
                        </p>
                        <h3>4.3 Account Termination</h3>
                        <p>
                           Accounts may be suspended or terminated if you violate these Terms,
                           upon graduation or end of employment, or at the university's discretion
                           for operational reasons.
                        </p>

                        <!-- 5. Acceptable Use -->
                        <h2 id="acceptable-use">5. Acceptable Use Policy</h2>
                        <p>You agree <strong>not</strong> to:</p>
                        <ul>
                           <li>Use the Services for any unlawful purpose or in violation of any applicable law or regulation in Bangladesh or internationally.</li>
                           <li>Attempt to gain unauthorised access to any part of our systems, servers, or databases.</li>
                           <li>Upload, transmit, or distribute malware, viruses, or any malicious code.</li>
                           <li>Engage in any form of harassment, hate speech, or abusive conduct towards other users.</li>
                           <li>Impersonate any person, student, staff member, or university official.</li>
                           <li>Share your login credentials or allow others to access your account.</li>
                           <li>Scrape, copy, or republish any content from the Services without written permission.</li>
                           <li>Use automated bots or scripts to interact with the Services without prior consent.</li>
                           <li>Submit false, misleading, or fraudulent information in any form or application.</li>
                           <li>Engage in academic dishonesty, plagiarism, or cheating using our digital platforms.</li>
                        </ul>
                        <div class="tos-warn-box">
                           <i class="fas fa-exclamation-triangle me-2" style="color:#f59e0b;"></i>
                           Violations may result in immediate account suspension, disciplinary proceedings under
                           the university's Code of Conduct, and/or referral to law-enforcement authorities.
                        </div>

                        <!-- 6. Intellectual Property -->
                        <h2 id="ip">6. Intellectual Property</h2>
                        <p>
                           All content on our Services — including text, images, logos, graphics, videos, software,
                           databases, and course materials — is the property of Prime University or its licensors
                           and is protected by applicable copyright, trademark, and intellectual property laws.
                        </p>
                        <p>
                           You may not reproduce, redistribute, republish, or create derivative works from any
                           university content without prior written permission, except for personal, non-commercial
                           educational use.
                        </p>
                        <p>
                           Student submissions (assignments, theses, research papers) remain the intellectual
                           property of the student unless otherwise agreed in writing, but the university retains
                           a non-exclusive licence to use submitted work for educational and institutional purposes.
                        </p>

                        <!-- 7. Fees & Payments -->
                        <h2 id="fees">7. Fees &amp; Payments</h2>
                        <p>
                           Tuition fees, admission fees, and other charges are set by Prime University and are
                           subject to change each academic session. All fees are quoted in Bangladeshi Taka (BDT).
                        </p>
                        <ul>
                           <li>Fees must be paid by the deadlines published each semester.</li>
                           <li>Late payments may incur penalties as notified by the Accounts Office.</li>
                           <li>Fee refunds are governed by the university's Refund Policy, available from the Accounts Office.</li>
                           <li>Online payment transactions are processed through third-party payment gateways; the university is not liable for failures or errors caused by those gateways.</li>
                        </ul>

                        <!-- 8. Third-Party Links -->
                        <h2 id="third-party">8. Third-Party Links &amp; Services</h2>
                        <p>
                           Our Services may contain links to third-party websites or integrate third-party services
                           (e.g., Google Maps, YouTube, payment processors). We do not control and are not
                           responsible for the content, privacy practices, or availability of those third-party
                           services. Accessing them is at your own risk.
                        </p>

                        <!-- 9. Disclaimers -->
                        <h2 id="disclaimers">9. Disclaimers</h2>
                        <p>
                           The Services are provided on an "<strong>as is</strong>" and "<strong>as available</strong>" basis
                           without warranties of any kind, express or implied, including but not limited to
                           merchantability, fitness for a particular purpose, or non-infringement.
                        </p>
                        <p>
                           We do not warrant that:
                        </p>
                        <ul>
                           <li>The Services will be uninterrupted, error-free, or completely secure.</li>
                           <li>Information on the website is always current, accurate, or complete.</li>
                           <li>Any defects or errors will be corrected promptly.</li>
                        </ul>
                        <p>
                           Academic information (results, schedules, notices) published on the portal
                           is provided for convenience. Official academic records are maintained by the
                           Registrar's Office.
                        </p>

                        <!-- 10. Limitation of Liability -->
                        <h2 id="liability">10. Limitation of Liability</h2>
                        <p>
                           To the maximum extent permitted by applicable law, Prime University and its officers,
                           faculty, staff, and agents shall not be liable for any indirect, incidental, special,
                           consequential, or punitive damages arising out of or related to your use of or inability
                           to use the Services, even if we have been advised of the possibility of such damages.
                        </p>
                        <p>
                           Our total liability to you for any claim arising from use of the Services shall not
                           exceed the amount of fees paid by you to the university in the immediately preceding
                           semester.
                        </p>

                        <!-- 11. Indemnification -->
                        <h2 id="indemnification">11. Indemnification</h2>
                        <p>
                           You agree to indemnify, defend, and hold harmless Prime University and its affiliates,
                           officers, employees, and agents from and against any claims, liabilities, damages,
                           losses, and expenses (including reasonable legal fees) arising from your use of the
                           Services, violation of these Terms, or infringement of any third-party rights.
                        </p>

                        <!-- 12. Privacy -->
                        <h2 id="privacy">12. Privacy</h2>
                        <p>
                           Your use of the Services is also governed by our
                           <a href="/privacy-policy.php">Privacy Policy</a>, which is incorporated into these Terms
                           by reference. You also have the right to submit a
                           <a href="/user-data-deletion.php">data deletion request</a> at any time.
                        </p>

                        <!-- 13. Governing Law -->
                        <h2 id="governing-law">13. Governing Law &amp; Dispute Resolution</h2>
                        <p>
                           These Terms are governed by and construed in accordance with the laws of the
                           <strong>People's Republic of Bangladesh</strong>. Any dispute arising from or
                           related to these Terms shall be subject to the exclusive jurisdiction of the
                           competent courts located in Dhaka, Bangladesh.
                        </p>
                        <p>
                           We encourage parties to resolve disputes amicably in the first instance.
                           Formal complaints may be submitted to the university's Registrar's Office before
                           initiating legal proceedings.
                        </p>

                        <!-- 14. Changes -->
                        <h2 id="changes">14. Changes to These Terms</h2>
                        <p>
                           We reserve the right to update or modify these Terms at any time. The revised
                           Terms will be posted on this page with an updated "Last Updated" date.
                           Significant changes will be communicated via email or a notice on our website.
                           Continued use of the Services after changes take effect constitutes your acceptance
                           of the revised Terms.
                        </p>

                        <!-- 15. Contact -->
                        <h2 id="contact">15. Contact Us</h2>
                        <p>For questions or concerns regarding these Terms, please contact:</p>
                        <div class="tos-highlight-box">
                           <strong>Registrar's Office – Prime University</strong><br>
                           114/116 Mazar Road, Mirpur, Dhaka-1216, Bangladesh<br>
                           Email: <a href="mailto:info@primeuniversity.ac.bd">info@primeuniversity.ac.bd</a><br>
                           Phone: +880 1969-955566
                        </div>

                     </div><!-- /.tos-body -->
                  </div>
               </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">

               <!-- TOC -->
               <div class="card tos-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#0c1a3a;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-list me-2 text-muted"></i>Table of Contents
                     </h6>
                     <ul class="tos-toc-list">
                        <li><a href="#acceptance"><span class="text-muted me-1">1.</span> Acceptance of Terms</a></li>
                        <li><a href="#services"><span class="text-muted me-1">2.</span> Description of Services</a></li>
                        <li><a href="#eligibility"><span class="text-muted me-1">3.</span> Eligibility</a></li>
                        <li><a href="#accounts"><span class="text-muted me-1">4.</span> User Accounts &amp; Security</a></li>
                        <li><a href="#acceptable-use"><span class="text-muted me-1">5.</span> Acceptable Use</a></li>
                        <li><a href="#ip"><span class="text-muted me-1">6.</span> Intellectual Property</a></li>
                        <li><a href="#fees"><span class="text-muted me-1">7.</span> Fees &amp; Payments</a></li>
                        <li><a href="#third-party"><span class="text-muted me-1">8.</span> Third-Party Links</a></li>
                        <li><a href="#disclaimers"><span class="text-muted me-1">9.</span> Disclaimers</a></li>
                        <li><a href="#liability"><span class="text-muted me-1">10.</span> Limitation of Liability</a></li>
                        <li><a href="#indemnification"><span class="text-muted me-1">11.</span> Indemnification</a></li>
                        <li><a href="#privacy"><span class="text-muted me-1">12.</span> Privacy</a></li>
                        <li><a href="#governing-law"><span class="text-muted me-1">13.</span> Governing Law</a></li>
                        <li><a href="#changes"><span class="text-muted me-1">14.</span> Changes</a></li>
                        <li><a href="#contact"><span class="text-muted me-1">15.</span> Contact</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Document Info -->
               <div class="card tos-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#0c1a3a;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-info-circle me-2 text-muted"></i>Document Info
                     </h6>
                     <ul class="list-unstyled mb-0" style="font-size:.875rem;">
                        <li class="d-flex gap-2 mb-2">
                           <i class="fas fa-calendar-check text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Effective Date</span><br>
                           <strong style="color:#1e293b;"><?= fh($effective_date) ?></strong></div>
                        </li>
                        <li class="d-flex gap-2 mb-2">
                           <i class="fas fa-sync-alt text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Last Updated</span><br>
                           <strong style="color:#1e293b;"><?= fh($last_updated) ?></strong></div>
                        </li>
                        <li class="d-flex gap-2">
                           <i class="fas fa-globe text-muted mt-1" style="width:16px;"></i>
                           <div><span style="color:#64748b;">Jurisdiction</span><br>
                           <strong style="color:#1e293b;">Bangladesh</strong></div>
                        </li>
                     </ul>
                  </div>
               </div>

               <!-- Related Pages -->
               <div class="card tos-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#0c1a3a;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-link me-2 text-muted"></i>Related Pages
                     </h6>
                     <ul class="tos-toc-list">
                        <li><a href="/privacy-policy.php"><i class="fas fa-shield-alt me-2 text-muted"></i>Privacy Policy</a></li>
                        <li><a href="/user-data-deletion.php"><i class="fas fa-trash-alt me-2 text-danger"></i>Data Deletion Request</a></li>
                        <li><a href="/contact.php"><i class="fas fa-envelope me-2 text-muted"></i>Contact Us</a></li>
                        <li><a href="/index.php"><i class="fas fa-home me-2 text-muted"></i>Home</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Print -->
               <div class="card tos-sidebar-card">
                  <div class="card-body p-4">
                     <button onclick="window.print()" class="btn btn-sm btn-outline-secondary w-100" style="border-radius:8px;">
                        <i class="fas fa-print me-1"></i>Print These Terms
                     </button>
                  </div>
               </div>

            </div>
         </div><!-- /.row -->
      </div>
   </section>

   </main>

<?php include __DIR__ . '/includes/footer.php'; ?>

   <?php include __DIR__ . '/includes/scripts.php'; ?>
</body>
</html>
