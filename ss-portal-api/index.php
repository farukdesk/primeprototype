<?php
require_once __DIR__ . '/includes/auth.php';

ssp_redirect(ssp_current_user() ? 'dashboard.php' : 'login.php');
