<?php
/**
 * Public PDF endpoint for published journal articles.
 * Serves the full-text PDF (used as citation_pdf_url for Google Scholar)
 * and counts downloads.
 */
require_once __DIR__ . '/includes/config.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    exit('Not found.');
}

$article = null;
try {
    $db = front_db();
    if ($db) {
        $stmt = $db->prepare(
            "SELECT a.id, a.title, a.pdf_file
             FROM journal_articles a
             JOIN journal_issues   i ON i.id = a.issue_id
             JOIN journal_volumes  v ON v.id = i.volume_id
             JOIN journal_journals j ON j.id = v.journal_id
             WHERE a.slug = ? AND a.status = 'published' AND j.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        $article = $stmt->fetch();
    }
} catch (Throwable $e) {
}

if (!$article || empty($article['pdf_file'])) {
    http_response_code(404);
    exit('PDF not found.');
}

$path = __DIR__ . '/admin/uploads/journal/pdfs/' . basename($article['pdf_file']);
if (!is_file($path)) {
    http_response_code(404);
    exit('PDF not found.');
}

try {
    $db->prepare('UPDATE journal_articles SET downloads = downloads + 1 WHERE id = ?')->execute([$article['id']]);
} catch (Throwable $e) {
}

$nice = preg_replace('/[^A-Za-z0-9 _.-]/', '', (string)$article['title']);
$nice = trim(mb_substr($nice, 0, 120)) ?: 'article';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $nice . '.pdf"');
header('X-Robots-Tag: all');
readfile($path);
exit;
