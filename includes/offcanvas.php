<?php
/**
 * Front-end include: Mobile Offcanvas Sidebar
 * Renders the slide-in sidebar menu used on all pages.
 * Reads contact info and quick-link URLs from cms_header_settings.
 */
$_oc_hs = get_header_settings();

$_oc_phone               = $_oc_hs['phone']               ?? '01969-955566';
$_oc_email               = $_oc_hs['email']               ?? 'info@primeuniversity.ac.bd';
$_oc_address             = $_oc_hs['address']             ?? '114/116, Mazar Rd, Dhaka-1216';
$_oc_student_portal_url  = $_oc_hs['student_portal_url']  ?? '#';
$_oc_student_portal_text = $_oc_hs['student_portal_text'] ?? 'Student Portal';
$_oc_find_result_url     = $_oc_hs['find_result_url']     ?? '#';
$_oc_find_result_text    = $_oc_hs['find_result_text']    ?? 'Certificate Verification';
$_oc_current_result_url  = $_oc_hs['current_result_url']  ?? '#';
$_oc_current_result_text = $_oc_hs['current_result_text'] ?? 'Student Enrollment Status';

// Resolve header logo
$_oc_logo_url = '/assets/img/logo/logo-black.png';
if (!empty($_oc_hs['logo_header'])) {
    $_oc_logo_url = ADMIN_UPLOAD_URL . '/logos/' . $_oc_hs['logo_header'];
}
?>
<!-- offcanvas area start -->
<div class="it-offcanvas-area">
   <div class="itoffcanvas">
      <div class="itoffcanvas__close-btn">
         <button class="close-btn" aria-label="Close menu"><i class="fal fa-times"></i></button>
      </div>
      <div class="itoffcanvas__logo">
         <a href="<?= fh(SITE_URL) ?>/index.php">
            <img src="<?= fh($_oc_logo_url) ?>" alt="Prime University">
         </a>
      </div>
      <div class="it-menu-mobile d-xl-none"></div>
      <div class="itoffcanvas__quick-links d-xl-none">
         <ul class="itoffcanvas__quick-links-list">
            <li><a href="<?= fh($_oc_student_portal_url) ?>"><?= fh($_oc_student_portal_text) ?></a></li>
            <li><a href="<?= fh($_oc_find_result_url) ?>"><?= fh($_oc_find_result_text) ?></a></li>
            <li><a href="<?= fh($_oc_current_result_url) ?>"><?= fh($_oc_current_result_text) ?></a></li>
         </ul>
      </div>
      <div class="itoffcanvas__info">
         <h3 class="offcanva-title">Get In Touch</h3>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="tel:<?= fh(sanitize_phone($_oc_phone)) ?>"><i class="fal fa-phone-alt"></i></a></div>
            <div class="itoffcanvas__info-address">
               <span>Phone</span>
               <a href="tel:<?= fh(sanitize_phone($_oc_phone)) ?>"><?= fh($_oc_phone) ?></a>
            </div>
         </div>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="mailto:<?= fh($_oc_email) ?>"><i class="fal fa-envelope"></i></a></div>
            <div class="itoffcanvas__info-address">
               <span>Email</span>
               <a href="mailto:<?= fh($_oc_email) ?>" class="itoffcanvas__email-link"><?= fh($_oc_email) ?></a>
            </div>
         </div>
         <div class="it-info-wrapper mb-20 d-flex align-items-center">
            <div class="itoffcanvas__info-icon"><a href="#"><i class="fas fa-map-marker-alt"></i></a></div>
            <div class="itoffcanvas__info-address">
               <span>Address</span>
               <a href="#"><?= fh($_oc_address) ?></a>
            </div>
         </div>
      </div>
   </div>
</div>
<div class="body-overlay"></div>
<!-- offcanvas area end -->
