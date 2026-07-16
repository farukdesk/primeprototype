<?php
require_once __DIR__ . '/../includes/auth.php';
auth_check();

// Profile self-service has been consolidated into the user account editor so staff
// can update their full profile from a single place. Send users to their own
// users/edit.php page (which already permits editing one's own account).
redirect(APP_URL . '/users/edit.php?id=' . (int)auth_user()['id']);
