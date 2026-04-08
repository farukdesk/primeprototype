<?php
/**
 * Admin Layout – HTML <head> + top navbar open
 * $page_title should be set before including this file.
 */
$page_title = $page_title ?? APP_NAME;
$user       = auth_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title) ?> | <?= h(APP_NAME) ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-bg:    #1a1f36;
            --sidebar-text:  #a8b2d8;
            --sidebar-hover: #252d4a;
            --sidebar-active:#4f8ef7;
            --topbar-h:      60px;
            --accent:        #4f8ef7;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6fb;
            margin: 0;
            color: #333;
        }

        /* ── Sidebar ── */
        #sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            overflow-y: auto;
            z-index: 1040;
            transition: transform .25s ease;
        }

        #sidebar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 18px 20px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            text-decoration: none;
        }
        #sidebar .brand img { width: 38px; border-radius: 8px; }
        #sidebar .brand span {
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            line-height: 1.2;
        }

        #sidebar .nav-label {
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #556;
            padding: 18px 20px 6px;
        }

        #sidebar .nav-item a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: .875rem;
            border-left: 3px solid transparent;
            transition: background .15s, color .15s;
        }
        #sidebar .nav-item a i { width: 18px; text-align: center; }
        #sidebar .nav-item a:hover,
        #sidebar .nav-item a.active {
            background: var(--sidebar-hover);
            color: #fff;
            border-left-color: var(--sidebar-active);
        }

        /* ── Main ── */
        #main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Topbar ── */
        #topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            height: var(--topbar-h);
            background: #fff;
            border-bottom: 1px solid #e8eaf0;
            display: flex;
            align-items: center;
            padding: 0 24px;
            gap: 12px;
        }
        #topbar .toggle-btn {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: #555;
            cursor: pointer;
            display: none;
        }
        #topbar .page-heading {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1f36;
            flex: 1;
        }
        #topbar .user-menu .dropdown-toggle {
            background: none;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .875rem;
            color: #333;
            cursor: pointer;
        }
        #topbar .user-menu .avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 600;
            font-size: .85rem;
        }

        /* ── Content ── */
        #content {
            flex: 1;
            padding: 28px 28px 40px;
        }

        /* ── Cards ── */
        .stat-card {
            border: none;
            border-radius: 12px;
            padding: 22px 24px;
            color: #fff;
            transition: transform .2s;
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card .stat-icon {
            font-size: 2rem;
            opacity: .8;
        }
        .stat-card .stat-val {
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: .8rem;
            opacity: .85;
        }

        .card { border: none; border-radius: 12px; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
        .card-header { border-bottom: 1px solid #f0f2f7; background: #fff; border-radius: 12px 12px 0 0 !important; }

        /* ── Tables ── */
        .table th { font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .table td { vertical-align: middle; font-size: .875rem; }

        /* ── Badges ── */
        .badge-super { background: linear-gradient(135deg,#f5a623,#e07b00); color:#fff; }

        /* ── Alerts ── */
        .alert { border: none; border-radius: 10px; font-size: .875rem; }

        /* ── Nav Groups (collapsible) ── */
        .nav-group-toggle {
            display: flex;
            align-items: center;
            width: 100%;
            padding: 9px 20px;
            background: none;
            border: none;
            border-left: 3px solid transparent;
            color: #8892c4;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            cursor: pointer;
            transition: color .15s, background .15s;
            gap: 9px;
            margin-top: 6px;
        }
        .nav-group-toggle:hover { color: #fff; background: rgba(255,255,255,.04); }
        .nav-group-toggle .grp-icon { width: 18px; text-align: center; font-size: .85rem; }
        .nav-group-toggle .toggle-icon {
            margin-left: auto;
            font-size: .6rem;
            opacity: .5;
            transition: transform .2s;
        }
        .nav-group-toggle:not(.collapsed) .toggle-icon { transform: rotate(180deg); }
        .grp-items { margin: 0; padding: 0; }
        .grp-items .nav-item a {
            padding-left: 40px;
            font-size: .83rem;
            border-left: 3px solid transparent;
        }
        .grp-items .nav-item a:hover,
        .grp-items .nav-item a.active {
            background: var(--sidebar-hover);
            color: #fff;
            border-left-color: var(--sidebar-active);
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.open { transform: translateX(0); }
            #main-wrapper { margin-left: 0; }
            #topbar .toggle-btn { display: block; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════ SIDEBAR ═══════════════════════ -->
<nav id="sidebar">
    <a href="<?= APP_URL ?>/index.php" class="brand">
        <img src="<?= APP_URL ?>/../assets/img/logo/favicon.png" alt="PU" onerror="this.style.display='none'">
        <span>Prime University<br><small style="font-weight:400;font-size:.7rem;opacity:.7">Admin Panel</small></span>
    </a>

    <?php
    $current_path = $_SERVER['PHP_SELF'];
    $is_website_active  = strpos($current_path, '/cms/') !== false || strpos($current_path, '/homepage/') !== false || strpos($current_path, '/pages/') !== false || strpos($current_path, '/policy-procedure/') !== false;
    $is_academic_active = strpos($current_path, '/departments/') !== false || strpos($current_path, '/faculty-profiles/') !== false || strpos($current_path, '/students/') !== false || strpos($current_path, '/course-curriculum/') !== false || strpos($current_path, '/clubs/') !== false || strpos($current_path, '/staff-profiles/') !== false;
    $is_comms_active    = strpos($current_path, '/contact/') !== false || strpos($current_path, '/support-tickets/') !== false || strpos($current_path, '/knowledge-base/') !== false || strpos($current_path, '/broadcast/') !== false;
    $is_leads_active    = strpos($current_path, '/leads/') !== false;
    $is_admissions_active = strpos($current_path, '/admissions/') !== false;
    $is_jobs_active          = strpos($current_path, '/jobs/') !== false;
    $is_library_active       = strpos($current_path, '/library/') !== false;
    $is_course_fees_active   = strpos($current_path, '/course-fees/') !== false;
    $is_governing_body_active = strpos($current_path, '/governing-body/') !== false;
    $is_admin_active    = strpos($current_path, '/users/') !== false || strpos($current_path, '/user-groups/') !== false
                       || strpos($current_path, '/modules/') !== false || strpos($current_path, '/access/') !== false
                       || strpos($current_path, '/email-templates/') !== false || strpos($current_path, '/change-log/') !== false;
    $is_internal_active = strpos($current_path, '/file-manager/') !== false || strpos($current_path, '/notice-signing/') !== false || strpos($current_path, '/my-signature/') !== false;
    ?>

    <!-- Dashboard -->
    <?php if (is_super_admin() || can_access('dashboard')): ?>
    <ul class="nav flex-column mt-2">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/index.php"
               class="<?= preg_match('#/admin/index\.php$#', $current_path) ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <!-- ── Website & CMS ── -->
    <?php if (is_super_admin() || can_access('homepage') || can_access('cms-notice-board')): ?>
    <button class="nav-group-toggle <?= $is_website_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-website"
            aria-expanded="<?= $is_website_active ? 'true' : 'false' ?>">
        <i class="fas fa-globe grp-icon" style="color:#4f8ef7"></i>
        Website & CMS
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_website_active ? 'show' : '' ?>" id="grp-website">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/header/index.php"
                   class="<?= strpos($current_path, '/cms/header/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-heading"></i> Header
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/menus/index.php"
                   class="<?= strpos($current_path, '/cms/menus/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-bars"></i> Menus
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/sliders/index.php"
                   class="<?= strpos($current_path, '/cms/sliders/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-images"></i> Sliders
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/news/index.php"
                   class="<?= strpos($current_path, '/cms/news/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-newspaper"></i> News
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/programs/index.php"
                   class="<?= strpos($current_path, '/cms/programs/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-graduation-cap"></i> Programs
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/about/index.php"
                   class="<?= strpos($current_path, '/cms/about/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-info-circle"></i> About
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/glance/index.php"
                   class="<?= strpos($current_path, '/cms/glance/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-eye"></i> PU At a Glance
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/why-choose-us/index.php"
                   class="<?= strpos($current_path, '/cms/why-choose-us/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-star"></i> Why Choose Us
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/admissions/index.php"
                   class="<?= strpos($current_path, '/cms/admissions/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-door-open"></i> Admissions
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/campus/index.php"
                   class="<?= strpos($current_path, '/cms/campus/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-university"></i> Campus Life
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/alumni/index.php"
                   class="<?= strpos($current_path, '/cms/alumni/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-user-graduate"></i> Alumni
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/code-of-conduct/index.php"
                   class="<?= strpos($current_path, '/cms/code-of-conduct/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-gavel"></i> Code of Conduct
                </a>
            </li>
            <?php if (is_super_admin()): ?>
            <?php
            // Pending approvals badge count (cached per request)
            try {
                $_pcdb = db();
                $_pc_count = (int)$_pcdb->query(
                    "SELECT
                        (SELECT COUNT(*) FROM cms_pending_changes WHERE status='pending') +
                        (SELECT COUNT(*) FROM cms_news    WHERE is_approved=0) +
                        (SELECT COUNT(*) FROM cms_notices WHERE is_approved=0)"
                )->fetchColumn();
            } catch (Throwable $_pce) { $_pc_count = 0; }
            ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/pending-changes/index.php"
                   class="<?= strpos($current_path, '/cms/pending-changes/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> Pending Approvals
                    <?php if ($_pc_count > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto" style="font-size:.65rem;"><?= $_pc_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/homepage/index.php"
                   class="<?= strpos($current_path, '/homepage/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Homepage
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/contact-settings/index.php"
                   class="<?= strpos($current_path, '/cms/contact-settings/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-address-card"></i> Contact Info
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/footer/index.php"
                   class="<?= strpos($current_path, '/cms/footer/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-shoe-prints"></i> Footer
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('cms-notice-board')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/notice-board/index.php"
                   class="<?= strpos($current_path, '/cms/notice-board/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-bullhorn"></i> Notice Board
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('policy-procedure')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/policy-procedure/index.php"
                   class="<?= strpos($current_path, '/policy-procedure/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-contract"></i> Policy &amp; Procedure
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('pages')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/pages/index.php?category=general"
                   class="<?= (strpos($current_path, '/pages/') !== false && ($_GET['category'] ?? '') === 'general') ? 'active' : '' ?>">
                    <i class="fas fa-columns"></i> General Pages
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/pages/index.php?category=profile"
                   class="<?= (strpos($current_path, '/pages/') !== false && ($_GET['category'] ?? '') === 'profile') ? 'active' : '' ?>">
                    <i class="fas fa-id-card"></i> Profile Pages
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/pages/index.php?category=policy"
                   class="<?= (strpos($current_path, '/pages/') !== false && ($_GET['category'] ?? '') === 'policy') ? 'active' : '' ?>">
                    <i class="fas fa-file-contract"></i> Policy Pages
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cms/popup/index.php"
                   class="<?= strpos($current_path, '/cms/popup/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-window-restore"></i> Popup
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Governing Body ── -->
    <?php if (is_super_admin() || can_access('governing-body')): ?>
    <button class="nav-group-toggle <?= $is_governing_body_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-governing-body"
            aria-expanded="<?= $is_governing_body_active ? 'true' : 'false' ?>">
        <i class="fas fa-university grp-icon" style="color:#002147"></i>
        Governing Body
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_governing_body_active ? 'show' : '' ?>" id="grp-governing-body">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/governing-body/index.php"
                   class="<?= (strpos($current_path, '/governing-body/') !== false && strpos($current_path, '/members/') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-th-large"></i> Overview
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/governing-body/members/index.php?page_type=board-of-trustees"
                   class="<?= strpos($current_path, '/governing-body/members/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Members
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Academic ── -->
    <?php if (is_super_admin() || can_access('departments') || can_access('students') || can_access('course-curriculum') || can_access('clubs') || can_access('staff-departments')): ?>
    <button class="nav-group-toggle <?= $is_academic_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-academic"
            aria-expanded="<?= $is_academic_active ? 'true' : 'false' ?>">
        <i class="fas fa-graduation-cap grp-icon" style="color:#2ecc71"></i>
        Academic
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_academic_active ? 'show' : '' ?>" id="grp-academic">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('departments')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/departments/index.php"
                   class="<?= strpos($current_path, '/departments/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-building-columns"></i> Departments
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/faculty-profiles/index.php"
                   class="<?= strpos($current_path, '/faculty-profiles/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chalkboard-teacher"></i> Faculty Profiles
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('students')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/students/index.php"
                   class="<?= strpos($current_path, '/students/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-user-graduate"></i> Students
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('course-curriculum')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/course-curriculum/index.php"
                   class="<?= strpos($current_path, '/course-curriculum/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Course Curriculum
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('clubs')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/clubs/index.php"
                   class="<?= strpos($current_path, '/clubs/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Clubs
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('staff-departments')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/staff-profiles/index.php"
                   class="<?= strpos($current_path, '/staff-profiles/') !== false && strpos($current_path, '/my-profile') === false ? 'active' : '' ?>">
                    <i class="fas fa-id-badge"></i> Staff Profiles
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/staff-profiles/departments.php"
                   class="<?= strpos($current_path, '/staff-profiles/departments') !== false ? 'active' : '' ?>">
                    <i class="fas fa-sitemap"></i> Staff Departments
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Lead Management ── -->
    <?php if (is_super_admin() || can_access('leads')): ?>
    <button class="nav-group-toggle <?= $is_leads_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-leads"
            aria-expanded="<?= $is_leads_active ? 'true' : 'false' ?>">
        <i class="fas fa-funnel-dollar grp-icon" style="color:#e74c3c"></i>
        Lead Management
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_leads_active ? 'show' : '' ?>" id="grp-leads">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/index.php"
                   class="<?= (strpos($current_path, '/leads/') !== false && strpos($current_path, '/create') === false) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> All Leads
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/index.php?status=fresh"
                   class="<?php $get_status = $_GET['status'] ?? ''; echo (strpos($current_path, '/leads/') !== false && $get_status === 'fresh') ? 'active' : ''; ?>">
                    <i class="fas fa-bolt"></i> Fresh Leads
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/index.php?status=unable_to_reach">
                    <i class="fas fa-phone-slash"></i> Unable to Reach
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/index.php?status=converted">
                    <i class="fas fa-check-circle"></i> Converted
                </a>
            </li>
            <?php if (is_super_admin() || can_access('leads', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/create.php"
                   class="<?= strpos($current_path, '/leads/create') !== false ? 'active' : '' ?>">
                    <i class="fas fa-plus"></i> Add Lead
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Admissions ── -->
    <?php if (is_super_admin() || can_access('admissions')): ?>
    <button class="nav-group-toggle <?= $is_admissions_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-admissions"
            aria-expanded="<?= $is_admissions_active ? 'true' : 'false' ?>">
        <i class="fas fa-user-plus grp-icon" style="color:#e67e22"></i>
        Admissions
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_admissions_active ? 'show' : '' ?>" id="grp-admissions">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/admissions/index.php"
                   class="<?= (strpos($current_path, '/admissions/') !== false && strpos($current_path, '/create') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> All Applications
                </a>
            </li>
            <?php if (is_super_admin() || can_access('admissions', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/admissions/create.php"
                   class="<?= strpos($current_path, '/admissions/create') !== false ? 'active' : '' ?>">
                    <i class="fas fa-plus"></i> New Application
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('admissions', 'can_edit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/admissions/settings.php"
                   class="<?= strpos($current_path, '/admissions/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Communication ── -->
    <?php if (is_super_admin() || can_access('contact') || can_access('support-tickets') || can_access('knowledge-base') || can_access('broadcast')): ?>
    <button class="nav-group-toggle <?= $is_comms_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-comms"
            aria-expanded="<?= $is_comms_active ? 'true' : 'false' ?>">
        <i class="fas fa-comments grp-icon" style="color:#9b59b6"></i>
        Communication
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_comms_active ? 'show' : '' ?>" id="grp-comms">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('contact')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/contact/index.php"
                   class="<?= strpos($current_path, '/contact/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-envelope-open-text"></i> Contact Messages
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('broadcast')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/broadcast/index.php"
                   class="<?= strpos($current_path, '/broadcast/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-bullhorn"></i> Broadcast
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('support-tickets')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/support-tickets/index.php"
                   class="<?= (strpos($current_path, '/support-tickets/') !== false && strpos($current_path, '/reports') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-ticket-alt"></i> IT Support
                </a>
            </li>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/support-tickets/reports.php"
                   class="<?= strpos($current_path, '/support-tickets/reports') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Support Reports
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/support-tickets/settings.php"
                   class="<?= strpos($current_path, '/support-tickets/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Support Settings
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('knowledge-base')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/knowledge-base/index.php"
                   class="<?= strpos($current_path, '/knowledge-base/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Knowledge Base
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── HR & Jobs ── -->
    <?php if (is_super_admin() || can_access('jobs')): ?>
    <button class="nav-group-toggle <?= $is_jobs_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-jobs"
            aria-expanded="<?= $is_jobs_active ? 'true' : 'false' ?>">
        <i class="fas fa-briefcase grp-icon" style="color:#e67e22"></i>
        HR &amp; Jobs
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_jobs_active ? 'show' : '' ?>" id="grp-jobs">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/jobs/index.php"
                   class="<?= (strpos($current_path, '/jobs/') !== false && strpos($current_path, '/jobs/application') === false) ? 'active' : '' ?>">
                    <i class="fas fa-briefcase"></i> Job Postings
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/jobs/applications.php"
                   class="<?= strpos($current_path, '/jobs/application') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-alt"></i> Applications
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Library ── -->
    <?php if (is_super_admin() || can_access('library') || can_access('library-circulation') || can_access('library-digital')): ?>
    <button class="nav-group-toggle <?= $is_library_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-library"
            aria-expanded="<?= $is_library_active ? 'true' : 'false' ?>">
        <i class="fas fa-book grp-icon" style="color:#1abc9c"></i>
        Library
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_library_active ? 'show' : '' ?>" id="grp-library">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('library')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/index.php"
                   class="<?= (strpos($current_path, '/library/') !== false && strpos($current_path, '/library/circulation') === false && strpos($current_path, '/library/digital') === false) ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/books/index.php"
                   class="<?= strpos($current_path, '/library/books/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-books"></i> Books
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/members/index.php"
                   class="<?= strpos($current_path, '/library/members/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Members
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('library-circulation')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/circulation/index.php"
                   class="<?= strpos($current_path, '/library/circulation/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt"></i> Circulation
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('library-digital')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/digital/index.php"
                   class="<?= strpos($current_path, '/library/digital/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-pdf"></i> Digital Library
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('library')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/fines/index.php"
                   class="<?= strpos($current_path, '/library/fines/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-money-bill-wave"></i> Fines
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/reports/index.php"
                   class="<?= strpos($current_path, '/library/reports/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/library/settings/index.php"
                   class="<?= strpos($current_path, '/library/settings/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Course Fees Calculator ── -->
    <?php if (is_super_admin() || can_access('course-fees')): ?>
    <button class="nav-group-toggle <?= $is_course_fees_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-course-fees"
            aria-expanded="<?= $is_course_fees_active ? 'true' : 'false' ?>">
        <i class="fas fa-calculator grp-icon" style="color:#f59e0b"></i>
        Course Fees
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_course_fees_active ? 'show' : '' ?>" id="grp-course-fees">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/course-fees/index.php"
                   class="<?= (strpos($current_path, '/course-fees/') !== false && strpos($current_path, '/create') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> Fee Structures
                </a>
            </li>
            <?php if (is_super_admin() || can_access('course-fees', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/course-fees/create.php"
                   class="<?= strpos($current_path, '/course-fees/create') !== false ? 'active' : '' ?>">
                    <i class="fas fa-plus"></i> Add Structure
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('course-fees', 'can_edit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/course-fees/settings.php"
                   class="<?= strpos($current_path, '/course-fees/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= SITE_URL ?>/course-fees-calculator.php" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Public Page
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Internal (File Manager & Notice Signing) ── -->
    <?php if (is_super_admin() || can_access('file-manager') || can_access('notice-signing') || can_access('my-signature')): ?>
    <button class="nav-group-toggle <?= $is_internal_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-internal"
            aria-expanded="<?= $is_internal_active ? 'true' : 'false' ?>">
        <i class="fas fa-building grp-icon" style="color:#8e44ad"></i>
        Internal
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_internal_active ? 'show' : '' ?>" id="grp-internal">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('file-manager')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/file-manager/index.php"
                   class="<?= strpos($current_path, '/file-manager/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-folder-open"></i> File Manager
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('notice-signing')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/notice-signing/index.php"
                   class="<?= strpos($current_path, '/notice-signing/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-signature"></i> Notice Signing
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/my-signature/index.php"
                   class="<?= strpos($current_path, '/my-signature/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-pen-nib"></i> My Signature
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Administration ── -->
    <?php if (is_super_admin() || can_access('users') || can_access('modules') || can_access('access') || can_access('email-templates')): ?>
    <button class="nav-group-toggle <?= $is_admin_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-admin"
            aria-expanded="<?= $is_admin_active ? 'true' : 'false' ?>">
        <i class="fas fa-cogs grp-icon" style="color:#e74c3c"></i>
        Administration
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_admin_active ? 'show' : '' ?>" id="grp-admin">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('user-groups')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/user-groups/index.php"
                   class="<?= strpos($current_path, '/user-groups/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-layer-group"></i> User Groups
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('users')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/users/index.php"
                   class="<?= strpos($current_path, '/users/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Users
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('modules')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/modules/index.php"
                   class="<?= strpos($current_path, '/modules/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cubes"></i> Modules
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('access')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/access/index.php"
                   class="<?= strpos($current_path, '/access/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Module Access
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('email-templates')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/email-templates/index.php"
                   class="<?= strpos($current_path, '/email-templates/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-envelope-open-text"></i> Email Templates
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/change-log/index.php"
                   class="<?= strpos($current_path, '/change-log/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-history"></i> Change Log
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── My Profile (non-super-admin faculty) ── -->
    <?php if (!is_super_admin() && can_access('faculty-profile')): ?>
    <ul class="nav flex-column mt-2">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/faculty-profiles/my-profile.php"
               class="<?= strpos($current_path, '/faculty-profiles/') !== false ? 'active' : '' ?>">
                <i class="fas fa-id-card"></i> My Faculty Profile
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <!-- ── My Staff Profile (non-super-admin general staff) ── -->
    <?php if (!is_super_admin() && can_access('staff-profile') && !can_access('staff-departments')): ?>
    <ul class="nav flex-column mt-2">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/staff-profiles/my-profile.php"
               class="<?= strpos($current_path, '/staff-profiles/my-profile') !== false ? 'active' : '' ?>">
                <i class="fas fa-id-badge"></i> My Staff Profile
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <div style="padding: 20px; margin-top: auto;">
        <a href="<?= APP_URL ?>/logout.php"
           style="display:flex;align-items:center;gap:8px;color:#e74c3c;font-size:.85rem;text-decoration:none;">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</nav>

<!-- ═══════════════════════ MAIN ═══════════════════════ -->
<div id="main-wrapper">

    <!-- Topbar -->
    <header id="topbar">
        <button class="toggle-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="fas fa-bars"></i>
        </button>
        <div class="page-heading"><?= h($page_title) ?></div>

        <div class="user-menu dropdown">
            <button class="dropdown-toggle" data-bs-toggle="dropdown">
                <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? 'A', 0, 1)) ?></div>
                <span><?= h($user['full_name'] ?? 'Admin') ?></span>
                <i class="fas fa-chevron-down" style="font-size:.7rem;opacity:.6"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius:12px;font-size:.875rem;">
                <li><a class="dropdown-item" href="<?= APP_URL ?>/users/edit.php?id=<?= $user['id'] ?>">
                    <i class="fas fa-user-edit me-2 text-muted"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/my-signature/index.php">
                    <i class="fas fa-pen-nib me-2 text-muted"></i>My Signature</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Flash messages -->
    <div style="padding: 0 28px; margin-top: 16px;">
    <?php
    foreach (['success','error','warning','info'] as $t):
        $msg = flash_get($t);
        if ($msg):
    ?>
    <div class="alert alert-<?= $t === 'error' ? 'danger' : h($t) ?> alert-dismissible fade show" role="alert">
        <?= strip_tags($msg, '<strong><em><b><i>') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; endforeach; ?>
    </div>

    <!-- Content starts here -->
    <main id="content">
