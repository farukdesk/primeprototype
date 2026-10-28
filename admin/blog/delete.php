<?php
/**
 * Blog — delete post (POST only).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/helpers.php';

auth_check();
blog_ensure_schema();
require_access('blog', 'can_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/blog/index.php');
}
csrf_check();

$id   = (int)($_POST['id'] ?? 0);
$post = $id > 0 ? blog_find_post($id) : null;

if (!$post) {
    flash_set('error', 'Blog post not found.');
    redirect(APP_URL . '/blog/index.php');
}

blog_delete_image($post['featured_image']);
db()->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]); // comments cascade

flash_set('success', 'Blog post <strong>' . h($post['title']) . '</strong> deleted.');
redirect(APP_URL . '/blog/index.php');
