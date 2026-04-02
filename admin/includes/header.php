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

    <?php if (is_super_admin() || can_access('dashboard')): ?>
    <p class="nav-label">Main</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/index.php"
               class="<?= preg_match('#/admin/index\.php$#', $_SERVER['PHP_SELF']) ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('users')): ?>
    <p class="nav-label">User Management</p>
    <ul class="nav flex-column">
        <?php if (is_super_admin() || can_access('user-groups')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/user-groups/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/user-groups/') !== false ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> User Groups
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('users')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/users/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/users/') !== false ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Users
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('modules') || can_access('access') || can_access('email-templates')): ?>
    <p class="nav-label">System</p>
    <ul class="nav flex-column">
        <?php if (is_super_admin() || can_access('modules')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/modules/') !== false ? 'active' : '' ?>">
                <i class="fas fa-cubes"></i> Modules
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('access')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/access/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/access/') !== false ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i> Module Access
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('email-templates')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/email-templates/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/email-templates/') !== false ? 'active' : '' ?>">
                <i class="fas fa-envelope-open-text"></i> Email Templates
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/change-log/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/change-log/') !== false ? 'active' : '' ?>">
                <i class="fas fa-history"></i> Change Log
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin()): ?>
    <p class="nav-label">Departments</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/departments/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/departments/') !== false ? 'active' : '' ?>">
                <i class="fas fa-building-columns"></i> Departments
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin()): ?>
    <p class="nav-label">CMS</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/header/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/header/') !== false ? 'active' : '' ?>">
                <i class="fas fa-heading"></i> Header Settings
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/menus/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/menus/') !== false ? 'active' : '' ?>">
                <i class="fas fa-bars"></i> Navigation Menus
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/news/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/news/') !== false ? 'active' : '' ?>">
                <i class="fas fa-newspaper"></i> Latest News
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/sliders/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/sliders/') !== false ? 'active' : '' ?>">
                <i class="fas fa-images"></i> Sliders
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/programs/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/programs/') !== false ? 'active' : '' ?>">
                <i class="fas fa-graduation-cap"></i> Programs
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/about/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/about/') !== false ? 'active' : '' ?>">
                <i class="fas fa-info-circle"></i> About Section
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/campus/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/campus/') !== false ? 'active' : '' ?>">
                <i class="fas fa-university"></i> Campus Life
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/cms/alumni/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/cms/alumni/') !== false ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i> Notable Alumni
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/homepage/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/homepage/') !== false ? 'active' : '' ?>">
                <i class="fas fa-home"></i> Homepage (Stats &amp; Reviews)
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('homepage')): ?>
    <p class="nav-label">Pages</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/pages/index.php?category=general"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/pages/') !== false && ($_GET['category'] ?? '') === 'general') ? 'active' : '' ?>">
                <i class="fas fa-columns"></i> General Pages
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/pages/index.php?category=profile"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/pages/') !== false && ($_GET['category'] ?? '') === 'profile') ? 'active' : '' ?>">
                <i class="fas fa-id-card"></i> Profile Pages
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/pages/index.php?category=policy"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/pages/') !== false && ($_GET['category'] ?? '') === 'policy') ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i> Policy Pages
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin()): ?>
    <p class="nav-label">Faculty</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/faculty-profiles/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/faculty-profiles/') !== false ? 'active' : '' ?>">
                <i class="fas fa-id-card"></i> Faculty Profiles
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (!is_super_admin() && can_access('faculty-profile')): ?>
    <p class="nav-label">My Profile</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/faculty-profiles/my-profile.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/faculty-profiles/') !== false ? 'active' : '' ?>">
                <i class="fas fa-id-card"></i> My Faculty Profile
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('support-tickets')): ?>
    <p class="nav-label">IT Support</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/support-tickets/index.php"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/support-tickets/') !== false && strpos($_SERVER['PHP_SELF'], '/reports') === false) ? 'active' : '' ?>">
                <i class="fas fa-ticket-alt"></i> My Tickets
            </a>
        </li>
        <?php if (is_super_admin()): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/support-tickets/reports.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/support-tickets/reports') !== false ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('knowledge-base')): ?>
    <p class="nav-label">Knowledge Base</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/knowledge-base/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/knowledge-base/') !== false ? 'active' : '' ?>">
                <i class="fas fa-book-open"></i> Knowledge Base
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('students')): ?>
    <p class="nav-label">Students</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/students/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/students/') !== false ? 'active' : '' ?>">
                <i class="fas fa-user-graduate"></i> Student Management
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('jobs')): ?>
    <p class="nav-label">Jobs</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/jobs/index.php"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/jobs/') !== false && strpos($_SERVER['PHP_SELF'], '/jobs/application') === false) ? 'active' : '' ?>">
                <i class="fas fa-briefcase"></i> Job Postings
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/jobs/applications.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/jobs/application') !== false ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i> Applications
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('contact')): ?>
    <p class="nav-label">Contact</p>
    <ul class="nav flex-column">
        <li class="nav-item">
            <a href="<?= APP_URL ?>/contact/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/contact/') !== false ? 'active' : '' ?>">
                <i class="fas fa-envelope-open-text"></i> Contact Messages
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <?php if (is_super_admin() || can_access('library') || can_access('library-circulation') || can_access('library-digital')): ?>
    <p class="nav-label">Library</p>
    <ul class="nav flex-column">
        <?php if (is_super_admin() || can_access('library')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/index.php"
               class="<?= (strpos($_SERVER['PHP_SELF'], '/library/') !== false && strpos($_SERVER['PHP_SELF'], '/library/circulation') === false && strpos($_SERVER['PHP_SELF'], '/library/digital') === false) ? 'active' : '' ?>">
                <i class="fas fa-book-open"></i> Library Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/books/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/books/') !== false ? 'active' : '' ?>">
                <i class="fas fa-books"></i> Books
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/members/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/members/') !== false ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Members
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('library-circulation')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/circulation/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/circulation/') !== false ? 'active' : '' ?>">
                <i class="fas fa-exchange-alt"></i> Circulation
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('library-digital')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/digital/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/digital/') !== false ? 'active' : '' ?>">
                <i class="fas fa-file-pdf"></i> Digital Library
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin() || can_access('library')): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/fines/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/fines/') !== false ? 'active' : '' ?>">
                <i class="fas fa-money-bill-wave"></i> Fines
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/reports/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/reports/') !== false ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
        </li>
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/library/settings/index.php"
               class="<?= strpos($_SERVER['PHP_SELF'], '/library/settings/') !== false ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Library Settings
            </a>
        </li>
        <?php endif; ?>
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
