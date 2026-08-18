<?php
/**
 * Exam Routine – shared helpers.
 */

/** Active exams available for routine building. */
function er_active_exams(): array
{
    try {
        return db()->query(
            'SELECT id, exam_name, exam_year
               FROM ei_exams
              WHERE is_active = 1
              ORDER BY exam_year DESC, exam_name ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** One routine with its exam / dept / program / batch labels, or null. */
function er_get_routine(int $id): ?array
{
    $st = db()->prepare(
        'SELECT r.*, e.exam_name, e.exam_year,
                d.name AS dept_name, p.program_name, b.name AS batch_name
           FROM exam_routines r
           JOIN ei_exams e                    ON e.id = r.exam_id
           JOIN dept_departments d            ON d.id = r.dept_id
      LEFT JOIN dept_academic_programs p      ON p.id = r.program_id
      LEFT JOIN student_batches b             ON b.id = r.batch_id
          WHERE r.id = ?'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Ordered items of a routine. */
function er_get_items(int $routine_id): array
{
    $st = db()->prepare(
        'SELECT * FROM exam_routine_items
          WHERE routine_id = ?
          ORDER BY sort_order ASC, exam_date ASC, start_time ASC, id ASC'
    );
    $st->execute([$routine_id]);
    return $st->fetchAll();
}

/** "09:30:00" → "9:30 AM" (falls back to the raw value). */
function er_fmt_time(?string $t): string
{
    if ($t === null || $t === '') return '';
    $dt = DateTimeImmutable::createFromFormat('H:i:s', $t)
        ?: DateTimeImmutable::createFromFormat('H:i', $t);
    return $dt ? $dt->format('g:i A') : (string)$t;
}

/** Active registered students of an offered subject. */
function er_registered_count(int $offer_subject_id): int
{
    $st = db()->prepare(
        "SELECT COUNT(*)
           FROM co_registrations r
           JOIN students s ON s.id = r.student_id
          WHERE r.offer_subject_id = ? AND s.status = 'Active'"
    );
    $st->execute([$offer_subject_id]);
    return (int)$st->fetchColumn();
}

/** Human label for a routine's class context. */
function er_context_label(array $r): string
{
    $parts = [];
    if (!empty($r['batch_name'])) $parts[] = 'Batch ' . $r['batch_name'];
    if (!empty($r['semester']))   $parts[] = $r['semester'];
    if (!empty($r['section']))    $parts[] = 'Sec ' . $r['section'];
    if (!empty($r['shift']))      $parts[] = $r['shift'];
    return implode(' · ', $parts);
}

/**
 * Teacher names per offer subject: [offer_subject_id => "Name1, Name2"].
 * Used to show the Course Teacher column on view/print pages.
 */
function er_subject_teacher_map(array $offer_subject_ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $offer_subject_ids))));
    if (empty($ids)) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT t.offer_subject_id, f.name
           FROM co_offer_subject_teachers t
           JOIN dept_faculty f ON f.id = t.faculty_id
          WHERE t.offer_subject_id IN ($ph)
          ORDER BY t.sort_order ASC, f.name ASC"
    );
    $st->execute($ids);
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $osid = (int)$r['offer_subject_id'];
        $map[$osid] = isset($map[$osid]) ? $map[$osid] . ', ' . $r['name'] : (string)$r['name'];
    }
    return $map;
}

// ── CSV import – normalisation & fuzzy matching helpers ────────────────────

/** Lowercase, '&'→'and', strip punctuation, collapse spaces. */
function er_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}

/** Acronym from the significant words of a normalised name ("cse", "fdae"…). */
function er_acronym(string $norm): string
{
    $stop = ['of', 'and', 'the', 'in', 'department', 'dept', 'faculty'];
    $out  = '';
    foreach (explode(' ', $norm) as $w) {
        if ($w === '' || in_array($w, $stop, true)) continue;
        $out .= $w[0];
    }
    return $out;
}

/**
 * All the keys a name can be matched by:
 * full normalised name, name without "Department of", parenthetical short
 * names ("(EEE)", "(CE)") and the acronym of its significant words.
 * e.g. "Department of Fashion Design and Apparel Engineering" →
 *      ["department of fashion design and apparel engineering",
 *       "fashion design and apparel engineering", "fdae"]
 */
function er_name_keys(string $name): array
{
    $keys = [];
    if (preg_match_all('/\(([^)]+)\)/', $name, $m)) {
        foreach ($m[1] as $p) {
            $k = er_norm($p);
            if ($k !== '') $keys[] = $k;
        }
    }
    $base = preg_replace('/\([^)]*\)/', ' ', $name);
    $norm = er_norm((string)$base);
    if ($norm !== '') $keys[] = $norm;
    $short = preg_replace('/^(department|dept) of /', '', $norm);
    if ($short !== '' && $short !== $norm) $keys[] = $short;
    $acr = er_acronym($norm);
    if (strlen($acr) >= 2) $keys[] = $acr;
    return array_values(array_unique($keys));
}

/**
 * Fuzzy-match a CSV token against a list of rows by name.
 * Returns [matched_row|null, ambiguous(bool)].
 */
function er_match_by_name(array $rows, string $token, string $name_field = 'name'): array
{
    $tok = er_norm($token);
    if ($tok === '') return [null, false];
    $exact = [];
    $partial = [];
    foreach ($rows as $r) {
        $keys = er_name_keys((string)$r[$name_field]);
        if (in_array($tok, $keys, true)) { $exact[] = $r; continue; }
        foreach ($keys as $k) {
            if (strlen($tok) >= 3 && (strpos($k, $tok) !== false || strpos($tok, $k) !== false)) {
                $partial[] = $r;
                break;
            }
        }
    }
    if (count($exact) === 1)   return [$exact[0], false];
    if (count($exact) > 1)     return [null, true];
    if (count($partial) === 1) return [$partial[0], false];
    if (count($partial) > 1)   return [null, true];
    return [null, false];
}

/**
 * Match a CSV batch token against student_batches rows.
 * "59", "59th" and "Batch 59" all resolve to the same batch via digit match.
 * Returns [matched_row|null, ambiguous(bool)].
 */
function er_match_batch(array $batches, string $token): array
{
    $tok = er_norm($token);
    if ($tok === '') return [null, false];
    $tok = trim((string)preg_replace('/^batch\s*/', '', $tok));
    $tok_digits = preg_replace('/\D+/', '', $tok);
    $exact = [];
    $digit = [];
    foreach ($batches as $b) {
        $n = er_norm((string)$b['name']);
        if ($n === $tok) { $exact[] = $b; continue; }
        $nd = preg_replace('/\D+/', '', $n);
        if ($tok_digits !== '' && $nd !== '' && $nd === $tok_digits) $digit[] = $b;
    }
    if (count($exact) === 1) return [$exact[0], false];
    if (count($exact) > 1)   return [null, true];
    if (count($digit) === 1) return [$digit[0], false];
    if (count($digit) > 1)   return [null, true];
    return [null, false];
}

/** Parse many common date spellings → "Y-m-d" (day-first preferred), or null. */
function er_parse_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $raw = (string)preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $raw);
    $formats = [
        'Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'Y/m/d',
        'j M Y', 'j F Y', 'j M, Y', 'j F, Y', 'M j Y', 'F j Y', 'M j, Y', 'F j, Y',
    ];
    foreach ($formats as $f) {
        $d = DateTimeImmutable::createFromFormat('!' . $f, $raw);
        if ($d) return $d->format('Y-m-d');
    }
    $ts = strtotime(str_replace('/', '-', $raw)); // day-first fallback
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Parse "9:30 AM" / "09:30" / "9.30pm" → "H:i" (24h), or null. */
function er_parse_time(string $raw): ?string
{
    $raw = str_replace(' ', '', mb_strtolower(trim($raw)));
    if ($raw === '') return null;
    if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(am|pm)?$/', $raw, $m)) return null;
    $h  = (int)$m[1];
    $i  = (int)($m[2] ?? 0);
    $ap = $m[3] ?? '';
    if ($i > 59) return null;
    if ($ap === 'pm' && $h < 12)  $h += 12;
    if ($ap === 'am' && $h === 12) $h = 0;
    if ($h > 23) return null;
    return sprintf('%02d:%02d', $h, $i);
}
