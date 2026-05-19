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
    $is_course_offer_active = strpos($current_path, '/course-offer/') !== false;
    $is_spring_result_active = strpos($current_path, '/spring-result/') !== false;
    $is_tabulation_checker_active = strpos($current_path, '/tabulation-checker/') !== false;
    $is_academic_active = strpos($current_path, '/departments/') !== false || strpos($current_path, '/faculty-profiles/') !== false || strpos($current_path, '/students/') !== false || strpos($current_path, '/course-curriculum/') !== false || strpos($current_path, '/clubs/') !== false || strpos($current_path, '/staff-profiles/') !== false || strpos($current_path, '/results/') !== false || strpos($current_path, '/student-verification/') !== false || strpos($current_path, '/cert-verifiers/') !== false || $is_course_offer_active || $is_spring_result_active || $is_tabulation_checker_active;
    $is_comms_active    = strpos($current_path, '/contact/') !== false || strpos($current_path, '/support-tickets/') !== false || strpos($current_path, '/knowledge-base/') !== false || strpos($current_path, '/broadcast/') !== false;
    $is_leads_active    = strpos($current_path, '/leads/') !== false;
    $is_admissions_active = strpos($current_path, '/admissions/') !== false;
    $is_gallery_active       = strpos($current_path, '/gallery/') !== false;
    $is_jobs_active          = strpos($current_path, '/jobs/') !== false;
    $is_library_active       = strpos($current_path, '/library/') !== false;
    $is_course_fees_active   = strpos($current_path, '/course-fees/') !== false;
    $is_governing_body_active = strpos($current_path, '/governing-body/') !== false;
    $is_office_of_vc_active         = strpos($current_path, '/office-of-vc/') !== false;
    $is_office_of_chairman_active   = strpos($current_path, '/office-of-chairman/') !== false;
    $is_office_of_pro_vc_active     = strpos($current_path, '/office-of-pro-vc/') !== false;
    $is_office_of_treasurer_active  = strpos($current_path, '/office-of-treasurer/') !== false;
    $is_office_of_registrar_active  = strpos($current_path, '/office-of-registrar/') !== false;
    $is_office_of_coe_active        = strpos($current_path, '/office-of-coe/') !== false;
    $is_office_of_it_active              = strpos($current_path, '/office-of-it/') !== false;
    $is_office_of_accounts_audit_active  = strpos($current_path, '/office-of-accounts-audit/') !== false;
    $is_office_of_estate_store_active    = strpos($current_path, '/office-of-estate-store/') !== false;
    $is_students_affairs_active          = strpos($current_path, '/students-affairs/') !== false;
    $is_office_of_crhp_active            = strpos($current_path, '/office-of-crhp/') !== false;
    $is_office_of_proctor_active         = strpos($current_path, '/office-of-proctor/') !== false;
    $is_law_legal_active            = strpos($current_path, '/law-legal/') !== false;
    $is_offices_active = $is_office_of_vc_active || $is_office_of_chairman_active || $is_office_of_pro_vc_active
                      || $is_office_of_treasurer_active || $is_office_of_registrar_active || $is_office_of_coe_active
                      || $is_office_of_it_active || $is_office_of_accounts_audit_active || $is_office_of_estate_store_active
                      || $is_students_affairs_active || $is_office_of_crhp_active || $is_office_of_proctor_active
                      || $is_law_legal_active;
    $is_admin_active    = strpos($current_path, '/users/') !== false || strpos($current_path, '/user-groups/') !== false
                       || strpos($current_path, '/modules/') !== false || strpos($current_path, '/access/') !== false
                       || strpos($current_path, '/email-templates/') !== false || strpos($current_path, '/change-log/') !== false;
    $is_internal_active = strpos($current_path, '/file-manager/') !== false || strpos($current_path, '/notice-signing/') !== false || strpos($current_path, '/my-signature/') !== false;
    $is_seo_active      = strpos($current_path, '/seo/') !== false;
    $is_accounting_active = strpos($current_path, '/accounting/') !== false;
    $is_scholarship_active = strpos($current_path, '/scholarship/') !== false;
    $is_fee_package_active = strpos($current_path, '/student-accounts/') !== false;
    $is_medical_active = strpos($current_path, '/medical-center/') !== false;
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

    <!-- ── University Offices ── -->
    <?php if (is_super_admin() || can_access('office-of-vc') || can_access('office-of-chairman') || can_access('office-of-pro-vc') || can_access('office-of-treasurer') || can_access('office-of-registrar') || can_access('office-of-coe') || can_access('office-of-it') || can_access('office-of-accounts-audit') || can_access('office-of-estate-store') || can_access('students-affairs') || can_access('office-of-crhp') || can_access('office-of-proctor') || can_access('law-legal')): ?>
    <button class="nav-group-toggle <?= $is_offices_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-offices"
            aria-expanded="<?= $is_offices_active ? 'true' : 'false' ?>">
        <i class="fas fa-landmark grp-icon" style="color:#3498db"></i>
        University Offices
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_offices_active ? 'show' : '' ?>" id="grp-offices">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('office-of-vc')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-vc/index.php"
                   class="<?= $is_office_of_vc_active ? 'active' : '' ?>">
                    <i class="fas fa-user-tie"></i> Office of Vice Chancellor
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-chairman')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-chairman/index.php"
                   class="<?= $is_office_of_chairman_active ? 'active' : '' ?>">
                    <i class="fas fa-gavel"></i> Office of Chairman
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-pro-vc')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-pro-vc/index.php"
                   class="<?= $is_office_of_pro_vc_active ? 'active' : '' ?>">
                    <i class="fas fa-user-graduate"></i> Office of Pro VC
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-treasurer')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-treasurer/index.php"
                   class="<?= $is_office_of_treasurer_active ? 'active' : '' ?>">
                    <i class="fas fa-coins"></i> Office of Treasurer
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-registrar')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-registrar/index.php"
                   class="<?= $is_office_of_registrar_active ? 'active' : '' ?>">
                    <i class="fas fa-stamp"></i> Office of Registrar
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-coe')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-coe/index.php"
                   class="<?= $is_office_of_coe_active ? 'active' : '' ?>">
                    <i class="fas fa-scroll"></i> Controller of Examinations
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-it')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-it/index.php"
                   class="<?= $is_office_of_it_active ? 'active' : '' ?>">
                    <i class="fas fa-laptop-code"></i> Office of IT
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-accounts-audit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-accounts-audit/index.php"
                   class="<?= $is_office_of_accounts_audit_active ? 'active' : '' ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Office of Accounts &amp; Audit
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-estate-store')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-estate-store/index.php"
                   class="<?= $is_office_of_estate_store_active ? 'active' : '' ?>">
                    <i class="fas fa-building"></i> Office of Estate &amp; Store
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('students-affairs')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/students-affairs/index.php"
                   class="<?= $is_students_affairs_active ? 'active' : '' ?>">
                    <i class="fas fa-user-graduate"></i> Students&#039; Affairs
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-crhp')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-crhp/index.php"
                   class="<?= $is_office_of_crhp_active ? 'active' : '' ?>">
                    <i class="fas fa-flask"></i> Office of the CRHP
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('office-of-proctor')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/office-of-proctor/index.php"
                   class="<?= $is_office_of_proctor_active ? 'active' : '' ?>">
                    <i class="fas fa-user-shield"></i> Office of the Proctor
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('law-legal')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/law-legal/index.php"
                   class="<?= $is_law_legal_active ? 'active' : '' ?>">
                    <i class="fas fa-gavel"></i> Law &amp; Legal Affairs
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Academic ── -->
    <?php if (is_super_admin() || can_access('departments') || can_access('students') || can_access('course-curriculum') || can_access('course-offer') || can_access('clubs') || can_access('staff-departments') || can_access('results') || can_access('spring-result')): ?>
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
                   class="<?= (strpos($current_path, '/faculty-profiles/') !== false && strpos($current_path, '/faculty-profiles/pending-subjects') === false) ? 'active' : '' ?>">
                    <i class="fas fa-chalkboard-teacher"></i> Faculty Profiles
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/faculty-profiles/pending-subjects.php"
                   class="<?= strpos($current_path, '/faculty-profiles/pending-subjects') !== false ? 'active' : '' ?>">
                    <i class="fas fa-book-open"></i> Subject Approvals
                    <?php
                    try {
                        $_sa_ps_cnt = (int)db()->query("SELECT COUNT(*) FROM faculty_subject_assignments WHERE status='pending'")->fetchColumn();
                    } catch (Throwable $_e) { $_sa_ps_cnt = 0; }
                    if ($_sa_ps_cnt > 0): ?>
                    <span class="badge bg-danger ms-auto" style="font-size:.65rem;"><?= $_sa_ps_cnt ?></span>
                    <?php endif; ?>
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
            <?php if (is_super_admin() || can_access('student-verification')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/student-verification/index.php"
                   class="<?= strpos($current_path, '/student-verification/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Student Verification
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('cert-verifiers')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/cert-verifiers/index.php"
                   class="<?= strpos($current_path, '/cert-verifiers/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-search-plus"></i> Cert. Verifiers
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
            <?php if (is_super_admin() || can_access('course-offer')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/course-offer/index.php"
                   class="<?= $is_course_offer_active ? 'active' : '' ?>">
                    <i class="fas fa-chalkboard"></i> Course Offer
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('results')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/results/index.php"
                   class="<?= strpos($current_path, '/results/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Results
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('results-entry')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/results/mark-entry.php"
                   class="<?= basename($current_path) === 'mark-entry.php' ? 'active' : '' ?>" style="padding-left:2.2rem;">
                    <i class="fas fa-pen-nib"></i> Mark Entry
                </a>
            </li>
            <?php endif; ?>
            <?php
            // Workflow queue: shown if user is an approver in any active chain
            // Count pending sheets for this user (chain-aware, no hard-coded roles)
            require_once __DIR__ . '/../results/workflow-helpers.php';
            try {
                $_wf_queue_count = wf_has_approver_role() ? count(wf_get_approver_queue()) : 0;
            } catch (Throwable $_e) { $_wf_queue_count = 0; }
            if ($_wf_queue_count > 0 || wf_has_approver_role()):
            ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/results/workflow-queue.php"
                   class="<?= in_array(basename($current_path), ['workflow-queue.php','workflow-review.php'], true) ? 'active' : '' ?>" style="padding-left:2.2rem;">
                    <i class="fas fa-tasks"></i> Workflow Queue
                    <?php if ($_wf_queue_count > 0): ?>
                    <span class="badge bg-primary ms-auto" style="font-size:.65rem;"><?= $_wf_queue_count ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('results-chains')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/results/chains/index.php"
                   class="<?= strpos($current_path, '/results/chains/') !== false ? 'active' : '' ?>" style="padding-left:2.2rem;">
                    <i class="fas fa-sitemap"></i> Workflow Chains
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('spring-result')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/spring-result/index.php"
                   class="<?= $is_spring_result_active ? 'active' : '' ?>">
                    <i class="fas fa-poll"></i> Spring Result
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('tabulation-checker')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/tabulation-checker/index.php"
                   class="<?= $is_tabulation_checker_active ? 'active' : '' ?>">
                    <i class="fas fa-check-double"></i> Tabulation Checker
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
                   class="<?= (strpos($current_path, '/admissions/') !== false && strpos($current_path, '/create') === false && strpos($current_path, '/settings') === false && strpos($current_path, '/form-sale') === false) ? 'active' : '' ?>">
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
            <li class="nav-item">
                <a href="<?= APP_URL ?>/admissions/form-sale-index.php"
                   class="<?= strpos($current_path, '/admissions/form-sale') !== false ? 'active' : '' ?>">
                    <i class="fas fa-receipt"></i> Form Sale
                </a>
            </li>
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
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/campus-visits.php"
                   class="<?= strpos($current_path, '/leads/campus-visits') !== false ? 'active' : '' ?>">
                    <i class="fas fa-university"></i> Campus Visits
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/leads/call-logs.php"
                   class="<?= strpos($current_path, '/leads/call-logs') !== false ? 'active' : '' ?>">
                    <i class="fas fa-phone-alt"></i> Call Logs
                </a>
            </li>
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

    <!-- ── Gallery ── -->
    <?php if (is_super_admin() || can_access('gallery')): ?>
    <?php
    try {
        $_gal_pending = (int)db()->query("SELECT COUNT(*) FROM gallery_photos WHERE status='pending'")->fetchColumn();
    } catch (Throwable $_gpe) { $_gal_pending = 0; }
    ?>
    <button class="nav-group-toggle <?= $is_gallery_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-gallery"
            aria-expanded="<?= $is_gallery_active ? 'true' : 'false' ?>">
        <i class="fas fa-images grp-icon" style="color:#a78bfa"></i>
        Gallery
        <?php if ($_gal_pending > 0): ?>
        <span class="badge bg-warning text-dark ms-1" style="font-size:.6rem;"><?= $_gal_pending ?></span>
        <?php endif; ?>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_gallery_active ? 'show' : '' ?>" id="grp-gallery">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/gallery/index.php"
                   class="<?= (strpos($current_path, '/gallery/') !== false && strpos($current_path, '/create') === false && strpos($current_path, '/photo-approve') === false) ? 'active' : '' ?>">
                    <i class="fas fa-th"></i> All Albums
                </a>
            </li>
            <?php if (is_super_admin() || can_access('gallery', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/gallery/create.php"
                   class="<?= strpos($current_path, '/gallery/create') !== false ? 'active' : '' ?>">
                    <i class="fas fa-plus"></i> New Album
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('gallery', 'can_edit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/gallery/photo-approve.php"
                   class="<?= strpos($current_path, '/gallery/photo-approve') !== false ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> Pending Approvals
                    <?php if ($_gal_pending > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto" style="font-size:.6rem;"><?= $_gal_pending ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= SITE_URL ?>/gallery.php" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Public Page
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

    <!-- ── Accounting ── -->
    <?php if (is_super_admin() || can_access('accounting') || can_access('accounting-coa') || can_access('accounting-reports')): ?>
    <button class="nav-group-toggle <?= $is_accounting_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-accounting"
            aria-expanded="<?= $is_accounting_active ? 'true' : 'false' ?>">
        <i class="fas fa-coins grp-icon" style="color:#f59e0b"></i>
        Accounting
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_accounting_active ? 'show' : '' ?>" id="grp-accounting">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('accounting')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/index.php"
                   class="<?= (strpos($current_path, '/accounting/') !== false && strpos($current_path, '/collect-payment') === false && strpos($current_path, '/add-expense') === false && strpos($current_path, '/transfer-money') === false && strpos($current_path, '/voucher') === false && strpos($current_path, '/chart-of-accounts') === false && strpos($current_path, '/reports/') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt"></i> Overview
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('accounting', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/collect-payment.php"
                   class="<?= strpos($current_path, '/accounting/collect-payment') !== false ? 'active' : '' ?>">
                    <i class="fas fa-hand-holding-usd text-success"></i> Collect Payment
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/add-expense.php"
                   class="<?= strpos($current_path, '/accounting/add-expense') !== false ? 'active' : '' ?>">
                    <i class="fas fa-receipt text-danger"></i> Add Expense
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/transfer-money.php"
                   class="<?= strpos($current_path, '/accounting/transfer-money') !== false ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt text-info"></i> Transfer Money
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('accounting')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/vouchers.php"
                   class="<?= strpos($current_path, '/accounting/voucher') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-invoice"></i> All Vouchers
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('accounting-coa')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/chart-of-accounts.php"
                   class="<?= strpos($current_path, '/accounting/chart-of-accounts') !== false || strpos($current_path, '/accounting/account-') !== false ? 'active' : '' ?>">
                    <i class="fas fa-sitemap"></i> Chart of Accounts
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('accounting-reports')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/trial-balance.php"
                   class="<?= strpos($current_path, '/accounting/reports/trial-balance') !== false ? 'active' : '' ?>">
                    <i class="fas fa-balance-scale"></i> Trial Balance
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/income-statement.php"
                   class="<?= strpos($current_path, '/accounting/reports/income-statement') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i> Income Statement
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/balance-sheet.php"
                   class="<?= strpos($current_path, '/accounting/reports/balance-sheet') !== false ? 'active' : '' ?>">
                    <i class="fas fa-building"></i> Balance Sheet
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/cash-flow.php"
                   class="<?= strpos($current_path, '/accounting/reports/cash-flow') !== false ? 'active' : '' ?>">
                    <i class="fas fa-water"></i> Cash Flow
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/ledger.php"
                   class="<?= strpos($current_path, '/accounting/reports/ledger') !== false ? 'active' : '' ?>">
                    <i class="fas fa-book"></i> Ledger
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/cash-book.php"
                   class="<?= strpos($current_path, '/accounting/reports/cash-book') !== false ? 'active' : '' ?>">
                    <i class="fas fa-money-bill-wave"></i> Cash Book
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/reports/bank-book.php"
                   class="<?= strpos($current_path, '/accounting/reports/bank-book') !== false ? 'active' : '' ?>">
                    <i class="fas fa-university"></i> Bank Book
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/accounting/settings.php"
                   class="<?= strpos($current_path, '/accounting/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Scholarship ── -->
    <?php if (is_super_admin() || can_access('scholarship') || can_access('scholarship-policies')): ?>
    <button class="nav-group-toggle <?= $is_scholarship_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-scholarship"
            aria-expanded="<?= $is_scholarship_active ? 'true' : 'false' ?>">
        <i class="fas fa-graduation-cap grp-icon" style="color:#10b981"></i>
        Scholarship
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_scholarship_active ? 'show' : '' ?>" id="grp-scholarship">
        <ul class="nav flex-column grp-items">
            <?php if (is_super_admin() || can_access('scholarship')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/scholarship/index.php"
                   class="<?= (strpos($current_path, '/scholarship/') !== false && strpos($current_path, '/policies') === false && strpos($current_path, '/policy-') === false && strpos($current_path, '/run-merit') === false && strpos($current_path, '/settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-trophy"></i> Awards
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('scholarship-policies')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/scholarship/policies.php"
                   class="<?= (strpos($current_path, '/scholarship/policies') !== false || strpos($current_path, '/scholarship/policy-') !== false) ? 'active' : '' ?>">
                    <i class="fas fa-file-alt"></i> Policies
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin() || can_access('scholarship')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/scholarship/run-merit.php"
                   class="<?= strpos($current_path, '/scholarship/run-merit') !== false ? 'active' : '' ?>">
                    <i class="fas fa-play-circle"></i> Run Merit
                </a>
            </li>
            <?php endif; ?>
            <?php if (is_super_admin()): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/scholarship/settings.php"
                   class="<?= strpos($current_path, '/scholarship/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Student Accounts ── -->
    <?php if (is_super_admin() || can_access('student-accounts')): ?>
    <button class="nav-group-toggle <?= $is_fee_package_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-fee-package"
            aria-expanded="<?= $is_fee_package_active ? 'true' : 'false' ?>">
        <i class="fas fa-file-invoice-dollar grp-icon" style="color:#10b981"></i>
        Student Accounts
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_fee_package_active ? 'show' : '' ?>" id="grp-fee-package">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/student-accounts/index.php"
                   class="<?= ($is_fee_package_active && strpos($current_path, '/create') === false) ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> All Accounts
                </a>
            </li>
            <?php if (is_super_admin() || can_access('student-accounts', 'can_create')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/student-accounts/create.php"
                   class="<?= strpos($current_path, '/student-accounts/create') !== false ? 'active' : '' ?>">
                    <i class="fas fa-plus"></i> Assign Package
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── Medical Center ── -->
    <?php if (is_super_admin() || can_access('medical-center')): ?>
    <button class="nav-group-toggle <?= $is_medical_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-medical"
            aria-expanded="<?= $is_medical_active ? 'true' : 'false' ?>">
        <i class="fas fa-hospital grp-icon" style="color:#20b2aa"></i>
        <span>Medical Center</span>
        <i class="fas fa-chevron-down toggle-icon ms-auto"></i>
    </button>
    <div class="collapse <?= $is_medical_active ? 'show' : '' ?>" id="grp-medical">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/medical-center/index.php"
                   class="<?= ($is_medical_active && strpos($current_path, 'appointment') === false && strpos($current_path, 'prescription') === false && strpos($current_path, 'medicine') === false && strpos($current_path, 'settings') === false) ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/medical-center/appointments.php"
                   class="<?= strpos($current_path, '/medical-center/appointment') !== false ? 'active' : '' ?>">
                    <i class="fas fa-calendar-check"></i> Appointments
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/medical-center/prescriptions.php"
                   class="<?= strpos($current_path, '/medical-center/prescription') !== false ? 'active' : '' ?>">
                    <i class="fas fa-file-medical"></i> Prescriptions
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/medical-center/medicines.php"
                   class="<?= strpos($current_path, '/medical-center/medicine') !== false ? 'active' : '' ?>">
                    <i class="fas fa-pills"></i> Medicine Stock
                </a>
            </li>
            <?php if (is_super_admin() || can_access('medical-center', 'can_edit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/medical-center/settings.php"
                   class="<?= strpos($current_path, '/medical-center/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ── SEO Manager ── -->
    <?php if (is_super_admin() || can_access('seo')): ?>
    <button class="nav-group-toggle <?= $is_seo_active ? '' : 'collapsed' ?>"
            data-bs-toggle="collapse" data-bs-target="#grp-seo"
            aria-expanded="<?= $is_seo_active ? 'true' : 'false' ?>">
        <i class="fas fa-search-plus grp-icon" style="color:#10b981"></i>
        SEO Manager
        <i class="fas fa-chevron-down toggle-icon"></i>
    </button>
    <div class="collapse <?= $is_seo_active ? 'show' : '' ?>" id="grp-seo">
        <ul class="nav flex-column grp-items">
            <li class="nav-item">
                <a href="<?= APP_URL ?>/seo/index.php"
                   class="<?= (strpos($current_path, '/seo/index') !== false) ? 'active' : '' ?>">
                    <i class="fas fa-list-alt"></i> All Pages
                </a>
            </li>
            <?php if (is_super_admin() || can_access('seo-settings', 'can_edit')): ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/seo/settings.php"
                   class="<?= strpos($current_path, '/seo/settings') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> Settings
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="<?= APP_URL ?>/seo/sitemap-preview.php"
                   class="<?= strpos($current_path, '/seo/sitemap-preview') !== false ? 'active' : '' ?>">
                    <i class="fas fa-sitemap"></i> Sitemap Preview
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= SITE_URL ?>/sitemap.php" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Live Sitemap
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= SITE_URL ?>/robots.php" target="_blank">
                    <i class="fas fa-robot"></i> Robots.txt
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
               class="<?= strpos($current_path, '/faculty-profiles/my-profile') !== false ? 'active' : '' ?>">
                <i class="fas fa-id-card"></i> My Faculty Profile
            </a>
        </li>
        <?php
        // Show Subject Approvals link for faculty members who are Heads of Department
        $_hod_link_user = auth_user();
        $_is_hod_nav    = false;
        $_hod_nav_cnt   = 0;
        if ($_hod_link_user) {
            try {
                $_hod_nav_st = db()->prepare(
                    "SELECT dept_id FROM dept_faculty WHERE user_id = ? AND is_head = 1 AND is_active = 1"
                );
                $_hod_nav_st->execute([$_hod_link_user['id']]);
                $_hod_nav_depts = $_hod_nav_st->fetchAll(PDO::FETCH_COLUMN);
                $_is_hod_nav    = !empty($_hod_nav_depts);
                if ($_is_hod_nav) {
                    $ph = implode(',', array_fill(0, count($_hod_nav_depts), '?'));
                    $_hod_cnt_st = db()->prepare(
                        "SELECT COUNT(*) FROM faculty_subject_assignments fsa
                           JOIN course_curriculum cc ON cc.id = fsa.course_id
                           JOIN dept_academic_programs dap ON dap.id = cc.program_id
                          WHERE fsa.status = 'pending' AND dap.dept_id IN ($ph)"
                    );
                    $_hod_cnt_st->execute($_hod_nav_depts);
                    $_hod_nav_cnt = (int)$_hod_cnt_st->fetchColumn();
                }
            } catch (Throwable $_hod_nav_ex) {}
        }
        if ($_is_hod_nav): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/faculty-profiles/pending-subjects.php"
               class="<?= strpos($current_path, '/faculty-profiles/pending-subjects') !== false ? 'active' : '' ?>">
                <i class="fas fa-book-open"></i> Subject Approvals
                <?php if ($_hod_nav_cnt > 0): ?>
                <span class="badge bg-danger ms-auto" style="font-size:.65rem;"><?= $_hod_nav_cnt ?></span>
                <?php endif; ?>
            </a>
        </li>
        <?php endif; ?>
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
