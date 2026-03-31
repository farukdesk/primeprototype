<?php
/**
 * Department sub-navigation partial.
 * Requires these variables to be defined before including:
 *   $slug         – department URL slug
 *   $current_page – one of: overview, faculty, facilities, academic-programs, routine, clubs, prime-pride, notice
 *   $base         – base URL prefix, e.g. SITE_URL . '/department'
 */
?>
<div class="it-dept-subnav" id="deptSubNav">
   <div class="container">
      <nav class="dept-subnav-inner">
         <ul>
            <li><a href="<?= $base . '.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'overview' ? 'active' : '' ?>">Overview</a></li>
            <li><a href="<?= $base . '-faculty.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'faculty' ? 'active' : '' ?>">Faculty Members</a></li>
            <li><a href="<?= $base . '-facilities.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'facilities' ? 'active' : '' ?>">Facilities</a></li>
            <li><a href="<?= $base . '-academic-programs.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'academic-programs' ? 'active' : '' ?>">Academic Programs</a></li>
            <li><a href="<?= $base . '-routine.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'routine' ? 'active' : '' ?>">Class/Exam Routine</a></li>
            <li><a href="<?= $base . '-clubs.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'clubs' ? 'active' : '' ?>">Clubs</a></li>
            <li><a href="<?= $base . '-prime-pride.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'prime-pride' ? 'active' : '' ?>">Prime Pride</a></li>
            <li><a href="<?= $base . '-notice.php?slug=' . urlencode($slug) ?>" class="<?= $current_page === 'notice' ? 'active' : '' ?>">Notice</a></li>
         </ul>
      </nav>
   </div>
</div>
