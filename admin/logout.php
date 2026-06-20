<?php
require_once __DIR__ . '/includes/auth.php';
session_destroy();
redirect(APP_URL . '/login.php');
