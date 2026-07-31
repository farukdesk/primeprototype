<?php
require_once __DIR__ . '/includes/auth.php';
$timeout = isset($_GET['timeout']);
remember_clear();
session_unset();
session_destroy();
redirect(APP_URL . '/login.php' . ($timeout ? '?timeout=1' : ''));
