<?php
/**
 * Pending Changes Review Handler
 * Processes approve / reject decisions for queued edit and delete requests.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../change-log/helpers.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_URL . '/cms/pending-changes/index.php');
}
csrf_check();

$change_id   = (int)($_POST['id']     ?? 0);
$decision    = trim($_POST['action']  ?? '');    // 'approve' or 'reject'
$review_note = trim($_POST['review_note'] ?? '');
$reviewer    = auth_user();

if (!in_array($decision, ['approve', 'reject'], true)) {
    flash_set('error', 'Invalid action.');
    redirect(APP_URL . '/cms/pending-changes/index.php');
}

// Load pending change
$stmt = db()->prepare('SELECT * FROM cms_pending_changes WHERE id = ?');
$stmt->execute([$change_id]);
$change = $stmt->fetch();

if (!$change || $change['status'] !== 'pending') {
    flash_set('error', 'Change request not found or already reviewed.');
    redirect(APP_URL . '/cms/pending-changes/index.php');
}

$db     = db();
$module = $change['module'];   // 'news' or 'notice'
$action = $change['action'];   // 'EDIT' or 'DELETE'

/**
 * Generate a unique news slug, excluding a specific record id.
 */
function pch_unique_news_slug(string $base, int $exclude_id = 0): string {
    $slug = $base; $i = 2;
    $db   = db();
    while (true) {
        $st = $db->prepare('SELECT id FROM cms_news WHERE slug = ? AND id != ?');
        $st->execute([$slug, $exclude_id]);
        if (!$st->fetch()) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

/**
 * Convert a title to a URL-safe slug.
 */
function pch_make_slug(string $title): string {
    $s = mb_strtolower(trim($title));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'untitled';
}

if ($decision === 'reject') {
    // ── Reject ────────────────────────────────────────────────────────────
    // Read payload once (needed for both file cleanup and was_approved restore)
    $p = ($change['payload']) ? (json_decode($change['payload'], true) ?? []) : [];

    // Clean up any uploaded files that were staged in the payload
    if ($action === 'EDIT') {
        if ($module === 'news') {
            if (!empty($p['featured_image_new'])) {
                $path = UPLOAD_DIR . '/news/' . $p['featured_image_new'];
                if (file_exists($path)) @unlink($path);
            }
            foreach ($p['new_attachments'] ?? [] as $att) {
                $path = UPLOAD_DIR . '/news/' . $att['stored_name'];
                if (file_exists($path)) @unlink($path);
            }
        } elseif ($module === 'notice') {
            if (!empty($p['attachment_new'])) {
                $path = UPLOAD_DIR . '/notices/' . $p['attachment_new'];
                if (file_exists($path)) @unlink($path);
            }
        }
    }

    // Restore is_approved to its state before the pending request was created
    $was_approved = isset($p['was_approved']) ? (int)$p['was_approved'] : 1;
    if ($module === 'notice') {
        $db->prepare('UPDATE cms_notices SET is_approved=? WHERE id=?')
           ->execute([$was_approved, $change['record_id']]);
    } elseif ($module === 'news') {
        $db->prepare('UPDATE cms_news SET is_approved=? WHERE id=?')
           ->execute([$was_approved, $change['record_id']]);
    }

    $db->prepare(
        "UPDATE cms_pending_changes
         SET status='rejected', reviewed_by=?, reviewed_at=NOW(), review_note=?
         WHERE id=?"
    )->execute([$reviewer['id'], $review_note ?: null, $change_id]);

    $log_module = $module === 'news' ? 'cms-news' : 'cms-notice-board';
    log_change($log_module, $action === 'DELETE' ? 'DELETE' : 'UPDATE',
        $change['record_id'], $change['record_title'],
        null, null, null,
        ucfirst($action) . ' request rejected by ' . $reviewer['full_name']
            . ($review_note ? ': ' . $review_note : '.'));

    flash_set('success', ucfirst(strtolower($action)) . ' request for <strong>' . h($change['record_title']) . '</strong> rejected.');

} else {
    // ── Approve ───────────────────────────────────────────────────────────
    $log_module = $module === 'news' ? 'cms-news' : 'cms-notice-board';

    if ($action === 'DELETE') {
        // Apply the deletion
        if ($module === 'news') {
            $news = $db->prepare('SELECT * FROM cms_news WHERE id = ?');
            $news->execute([$change['record_id']]);
            $rec = $news->fetch();
            if ($rec) {
                // Delete attachments
                $atts = $db->prepare('SELECT stored_name FROM cms_news_attachments WHERE news_id = ?');
                $atts->execute([$change['record_id']]);
                foreach ($atts->fetchAll() as $att) {
                    $path = UPLOAD_DIR . '/news/' . $att['stored_name'];
                    if (file_exists($path)) @unlink($path);
                }
                if ($rec['featured_image']) {
                    $path = UPLOAD_DIR . '/news/' . $rec['featured_image'];
                    if (file_exists($path)) @unlink($path);
                }
                $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$change['record_id']]);
            }
        } else {
            $notice = $db->prepare('SELECT * FROM cms_notices WHERE id = ?');
            $notice->execute([$change['record_id']]);
            $rec = $notice->fetch();
            if ($rec) {
                if ($rec['news_id']) {
                    $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$rec['news_id']]);
                }
                if ($rec['attachment']) {
                    $path = UPLOAD_DIR . '/notices/' . $rec['attachment'];
                    if (file_exists($path)) @unlink($path);
                }
                $db->prepare('DELETE FROM cms_notices WHERE id = ?')->execute([$change['record_id']]);
            }
        }

        log_change($log_module, 'DELETE',
            $change['record_id'], $change['record_title'],
            null, null, null,
            'Delete request approved and executed by ' . $reviewer['full_name'] . '.');

    } else {
        // EDIT – apply the proposed changes from payload JSON
        $p = json_decode($change['payload'], true) ?? [];

        if ($module === 'news') {
            // Load current record
            $rec_stmt = $db->prepare('SELECT * FROM cms_news WHERE id = ?');
            $rec_stmt->execute([$change['record_id']]);
            $rec = $rec_stmt->fetch();

            if ($rec) {
                $title        = $p['title']        ?? $rec['title'];
                $content      = $p['content']      ?? $rec['content'];
                $content_type = $p['content_type'] ?? $rec['content_type'];
                $is_published = isset($p['is_published']) ? (int)$p['is_published'] : (int)$rec['is_published'];
                $published_at = $p['published_at'] ?? $rec['published_at'];
                // Preserve original approval state; default to 1 so approving an edit approves the record
                $edit_approved = isset($p['was_approved']) ? (int)$p['was_approved'] : 1;

                // Handle featured image
                $featured_image = $rec['featured_image'];
                if (!empty($p['featured_image_remove'])) {
                    if ($featured_image && file_exists(UPLOAD_DIR . '/news/' . $featured_image)) {
                        @unlink(UPLOAD_DIR . '/news/' . $featured_image);
                    }
                    $featured_image = null;
                }
                if (!empty($p['featured_image_new'])) {
                    if ($featured_image && file_exists(UPLOAD_DIR . '/news/' . $featured_image)) {
                        @unlink(UPLOAD_DIR . '/news/' . $featured_image);
                    }
                    $featured_image = $p['featured_image_new'];
                }

                // Regenerate slug only if title changed
                $slug = $rec['slug'];
                if ($title !== $rec['title']) {
                    $slug = pch_unique_news_slug(pch_make_slug($title), $change['record_id']);
                }

                $db->prepare(
                    'UPDATE cms_news
                     SET title=?, slug=?, content=?, content_type=?, featured_image=?,
                         is_published=?, published_at=?, is_approved=?, approved_by=?, approved_at=NOW(),
                         updated_at=NOW()
                     WHERE id=?'
                )->execute([$title, $slug, $content, $content_type, $featured_image,
                             $is_published, $published_at, $edit_approved, $reviewer['id'], $change['record_id']]);

                // Add any new attachments from payload
                foreach ($p['new_attachments'] ?? [] as $att) {
                    $db->prepare(
                        'INSERT INTO cms_news_attachments (news_id, original_name, stored_name, mime_type, size)
                         VALUES (?,?,?,?,?)'
                    )->execute([
                        $change['record_id'],
                        $att['original_name'],
                        $att['stored_name'],
                        $att['mime_type'],
                        $att['size'],
                    ]);
                }

                log_change($log_module, 'UPDATE',
                    $change['record_id'], $title,
                    null, null, null,
                    'Edit request approved and applied by ' . $reviewer['full_name'] . '.');
            }

        } else {
            // Notice EDIT
            $rec_stmt = $db->prepare('SELECT * FROM cms_notices WHERE id = ?');
            $rec_stmt->execute([$change['record_id']]);
            $rec = $rec_stmt->fetch();

            if ($rec) {
                $title           = $p['title']           ?? $rec['title'];
                $content         = $p['content']         ?? $rec['content'];
                $content_type    = $p['content_type']    ?? $rec['content_type'];
                $publish_as_news = isset($p['publish_as_news']) ? (int)$p['publish_as_news'] : (int)$rec['publish_as_news'];
                $is_published    = isset($p['is_published'])    ? (int)$p['is_published']    : (int)$rec['is_published'];
                $published_at    = $p['published_at'] ?? $rec['published_at'];
                // Preserve original approval state; default to 1 so approving an edit approves the record
                $edit_approved   = isset($p['was_approved']) ? (int)$p['was_approved'] : 1;

                // Attachment handling
                $attachment               = $rec['attachment'];
                $attachment_original_name = $rec['attachment_original_name'];
                $attachment_mime          = $rec['attachment_mime'];
                $attachment_size          = $rec['attachment_size'];

                if (!empty($p['attachment_remove']) && $attachment) {
                    $path = UPLOAD_DIR . '/notices/' . $attachment;
                    if (file_exists($path)) @unlink($path);
                    $attachment = $attachment_original_name = $attachment_mime = $attachment_size = null;
                }
                if (!empty($p['attachment_new'])) {
                    if ($attachment) {
                        $path = UPLOAD_DIR . '/notices/' . $attachment;
                        if (file_exists($path)) @unlink($path);
                    }
                    $attachment               = $p['attachment_new'];
                    $attachment_original_name = $p['attachment_original_name'] ?? $attachment;
                    $attachment_mime          = $p['attachment_mime']          ?? null;
                    $attachment_size          = $p['attachment_size']          ?? null;
                }

                $old_news_id = (int)($rec['news_id'] ?? 0);

                $db->prepare(
                    'UPDATE cms_notices SET
                     title=?, content=?, content_type=?, attachment=?, attachment_original_name=?,
                     attachment_mime=?, attachment_size=?, publish_as_news=?, is_published=?,
                     published_at=?, is_approved=?, approved_by=?, approved_at=NOW(), updated_at=NOW()
                     WHERE id=?'
                )->execute([
                    $title, $content, $content_type,
                    $attachment, $attachment_original_name, $attachment_mime, $attachment_size,
                    $publish_as_news, $is_published, $published_at,
                    $edit_approved, $reviewer['id'], $change['record_id'],
                ]);

                // Sync linked news row
                if ($publish_as_news && !$old_news_id) {
                    $st = $db->prepare('SELECT id FROM cms_news WHERE slug = ?');
                    $st->execute([$rec['slug']]);
                    $news_slug = $st->fetch() ? $rec['slug'] . '-notice' : $rec['slug'];
                    $db->prepare(
                        'INSERT INTO cms_news (title, slug, content, content_type, featured_image, is_published, published_at)
                         VALUES (?,?,?,?,NULL,?,?)'
                    )->execute([$title, $news_slug, $content, $content_type, $is_published, $published_at]);
                    $nn = (int)$db->lastInsertId();
                    $db->prepare('UPDATE cms_notices SET news_id = ? WHERE id = ?')->execute([$nn, $change['record_id']]);
                } elseif (!$publish_as_news && $old_news_id) {
                    $db->prepare('DELETE FROM cms_news WHERE id = ?')->execute([$old_news_id]);
                    $db->prepare('UPDATE cms_notices SET news_id = NULL WHERE id = ?')->execute([$change['record_id']]);
                } elseif ($publish_as_news && $old_news_id) {
                    $db->prepare(
                        'UPDATE cms_news SET title=?, content=?, content_type=?, is_published=?, published_at=?, updated_at=NOW()
                         WHERE id=?'
                    )->execute([$title, $content, $content_type, $is_published, $published_at, $old_news_id]);
                }

                log_change($log_module, 'UPDATE',
                    $change['record_id'], $title,
                    null, null, null,
                    'Edit request approved and applied by ' . $reviewer['full_name'] . '.');
            }
        }
    }

    // Mark change as approved
    $db->prepare(
        "UPDATE cms_pending_changes
         SET status='approved', reviewed_by=?, reviewed_at=NOW(), review_note=?
         WHERE id=?"
    )->execute([$reviewer['id'], $review_note ?: null, $change_id]);

    flash_set('success', ucfirst(strtolower($action)) . ' request for <strong>' . h($change['record_title']) . '</strong> approved and applied.');
}

redirect(APP_URL . '/cms/pending-changes/index.php');
