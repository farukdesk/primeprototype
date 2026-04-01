<?php
/**
 * Shared helper functions for the Contact module.
 */

/**
 * Fetch a single contact message by ID, or redirect on failure.
 */
function contact_get_message(int $id): array {
    if ($id <= 0) {
        flash_set('error', 'Message not found.');
        redirect(APP_URL . '/contact/index.php');
    }
    $stmt = db()->prepare('SELECT * FROM contact_messages WHERE id = ?');
    $stmt->execute([$id]);
    $msg = $stmt->fetch();
    if (!$msg) {
        flash_set('error', 'Message not found.');
        redirect(APP_URL . '/contact/index.php');
    }
    return $msg;
}
