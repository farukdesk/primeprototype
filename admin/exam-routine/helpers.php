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
