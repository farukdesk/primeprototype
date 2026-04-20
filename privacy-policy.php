<?php
/**
 * Privacy Policy – Prime University
 * Covers: GDPR, UK Data Protection Act 2018, Bangladesh Digital Security Act 2018
 * and general personal-data practices.
 */
require_once __DIR__ . '/includes/config.php';

$page_title    = 'Privacy Policy';
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
   <meta name="description" content="Read Prime University's Privacy Policy covering how we collect, use and protect your personal data in compliance with GDPR, the Data Protection Act 2018, and Bangladesh data-protection law.">
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
      /* ── Privacy Policy Page ───────────────────────────────────── */
      .pp-hero {
         background: linear-gradient(135deg, #002147 0%, #1a3d6e 100%);
         padding: 80px 0 70px;
         position: relative;
         overflow: hidden;
      }
      .pp-hero::before {
         content: '';
         position: absolute; inset: 0;
         background: url('/assets/img/shape/footer-bg-3-1.jpg') center/cover no-repeat;
         opacity: .06;
      }
      .pp-badge {
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
      .pp-meta-bar {
         background: #fff;
         border-bottom: 3px solid #D21034;
         padding: 13px 0;
         box-shadow: 0 2px 12px rgba(0,33,71,.06);
         position: sticky;
         top: 0;
         z-index: 50;
      }
      .pp-meta-item {
         display: flex;
         align-items: center;
         gap: 7px;
         font-size: .85rem;
         color: #475569;
      }
      .pp-meta-item i { color: #D21034; }
      /* Content typography */
      .pp-body {
         font-family: 'Inter', 'Segoe UI', sans-serif;
         font-size: 16px;
         line-height: 1.85;
         color: #1e293b;
      }
      .pp-body h2 {
         font-size: 1.3rem;
         font-weight: 700;
         color: #002147;
         border-left: 4px solid #D21034;
         padding-left: 14px;
         margin-top: 2.2rem;
         margin-bottom: .8rem;
      }
      .pp-body h3 {
         font-size: 1.1rem;
         font-weight: 600;
         color: #1a3d6e;
         margin-top: 1.5rem;
         margin-bottom: .5rem;
      }
      .pp-body p { margin-bottom: 1rem; }
      .pp-body ul, .pp-body ol { padding-left: 1.6rem; margin-bottom: 1rem; }
      .pp-body li { margin-bottom: .35rem; }
      .pp-body a { color: #2d63e8; text-decoration: underline; }
      .pp-body table {
         width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: .9rem;
      }
      .pp-body table th {
         background: #002147; color: #fff; padding: 10px 14px; text-align: left; font-size: .85rem;
      }
      .pp-body table td { padding: 9px 14px; border-bottom: 1px solid #e2e8f0; }
      .pp-body table tr:nth-child(even) td { background: #f8fafc; }
      .pp-highlight-box {
         background: #eff6ff;
         border-left: 4px solid #2563eb;
         border-radius: 0 8px 8px 0;
         padding: 14px 18px;
         margin-bottom: 1rem;
         font-size: .93rem;
      }
      .pp-gdpr-tag {
         display: inline-block;
         background: #2563eb;
         color: #fff;
         font-size: .72rem;
         font-weight: 700;
         padding: 2px 8px;
         border-radius: 4px;
         margin-left: 8px;
         vertical-align: middle;
         letter-spacing: .03em;
      }
      .pp-bd-tag {
         display: inline-block;
         background: #006a4e;
         color: #fff;
         font-size: .72rem;
         font-weight: 700;
         padding: 2px 8px;
         border-radius: 4px;
         margin-left: 8px;
         vertical-align: middle;
         letter-spacing: .03em;
      }
      .pp-dpa-tag {
         display: inline-block;
         background: #7c3aed;
         color: #fff;
         font-size: .72rem;
         font-weight: 700;
         padding: 2px 8px;
         border-radius: 4px;
         margin-left: 8px;
         vertical-align: middle;
         letter-spacing: .03em;
      }
      /* Sidebar */
      .pp-sidebar-card {
         border: none;
         border-radius: 14px;
         box-shadow: 0 2px 16px rgba(0,33,71,.07);
         margin-bottom: 20px;
      }
      .pp-toc-list { list-style: none; padding: 0; margin: 0; }
      .pp-toc-list li { border-bottom: 1px dotted #e2e8f0; }
      .pp-toc-list li:last-child { border: none; }
      .pp-toc-list a {
         display: block;
         padding: 7px 0;
         color: #1a3d6e;
         font-size: .875rem;
         text-decoration: none;
      }
      .pp-toc-list a:hover { color: #D21034; }
      .pp-law-badge {
         display: inline-flex; align-items: center; gap: 6px;
         border-radius: 8px; padding: 8px 14px; font-size: .82rem; font-weight: 600;
         margin-bottom: 8px; width: 100%;
      }
      .pp-law-badge.gdpr  { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
      .pp-law-badge.dpa   { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
      .pp-law-badge.bd    { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
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
<?php include __DIR__ . '/includes/offcanvas.php'; ?>

   <header class="it-header-height">
      <?php include __DIR__ . '/includes/header-top.php'; ?>
      <?php include __DIR__ . '/includes/nav-menu.php'; ?>
   </header>

   <main>

   <!-- Hero -->
   <section class="pp-hero">
      <div class="container" style="position:relative;">
         <div class="row justify-content-center text-center">
            <div class="col-lg-8">
               <div class="pp-badge">
                  <i class="fas fa-shield-alt"></i> Legal &amp; Compliance
               </div>
               <h1 style="color:#fff;font-size:clamp(26px,4vw,46px);font-weight:700;margin-bottom:14px;">
                  Privacy Policy
               </h1>
               <p style="color:rgba(255,255,255,.8);font-size:17px;line-height:1.8;">
                  We are committed to protecting your personal data. This Policy explains what
                  we collect, how we use it, and your rights under applicable laws.
               </p>
               <nav aria-label="breadcrumb" class="mt-3">
                  <ol class="breadcrumb justify-content-center mb-0" style="background:none;">
                     <li class="breadcrumb-item">
                        <a href="<?= fh(SITE_URL) ?>/index.php" style="color:rgba(255,255,255,.65);">Home</a>
                     </li>
                     <li class="breadcrumb-item active" style="color:#FFB81C;">Privacy Policy</li>
                  </ol>
               </nav>
            </div>
         </div>
      </div>
   </section>

   <!-- Meta bar -->
   <div class="pp-meta-bar">
      <div class="container">
         <div class="d-flex flex-wrap gap-4 align-items-center">
            <div class="pp-meta-item">
               <i class="fas fa-calendar-check"></i>
               <strong>Effective:</strong>&nbsp;<?= fh($effective_date) ?>
            </div>
            <div class="pp-meta-item">
               <i class="fas fa-clock"></i>
               <strong>Last Updated:</strong>&nbsp;<?= fh($last_updated) ?>
            </div>
            <div class="pp-meta-item ms-auto">
               <i class="fas fa-globe"></i>
               <strong>Applies to:</strong>&nbsp;All users &amp; visitors
            </div>
         </div>
      </div>
   </div>

   <!-- Main Content -->
   <section class="pt-70 pb-100" style="background:#f8fafc;">
      <div class="container">
         <div class="row g-4">

            <!-- Policy Body -->
            <div class="col-lg-8">
               <div class="card" style="border:none;border-radius:14px;box-shadow:0 2px 16px rgba(0,33,71,.07);">
                  <div class="card-body p-4 p-lg-5">
                     <div class="pp-body">

                        <!-- 1. Introduction -->
                        <h2 id="introduction">1. Introduction</h2>
                        <p>
                           Prime University ("<strong>we</strong>", "<strong>us</strong>", "<strong>our</strong>") respects your privacy
                           and is committed to protecting the personal data you share with us.
                           This Privacy Policy applies to our website, digital services, admissions portal, student
                           information system, and any other platform operated by Prime University.
                        </p>
                        <p>
                           This Policy is drafted in compliance with:
                        </p>
                        <ul>
                           <li>The <strong>EU General Data Protection Regulation (GDPR)</strong> (Regulation (EU) 2016/679)
                              <span class="pp-gdpr-tag">GDPR</span></li>
                           <li>The <strong>UK Data Protection Act 2018</strong> and UK GDPR
                              <span class="pp-dpa-tag">DPA 2018</span></li>
                           <li>The <strong>Bangladesh Digital Security Act 2018</strong> (Act No. 46 of 2018)
                              <span class="pp-bd-tag">BD DSA</span></li>
                           <li>General principles of personal-data protection applicable in Bangladesh</li>
                        </ul>
                        <div class="pp-highlight-box">
                           <i class="fas fa-info-circle me-2 text-primary"></i>
                           By using our services, you acknowledge that you have read and understood this Privacy Policy.
                           If you do not agree, please discontinue use of our platforms.
                        </div>

                        <!-- 2. Data Controller -->
                        <h2 id="controller">2. Data Controller</h2>
                        <p>The data controller responsible for your personal data is:</p>
                        <table>
                           <tr>
                              <th>Detail</th><th>Information</th>
                           </tr>
                           <tr><td>Organisation</td><td>Prime University</td></tr>
                           <tr><td>Address</td><td>114/116, Mazar Road, Mirpur, Dhaka-1216, Bangladesh</td></tr>
                           <tr><td>Email</td><td><a href="mailto:privacy@primeuniversity.ac.bd">privacy@primeuniversity.ac.bd</a></td></tr>
                           <tr><td>Phone</td><td>+880 1969-955566</td></tr>
                           <tr><td>Website</td><td><a href="https://primeuniversity.ac.bd">primeuniversity.ac.bd</a></td></tr>
                        </table>

                        <!-- 3. What We Collect -->
                        <h2 id="what-we-collect">3. What Personal Data We Collect</h2>
                        <p>We may collect the following categories of personal data depending on how you interact with us:</p>

                        <h3>3.1 Identity &amp; Contact Data</h3>
                        <ul>
                           <li>Full name, date of birth, gender, nationality</li>
                           <li>Email address, phone number, postal address</li>
                           <li>National Identity Card (NID) number or passport number (for admissions and staff records)</li>
                        </ul>

                        <h3>3.2 Academic &amp; Professional Data</h3>
                        <ul>
                           <li>Academic qualifications, transcripts, certificates</li>
                           <li>Examination results, grades, attendance records</li>
                           <li>Employment history (for faculty and staff)</li>
                        </ul>

                        <h3>3.3 Financial Data</h3>
                        <ul>
                           <li>Fee payment records and receipts</li>
                           <li>Bank account or mobile banking details (for scholarship disbursements)</li>
                        </ul>

                        <h3>3.4 Technical &amp; Usage Data</h3>
                        <ul>
                           <li>IP address, browser type, device identifiers</li>
                           <li>Pages visited, time spent, referral URLs (via cookies and server logs)</li>
                           <li>Login timestamps and session data</li>
                        </ul>

                        <h3>3.5 Communications Data</h3>
                        <ul>
                           <li>Messages submitted via contact forms or support tickets</li>
                           <li>Correspondence with university departments</li>
                        </ul>

                        <h3>3.6 Sensitive / Special-Category Data <span class="pp-gdpr-tag">GDPR Art. 9</span></h3>
                        <p>
                           We only process sensitive data (such as health information or disability status) where
                           strictly necessary (e.g., to provide reasonable adjustments) and with your explicit
                           consent or as permitted by applicable law.
                        </p>

                        <!-- 4. How We Collect -->
                        <h2 id="how-we-collect">4. How We Collect Your Data</h2>
                        <ul>
                           <li><strong>Directly from you</strong> – admission applications, registration forms, contact requests, support tickets</li>
                           <li><strong>Automatically</strong> – via cookies, web beacons, and server logs when you visit our website</li>
                           <li><strong>From third parties</strong> – previous educational institutions, background-check providers, government agencies (where legally permitted)</li>
                        </ul>

                        <!-- 5. Legal Basis -->
                        <h2 id="legal-basis">5. Legal Basis for Processing <span class="pp-gdpr-tag">GDPR Art. 6</span></h2>
                        <p>We process your personal data on the following lawful bases:</p>
                        <table>
                           <tr>
                              <th>Basis</th><th>Example Use</th>
                           </tr>
                           <tr>
                              <td><strong>Contract</strong></td>
                              <td>Enrolling you as a student; processing tuition fees</td>
                           </tr>
                           <tr>
                              <td><strong>Legal Obligation</strong></td>
                              <td>Submitting academic records to regulatory bodies (UGC)</td>
                           </tr>
                           <tr>
                              <td><strong>Legitimate Interests</strong></td>
                              <td>Improving our website; preventing fraud; IT security</td>
                           </tr>
                           <tr>
                              <td><strong>Consent</strong></td>
                              <td>Marketing communications; non-essential cookies</td>
                           </tr>
                           <tr>
                              <td><strong>Vital Interests</strong></td>
                              <td>Emergency medical situations on campus</td>
                           </tr>
                        </table>

                        <!-- 6. How We Use Your Data -->
                        <h2 id="how-we-use">6. How We Use Your Personal Data</h2>
                        <ul>
                           <li>Processing applications for admission and enrolment</li>
                           <li>Providing academic, administrative, and student-support services</li>
                           <li>Communicating important university notices and announcements</li>
                           <li>Issuing certificates, transcripts, and ID cards</li>
                           <li>Administering scholarships, fee waivers, and financial assistance</li>
                           <li>Complying with Bangladesh University Grants Commission (UGC) reporting requirements</li>
                           <li>Maintaining campus security and access control</li>
                           <li>Improving our digital platforms and user experience</li>
                           <li>Sending marketing or promotional communications (only with your consent)</li>
                        </ul>

                        <!-- 7. Cookies -->
                        <h2 id="cookies">7. Cookies &amp; Tracking Technologies</h2>
                        <p>
                           Our website uses cookies to enhance your browsing experience.
                           Types of cookies we may use:
                        </p>
                        <table>
                           <tr>
                              <th>Type</th><th>Purpose</th>
                           </tr>
                           <tr>
                              <td><strong>Strictly Necessary</strong></td>
                              <td>Session management, security (CSRF tokens), login state</td>
                           </tr>
                           <tr>
                              <td><strong>Functional</strong></td>
                              <td>Remembering your language/region preferences</td>
                           </tr>
                           <tr>
                              <td><strong>Analytical</strong></td>
                              <td>Aggregated page-view statistics to improve content</td>
                           </tr>
                           <tr>
                              <td><strong>Marketing</strong></td>
                              <td>Retargeting via Facebook Pixel (only with consent)</td>
                           </tr>
                        </table>
                        <p>
                           You may disable non-essential cookies in your browser settings at any time.
                           Disabling strictly necessary cookies may affect your ability to use certain features.
                        </p>

                        <!-- 8. Data Sharing -->
                        <h2 id="data-sharing">8. Sharing Your Personal Data</h2>
                        <p>We do <strong>not</strong> sell your personal data. We may share data with:</p>
                        <ul>
                           <li><strong>Regulatory &amp; governmental authorities</strong> – Bangladesh UGC, Ministry of Education, law-enforcement agencies (when legally required)</li>
                           <li><strong>Service providers</strong> – IT infrastructure, email delivery, payment gateways (bound by data-processing agreements)</li>
                           <li><strong>Accreditation bodies</strong> – for institutional audits and quality assurance</li>
                           <li><strong>Emergency services</strong> – where necessary to protect vital interests</li>
                        </ul>
                        <p>
                           Any third party that processes data on our behalf is required to handle it securely
                           and only for the purposes we specify.
                        </p>

                        <!-- 9. International Transfers -->
                        <h2 id="international">9. International Data Transfers <span class="pp-gdpr-tag">GDPR Ch. V</span></h2>
                        <p>
                           If personal data is transferred outside Bangladesh or the European Economic Area (EEA),
                           we ensure adequate safeguards are in place, such as Standard Contractual Clauses (SCCs)
                           or equivalent mechanisms, in compliance with GDPR Chapter V and applicable Bangladeshi regulations.
                        </p>

                        <!-- 10. Data Retention -->
                        <h2 id="retention">10. Data Retention</h2>
                        <p>We retain personal data only for as long as necessary:</p>
                        <table>
                           <tr>
                              <th>Data Category</th><th>Retention Period</th>
                           </tr>
                           <tr>
                              <td>Student academic records</td>
                              <td>Permanent (required by UGC)</td>
                           </tr>
                           <tr>
                              <td>Staff employment records</td>
                              <td>7 years after leaving employment</td>
                           </tr>
                           <tr>
                              <td>Admission applications (unsuccessful)</td>
                              <td>2 years</td>
                           </tr>
                           <tr>
                              <td>Financial transaction records</td>
                              <td>7 years (tax/audit compliance)</td>
                           </tr>
                           <tr>
                              <td>Website usage logs</td>
                              <td>12 months</td>
                           </tr>
                           <tr>
                              <td>Marketing consent records</td>
                              <td>3 years from last interaction</td>
                           </tr>
                        </table>

                        <!-- 11. Data Security -->
                        <h2 id="security">11. Data Security</h2>
                        <p>
                           We implement appropriate technical and organisational measures to safeguard your personal
                           data, including:
                        </p>
                        <ul>
                           <li>TLS/HTTPS encryption for all data in transit</li>
                           <li>Access controls and role-based permissions for staff</li>
                           <li>Regular security assessments and penetration testing</li>
                           <li>Secure password hashing (bcrypt)</li>
                           <li>CSRF token protection on all forms</li>
                           <li>Regular automated database backups stored in encrypted form</li>
                        </ul>
                        <p>
                           Despite our efforts, no data transmission over the internet is 100% secure.
                           In the event of a personal-data breach, we will notify affected individuals and
                           relevant authorities as required by applicable law.
                        </p>

                        <!-- 12. Your Rights -->
                        <h2 id="your-rights">12. Your Rights <span class="pp-gdpr-tag">GDPR Art. 15–22</span> <span class="pp-dpa-tag">DPA 2018</span></h2>
                        <p>Depending on your location and applicable law, you have the following rights regarding your personal data:</p>
                        <ul>
                           <li><strong>Right of Access</strong> – Request a copy of personal data we hold about you.</li>
                           <li><strong>Right to Rectification</strong> – Request correction of inaccurate or incomplete data.</li>
                           <li><strong>Right to Erasure ("Right to be Forgotten")</strong> – Request deletion of your data where there is no overriding legal reason to retain it.</li>
                           <li><strong>Right to Restrict Processing</strong> – Ask us to pause processing while a dispute is resolved.</li>
                           <li><strong>Right to Data Portability</strong> – Receive your data in a structured, machine-readable format.</li>
                           <li><strong>Right to Object</strong> – Object to processing based on legitimate interests or for direct marketing.</li>
                           <li><strong>Rights related to Automated Decision-Making</strong> – Not to be subject to solely automated decisions that significantly affect you.</li>
                           <li><strong>Right to Withdraw Consent</strong> – Withdraw any previously given consent at any time.</li>
                        </ul>
                        <p>
                           To exercise any of these rights, please contact us at
                           <a href="mailto:privacy@primeuniversity.ac.bd">privacy@primeuniversity.ac.bd</a>
                           or use our <a href="/user-data-deletion.php">Data Deletion Request</a> page.
                           We will respond within <strong>30 days</strong>.
                        </p>

                        <!-- 13. Bangladesh Digital Security Act -->
                        <h2 id="bd-dsa">13. Bangladesh Digital Security Act 2018 <span class="pp-bd-tag">BD DSA</span></h2>
                        <p>
                           The Bangladesh Digital Security Act 2018 (Act No. 46 of 2018) governs
                           cybersecurity and digital information in Bangladesh.
                           In accordance with this Act, Prime University:
                        </p>
                        <ul>
                           <li>Does not collect, process, or store digital data in a manner that violates individual privacy or national security.</li>
                           <li>Cooperates with the Bangladesh Digital Security Agency (BDSA) and law-enforcement authorities as required by law.</li>
                           <li>Maintains logs of access to critical digital infrastructure for the period required by applicable regulations.</li>
                           <li>Prohibits the unlawful publication of personal or sensitive information through any digital medium.</li>
                        </ul>
                        <p>
                           Bangladesh does not yet have a standalone Personal Data Protection Act; however, we apply
                           internationally recognised data-protection principles as best practice and in anticipation
                           of forthcoming Bangladeshi legislation.
                        </p>

                        <!-- 14. GDPR Supplemental Rights -->
                        <h2 id="gdpr-supplement">14. GDPR &amp; UK Data Protection Act Supplement <span class="pp-gdpr-tag">GDPR</span> <span class="pp-dpa-tag">DPA 2018</span></h2>
                        <p>
                           For users located in the EEA or the United Kingdom, the following additional information applies:
                        </p>
                        <ul>
                           <li>Our Data Protection Officer (DPO) can be contacted at <a href="mailto:dpo@primeuniversity.ac.bd">dpo@primeuniversity.ac.bd</a>.</li>
                           <li>You have the right to lodge a complaint with your national supervisory authority
                               (e.g., the UK Information Commissioner's Office at <a href="https://ico.org.uk" target="_blank" rel="noopener">ico.org.uk</a>,
                               or the relevant EU Data Protection Authority).</li>
                           <li>Where we rely on <strong>legitimate interests</strong> as our legal basis, you may request a copy of our
                               Legitimate Interests Assessment (LIA) by contacting us.</li>
                        </ul>

                        <!-- 15. Children's Privacy -->
                        <h2 id="children">15. Children's Privacy</h2>
                        <p>
                           Our primary services are aimed at persons aged 18 and over.
                           We do not knowingly collect personal data from children under 13 without verifiable
                           parental consent. If you believe we have inadvertently collected such data, please
                           contact us immediately and we will delete it.
                        </p>

                        <!-- 16. Third-Party Links -->
                        <h2 id="third-party">16. Third-Party Links</h2>
                        <p>
                           Our website may contain links to external websites. We are not responsible for the
                           privacy practices of those sites. We encourage you to review their privacy policies
                           before providing any personal data.
                        </p>

                        <!-- 17. Changes -->
                        <h2 id="changes">17. Changes to This Policy</h2>
                        <p>
                           We may update this Privacy Policy from time to time. Any significant changes will be
                           communicated via email (where we hold your address) or a prominent notice on our website.
                           The "Last Updated" date at the top of this page indicates when the latest revision was made.
                           Continued use of our services after changes take effect constitutes acceptance of the
                           updated Policy.
                        </p>

                        <!-- 18. Contact -->
                        <h2 id="contact">18. Contact &amp; Complaints</h2>
                        <p>For any privacy-related queries or to exercise your rights, please contact:</p>
                        <div class="pp-highlight-box">
                           <strong>Privacy Office – Prime University</strong><br>
                           114/116 Mazar Road, Mirpur, Dhaka-1216, Bangladesh<br>
                           Email: <a href="mailto:privacy@primeuniversity.ac.bd">privacy@primeuniversity.ac.bd</a><br>
                           Phone: +880 1969-955566
                        </div>
                        <p>
                           You also have the right to submit a data-deletion request via our dedicated
                           <a href="/user-data-deletion.php">User Data Deletion</a> page.
                        </p>

                     </div><!-- /.pp-body -->
                  </div>
               </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">

               <!-- Table of Contents -->
               <div class="card pp-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-list me-2 text-muted"></i>Table of Contents
                     </h6>
                     <ul class="pp-toc-list">
                        <li><a href="#introduction"><span class="text-muted me-1">1.</span> Introduction</a></li>
                        <li><a href="#controller"><span class="text-muted me-1">2.</span> Data Controller</a></li>
                        <li><a href="#what-we-collect"><span class="text-muted me-1">3.</span> What We Collect</a></li>
                        <li><a href="#how-we-collect"><span class="text-muted me-1">4.</span> How We Collect</a></li>
                        <li><a href="#legal-basis"><span class="text-muted me-1">5.</span> Legal Basis</a></li>
                        <li><a href="#how-we-use"><span class="text-muted me-1">6.</span> How We Use Data</a></li>
                        <li><a href="#cookies"><span class="text-muted me-1">7.</span> Cookies</a></li>
                        <li><a href="#data-sharing"><span class="text-muted me-1">8.</span> Data Sharing</a></li>
                        <li><a href="#international"><span class="text-muted me-1">9.</span> International Transfers</a></li>
                        <li><a href="#retention"><span class="text-muted me-1">10.</span> Data Retention</a></li>
                        <li><a href="#security"><span class="text-muted me-1">11.</span> Data Security</a></li>
                        <li><a href="#your-rights"><span class="text-muted me-1">12.</span> Your Rights</a></li>
                        <li><a href="#bd-dsa"><span class="text-muted me-1">13.</span> Bangladesh DSA</a></li>
                        <li><a href="#gdpr-supplement"><span class="text-muted me-1">14.</span> GDPR / DPA Supplement</a></li>
                        <li><a href="#children"><span class="text-muted me-1">15.</span> Children's Privacy</a></li>
                        <li><a href="#third-party"><span class="text-muted me-1">16.</span> Third-Party Links</a></li>
                        <li><a href="#changes"><span class="text-muted me-1">17.</span> Changes</a></li>
                        <li><a href="#contact"><span class="text-muted me-1">18.</span> Contact</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Applicable Laws -->
               <div class="card pp-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-balance-scale me-2 text-muted"></i>Applicable Laws
                     </h6>
                     <div class="pp-law-badge gdpr">
                        <i class="fas fa-flag"></i> EU GDPR (2016/679)
                     </div>
                     <div class="pp-law-badge dpa">
                        <i class="fas fa-flag"></i> UK Data Protection Act 2018
                     </div>
                     <div class="pp-law-badge bd">
                        <i class="fas fa-flag"></i> Bangladesh Digital Security Act 2018
                     </div>
                  </div>
               </div>

               <!-- Quick Links -->
               <div class="card pp-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-link me-2 text-muted"></i>Related Pages
                     </h6>
                     <ul class="pp-toc-list">
                        <li><a href="/user-data-deletion.php"><i class="fas fa-trash-alt me-2 text-danger"></i>Data Deletion Request</a></li>
                        <li><a href="/contact.php"><i class="fas fa-envelope me-2 text-muted"></i>Contact Us</a></li>
                        <li><a href="/index.php"><i class="fas fa-home me-2 text-muted"></i>Home</a></li>
                     </ul>
                  </div>
               </div>

               <!-- Share -->
               <div class="card pp-sidebar-card">
                  <div class="card-body p-4">
                     <h6 class="fw-bold mb-3" style="color:#002147;border-bottom:2px solid #FFB81C;padding-bottom:10px;">
                        <i class="fas fa-print me-2 text-muted"></i>Print / Share
                     </h6>
                     <button onclick="window.print()" class="btn btn-sm btn-outline-secondary w-100" style="border-radius:8px;">
                        <i class="fas fa-print me-1"></i>Print This Policy
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
