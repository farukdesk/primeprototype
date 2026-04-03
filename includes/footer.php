<?php
/**
 * Front-end include: Site Footer
 * Pulls all content from cms_footer_settings via get_footer_settings().
 * Social media links are pulled from cms_header_settings via get_header_settings().
 * Also renders the site popup (if enabled via admin Popup Settings).
 */

// Popup – rendered before the footer markup so it is always in the DOM
require_once __DIR__ . '/popup.php';
$fs = get_footer_settings();
$hs = get_header_settings();

$about_text          = $fs['about_text']          ?? 'Empowering future leaders through quality education, research and vibrant campus life since 1993.';
$cta_text            = $fs['cta_text']            ?? 'Contact Us';
$cta_url             = $fs['cta_url']             ?? 'contact.php';
$col2_title          = $fs['col2_title']          ?? 'Quick Links';
$col3_title          = $fs['col3_title']          ?? 'Student Services';
$contact_phone       = $fs['contact_phone']       ?? '+880-1710-996196';
$contact_email       = $fs['contact_email']       ?? 'info@primeuniversity.edu.bd';
$contact_address     = $fs['contact_address']     ?? '114/116, Mazar Rd, Dhaka-1216';
$contact_address_url = $fs['contact_address_url'] ?? 'https://maps.google.com/?q=Prime+University+Dhaka';
$copyright_text      = $fs['copyright_text']      ?? 'Prime University';

// Social links from header settings
$facebook_url  = $hs['facebook_url']  ?? '';
$twitter_url   = $hs['twitter_url']   ?? '';
$instagram_url = $hs['instagram_url'] ?? '';
$linkedin_url  = $hs['linkedin_url']  ?? '';

$col2_links = [];
$col3_links = [];
for ($i = 1; $i <= 5; $i++) {
    $text2 = trim($fs["col2_link_{$i}_text"] ?? '');
    $url2  = trim($fs["col2_link_{$i}_url"]  ?? '');
    if ($text2 !== '') {
        $col2_links[] = ['text' => $text2, 'url' => $url2 ?: '#'];
    }
    $text3 = trim($fs["col3_link_{$i}_text"] ?? '');
    $url3  = trim($fs["col3_link_{$i}_url"]  ?? '');
    if ($text3 !== '') {
        $col3_links[] = ['text' => $text3, 'url' => $url3 ?: '#'];
    }
}
?>
<!-- FOOTER -->
<footer class="pu-footer">

   <!-- ── Main Footer Body ──────────────────────────────────────────────── -->
   <div class="pu-footer__body">
      <div class="container">
         <div class="row gy-5">

            <!-- Col 1 – Brand & About -->
            <div class="col-xl-4 col-lg-4 col-md-12">
               <div class="pu-footer__brand">
                  <a href="/" class="pu-footer__logo">
                     <img src="/assets/img/logo/logo-white.png" alt="Prime University" class="pu-footer__logo-img">
                  </a>
                  <p class="pu-footer__about"><?= fh($about_text) ?></p>

                  <?php if ($facebook_url || $twitter_url || $instagram_url || $linkedin_url): ?>
                  <div class="pu-footer__social">
                     <?php if ($facebook_url && $facebook_url !== '#'): ?>
                     <a href="<?= fh($facebook_url) ?>" target="_blank" rel="noopener" aria-label="Facebook">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                     </a>
                     <?php endif; ?>
                     <?php if ($twitter_url && $twitter_url !== '#'): ?>
                     <a href="<?= fh($twitter_url) ?>" target="_blank" rel="noopener" aria-label="Twitter / X">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                     </a>
                     <?php endif; ?>
                     <?php if ($instagram_url && $instagram_url !== '#'): ?>
                     <a href="<?= fh($instagram_url) ?>" target="_blank" rel="noopener" aria-label="Instagram">
                        <svg viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" fill="none" stroke="currentColor" stroke-width="2"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                     </a>
                     <?php endif; ?>
                     <?php if ($linkedin_url && $linkedin_url !== '#'): ?>
                     <a href="<?= fh($linkedin_url) ?>" target="_blank" rel="noopener" aria-label="LinkedIn">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                     </a>
                     <?php endif; ?>
                  </div>
                  <?php endif; ?>

                  <a href="<?= fh($cta_url) ?>" class="pu-footer__cta">
                     <?= fh($cta_text) ?>
                     <svg width="14" height="14" viewBox="0 0 16 15" fill="none"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg>
                  </a>
               </div>
            </div>

            <!-- Col 2 – Quick Links -->
            <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 col-6">
               <div class="pu-footer__widget">
                  <h5 class="pu-footer__widget-title"><?= fh($col2_title) ?></h5>
                  <?php if ($col2_links): ?>
                  <ul class="pu-footer__links">
                     <?php foreach ($col2_links as $link): ?>
                     <li><a href="<?= fh($link['url']) ?>"><?= fh($link['text']) ?></a></li>
                     <?php endforeach; ?>
                  </ul>
                  <?php endif; ?>
               </div>
            </div>

            <!-- Col 3 – Student Services -->
            <div class="col-xl-2 col-lg-2 col-md-4 col-sm-6 col-6">
               <div class="pu-footer__widget">
                  <h5 class="pu-footer__widget-title"><?= fh($col3_title) ?></h5>
                  <?php if ($col3_links): ?>
                  <ul class="pu-footer__links">
                     <?php foreach ($col3_links as $link): ?>
                     <li><a href="<?= fh($link['url']) ?>"><?= fh($link['text']) ?></a></li>
                     <?php endforeach; ?>
                  </ul>
                  <?php endif; ?>
               </div>
            </div>

            <!-- Col 4 – Contact -->
            <div class="col-xl-4 col-lg-4 col-md-4 col-sm-12">
               <div class="pu-footer__widget">
                  <h5 class="pu-footer__widget-title">Get In Touch</h5>
                  <ul class="pu-footer__contact">
                     <?php if ($contact_phone): ?>
                     <li>
                        <span class="pu-footer__contact-icon">
                           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        </span>
                        <div>
                           <span class="pu-footer__contact-label">Phone</span>
                           <a href="tel:<?= fh(sanitize_phone($contact_phone)) ?>"><?= fh($contact_phone) ?></a>
                        </div>
                     </li>
                     <?php endif; ?>
                     <?php if ($contact_email): ?>
                     <li>
                        <span class="pu-footer__contact-icon">
                           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </span>
                        <div>
                           <span class="pu-footer__contact-label">Email</span>
                           <a href="mailto:<?= fh($contact_email) ?>"><?= fh($contact_email) ?></a>
                        </div>
                     </li>
                     <?php endif; ?>
                     <?php if ($contact_address): ?>
                     <li>
                        <span class="pu-footer__contact-icon">
                           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </span>
                        <div>
                           <span class="pu-footer__contact-label">Address</span>
                           <a href="<?= fh($contact_address_url) ?>" target="_blank" rel="noopener"><?= fh($contact_address) ?></a>
                        </div>
                     </li>
                     <?php endif; ?>
                  </ul>
               </div>
            </div>

         </div><!-- /row -->
      </div><!-- /container -->
   </div><!-- /pu-footer__body -->

   <!-- ── Copyright Bar ─────────────────────────────────────────────────── -->
   <div class="pu-footer__bottom">
      <div class="container">
         <div class="row align-items-center gy-2">
            <div class="col-md-6 text-center text-md-start">
               <p class="pu-footer__copy mb-0">
                  &copy; <?= date('Y') ?> <a href="/"><?= fh($copyright_text) ?></a>. All Rights Reserved.
               </p>
            </div>
            <div class="col-md-6 text-center text-md-end">
               <p class="pu-footer__copy mb-0">
                  Designed with <span class="pu-footer__heart">&#10084;</span> for Excellence in Education
               </p>
            </div>
         </div>
      </div>
   </div>

</footer>
<!-- FOOTER END -->
