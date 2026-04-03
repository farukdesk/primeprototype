<?php
/**
 * Front-end include: News Ticker
 * Pulls published news from cms_news (most recent 10) and renders the scrolling ticker.
 * Falls back to an empty ticker if no news exists or DB is unavailable.
 */

$_news_items = [];
try {
    $db = front_db();
    if ($db) {
        $_news_items = $db->query(
            'SELECT title, slug
             FROM cms_news
             WHERE is_published = 1
             ORDER BY published_at DESC, created_at DESC
             LIMIT 10'
        )->fetchAll();
    }
} catch (Throwable $e) {
    // silently fall through
}
?>
<!-- news-ticker-area-start -->
<div class="it-news-ticker-area">
   <div class="container-fluid">
      <div class="it-news-ticker-wrap d-flex align-items-center">
         <div class="it-news-ticker-label">
            <span><i class="fas fa-bell"></i> Latest News</span>
         </div>
         <div class="it-news-ticker-content">
            <div class="it-news-ticker-slider">
               <?php if (!empty($_news_items)): ?>
                  <?php foreach ($_news_items as $n): ?>
                  <div class="it-news-ticker-item">
                     <a href="<?= SITE_URL ?>/news/<?= urlencode($n['slug']) ?>"><?= fh($n['title']) ?></a>
                  </div>
                  <?php endforeach; ?>
               <?php else: ?>
                  <div class="it-news-ticker-item">
                     <a href="#">Welcome to Prime University – Empowering Future Leaders Through Quality Education</a>
                  </div>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </div>
</div>
<!-- news-ticker-area-end -->
