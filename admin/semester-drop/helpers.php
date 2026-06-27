<?php
/**
 * Semester Drop – helper functions
 * ================================================================
 * A semester drop marks that a student is taking a study break for a fixed
 * block of months. During that window the student's monthly tuition is not
 * counted as a due – it is shown as "Semester Drop" instead.
 *
 *   BI  semester → 6 months blocked
 *   TRI semester → 4 months blocked
 *
 * These helpers are intentionally dependency-light (only db() + auth helpers)
 * so they can be safely required from the accounting layer and report screens
 * to drive the "not counted as due" behaviour.
 */

require_once __DIR__ . '/../includes/auth.php';

// ── Constants ───────────────────────────────────────────────────────────────
const SD_SLUG       = 'semester-drop';
const SD_BI_MONTHS  = 6;   // BI semester drop blocks 6 months
const SD_TRI_MONTHS = 4;   // TRI semester drop blocks 4 months

// Evidence upload constraints (mirrors the student file uploader)
const SD_EVIDENCE_EXTS  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
const SD_EVIDENCE_MIMES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const SD_EVIDENCE_MAX = 20 * 1024 * 1024; // 20 MB

// ── Permission helpers ──────────────────────────────────────────────────────
function sd_can_view(): bool   { return can_access(SD_SLUG, 'can_view'); }
function sd_can_create(): bool { return can_access(SD_SLUG, 'can_create'); }
function sd_can_delete(): bool { return can_access(SD_SLUG, 'can_delete'); }

// ── Type / window helpers ───────────────────────────────────────────────────

/**
 * Number of blocked months for a given semester type.
 */
function sd_block_months(string $type): int
{
    return $type === 'tri' ? SD_TRI_MONTHS : SD_BI_MONTHS;
}

/**
 * Human label for a semester type.
 */
function sd_type_label(string $type): string
{
    return $type === 'tri' ? 'Tri-semester' : 'Bi-semester';
}

/**
 * Compute the inclusive last day of the blocked window.
 *
 * @param string $start  Y-m-d start date
 * @param string $type   'bi' | 'tri'
 * @return string        Y-m-d inclusive end date
 */
function sd_compute_end(string $start, string $type): string
{
    $months = sd_block_months($type);
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    if ($dt === false) {
        $dt = new \DateTimeImmutable($start);
    }
    // The window covers `$months` whole months starting on $start, so the last
    // blocked day is one day before the same day-of-month $months later.
    $end = $dt->modify('+' . $months . ' months')->modify('-1 day');
    return $end->format('Y-m-d');
}

// ── CRUD ────────────────────────────────────────────────────────────────────

/**
 * Fetch a single drop record joined with student details.
 */
function sd_get_drop(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT d.*, s.full_name AS student_name, s.student_id AS student_sid,
                sf.stored_name AS evidence_stored_name, sf.original_name AS evidence_original_name,
                cb.full_name AS created_by_name, xb.full_name AS cancelled_by_name
         FROM semester_drops d
         JOIN students s ON s.id = d.student_id
         LEFT JOIN student_files sf ON sf.id = d.evidence_file_id
         LEFT JOIN users cb ON cb.id = d.created_by
         LEFT JOIN users xb ON xb.id = d.cancelled_by
         WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create a semester drop record.
 *
 * @return int  The new record id.
 */
function sd_create_drop(
    int $student_id,
    string $type,
    string $drop_start,
    ?string $reason,
    ?int $evidence_file_id,
    int $created_by
): int {
    $months = sd_block_months($type);
    $drop_end = sd_compute_end($drop_start, $type);

    $stmt = db()->prepare(
        'INSERT INTO semester_drops
            (student_id, semester_type, block_months, drop_start, drop_end, reason, evidence_file_id, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
    );
    $stmt->execute([
        $student_id,
        $type,
        $months,
        $drop_start,
        $drop_end,
        ($reason !== null && $reason !== '') ? $reason : null,
        $evidence_file_id,
        $created_by,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Cancel an active semester drop.
 */
function sd_cancel_drop(int $id, int $user_id, ?string $reason): void
{
    $stmt = db()->prepare(
        'UPDATE semester_drops
            SET status = \'cancelled\', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ?
          WHERE id = ? AND status = \'active\''
    );
    $stmt->execute([$user_id, ($reason !== null && $reason !== '') ? $reason : null, $id]);
}

// ── Lookups used by accounts / profile integrations ─────────────────────────

/**
 * All active drop windows for a student (cached per request).
 *
 * @return array<int,array{drop_start:string,drop_end:string,semester_type:string}>
 */
function sd_active_drops_for_student(int $student_id): array
{
    static $cache = [];
    if (array_key_exists($student_id, $cache)) {
        return $cache[$student_id];
    }
    $rows = [];
    try {
        $stmt = db()->prepare(
            'SELECT id, drop_start, drop_end, semester_type, block_months
               FROM semester_drops
              WHERE student_id = ? AND status = \'active\'
              ORDER BY drop_start ASC'
        );
        $stmt->execute([$student_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not be migrated yet – behave as if there are no drops.
        $rows = [];
    }
    $cache[$student_id] = $rows;
    return $rows;
}

/**
 * Is the student currently (today) within an active drop window?
 * Returns the matching drop row, or null.
 */
function sd_student_on_drop_now(int $student_id, ?string $on_date = null): ?array
{
    $today = $on_date ?? date('Y-m-d');
    foreach (sd_active_drops_for_student($student_id) as $row) {
        if ($row['drop_start'] <= $today && $today <= $row['drop_end']) {
            return $row;
        }
    }
    return null;
}

/**
 * Does the given calendar month (year, month) fall inside any active drop
 * window for this student? A month counts as dropped when it overlaps the
 * blocked window at month granularity.
 */
function sd_is_month_dropped(int $student_id, int $cal_year, int $cal_month): bool
{
    if ($cal_year <= 0 || $cal_month < 1 || $cal_month > 12) {
        return false;
    }
    $idx = $cal_year * 12 + ($cal_month - 1);
    foreach (sd_active_drops_for_student($student_id) as $row) {
        $s = explode('-', $row['drop_start']);
        $e = explode('-', $row['drop_end']);
        if (count($s) < 2 || count($e) < 2) {
            continue;
        }
        $start_idx = ((int)$s[0]) * 12 + ((int)$s[1] - 1);
        $end_idx   = ((int)$e[0]) * 12 + ((int)$e[1] - 1);
        if ($idx >= $start_idx && $idx <= $end_idx) {
            return true;
        }
    }
    return false;
}

// ── Presentation helpers ────────────────────────────────────────────────────

/**
 * URL of an uploaded evidence file (stored under students/files).
 */
function sd_evidence_url(string $stored_name): string
{
    return UPLOAD_URL . '/students/files/' . rawurlencode($stored_name);
}

/**
 * Small status badge for a drop record.
 */
function sd_status_badge(string $status): string
{
    return $status === 'active'
        ? '<span class="badge bg-warning text-dark"><i class="fas fa-pause me-1"></i>Active Drop</span>'
        : '<span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>';
}

/**
 * Badge shown next to a student who is currently on a semester drop.
 * Returns an empty string when the student is not on drop today.
 */
function sd_current_badge(int $student_id): string
{
    $row = sd_student_on_drop_now($student_id);
    if (!$row) {
        return '';
    }
    return '<span class="badge bg-warning text-dark" title="On semester drop until '
        . h(date('d M Y', strtotime($row['drop_end']))) . '">'
        . '<i class="fas fa-pause-circle me-1"></i>Semester Drop</span>';
}

/**
 * Validate and store an uploaded evidence file, returning the new
 * student_files.id, or null on failure.
 */
function sd_store_evidence(array $file, int $student_id, int $uploaded_by): ?int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > SD_EVIDENCE_MAX) {
        return null;
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, SD_EVIDENCE_EXTS, true)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SD_EVIDENCE_MIMES, true)) {
        return null;
    }

    $dir = UPLOAD_DIR . '/students/files';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        return null;
    }

    $stmt = db()->prepare(
        'INSERT INTO student_files
            (student_id, file_name, description, stored_name, original_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $student_id,
        'Semester Drop Evidence',
        'Evidence uploaded for a semester drop.',
        $stored,
        $file['name'] ?? $stored,
        $mime,
        (int)$file['size'],
        $uploaded_by,
    ]);
    return (int)db()->lastInsertId();
}
