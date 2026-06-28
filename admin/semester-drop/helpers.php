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

// Extra iterations granted to the calendar-walk loop guards on top of the
// theoretical maximum (obligation slots + deferred months). Purely a safety net
// so malformed drop data can never cause an unbounded loop; large enough to be
// irrelevant in practice (50 years of monthly slots).
const SD_MAX_GUARD_HEADROOM = 600;

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

// ── Calendar-shift helpers (deferral model) ─────────────────────────────────
//
// A semester drop does NOT erase the dropped months' obligations – it *defers*
// them. Every later obligation slot is pushed forward past the blocked calendar
// months, so the student still owes the full programme total and the schedule
// (programme end) is automatically extended by the drop length (Bi = 6 months,
// Tri = 4 months). The helpers below translate a sequential obligation slot into
// the real calendar month it lands on once dropped months are skipped.

/**
 * Calendar month/year/label for a slot offset from the package start month.
 *
 * Delegates to the accounting helper when available (so labels stay identical
 * across the app) and falls back to a self-contained implementation otherwise,
 * keeping this module dependency-light.
 *
 * @return array{month:int,year:int,label:string}
 */
function sd_month_year_for_slot(int $start_month, int $start_year, int $offset): array
{
    if (function_exists('acc_month_year_for_slot')) {
        return acc_month_year_for_slot($start_month, $start_year, $offset);
    }
    // Fallback: acc_month_year_for_slot() lives in admin/accounting/helpers.php and
    // is loaded in every normal request that touches fees. This self-contained copy
    // keeps the Semester Drop module usable when it is required on its own (e.g. the
    // module's own pages, or unit-style includes) without pulling in the accounting
    // layer. It must stay numerically identical to acc_month_year_for_slot().
    static $month_short_names = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];
    $serial      = ($start_month - 1) + $offset;
    $month_index = (($serial % 12) + 12) % 12;
    $month       = $month_index + 1;
    $year        = $start_year + (int)floor(($serial - $month_index) / 12);
    return [
        'month' => $month,
        'year'  => $year,
        'label' => ($month_short_names[$month] ?? '') . ' ' . $year,
    ];
}

/**
 * Total number of deferred (dropped) months for a student across all active
 * drops. Used to compute the extended programme end date / month count.
 *
 * Note: overlapping drop windows are summed by their block length; this matches
 * the intended "extend by drop length" semantics (Bi = 6, Tri = 4) and is only
 * an upper bound in the rare overlapping case.
 */
function sd_dropped_months_count(int $student_id): int
{
    $total = 0;
    foreach (sd_active_drops_for_student($student_id) as $row) {
        $total += (int)($row['block_months'] ?? 0);
    }
    return $total;
}

/**
 * Map a sequential obligation slot (0-based) to the real calendar month it
 * lands on once active drop windows are skipped.
 *
 * Walks a calendar cursor forward from the package start month; calendar months
 * inside an active drop window are skipped without consuming an obligation slot,
 * so every obligation after a drop is shifted forward (deferred).
 *
 * @return array{month:int,year:int,label:string}
 */
function sd_shifted_slot_calendar(int $student_id, int $start_month, int $start_year, int $obligation_offset): array
{
    if ($obligation_offset < 0) {
        $obligation_offset = 0;
    }
    // No active drops → behaves exactly like the plain calendar mapping.
    if (empty(sd_active_drops_for_student($student_id))) {
        return sd_month_year_for_slot($start_month, $start_year, $obligation_offset);
    }

    $slot     = 0;   // obligation slot currently being placed
    $cal      = 0;   // calendar offset from the start month
    $guard    = 0;
    // Upper bound on calendar steps: to place obligation slot N we advance the
    // calendar cursor once per non-dropped month (at most N+1) plus once per
    // dropped month we skip (at most sd_dropped_months_count). The extra
    // SD_MAX_GUARD_HEADROOM is a safety net against malformed drop data so the
    // loop can never run unbounded.
    $maxGuard = $obligation_offset + sd_dropped_months_count($student_id) + SD_MAX_GUARD_HEADROOM;

    while ($guard++ <= $maxGuard) {
        $info = sd_month_year_for_slot($start_month, $start_year, $cal);
        if (sd_is_month_dropped($student_id, (int)$info['year'], (int)$info['month'])) {
            $cal++;
            continue;
        }
        if ($slot === $obligation_offset) {
            return $info;
        }
        $slot++;
        $cal++;
    }

    // Safety fallback (should be unreachable): unshifted calendar slot.
    return sd_month_year_for_slot($start_month, $start_year, $obligation_offset);
}

/**
 * Build an ordered schedule of calendar rows for a block of obligation slots,
 * interleaving "Semester Drop" placeholder rows for the skipped (deferred)
 * calendar months.
 *
 * Each returned row is:
 *   ['type' => 'obligation'|'drop', 'slot' => ?int, 'month' => int,
 *    'year' => int, 'label' => string]
 * where 'slot' is the 0-based global obligation index for obligation rows and
 * null for drop placeholder rows.
 *
 * @param int $num_obligations Number of obligation rows to emit.
 * @param int $start_slot      Global obligation index of the first row to emit.
 * @return array<int,array{type:string,slot:?int,month:int,year:int,label:string}>
 */
function sd_build_schedule(int $student_id, int $start_month, int $start_year, int $num_obligations, int $start_slot = 0): array
{
    $rows = [];
    if ($num_obligations < 1) {
        return $rows;
    }

    $emitted = 0;   // obligation rows emitted so far
    $slot    = 0;   // global obligation index
    $cal     = 0;   // calendar offset from the start month
    $guard   = 0;
    $maxGuard = $start_slot + $num_obligations + sd_dropped_months_count($student_id) + SD_MAX_GUARD_HEADROOM;

    $has_drops = !empty(sd_active_drops_for_student($student_id));

    while ($emitted < $num_obligations && $guard++ <= $maxGuard) {
        $info   = sd_month_year_for_slot($start_month, $start_year, $cal);
        $is_drop = $has_drops
            && sd_is_month_dropped($student_id, (int)$info['year'], (int)$info['month']);

        if ($is_drop) {
            // Surface deferred calendar months as informational placeholder rows,
            // but only once we have reached the requested window of slots.
            if ($slot >= $start_slot) {
                $rows[] = [
                    'type'  => 'drop',
                    'slot'  => null,
                    'month' => (int)$info['month'],
                    'year'  => (int)$info['year'],
                    'label' => (string)$info['label'],
                ];
            }
            $cal++;
            continue;
        }

        if ($slot >= $start_slot) {
            $rows[] = [
                'type'  => 'obligation',
                'slot'  => $slot,
                'month' => (int)$info['month'],
                'year'  => (int)$info['year'],
                'label' => (string)$info['label'],
            ];
            $emitted++;
        }
        $slot++;
        $cal++;
    }

    return $rows;
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
