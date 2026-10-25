<?php
// Helpers for the faculty self-service "Invigilation Duty" module.
// Resolves the logged-in user's ei_faculty record(s) so they can see the
// invigilation slots assigned to them in the Exam Invigilation module.

/**
 * Best-effort auto-migration: add a nullable user_id link column to
 * ei_faculty so pool members can be tied to login accounts.
 */
function ivd_ensure_user_column(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $has = db()->query("SHOW COLUMNS FROM ei_faculty LIKE 'user_id'")->fetch();
        if (!$has) {
            db()->exec(
                "ALTER TABLE ei_faculty
                   ADD COLUMN user_id INT UNSIGNED DEFAULT NULL COMMENT 'Linked login account (users.id)' AFTER dept_id,
                   ADD KEY idx_ei_faculty_user (user_id)"
            );
        }
    } catch (Throwable $e) {
        // best effort — queries will surface real problems
    }
}

/**
 * Normalise a person name for matching: lowercase, strip punctuation,
 * common titles ("Dr.", "Prof.", "Mr.", "Ms.", "Mrs.", "Engr.") and
 * low-signal honorific given-name prefixes ("Md."/"Mohammad"/"Muhammad"/
 * "Mst."/"Most." and their common spellings), collapse whitespace.
 */
function ivd_normalize_name(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = str_replace(['.', ',', '-', "'", '’', '(', ')', '/'], ' ', $name);
    $name = preg_replace(
        '/\b(dr|prof|professor|mr|ms|mrs|engr|md|mohammad|muhammad|mohammed|muhammed|mohamed|mohd|mst|most|mosammat|mosammad)\b/u',
        ' ',
        $name
    );
    $name = preg_replace('/\s+/u', ' ', (string)$name);
    return trim((string)$name);
}

/**
 * Order-insensitive key for a normalised name: its words sorted and joined,
 * so "karim abdul" and "abdul karim" compare equal. Returns '' for names
 * too short to be a meaningful identifier (fewer than 2 words).
 */
function ivd_name_token_key(string $normalized): string
{
    $tokens = array_values(array_filter(explode(' ', $normalized), 'strlen'));
    if (count($tokens) < 2) return '';
    sort($tokens);
    return implode(' ', $tokens);
}

/**
 * Tokenise a raw person name for fuzzy matching: normalise it, split into
 * words and merge runs of single-letter initials so "A. K. M. Rahman" and
 * "AKM Rahman" both yield ["akm", "rahman"].
 *
 * @return string[]
 */
function ivd_name_tokens(string $name): array
{
    $tokens = array_values(array_filter(explode(' ', ivd_normalize_name($name)), 'strlen'));
    $out = [];
    $run = '';
    foreach ($tokens as $t) {
        if (mb_strlen($t) === 1) { $run .= $t; continue; }
        if ($run !== '') { $out[] = $run; $run = ''; }
        $out[] = $t;
    }
    if ($run !== '') $out[] = $run;
    return $out;
}

/**
 * Whether two name tokens should be considered the same word:
 *  - exact match;
 *  - an initial matching the first letter of the other word ("k" ~ "kamrul");
 *  - small typo / spelling variant (levenshtein ≤ 1, or ≤ 2 for long words)
 *    e.g. "hasan" ~ "hassan", "rahman" ~ "rahaman";
 *  - identical metaphone (same pronunciation), e.g. "chowdhury" ~ "choudhury".
 * Typo/phonetic comparison is only attempted for plain ASCII words.
 */
function ivd_token_pair_match(string $a, string $b): bool
{
    if ($a === $b) return true;
    $la = mb_strlen($a);
    $lb = mb_strlen($b);
    if ($la === 1 || $lb === 1) {
        return $la === 1
            ? mb_substr($b, 0, 1) === $a
            : mb_substr($a, 0, 1) === $b;
    }
    if (min($la, $lb) >= 4 && preg_match('/^[a-z]+$/', $a) && preg_match('/^[a-z]+$/', $b)) {
        if (levenshtein($a, $b) <= (min($la, $lb) >= 7 ? 2 : 1)) return true;
        if (function_exists('metaphone')) {
            $ma = metaphone($a);
            if ($ma !== '' && $ma === metaphone($b)) return true;
        }
    }
    return false;
}

/**
 * Fuzzy order-insensitive comparison of two tokenised names. Matches when
 * every token of the shorter name can be paired with a distinct token of the
 * longer one (so "Kamrul Hasan" matches "Md. Kamrul Hasan Khan"), with at
 * least two paired tokens and at least one "strong" pair (both words ≥ 4
 * characters — initials alone are never enough).
 *
 * @param string[] $a
 * @param string[] $b
 */
function ivd_fuzzy_name_match(array $a, array $b): bool
{
    if (count($a) < 2 || count($b) < 2) return false;
    if (count($a) > count($b)) { $tmp = $a; $a = $b; $b = $tmp; }

    $used   = [];
    $strong = 0;
    foreach ($a as $ta) {
        $found = -1;
        foreach ($b as $i => $tb) { // prefer exact pairings first
            if (!isset($used[$i]) && $ta === $tb) { $found = $i; break; }
        }
        if ($found < 0) {
            foreach ($b as $i => $tb) {
                if (!isset($used[$i]) && ivd_token_pair_match($ta, $tb)) { $found = $i; break; }
            }
        }
        if ($found < 0) return false;
        $used[$found] = true;
        if (mb_strlen($ta) >= 4 && mb_strlen($b[$found]) >= 4) $strong++;
    }
    return $strong >= 1 && count($used) >= 2;
}

/**
 * Canonical key for a phone number: digits only, trimmed to the last 10
 * digits (drops "+880"/"880"/leading 0 country/trunk prefixes). Returns ''
 * when the number is too short to be meaningful.
 */
function ivd_phone_key(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($d) > 10) $d = substr($d, -10);
    return strlen($d) >= 9 ? $d : '';
}

/**
 * The user's normalised name keys (order-insensitive), gathered from
 * users.full_name and any active dept_faculty rows linked to the account.
 *
 * @return array<string,true>
 */
function ivd_name_keys_for_user(int $user_id): array
{
    $keys = [];
    try {
        $st = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $st->execute([$user_id]);
        if ($u = $st->fetch()) {
            $k = ivd_name_token_key(ivd_normalize_name((string)($u['full_name'] ?? '')));
            if ($k !== '') $keys[$k] = true;
        }
    } catch (Throwable $e) {}
    try {
        $st = db()->prepare('SELECT name FROM dept_faculty WHERE user_id = ? AND is_active = 1');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $k = ivd_name_token_key(ivd_normalize_name((string)$n));
            if ($k !== '') $keys[$k] = true;
        }
    } catch (Throwable $e) {}
    return $keys;
}

/**
 * Whether any OTHER active login account carries one of the given name
 * keys (via users.full_name or a linked dept_faculty row). Used to refuse
 * a name-based match when two real people share the same name.
 *
 * @param array<string,true> $keys
 */
function ivd_name_shared_with_other_user(array $keys, int $user_id): bool
{
    if (!$keys) return false;
    try {
        $st = db()->prepare('SELECT full_name FROM users WHERE id <> ? AND is_active = 1');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $k = ivd_name_token_key(ivd_normalize_name((string)$n));
            if ($k !== '' && isset($keys[$k])) return true;
        }
    } catch (Throwable $e) {
        return true; // fail safe: cannot prove uniqueness
    }
    try {
        $st = db()->prepare(
            'SELECT name FROM dept_faculty WHERE is_active = 1 AND user_id IS NOT NULL AND user_id <> ?'
        );
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $k = ivd_name_token_key(ivd_normalize_name((string)$n));
            if ($k !== '' && isset($keys[$k])) return true;
        }
    } catch (Throwable $e) {
        return true;
    }
    return false;
}

/**
 * The user's tokenised name candidates for fuzzy matching, gathered from
 * users.full_name and any active dept_faculty rows linked to the account.
 *
 * @return string[][]
 */
function ivd_user_name_token_lists(int $user_id): array
{
    $lists = [];
    $seen  = [];
    $push  = static function (string $name) use (&$lists, &$seen): void {
        $tokens = ivd_name_tokens($name);
        if (count($tokens) < 2) return;
        $key = implode(' ', $tokens);
        if (isset($seen[$key])) return;
        $seen[$key]  = true;
        $lists[] = $tokens;
    };
    try {
        $st = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $st->execute([$user_id]);
        if ($u = $st->fetch()) $push((string)($u['full_name'] ?? ''));
    } catch (Throwable $e) {}
    try {
        $st = db()->prepare('SELECT name FROM dept_faculty WHERE user_id = ? AND is_active = 1');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) $push((string)$n);
    } catch (Throwable $e) {}
    return $lists;
}

/**
 * Department id(s) the user belongs to (faculty_profiles / active
 * dept_faculty rows). Used to disambiguate when several pool rows carry
 * matching names or phone numbers.
 *
 * @return array<int,true>
 */
function ivd_user_dept_ids(int $user_id): array
{
    $depts = [];
    foreach ([
        'SELECT dept_id FROM faculty_profiles WHERE user_id = ?',
        'SELECT dept_id FROM dept_faculty WHERE user_id = ? AND is_active = 1',
    ] as $sql) {
        try {
            $st = db()->prepare($sql);
            $st->execute([$user_id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
                if ($d !== null && (int)$d > 0) $depts[(int)$d] = true;
            }
        } catch (Throwable $e) {}
    }
    return $depts;
}

/**
 * Whether any OTHER active login account's name fuzzy-matches the given
 * pool-row name tokens. Used to refuse a fuzzy name match when the pool row
 * could plausibly belong to a different real person.
 *
 * @param string[] $pool_tokens
 */
function ivd_fuzzy_shared_with_other_user(array $pool_tokens, int $user_id): bool
{
    try {
        $st = db()->prepare('SELECT full_name FROM users WHERE id <> ? AND is_active = 1');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (ivd_fuzzy_name_match($pool_tokens, ivd_name_tokens((string)$n))) return true;
        }
    } catch (Throwable $e) {
        return true; // fail safe: cannot prove uniqueness
    }
    try {
        $st = db()->prepare(
            'SELECT name FROM dept_faculty WHERE is_active = 1 AND user_id IS NOT NULL AND user_id <> ?'
        );
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (ivd_fuzzy_name_match($pool_tokens, ivd_name_tokens((string)$n))) return true;
        }
    } catch (Throwable $e) {
        return true;
    }
    return false;
}

/**
 * Resolve the ei_faculty row id(s) belonging to the given user.
 *
 * Resolution order, strongest evidence first:
 *
 * 1. Direct link via ei_faculty.user_id (set by admins on the Exam
 *    Invigilation → Faculty Pool add/edit forms).
 * 2. Exact phone match (users.phone / faculty_profiles.phone vs
 *    ei_faculty.contact_number) against unlinked pool rows, accepted only
 *    when it identifies exactly one row.
 * 3. Exact normalised-name match (order-insensitive, titles/honorifics
 *    stripped) against unlinked pool rows — accepted only when it is
 *    doubly unambiguous: exactly one pool row matches AND no other login
 *    account carries the same name. This restores self-service access for
 *    faculty whose pool row has no usable phone, without the risk of
 *    showing one person's duties to a same-named colleague.
 * 4. Fuzzy name match against unlinked pool rows — tolerates missing
 *    honorifics, initials vs full words ("K." ~ "Kamrul", "A K M" ~ "AKM"),
 *    small typos / spelling variants ("Hasan" ~ "Hassan", "Rahman" ~
 *    "Rahaman", "Chowdhury" ~ "Choudhury") and extra/missing name parts
 *    ("Kamrul Hasan" ~ "Md. Kamrul Hasan Khan"). Accepted only when it is
 *    doubly unambiguous: exactly one pool row matches AND no other login
 *    account fuzzy-matches that row's name.
 *
 * Whenever several pool rows tie at tiers 2–4, the user's own department(s)
 * (faculty_profiles / dept_faculty) are used as a tie-breaker: if exactly
 * one of the tied rows belongs to the user's department, it wins.
 *
 * A unique fallback match is persisted back to ei_faculty.user_id so
 * future lookups are direct.
 *
 * @return int[] ei_faculty ids (may be empty)
 */
function ivd_faculty_ids_for_user(int $user_id): array
{
    if ($user_id <= 0) return [];
    ivd_ensure_user_column();

    // 1. Direct link
    try {
        $st = db()->prepare('SELECT id FROM ei_faculty WHERE user_id = ?');
        $st->execute([$user_id]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) return $ids;
    } catch (Throwable $e) {
        return [];
    }

    // 2–3. Fallback: gather this user's phone numbers and name keys.
    $phones = [];
    try {
        $st = db()->prepare('SELECT phone FROM users WHERE id = ?');
        $st->execute([$user_id]);
        if ($u = $st->fetch()) {
            $pk = ivd_phone_key((string)($u['phone'] ?? ''));
            if ($pk !== '') $phones[$pk] = true;
        }
    } catch (Throwable $e) {}
    try {
        $st = db()->prepare('SELECT phone FROM faculty_profiles WHERE user_id = ?');
        $st->execute([$user_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $pk = ivd_phone_key((string)$p);
            if ($pk !== '') $phones[$pk] = true;
        }
    } catch (Throwable $e) {}
    $name_keys = ivd_name_keys_for_user($user_id);
    if (!$phones && !$name_keys) return [];

    try {
        $rows = db()->query('SELECT id, user_id, dept_id, name, contact_number FROM ei_faculty')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $user_depts  = ivd_user_dept_ids($user_id);
    $token_lists = ivd_user_name_token_lists($user_id);

    // Tie-breaker: when several pool rows match equally well, prefer the
    // single one in the user's own department.
    $narrow_by_dept = static function (array $candidates) use ($user_depts): array {
        if (count($candidates) <= 1 || !$user_depts) return $candidates;
        $own = array_values(array_filter(
            $candidates,
            static fn (array $c) => isset($user_depts[(int)($c['dept_id'] ?? 0)])
        ));
        return count($own) === 1 ? $own : $candidates;
    };

    $phone_eq = [];
    $name_eq  = [];
    $fuzzy_eq = [];
    foreach ($rows as $row) {
        if (!empty($row['user_id'])) continue; // already linked to someone else
        $pk = ivd_phone_key((string)($row['contact_number'] ?? ''));
        if ($pk !== '' && isset($phones[$pk])) {
            $phone_eq[] = $row;
            continue;
        }
        $nk = ivd_name_token_key(ivd_normalize_name((string)($row['name'] ?? '')));
        if ($nk !== '' && isset($name_keys[$nk])) {
            $name_eq[] = $row;
            continue;
        }
        $pool_tokens = ivd_name_tokens((string)($row['name'] ?? ''));
        foreach ($token_lists as $mine) {
            if (ivd_fuzzy_name_match($mine, $pool_tokens)) {
                $row['_tokens'] = $pool_tokens;
                $fuzzy_eq[]     = $row;
                break;
            }
        }
    }

    // Phone is the strongest identifier; then exact name, then fuzzy name.
    // Name-based tiers additionally require that no other login account
    // could claim the same pool row.
    $phone_eq = $narrow_by_dept($phone_eq);
    $name_eq  = $narrow_by_dept($name_eq);
    $fuzzy_eq = $narrow_by_dept($fuzzy_eq);

    $match = [];
    if (count($phone_eq) === 1) {
        $match = [(int)$phone_eq[0]['id']];
    } elseif (!$phone_eq && count($name_eq) === 1
        && !ivd_name_shared_with_other_user($name_keys, $user_id)) {
        $match = [(int)$name_eq[0]['id']];
    } elseif (!$phone_eq && !$name_eq && count($fuzzy_eq) === 1
        && !ivd_fuzzy_shared_with_other_user($fuzzy_eq[0]['_tokens'], $user_id)) {
        $match = [(int)$fuzzy_eq[0]['id']];
    }
    if (count($match) !== 1) return [];

    // Persist the unique match so future lookups are direct.
    try {
        db()->prepare('UPDATE ei_faculty SET user_id = ? WHERE id = ? AND user_id IS NULL')
            ->execute([$user_id, $match[0]]);
    } catch (Throwable $e) {}

    return $match;
}

/**
 * Fetch all invigilation duty slots for the given ei_faculty ids, in date
 * order. Each row includes the exam, slot details, which seat
 * (Invigilator 1/2) is mine, the co-invigilator's name and my attendance
 * status for that slot (1 attended, 0 absent, null = not recorded).
 *
 * @param int[] $faculty_ids
 */
function ivd_duties_for_faculty(array $faculty_ids): array
{
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];

    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    $st = db()->prepare(
        "SELECT s.id, s.slot_date, s.time_slot, s.room_number,
                s.faculty1_id, s.faculty2_id,
                e.id AS exam_id, e.exam_name, e.exam_year,
                f1.name AS faculty1_name, f2.name AS faculty2_name,
                d.name AS dept_name
         FROM ei_slots s
         JOIN ei_exams e        ON e.id = s.exam_id
         LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
         LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
         LEFT JOIN dept_departments d ON d.id = s.dept_id
         WHERE s.faculty1_id IN ($ph) OR s.faculty2_id IN ($ph)
         ORDER BY s.slot_date ASC, s.time_slot ASC, s.room_number ASC"
    );
    $st->execute(array_merge($faculty_ids, $faculty_ids));
    $rows = $st->fetchAll();
    if (!$rows) return [];

    // My attendance per slot
    $slot_ids = array_map(static fn ($r) => (int)$r['id'], $rows);
    $att = [];
    try {
        $sph = implode(',', array_fill(0, count($slot_ids), '?'));
        $fph = implode(',', array_fill(0, count($faculty_ids), '?'));
        $ast = db()->prepare(
            "SELECT slot_id, attended FROM ei_slot_attendance
             WHERE slot_id IN ($sph) AND faculty_id IN ($fph)"
        );
        $ast->execute(array_merge($slot_ids, $faculty_ids));
        foreach ($ast->fetchAll() as $a) {
            $att[(int)$a['slot_id']] = (int)$a['attended'];
        }
    } catch (Throwable $e) {}

    $mine = array_flip($faculty_ids);
    foreach ($rows as &$r) {
        $is_f1            = isset($mine[(int)$r['faculty1_id']]);
        $r['my_position'] = $is_f1 ? 1 : 2;
        $r['co_name']     = $is_f1 ? ($r['faculty2_name'] ?? null) : ($r['faculty1_name'] ?? null);
        $r['attended']    = $att[(int)$r['id']] ?? null;
    }
    unset($r);

    return $rows;
}

/**
 * Resolve the department id(s) of the given ei_faculty ids.
 *
 * @param int[] $faculty_ids
 * @return int[] dept ids (may be empty)
 */
function ivd_dept_ids_for_faculty(array $faculty_ids): array
{
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];

    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT DISTINCT dept_id FROM ei_faculty WHERE id IN ($ph) AND dept_id IS NOT NULL"
        );
        $st->execute($faculty_ids);
        return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Whether the given user belongs to a user group that grants departmental
 * oversight — i.e. a group whose name contains "coordinator", "head", "dean"
 * or "section officer". Only these groups may see the department-wide "My
 * Department's Duties" overview; ordinary faculty see only their own duties.
 *
 * @param array $user The logged-in user (from auth_user()).
 */
function ivd_can_view_dept_duties(array $user): bool
{
    $group_ids = $user['group_ids'] ?? [(int)($user['group_id'] ?? 0)];
    $group_ids = array_values(array_unique(array_filter(array_map('intval', $group_ids))));
    if (!$group_ids) return false;

    $ph = implode(',', array_fill(0, count($group_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT 1 FROM user_groups
             WHERE id IN ($ph) AND is_active = 1 AND (
                 LOWER(COALESCE(name, '')) LIKE '%coordinator%'
                 OR LOWER(COALESCE(name, '')) LIKE '%head%'
                 OR LOWER(COALESCE(name, '')) LIKE '%dean%'
                 OR LOWER(COALESCE(name, '')) LIKE '%section officer%'
             )
             LIMIT 1"
        );
        $st->execute($group_ids);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fetch all invigilation duty slots assigned to faculty of the given
 * department(s), in date order. Each row carries both invigilators' names,
 * the exam and slot details and the department name. Used for the faculty
 * self-service "My Department's Duties" overview.
 *
 * @param int[] $dept_ids
 */
function ivd_dept_duties(array $dept_ids): array
{
    $dept_ids = array_values(array_unique(array_filter(array_map('intval', $dept_ids))));
    if (!$dept_ids) return [];

    $ph = implode(',', array_fill(0, count($dept_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT s.id, s.slot_date, s.time_slot, s.room_number,
                    s.faculty1_id, s.faculty2_id,
                    e.exam_name, e.exam_year,
                    f1.name AS faculty1_name, f2.name AS faculty2_name,
                    d.name AS dept_name
             FROM ei_slots s
             JOIN ei_exams e        ON e.id = s.exam_id
             LEFT JOIN ei_faculty f1 ON f1.id = s.faculty1_id
             LEFT JOIN ei_faculty f2 ON f2.id = s.faculty2_id
             LEFT JOIN dept_departments d ON d.id = s.dept_id
             WHERE f1.dept_id IN ($ph) OR f2.dept_id IN ($ph)
             ORDER BY s.slot_date ASC, s.time_slot ASC, s.room_number ASC"
        );
        $st->execute(array_merge($dept_ids, $dept_ids));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ── Duty swap / substitute requests ─────────────────────────────────────────

/**
 * Best-effort auto-migration: create the ivd_swap_requests table on first use
 * so existing deployments work without a manual migration step.
 */
function ivd_ensure_swap_table(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS ivd_swap_requests (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                request_type ENUM('swap','substitute') NOT NULL,
                status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
                requester_faculty_id INT(10) UNSIGNED NOT NULL,
                requester_slot_id INT(10) UNSIGNED NOT NULL,
                requester_position TINYINT(1) NOT NULL,
                target_faculty_id INT(10) UNSIGNED NOT NULL,
                target_slot_id INT(10) UNSIGNED DEFAULT NULL,
                target_position TINYINT(1) DEFAULT NULL,
                message VARCHAR(500) DEFAULT NULL,
                responded_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT current_timestamp(),
                updated_at DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_ivd_swap_requester (requester_faculty_id, status),
                KEY idx_ivd_swap_target (target_faculty_id, status),
                KEY idx_ivd_swap_req_slot (requester_slot_id),
                KEY idx_ivd_swap_tgt_slot (target_slot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // best effort — queries will surface real problems
    }
}

/**
 * Load a single ei_slots row (with exam name) by id, or null.
 */
function ivd_get_slot(int $slot_id): ?array
{
    if ($slot_id <= 0) return null;
    try {
        $st = db()->prepare(
            'SELECT s.*, e.exam_name
             FROM ei_slots s JOIN ei_exams e ON e.id = s.exam_id
             WHERE s.id = ?'
        );
        $st->execute([$slot_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Which seat (1 or 2) does the faculty hold on this slot? 0 if none.
 */
function ivd_slot_position_for_faculty(array $slot, int $faculty_id): int
{
    if ($faculty_id <= 0) return 0;
    if ((int)($slot['faculty1_id'] ?? 0) === $faculty_id) return 1;
    if ((int)($slot['faculty2_id'] ?? 0) === $faculty_id) return 2;
    return 0;
}

/**
 * Active faculty eligible to receive a request for the requester's slot.
 *
 * For 'substitute': anyone free at the slot's date+time (not already assigned
 * to a slot then). For 'swap': same free-at-slot rule — the target's own slot
 * is chosen later; we still exclude people busy at the requester's time so the
 * target can actually cover it.
 *
 * Excludes the requester faculty id(s) themselves.
 *
 * @param int[] $exclude_faculty_ids
 * @return array<int, array{id:int,name:string,designation:?string,dept_name:string}>
 */
function ivd_eligible_targets_for_slot(array $slot, array $exclude_faculty_ids = []): array
{
    require_once __DIR__ . '/../exam-invigilation/slot-helpers.php';
    $slot_date = (string)($slot['slot_date'] ?? '');
    $time_slot = (string)($slot['time_slot'] ?? '');
    if ($slot_date === '' || $time_slot === '') return [];

    $busy = ei_get_busy_faculty_ids($slot_date, $time_slot); // [faculty_id => true]
    $exclude = array_fill_keys(array_map('intval', $exclude_faculty_ids), true);

    $out = [];
    try {
        foreach (ei_get_faculty_list() as $f) {
            $fid = (int)$f['id'];
            if (isset($exclude[$fid]) || isset($busy[$fid])) continue;
            $out[$fid] = [
                'id'          => $fid,
                'name'        => (string)$f['name'],
                'designation' => $f['designation'] ?? null,
                'dept_name'   => (string)($f['dept_name'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * Create a swap/substitute request. Returns [ok(bool), message(string)].
 *
 * @param int[] $requester_faculty_ids ei_faculty ids owned by the acting user
 */
function ivd_create_swap_request(
    array $requester_faculty_ids,
    int $requester_slot_id,
    string $request_type,
    int $target_faculty_id,
    string $message = ''
): array {
    ivd_ensure_swap_table();

    $request_type = $request_type === 'swap' ? 'swap' : 'substitute';
    $requester_faculty_ids = array_values(array_unique(array_map('intval', $requester_faculty_ids)));

    $slot = ivd_get_slot($requester_slot_id);
    if ($slot === null) return [false, 'The selected duty could not be found.'];

    // Verify the acting user actually holds a seat on this slot.
    $requester_position = 0;
    $requester_faculty_id = 0;
    foreach ($requester_faculty_ids as $fid) {
        $pos = ivd_slot_position_for_faculty($slot, $fid);
        if ($pos > 0) { $requester_position = $pos; $requester_faculty_id = $fid; break; }
    }
    if ($requester_position === 0) {
        return [false, 'You are not assigned to the selected duty.'];
    }

    if ((string)$slot['slot_date'] < date('Y-m-d')) {
        return [false, 'You cannot request changes for a past duty.'];
    }

    if ($target_faculty_id <= 0 || in_array($target_faculty_id, $requester_faculty_ids, true)) {
        return [false, 'Please choose a different faculty member.'];
    }

    // Target must be eligible (active + free at this slot time).
    $eligible = ivd_eligible_targets_for_slot($slot, $requester_faculty_ids);
    if (!isset($eligible[$target_faculty_id])) {
        return [false, 'The selected faculty is not available for this duty slot.'];
    }

    // No duplicate pending request for the same slot + target.
    try {
        $dup = db()->prepare(
            "SELECT id FROM ivd_swap_requests
             WHERE requester_slot_id = ? AND target_faculty_id = ? AND status = 'pending' LIMIT 1"
        );
        $dup->execute([$requester_slot_id, $target_faculty_id]);
        if ($dup->fetchColumn()) {
            return [false, 'A pending request for this duty and faculty already exists.'];
        }
    } catch (Throwable $e) {
        return [false, 'Could not create the request. Please try again.'];
    }

    try {
        $ins = db()->prepare(
            'INSERT INTO ivd_swap_requests
                (request_type, status, requester_faculty_id, requester_slot_id, requester_position,
                 target_faculty_id, message)
             VALUES (?, "pending", ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $request_type, $requester_faculty_id, $requester_slot_id, $requester_position,
            $target_faculty_id, ($message !== '' ? mb_substr($message, 0, 500) : null),
        ]);
    } catch (Throwable $e) {
        return [false, 'Could not create the request. Please try again.'];
    }

    ivd_push_to_faculty(
        $target_faculty_id,
        'Invigilation duty request',
        'A colleague has requested you to ' .
            ($request_type === 'swap' ? 'swap' : 'cover') .
            ' an invigilation duty on ' . date('d M Y', strtotime((string)$slot['slot_date'])) .
            '. Open Invigilation Duty in the app to respond.'
    );

    return [true, 'Your request has been sent.'];
}

/**
 * Pending requests addressed TO the given faculty (to accept/reject).
 *
 * @param int[] $faculty_ids
 */
function ivd_incoming_requests(array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT r.*, rf.name AS requester_name,
                    s.slot_date, s.time_slot, s.room_number, e.exam_name
             FROM ivd_swap_requests r
             JOIN ei_faculty rf ON rf.id = r.requester_faculty_id
             JOIN ei_slots s ON s.id = r.requester_slot_id
             JOIN ei_exams e ON e.id = s.exam_id
             WHERE r.target_faculty_id IN ($ph) AND r.status = 'pending'
             ORDER BY r.created_at DESC"
        );
        $st->execute($faculty_ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Requests created BY the given faculty (status tracking).
 *
 * @param int[] $faculty_ids
 */
function ivd_outgoing_requests(array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT r.*, tf.name AS target_name,
                    s.slot_date, s.time_slot, s.room_number, e.exam_name
             FROM ivd_swap_requests r
             JOIN ei_faculty tf ON tf.id = r.target_faculty_id
             JOIN ei_slots s ON s.id = r.requester_slot_id
             JOIN ei_exams e ON e.id = s.exam_id
             WHERE r.requester_faculty_id IN ($ph)
             ORDER BY r.created_at DESC
             LIMIT 50"
        );
        $st->execute($faculty_ids);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Accept a request addressed to the acting user, applying the change to
 * ei_slots inside a transaction. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_accept_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));

    try {
        $st = db()->prepare("SELECT * FROM ivd_swap_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$request_id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [false, 'Could not load the request.'];
    }
    if (!$req) return [false, 'This request is no longer available.'];

    $target_faculty_id = (int)$req['target_faculty_id'];
    if (!in_array($target_faculty_id, $faculty_ids, true)) {
        return [false, 'You are not authorised to respond to this request.'];
    }

    $slot = ivd_get_slot((int)$req['requester_slot_id']);
    if ($slot === null) return [false, 'The duty no longer exists.'];

    // Re-verify the requester still holds their seat.
    $requester_position = (int)$req['requester_position'];
    $requester_faculty_id = (int)$req['requester_faculty_id'];
    if (ivd_slot_position_for_faculty($slot, $requester_faculty_id) !== $requester_position) {
        return [false, 'The duty assignment has changed; this request is no longer valid.'];
    }

    require_once __DIR__ . '/../exam-invigilation/slot-helpers.php';

    // Target must still be free at this slot's time (ignore the requester's slot).
    if (ei_faculty_has_overlap($target_faculty_id, (string)$slot['slot_date'], (string)$slot['time_slot'], (int)$slot['id'])) {
        return [false, 'You now have another duty at that time and can no longer take this one.'];
    }

    $seat_col = $requester_position === 1 ? 'faculty1_id' : 'faculty2_id';

    try {
        db()->beginTransaction();
        $upd = db()->prepare("UPDATE ei_slots SET $seat_col = ? WHERE id = ?");
        $upd->execute([$target_faculty_id, (int)$slot['id']]);

        $done = db()->prepare(
            "UPDATE ivd_swap_requests
             SET status = 'accepted', responded_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $done->execute([$request_id]);

        // Cancel other pending requests that target the same seat/slot.
        $cancel = db()->prepare(
            "UPDATE ivd_swap_requests
             SET status = 'cancelled', responded_at = NOW()
             WHERE requester_slot_id = ? AND status = 'pending' AND id != ?"
        );
        $cancel->execute([(int)$slot['id'], $request_id]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return [false, 'Could not apply the change. Please try again.'];
    }

    // Snapshot for audit/version history (best effort).
    try {
        ei_save_assignment_snapshot(
            (int)$slot['exam_id'],
            'manual_edit',
            'Duty ' . ($req['request_type'] === 'swap' ? 'swap' : 'substitution') .
                ' accepted via self-service for ' . (string)$slot['slot_date']
        );
    } catch (Throwable $e) {}

    // Notify the original requester that it was accepted.
    ivd_push_to_faculty(
        $requester_faculty_id,
        'Invigilation duty request accepted',
        'Your invigilation duty on ' . date('d M Y', strtotime((string)$slot['slot_date'])) .
            ' has been accepted and reassigned.'
    );

    return [true, 'Request accepted. The duty has been reassigned.'];
}

/**
 * Reject a request addressed to the acting user. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_reject_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    try {
        $st = db()->prepare("SELECT * FROM ivd_swap_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$request_id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [false, 'Could not load the request.'];
    }
    if (!$req) return [false, 'This request is no longer available.'];
    if (!in_array((int)$req['target_faculty_id'], $faculty_ids, true)) {
        return [false, 'You are not authorised to respond to this request.'];
    }
    try {
        db()->prepare(
            "UPDATE ivd_swap_requests SET status = 'rejected', responded_at = NOW() WHERE id = ? AND status = 'pending'"
        )->execute([$request_id]);
    } catch (Throwable $e) {
        return [false, 'Could not update the request.'];
    }

    $slot = ivd_get_slot((int)$req['requester_slot_id']);
    ivd_push_to_faculty(
        (int)$req['requester_faculty_id'],
        'Invigilation duty request declined',
        'Your invigilation duty request' .
            ($slot ? ' for ' . date('d M Y', strtotime((string)$slot['slot_date'])) : '') .
            ' was declined.'
    );
    return [true, 'Request declined.'];
}

/**
 * Cancel a pending request created BY the acting user. Returns [ok, message].
 *
 * @param int[] $faculty_ids acting user's ei_faculty ids
 */
function ivd_cancel_request(int $request_id, array $faculty_ids): array
{
    ivd_ensure_swap_table();
    $faculty_ids = array_values(array_unique(array_map('intval', $faculty_ids)));
    if (!$faculty_ids) return [false, 'Not authorised.'];
    $ph = implode(',', array_fill(0, count($faculty_ids), '?'));
    try {
        $st = db()->prepare(
            "UPDATE ivd_swap_requests SET status = 'cancelled', responded_at = NOW()
             WHERE id = ? AND status = 'pending' AND requester_faculty_id IN ($ph)"
        );
        $st->execute(array_merge([$request_id], $faculty_ids));
        if ($st->rowCount() < 1) {
            return [false, 'This request can no longer be cancelled.'];
        }
    } catch (Throwable $e) {
        return [false, 'Could not cancel the request.'];
    }
    return [true, 'Request cancelled.'];
}

/**
 * Best-effort push notification to the login account linked to an ei_faculty.
 */
function ivd_push_to_faculty(int $faculty_id, string $title, string $body): void
{
    if ($faculty_id <= 0) return;
    try {
        ivd_ensure_user_column();
        $st = db()->prepare('SELECT user_id FROM ei_faculty WHERE id = ?');
        $st->execute([$faculty_id]);
        $user_id = (int)$st->fetchColumn();
        if ($user_id <= 0) return;

        require_once __DIR__ . '/../app-notifications/helpers.php';
        if (!function_exists('apn_fcm_is_configured') || !apn_fcm_is_configured()) return;
        apn_send_to_audience('user', $title, $body, null, $user_id);
    } catch (Throwable $e) {
        // delivery must never block the duty change
    }
}
