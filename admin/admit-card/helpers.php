<?php
/**
 * Admit Card Module – Shared Helpers
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../accounting/helpers.php';

// ── Due-amount threshold ──────────────────────────────────────────────────────
const AC_DUE_THRESHOLD = 500.0;

// ── Permission helpers ────────────────────────────────────────────────────────

function ac_can_view(): bool
{
    return is_super_admin() || can_access('admit-card');
}

function ac_can_create(): bool
{
    return is_super_admin() || can_access('admit-card', 'can_create');
}

function ac_can_edit(): bool
{
    return is_super_admin() || can_access('admit-card', 'can_edit');
}

function ac_can_delete(): bool
{
    return is_super_admin() || can_access('admit-card', 'can_delete');
}

// ── Fetch a single admit card with dept/program/batch joins ───────────────────

function ac_get_card(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT ac.*,
                d.name           AS dept_name,
                d.code           AS dept_code,
                d.faculty_label  AS dept_faculty_label,
                p.program_name,
                b.name           AS batch_name_db,
                u.full_name      AS created_by_name
         FROM ac_admit_cards ac
         JOIN dept_departments         d ON d.id = ac.dept_id
         JOIN dept_academic_programs   p ON p.id = ac.program_id
         LEFT JOIN student_batches     b ON b.id = ac.batch_id
         LEFT JOIN users               u ON u.id = ac.created_by
         WHERE ac.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ── Fetch courses for an admit card ──────────────────────────────────────────

function ac_get_courses(int $admit_card_id): array
{
    $stmt = db()->prepare(
        'SELECT * FROM ac_admit_card_courses
         WHERE admit_card_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$admit_card_id]);
    return $stmt->fetchAll();
}

// ── Check if a student has an override ───────────────────────────────────────

function ac_has_override(int $admit_card_id, int $student_id): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM ac_student_overrides
         WHERE admit_card_id = ? AND student_id = ?
         LIMIT 1'
    );
    $stmt->execute([$admit_card_id, $student_id]);
    return $stmt->fetchColumn() !== false;
}

// ── Exam-routine linkage (see admin/admit-card-routine-link.sql) ─────────────

/** Routine linked to a card, or 0 (also 0 when the column doesn't exist yet). */
function ac_card_routine_id(int $admit_card_id): int
{
    try {
        $st = db()->prepare('SELECT routine_id FROM ac_admit_cards WHERE id = ?');
        $st->execute([$admit_card_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Exam behind a card (via its linked routine), or 0. */
function ac_card_exam_id(int $admit_card_id): int
{
    $rid = ac_card_routine_id($admit_card_id);
    if ($rid <= 0) return 0;
    try {
        $st = db()->prepare('SELECT exam_id FROM exam_routines WHERE id = ?');
        $st->execute([$rid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Student-centric course resolution for an exam.
 *
 * WHAT the student sits comes from the course-offer registrations (the
 * single source of truth for enrollment); WHEN comes from the exam's
 * routine items:
 *   1. a routine item pointing at the student's exact offer subject wins
 *      (it carries the student's own shift / section / date / time);
 *   2. otherwise, among routine items with the same course code, the item
 *      whose offer matches the student's SHIFT and BATCH is preferred,
 *      then shift only, then batch only, then the first item;
 *   3. a registered course with no routine item in this exam is omitted
 *      (it is not part of this exam).
 */
function ac_resolve_student_courses(int $exam_id, int $student_id): array
{
    require_once __DIR__ . '/../exam-routine/helpers.php';

    $norm = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));

    try {
        // The student's registrations with their offer context.
        $st = db()->prepare(
            'SELECT r.offer_subject_id, o.batch_id, o.shift, o.section,
                    c.course_code, c.course_name
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN co_offers o           ON o.id  = cos.offer_id
               JOIN course_curriculum c   ON c.id  = cos.curriculum_id
              WHERE r.student_id = ?'
        );
        $st->execute([$student_id]);
        $regs = $st->fetchAll();
        if (!$regs) return [];

        // The exam's routine items with their offer context.
        $it = db()->prepare(
            'SELECT i.offer_subject_id, i.course_code, i.course_title,
                    i.exam_date, i.start_time, i.end_time,
                    o.batch_id AS item_batch_id, o.shift AS item_shift
               FROM exam_routine_items i
               JOIN exam_routines rt      ON rt.id  = i.routine_id
          LEFT JOIN co_offer_subjects cos ON cos.id = i.offer_subject_id
          LEFT JOIN co_offers o           ON o.id   = cos.offer_id
              WHERE rt.exam_id = ?'
        );
        $it->execute([$exam_id]);
        $items = $it->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $by_osid = [];
    $by_code = [];
    foreach ($items as $i) {
        $osid = (int)($i['offer_subject_id'] ?? 0);
        if ($osid > 0 && !isset($by_osid[$osid])) $by_osid[$osid] = $i;
        $ck = $norm((string)$i['course_code']);
        if ($ck !== '') $by_code[$ck][] = $i;
    }

    // Registrations with an exact routine item first, so a code-level
    // fallback of another registration can never shadow an exact match.
    usort($regs, static fn($a, $b) =>
        (int)isset($by_osid[(int)$b['offer_subject_id']]) <=> (int)isset($by_osid[(int)$a['offer_subject_id']]));

    $out  = [];
    $seen = [];
    foreach ($regs as $r) {
        $ck = $norm((string)$r['course_code']);
        if ($ck === '' || isset($seen[$ck])) continue;

        $item = $by_osid[(int)$r['offer_subject_id']] ?? null;
        if (!$item && !empty($by_code[$ck])) {
            // Several offers (sections / shifts / batches) may carry this
            // course with different dates — prefer shift + batch matches.
            $best = null;
            $best_score = -1;
            foreach ($by_code[$ck] as $cand) {
                $score = 0;
                $cs = $norm((string)($cand['item_shift'] ?? ''));
                if ($cs !== '' && $cs === $norm((string)($r['shift'] ?? ''))) $score += 2;
                $cb = (int)($cand['item_batch_id'] ?? 0);
                if ($cb > 0 && $cb === (int)($r['batch_id'] ?? 0)) $score += 1;
                if ($score > $best_score) { $best = $cand; $best_score = $score; }
            }
            $item = $best;
        }
        if (!$item) continue; // course is not part of this exam
        $seen[$ck] = true;

        $slot = trim(er_fmt_time($item['start_time'] ?? null)
            . (($item['end_time'] ?? null) ? ' - ' . er_fmt_time($item['end_time']) : ''));
        $out[] = [
            'offer_subject_id' => (int)$r['offer_subject_id'],
            'course_code'      => (string)$r['course_code'],
            'course_title'     => (string)(($item['course_title'] ?? '') !== '' ? $item['course_title'] : $r['course_name']),
            'exam_date'        => (string)($item['exam_date'] ?? '') ?: null,
            'time_slot'        => $slot !== '' ? $slot : null,
            'section'          => trim((string)((($r['section'] ?? '') !== '') ? $r['section'] : ($r['shift'] ?? ''))) ?: null,
        ];
    }

    usort($out, static function ($a, $b) {
        $da  = (string)($a['exam_date'] ?? '');
        $dbv = (string)($b['exam_date'] ?? '');
        if ($da === $dbv) return 0;
        if ($da === '')  return 1;
        if ($dbv === '') return -1;
        return strcmp($da, $dbv);
    });

    return $out;
}

/**
 * Is the student registered in at least one course of the routine?
 * Matches by offer subject first, then by course code — the student may be
 * registered for the same course through a different offer / section than
 * the one the routine row points at.
 */
function ac_is_enrolled_in_routine(int $routine_id, int $student_id): bool
{
    try {
        $st = db()->prepare(
            'SELECT 1
               FROM exam_routine_items i
               JOIN co_offer_subjects ros ON ros.id = i.offer_subject_id
               JOIN course_curriculum rc  ON rc.id = ros.curriculum_id
               JOIN co_registrations r    ON r.student_id = ?
               JOIN co_offer_subjects ss  ON ss.id = r.offer_subject_id
               JOIN course_curriculum sc  ON sc.id = ss.curriculum_id
              WHERE i.routine_id = ?
                AND (r.offer_subject_id = i.offer_subject_id
                     OR sc.course_code = rc.course_code)
              LIMIT 1'
        );
        $st->execute([$student_id, $routine_id]);
        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return true; // fail open if routine tables are unavailable
    }
}

/**
 * Courses of a card for one student. For routine-linked cards the list is
 * filtered to the courses the student actually registered for; manually
 * added rows (no offer_subject_id) are always kept.
 */
function ac_get_courses_for_student(int $admit_card_id, int $student_id): array
{
    $courses = ac_get_courses($admit_card_id);
    if (ac_card_routine_id($admit_card_id) <= 0) return $courses;

    $codeKey = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));

    try {
        $st = db()->prepare('SELECT offer_subject_id FROM co_registrations WHERE student_id = ?');
        $st->execute([$student_id]);
        $reg = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // Course codes the student is registered in — the registration may
        // sit on a different offer / section than the offer subject the
        // admit-card row points at, so codes are matched as a fallback.
        $cst = db()->prepare(
            'SELECT c.course_code
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN course_curriculum c   ON c.id  = cos.curriculum_id
              WHERE r.student_id = ?'
        );
        $cst->execute([$student_id]);
        $reg_codes = array_values(array_unique(array_filter(
            array_map($codeKey, $cst->fetchAll(PDO::FETCH_COLUMN))
        )));
    } catch (Throwable $e) {
        return $courses;
    }

    $filtered = array_values(array_filter($courses, function ($c) use ($reg, $reg_codes, $codeKey) {
        $osid = (int)($c['offer_subject_id'] ?? 0);
        if ($osid <= 0 || in_array($osid, $reg, true)) return true;
        $ck = $codeKey($c['course_code'] ?? '');
        return $ck !== '' && in_array($ck, $reg_codes, true);
    }));
    return $filtered ?: $courses;
}

/**
 * Merged course list for one student: the card's own courses PLUS the
 * student's registered courses from SIBLING cards.
 *
 * Bulk creation makes one admit card per exam routine, and routines are
 * stored one per course offer — so one exam's subjects may be scattered
 * across several cards of the same exam + semester + dept + program
 * (+ batch). This merges the student's courses from all those sibling
 * cards so a single card / PDF lists every subject, deduplicated by
 * course code and ordered by exam date.
 */
function ac_get_merged_courses_for_student(int $admit_card_id, int $student_id): array
{
    // Rebuilt (student-centric): for exam-linked cards the course list is
    // resolved directly from the student's course-offer registrations
    // matched against the exam's routine items — see
    // ac_resolve_student_courses(). The legacy card/sibling merge below
    // only runs for cards that cannot be tied to an exam.
    $exam_id = ac_card_exam_id($admit_card_id);
    if ($exam_id > 0) {
        $resolved = ac_resolve_student_courses($exam_id, $student_id);
        if ($resolved) return $resolved;
    }

    $courses = ac_get_courses_for_student($admit_card_id, $student_id);

    $card = ac_get_card($admit_card_id);
    if (!$card) return $courses;

    $norm = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));

    // Sibling cards: every other ACTIVE card with the same normalised exam
    // name. Department, program, semester and batch are deliberately NOT
    // compared: students register for courses in offers of other batches
    // (retakes — see course-offer/registrations.php), evening students often
    // sit under a separate program record, and service courses (Bangla,
    // Math, …) are offered under other departments — all of which produce
    // cards with different dept / program / batch / semester values.
    // Merging across them is safe because a sibling course is only added
    // when the student is actually registered in it (see below).
    try {
        $st = db()->prepare(
            'SELECT id, exam_name FROM ac_admit_cards
              WHERE is_active = 1 AND id <> ?'
        );
        $st->execute([$admit_card_id]);
        $siblings = [];
        foreach ($st->fetchAll() as $c) {
            if ($norm($c['exam_name']) !== $norm($card['exam_name'])) continue;
            $siblings[] = (int)$c['id'];
        }
    } catch (Throwable $e) {
        return $courses;
    }
    // The student's registrations: offer_subject_id => normalised course
    // code. The merge is built FROM the registrations (student-centric),
    // so a row from another shift / section can never replace the row of
    // the offer subject the student is actually registered in.
    $codeKey = static fn($s) => strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$s));
    try {
        $st = db()->prepare(
            'SELECT r.offer_subject_id, c.course_code
               FROM co_registrations r
               JOIN co_offer_subjects cos ON cos.id = r.offer_subject_id
               JOIN course_curriculum c   ON c.id  = cos.curriculum_id
              WHERE r.student_id = ?'
        );
        $st->execute([$student_id]);
        $reg = [];
        foreach ($st->fetchAll() as $r) {
            $reg[(int)$r['offer_subject_id']] = $codeKey((string)$r['course_code']);
        }
    } catch (Throwable $e) {
        return $courses;
    }
    if (!$reg) return $courses;

    // Every course row of this exam's cards — the shown card first so its
    // rows win ties in the code-fallback pass.
    $all = [];
    foreach (array_merge([$admit_card_id], $siblings) as $cid) {
        foreach (ac_get_courses($cid) as $c) $all[] = $c;
    }

    $picked        = [];
    $covered_codes = [];

    // Pass 1 — EXACT matches: card rows pointing at the very offer subject
    // the student registered in. These carry the student's own shift /
    // section, date and time, so they always win over same-code rows of
    // other sections.
    foreach ($all as $c) {
        $osid = (int)($c['offer_subject_id'] ?? 0);
        if ($osid <= 0 || !isset($reg[$osid])) continue;
        $ck = $codeKey($c['course_code'] ?? '') ?: $reg[$osid];
        if ($ck !== '' && isset($covered_codes[$ck])) continue;
        if ($ck !== '') $covered_codes[$ck] = true;
        $picked[] = $c;
    }

    // Shift of every offer subject involved (student registrations + card
    // rows). The code fallback must not show a row of ANOTHER shift: a Day
    // row carries the Day exam date / time, which is wrong (and dangerous)
    // on an Evening student's admit card.
    $shift_of = [];
    try {
        $ids = array_keys($reg);
        foreach ($all as $c) {
            $osid = (int)($c['offer_subject_id'] ?? 0);
            if ($osid > 0) $ids[] = $osid;
        }
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare(
                "SELECT cos.id, o.shift
                   FROM co_offer_subjects cos
                   JOIN co_offers o ON o.id = cos.offer_id
                  WHERE cos.id IN ($ph)"
            );
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $shift_of[(int)$r['id']] = $norm((string)($r['shift'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // shift unknown — the fallback stays shift-agnostic
    }

    // Pass 2 — code fallback: registered courses that have NO exact card
    // row (e.g. the routine was built from another section's offer). Take
    // the first card row with the same course code AND a compatible shift
    // (same shift as the offer the student registered in, or unknown).
    foreach ($reg as $osid => $ck) {
        if ($ck === '' || isset($covered_codes[$ck])) continue;
        $want = $shift_of[$osid] ?? '';
        foreach ($all as $c) {
            if ($codeKey($c['course_code'] ?? '') !== $ck) continue;
            $row_shift = $shift_of[(int)($c['offer_subject_id'] ?? 0)] ?? '';
            if ($want !== '' && $row_shift !== '' && $row_shift !== $want) continue;
            $covered_codes[$ck] = true;
            $picked[] = $c;
            break;
        }
    }

    // Pass 3 — manually added rows of the shown card (no offer-subject
    // link) are kept: they were put on the card intentionally.
    foreach (ac_get_courses($admit_card_id) as $c) {
        if ((int)($c['offer_subject_id'] ?? 0) > 0) continue;
        $ck = $codeKey($c['course_code'] ?? '') ?: $norm((string)($c['course_title'] ?? ''));
        if ($ck !== '' && isset($covered_codes[$ck])) continue;
        if ($ck !== '') $covered_codes[$ck] = true;
        $picked[] = $c;
    }

    if (!$picked) return $courses; // fail open — never blank out the card

    // Order by exam date (undated rows last); rows of the same date keep
    // their original relative order.
    usort($picked, static function ($a, $b) {
        $da = (string)($a['exam_date'] ?? '');
        $dbv = (string)($b['exam_date'] ?? '');
        if ($da === $dbv) return 0;
        if ($da === '')  return 1;
        if ($dbv === '') return -1;
        return strcmp($da, $dbv);
    });

    return $picked;
}

// ── Get or create a unique QR token for a student+card combination ────────────

function ac_get_or_create_token(int $admit_card_id, int $student_id): string
{
    $db = db();
    $stmt = $db->prepare(
        'SELECT token FROM ac_student_tokens
         WHERE admit_card_id = ? AND student_id = ?
         LIMIT 1'
    );
    $stmt->execute([$admit_card_id, $student_id]);
    $row = $stmt->fetch();
    if ($row) {
        return $row['token'];
    }

    $token = bin2hex(random_bytes(32));
    $db->prepare(
        'INSERT INTO ac_student_tokens (admit_card_id, student_id, token)
         VALUES (?, ?, ?)'
    )->execute([$admit_card_id, $student_id, $token]);

    return $token;
}

// ── Check whether a student can access/download an admit card ─────────────────

/**
 * Returns:
 *   ['allowed' => true]
 *   ['allowed' => false, 'due' => float, 'reason' => string]
 */
function ac_check_access(int $admit_card_id, int $student_id): array
{
    // Check for admin override first
    if (ac_has_override($admit_card_id, $student_id)) {
        return ['allowed' => true];
    }

    // Exam-linked cards: only students actually enrolled (registered) in
    // at least one course of the card's EXAM may access the card. The
    // exam-wide check is used because a card links one routine while the
    // student's courses may sit in other routines of the same exam.
    $exam_id    = ac_card_exam_id($admit_card_id);
    $routine_id = ac_card_routine_id($admit_card_id);
    if ($exam_id > 0) {
        if (!ac_resolve_student_courses($exam_id, $student_id)) {
            return [
                'allowed' => false,
                'due'     => 0.0,
                'reason'  => 'You are not enrolled in any course of this exam. Please contact your department office.',
            ];
        }
    } elseif ($routine_id > 0 && !ac_is_enrolled_in_routine($routine_id, $student_id)) {
        return [
            'allowed' => false,
            'due'     => 0.0,
            'reason'  => 'You are not enrolled in any course of this exam routine. Please contact your department office.',
        ];
    }

    // Find the student's package and compute outstanding balance
    $pkg_stmt = db()->prepare(
        'SELECT id FROM sfp_packages WHERE student_id = ? ORDER BY id DESC LIMIT 1'
    );
    $pkg_stmt->execute([$student_id]);
    $pkg_row = $pkg_stmt->fetch();

    $due = 0.0;
    if ($pkg_row) {
        $due = acc_outstanding_through_current_month((int)$pkg_row['id']);
    }

    if ($due > AC_DUE_THRESHOLD) {
        return [
            'allowed' => false,
            'due'     => $due,
            'reason'  => sprintf(
                'You have a current due of ৳%s. Please clear your dues to download your admit card.',
                number_format($due, 2)
            ),
        ];
    }

    return ['allowed' => true];
}

// ── Generate QR code as base64 data URI ──────────────────────────────────────
// Uses a temp file to avoid the Header() side-effect that phpqrcode emits
// when writing directly to stdout, which would corrupt PDF Content-Type headers.

function ac_qr_data_uri(string $url): string
{
    require_once __DIR__ . '/phpqrcode.php';

    // ── PNG via temp file (preferred – no header() side-effect) ──────────────
    $png_f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . uniqid('', true) . '.png';
    try {
        QRcode::png($url, $png_f, QR_ECLEVEL_M, 4, 4);
        if (is_file($png_f) && filesize($png_f) > 0) {
            $data = file_get_contents($png_f);
            @unlink($png_f);
            return 'data:image/png;base64,' . base64_encode($data);
        }
    } catch (Throwable $e) {
        // fall through to SVG fallback
    }
    @unlink($png_f);

    // ── SVG fallback (no GD required) ────────────────────────────────────────
    $svg_f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . uniqid('', true) . '.svg';
    try {
        QRcode::svg($url, $svg_f, QR_ECLEVEL_M, 4, 4);
        if (is_file($svg_f) && filesize($svg_f) > 0) {
            $data = file_get_contents($svg_f);
            @unlink($svg_f);
            return 'data:image/svg+xml;base64,' . base64_encode($data);
        }
    } catch (Throwable $e) {
        // ignore
    }
    @unlink($svg_f);

    return ''; // nothing worked – caller should handle empty URI gracefully
}

// ── Verification URL for a token ─────────────────────────────────────────────

function ac_verify_url(string $token): string
{
    return SITE_URL . '/admin/admit-card/verify.php?t=' . urlencode($token);
}

// ── Build admit card HTML (used for both on-page preview and PDF) ─────────────

function ac_build_html(array $card, array $student, array $courses, string $qr_data_uri): string
{
    $logo_uri = acc_logo_data_uri();
    $dept_label = h($card['dept_faculty_label'] ?? $card['dept_name']);
    $program_label = h($card['program_name']);
    $exam_name  = h($card['exam_name']);
    $semester   = h($card['semester']);
    $batch_label = h($card['batch_label'] ?? ($card['batch_name_db'] ?? ''));
    $student_name = h($student['full_name']);
    $student_id   = h($student['student_id']);

    // Student photo — when the student has no photo, the photo field is
    // omitted from the PDF entirely (no empty placeholder box).
    $photo_html = '';
    if (!empty($student['photo'])) {
        $photo_abs = dirname(__DIR__) . '/uploads/students/photos/' . $student['photo'];
        if (is_file($photo_abs)) {
            $photo_mime = 'image/jpeg';
            $ext_p = strtolower(pathinfo($student['photo'], PATHINFO_EXTENSION));
            if ($ext_p === 'png')  $photo_mime = 'image/png';
            if ($ext_p === 'gif')  $photo_mime = 'image/gif';
            if ($ext_p === 'webp') $photo_mime = 'image/webp';
            $photo_b64  = base64_encode(file_get_contents($photo_abs));
            $photo_html = '<img src="data:' . $photo_mime . ';base64,' . $photo_b64 . '"
                                style="width:95px;height:115px;object-fit:cover;border:1px solid #ddd;"
                                alt="Student Photo">';
        }
    }

    // Course rows
    $course_rows = '';
    foreach ($courses as $c) {
        $date_str = $c['exam_date'] ? date('d-m-Y', strtotime($c['exam_date'])) : '—';
        $course_rows .= '<tr>'
            . '<td style="border:1px solid #000;padding:6px;">' . h($c['course_code'])  . '</td>'
            . '<td style="border:1px solid #000;padding:6px;text-align:left;">' . h($c['course_title']) . '</td>'
            . '<td style="border:1px solid #000;padding:6px;">' . h($date_str)          . '</td>'
            . '<td style="border:1px solid #000;padding:6px;">' . h($c['time_slot'] ?? '—')  . '</td>'
            . '<td style="border:1px solid #000;padding:6px;">' . h($c['section']   ?? '—')  . '</td>'
            . '</tr>';
    }
    if ($course_rows === '') {
        $course_rows = '<tr><td colspan="5" style="border:1px solid #000;padding:8px;text-align:center;color:#777;">No courses listed</td></tr>';
    }

    $logo_img = $logo_uri
        ? '<img src="' . $logo_uri . '" style="width:80px;height:auto;max-height:100px;" alt="Prime University">'
        : '<div style="width:80px;height:100px;border:1px solid #ccc;display:flex;align-items:center;
                       justify-content:center;font-size:11px;color:#777;">Logo</div>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  body { margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; }
  @page { margin:15mm; }
</style>
</head><body>
<div style="max-width:750px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#000;padding:20px;background:#fff;">

  <!-- Header: logo + university/faculty/program (+ photo) on one line.
       Table layout is used because dompdf does not support flexbox. -->
  <table style="width:100%;border-collapse:collapse;margin-bottom:15px;">
    <tr>
      <td style="width:120px;text-align:center;vertical-align:middle;">' . $logo_img . '</td>
      <td style="text-align:center;vertical-align:middle;line-height:1.4;">
        <h1 style="margin:0;font-size:22px;font-weight:bold;">Prime University</h1>
        <div style="font-size:15px;font-weight:bold;">' . $dept_label . '</div>
        <div style="font-size:15px;font-weight:bold;">' . $program_label . '</div>
      </td>
      <td style="width:120px;text-align:center;vertical-align:middle;">' . $photo_html . '</td>
    </tr>
  </table>

  <!-- Admit Card title -->
  <div style="text-align:center;margin:20px 0 14px 0;">
    <span style="font-size:21px;font-weight:bold;border:3px solid #000;padding:2px 22px;display:inline-block;">Admit Card</span>
  </div>

  <!-- Student info + course table -->
  <table style="width:100%;border-collapse:collapse;text-align:center;font-size:14px;">
    <tbody>
      <tr>
        <td style="border:1px solid #000;padding:6px;width:15%;">Name</td>
        <td style="border:1px solid #000;padding:6px;font-weight:bold;width:45%;">' . $student_name . '</td>
        <td colspan="3" style="border:1px solid #000;padding:6px;font-size:13px;font-weight:bold;">' . $exam_name . '</td>
      </tr>
      <tr>
        <td style="border:1px solid #000;padding:6px;">ID No.</td>
        <td style="border:1px solid #000;padding:6px;font-weight:bold;">' . $student_id . '</td>
        <td colspan="2" style="border:1px solid #000;padding:6px;font-size:13px;font-weight:bold;">Batch: ' . $batch_label . '</td>
        <td style="border:1px solid #000;padding:6px;font-size:13px;font-weight:bold;">' . $semester . '</td>
      </tr>
      <tr style="font-weight:bold;font-size:15px;background:#f5f5f5;">
        <td style="border:1px solid #000;padding:7px;">Course Code</td>
        <td style="border:1px solid #000;padding:7px;">Course Title</td>
        <td style="border:1px solid #000;padding:7px;">Date</td>
        <td style="border:1px solid #000;padding:7px;">Time Slot</td>
        <td style="border:1px solid #000;padding:7px;">Section</td>
      </tr>
      ' . $course_rows . '
    </tbody>
  </table>

  <!-- Footer with QR (table layout — dompdf does not support flexbox) -->
  <table style="width:100%;border-collapse:collapse;margin-top:20px;">
    <tr>
      <td style="font-size:11px;color:#555;line-height:1.5;vertical-align:bottom;">
        <p style="margin:8px 0 0 0;font-style:italic;color:#444;">
          This is a digitally generated admit card. You can authenticate it by scanning the QR code.
        </p>
      </td>
      <td style="width:110px;text-align:center;vertical-align:bottom;">
        <img src="' . $qr_data_uri . '" style="width:100px;height:100px;" alt="QR Code">
        <div style="font-size:9px;color:#666;margin-top:2px;">Scan to verify</div>
      </td>
    </tr>
  </table>

</div>
</body></html>';

    return $html;
}
