<?php
/**
 * Front-end include: Site Footer
 * Pulls all content from cms_footer_settings via get_footer_settings().
 */
$fs = get_footer_settings();

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
<footer>
<section class="it-footer-wrap it-footer-style-2 fix">
   <div class="it-footer-area z-index-1 pt-120 pb-80" data-background="assets/img/shape/footer-bg-3-1.jpg">
      <img class="it-footer-shape-1 d-none d-xxl-block" src="assets/img/shape/footer-3-1.png" alt="">
      <img class="it-footer-shape-2" data-parallax='{"y": -200, "smoothness": 30}' src="assets/img/shape/footer-3-2.png" alt="">
      <div class="it-footer-border"><span></span></div>
      <div class="container">
         <div class="row">

            <!-- Col 1 – Logo & About -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".3s">
               <div class="it-footer-widget it-footer-col-1-1">
                  <div class="it-footer-widget-logo mb-30">
                     <a href="index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
                  </div>
                  <div class="it-footer-widget-text"><p><?= fh($about_text) ?></p></div>
                  <div class="it-footer-widget-btn">
                     <a href="<?= fh($cta_url) ?>" class="it-btn-yellow theme-bg border-radius-100">
                        <span><span class="text-1"><?= fh($cta_text) ?></span><span class="text-2"><?= fh($cta_text) ?></span></span>
                        <i><svg width="16" height="15" viewBox="0 0 16 15" fill="none"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg></i>
                     </a>
                  </div>
               </div>
            </div>

            <!-- Col 2 – Quick Links -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".5s">
               <div class="it-footer-widget">
                  <h4 class="it-footer-widget-title"><?= fh($col2_title) ?></h4>
                  <?php if ($col2_links): ?>
                  <div class="it-footer-widget-menu"><ul>
                     <?php foreach ($col2_links as $link): ?>
                     <li><a href="<?= fh($link['url']) ?>"><?= fh($link['text']) ?></a></li>
                     <?php endforeach; ?>
                  </ul></div>
                  <?php endif; ?>
               </div>
            </div>

            <!-- Col 3 – Student Services -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".7s">
               <div class="it-footer-widget">
                  <h4 class="it-footer-widget-title"><?= fh($col3_title) ?></h4>
                  <?php if ($col3_links): ?>
                  <div class="it-footer-widget-menu"><ul>
                     <?php foreach ($col3_links as $link): ?>
                     <li><a href="<?= fh($link['url']) ?>"><?= fh($link['text']) ?></a></li>
                     <?php endforeach; ?>
                  </ul></div>
                  <?php endif; ?>
               </div>
            </div>

            <!-- Col 4 – Contact -->
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 mb-50 wow itfadeUp" data-wow-duration=".9s" data-wow-delay=".9s">
               <div class="it-footer-widget d-flex justify-content-lg-end">
                  <div>
                     <h4 class="it-footer-widget-title">Get Contact</h4>
                     <div class="it-footer-widget-contact mb-25"><ul>
                        <?php if ($contact_phone): ?>
                        <li><span>Phone:</span><a href="tel:<?= fh(sanitize_phone($contact_phone)) ?>"><?= fh($contact_phone) ?></a></li>
                        <?php endif; ?>
                        <?php if ($contact_email): ?>
                        <li><span>Email:</span><a href="mailto:<?= fh($contact_email) ?>"><?= fh($contact_email) ?></a></li>
                        <?php endif; ?>
                        <?php if ($contact_address): ?>
                        <li><span>Location:</span><a target="_blank" rel="noopener" href="<?= fh($contact_address_url) ?>"><?= fh($contact_address) ?></a></li>
                        <?php endif; ?>
                     </ul></div>
                  </div>
               </div>
            </div>

         </div>
      </div>
   </div>
   <div class="it-copyright-area it-copyright-ptb it-copyright-bg z-index-1 theme-bg">
      <div class="container">
         <div class="row align-items-center">
            <div class="col-12">
               <div class="it-copyright-left style-2 text-center">
                  <p class="mb-0">Copyright &copy; <?= date('Y') ?> <a href="index.php"><?= fh($copyright_text) ?></a>. All Rights Reserved.</p>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>
</footer>
<!-- FOOTER END -->
