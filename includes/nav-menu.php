<?php
/**
 * Front-end include: Navigation Menu
 * Builds a recursive tree from cms_menus and renders the desktop megamenu/dropdown nav.
 *
 * Menu type conventions:
 *   type = 'link'      → plain link (or a group-header inside a megamenu column)
 *   type = 'dropdown'  → top-level item with a simple <ul> dropdown
 *   type = 'megamenu'  → top-level item with a multi-column megamenu panel
 *
 * Depth-based rendering:
 *   Depth 0 (parent_id IS NULL)  → main nav <li>
 *   Depth 1 (child of depth-0)   → dropdown link -or- megamenu column header
 *   Depth 2 (child of depth-1)   → megamenu link inside a column
 */

function _nav_build_tree(array $rows): array {
    $map = [];
    foreach ($rows as $r) {
        $r['children'] = [];
        $map[$r['id']] = $r;
    }
    $tree = [];
    foreach ($map as $id => $node) {
        if ($node['parent_id'] && isset($map[$node['parent_id']])) {
            $map[$node['parent_id']]['children'][$id] = &$map[$id];
        } else {
            $tree[$id] = &$map[$id];
        }
    }
    return $tree;
}

$_nav_items = [];
try {
    $db = front_db();
    if ($db) {
        $_nav_items = $db->query(
            'SELECT id, parent_id, label, url, target, type, sort_order
             FROM cms_menus
             WHERE is_active = 1
             ORDER BY COALESCE(parent_id, id), sort_order, id'
        )->fetchAll();
    }
} catch (Throwable $e) {
    // silently fall through – nav will render nothing
}

$_nav_tree = _nav_build_tree($_nav_items);

// SVG arrow used on button labels
$_svg_arrow = '<svg width="16" height="15" viewBox="0 0 16 15" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.0544 8.1364C15.4058 7.78492 15.4058 7.21508 15.0544 6.8636L9.3268 1.13604C8.97533 0.784567 8.40548 0.784567 8.05401 1.13604C7.70254 1.48751 7.70254 2.05736 8.05401 2.40883L13.1452 7.5L8.05401 12.5912C7.70254 12.9426 7.70254 13.5125 8.05401 13.864C8.40548 14.2154 8.97533 14.2154 9.3268 13.864L15.0544 8.1364ZM0.417969 7.5V8.4H14.418V7.5V6.6H0.417969V7.5Z" fill="currentcolor"/></svg>';
?>
<!-- header-area-start -->
<div id="header-sticky" class="it-header-area it-header-ptb p-relative">
   <div class="container">
      <div class="row align-items-center">
         <div class="col-xxl-2 col-xl-2 col-lg-4 col-md-5 col-6">
            <div class="it-header-logo">
               <a href="<?= fh(SITE_URL) ?>/index.php"><img src="assets/img/logo/logo-black.png" alt="Prime University"></a>
            </div>
         </div>
         <div class="col-xxl-8 col-xl-8 d-none d-xl-block">
            <div class="it-header-menu it-header-dropdown">
               <nav class="it-menu-content">
                  <ul>
                  <?php foreach ($_nav_tree as $item): ?>
                     <?php
                     $has_children = !empty($item['children']);
                     $type         = $item['type'];
                     $target_attr  = $item['target'] === '_blank' ? ' target="_blank" rel="noopener"' : '';
                     ?>
                     <?php if (!$has_children): ?>
                        <li class="p-static">
                           <a href="<?= fh($item['url']) ?>"<?= $target_attr ?>><?= fh($item['label']) ?></a>
                        </li>
                     <?php elseif ($type === 'megamenu'): ?>
                        <li class="has-dropdown p-static">
                           <a href="<?= fh($item['url']) ?>"<?= $target_attr ?>><?= fh($item['label']) ?></a>
                           <div class="it-submenu submenu it-megamenu-wrap">
                              <div class="row gx-50">
                              <?php foreach ($item['children'] as $col): ?>
                                 <div class="col-xl-3">
                                    <div class="it-megamenu-item">
                                       <h4 class="it-megamenu-title"><?= fh($col['label']) ?></h4>
                                       <?php if (!empty($col['children'])): ?>
                                       <ul>
                                          <?php foreach ($col['children'] as $link): ?>
                                          <li>
                                             <a href="<?= fh($link['url']) ?>"
                                                <?= $link['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                                                <?= fh($link['label']) ?>
                                             </a>
                                          </li>
                                          <?php endforeach; ?>
                                       </ul>
                                       <?php endif; ?>
                                    </div>
                                 </div>
                              <?php endforeach; ?>
                              </div>
                           </div>
                        </li>
                     <?php else: /* dropdown */ ?>
                        <li class="has-dropdown">
                           <a href="<?= fh($item['url']) ?>"<?= $target_attr ?>><?= fh($item['label']) ?></a>
                           <ul class="it-submenu submenu">
                              <?php foreach ($item['children'] as $child): ?>
                              <li>
                                 <a href="<?= fh($child['url']) ?>"
                                    <?= $child['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
                                    <?= fh($child['label']) ?>
                                 </a>
                              </li>
                              <?php endforeach; ?>
                           </ul>
                        </li>
                     <?php endif; ?>
                  <?php endforeach; ?>
                  </ul>
               </nav>
            </div>
         </div>
         <div class="col-xxl-2 col-xl-2 col-lg-2 col-md-7 col-6">
            <div class="it-header-right-action d-flex justify-content-end align-items-center">
               <a href="courses-with-filter.html" class="it-btn-yellow border-radius-100 d-none d-md-flex">
                  <span>
                     <span class="text-1">Apply Now</span>
                     <span class="text-2">Apply Now</span>
                  </span>
                  <i><?= $_svg_arrow ?></i>
               </a>
               <div class="it-header-bar d-xl-none">
                  <button class="it-menu-bar">
                     <span>
                        <i class="fa-light fa-bars-staggered"></i>
                     </span>
                  </button>
               </div>
            </div>
         </div>
      </div>
   </div>
</div>
<!-- header-area-end -->
