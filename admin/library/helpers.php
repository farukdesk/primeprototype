<?php
/**
 * Library Management System – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../change-log/helpers.php';

// ── File type & size constants ────────────────────────────────────────────────

define('LIB_COVER_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('LIB_COVER_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('LIB_COVER_MAX',   5 * 1024 * 1024); // 5 MB

define('LIB_PHOTO_EXTS',  ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('LIB_PHOTO_MIMES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('LIB_PHOTO_MAX',   3 * 1024 * 1024); // 3 MB

define('LIB_DIGITAL_EXTS',  ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'epub', 'mobi', 'txt']);
define('LIB_DIGITAL_MIMES', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip',
    'application/epub+zip',
    'application/x-mobipocket-ebook',
    'text/plain',
]);
define('LIB_DIGITAL_MAX', 100 * 1024 * 1024); // 100 MB

// ── Permission helpers ────────────────────────────────────────────────────────

/** True for super admins and users with can_edit on 'library'. */
function lib_is_staff(): bool
{
    return is_super_admin() || can_access('library', 'can_edit');
}

/** True for super admins and users with can_create on 'library'. */
function lib_can_create(): bool
{
    return is_super_admin() || can_access('library', 'can_create');
}

/** True for super admins and users with can_delete on 'library'. */
function lib_can_delete(): bool
{
    return is_super_admin() || can_access('library', 'can_delete');
}

/** True for super admins and users with can_edit on 'library-circulation'. */
function lib_is_circulation_staff(): bool
{
    return is_super_admin() || can_access('library-circulation', 'can_edit');
}

/** True for super admins and users with any permission on 'library-digital'. */
function lib_can_access_digital(): bool
{
    return is_super_admin()
        || can_access('library-digital', 'can_view')
        || can_access('library-digital', 'can_edit')
        || can_access('library-digital', 'can_create')
        || can_access('library-digital', 'can_delete');
}

// ── Settings helpers ──────────────────────────────────────────────────────────

/**
 * Fetch a single library setting by key.
 *
 * @param string $key     Setting key name.
 * @param mixed  $default Value returned when the key is not found.
 * @return mixed
 */
function lib_setting(string $key, mixed $default = ''): mixed
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];

    $stmt = db()->prepare('SELECT setting_value FROM library_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    $cache[$key] = ($val !== false) ? $val : $default;
    return $cache[$key];
}

/**
 * Return all library settings as an associative array (key => value).
 */
function lib_settings_all(): array
{
    $rows = db()->query('SELECT setting_key, setting_value FROM library_settings')->fetchAll();
    return array_column($rows, 'setting_value', 'setting_key');
}

/**
 * Insert or update a library setting (upsert).
 */
function lib_save_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO library_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

// ── Upload helpers ────────────────────────────────────────────────────────────

/**
 * Upload a book/resource cover image to UPLOAD_DIR/library/covers/.
 *
 * @param  array  $file   $_FILES entry.
 * @return string         Stored filename on success.
 * @throws RuntimeException on validation or move failure.
 */
function lib_upload_cover(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Cover upload error code: ' . $file['error']);
    }
    if ($file['size'] > LIB_COVER_MAX) {
        throw new RuntimeException('Cover image exceeds maximum size of 5 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, LIB_COVER_EXTS, true)) {
        throw new RuntimeException('Invalid cover image extension: ' . $ext);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, LIB_COVER_MIMES, true)) {
        throw new RuntimeException('Invalid cover image MIME type: ' . $mime);
    }

    $dir = UPLOAD_DIR . '/library/covers';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Failed to move cover image to storage.');
    }
    return $stored;
}

/**
 * Upload a librarian photo to UPLOAD_DIR/library/librarians/.
 *
 * @param  array  $file  $_FILES entry.
 * @return string        Stored filename on success.
 * @throws RuntimeException on validation or move failure.
 */
function lib_upload_librarian_photo(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload error code: ' . $file['error']);
    }
    if ($file['size'] > LIB_PHOTO_MAX) {
        throw new RuntimeException('Librarian photo exceeds maximum size of 3 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, LIB_PHOTO_EXTS, true)) {
        throw new RuntimeException('Invalid photo extension: ' . $ext);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, LIB_PHOTO_MIMES, true)) {
        throw new RuntimeException('Invalid photo MIME type: ' . $mime);
    }

    $dir = UPLOAD_DIR . '/library/librarians';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Failed to move librarian photo to storage.');
    }
    return $stored;
}

/**
 * Upload a digital resource file to UPLOAD_DIR/library/digital/.
 *
 * @param  array $file  $_FILES entry.
 * @return array{stored: string, original: string, mime: string, size: int}
 * @throws RuntimeException on validation or move failure.
 */
function lib_upload_digital(array $file): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Digital file upload error code: ' . $file['error']);
    }
    if ($file['size'] > LIB_DIGITAL_MAX) {
        throw new RuntimeException('Digital file exceeds maximum size of 100 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, LIB_DIGITAL_EXTS, true)) {
        throw new RuntimeException('Invalid digital file extension: ' . $ext);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, LIB_DIGITAL_MIMES, true)) {
        throw new RuntimeException('Invalid digital file MIME type: ' . $mime);
    }

    $dir = UPLOAD_DIR . '/library/digital';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('Failed to move digital file to storage.');
    }
    return [
        'stored'   => $stored,
        'original' => $file['name'],
        'mime'     => $mime,
        'size'     => $file['size'],
    ];
}

/**
 * Delete a file from UPLOAD_DIR/library/{subdir}/{filename}.
 *
 * @param string $subdir   Subdirectory under library/ (e.g. 'covers', 'digital').
 * @param string $filename Stored filename.
 */
function lib_delete_file(string $subdir, string $filename): void
{
    if (!$filename) return;
    $path = UPLOAD_DIR . '/library/' . $subdir . '/' . $filename;
    if (is_file($path)) unlink($path);
}

// ── Member code & barcode generators ─────────────────────────────────────────

/**
 * Generate a unique library member code in the format LIB-YYYY-NNNN.
 */
function lib_generate_member_code(): string
{
    $year   = date('Y');
    $prefix = 'LIB-' . $year . '-';
    $stmt   = db()->prepare(
        "SELECT member_code FROM library_members
         WHERE member_code LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate a barcode string for a book copy.
 *
 * Format: BK-{book_id padded 6}-{copy_number padded 3}
 * Example: BK-000001-001
 *
 * @param int $book_id     Book ID.
 * @param int $copy_number Copy number within the book.
 */
function lib_generate_barcode(int $book_id, int $copy_number): string
{
    return 'BK-'
        . str_pad((string)$book_id,     6, '0', STR_PAD_LEFT)
        . '-'
        . str_pad((string)$copy_number, 3, '0', STR_PAD_LEFT);
}

// ── Fine calculation ──────────────────────────────────────────────────────────

/**
 * Calculate the overdue fine for a circulation record.
 *
 * Fetches due_date from library_circulation, counts days overdue,
 * multiplies by the 'fine_per_day' library setting (default 5.00).
 *
 * @param  int   $circulation_id
 * @return float Fine amount; 0.0 if not overdue or record not found.
 */
function lib_calculate_fine(int $circulation_id): float
{
    $stmt = db()->prepare(
        'SELECT due_date, status FROM library_circulation WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$circulation_id]);
    $row = $stmt->fetch();
    if (!$row) return 0.0;
    if ($row['status'] === 'Returned') return 0.0;

    $days = lib_overdue_days($row['due_date']);
    if ($days <= 0) return 0.0;

    $rate = (float)lib_setting('fine_per_day', '5.00');
    return round($days * $rate, 2);
}

// ── Receipt number generator ──────────────────────────────────────────────────

/**
 * Generate a unique fine receipt number in the format RCP-YYYY-NNNN.
 */
function lib_generate_receipt(): string
{
    $year   = date('Y');
    $prefix = 'RCP-' . $year . '-';
    $stmt   = db()->prepare(
        "SELECT receipt_number FROM library_fines
         WHERE receipt_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ── Badge helpers ─────────────────────────────────────────────────────────────

/** Bootstrap badge for circulation status. */
function lib_circulation_status_badge(string $status): string
{
    $map = [
        'Issued'   => 'bg-primary',
        'Returned' => 'bg-success',
        'Overdue'  => 'bg-danger',
        'Lost'     => 'bg-dark',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

/** Bootstrap badge for reservation status. */
function lib_reservation_status_badge(string $status): string
{
    $map = [
        'Pending'   => 'bg-warning text-dark',
        'Available' => 'bg-success',
        'Fulfilled' => 'bg-secondary',
        'Cancelled' => 'bg-secondary',
        'Expired'   => 'bg-secondary text-muted',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

/** Bootstrap badge for fine payment status. */
function lib_fine_status_badge(string $status): string
{
    $map = [
        'Unpaid' => 'bg-danger',
        'Paid'   => 'bg-success',
        'Waived' => 'bg-secondary',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

/** Bootstrap badge for book copy condition. */
function lib_copy_condition_badge(string $condition): string
{
    $map = [
        'Good'    => 'bg-success',
        'Fair'    => 'bg-warning text-dark',
        'Poor'    => 'bg-danger',
        'Lost'    => 'bg-dark',
        'Damaged' => 'bg-warning text-dark',
    ];
    $cls = $map[$condition] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($condition) . '</span>';
}

/** Bootstrap badge for digital resource access level. */
function lib_access_level_badge(string $level): string
{
    $map = [
        'Public'   => 'bg-success',
        'Students' => 'bg-primary',
        'Faculty'  => 'bg-info text-dark',
        'Staff'    => 'bg-secondary',
        'Admin'    => 'bg-dark',
    ];
    $cls = $map[$level] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($level) . '</span>';
}

/** Bootstrap badge for digital resource type. */
function lib_resource_type_badge(string $type): string
{
    $map = [
        'E-Book'           => 'bg-primary',
        'Journal'          => 'bg-info text-dark',
        'Research Paper'   => 'bg-success',
        'Thesis'           => 'bg-warning text-dark',
        'Dissertation'     => 'bg-warning text-dark',
        'Other'            => 'bg-secondary',
    ];
    $cls = $map[$type] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($type) . '</span>';
}

// ── Data fetch helpers ────────────────────────────────────────────────────────

/**
 * Fetch a book row joined with category name and department name.
 * Redirects to library index with flash error if not found.
 */
function lib_get_book(int $id): array
{
    $stmt = db()->prepare(
        'SELECT b.*,
                c.name AS category_name,
                d.name AS dept_name
         FROM library_books b
         LEFT JOIN library_categories c ON c.id = b.category_id
         LEFT JOIN dept_departments   d ON d.id = b.dept_id
         WHERE b.id = ?'
    );
    $stmt->execute([$id]);
    $book = $stmt->fetch();
    if (!$book) {
        flash_set('error', 'Book not found.');
        redirect(APP_URL . '/library/index.php');
    }
    return $book;
}

/**
 * Fetch a book copy row joined with book title and ISBN.
 * Redirects to library index with flash error if not found.
 */
function lib_get_copy(int $id): array
{
    $stmt = db()->prepare(
        'SELECT cp.*, b.title AS book_title, b.isbn
         FROM library_book_copies cp
         JOIN library_books b ON b.id = cp.book_id
         WHERE cp.id = ?'
    );
    $stmt->execute([$id]);
    $copy = $stmt->fetch();
    if (!$copy) {
        flash_set('error', 'Book copy not found.');
        redirect(APP_URL . '/library/index.php');
    }
    return $copy;
}

/**
 * Fetch a library member row joined with related student/user info.
 * Redirects to library members list with flash error if not found.
 */
function lib_get_member(int $id): array
{
    $stmt = db()->prepare(
        'SELECT m.*,
                s.student_id,
                s.full_name  AS student_name,
                u.full_name  AS user_full_name,
                u.email      AS user_email
         FROM library_members m
         LEFT JOIN students s ON s.id = m.student_id
         LEFT JOIN users    u ON u.id = m.user_id
         WHERE m.id = ?'
    );
    $stmt->execute([$id]);
    $member = $stmt->fetch();
    if (!$member) {
        flash_set('error', 'Library member not found.');
        redirect(APP_URL . '/library/members/index.php');
    }
    return $member;
}

/**
 * Fetch a circulation record joined with book title, copy barcode, and member name.
 * Redirects to circulation list with flash error if not found.
 */
function lib_get_circulation(int $id): array
{
    $stmt = db()->prepare(
        'SELECT c.*,
                b.title      AS book_title,
                cp.barcode   AS copy_barcode,
                m.member_code,
                COALESCE(s.full_name, u.full_name) AS member_name
         FROM library_circulation c
         JOIN library_book_copies cp ON cp.id = c.copy_id
         JOIN library_books       b  ON b.id  = cp.book_id
         JOIN library_members     m  ON m.id  = c.member_id
         LEFT JOIN students       s  ON s.id  = m.student_id
         LEFT JOIN users          u  ON u.id  = m.user_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $circ = $stmt->fetch();
    if (!$circ) {
        flash_set('error', 'Circulation record not found.');
        redirect(APP_URL . '/library/circulation/index.php');
    }
    return $circ;
}

/**
 * Fetch a library_settings row by primary key.
 * Redirects to settings list with flash error if not found.
 */
function lib_get_setting_or_redirect(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM library_settings WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Setting not found.');
        redirect(APP_URL . '/library/settings.php');
    }
    return $row;
}

// ── Misc utilities ────────────────────────────────────────────────────────────

/**
 * Format a byte count as a human-readable string (B / KB / MB / GB).
 */
function lib_format_size(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576,    1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024,       1) . ' KB';
    return $bytes . ' B';
}

/**
 * Return a Font Awesome icon class for a given file extension or MIME type.
 *
 * @param string $extOrMime File extension (e.g. 'pdf') or MIME type.
 */
function lib_mime_icon(string $extOrMime): string
{
    $v = strtolower($extOrMime);
    if ($v === 'pdf'  || str_contains($v, 'pdf'))                                          return 'fas fa-file-pdf text-danger';
    if (in_array($v, ['doc','docx']) || str_contains($v, 'word'))                          return 'fas fa-file-word text-primary';
    if (in_array($v, ['xls','xlsx']) || str_contains($v, 'excel') || str_contains($v, 'spreadsheet')) return 'fas fa-file-excel text-success';
    if (in_array($v, ['ppt','pptx']) || str_contains($v, 'powerpoint') || str_contains($v, 'presentation')) return 'fas fa-file-powerpoint text-warning';
    if ($v === 'zip'  || str_contains($v, 'zip'))                                          return 'fas fa-file-archive text-secondary';
    if ($v === 'epub' || str_contains($v, 'epub'))                                         return 'fas fa-book text-info';
    if ($v === 'mobi' || str_contains($v, 'mobipocket'))                                   return 'fas fa-book-open text-info';
    if ($v === 'txt'  || str_contains($v, 'text/plain'))                                   return 'fas fa-file-alt text-muted';
    if (in_array($v, ['jpg','jpeg','png','gif','webp']) || str_contains($v, 'image/'))     return 'fas fa-file-image text-info';
    return 'fas fa-file text-muted';
}

/**
 * Calculate how many days overdue a due date is.
 *
 * @param  string $due_date_str  MySQL DATETIME or DATE string.
 * @return int                   Days overdue; 0 if not yet overdue.
 */
function lib_overdue_days(string $due_date_str): int
{
    $due = strtotime($due_date_str);
    if ($due === false || $due >= time()) return 0;
    return (int)floor((time() - $due) / 86400);
}

/**
 * Return all categories as a flat array with 'depth' metadata for nested dropdowns.
 *
 * Each element: ['id', 'name', 'depth', 'parent_id']
 */
function lib_categories_tree(): array
{
    $rows = db()->query(
        'SELECT id, name, parent_id FROM library_categories ORDER BY parent_id, name'
    )->fetchAll();

    // Index by id
    $indexed = [];
    foreach ($rows as $r) {
        $indexed[$r['id']] = $r + ['depth' => 0, 'children' => []];
    }

    $roots = [];
    foreach ($indexed as $id => $node) {
        if ($node['parent_id']) {
            $indexed[$node['parent_id']]['children'][] = $id;
        } else {
            $roots[] = $id;
        }
    }

    $flat = [];
    $walk = function(int $id, int $depth) use (&$walk, &$indexed, &$flat): void {
        $node          = $indexed[$id];
        $node['depth'] = $depth;
        $flat[]        = $node;
        foreach ($node['children'] as $childId) {
            $walk($childId, $depth + 1);
        }
    };
    foreach ($roots as $rootId) {
        $walk($rootId, 0);
    }
    return $flat;
}

/**
 * Return all categories as a flat array ordered by name.
 */
function lib_all_categories(): array
{
    return db()->query(
        'SELECT id, name, parent_id FROM library_categories ORDER BY name ASC'
    )->fetchAll();
}

/**
 * Count active (Issued/Overdue) borrows for a library member.
 */
function lib_member_borrow_count(int $member_id): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM library_circulation
         WHERE member_id = ? AND status IN ('Issued', 'Overdue')"
    );
    $stmt->execute([$member_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Sum of unpaid fines for a library member.
 */
function lib_member_unpaid_fines(int $member_id): float
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM library_fines
         WHERE member_id = ? AND status = 'Unpaid'"
    );
    $stmt->execute([$member_id]);
    return (float)$stmt->fetchColumn();
}

/**
 * Count available copies for a book.
 */
function lib_book_available_copies(int $book_id): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM library_book_copies
         WHERE book_id = ? AND is_available = 1'
    );
    $stmt->execute([$book_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Insert a row into library_audit_log.
 *
 * @param string $action      e.g. 'CREATE', 'UPDATE', 'DELETE', 'ISSUE', 'RETURN'
 * @param string $module      e.g. 'books', 'circulation', 'members'
 * @param int    $record_id   Primary key of the affected record.
 * @param string $record_label Human-readable label (e.g. book title, member code).
 * @param string $details     Additional freeform description.
 */
function lib_audit(string $action, string $module, int $record_id, string $record_label, string $details = ''): void
{
    $user = auth_user();
    $stmt = db()->prepare(
        'INSERT INTO library_audit_log
             (user_id, user_name, action, module, record_id, record_label, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $user['id']        ?? 0,
        $user['full_name'] ?? 'System',
        $action,
        $module,
        $record_id,
        $record_label,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}
