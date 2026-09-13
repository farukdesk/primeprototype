<?php
require_once __DIR__ . '/includes/auth.php';

if (!ssp_is_post()) {
    ssp_redirect('dashboard.php');
}
ssp_csrf_verify();
ssp_logout();
ssp_redirect('login.php');
